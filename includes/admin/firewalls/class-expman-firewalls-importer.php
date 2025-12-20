<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Firewalls_Importer' ) ) {
class Expman_Firewalls_Importer {

    private $logger;

    public function __construct( $logger ) {
        $this->logger = $logger;
    }

    public function run() {
        if ( empty( $_FILES['firewalls_file']['tmp_name'] ) ) {
            set_transient( 'expman_firewalls_errors', array( 'לא נבחר קובץ ליבוא.' ), 90 );
            return;
        }

        $tmp = $_FILES['firewalls_file']['tmp_name'];
        $h = fopen( $tmp, 'r' );
        if ( ! $h ) {
            set_transient( 'expman_firewalls_errors', array( 'לא ניתן לקרוא את הקובץ.' ), 90 );
            return;
        }

        global $wpdb;
        $fw_table     = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $types_table  = $wpdb->prefix . Expman_Firewalls_Page::TABLE_TYPES;
        $cust_table   = $wpdb->prefix . 'dc_customers';
        $assets_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FORTICLOUD_ASSETS;

        $request_id = $this->logger->new_request_id();
        $row_num = 0;
        $imported = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = array();
        $header_map = array();
        $import_mode = '';

        while ( ( $data = fgetcsv( $h, 0, ',' ) ) !== false ) {
            $row_num++;

            if ( isset( $data[0] ) ) {
                $data[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $data[0] );
            }

            if ( $row_num === 1 ) {
                $header_map = $this->build_import_header_map( $data );
                if ( ! empty( $header_map ) ) {
                    if ( isset( $header_map['serial number'] ) || isset( $header_map['serial_number_forticloud'] ) ) {
                        $import_mode = 'forticloud';
                    } elseif ( isset( $header_map['serial_number'] ) ) {
                        $import_mode = 'firewalls';
                    }
                    $this->log_import_row( 'import_row', array(
                        'request_id'    => $request_id,
                        'row_number'    => $row_num,
                        'serial_number' => '',
                        'reason'        => 'header',
                        'payload'       => $data,
                        'existing'      => null,
                        'last_query'    => $wpdb->last_query,
                        'last_error'    => $wpdb->last_error,
                    ) );
                    continue;
                }
            }

            if ( $this->is_empty_row( $data ) ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: שורה ריקה.";
                $this->log_import_row( 'import_skip', array(
                    'request_id'    => $request_id,
                    'row_number'    => $row_num,
                    'serial_number' => '',
                    'reason'        => 'empty_row',
                    'payload'       => $data,
                    'existing'      => null,
                    'last_query'    => $wpdb->last_query,
                    'last_error'    => $wpdb->last_error,
                ) );
                continue;
            }

            if ( $import_mode === 'forticloud' ) {
                $serial = $this->get_import_value( $data, $header_map, array( 'serial number', 'serial_number_forticloud' ) );
                $serial = trim( (string) $serial );
                if ( $serial === '' ) {
                    $skipped++;
                    $errors[] = "שורה {$row_num}: חסר מספר סידורי.";
                    $this->log_import_row( 'import_skip', array(
                        'request_id'    => $request_id,
                        'row_number'    => $row_num,
                        'serial_number' => '',
                        'reason'        => 'missing_serial',
                        'payload'       => $data,
                        'existing'      => null,
                        'last_query'    => $wpdb->last_query,
                        'last_error'    => $wpdb->last_error,
                    ) );
                    continue;
                }

                $exists_fw = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$fw_table} WHERE serial_number=%s LIMIT 1", $serial ) );
                $exists_asset = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$assets_table} WHERE serial_number=%s LIMIT 1", $serial ) );
                if ( $exists_fw || $exists_asset ) {
                    $skipped++;
                    $errors[] = "שורה {$row_num}: מספר סידורי כבר קיים ({$serial}).";
                    $this->log_import_row( 'import_skip', array(
                        'request_id'    => $request_id,
                        'row_number'    => $row_num,
                        'serial_number' => $serial,
                        'reason'        => 'serial_exists',
                        'payload'       => $data,
                        'existing'      => array(
                            'firewall_id' => $exists_fw,
                            'asset_id'    => $exists_asset,
                        ),
                        'last_query'    => $wpdb->last_query,
                        'last_error'    => $wpdb->last_error,
                    ) );
                    continue;
                }

                $vendor = 'FortiGate';
                $model  = $this->get_import_value( $data, $header_map, array( 'product model', 'model', 'model_name' ) );
                $desc   = $this->get_import_value( $data, $header_map, array( 'description' ) );
                $expiry_raw = $this->get_import_value( $data, $header_map, array( 'unit expiration date', 'unit expiration da', 'expiration date', 'expiry date', 'expiry_date' ) );
                $registration_raw = $this->get_import_value( $data, $header_map, array( 'registration date', 'registration da', 'registration_date' ) );

                $expiry_date = $this->normalize_import_date( $expiry_raw );
                $registration_date = $this->normalize_import_date( $registration_raw );

                $payload = array(
                    'serial_number'     => $serial,
                    'category_name'     => sanitize_text_field( $vendor ),
                    'model_name'        => $model !== '' ? sanitize_text_field( $model ) : null,
                    'expiration_date'   => $expiry_date,
                    'registration_date' => $registration_date,
                    'description'       => $desc !== '' ? sanitize_textarea_field( $desc ) : null,
                    'created_at'        => current_time( 'mysql' ),
                    'updated_at'        => current_time( 'mysql' ),
                );

                $ok = $wpdb->insert(
                    $assets_table,
                    $payload,
                    array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
                );

                if ( $ok === false ) {
                    $skipped++;
                    $errors[] = "שורה {$row_num}: הוספה נכשלה: " . $wpdb->last_error;
                    $this->log_import_row( 'import_error', array(
                        'request_id'    => $request_id,
                        'row_number'    => $row_num,
                        'serial_number' => $serial,
                        'reason'        => 'insert_failed',
                        'payload'       => $payload,
                        'existing'      => null,
                        'last_query'    => $wpdb->last_query,
                        'last_error'    => $wpdb->last_error,
                    ) );
                    continue;
                }

                $imported++;
                $this->log_import_row( 'import_create', array(
                    'request_id'    => $request_id,
                    'row_number'    => $row_num,
                    'serial_number' => $serial,
                    'reason'        => 'forticloud_asset_created',
                    'payload'       => $payload,
                    'existing'      => null,
                    'last_query'    => $wpdb->last_query,
                    'last_error'    => $wpdb->last_error,
                ) );
                continue;
            }

            $legacy_offset = empty( $header_map ) && count( $data ) <= 15;
            $col = array_pad( $data, 16, '' );
            $id              = intval( trim( $this->get_import_value( $data, $header_map, array( 'id' ), $col[0] ) ) );
            $customer_number = trim( (string) $this->get_import_value( $data, $header_map, array( 'customer_number' ), $col[1] ) );
            $branch          = trim( (string) $this->get_import_value( $data, $header_map, array( 'branch' ), $col[3] ) );
            $serial          = trim( (string) $this->get_import_value( $data, $header_map, array( 'serial_number' ), $col[4] ) );
            $is_managed      = trim( (string) $this->get_import_value( $data, $header_map, array( 'is_managed' ), $col[5] ) ) === '0' ? 0 : 1;
            $track_only      = trim( (string) $this->get_import_value( $data, $header_map, array( 'track_only' ), $col[6] ) ) === '1' ? 1 : 0;
            $vendor          = trim( (string) $this->get_import_value( $data, $header_map, array( 'vendor' ), $col[7] ) );
            $model           = trim( (string) $this->get_import_value( $data, $header_map, array( 'model' ), $col[8] ) );
            $expiry_raw      = $this->get_import_value( $data, $header_map, array( 'expiry_date' ), $col[9] );
            $registration_default = $legacy_offset ? '' : $col[10];
            $access_default = $legacy_offset ? $col[10] : $col[11];
            $notes_default = $legacy_offset ? $col[11] : $col[12];
            $tmp_enabled_default = $legacy_offset ? $col[12] : $col[13];
            $tmp_notice_default = $legacy_offset ? $col[13] : $col[14];

            $registration_raw = $this->get_import_value( $data, $header_map, array( 'registration_date', 'created_at' ), $registration_default );
            $access_url      = trim( (string) $this->get_import_value( $data, $header_map, array( 'access_url' ), $access_default ) );
            $notes           = (string) $this->get_import_value( $data, $header_map, array( 'notes' ), $notes_default );
            $tmp_enabled     = trim( (string) $this->get_import_value( $data, $header_map, array( 'temp_notice_enabled' ), $tmp_enabled_default ) ) === '1' ? 1 : 0;
            $tmp_notice      = (string) $this->get_import_value( $data, $header_map, array( 'temp_notice' ), $tmp_notice_default );
            $expiry          = $this->normalize_import_date( $expiry_raw );
            $registration_date = $this->normalize_import_date( $registration_raw );

            if ( $serial === '' ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: חסר מספר סידורי.";
                $this->log_import_row( 'import_skip', array(
                    'request_id'    => $request_id,
                    'row_number'    => $row_num,
                    'serial_number' => '',
                    'reason'        => 'missing_serial',
                    'payload'       => $data,
                    'existing'      => null,
                    'last_query'    => $wpdb->last_query,
                    'last_error'    => $wpdb->last_error,
                ) );
                continue;
            }

            // customer_id by customer_number (optional)
            $customer_id = null;
            if ( $customer_number !== '' ) {
                $customer_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$cust_table} WHERE is_deleted=0 AND customer_number=%s LIMIT 1",
                    $customer_number
                ) );
                $customer_id = $customer_id ? intval( $customer_id ) : null;
            }

            // vendor/model -> box_type_id (optional)
            $box_type_id = null;
            if ( $vendor !== '' && $model !== '' ) {
                $box_type_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$types_table} WHERE vendor=%s AND model=%s LIMIT 1",
                    $vendor, $model
                ) );
                if ( ! $box_type_id ) {
                    $wpdb->insert(
                        $types_table,
                        array(
                            'vendor'     => $vendor,
                            'model'      => $model,
                            'created_at' => current_time( 'mysql' ),
                            'updated_at' => current_time( 'mysql' ),
                        )
                    );
                    $box_type_id = intval( $wpdb->insert_id );
                } else {
                    $box_type_id = intval( $box_type_id );
                }
            }

            if ( $access_url !== '' && ! preg_match( '/^https?:\/\//i', $access_url ) ) {
                $access_url = 'https://' . ltrim( $access_url );
            }

            // Unique serial check (skip existing rows entirely)
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$fw_table} WHERE serial_number=%s LIMIT 1",
                $serial
            ) );
            if ( $exists ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: מספר סידורי כבר קיים ({$serial}).";
                $this->log_import_row( 'import_skip', array(
                    'request_id'    => $request_id,
                    'row_number'    => $row_num,
                    'serial_number' => $serial,
                    'reason'        => 'serial_exists',
                    'payload'       => $data,
                    'existing'      => array( 'firewall_id' => $exists ),
                    'last_query'    => $wpdb->last_query,
                    'last_error'    => $wpdb->last_error,
                ) );
                continue;
            }

            $payload = array(
                'customer_id'         => $customer_id,
                'branch'              => $branch !== '' ? $branch : null,
                'serial_number'       => $serial,
                'is_managed'          => $is_managed,
                'track_only'          => $track_only,
                'box_type_id'         => $box_type_id,
                'expiry_date'         => $expiry !== '' ? $expiry : null,
                'access_url'          => $access_url !== '' ? $access_url : null,
                'notes'               => $notes,
                'temp_notice_enabled' => $tmp_enabled,
                'temp_notice'         => $tmp_enabled ? $tmp_notice : '',
                'updated_at'          => current_time( 'mysql' ),
            );

            if ( $id > 0 ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: רשומה קיימת (לא מעודכן).";
                $this->log_import_row( 'import_skip', array(
                    'request_id'    => $request_id,
                    'row_number'    => $row_num,
                    'serial_number' => $serial,
                    'reason'        => 'existing_row_id',
                    'payload'       => $payload,
                    'existing'      => array( 'row_id' => $id ),
                    'last_query'    => $wpdb->last_query,
                    'last_error'    => $wpdb->last_error,
                ) );
            } else {
                $payload['created_at'] = $registration_date ? $registration_date : current_time( 'mysql' );
                $ok = $wpdb->insert( $fw_table, $payload );
                if ( $ok === false ) {
                    $skipped++;
                    $errors[] = "שורה {$row_num}: הוספה נכשלה: " . $wpdb->last_error;
                    $this->logger->log_firewall_event( null, 'import_create', 'הוספה נכשלה בייבוא', array( 'error' => $wpdb->last_error ), 'error' );
                    $this->log_import_row( 'import_error', array(
                        'request_id'    => $request_id,
                        'row_number'    => $row_num,
                        'serial_number' => $serial,
                        'reason'        => 'insert_failed',
                        'payload'       => $payload,
                        'existing'      => null,
                        'last_query'    => $wpdb->last_query,
                        'last_error'    => $wpdb->last_error,
                    ) );
                    continue;
                }
                $imported++;
                $this->logger->log_firewall_event( $wpdb->insert_id, 'import_create', 'נוסף רישום בייבוא' );
                $this->log_import_row( 'import_create', array(
                    'request_id'    => $request_id,
                    'row_number'    => $row_num,
                    'serial_number' => $serial,
                    'reason'        => 'created_firewall',
                    'payload'       => $payload,
                    'existing'      => null,
                    'last_query'    => $wpdb->last_query,
                    'last_error'    => $wpdb->last_error,
                ) );
            }
        }

        fclose( $h );
        $summary = array( "ייבוא הסתיים. נוספו {$imported}, עודכנו {$updated}, דולגו {$skipped}." );
        set_transient( 'expman_firewalls_errors', array_merge( $summary, $errors ), 120 );
    }

    private function log_import_row( $action, $context ) {
        $default_context = array(
            'request_id'    => '',
            'row_number'    => 0,
            'serial_number' => '',
            'reason'        => '',
            'payload'       => null,
            'existing'      => null,
            'last_query'    => '',
            'last_error'    => '',
        );
        $context = array_merge( $default_context, (array) $context );
        $this->logger->log_firewall_event( null, $action, '', $context, $action === 'import_error' ? 'error' : 'info' );
    }

    private function build_import_header_map( $row ) {
        $map = array();
        foreach ( (array) $row as $idx => $val ) {
            $key = strtolower( trim( (string) $val ) );
            if ( $key === '' ) {
                continue;
            }
            $map[ $key ] = $idx;
        }
        if ( empty( $map ) ) {
            return array();
        }
        if ( isset( $map['serial number'] ) ) {
            $map['serial_number_forticloud'] = $map['serial number'];
        }
        return $map;
    }

    private function get_import_value( $row, $map, $keys, $fallback = '' ) {
        foreach ( (array) $keys as $key ) {
            if ( isset( $map[ $key ] ) ) {
                return $row[ $map[ $key ] ] ?? '';
            }
        }
        return $fallback;
    }

    private function normalize_import_date( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) {
            return null;
        }
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return $value;
        }
        $ts = strtotime( $value );
        if ( $ts ) {
            return date( 'Y-m-d', $ts );
        }
        return null;
    }

    private function is_empty_row( $row ) {
        foreach ( (array) $row as $value ) {
            if ( trim( (string) $value ) !== '' ) {
                return false;
            }
        }
        return true;
    }
}
}
