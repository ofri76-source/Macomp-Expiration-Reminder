<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Servers_Importer' ) ) {
class Expman_Servers_Importer {

    private $logger;
    private $option_key;

    public function __construct( $logger, $option_key ) {
        $this->logger = $logger;
        $this->option_key = $option_key;
    }

    public function run( $file_field = 'servers_file' ) {
        if ( empty( $_FILES[ $file_field ]['tmp_name'] ) ) {
            set_transient( 'expman_servers_errors', array( 'לא נבחר קובץ ליבוא.' ), 90 );
            return;
        }

        $tmp = $_FILES[ $file_field ]['tmp_name'];
        $h = fopen( $tmp, 'r' );
        if ( ! $h ) {
            set_transient( 'expman_servers_errors', array( 'לא ניתן לקרוא את הקובץ.' ), 90 );
            return;
        }

        global $wpdb;
        $stage_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_IMPORT_STAGE;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;

        $row_num = 0;
        $imported = 0;
        $skipped = 0;
        $errors = array();
        $header_map = array();

        while ( ( $data = fgetcsv( $h, 0, ',' ) ) !== false ) {
            $row_num++;

            if ( isset( $data[0] ) ) {
                $data[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $data[0] );
            }

            if ( $row_num === 1 ) {
                $header_map = $this->build_header_map( $data );
                if ( ! empty( $header_map ) ) {
                    continue;
                }
            }

            if ( $this->is_empty_row( $data ) ) {
                $skipped++;
                continue;
            }

            $col = array_pad( $data, 5, '' );
            $customer_number = trim( (string) $this->get_value( $data, $header_map, array( 'customer_number', 'customer number', 'מספר לקוח' ), $col[0] ) );
            $customer_name = trim( (string) $this->get_value( $data, $header_map, array( 'customer_name', 'customer name', 'שם לקוח' ), $col[1] ) );
            $service_tag = trim( (string) $this->get_value( $data, $header_map, array( 'service_tag', 'service tag', 'tag', 'Service Tag' ), $col[2] ) );
            $ending_on = trim( (string) $this->get_value( $data, $header_map, array( 'ending on', 'ending_on', 'ending on', 'תאריך סיום' ), $col[3] ) );
            $notes = trim( (string) $this->get_value( $data, $header_map, array( 'notes', 'note', 'הערות' ), $col[4] ) );

            if ( $service_tag === '' ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: חסר Service Tag.";
                continue;
            }

            $service_tag = strtoupper( $service_tag );
            $ending_on_date = $this->sanitize_date( $ending_on );

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$servers_table} WHERE service_tag=%s",
                    $service_tag
                )
            );
            if ( $exists ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: Service Tag כבר קיים ({$service_tag}).";
                continue;
            }

            $stage_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$stage_table} WHERE option_key=%s AND service_tag=%s",
                    $this->option_key,
                    $service_tag
                )
            );
            if ( $stage_exists ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: Service Tag כבר קיים בשלב ({$service_tag}).";
                continue;
            }

            $ok = $wpdb->insert(
                $stage_table,
                array(
                    'option_key'      => $this->option_key,
                    'customer_number' => $customer_number !== '' ? $customer_number : null,
                    'customer_name'   => $customer_name !== '' ? $customer_name : null,
                    'service_tag'     => $service_tag,
                    'ending_on'       => $ending_on_date,
                    'notes'           => $notes !== '' ? $notes : null,
                    'created_at'      => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );

            if ( ! $ok ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: הוספה לשלב נכשלה.";
                continue;
            }

            $imported++;
        }

        fclose( $h );

        $this->logger->log_server_event( null, 'import', 'ייבוא CSV לשלב', array( 'imported' => $imported, 'skipped' => $skipped ), 'info' );

        if ( ! empty( $errors ) ) {
            set_transient( 'expman_servers_errors', $errors, 90 );
        }

        if ( $imported > 0 ) {
            set_transient( 'expman_servers_imported', $imported, 90 );
        }
    }

    private function build_header_map( $row ) {
        $map = array();
        foreach ( $row as $i => $col ) {
            $key = strtolower( trim( (string) $col ) );
            if ( $key !== '' ) {
                $map[ $key ] = $i;
            }
        }
        return $map;
    }

    private function get_value( $row, $header_map, $keys, $default = '' ) {
        foreach ( (array) $keys as $key ) {
            $key = strtolower( $key );
            if ( isset( $header_map[ $key ] ) ) {
                $idx = $header_map[ $key ];
                return isset( $row[ $idx ] ) ? $row[ $idx ] : $default;
            }
        }
        return $default;
    }

    private function is_empty_row( $row ) {
        foreach ( (array) $row as $val ) {
            if ( trim( (string) $val ) !== '' ) {
                return false;
            }
        }
        return true;
    }

    private function sanitize_date( $value ) {
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

    private function sanitize_datetime( $value ) {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( $value === '' ) { return null; }

        $formats = array( 'Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i', 'd/m/Y H:i:s' );
        foreach ( $formats as $fmt ) {
            $dt = DateTime::createFromFormat( $fmt, $value );
            if ( $dt instanceof DateTime ) {
                return $dt->format( 'Y-m-d H:i:s' );
            }
        }

        $ts = strtotime( $value );
        if ( $ts ) {
            return gmdate( 'Y-m-d H:i:s', $ts );
        }

        return null;
    }

    private function parse_bool( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) { return 0; }
        $value = mb_strtolower( $value );
        return in_array( $value, array( '1', 'כן', 'yes', 'true', 'on' ), true ) ? 1 : 0;
    }

    public function run_direct( $file_field = 'servers_direct_file' ) {
        if ( empty( $_FILES[ $file_field ]['tmp_name'] ) ) {
            set_transient( 'expman_servers_errors', array( 'לא נבחר קובץ ליבוא.' ), 90 );
            return;
        }

        $tmp = $_FILES[ $file_field ]['tmp_name'];
        $h = fopen( $tmp, 'r' );
        if ( ! $h ) {
            set_transient( 'expman_servers_errors', array( 'לא ניתן לקרוא את הקובץ.' ), 90 );
            return;
        }

        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;

        $row_num = 0;
        $imported = 0;
        $skipped = 0;
        $errors = array();
        $header_map = array();

        while ( ( $data = fgetcsv( $h, 0, ',' ) ) !== false ) {
            $row_num++;

            if ( isset( $data[0] ) ) {
                $data[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $data[0] );
            }

            if ( $row_num === 1 ) {
                $header_map = $this->build_header_map( $data );
                if ( ! empty( $header_map ) ) {
                    continue;
                }
            }

            if ( $this->is_empty_row( $data ) ) {
                $skipped++;
                continue;
            }

            $service_tag = trim( (string) $this->get_value( $data, $header_map, array( 'service tag', 'service_tag' ), '' ) );
            if ( $service_tag === '' ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: חסר Service Tag.";
                continue;
            }

            $service_tag = strtoupper( $service_tag );

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$servers_table} WHERE service_tag=%s AND deleted_at IS NULL",
                    $service_tag
                )
            );
            if ( $exists ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: Service Tag כבר קיים ({$service_tag}).";
                continue;
            }

            $customer_id = intval( $this->get_value( $data, $header_map, array( 'customer id', 'customer_id' ), '' ) );
            $customer_number = trim( (string) $this->get_value( $data, $header_map, array( 'מספר לקוח', 'customer number', 'customer_number' ), '' ) );
            $customer_name = trim( (string) $this->get_value( $data, $header_map, array( 'שם לקוח', 'customer name', 'customer_name' ), '' ) );
            $express_service_code = trim( (string) $this->get_value( $data, $header_map, array( 'express service code', 'express_service_code' ), '' ) );
            $ship_date = $this->sanitize_date( $this->get_value( $data, $header_map, array( 'ship date', 'ship_date' ), '' ) );
            $ending_on = $this->sanitize_date( $this->get_value( $data, $header_map, array( 'ending on', 'ending_on' ), '' ) );
            $operating_system = trim( (string) $this->get_value( $data, $header_map, array( 'מערכת הפעלה', 'operating system', 'operating_system' ), '' ) );
            $service_level = trim( (string) $this->get_value( $data, $header_map, array( 'סוג שירות', 'service level', 'service_level' ), '' ) );
            $server_model = trim( (string) $this->get_value( $data, $header_map, array( 'דגם שרת', 'server model', 'server_model' ), '' ) );
            $track_only = $this->parse_bool( $this->get_value( $data, $header_map, array( 'שרת במעקב', 'track only', 'track_only' ), '' ) );
            $temp_notice_enabled = $this->parse_bool( $this->get_value( $data, $header_map, array( 'הודעה זמנית פעילה', 'temp notice enabled', 'temp_notice_enabled' ), '' ) );
            $temp_notice_text = trim( (string) $this->get_value( $data, $header_map, array( 'טקסט הודעה זמנית', 'temp notice text', 'temp_notice_text' ), '' ) );
            $notes = trim( (string) $this->get_value( $data, $header_map, array( 'הערות', 'notes', 'note' ), '' ) );
            $raw_json = trim( (string) $this->get_value( $data, $header_map, array( 'raw json', 'raw_json' ), '' ) );
            $last_sync_at = $this->sanitize_datetime( $this->get_value( $data, $header_map, array( 'last sync', 'last_sync_at' ), '' ) );
            $deleted_at = $this->sanitize_datetime( $this->get_value( $data, $header_map, array( 'deleted at', 'deleted_at' ), '' ) );
            $deleted_by = intval( $this->get_value( $data, $header_map, array( 'deleted by', 'deleted_by' ), '' ) );
            $created_at = $this->sanitize_datetime( $this->get_value( $data, $header_map, array( 'created at', 'created_at' ), '' ) );
            $updated_at = $this->sanitize_datetime( $this->get_value( $data, $header_map, array( 'updated at', 'updated_at' ), '' ) );

            $data_row = array(
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
                'track_only'               => $track_only,
                'temp_notice_enabled'      => $temp_notice_enabled,
                'temp_notice_text'         => $temp_notice_text !== '' ? $temp_notice_text : null,
                'notes'                    => $notes !== '' ? $notes : null,
                'raw_json'                 => $raw_json !== '' ? $raw_json : null,
                'last_sync_at'             => $last_sync_at,
                'deleted_at'               => $deleted_at,
                'deleted_by'               => $deleted_by > 0 ? $deleted_by : null,
                'created_at'               => $created_at ?: current_time( 'mysql' ),
                'updated_at'               => $updated_at ?: current_time( 'mysql' ),
            );

            $ok = $wpdb->insert( $servers_table, $data_row );
            if ( ! $ok ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: הוספה לטבלה הראשית נכשלה.";
                continue;
            }

            $imported++;
        }

        fclose( $h );

        $this->logger->log_server_event( null, 'import_direct', 'ייבוא CSV לטבלה ראשית', array( 'imported' => $imported, 'skipped' => $skipped ), 'info' );

        if ( ! empty( $errors ) ) {
            set_transient( 'expman_servers_errors', $errors, 90 );
        }

        if ( $imported > 0 ) {
            set_transient( 'expman_servers_imported', $imported, 90 );
        }
    }
}
}
