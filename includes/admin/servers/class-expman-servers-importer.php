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

            $col = array_pad( $data, 4, '' );
            $service_tag = trim( (string) $this->get_value( $data, $header_map, array( 'service_tag', 'service tag', 'tag' ), $col[0] ) );
            $customer_number = trim( (string) $this->get_value( $data, $header_map, array( 'customer_number', 'customer number' ), $col[1] ) );
            $customer_name = trim( (string) $this->get_value( $data, $header_map, array( 'customer_name', 'customer name' ), $col[2] ) );
            $notes = trim( (string) $this->get_value( $data, $header_map, array( 'notes', 'note' ), $col[3] ) );

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

            $ok = $wpdb->insert(
                $stage_table,
                array(
                    'option_key'      => $this->option_key,
                    'customer_number' => $customer_number !== '' ? $customer_number : null,
                    'customer_name'   => $customer_name !== '' ? $customer_name : null,
                    'service_tag'     => $service_tag,
                    'notes'           => $notes !== '' ? $notes : null,
                    'created_at'      => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%s' )
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
}
}
