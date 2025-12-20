<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Firewalls_Actions' ) ) {
class Expman_Firewalls_Actions {

    private $logger;
    private $forticloud;
    private $notifier;
    private $option_key;

    public function __construct( $logger ) {
        $this->logger = $logger;
    }

    public function set_forticloud( $forticloud ) {
        $this->forticloud = $forticloud;
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

    private function column_exists( $table, $column ) {
        global $wpdb;
        $col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
        return ! empty( $col );
    }

    private function fw_column_exists( $column ) {
        global $wpdb;
        $fw_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        return $this->column_exists( $fw_table, $column );
    }

    public function action_save_firewall() {
        global $wpdb;
        $fw_table    = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $types_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_TYPES;

        $id          = isset( $_POST['firewall_id'] ) ? intval( $_POST['firewall_id'] ) : 0;
        $customer_id = isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : 0;

        $customer_number = sanitize_text_field( $_POST['customer_number'] ?? '' );
        $customer_name   = sanitize_text_field( $_POST['customer_name'] ?? '' );

        $branch      = sanitize_text_field( $_POST['branch'] ?? '' );
        $serial      = sanitize_text_field( $_POST['serial_number'] ?? '' );

        $is_managed  = isset( $_POST['is_managed'] ) ? intval( $_POST['is_managed'] ) : 1;
        $track_only  = isset( $_POST['track_only'] ) ? 1 : 0;

        $expiry      = sanitize_text_field( $_POST['expiry_date'] ?? '' );
        $access_url  = sanitize_text_field( $_POST['access_url'] ?? '' );
        $notes       = wp_kses_post( $_POST['notes'] ?? '' );

        $temp_enabled = isset( $_POST['temp_notice_enabled'] ) ? 1 : 0;
        $temp_notice  = wp_kses_post( $_POST['temp_notice'] ?? '' );

        $vendor      = sanitize_text_field( $_POST['vendor'] ?? '' );
        $model       = sanitize_text_field( $_POST['model'] ?? '' );

        $prev_row = null;
        if ( $id > 0 ) {
            $prev_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT fw.*, bt.vendor, bt.model FROM {$fw_table} fw LEFT JOIN {$types_table} bt ON bt.id = fw.box_type_id WHERE fw.id=%d",
                    $id
                ),
                ARRAY_A
            );
        }

        $errors = array();
        if ( $serial === '' ) { $errors[] = 'מספר סידורי חובה.'; }

        // Unique serial validation (excluding trashed)
        if ( $serial !== '' ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$fw_table} WHERE serial_number=%s AND deleted_at IS NULL AND archived_at IS NULL AND id != %d LIMIT 1",
                $serial, $id
            ) );
            if ( $existing ) {
                $errors[] = 'מספר סידורי כבר קיים במערכת.';
            }
        }

        if ( ! empty( $expiry ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiry ) ) {
            $errors[] = 'תאריך תפוגה לא תקין.';
        }

        if ( $access_url !== '' && ! preg_match( '/^https?:\/\//i', $access_url ) ) {
            $access_url = 'https://' . ltrim( $access_url );
        }
        if ( $access_url !== '' && ! filter_var( $access_url, FILTER_VALIDATE_URL ) ) {
            $errors[] = 'URL לגישה מהירה אינו תקין.';
        }

        // Box type (vendor/model select). If one is set, both required.
        $box_type_id = null;
        if ( $vendor !== '' || $model !== '' ) {
            if ( $vendor === '' || $model === '' ) {
                $errors[] = 'יש לבחור גם יצרן וגם דגם.';
            } else {
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$types_table} WHERE vendor=%s AND model=%s",
                    $vendor, $model
                ) );
                if ( $existing ) {
                    $box_type_id = (int) $existing;
                } else {
                    $wpdb->insert(
                        $types_table,
                        array(
                            'vendor'     => $vendor,
                            'model'      => $model,
                            'created_at' => current_time( 'mysql' ),
                            'updated_at' => current_time( 'mysql' ),
                        ),
                        array( '%s', '%s', '%s', '%s' )
                    );
                    $box_type_id = (int) $wpdb->insert_id;
                }
            }
        }

        // Temp notice rules
        if ( ! $temp_enabled ) {
            $temp_notice = '';
        }

        // If renewed (expiry increased), auto clear temp notice
        if ( $id > 0 && $expiry !== '' ) {
            $prev = $wpdb->get_var( $wpdb->prepare( "SELECT expiry_date FROM {$fw_table} WHERE id=%d", $id ) );
            if ( $prev && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $prev ) ) {
                if ( strtotime( $expiry ) > strtotime( (string) $prev ) ) {
                    $temp_enabled = 0;
                    $temp_notice  = '';
                }
            }
        }

        if ( ! empty( $errors ) ) {
            set_transient( 'expman_firewalls_errors', $errors, 90 );
            return;
        }

        $data = array(
            'customer_id'          => $customer_id > 0 ? $customer_id : null,
            'customer_number'      => $customer_number !== '' ? $customer_number : null,
            'customer_name'        => $customer_name !== '' ? $customer_name : null,
            'branch'               => $branch !== '' ? $branch : null,
            'serial_number'        => $serial,
            'is_managed'           => $is_managed ? 1 : 0,
            'track_only'           => $track_only ? 1 : 0,
            'box_type_id'          => $box_type_id,
            'expiry_date'          => $expiry !== '' ? $expiry : null,
            'access_url'           => $access_url !== '' ? $access_url : null,
            'notes'                => $notes,
            'temp_notice_enabled'  => $temp_enabled,
            'temp_notice'          => $temp_notice,
            'updated_at'           => current_time( 'mysql' ),
        );

        // Backward compatibility: if schema migration didn't apply, don't fail the save.
        if ( ! $this->column_exists( $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS, 'customer_number' ) ) { unset( $data['customer_number'] ); }
        if ( ! $this->column_exists( $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS, 'customer_name' ) )   { unset( $data['customer_name'] ); }
        // Backward compatibility: if migration didn't run for some reason, don't fail the save.
        if ( ! $this->fw_column_exists( 'customer_number' ) ) { unset( $data['customer_number'] ); }
        if ( ! $this->fw_column_exists( 'customer_name' ) )   { unset( $data['customer_name'] ); }

        if ( $id > 0 ) {
            $ok = $wpdb->update( $fw_table, $data, array( 'id' => $id ) );
            if ( $ok === false ) {
                set_transient( 'expman_firewalls_errors', array( 'שמירה נכשלה: ' . $wpdb->last_error ), 90 );
                $this->logger->log_firewall_event( $id, 'update', 'שמירה נכשלה', array( 'error' => $wpdb->last_error ), 'error' );
            } else {
                $changes = array();
                if ( ! empty( $prev_row ) ) {
                    $fields = array(
                        array( 'key' => 'customer_number', 'label' => 'מספר לקוח', 'type' => 'text', 'from' => $prev_row['customer_number'] ?? '', 'to' => $customer_number ),
                        array( 'key' => 'customer_name', 'label' => 'שם לקוח', 'type' => 'text', 'from' => $prev_row['customer_name'] ?? '', 'to' => $customer_name ),
                        array( 'key' => 'branch', 'label' => 'סניף', 'type' => 'text', 'from' => $prev_row['branch'] ?? '', 'to' => $branch ),
                        array( 'key' => 'serial_number', 'label' => 'מספר סידורי', 'type' => 'text', 'from' => $prev_row['serial_number'] ?? '', 'to' => $serial ),
                        array( 'key' => 'is_managed', 'label' => 'ניהול', 'type' => 'bool', 'from' => $prev_row['is_managed'] ?? 0, 'to' => $is_managed ),
                        array( 'key' => 'track_only', 'label' => 'לקוח למעקב', 'type' => 'bool', 'from' => $prev_row['track_only'] ?? 0, 'to' => $track_only ),
                        array( 'key' => 'vendor', 'label' => 'יצרן', 'type' => 'text', 'from' => $prev_row['vendor'] ?? '', 'to' => $vendor ),
                        array( 'key' => 'model', 'label' => 'דגם', 'type' => 'text', 'from' => $prev_row['model'] ?? '', 'to' => $model ),
                        array( 'key' => 'expiry_date', 'label' => 'תאריך תפוגה', 'type' => 'date', 'from' => $prev_row['expiry_date'] ?? '', 'to' => $expiry ),
                        array( 'key' => 'access_url', 'label' => 'כתובת גישה', 'type' => 'text', 'from' => $prev_row['access_url'] ?? '', 'to' => $access_url ),
                        array( 'key' => 'notes', 'label' => 'הערה קבועה', 'type' => 'text', 'from' => $prev_row['notes'] ?? '', 'to' => $notes ),
                        array( 'key' => 'temp_notice_enabled', 'label' => 'הודעה זמנית פעילה', 'type' => 'bool', 'from' => $prev_row['temp_notice_enabled'] ?? 0, 'to' => $temp_enabled ),
                        array( 'key' => 'temp_notice', 'label' => 'הודעה זמנית', 'type' => 'text', 'from' => $prev_row['temp_notice'] ?? '', 'to' => $temp_notice ),
                    );

                    foreach ( $fields as $field ) {
                        $from = $this->logger->format_log_value( $field['from'], $field['type'] );
                        $to   = $this->logger->format_log_value( $field['to'], $field['type'] );
                        if ( $from !== $to ) {
                            $changes[] = array(
                                'field' => $field['label'],
                                'from'  => $from,
                                'to'    => $to,
                            );
                        }
                    }
                }

                $this->logger->log_firewall_event( $id, 'update', 'עודכן רישום חומת אש', array( 'changes' => $changes ) );
            }
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $ok = $wpdb->insert( $fw_table, $data );
            if ( $ok === false ) {
                set_transient( 'expman_firewalls_errors', array( 'שמירה נכשלה: ' . $wpdb->last_error ), 90 );
                $this->logger->log_firewall_event( null, 'create', 'שמירה נכשלה', array( 'error' => $wpdb->last_error ), 'error' );
            } else {
                $new_id = $wpdb->insert_id;
                $this->logger->log_firewall_event( $new_id, 'create', 'נוסף רישום חומת אש' );
            }
        }
    }

    public function action_save_box_types() {
        global $wpdb;
        $types_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_TYPES;

        $vendors = $_POST['box_type_vendor'] ?? array();
        $models  = $_POST['box_type_model'] ?? array();
        $delete  = $_POST['box_type_delete'] ?? array();

        foreach ( (array) $vendors as $id => $vendor ) {
            if ( ! is_numeric( $id ) ) {
                continue;
            }
            $vendor = sanitize_text_field( $vendor );
            $model  = sanitize_text_field( $models[ $id ] ?? '' );
            $should_delete = isset( $delete[ $id ] );

            if ( $should_delete ) {
                $wpdb->delete( $types_table, array( 'id' => intval( $id ) ), array( '%d' ) );
                continue;
            }

            if ( $vendor === '' && $model === '' ) {
                continue;
            }

            if ( $vendor !== '' && $model !== '' ) {
                $wpdb->update(
                    $types_table,
                    array(
                        'vendor'     => $vendor,
                        'model'      => $model,
                        'updated_at' => current_time( 'mysql' ),
                    ),
                    array( 'id' => intval( $id ) ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );
            }
        }

        $new_vendors = $_POST['new_box_type_vendor'] ?? array();
        $new_models  = $_POST['new_box_type_model'] ?? array();

        foreach ( (array) $new_vendors as $idx => $vendor ) {
            $vendor = sanitize_text_field( $vendor );
            $model  = sanitize_text_field( $new_models[ $idx ] ?? '' );
            if ( $vendor === '' || $model === '' ) {
                continue;
            }
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$types_table} WHERE vendor=%s AND model=%s",
                $vendor,
                $model
            ) );
            if ( $exists ) {
                continue;
            }
            $wpdb->insert(
                $types_table,
                array(
                    'vendor'     => $vendor,
                    'model'      => $model,
                    'created_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%s' )
            );
        }
    }

    public function action_map_forticloud_asset() {
        global $wpdb;
        $assets_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FORTICLOUD_ASSETS;
        $fw_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $types_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_TYPES;
        $cust_table = $wpdb->prefix . 'dc_customers';

        $asset_id = intval( $_POST['asset_id'] ?? 0 );
        $customer_id = intval( $_POST['customer_id'] ?? 0 );

        if ( $asset_id <= 0 || $customer_id <= 0 ) {
            $this->add_notice( 'יש לבחור לקוח לשיוך.', 'error' );
            return;
        }

        $asset = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$assets_table} WHERE id=%d", $asset_id ) );
        if ( ! $asset ) {
            $this->add_notice( 'נכס לא נמצא.', 'error' );
            return;
        }

        $customer = $wpdb->get_row( $wpdb->prepare( "SELECT id, customer_number, customer_name FROM {$cust_table} WHERE id=%d AND is_deleted=0", $customer_id ) );
        if ( ! $customer ) {
            $this->add_notice( 'לקוח לא נמצא.', 'error' );
            return;
        }

        $wpdb->update(
            $assets_table,
            array(
                'customer_id'             => $customer->id,
                'customer_number_snapshot'=> $customer->customer_number,
                'customer_name_snapshot'  => $customer->customer_name,
                'mapped_at'               => current_time( 'mysql' ),
                'updated_at'              => current_time( 'mysql' ),
            ),
            array( 'id' => $asset_id ),
            array( '%d', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        $vendor = sanitize_text_field( $asset->category_name ?? '' );
        if ( $vendor === '' ) {
            $vendor = 'FortiGate';
        }
        $model  = sanitize_text_field( $asset->model_name ?? '' );
        $box_type_id = null;
        if ( $vendor !== '' && $model !== '' ) {
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$types_table} WHERE vendor=%s AND model=%s", $vendor, $model ) );
            if ( $existing ) {
                $box_type_id = intval( $existing );
            } else {
                $wpdb->insert(
                    $types_table,
                    array(
                        'vendor'     => $vendor,
                        'model'      => $model,
                        'created_at' => current_time( 'mysql' ),
                        'updated_at' => current_time( 'mysql' ),
                    ),
                    array( '%s', '%s', '%s', '%s' )
                );
                $box_type_id = intval( $wpdb->insert_id );
            }
        }

        $expiry_date = '';
        if ( ! empty( $asset->expiration_date ) ) {
            $expiry_date = date( 'Y-m-d', strtotime( $asset->expiration_date ) );
        }

        $existing_fw = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$fw_table} WHERE serial_number=%s AND deleted_at IS NULL LIMIT 1", $asset->serial_number ) );
        $fw_data = array(
            'customer_id'     => $customer->id,
            'customer_number' => $customer->customer_number,
            'customer_name'   => $customer->customer_name,
            'serial_number'   => $asset->serial_number,
            'is_managed'      => 1,
            'track_only'      => 0,
            'box_type_id'     => $box_type_id,
            'expiry_date'     => $expiry_date !== '' ? $expiry_date : null,
            'notes'           => $asset->description ?? null,
            'updated_at'      => current_time( 'mysql' ),
        );

        if ( $existing_fw ) {
            $wpdb->update( $fw_table, $fw_data, array( 'id' => intval( $existing_fw ) ) );
            $this->logger->log_firewall_event( intval( $existing_fw ), 'map_update', 'עודכן רישום חומת אש משיוך FortiCloud', array( 'serial' => $asset->serial_number ) );
        } else {
            $fw_data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $fw_table, $fw_data );
            $this->logger->log_firewall_event( $wpdb->insert_id, 'map_create', 'נוסף רישום חומת אש משיוך FortiCloud', array( 'serial' => $asset->serial_number ) );
        }

        if ( $this->forticloud ) {
            $this->forticloud->update_forticloud_description( $asset, $customer );
        }

        $redirect_url = add_query_arg(
            array(
                'expman_msg' => rawurlencode( 'שיוך הושלם' ),
                'highlight'  => rawurlencode( $asset->serial_number ),
                'tab'        => 'main',
            ),
            remove_query_arg( array( 'expman_msg', 'highlight', 'tab' ) )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    public function action_trash_firewall() {
        global $wpdb;
        $fw_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $id = isset( $_POST['firewall_id'] ) ? intval( $_POST['firewall_id'] ) : 0;
        if ( $id <= 0 ) { return; }

        $ok = $wpdb->update(
            $fw_table,
            array( 'deleted_at' => current_time( 'mysql' ) ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );
        if ( $ok === false ) {
            $this->logger->log_firewall_event( $id, 'delete_failed', 'מחיקה נכשלה', array(
                'error'          => $wpdb->last_error,
                'query'          => $wpdb->last_query,
                'table'          => $fw_table,
                'has_deleted_at' => $this->column_exists( $fw_table, 'deleted_at' ),
            ), 'error' );
            set_transient( 'expman_firewalls_errors', array( 'מחיקה נכשלה: ' . $wpdb->last_error ), 90 );
        } elseif ( $ok === 0 ) {
            $this->logger->log_firewall_event( $id, 'delete_failed', 'מחיקה לא בוצעה', array(
                'query'          => $wpdb->last_query,
                'table'          => $fw_table,
                'has_deleted_at' => $this->column_exists( $fw_table, 'deleted_at' ),
            ), 'warning' );
            set_transient( 'expman_firewalls_errors', array( 'מחיקה לא בוצעה (ייתכן שהרשומה לא עודכנה).' ), 90 );
        } else {
            $this->logger->log_firewall_event( $id, 'delete', 'רישום הועבר לסל המחזור' );
        }
    }

    public function action_restore_firewall() {
        global $wpdb;
        $fw_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $id = isset( $_POST['firewall_id'] ) ? intval( $_POST['firewall_id'] ) : 0;
        if ( $id <= 0 ) { return; }

        $ok = $wpdb->update(
            $fw_table,
            array( 'deleted_at' => null ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );
        if ( $ok === false ) {
            $this->logger->log_firewall_event( $id, 'restore_failed', 'שחזור מסל המחזור נכשל', array(
                'error'          => $wpdb->last_error,
                'query'          => $wpdb->last_query,
                'table'          => $fw_table,
                'has_deleted_at' => $this->column_exists( $fw_table, 'deleted_at' ),
            ), 'error' );
            set_transient( 'expman_firewalls_errors', array( 'שחזור נכשל: ' . $wpdb->last_error ), 90 );
        } elseif ( $ok === 0 ) {
            $this->logger->log_firewall_event( $id, 'restore_failed', 'שחזור לא בוצע', array(
                'query'          => $wpdb->last_query,
                'table'          => $fw_table,
                'has_deleted_at' => $this->column_exists( $fw_table, 'deleted_at' ),
            ), 'warning' );
            set_transient( 'expman_firewalls_errors', array( 'שחזור לא בוצע (ייתכן שהרשומה לא עודכנה).' ), 90 );
        } else {
            $this->logger->log_firewall_event( $id, 'restore', 'רישום שוחזר מסל המחזור' );
        }
    }

    public function action_archive_firewall() {
        global $wpdb;
        $fw_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $id = isset( $_POST['firewall_id'] ) ? intval( $_POST['firewall_id'] ) : 0;
        if ( $id <= 0 ) { return; }

        $ok = $wpdb->update(
            $fw_table,
            array( 'archived_at' => current_time( 'mysql' ) ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );
        $this->logger->log_firewall_event( $id, 'archive', 'רישום הועבר לארכיון' );
        if ( $ok === false ) {
            $this->logger->log_firewall_event( $id, 'archive', 'העברה לארכיון נכשלה', array( 'error' => $wpdb->last_error ), 'error' );
        }
    }

    public function action_restore_archive() {
        global $wpdb;
        $fw_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $id = isset( $_POST['firewall_id'] ) ? intval( $_POST['firewall_id'] ) : 0;
        if ( $id <= 0 ) { return; }

        $ok = $wpdb->update(
            $fw_table,
            array( 'archived_at' => null ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );
        $this->logger->log_firewall_event( $id, 'unarchive', 'רישום הוחזר מארכיון' );
        if ( $ok === false ) {
            $this->logger->log_firewall_event( $id, 'unarchive', 'שחזור מארכיון נכשל', array( 'error' => $wpdb->last_error ), 'error' );
        }
    }

    public function action_export_csv() {
        global $wpdb;
        $fw_table    = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $types_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_TYPES;
        $cust_table  = $wpdb->prefix . 'dc_customers';

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        nocache_headers();

        $rows = $wpdb->get_results( "
            SELECT fw.*,\n                   c.customer_number AS customer_number,\n                   c.customer_name AS customer_name,\n                   bt.vendor, bt.model\n            FROM {$fw_table} fw\n            LEFT JOIN {$cust_table} c ON c.id = fw.customer_id\n            LEFT JOIN {$types_table} bt ON bt.id = fw.box_type_id\n            WHERE 1=1\n            ORDER BY fw.id ASC\n        " );

        $filename = 'firewalls-template-' . date( 'Ymd-His' ) . '.csv';
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Encoding: UTF-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $out = fopen( 'php://output', 'w' );
        fputs( $out, "\xEF\xBB\xBF" );

        fputcsv( $out, array(
            'id',
            'customer_number',
            'customer_name',
            'branch',
            'serial_number',
            'is_managed',
            'track_only',
            'vendor',
            'model',
            'expiry_date',
            'registration_date',
            'access_url',
            'notes',
            'temp_notice_enabled',
            'temp_notice',
            'deleted_at',
        ) );

        foreach ( (array) $rows as $r ) {
            fputcsv( $out, array(
                $r->id,
                $r->customer_number,
                $r->customer_name,
                $r->branch,
                $r->serial_number,
                $r->is_managed,
                $r->track_only,
                $r->vendor,
                $r->model,
                $r->expiry_date,
                $r->created_at ? date( 'Y-m-d', strtotime( $r->created_at ) ) : '',
                $r->access_url,
                $r->notes,
                $r->temp_notice_enabled,
                $r->temp_notice,
                $r->deleted_at,
            ) );
        }

        fclose( $out );
        exit;
    }

    public function get_box_types() {
        global $wpdb;
        $types_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_TYPES;
        return $wpdb->get_results( "SELECT id, vendor, model FROM {$types_table} ORDER BY vendor ASC, model ASC" );
    }

    public function get_firewalls_rows( $filters, $orderby, $order, $status = 'active', $track_only = null ) {
        global $wpdb;

        $fw_table    = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $types_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_TYPES;
        $cust_table  = $wpdb->prefix . 'dc_customers';

        $allowed_orderby = array(
            'id' => 'fw.id',
            'customer_number' => 'c.customer_number',
            'customer_name' => 'c.customer_name',
            'branch' => 'fw.branch',
            'serial_number' => 'fw.serial_number',
            'is_managed' => 'fw.is_managed',
            'track_only' => 'fw.track_only',
            'expiry_date' => 'fw.expiry_date',
            'days_to_renew' => 'days_to_renew',
            'vendor' => 'bt.vendor',
            'model' => 'bt.model',
            'archived_at' => 'fw.archived_at',
        );

        $orderby_sql = isset( $allowed_orderby[ $orderby ] ) ? $allowed_orderby[ $orderby ] : 'fw.expiry_date';
        $order_sql   = strtoupper( $order ) === 'DESC' ? 'DESC' : 'ASC';

        $where = "WHERE 1=1";
        $params = array();

        if ( $status === 'trash' ) {
            $where .= " AND fw.deleted_at IS NOT NULL";
        } elseif ( $status === 'archive' ) {
            $where .= " AND fw.deleted_at IS NULL AND fw.archived_at IS NOT NULL";
        } else {
            $where .= " AND fw.deleted_at IS NULL AND fw.archived_at IS NULL";
        }

        if ( $track_only !== null ) {
            $where .= " AND fw.track_only = %d";
            $params[] = intval( $track_only ) ? 1 : 0;
        } elseif ( $filters['track_only'] !== '' && $filters['track_only'] !== null ) {
            $where .= " AND fw.track_only = %d";
            $params[] = intval( $filters['track_only'] ) ? 1 : 0;
        }

        $like_map = array(
            'customer_number' => 'c.customer_number',
            'customer_name'   => 'c.customer_name',
            'branch'          => 'fw.branch',
            'serial_number'   => 'fw.serial_number',
        );

        foreach ( $like_map as $k => $col ) {
            if ( ! empty( $filters[ $k ] ) ) {
                $where .= " AND {$col} LIKE %s";
                $params[] = '%' . $wpdb->esc_like( (string) $filters[ $k ] ) . '%';
            }
        }

        // vendor / model multi-select support
        if ( ! empty( $filters['vendor'] ) ) {
            $vals = array_filter( array_map( 'trim', explode( ',', (string) $filters['vendor'] ) ) );
            if ( count( $vals ) > 1 ) {
                $in = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
                $where .= " AND bt.vendor IN ($in)";
                foreach ( $vals as $v ) { $params[] = $v; }
            } else {
                $where .= " AND bt.vendor LIKE %s";
                $params[] = '%' . $wpdb->esc_like( (string) $filters['vendor'] ) . '%';
            }
        }

        if ( ! empty( $filters['model'] ) ) {
            $vals = array_filter( array_map( 'trim', explode( ',', (string) $filters['model'] ) ) );
            if ( count( $vals ) > 1 ) {
                $in = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
                $where .= " AND bt.model IN ($in)";
                foreach ( $vals as $v ) { $params[] = $v; }
            } else {
                $where .= " AND bt.model LIKE %s";
                $params[] = '%' . $wpdb->esc_like( (string) $filters['model'] ) . '%';
            }
        }

        if ( $filters['is_managed'] !== '' && $filters['is_managed'] !== null ) {
            $where .= " AND fw.is_managed = %d";
            $params[] = intval( $filters['is_managed'] ) ? 1 : 0;
        }

        if ( ! empty( $filters['expiry_date'] ) ) {
            $where .= " AND fw.expiry_date LIKE %s";
            $params[] = '%' . $wpdb->esc_like( (string) $filters['expiry_date'] ) . '%';
        }

        $sql = "
            SELECT
                fw.id,
                fw.customer_id,
                c.customer_number AS customer_number,
                c.customer_name   AS customer_name,
                fw.branch,
                fw.serial_number,
                fw.is_managed,
                fw.track_only,
                fw.expiry_date,
                DATEDIFF(fw.expiry_date, CURDATE()) AS days_to_renew,
                bt.vendor,
                bt.model,
                fw.access_url,
                fw.notes,
                fw.temp_notice_enabled,
                fw.temp_notice,
                fw.archived_at,
                fw.deleted_at
            FROM {$fw_table} fw
            LEFT JOIN {$cust_table} c ON c.id = fw.customer_id AND c.is_deleted = 0
            LEFT JOIN {$types_table} bt ON bt.id = fw.box_type_id
            {$where}
            ORDER BY {$orderby_sql} {$order_sql}, fw.id DESC
        ";

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params );
        }

        return $wpdb->get_results( $sql );
    }

    public function get_summary_counts( $option_key ) {
        global $wpdb;
        $settings = get_option( $option_key, array() );
        $yellow   = intval( $settings['yellow_threshold'] ?? 60 );
        $red      = intval( $settings['red_threshold'] ?? 30 );
        $fw_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;

        $counts = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) > %d THEN 1 ELSE 0 END) AS green_count,
                    SUM(CASE WHEN expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) BETWEEN %d AND %d THEN 1 ELSE 0 END) AS yellow_count,
                    SUM(CASE WHEN expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) <= %d THEN 1 ELSE 0 END) AS red_count,
                    COUNT(*) AS total_count
                 FROM {$fw_table}
                 WHERE deleted_at IS NULL AND archived_at IS NULL",
                $yellow,
                $red + 1,
                $yellow,
                $red
            ),
            ARRAY_A
        );

        $archived = $wpdb->get_var( "SELECT COUNT(*) FROM {$fw_table} WHERE deleted_at IS NULL AND archived_at IS NOT NULL" );

        return array(
            'green'   => intval( $counts['green_count'] ?? 0 ),
            'yellow'  => intval( $counts['yellow_count'] ?? 0 ),
            'red'     => intval( $counts['red_count'] ?? 0 ),
            'total'   => intval( $counts['total_count'] ?? 0 ),
            'archived'=> intval( $archived ),
            'yellow_threshold' => $yellow,
            'red_threshold'    => $red,
        );
    }

    public function get_distinct_type_values( $field ) {
        global $wpdb;
        $types_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_TYPES;
        $field = ( $field === 'model' ) ? 'model' : 'vendor';
        $sql = "SELECT DISTINCT {$field} FROM {$types_table} WHERE {$field} IS NOT NULL AND {$field} <> '' ORDER BY {$field} ASC";
        $vals = $wpdb->get_col( $sql );
        $vals = array_values( array_filter( array_map( 'trim', (array) $vals ) ) );
        return $vals;
    }

    public function get_import_stage_rows( $batch_id ) {
        global $wpdb;
        $stage_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALL_IMPORT_STAGE;
        if ( $batch_id === '' ) {
            return array();
        }
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$stage_table} WHERE import_batch_id=%s ORDER BY id ASC",
                $batch_id
            )
        );
    }

    public function action_assign_import_stage() {
        global $wpdb;
        $stage_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALL_IMPORT_STAGE;

        $stage_id = intval( $_POST['stage_id'] ?? 0 );
        if ( $stage_id <= 0 ) {
            return;
        }

        $this->assign_stage_row( $stage_id, $_POST );
    }

    public function action_assign_import_stage_bulk() {
        global $wpdb;
        $stage_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALL_IMPORT_STAGE;
        $batch_id = sanitize_text_field( $_POST['batch'] ?? '' );
        if ( $batch_id === '' ) {
            return;
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$stage_table} WHERE import_batch_id=%s AND status IN ('pending','failed') ORDER BY id ASC",
            $batch_id
        ) );
        if ( empty( $rows ) ) {
            return;
        }

        $failures = array();
        foreach ( $rows as $row ) {
            $result = $this->assign_stage_row( intval( $row->id ), array(), $batch_id );
            if ( ! empty( $result['error'] ) ) {
                $failures[] = $result;
            }
        }

        if ( ! empty( $failures ) ) {
            set_transient( 'expman_firewalls_assign_failures', $failures, 300 );
        }
    }

    private function assign_stage_row( $stage_id, $input, $batch_override = '' ) {
        global $wpdb;
        $stage_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALL_IMPORT_STAGE;
        $fw_table    = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $types_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_TYPES;

        $stage = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$stage_table} WHERE id=%d", $stage_id ), ARRAY_A );
        if ( ! $stage ) {
            return array( 'stage_id' => $stage_id, 'error' => 'שורת שיוך לא נמצאה.' );
        }

        $serial = sanitize_text_field( $input['serial_number'] ?? $stage['serial_number'] ?? '' );
        $customer_id = isset( $input['customer_id'] ) ? intval( $input['customer_id'] ) : (int) ( $stage['customer_id'] ?? 0 );
        $customer_number = sanitize_text_field( $input['customer_number'] ?? $stage['customer_number'] ?? '' );
        $customer_name = sanitize_text_field( $input['customer_name'] ?? $stage['customer_name'] ?? '' );
        $branch = sanitize_text_field( $input['branch'] ?? $stage['branch'] ?? '' );
        $is_managed = isset( $input['is_managed'] ) ? intval( $input['is_managed'] ) : intval( $stage['is_managed'] ?? 1 );
        $track_only = isset( $input['track_only'] ) ? intval( $input['track_only'] ) : intval( $stage['track_only'] ?? 0 );
        $vendor = sanitize_text_field( $input['vendor'] ?? $stage['vendor'] ?? '' );
        $model = sanitize_text_field( $input['model'] ?? $stage['model'] ?? '' );
        $expiry_date = sanitize_text_field( $input['expiry_date'] ?? $stage['expiry_date'] ?? '' );
        $access_url = sanitize_text_field( $input['access_url'] ?? $stage['access_url'] ?? '' );
        $notes = wp_kses_post( $input['notes'] ?? $stage['notes'] ?? '' );
        $temp_notice_enabled = isset( $input['temp_notice_enabled'] ) ? intval( $input['temp_notice_enabled'] ) : intval( $stage['temp_notice_enabled'] ?? 0 );
        $temp_notice = wp_kses_post( $input['temp_notice'] ?? $stage['temp_notice'] ?? '' );

        if ( $serial === '' ) {
            $this->mark_stage_failed( $stage_id, 'חסר מספר סידורי.' );
            return array( 'stage_id' => $stage_id, 'serial_number' => $serial, 'error' => 'חסר מספר סידורי.' );
        }

        if ( $customer_id <= 0 && $customer_number !== '' ) {
            $cust_table = $wpdb->prefix . 'dc_customers';
            $cust_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, customer_name FROM {$cust_table} WHERE is_deleted=0 AND customer_number=%s LIMIT 1",
                $customer_number
            ), ARRAY_A );
            if ( $cust_row ) {
                $customer_id = intval( $cust_row['id'] );
                if ( $customer_name === '' ) {
                    $customer_name = (string) ( $cust_row['customer_name'] ?? '' );
                }
            }
        }

        if ( $customer_id <= 0 ) {
            $this->mark_stage_failed( $stage_id, 'חובה לבחור לקוח.' );
            return array( 'stage_id' => $stage_id, 'serial_number' => $serial, 'error' => 'חובה לבחור לקוח.' );
        }

        if ( $expiry_date === '' || ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $expiry_date ) ) {
            $this->mark_stage_failed( $stage_id, 'חובה לבחור תאריך חידוש תקין.' );
            return array( 'stage_id' => $stage_id, 'serial_number' => $serial, 'error' => 'חובה לבחור תאריך חידוש תקין.' );
        }

        if ( $access_url !== '' && ! preg_match( '/^https?:\\/\\//i', $access_url ) ) {
            $access_url = 'https://' . ltrim( $access_url );
        }

        $box_type_id = null;
        if ( $vendor !== '' && $model !== '' ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$types_table} WHERE vendor=%s AND model=%s",
                $vendor,
                $model
            ) );
            if ( $existing ) {
                $box_type_id = (int) $existing;
            } else {
                $wpdb->insert(
                    $types_table,
                    array(
                        'vendor'     => $vendor,
                        'model'      => $model,
                        'created_at' => current_time( 'mysql' ),
                        'updated_at' => current_time( 'mysql' ),
                    ),
                    array( '%s', '%s', '%s', '%s' )
                );
                $box_type_id = (int) $wpdb->insert_id;
            }
        }

        $payload = array(
            'customer_id'         => $customer_id > 0 ? $customer_id : null,
            'customer_number'     => $customer_number !== '' ? $customer_number : null,
            'customer_name'       => $customer_name !== '' ? $customer_name : null,
            'branch'              => $branch !== '' ? $branch : null,
            'serial_number'       => $serial,
            'is_managed'          => $is_managed ? 1 : 0,
            'track_only'          => $track_only ? 1 : 0,
            'box_type_id'         => $box_type_id,
            'expiry_date'         => $expiry_date !== '' ? $expiry_date : null,
            'access_url'          => $access_url !== '' ? $access_url : null,
            'notes'               => $notes,
            'temp_notice_enabled' => $temp_notice_enabled ? 1 : 0,
            'temp_notice'         => $temp_notice_enabled ? $temp_notice : '',
            'updated_at'          => current_time( 'mysql' ),
        );

        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$fw_table} WHERE serial_number=%s LIMIT 1",
            $serial
        ) );

        $firewall_id = null;
        if ( $existing_id ) {
            $ok = $wpdb->update( $fw_table, $payload, array( 'id' => intval( $existing_id ) ) );
            if ( $ok === false ) {
                $this->mark_stage_failed( $stage_id, $wpdb->last_error );
                $this->logger->log_firewall_event( intval( $existing_id ), 'import_stage_assign', 'עדכון חומת אש נכשל', array(
                    'batch_id'      => $batch_override !== '' ? $batch_override : $stage['import_batch_id'],
                    'stage_id'      => $stage_id,
                    'serial_number' => $serial,
                    'customer_id'   => $customer_id,
                    'payload'       => $payload,
                    'last_error'    => $wpdb->last_error,
                ), 'error' );
                return array( 'stage_id' => $stage_id, 'serial_number' => $serial, 'error' => $wpdb->last_error );
            }
            $firewall_id = intval( $existing_id );
        } else {
            $payload['created_at'] = $stage['created_at'] ? $stage['created_at'] : current_time( 'mysql' );
            $ok = $wpdb->insert( $fw_table, $payload );
            if ( $ok === false ) {
                $this->mark_stage_failed( $stage_id, $wpdb->last_error );
                $this->logger->log_firewall_event( null, 'import_stage_assign', 'יצירת חומת אש נכשלה', array(
                    'batch_id'      => $batch_override !== '' ? $batch_override : $stage['import_batch_id'],
                    'stage_id'      => $stage_id,
                    'serial_number' => $serial,
                    'customer_id'   => $customer_id,
                    'payload'       => $payload,
                    'last_error'    => $wpdb->last_error,
                ), 'error' );
                return array( 'stage_id' => $stage_id, 'serial_number' => $serial, 'error' => $wpdb->last_error );
            }
            $firewall_id = intval( $wpdb->insert_id );
        }

        $wpdb->update(
            $stage_table,
            array(
                'status'              => 'assigned',
                'assigned_at'         => current_time( 'mysql' ),
                'assigned_by_user_id' => get_current_user_id(),
                'firewall_id'         => $firewall_id,
                'customer_id'         => $customer_id > 0 ? $customer_id : null,
                'customer_number'     => $customer_number !== '' ? $customer_number : null,
                'customer_name'       => $customer_name !== '' ? $customer_name : null,
                'branch'              => $branch !== '' ? $branch : null,
                'serial_number'       => $serial,
                'is_managed'          => $is_managed ? 1 : 0,
                'track_only'          => $track_only ? 1 : 0,
                'vendor'              => $vendor !== '' ? $vendor : null,
                'model'               => $model !== '' ? $model : null,
                'box_type_id'         => $box_type_id,
                'expiry_date'         => $expiry_date !== '' ? $expiry_date : null,
                'access_url'          => $access_url !== '' ? $access_url : null,
                'notes'               => $notes,
                'temp_notice_enabled' => $temp_notice_enabled ? 1 : 0,
                'temp_notice'         => $temp_notice_enabled ? $temp_notice : '',
                'last_error'          => null,
                'last_error_at'       => null,
            ),
            array( 'id' => $stage_id ),
            array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ),
            array( '%d' )
        );

        $this->logger->log_firewall_event( $firewall_id, 'import_stage_assign', 'שיוך לאחר ייבוא', array(
            'batch_id'      => $batch_override !== '' ? $batch_override : $stage['import_batch_id'],
            'stage_id'      => $stage_id,
            'serial_number' => $serial,
            'customer_id'   => $customer_id,
            'payload'       => $payload,
        ), 'info' );

        return array( 'stage_id' => $stage_id, 'serial_number' => $serial, 'error' => '' );
    }

    private function mark_stage_failed( $stage_id, $error_message ) {
        global $wpdb;
        $stage_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALL_IMPORT_STAGE;
        $wpdb->update(
            $stage_table,
            array(
                'status'        => 'failed',
                'last_error'    => $error_message,
                'last_error_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $stage_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );
    }
}
}
