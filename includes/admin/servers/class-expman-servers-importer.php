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
        $name = (string) ( $_FILES[ $file_field ]['name'] ?? '' );

        global $wpdb;
        $stage_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_IMPORT_STAGE;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;

        $row_num = 0;
        $imported = 0;
        $skipped = 0;
        $errors = array();

        $rows = $this->read_rows_from_upload( $tmp, $name, $errors );
        if ( empty( $rows ) ) {
            set_transient( 'expman_servers_errors', array_merge( array( 'לא נמצאו שורות ליבוא.' ), $errors ), 90 );
            return;
        }

        $header_map = array();
        $header_row_index = -1;
        $max_scan = min( 10, count( $rows ) );
        for ( $i = 0; $i < $max_scan; $i++ ) {
            $map = $this->build_header_map( $rows[ $i ] );
            if ( isset( $map['service tag'] ) || isset( $map['servicetag'] ) || isset( $map['service_tag'] ) ) {
                $header_row_index = $i;
                $header_map = $map;
                break;
            }
        }
        if ( $header_row_index < 0 ) {
            // Fallback: assume first row is header
            $header_row_index = 0;
            $header_map = $this->build_header_map( $rows[0] );
        }

        $row_count = count( $rows );
        for ( $i = $header_row_index + 1; $i < $row_count; $i++ ) {
            $data = $rows[ $i ];
            $row_num = $i + 1; // file row number (1-based)

            if ( $this->is_empty_row( $data ) ) {
                $skipped++;
                continue;
            }

            $customer_number = trim( (string) $this->get_value( $data, $header_map, array( 'מספר לקוח', 'customer number', 'customer_number' ), '' ) );
            $customer_name   = trim( (string) $this->get_value( $data, $header_map, array( 'שם לקוח', 'customer name', 'customer_name' ), '' ) );
            $service_tag_raw = trim( (string) $this->get_value( $data, $header_map, array( 'service tag', 'service_tag', 'servicetag', 'tag' ), '' ) );
            $service_tag     = $this->normalize_service_tag( $service_tag_raw );
            if ( $service_tag === '' ) {
                $service_tag = $this->guess_service_tag( $data );
            }

            $express_service_code = trim( (string) $this->get_value( $data, $header_map, array( 'express service code', 'express_service_code', 'expressservicecode', 'express code' ), '' ) );
            $ship_date       = $this->sanitize_date( $this->get_value( $data, $header_map, array( 'ship date', 'ship_date', 'תאריך משלוח' ), '' ) );
            $ending_on       = $this->sanitize_date( $this->get_value( $data, $header_map, array( 'ending on', 'ending_on', 'ending', 'end date', 'תאריך סיום' ), '' ) );
            $operating_system= trim( (string) $this->get_value( $data, $header_map, array( 'מערכת הפעלה', 'operating system', 'operating_system' ), '' ) );
            $service_level   = trim( (string) $this->get_value( $data, $header_map, array( 'סוג שירות', 'service level', 'service_level' ), '' ) );
            $server_model    = trim( (string) $this->get_value( $data, $header_map, array( 'דגם שרת', 'server model', 'server_model' ), '' ) );
            $temp_notice_enabled = $this->parse_bool( $this->get_value( $data, $header_map, array( 'הודעה זמנית פעילה', 'temp notice enabled', 'temp_notice_enabled', 'הודעה זמנית' ), '' ) );
            $temp_notice_text= trim( (string) $this->get_value( $data, $header_map, array( 'טקסט הודעה זמנית', 'temp notice text', 'temp_notice_text' ), '' ) );
            $notes           = trim( (string) $this->get_value( $data, $header_map, array( 'הערות', 'notes', 'note' ), '' ) );

            if ( $service_tag === '' ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: חסר Service Tag.";
                continue;
            }

            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$servers_table} WHERE service_tag=%s", $service_tag ) );
            if ( $exists ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: Service Tag כבר קיים ({$service_tag}).";
                continue;
            }

            $stage_exists = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$stage_table} WHERE option_key=%s AND service_tag=%s", $this->option_key, $service_tag )
            );
            if ( $stage_exists ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: Service Tag כבר קיים בשלב ({$service_tag}).";
                continue;
            }

            $ok = $wpdb->insert(
                $stage_table,
                array(
                    'option_key'           => $this->option_key,
                    'customer_number'      => $customer_number !== '' ? $customer_number : null,
                    'customer_name'        => $customer_name !== '' ? $customer_name : null,
                    'service_tag'          => $service_tag,
                    'express_service_code' => $express_service_code !== '' ? $express_service_code : null,
                    'ship_date'            => $ship_date,
                    'ending_on'            => $ending_on,
                    'operating_system'     => $operating_system !== '' ? $operating_system : null,
                    'service_level'        => $service_level !== '' ? $service_level : null,
                    'server_model'         => $server_model !== '' ? $server_model : null,
                    'temp_notice_enabled'  => $temp_notice_enabled,
                    'temp_notice_text'     => $temp_notice_text !== '' ? $temp_notice_text : null,
                    'notes'                => $notes !== '' ? $notes : null,
                    'created_at'           => current_time( 'mysql' ),
                )
            );

            if ( ! $ok ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: הוספה לשלב נכשלה.";
                continue;
            }

            $imported++;
        }

$this->logger->log_server_event( null, 'import', 'ייבוא CSV לשלב', array( 'imported' => $imported, 'skipped' => $skipped ), 'info' );

        if ( ! empty( $errors ) ) {
            set_transient( 'expman_servers_errors', $errors, 90 );
        }

        if ( $imported > 0 ) {
            set_transient( 'expman_servers_imported', $imported, 90 );
        }
    }

    private function normalize_header_key( $s ) {
        $s = (string) $s;
        $s = preg_replace( '/^\xEF\xBB\xBF/', '', $s );
        // Remove common bidi / invisible characters
        $s = preg_replace( '/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{00A0}]/u', ' ', $s );
        $s = trim( preg_replace( '/\s+/', ' ', $s ) );
        return mb_strtolower( $s );
    }

    private function read_rows_from_upload( $tmp_path, $original_name, &$errors ) {
        $ext = strtolower( pathinfo( (string) $original_name, PATHINFO_EXTENSION ) );
        if ( $ext === 'xlsx' ) {
            $rows = $this->read_xlsx_rows( $tmp_path, $errors );
            return is_array( $rows ) ? $rows : array();
        }

        // Default: CSV
        $delimiter = ',';
        $head = @file_get_contents( $tmp_path, false, null, 0, 4096 );
        if ( is_string( $head ) ) {
            $comma = substr_count( $head, ',' );
            $semi  = substr_count( $head, ';' );
            if ( $semi > $comma ) {
                $delimiter = ';';
            }
        }

        $h = fopen( $tmp_path, 'r' );
        if ( ! $h ) {
            $errors[] = 'לא ניתן לקרוא את הקובץ.';
            return array();
        }
        $rows = array();
        while ( ( $data = fgetcsv( $h, 0, $delimiter ) ) !== false ) {
            $rows[] = $data;
        }
        fclose( $h );
        return $rows;
    }

    private function read_xlsx_rows( $path, &$errors ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            $errors[] = 'ZipArchive לא זמין בשרת - לא ניתן לייבא XLSX.';
            return array();
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            $errors[] = 'לא ניתן לפתוח קובץ XLSX.';
            return array();
        }

        $shared = array();
        $sharedXml = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( $sharedXml ) {
            $sx = @simplexml_load_string( $sharedXml );
            if ( $sx ) {
                foreach ( $sx->si as $si ) {
                    // Support rich text (<r><t>)
                    if ( isset( $si->t ) ) {
                        $shared[] = (string) $si->t;
                    } else {
                        $parts = array();
                        foreach ( $si->r as $r ) {
                            $parts[] = (string) $r->t;
                        }
                        $shared[] = implode( '', $parts );
                    }
                }
            }
        }

        $sheetXml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        if ( ! $sheetXml ) {
            $sheetXml = $zip->getFromName( 'xl/worksheets/sheet.xml' );
        }
        if ( ! $sheetXml ) {
            $errors[] = 'לא נמצאה גליון ראשון בקובץ XLSX.';
            $zip->close();
            return array();
        }

        $sx = @simplexml_load_string( $sheetXml );
        if ( ! $sx ) {
            $errors[] = 'קריאת XLSX נכשלה.';
            $zip->close();
            return array();
        }

        $rows = array();
        foreach ( $sx->sheetData->row as $row ) {
            $cells = array();
            foreach ( $row->c as $c ) {
                $ref = (string) $c['r'];
                $colLetters = preg_replace( '/\d+/', '', $ref );
                $colIndex = $this->letters_to_index( $colLetters );

                $v = isset( $c->v ) ? (string) $c->v : '';
                $t = (string) ( $c['t'] ?? '' );
                if ( $t === 's' ) {
                    $idx = intval( $v );
                    $v = isset( $shared[ $idx ] ) ? $shared[ $idx ] : '';
                }
                $cells[ $colIndex ] = $v;
            }
            if ( ! empty( $cells ) ) {
                $max = max( array_keys( $cells ) );
                $arr = array();
                for ( $i = 0; $i <= $max; $i++ ) {
                    $arr[ $i ] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
                }
                $rows[] = $arr;
            }
        }

        $zip->close();
        return $rows;
    }

    private function letters_to_index( $letters ) {
        $letters = strtoupper( (string) $letters );
        $sum = 0;
        for ( $i = 0; $i < strlen( $letters ); $i++ ) {
            $sum = $sum * 26 + ( ord( $letters[ $i ] ) - 64 );
        }
        return max( 0, $sum - 1 );
    }

    private function build_header_map( $row ) {
        $map = array();
        foreach ( $row as $i => $col ) {
            $key = $this->normalize_header_key( $col );
            if ( $key !== '' ) {
                $map[ $key ] = $i;
            }
        }
        return $map;
    }

    private function get_value( $row, $header_map, $keys, $default = '' ) {
        foreach ( (array) $keys as $key ) {
            $key = $this->normalize_header_key( $key );
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

        // Excel serial dates (common when importing XLSX/Excel-exported CSV)
        if ( is_numeric( $value ) ) {
            $n = floatval( $value );
            if ( $n > 20000 && $n < 90000 ) {
                $days = (int) floor( $n );
                $base = new DateTime( '1899-12-30', new DateTimeZone( 'UTC' ) );
                $base->modify( '+' . $days . ' days' );
                return $base->format( 'Y-m-d' );
            }
        }

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

        private function normalize_service_tag( $value ) {
        $value = strtoupper( trim( (string) $value ) );
        $value = preg_replace( '/\s+/', '', $value );
        $value = preg_replace( '/[^A-Z0-9]/', '', $value );
        if ( $value === '' ) { return ''; }
        // Typical Dell Service Tag length is 5-12 characters
        if ( strlen( $value ) < 5 || strlen( $value ) > 12 ) { return ''; }
        return $value;
    }

    private function guess_service_tag( $row ) {
        foreach ( (array) $row as $val ) {
            $cand = $this->normalize_service_tag( $val );
            if ( $cand !== '' ) {
                return $cand;
            }
        }
        return '';
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
        $name = (string) ( $_FILES[ $file_field ]['name'] ?? '' );

        global $wpdb;
        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;

        $row_num = 0;
        $imported = 0;
        $skipped = 0;
        $errors = array();

        $rows = $this->read_rows_from_upload( $tmp, $name, $errors );
        if ( empty( $rows ) ) {
            set_transient( 'expman_servers_errors', array_merge( array( 'לא נמצאו שורות ליבוא.' ), $errors ), 90 );
            return;
        }

        $header_map = array();
        foreach ( $rows as $data ) {
            $row_num++;

            if ( $row_num === 1 ) {
                $header_map = $this->build_header_map( $data );
                continue;
            }

            if ( $this->is_empty_row( $data ) ) {
                $skipped++;
                continue;
            }

            $service_tag = trim( (string) $this->get_value( $data, $header_map, array( 'service tag', 'service_tag', 'servicetag', 'tag' ), '' ) );
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
            $ship_date = $this->sanitize_date( $this->get_value( $data, $header_map, array( 'ship date', 'ship_date', 'תאריך משלוח' ), '' ) );
            $ending_on = $this->sanitize_date( $this->get_value( $data, $header_map, array( 'ending on', 'ending_on', 'ending', 'end date', 'תאריך סיום' ), '' ) );
            $operating_system = trim( (string) $this->get_value( $data, $header_map, array( 'מערכת הפעלה', 'operating system', 'operating_system' ), '' ) );
            $service_level = trim( (string) $this->get_value( $data, $header_map, array( 'סוג שירות', 'service level', 'service_level' ), '' ) );
            $server_model = trim( (string) $this->get_value( $data, $header_map, array( 'דגם שרת', 'server model', 'server_model' ), '' ) );
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
