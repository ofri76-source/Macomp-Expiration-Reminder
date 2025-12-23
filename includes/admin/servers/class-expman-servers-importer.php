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
            $service_tag = trim( (string) $this->get_value( $data, $header_map, array( 'service_tag', 'service tag', 'tag' ), $col[0] ) );
            $customer_number = trim( (string) $this->get_value( $data, $header_map, array( 'customer_number', 'customer number' ), $col[1] ) );
            $customer_name = trim( (string) $this->get_value( $data, $header_map, array( 'customer_name', 'customer name' ), $col[2] ) );
            $last_renewal = trim( (string) $this->get_value( $data, $header_map, array( 'last_renewal_date', 'last renewal date', 'תאריך חידוש אחרון', 'תאריך חידוש' ), $col[3] ) );
            $notes = trim( (string) $this->get_value( $data, $header_map, array( 'notes', 'note', 'הערות' ), $col[4] ) );

            if ( $service_tag === '' ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: חסר Service Tag.";
                continue;
            }

            $service_tag = strtoupper( $service_tag );
            $last_renewal_date = $this->sanitize_date( $last_renewal );

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
                    'last_renewal_date' => $last_renewal_date,
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

        $formats = array( 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'Y-m-d H:i:s' );
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

        return null;
    }
}
}
