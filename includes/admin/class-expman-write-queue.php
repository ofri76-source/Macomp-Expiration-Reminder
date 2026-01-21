<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Simple write-ahead queue (Option A): save quickly to a temp table, process later.
 */
class Expman_Write_Queue {

    const TABLE = 'expman_write_queue';
    const CRON_HOOK = 'expman_write_queue_worker';

    /** @var int[] */
    private static $kick_ids = array();
    /** @var bool */
    private static $shutdown_registered = false;

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install_table(): void {
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(60) NOT NULL,
            action VARCHAR(60) NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY object_type (object_type)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Enqueue a write operation.
     *
     * Returns queue id.
     */
    public static function enqueue( string $object_type, string $action, array $payload ): int {
        global $wpdb;
        $table = self::table_name();

        $wpdb->insert(
            $table,
            array(
                'object_type' => $object_type,
                'action'      => $action,
                'payload'     => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
                'status'      => 'pending',
                'attempts'    => 0,
                'created_at'  => current_time( 'mysql' ),
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        $qid = (int) $wpdb->insert_id;
        if ( $qid > 0 ) {
            self::kick_async( array( $qid ) );
        }

        return $qid;
    }

    /**
     * Best-effort immediate processing after the response is sent.
     * This helps on slow sites / environments where WP-Cron is unreliable.
     */
    public static function kick_async( array $ids = array() ): void {
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
        if ( ! empty( $ids ) ) {
            self::$kick_ids = array_values( array_unique( array_merge( self::$kick_ids, $ids ) ) );
        }

        if ( self::$shutdown_registered ) {
            return;
        }

        self::$shutdown_registered = true;

        register_shutdown_function( array( __CLASS__, 'shutdown_process_kicks' ) );
    }

    /**
     * Shutdown hook.
     */
    public static function shutdown_process_kicks(): void {
        if ( empty( self::$kick_ids ) ) {
            return;
        }

        // Try to finish the response quickly, then commit.
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            @fastcgi_finish_request();
        }

        @ignore_user_abort( true );
        @set_time_limit( 0 );

        self::process_by_ids( self::$kick_ids );
    }

    /**
     * Process specific queue ids.
     */
    public static function process_by_ids( array $ids ): void {
        global $wpdb;
        $table = self::table_name();
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
        if ( empty( $ids ) ) {
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND id IN ({$placeholders}) ORDER BY id ASC", array_merge( array( 'pending' ), $ids ) );
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id <= 0 ) { continue; }

            // Mark processing
            $wpdb->update(
                $table,
                array( 'status' => 'processing', 'updated_at' => current_time( 'mysql' ) ),
                array( 'id' => $id ),
                array( '%s', '%s' ),
                array( '%d' )
            );

            $payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
            if ( ! is_array( $payload ) ) { $payload = array(); }

            try {
                self::dispatch( (string) $row['object_type'], (string) $row['action'], $payload );
                $wpdb->update(
                    $table,
                    array( 'status' => 'done', 'updated_at' => current_time( 'mysql' ), 'last_error' => null ),
                    array( 'id' => $id ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );
            } catch ( Exception $e ) {
                $attempts = (int) ( $row['attempts'] ?? 0 ) + 1;
                $wpdb->update(
                    $table,
                    array(
                        'status' => 'failed',
                        'attempts' => $attempts,
                        'last_error' => $e->getMessage(),
                        'updated_at' => current_time( 'mysql' ),
                    ),
                    array( 'id' => $id ),
                    array( '%s', '%d', '%s', '%s' ),
                    array( '%d' )
                );
            }
        }
    }

    public static function schedule_worker(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'expman_every_minute', self::CRON_HOOK );
        }
    }

    public static function clear_worker(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
    }

    /**
     * Process up to $limit pending queue items.
     */
    public static function process( int $limit = 10 ): void {
        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d",
                'pending',
                $limit
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id <= 0 ) { continue; }

            // Mark processing
            $wpdb->update(
                $table,
                array( 'status' => 'processing', 'updated_at' => current_time( 'mysql' ) ),
                array( 'id' => $id ),
                array( '%s', '%s' ),
                array( '%d' )
            );

            $payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
            if ( ! is_array( $payload ) ) { $payload = array(); }

            try {
                self::dispatch( (string) $row['object_type'], (string) $row['action'], $payload );

                $wpdb->update(
                    $table,
                    array( 'status' => 'done', 'updated_at' => current_time( 'mysql' ), 'last_error' => null ),
                    array( 'id' => $id ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );
            } catch ( Exception $e ) {
                $attempts = (int) ( $row['attempts'] ?? 0 ) + 1;
                $wpdb->update(
                    $table,
                    array(
                        'status' => 'failed',
                        'attempts' => $attempts,
                        'last_error' => $e->getMessage(),
                        'updated_at' => current_time( 'mysql' ),
                    ),
                    array( 'id' => $id ),
                    array( '%s', '%d', '%s', '%s' ),
                    array( '%d' )
                );
            }
        }
    }

    private static function dispatch( string $object_type, string $action, array $payload ): void {
        if ( $object_type === 'domains' && $action === 'upsert' ) {
            if ( ! class_exists( 'Expman_Domains_Manager' ) ) {
                throw new Exception( 'Domains manager not loaded.' );
            }
            $mgr = new Expman_Domains_Manager();
            $mgr->ensure_schema();
            global $wpdb;
            $table = $mgr->get_table_name();

            $id = (int) ( $payload['id'] ?? 0 );
            $data = $payload['data'] ?? array();
            if ( ! is_array( $data ) ) { $data = array(); }

            if ( $id > 0 ) {
                $prev = $payload['prev'] ?? null;
                if ( ! is_array( $prev ) ) {
                    $prev = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ), ARRAY_A );
                }
                $wpdb->update( $table, $data, array( 'id' => $id ) );
                $domain = (string) ( $payload['domain'] ?? ( $data['domain'] ?? '' ) );

                // Log changes
                if ( method_exists( $mgr, 'log_event' ) ) {
                    $changed = array();
                    if ( is_array( $prev ) ) {
                        foreach ( $data as $k => $v ) {
                            $old = isset( $prev[$k] ) ? (string) $prev[$k] : '';
                            $new = ( $v === null ) ? '' : (string) $v;
                            if ( $old !== $new ) {
                                $changed[$k] = array( 'from' => $old, 'to' => $new );
                            }
                        }
                    }
                    $mgr->log_event( 'domain_update', $id, $domain, array( 'changed' => $changed, 'queued' => true ) );
                }
            } else {
                $data['created_at'] = $data['created_at'] ?? current_time( 'mysql' );
                $wpdb->insert( $table, $data );
                $new_id = (int) $wpdb->insert_id;
                $domain = (string) ( $payload['domain'] ?? ( $data['domain'] ?? '' ) );
                if ( method_exists( $mgr, 'log_event' ) ) {
                    $mgr->log_event( 'domain_create', $new_id, $domain, array( 'data' => $data, 'queued' => true ) );
                }
            }

            return;
        }

        if ( $object_type === 'servers' && $action === 'create' ) {
            if ( ! class_exists( 'Expman_Servers_Page' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'class-expman-servers-page.php';
            }
            if ( ! class_exists( 'Expman_Servers_Page' ) ) {
                throw new Exception( 'Servers module not loaded.' );
            }
            Expman_Servers_Page::install_tables();
            $page = new Expman_Servers_Page( Expiry_Manager_Plugin::OPTION_KEY, Expiry_Manager_Plugin::VERSION );

            global $wpdb;
            $table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
            $data  = $payload['data'] ?? array();
            if ( ! is_array( $data ) ) { $data = array(); }
            $wpdb->insert( $table, $data );
            $new_id = (int) $wpdb->insert_id;
            if ( $new_id && ! empty( $payload['sync_now'] ) ) {
                $page->get_actions()->set_option_key( Expiry_Manager_Plugin::OPTION_KEY );
                $page->get_actions()->set_dell( $page->get_dell() );
                $page->get_actions()->sync_server_by_id( $new_id );
            }
            return;
        }

        throw new Exception( 'Unknown queue action.' );
    }
}
