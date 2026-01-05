<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Servers_Actions' ) ) {
class Expman_Servers_Actions {

    private $logger;
    private $dell;
    private $notifier;
    private $option_key;

    public function __construct( $logger ) {
        $this->logger = $logger;
    }

    public function set_dell( $dell ) {
        $this->dell = $dell;
    }

    public function set_notifier( $notifier ) {
        $this->notifier = $notifier;
    }

    public function set_option_key( $option_key ) {
        $this->option_key = $option_key;
    }

    private function add_notice( $message, $type = 'success' ) {
        if ( is_callable( $this->notifier ) ) {
            call_user_func( $this->notifier, $message, $type );
        }
    }

    public function get_dell_settings() {
        if ( $this->dell ) {
            return $this->dell->get_settings();
        }
        return array();
    }

    private function sanitize_date_value( $value ) {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( $value === '' ) { return null; }

        $formats = array( 'd/m/Y', 'd/m/y', 'd-m-Y', 'd-m-y', 'd.m.Y', 'd.m.y', 'Y-m-d', 'Y/m/d', 'Y-m-d H:i:s' );
        foreach ( $formats as $fmt ) {
            $dt = DateTime::createFromFormat( $fmt, $value );
            if ( $dt instanceof DateTime ) {
                return $dt->format( 'Y-m-d' );
            }
        }

        if ( preg_match( '/^\\d{6}$/', $value ) ) {
            $day = substr( $value, 0, 2 );
            $month = substr( $value, 2, 2 );
            $year = '20' . substr( $value, 4, 2 );
            $dt = DateTime::createFromFormat( 'd/m/Y', "{$day}/{$month}/{$year}" );
            if ( $dt instanceof DateTime ) {
                return $dt->format( 'Y-m-d' );
            }
        }

        if ( preg_match( '/^\\d{8}$/', $value ) ) {
            $day = substr( $value, 0, 2 );
            $month = substr( $value, 2, 2 );
            $year = substr( $value, 4, 4 );
            $dt = DateTime::createFromFormat( 'd/m/Y', "{$day}/{$month}/{$year}" );
            if ( $dt instanceof DateTime ) {
                return $dt->format( 'Y-m-d' );
            }
        }

        $ts = strtotime( $value );
        if ( $ts ) {
            return gmdate( 'Y-m-d', $ts );
        }

        return null;
    }

    
    public function action_save_server() {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;

        $id = intval( $_POST['server_id'] ?? 0 );

        $customer_id     = intval( $_POST['customer_id'] ?? 0 );
        $customer_number = sanitize_text_field( wp_unslash( $_POST['customer_number'] ?? '' ) );
        $customer_name   = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );

        $service_tag           = strtoupper( sanitize_text_field( wp_unslash( $_POST['service_tag'] ?? '' ) ) );
        $express_service_code  = sanitize_text_field( wp_unslash( $_POST['express_service_code'] ?? '' ) );
        $ship_date             = $this->sanitize_date_value( sanitize_text_field( wp_unslash( $_POST['ship_date'] ?? '' ) ) );
        $ending_on             = $this->sanitize_date_value( sanitize_text_field( wp_unslash( $_POST['ending_on'] ?? '' ) ) );
        $last_renewal_date     = $this->sanitize_date_value( sanitize_text_field( wp_unslash( $_POST['last_renewal_date'] ?? '' ) ) );
        $operating_system      = sanitize_text_field( wp_unslash( $_POST['operating_system'] ?? '' ) );
        $service_level         = sanitize_text_field( wp_unslash( $_POST['service_level'] ?? '' ) );
        $server_model          = sanitize_text_field( wp_unslash( $_POST['server_model'] ?? '' ) );
        $nickname              = sanitize_text_field( wp_unslash( $_POST['nickname'] ?? '' ) );

        $notes        = wp_kses_post( wp_unslash( $_POST['notes'] ?? '' ) );
        $temp_enabled = isset( $_POST['temp_notice_enabled'] ) ? 1 : 0;
        $temp_notice  = wp_kses_post( wp_unslash( $_POST['temp_notice_text'] ?? '' ) );
        $sync_now     = isset( $_POST['sync_now'] ) ? 1 : 0;

        if ( ! $temp_enabled ) {
            $temp_notice = '';
        }

        $errors = array();
        if ( $service_tag === '' ) {
            $errors[] = 'Service Tag חובה.';
        }

        $existing_row = null;
        if ( $service_tag !== '' ) {
            $existing_row = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$servers_table} WHERE service_tag=%s", $service_tag ),
                ARRAY_A
            );

            if ( $existing_row ) {
                $existing_id = intval( $existing_row['id'] ?? 0 );
                $is_deleted  = ! empty( $existing_row['deleted_at'] );

                if ( $id > 0 && $existing_id !== $id ) {
                    $errors[] = 'Service Tag כבר קיים במערכת.';
                } elseif ( $id === 0 && ! $is_deleted ) {
                    $errors[] = 'Service Tag כבר קיים במערכת.';
                } elseif ( $id === 0 && $is_deleted ) {
                    // Reuse deleted row to bypass UNIQUE(service_tag)
                    $id = $existing_id;
                }
            }
        }

        if ( ! empty( $errors ) ) {
            set_transient( 'expman_servers_errors', $errors, 90 );
            return;
        }

        $data = array(
            'option_key'               => $this->option_key,
            'customer_id'              => $customer_id > 0 ? $customer_id : null,
            'customer_number_snapshot' => $customer_number !== '' ? $customer_number : null,
            'customer_name_snapshot'   => $customer_name !== '' ? $customer_name : null,
            'service_tag'              => $service_tag,
            'express_service_code'     => $express_service_code !== '' ? $express_service_code : null,
            'ship_date'                => $ship_date,
            'ending_on'                => $ending_on,
            'last_renewal_date'        => $last_renewal_date,
            'operating_system'         => $operating_system !== '' ? $operating_system : null,
            'service_level'            => $service_level !== '' ? $service_level : null,
            'server_model'             => $server_model !== '' ? $server_model : null,
            'nickname'                 => $nickname !== '' ? $nickname : null,
            'notes'                    => $notes,
            'temp_notice_enabled'      => $temp_enabled,
            'temp_notice_text'         => $temp_notice,
            'updated_at'               => current_time( 'mysql' ),
        );

        // If we reused a deleted row - restore it
        if ( $id > 0 && $existing_row && ! empty( $existing_row['deleted_at'] ) ) {
            $data['deleted_at'] = null;
            $data['deleted_by'] = null;
        }

        if ( $id > 0 ) {
            $prev = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$servers_table} WHERE id=%d", $id ), ARRAY_A );
            $ok = $wpdb->update( $servers_table, $data, array( 'id' => $id ) );

            if ( $ok === false ) {
                set_transient( 'expman_servers_errors', array( 'שמירה נכשלה: ' . $wpdb->last_error ), 90 );
                $this->logger->log_server_event( $id, 'update', 'שמירה נכשלה', array( 'error' => $wpdb->last_error ), 'error' );
                return;
            }

            $changes = array();
            if ( $prev ) {
                $fields = array(
                    array( 'label' => 'מספר לקוח', 'from' => $prev['customer_number_snapshot'] ?? '', 'to' => $customer_number ),
                    array( 'label' => 'שם לקוח',   'from' => $prev['customer_name_snapshot'] ?? '', 'to' => $customer_name ),
                    array( 'label' => 'Service Tag', 'from' => $prev['service_tag'] ?? '', 'to' => $service_tag ),
                    array( 'label' => 'Express Service Code', 'from' => $prev['express_service_code'] ?? '', 'to' => $express_service_code ),
                    array( 'label' => 'Ship Date', 'from' => $prev['ship_date'] ?? '', 'to' => $ship_date ),
                    array( 'label' => 'Ending On', 'from' => $prev['ending_on'] ?? '', 'to' => $ending_on ),
                    array( 'label' => 'תאריך חידוש אחרון', 'from' => $prev['last_renewal_date'] ?? '', 'to' => $last_renewal_date ),
                    array( 'label' => 'מערכת הפעלה', 'from' => $prev['operating_system'] ?? '', 'to' => $operating_system ),
                    array( 'label' => 'סוג שירות', 'from' => $prev['service_level'] ?? '', 'to' => $service_level ),
                    array( 'label' => 'דגם שרת', 'from' => $prev['server_model'] ?? '', 'to' => $server_model ),
                    array( 'label' => 'כינוי', 'from' => $prev['nickname'] ?? '', 'to' => $nickname ),
                    array( 'label' => 'הערות', 'from' => $prev['notes'] ?? '', 'to' => $notes ),
                    array( 'label' => 'הודעה זמנית', 'from' => (string) ( $prev['temp_notice_enabled'] ?? 0 ), 'to' => (string) $temp_enabled ),
                    array( 'label' => 'טקסט הודעה זמנית', 'from' => $prev['temp_notice_text'] ?? '', 'to' => $temp_notice ),
                );
                foreach ( $fields as $field ) {
                    if ( (string) $field['from'] !== (string) $field['to'] ) {
                        $changes[] = array(
                            'field' => $field['label'],
                            'from'  => (string) $field['from'],
                            'to'    => (string) $field['to'],
                        );
                    }
                }
            }

            $event = ( $existing_row && ! empty( $existing_row['deleted_at'] ) ) ? 'restore_update' : 'update';
            $msg   = ( $existing_row && ! empty( $existing_row['deleted_at'] ) ) ? 'שרת שוחזר ועודכן' : 'שרת עודכן';
            $this->logger->log_server_event( $id, $event, $msg, array( 'changes' => $changes ), 'info' );
            $this->add_notice( $msg . '.' );

            if ( $sync_now ) {
                $this->sync_server_by_id( $id );
            }
            return;
        }

        $data['created_at'] = current_time( 'mysql' );
        $ok = $wpdb->insert( $servers_table, $data );
        if ( ! $ok ) {
            set_transient( 'expman_servers_errors', array( 'שמירה נכשלה: ' . $wpdb->last_error ), 90 );
            $this->logger->log_server_event( null, 'create', 'שמירה נכשלה', array( 'error' => $wpdb->last_error ), 'error' );
            return;
        }

        $server_id = intval( $wpdb->insert_id );
        $this->logger->log_server_event( $server_id, 'create', 'שרת נוסף', array( 'service_tag' => $service_tag ), 'info' );
        $this->add_notice( 'שרת נוסף.' );

        if ( $sync_now ) {
            $this->sync_server_by_id( $server_id );
        }
    }

    public function save_server_from_request() {
        return $this->action_save_server();
    }

    public function action_trash_server() {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
        $id = intval( $_POST['server_id'] ?? 0 );
        if ( $id <= 0 ) {
            return;
        }
        $wpdb->update(
            $servers_table,
            array(
                'deleted_at' => current_time( 'mysql' ),
                'deleted_by' => get_current_user_id(),
            ),
            array( 'id' => $id )
        );
        $this->logger->log_server_event( $id, 'trash', 'השרת הועבר לסל מחזור', array(), 'info' );
        $this->add_notice( 'השרת הועבר לסל מחזור.' );
    }

    public function action_restore_server() {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
        $id = intval( $_POST['server_id'] ?? 0 );
        if ( $id <= 0 ) {
            return;
        }
        $wpdb->update(
            $servers_table,
            array(
                'deleted_at' => null,
                'deleted_by' => null,
            ),
            array( 'id' => $id )
        );
        $this->logger->log_server_event( $id, 'restore', 'השרת שוחזר', array(), 'info' );
        $this->add_notice( 'השרת שוחזר.' );
    }

    public function action_delete_server_permanently() {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
        $id = intval( $_POST['server_id'] ?? 0 );
        if ( $id <= 0 ) {
            return;
        }
        $wpdb->delete( $servers_table, array( 'id' => $id ) );
        $this->logger->log_server_event( $id, 'delete', 'השרת נמחק לצמיתות', array(), 'info' );
        $this->add_notice( 'השרת נמחק לצמיתות.' );
    }

    public function action_empty_trash() {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
        $wpdb->query( "DELETE FROM {$servers_table} WHERE deleted_at IS NOT NULL" );
        $this->logger->log_server_event( null, 'empty_trash', 'סל המחזור רוקן', array(), 'info' );
        $this->add_notice( 'סל המחזור רוקן.' );
    }

    public function action_archive_server() {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
        $id = intval( $_POST['server_id'] ?? 0 );
        if ( $id <= 0 ) {
            return;
        }
        $wpdb->update(
            $servers_table,
            array(
                'archived_at' => current_time( 'mysql' ),
                'archived_by' => get_current_user_id(),
            ),
            array( 'id' => $id )
        );
        $this->logger->log_server_event( $id, 'archive', 'השרת הועבר לארכיון', array(), 'info' );
        $this->add_notice( 'השרת הועבר לארכיון.' );
    }

    public function action_unarchive_server() {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
        $id = intval( $_POST['server_id'] ?? 0 );
        if ( $id <= 0 ) {
            return;
        }
        $wpdb->update(
            $servers_table,
            array(
                'archived_at' => null,
                'archived_by' => null,
            ),
            array( 'id' => $id )
        );
        $this->logger->log_server_event( $id, 'unarchive', 'השרת הוחזר מהארכיון', array(), 'info' );
        $this->add_notice( 'השרת הוחזר מהארכיון.' );
    }

    private function get_thresholds() {
        $settings = $this->get_dell_settings();
        return array(
            'red'    => intval( $settings['red_days'] ?? 30 ),
            'yellow' => intval( $settings['yellow_days'] ?? 60 ),
        );
    }

    private function status_for_days( $days, $thresholds ) {
        if ( $days === null ) {
            return 'unknown';
        }
        if ( $days <= $thresholds['red'] ) {
            return 'red';
        }
        if ( $days <= $thresholds['yellow'] ) {
            return 'yellow';
        }
        return 'green';
    }

    public function get_servers_rows( $filters = array(), $orderby = 'ending_on', $order = 'ASC', $include_deleted = false, $limit = 0, $offset = 0, $archived_mode = 'exclude' ) {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;

        $where = array( 'option_key = %s' );
        $params = array( $this->option_key );

        if ( ! $include_deleted ) {
            $where[] = 'deleted_at IS NULL';
        }

        if ( $archived_mode === 'exclude' ) {
            $where[] = 'archived_at IS NULL';
        } elseif ( $archived_mode === 'only' ) {
            $where[] = 'archived_at IS NOT NULL';
        }

        if ( ! empty( $filters['customer_number'] ) ) {
            $where[] = 'customer_number_snapshot LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['customer_number'] ) . '%';
        }
        if ( ! empty( $filters['customer_name'] ) ) {
            $where[] = 'customer_name_snapshot LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['customer_name'] ) . '%';
        }
        if ( ! empty( $filters['service_tag'] ) ) {
            $where[] = 'service_tag LIKE %s';
            $params[] = '%' . $wpdb->esc_like( strtoupper( $filters['service_tag'] ) ) . '%';
        }

        $allowed_order = array( 'ending_on', 'service_tag', 'customer_number_snapshot', 'customer_name_snapshot', 'ship_date', 'days_to_end' );
        if ( ! in_array( $orderby, $allowed_order, true ) ) {
            $orderby = 'ending_on';
        }
        $order = strtoupper( $order ) === 'DESC' ? 'DESC' : 'ASC';

        $where_sql = 'WHERE ' . implode( ' AND ', $where );

        $order_sql = "{$orderby} {$order}, id DESC";
        if ( $orderby === 'days_to_end' ) {
            $order_sql = "(ending_on IS NULL) ASC, days_to_end {$order}, id DESC";
        }

        $sql = "SELECT *, DATEDIFF(ending_on, CURDATE()) AS days_to_end FROM {$servers_table} {$where_sql} ORDER BY {$order_sql}";
        $sql = $wpdb->prepare( $sql, $params );

        if ( $limit > 0 ) {
            $limit = intval( $limit );
            $offset = max( 0, intval( $offset ) );
            $sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
        }

        return $wpdb->get_results( $sql );
    }

    public function get_servers_total( $filters = array(), $include_deleted = false, $archived_mode = 'exclude' ) {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;

        $where = array( 'option_key = %s' );
        $params = array( $this->option_key );

        if ( ! $include_deleted ) {
            $where[] = 'deleted_at IS NULL';
        }

        if ( $archived_mode === 'exclude' ) {
            $where[] = 'archived_at IS NULL';
        } elseif ( $archived_mode === 'only' ) {
            $where[] = 'archived_at IS NOT NULL';
        }

        if ( ! empty( $filters['customer_number'] ) ) {
            $where[] = 'customer_number_snapshot LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['customer_number'] ) . '%';
        }
        if ( ! empty( $filters['customer_name'] ) ) {
            $where[] = 'customer_name_snapshot LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['customer_name'] ) . '%';
        }
        if ( ! empty( $filters['service_tag'] ) ) {
            $where[] = 'service_tag LIKE %s';
            $params[] = '%' . $wpdb->esc_like( strtoupper( $filters['service_tag'] ) ) . '%';
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );
        $sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$servers_table} {$where_sql}", $params );
        return intval( $wpdb->get_var( $sql ) );
    }

    public function get_summary_counts() {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
        $thresholds = $this->get_thresholds();

        $counts = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN ending_on IS NOT NULL AND DATEDIFF(ending_on, CURDATE()) > %d THEN 1 ELSE 0 END) AS green_count,
                    SUM(CASE WHEN ending_on IS NOT NULL AND DATEDIFF(ending_on, CURDATE()) BETWEEN %d AND %d THEN 1 ELSE 0 END) AS yellow_count,
                    SUM(CASE WHEN ending_on IS NOT NULL AND DATEDIFF(ending_on, CURDATE()) <= %d THEN 1 ELSE 0 END) AS red_count,
                    COUNT(*) AS total_count
                 FROM {$servers_table}
                 WHERE option_key=%s AND deleted_at IS NULL AND archived_at IS NULL",
                $thresholds['yellow'],
                $thresholds['red'] + 1,
                $thresholds['yellow'],
                $thresholds['red'],
                $this->option_key
            ),
            ARRAY_A
        );

        $trash = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$servers_table} WHERE option_key=%s AND deleted_at IS NOT NULL", $this->option_key ) );
        $archive = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$servers_table} WHERE option_key=%s AND archived_at IS NOT NULL AND deleted_at IS NULL", $this->option_key ) );

        return array(
            'green' => intval( $counts['green_count'] ?? 0 ),
            'yellow' => intval( $counts['yellow_count'] ?? 0 ),
            'red' => intval( $counts['red_count'] ?? 0 ),
            'total' => intval( $counts['total_count'] ?? 0 ),
            'trash' => intval( $trash ),
            'archive' => intval( $archive ),
            'yellow_threshold' => $thresholds['yellow'],
            'red_threshold' => $thresholds['red'],
        );
    }

    public function get_trash_rows() {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *, DATEDIFF(ending_on, CURDATE()) AS days_to_end FROM {$servers_table} WHERE option_key=%s AND deleted_at IS NOT NULL ORDER BY deleted_at DESC",
                $this->option_key
            )
        );
    }

    public function get_logs( $limit = 200 ) {
        global $wpdb;
        $logs_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_LOGS;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT logs.*, srv.customer_name_snapshot, srv.service_tag
                 FROM {$logs_table} logs
                 LEFT JOIN {$servers_table} srv ON srv.id = logs.server_id
                 ORDER BY logs.created_at DESC
                 LIMIT %d",
                intval( $limit )
            )
        );
    }

    public function action_sync_server() {
        $id = intval( $_POST['server_id'] ?? 0 );
        if ( $id <= 0 ) {
            return;
        }
        $this->sync_servers_by_ids( array( $id ) );
    }

    public function sync_server_by_id( $id ) {
        $id = intval( $id );
        if ( $id <= 0 ) {
            return;
        }
        $this->sync_servers_by_ids( array( $id ) );
    }

    public function action_sync_bulk() {
        $ids = array_map( 'intval', (array) ( $_POST['server_ids'] ?? array() ) );
        $ids = array_filter( $ids );
        if ( empty( $ids ) ) {
            $this->add_notice( 'לא נבחרו שרתים לסנכרון.', 'warning' );
            return;
        }
        $this->sync_servers_by_ids( $ids );
    }

    private function sync_servers_by_ids( $ids ) {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
        $tracking_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_TRACKING;

        $ids = array_map( 'intval', $ids );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = $wpdb->prepare( "SELECT * FROM {$servers_table} WHERE id IN ({$placeholders})", $ids );
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( empty( $rows ) ) {
            return;
        }

        $tags = array();
        foreach ( $rows as $row ) {
            $tags[] = $row['service_tag'];
        }

        if ( ! $this->dell ) {
            $this->add_notice( 'Dell API לא זמין.', 'error' );
            return;
        }

        $request_id = $this->logger->new_request_id();
        $this->logger->log_server_event( null, 'sync', 'התחלת סנכרון Dell', array( 'request_id' => $request_id, 'count' => count( $tags ) ), 'info' );

        $response = $this->dell->fetch_warranty_bulk( $tags );
        if ( is_wp_error( $response ) ) {
            $this->logger->log_server_event( null, 'sync', 'Dell API שגיאה', array( 'request_id' => $request_id, 'error' => $response->get_error_message() ), 'error' );
            $this->add_notice( 'שגיאת סנכרון: ' . $response->get_error_message(), 'error' );
            return;
        }

        $thresholds = $this->get_thresholds();
        $updated = 0;
        foreach ( $rows as $row ) {
            $tag = strtoupper( $row['service_tag'] );
            $payload = $response[ $tag ] ?? null;
            if ( ! $payload ) {
                continue;
            }

            $ending_on = $payload['ending_on'] ?? null;
            $temp_enabled = intval( $row['temp_notice_enabled'] );
            $temp_notice = (string) ( $row['temp_notice_text'] ?? '' );
            $days = null;
            if ( $ending_on ) {
                $days = (int) ( ( strtotime( $ending_on ) - strtotime( gmdate( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
            }

            if ( $days !== null && $days > $thresholds['yellow'] ) {
                $temp_enabled = 0;
                $temp_notice = '';
            }

            $update = array(
                'express_service_code' => $payload['express_service_code'] ?? null,
                'ship_date'            => $payload['ship_date'] ?? null,
                'ending_on'            => $ending_on,
                'service_level'        => $payload['service_level'] ?? null,
                'server_model'         => $payload['server_model'] ?? null,
                'raw_json'             => isset( $payload['raw_json'] ) ? wp_json_encode( $payload['raw_json'], JSON_UNESCAPED_UNICODE ) : null,
                'last_sync_at'         => current_time( 'mysql' ),
                'temp_notice_enabled'  => $temp_enabled,
                'temp_notice_text'     => $temp_notice,
                'updated_at'           => current_time( 'mysql' ),
            );

            $wpdb->update( $servers_table, $update, array( 'id' => $row['id'] ) );

            $wpdb->insert(
                $tracking_table,
                array(
                    'server_id'   => $row['id'],
                    'action'      => 'sync',
                    'before_json' => wp_json_encode( $row, JSON_UNESCAPED_UNICODE ),
                    'after_json'  => wp_json_encode( array_merge( $row, $update ), JSON_UNESCAPED_UNICODE ),
                    'meta'        => wp_json_encode( array( 'request_id' => $request_id ), JSON_UNESCAPED_UNICODE ),
                    'created_at'  => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s' )
            );
            $updated++;
        }

        $this->logger->log_server_event( null, 'sync', 'סנכרון Dell הושלם', array( 'request_id' => $request_id, 'updated' => $updated ), 'info' );
        $this->add_notice( 'סנכרון Dell הושלם. עודכנו ' . $updated . ' רשומות.' );
    }

    public function action_import_csv() {
        return ( new Expman_Servers_Importer( $this->logger, $this->option_key ) )->run();
    }

    public function action_import_excel_settings() {
        return ( new Expman_Servers_Importer( $this->logger, $this->option_key ) )->run( 'servers_excel_file' );
    }

    public function action_import_csv_direct() {
        return ( new Expman_Servers_Importer( $this->logger, $this->option_key ) )->run_direct( 'servers_direct_file' );
    }

    public function get_stage_rows() {
        global $wpdb;
        $stage_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_IMPORT_STAGE;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$stage_table} WHERE option_key=%s ORDER BY created_at DESC", $this->option_key )
        );
    }

    public function action_assign_import_stage() {
        global $wpdb;
        $stage_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_IMPORT_STAGE;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;

        $stage_id = intval( $_POST['stage_id'] ?? 0 );
        if ( $stage_id <= 0 ) {
            return;
        }

        $stage = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$stage_table} WHERE id=%d", $stage_id ), ARRAY_A );
        if ( ! $stage ) {
            return;
        }

        $customer_id = intval( $_POST['customer_id'] ?? 0 );
        $customer_number = sanitize_text_field( wp_unslash( $_POST['customer_number'] ?? $stage['customer_number'] ?? '' ) );
        $customer_name = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? $stage['customer_name'] ?? '' ) );
        $service_tag = strtoupper( sanitize_text_field( wp_unslash( $_POST['service_tag'] ?? $stage['service_tag'] ?? '' ) ) );
        $express_service_code = sanitize_text_field( wp_unslash( $_POST['express_service_code'] ?? $stage['express_service_code'] ?? '' ) );
        $ship_date_raw = sanitize_text_field( wp_unslash( $_POST['ship_date'] ?? $stage['ship_date'] ?? '' ) );
        $ending_on_raw = sanitize_text_field( wp_unslash( $_POST['ending_on'] ?? $stage['ending_on'] ?? '' ) );
        $ship_date = $ship_date_raw !== '' ? $this->sanitize_date_value( $ship_date_raw ) : null;
        $ending_on = $ending_on_raw !== '' ? $this->sanitize_date_value( $ending_on_raw ) : null;
        $operating_system = sanitize_text_field( wp_unslash( $_POST['operating_system'] ?? $stage['operating_system'] ?? '' ) );
        $service_level = sanitize_text_field( wp_unslash( $_POST['service_level'] ?? $stage['service_level'] ?? '' ) );
        $server_model = sanitize_text_field( wp_unslash( $_POST['server_model'] ?? $stage['server_model'] ?? '' ) );
        $temp_notice_enabled = ! empty( $_POST['temp_notice_enabled'] ) ? 1 : intval( $stage['temp_notice_enabled'] ?? 0 );
        $temp_notice_text = sanitize_text_field( wp_unslash( $_POST['temp_notice_text'] ?? $stage['temp_notice_text'] ?? '' ) );
        $notes = wp_kses_post( wp_unslash( $_POST['notes'] ?? $stage['notes'] ?? '' ) );

        if ( $service_tag === '' ) {
            set_transient( 'expman_servers_errors', array( 'Service Tag חסר בשורת שיוך.' ), 90 );
            return;
        }

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$servers_table} WHERE service_tag=%s AND deleted_at IS NULL", $service_tag ) );
        if ( $existing ) {
            set_transient( 'expman_servers_errors', array( 'Service Tag כבר קיים במערכת.' ), 90 );
            return;
        }

        $data = array(
            'option_key'               => $this->option_key,
            'customer_id'              => $customer_id > 0 ? $customer_id : null,
            'customer_number_snapshot' => $customer_number !== '' ? $customer_number : null,
            'customer_name_snapshot'   => $customer_name !== '' ? $customer_name : null,
            'service_tag'              => $service_tag,
            'express_service_code'     => $express_service_code !== '' ? $express_service_code : null,
            'ship_date'                => $ship_date,
            'ending_on'                => $ending_on,
            'operating_system'         => $operating_system !== '' ? $operating_system : null,
            'service_level'            => $service_level !== '' ? $service_level : null,
            'server_model'             => $server_model !== '' ? $server_model : null,
            'temp_notice_enabled'      => $temp_notice_enabled,
            'temp_notice_text'         => $temp_notice_text !== '' ? $temp_notice_text : null,
            'notes'                    => $notes,
            'created_at'               => current_time( 'mysql' ),
            'updated_at'               => current_time( 'mysql' ),
        );

        $ok = $wpdb->insert( $servers_table, $data );
        if ( ! $ok ) {
            set_transient( 'expman_servers_errors', array( 'שיוך נכשל: ' . $wpdb->last_error ), 90 );
            return;
        }

        $server_id = $wpdb->insert_id;
        $wpdb->delete( $stage_table, array( 'id' => $stage_id ) );

        $this->logger->log_server_event( $server_id, 'assign', 'שיוך משלב ייבוא', array( 'stage_id' => $stage_id ), 'info' );
        $this->add_notice( 'השורה שויכה ונוצר שרת.' );
    }

    public function action_assign_import_stage_bulk() {
        global $wpdb;
        $stage_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_IMPORT_STAGE;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;

        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$stage_table} WHERE option_key=%s ORDER BY created_at ASC", $this->option_key ),
            ARRAY_A
        );

        $assigned = 0;
        $skipped = 0;
        foreach ( (array) $rows as $stage ) {
            $service_tag = strtoupper( sanitize_text_field( $stage['service_tag'] ?? '' ) );
            $customer_number = sanitize_text_field( $stage['customer_number'] ?? '' );
            $customer_name = sanitize_text_field( $stage['customer_name'] ?? '' );
            if ( $service_tag === '' || $customer_number === '' || $customer_name === '' ) {
                $skipped++;
                continue;
            }

            $existing = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$servers_table} WHERE service_tag=%s AND deleted_at IS NULL", $service_tag )
            );
            if ( $existing ) {
                $skipped++;
                continue;
            }

            $data = array(
                'option_key'               => $this->option_key,
                'customer_number_snapshot' => $customer_number,
                'customer_name_snapshot'   => $customer_name,
                'service_tag'              => $service_tag,
                'express_service_code'     => ! empty( $stage['express_service_code'] ) ? sanitize_text_field( $stage['express_service_code'] ) : null,
                'ship_date'                => ! empty( $stage['ship_date'] ) ? $this->sanitize_date_value( $stage['ship_date'] ) : null,
                'ending_on'                => ! empty( $stage['ending_on'] ) ? $this->sanitize_date_value( $stage['ending_on'] ) : null,
                'operating_system'         => ! empty( $stage['operating_system'] ) ? sanitize_text_field( $stage['operating_system'] ) : null,
                'service_level'            => ! empty( $stage['service_level'] ) ? sanitize_text_field( $stage['service_level'] ) : null,
                'server_model'             => ! empty( $stage['server_model'] ) ? sanitize_text_field( $stage['server_model'] ) : null,
                'temp_notice_enabled'      => ! empty( $stage['temp_notice_enabled'] ) ? 1 : 0,
                'temp_notice_text'         => ! empty( $stage['temp_notice_text'] ) ? sanitize_text_field( $stage['temp_notice_text'] ) : null,
                'notes'                    => ! empty( $stage['notes'] ) ? wp_kses_post( $stage['notes'] ) : null,
                'created_at'               => current_time( 'mysql' ),
                'updated_at'               => current_time( 'mysql' ),
            );

            $ok = $wpdb->insert( $servers_table, $data );
            if ( ! $ok ) {
                $skipped++;
                continue;
            }

            $wpdb->delete( $stage_table, array( 'id' => $stage['id'] ) );
            $assigned++;
        }

        $this->logger->log_server_event( null, 'assign_bulk', 'שיוך גורף משלב ייבוא', array( 'assigned' => $assigned, 'skipped' => $skipped ), 'info' );
        $this->add_notice( 'שיוך גורף בוצע. שויכו ' . $assigned . ' רשומות, דולגו ' . $skipped . '.' );
    }

    public function action_delete_import_stage() {
        global $wpdb;
        $stage_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_IMPORT_STAGE;
        $stage_id = intval( $_POST['stage_id'] ?? 0 );
        if ( $stage_id <= 0 ) {
            return;
        }
        $wpdb->delete( $stage_table, array( 'id' => $stage_id ) );
        $this->logger->log_server_event( null, 'import_delete', 'שורת שיוך נמחקה', array( 'stage_id' => $stage_id ), 'info' );
        $this->add_notice( 'שורת שיוך נמחקה.' );
    }

    public function action_empty_import_stage() {
        global $wpdb;
        $stage_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_IMPORT_STAGE;
        if ( empty( $this->option_key ) ) {
            return;
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$stage_table} WHERE option_key=%s", $this->option_key ) );
        $this->logger->log_server_event( null, 'stage_clear', 'טבלת שיוך נוקתה', array( 'option_key' => $this->option_key ), 'info' );
        $this->add_notice( 'טבלת שיוך נוקתה.' );
    }

    public function action_export_csv() {
        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$servers_table} WHERE option_key=%s ORDER BY id DESC",
                $this->option_key
            ),
            ARRAY_A
        );

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        $filename = 'expman_servers_' . gmdate( 'Ymd_His' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        if ( $out ) {
            fwrite( $out, "\xEF\xBB\xBF" );
            fputcsv( $out, array(
                'ID',
                'Option Key',
                'Customer ID',
                'מספר לקוח',
                'שם לקוח',
                'Service Tag',
                'Express Service Code',
                'Ship Date',
                'Ending On',
                'תאריך חידוש אחרון',
                'מערכת הפעלה',
                'סוג שירות',
                'דגם שרת',
                'כינוי',
                'הודעה זמנית פעילה',
                'טקסט הודעה זמנית',
                'הערות',
                'Raw JSON',
                'Last Sync',
                'Archived At',
                'Archived By',
                'Deleted At',
                'Deleted By',
                'Created At',
                'Updated At',
            ) );

            foreach ( (array) $rows as $row ) {
                fputcsv( $out, array(
                    $row['id'] ?? '',
                    $row['option_key'] ?? '',
                    $row['customer_id'] ?? '',
                    $row['customer_number_snapshot'] ?? '',
                    $row['customer_name_snapshot'] ?? '',
                    $row['service_tag'] ?? '',
                    $row['express_service_code'] ?? '',
                    $row['ship_date'] ?? '',
                    $row['ending_on'] ?? '',
                    $row['last_renewal_date'] ?? '',
                    $row['operating_system'] ?? '',
                    $row['service_level'] ?? '',
                    $row['server_model'] ?? '',
                    $row['nickname'] ?? '',
                    ! empty( $row['temp_notice_enabled'] ) ? 'כן' : 'לא',
                    $row['temp_notice_text'] ?? '',
                    $row['notes'] ?? '',
                    $row['raw_json'] ?? '',
                    $row['last_sync_at'] ?? '',
                    $row['archived_at'] ?? '',
                    $row['archived_by'] ?? '',
                    $row['deleted_at'] ?? '',
                    $row['deleted_by'] ?? '',
                    $row['created_at'] ?? '',
                    $row['updated_at'] ?? '',
                ) );
            }

            fclose( $out );
        }

        $this->logger->log_server_event( null, 'export', 'ייצוא CSV בוצע', array( 'count' => count( $rows ) ), 'info' );
        exit;
    }
}
}
