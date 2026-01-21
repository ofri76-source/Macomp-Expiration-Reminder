<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SSL module logs (separate table).
 */
class Expman_SSL_Logs {
    const TABLE_SLUG = 'expman_ssl_logs';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    public static function install_table() {
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            level VARCHAR(20) NOT NULL,
            action VARCHAR(80) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            request_uri TEXT NULL,
            remote_ip VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Write a log entry.
     *
     * @param string $level   info|warn|error|debug
     * @param string $action  short action key
     * @param string $message message
     * @param array  $context extra structured data
     */
    public static function add( $level, $action, $message, $context = array() ) {
        global $wpdb;
        $table = self::table_name();

        // Best-effort: if table missing (e.g. activation skipped), create it once.
        static $ensured = false;
        if ( ! $ensured ) {
            $ensured = true;
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( ! $exists ) {
                self::install_table();
            }
        }

        $level = sanitize_key( (string) $level );
        $action = sanitize_key( (string) $action );
        $message = (string) $message;

        $ctx = ! empty( $context ) ? wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null;

        $wpdb->insert(
            $table,
            array(
                'level'       => $level,
                'action'      => $action,
                'message'     => $message,
                'context'     => $ctx,
                'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null,
                'remote_ip'   => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : null,
                'created_at'  => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    public static function cleanup_older_than_days( $keep_days ) {
        global $wpdb;
        $table = self::table_name();
        $keep_days = max( 1, intval( $keep_days ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < (NOW() - INTERVAL %d DAY)", $keep_days ) );
    }

    public static function clear_all() {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query( "TRUNCATE TABLE {$table}" );
    }

    public static function query( $args = array() ) {
        global $wpdb;
        $table = self::table_name();

        $defaults = array(
            'search'   => '',
            'level'    => '',
            'action'   => '',
            'date_from'=> '',
            'date_to'  => '',
            'paged'    => 1,
            'per_page' => 50,
        );
        $args = wp_parse_args( $args, $defaults );

        $where = array( '1=1' );
        $params = array();

        if ( $args['level'] !== '' ) {
            $where[] = 'level = %s';
            $params[] = sanitize_key( $args['level'] );
        }
        if ( $args['action'] !== '' ) {
            $where[] = 'action = %s';
            $params[] = sanitize_key( $args['action'] );
        }
        if ( $args['search'] !== '' ) {
            $like = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
            $where[] = '(message LIKE %s OR context LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        if ( $args['date_from'] !== '' ) {
            $where[] = 'created_at >= %s';
            $params[] = (string) $args['date_from'];
        }
        if ( $args['date_to'] !== '' ) {
            $where[] = 'created_at <= %s';
            $params[] = (string) $args['date_to'];
        }

        $where_sql = implode( ' AND ', $where );
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $rows_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

        $paged = max( 1, intval( $args['paged'] ) );
        $per_page = max( 1, min( 500, intval( $args['per_page'] ) ) );
        $offset = ( $paged - 1 ) * $per_page;

        $total = intval( $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ) );

        $rows_params = $params;
        $rows_params[] = $per_page;
        $rows_params[] = $offset;
        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A );

        return array(
            'total' => $total,
            'rows'  => $rows,
            'paged' => $paged,
            'per_page' => $per_page,
            'pages' => (int) ceil( $total / $per_page ),
        );
    }
}
