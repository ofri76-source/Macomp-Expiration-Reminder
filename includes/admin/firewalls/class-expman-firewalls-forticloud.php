<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Firewalls_Forticloud' ) ) {
class Expman_Firewalls_Forticloud {

    private $logger;
    private $option_key;
    private $notifier;

    public function __construct( $logger, $option_key = '', $notifier = null ) {
        $this->logger = $logger;
        $this->option_key = $option_key;
        $this->notifier = $notifier;
    }

    public function set_option_key( $option_key ) {
        $this->option_key = $option_key;
    }

    public function set_notifier( $notifier ) {
        $this->notifier = $notifier;
    }

    private function add_notice( $message, $type = 'success' ) {
        if ( is_callable( $this->notifier ) ) {
            call_user_func( $this->notifier, $message, $type );
        }
    }

    private function get_crypto_key() {
        return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
    }

    private function encrypt_secret( $plaintext ) {
        $key = $this->get_crypto_key();
        $cipher = 'aes-256-gcm';
        $iv = random_bytes( 12 );
        $tag = '';
        $ciphertext = openssl_encrypt( $plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $ciphertext ) {
            $cipher = 'aes-256-cbc';
            $iv = random_bytes( 16 );
            $ciphertext = openssl_encrypt( $plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv );
            $tag = '';
        }

        return array(
            'cipher' => $cipher,
            'value'  => base64_encode( $ciphertext ),
            'iv'     => base64_encode( $iv ),
            'tag'    => $tag !== '' ? base64_encode( $tag ) : '',
        );
    }

    private function decrypt_secret( $payload ) {
        if ( empty( $payload['value'] ) || empty( $payload['iv'] ) || empty( $payload['cipher'] ) ) {
            return '';
        }

        $key = $this->get_crypto_key();
        $ciphertext = base64_decode( $payload['value'] );
        $iv = base64_decode( $payload['iv'] );
        $tag = ! empty( $payload['tag'] ) ? base64_decode( $payload['tag'] ) : '';

        if ( $payload['cipher'] === 'aes-256-gcm' ) {
            $plain = openssl_decrypt( $ciphertext, $payload['cipher'], $key, OPENSSL_RAW_DATA, $iv, $tag );
        } else {
            $plain = openssl_decrypt( $ciphertext, $payload['cipher'], $key, OPENSSL_RAW_DATA, $iv );
        }

        return $plain ? $plain : '';
    }

    public function get_forticloud_settings() {
        $settings = get_option( $this->option_key, array() );
        return $settings['forticloud'] ?? array();
    }

    private function update_forticloud_settings( $forti_settings ) {
        $settings = get_option( $this->option_key, array() );
        $settings['forticloud'] = $forti_settings;
        update_option( $this->option_key, $settings );
    }

    private function get_forticloud_endpoints() {
        $defaults = array(
            // Fortinet Support Portal - Asset Management API
            'assets' => '/app/asset/api/products',

            // OAuth token retrieval (FortiAuthenticator) - FortiCloud IAM API Users
            'token'  => 'https://customerapiauth.fortinet.com/api/v1/oauth/token/',

            // Optional update endpoint (varies by API availability)
            'update' => '',
        );

        return apply_filters( 'expman_forticloud_endpoints', $defaults );
    }

    private function normalize_base_url_and_endpoints( $base_url, &$endpoints ) {
        $base_url = trim( (string) $base_url );
        if ( $base_url === '' ) {
            return '';
        }

        $parts = wp_parse_url( $base_url );
        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            $base_url = 'https://' . ltrim( $base_url, '/' );
            $parts = wp_parse_url( $base_url );
        }

        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return trim( $base_url );
        }

        $scheme = $parts['scheme'];
        $host   = $parts['host'];
        $port   = isset( $parts['port'] ) ? ':' . intval( $parts['port'] ) : '';
        $path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';

        $normalized = $scheme . '://' . $host . $port;

        // If user pasted a full endpoint, treat the path as the assets endpoint (when matching known prefixes).
        if ( $path && $path !== '/' ) {
            $path = '/' . ltrim( $path, '/' );
            if ( strpos( $path, '/app/asset/api/' ) === 0 || strpos( $path, '/asset/' ) === 0 ) {
                if ( empty( $endpoints['assets'] ) || $endpoints['assets'] === '/app/asset/api/products' || $endpoints['assets'] === '/asset/v1/products' ) {
                    $endpoints['assets'] = $path;
                }
            }
        }

        return $normalized;
    }

    public function action_save_forticloud_settings() {
        $api_id     = sanitize_text_field( $_POST['forticloud_api_id'] ?? '' );
        $client_id  = sanitize_text_field( $_POST['forticloud_client_id'] ?? '' );

        $base_url_in = isset( $_POST['forticloud_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['forticloud_base_url'] ) ) : '';
        $secret_new  = isset( $_POST['forticloud_api_secret'] ) ? trim( (string) wp_unslash( $_POST['forticloud_api_secret'] ) ) : '';

        $forti_settings = $this->get_forticloud_settings();
        $prev_settings  = $forti_settings;
        $forti_settings['api_id']    = $api_id;
        $forti_settings['client_id'] = $client_id !== '' ? $client_id : 'assetmanagement';

        // Normalize base_url: keep only scheme://host and, if user pasted an endpoint path, let it override endpoints (runtime)
        $tmp_endpoints = $this->get_forticloud_endpoints();
        $base_url_norm = $this->normalize_base_url_and_endpoints( $base_url_in, $tmp_endpoints );
        $forti_settings['base_url']  = $base_url_norm !== '' ? $base_url_norm : $base_url_in;

        if ( $secret_new !== '' ) {
            $forti_settings['api_secret'] = $this->encrypt_secret( $secret_new );
        }

        $this->update_forticloud_settings( $forti_settings );
        $this->add_notice( 'הגדרות FortiCloud נשמרו.' );
        $changes = array();
        $settings_fields = array(
            array( 'label' => 'FortiCloud API ID', 'from' => $prev_settings['api_id'] ?? '', 'to' => $forti_settings['api_id'] ?? '' ),
            array( 'label' => 'Client ID', 'from' => $prev_settings['client_id'] ?? '', 'to' => $forti_settings['client_id'] ?? '' ),
            array( 'label' => 'Base URL', 'from' => $prev_settings['base_url'] ?? '', 'to' => $forti_settings['base_url'] ?? '' ),
        );
        foreach ( $settings_fields as $field ) {
            if ( (string) $field['from'] !== (string) $field['to'] ) {
                $changes[] = array(
                    'field' => $field['label'],
                    'from'  => (string) $field['from'],
                    'to'    => (string) $field['to'],
                );
            }
        }
        if ( $secret_new !== '' ) {
            $changes[] = array(
                'field' => 'API Secret',
                'from'  => '***',
                'to'    => 'עודכן',
            );
        }

        $this->logger->log_firewall_event( null, 'forticloud_settings', 'הגדרות FortiCloud נשמרו', array(
            'changes' => $changes,
        ), 'info' );
    }

    public function action_sync_forticloud_assets() {
        $settings  = $this->get_forticloud_settings();
        $api_id    = $settings['api_id'] ?? '';
        $client_id = $settings['client_id'] ?? 'assetmanagement';
        $base_url  = $settings['base_url'] ?? '';
        $secret    = $this->decrypt_secret( $settings['api_secret'] ?? array() );

        $request_id = $this->logger->new_request_id();
        $this->logger->log_firewall_event( null, 'forticloud_sync', 'התחלת סנכרון נכסים', array(
            'request_id' => $request_id,
        ), 'info' );

        if ( $base_url === '' ) {
            $base_url = 'https://support.fortinet.com';
        }

        $endpoints = $this->get_forticloud_endpoints();

        // Normalize base_url (strip pasted path) and allow pasted endpoint path to override assets endpoint.
        $base_url = $this->normalize_base_url_and_endpoints( $base_url, $endpoints );

        $token_ep  = $endpoints['token'] ?? '';
        $assets_ep = $endpoints['assets'] ?? '';

        if ( $assets_ep === '' ) {
            $this->add_notice( 'חסר endpoint לנכסים (assets).', 'error' );
            $this->logger->log_firewall_event( null, 'forticloud_sync', 'חסר endpoint לנכסים (assets)', array(
                'request_id' => $request_id,
                'endpoints'  => $endpoints,
            ), 'error' );
            return;
        }

        if ( $api_id === '' || $client_id === '' || $secret === '' ) {
            $this->add_notice( 'חסרים פרטי התחברות ל-FortiCloud/IAM (API Key + Password + Client ID).', 'error' );
            $this->logger->log_firewall_event( null, 'forticloud_sync', 'חסרים פרטי התחברות', array(
                'request_id' => $request_id,
            ), 'error' );
            return;
        }

        if ( $token_ep === '' ) {
            $this->add_notice( 'חסר endpoint לטוקן. ברירת מחדל היא customerapiauth.fortinet.com. בדוק filters/endpoints.', 'error' );
            $this->logger->log_firewall_event( null, 'forticloud_sync', 'חסר endpoint לטוקן', array(
                'request_id' => $request_id,
                'endpoints'  => $endpoints,
            ), 'error' );
            return;
        }

        // Token URL can be absolute.
        $token_url = ( preg_match( '#^https?://#i', $token_ep ) ) ? $token_ep : ( rtrim( $base_url, '/' ) . $token_ep );

        $token_payload = array(
            'username'   => $api_id,
            'password'   => $secret,
            'client_id'  => $client_id,
            'grant_type' => 'password',
        );
        $token_payload = apply_filters( 'expman_forticloud_token_request_body', $token_payload, $settings );

        $token_response = wp_remote_post( $token_url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body'    => wp_json_encode( $token_payload ),
        ) );

        $this->logger->log_firewall_event( null, 'forticloud_sync', 'תגובת טוקן (debug)', array(
            'request_id' => $request_id,
            'debug'      => $this->logger->http_debug_context( $token_url, $token_response ),
        ), is_wp_error( $token_response ) ? 'error' : 'info' );

        if ( is_wp_error( $token_response ) ) {
            $this->add_notice( 'שגיאה בקבלת טוקן: ' . $token_response->get_error_message(), 'error' );
            return;
        }

        $token_raw  = (string) wp_remote_retrieve_body( $token_response );
        $token_data = json_decode( $token_raw, true );

        if ( ! is_array( $token_data ) ) {
            $this->logger->log_firewall_event( null, 'forticloud_sync', 'טוקן: JSON לא תקין/ריק', array(
                'request_id'        => $request_id,
                'http_code'         => wp_remote_retrieve_response_code( $token_response ),
                'json_error'        => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : json_last_error(),
                'token_raw_preview' => mb_substr( $token_raw, 0, 2000 ),
            ), 'error' );
            $this->add_notice( 'שגיאה בפענוח תגובת הטוקן (לא JSON / ריק). ראה לוגים.', 'error' );
            return;
        }

        $access_token = $token_data['access_token'] ?? '';
        if ( $access_token === '' ) {
            $this->logger->log_firewall_event( null, 'forticloud_sync', 'לא התקבל access token', array(
                'request_id'        => $request_id,
                'http_code'         => wp_remote_retrieve_response_code( $token_response ),
                'token_keys'        => array_keys( $token_data ),
                'token_raw_preview' => mb_substr( $token_raw, 0, 2000 ),
            ), 'error' );
            $this->add_notice( 'לא התקבל access token מהשרת. ראה לוגים.', 'error' );
            return;
        }

        // Assets request
        $assets_url = rtrim( $base_url, '/' ) . $assets_ep;

        // Some Fortinet gateways accept token also as query param; keep it optional but do not log it.
        $assets_url_with_qs = add_query_arg(
            array(
                'access_token' => $access_token,
                'client_id'    => $client_id,
            ),
            $assets_url
        );

        $headers = array(
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $access_token,
            'X-API-Key'     => $api_id,
            'X-Client-ID'   => $client_id,
        );

        $assets_response = wp_remote_get( $assets_url_with_qs, array(
            'timeout' => 45,
            'headers' => $headers,
        ) );

        // Redact token from URL in logs.
        $assets_log_url = remove_query_arg( 'access_token', $assets_url_with_qs );

        $this->logger->log_firewall_event( null, 'forticloud_sync', 'תגובת נכסים (debug)', array(
            'request_id' => $request_id,
            'debug'      => $this->logger->http_debug_context( $assets_log_url, $assets_response ),
        ), is_wp_error( $assets_response ) ? 'error' : 'info' );

        if ( is_wp_error( $assets_response ) ) {
            $this->add_notice( 'שגיאה בשליפת נכסים: ' . $assets_response->get_error_message(), 'error' );
            return;
        }

        $assets_raw = (string) wp_remote_retrieve_body( $assets_response );
        $assets_payload = json_decode( $assets_raw, true );

        if ( ! is_array( $assets_payload ) ) {
            $this->add_notice( 'תגובה לא צפויה (לא JSON). ראה לוגים.', 'error' );
            $this->logger->log_firewall_event( null, 'forticloud_sync', 'תגובה לא JSON', array(
                'request_id' => $request_id,
                'http_code'  => wp_remote_retrieve_response_code( $assets_response ),
                'raw_preview'=> mb_substr( $assets_raw, 0, 2000 ),
            ), 'error' );
            return;
        }

        $assets = $this->normalize_assets_payload( $assets_payload );

        if ( empty( $assets ) ) {
            $this->add_notice( 'לא נמצאו נכסים לעדכון (payload ריק/מבנה שונה). ראה לוגים.', 'warning' );
            $this->logger->log_firewall_event( null, 'forticloud_sync', 'לא נמצאו נכסים לעדכון', array(
                'request_id'    => $request_id,
                'payload_keys'  => array_keys( $assets_payload ),
            ), 'warning' );
            return;
        }

        $saved = $this->upsert_forticloud_assets( $assets, $request_id );
        $this->add_notice( 'סנכרון הושלם. עודכנו ' . intval( $saved ) . ' נכסים.' );
        $this->logger->log_firewall_event( null, 'forticloud_sync', 'סנכרון הושלם', array(
            'request_id' => $request_id,
            'count'      => intval( $saved ),
        ), 'info' );
    }

    private function normalize_assets_payload( $payload ) {
        if ( empty( $payload ) ) {
            return array();
        }

        $candidates = array();
        foreach ( array( 'data', 'items', 'assets', 'products' ) as $key ) {
            if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
                $candidates = $payload[ $key ];
                break;
            }
        }

        if ( empty( $candidates ) && is_array( $payload ) ) {
            $candidates = $payload;
        }

        return is_array( $candidates ) ? $candidates : array();
    }

    private function get_asset_value( $asset, $keys ) {
        foreach ( (array) $keys as $key ) {
            if ( isset( $asset[ $key ] ) && $asset[ $key ] !== '' ) {
                return $asset[ $key ];
            }
        }
        return null;
    }

    private function upsert_forticloud_assets( $assets, $request_id = '' ) {
        global $wpdb;
        $assets_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FORTICLOUD_ASSETS;
        $saved = 0;

        foreach ( (array) $assets as $asset ) {
            if ( ! is_array( $asset ) ) {
                continue;
            }

            $serial = sanitize_text_field( $this->get_asset_value( $asset, array( 'serial_number', 'serialNumber', 'serial', 'sn' ) ) );
            if ( $serial === '' ) {
                continue;
            }

            $forticloud_id = sanitize_text_field( $this->get_asset_value( $asset, array( 'id', 'asset_id', 'product_id' ) ) );
            $category = sanitize_text_field( $this->get_asset_value( $asset, array( 'category_name', 'categoryName', 'category' ) ) );
            $model = sanitize_text_field( $this->get_asset_value( $asset, array( 'model_name', 'modelName', 'model' ) ) );
            $description = sanitize_textarea_field( $this->get_asset_value( $asset, array( 'description', 'desc' ) ) );
            $folder = sanitize_text_field( $this->get_asset_value( $asset, array( 'folder_id', 'folderId', 'folder' ) ) );
            $groups = $this->get_asset_value( $asset, array( 'asset_groups', 'assetGroups', 'groups' ) );
            $groups_value = is_array( $groups ) ? wp_json_encode( $groups ) : sanitize_text_field( (string) $groups );

            $registration = $this->get_asset_value( $asset, array( 'registration_date', 'registrationDate', 'registered_at' ) );
            $ship = $this->get_asset_value( $asset, array( 'ship_date', 'shipDate', 'ship_at' ) );
            $expiration = $this->get_asset_value( $asset, array( 'expiration_date', 'expirationDate', 'expiry_date', 'expiryDate' ) );

            $registration_date = $registration ? date( 'Y-m-d', strtotime( (string) $registration ) ) : null;
            $ship_date = $ship ? date( 'Y-m-d', strtotime( (string) $ship ) ) : null;
            $expiration_date = $expiration ? date( 'Y-m-d', strtotime( (string) $expiration ) ) : null;

            $data = array(
                'forticloud_id'     => $forticloud_id !== '' ? $forticloud_id : null,
                'serial_number'     => $serial,
                'category_name'     => $category !== '' ? $category : null,
                'model_name'        => $model !== '' ? $model : null,
                'registration_date' => $registration_date,
                'ship_date'         => $ship_date,
                'expiration_date'   => $expiration_date,
                'description'       => $description !== '' ? $description : null,
                'folder_id'         => $folder !== '' ? $folder : null,
                'asset_groups'      => $groups_value !== '' ? $groups_value : null,
                'raw_json'          => wp_json_encode( $asset ),
                'updated_at'        => current_time( 'mysql' ),
            );

            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$assets_table} WHERE serial_number=%s LIMIT 1", $serial ), ARRAY_A );

            if ( $existing ) {
                $ok = $wpdb->update( $assets_table, $data, array( 'id' => intval( $existing['id'] ) ) );
                if ( $ok !== false ) {
                    $changed = array();
                    foreach ( array( 'forticloud_id','category_name','model_name','registration_date','ship_date','expiration_date','description','folder_id','asset_groups' ) as $k ) {
                        $before = isset( $existing[ $k ] ) ? (string) $existing[ $k ] : '';
                        $after  = isset( $data[ $k ] ) ? (string) $data[ $k ] : '';
                        if ( $before !== $after ) {
                            $changed[ $k ] = array( 'before' => $before, 'after' => $after );
                        }
                    }

                    if ( ! empty( $changed ) ) {
                        $this->logger->log_firewall_event( null, 'forticloud_asset_update', 'עודכן נכס FortiCloud', array(
                            'request_id' => $request_id,
                            'serial'     => $serial,
                            'asset_id'   => $existing['id'],
                            'changed'    => $changed,
                        ), 'info' );
                    }
                    $saved++;
                } else {
                    $this->logger->log_firewall_event( null, 'forticloud_asset_update', 'עדכון נכס נכשל', array(
                        'request_id' => $request_id,
                        'serial'     => $serial,
                        'error'      => $wpdb->last_error,
                    ), 'error' );
                }
            } else {
                $data['created_at'] = current_time( 'mysql' );
                $ok = $wpdb->insert( $assets_table, $data );
                if ( $ok !== false ) {
                    $this->logger->log_firewall_event( null, 'forticloud_asset_create', 'נוסף נכס FortiCloud', array(
                        'request_id' => $request_id,
                        'serial'     => $serial,
                        'asset_id'   => intval( $wpdb->insert_id ),
                    ), 'info' );
                    $saved++;
                } else {
                    $this->logger->log_firewall_event( null, 'forticloud_asset_create', 'הוספת נכס נכשלה', array(
                        'request_id' => $request_id,
                        'serial'     => $serial,
                        'error'      => $wpdb->last_error,
                    ), 'error' );
                }
            }
        }

        return $saved;
    }

    public function update_forticloud_description( $asset, $customer ) {
        $settings = $this->get_forticloud_settings();
        $api_id = $settings['api_id'] ?? '';
        $client_id = $settings['client_id'] ?? 'assetmanagement';
        $base_url = $settings['base_url'] ?? '';
        $secret = $this->decrypt_secret( $settings['api_secret'] ?? array() );

        if ( $api_id === '' || $client_id === '' || $secret === '' ) {
            return;
        }

        if ( $base_url === '' ) {
            $base_url = 'https://api.forticloud.com';
        }

        $endpoints = $this->get_forticloud_endpoints();
        $token_url = rtrim( $base_url, '/' ) . ( $endpoints['token'] ?? '' );

        $token_body = array(
            'grant_type'    => 'client_credentials',
            'client_id'     => $client_id,
            'client_secret' => $secret,
            'api_id'        => $api_id,
            'scope'         => 'assetmanagement',
        );
        $token_body = apply_filters( 'expman_forticloud_token_request_body', $token_body, $settings );

        $token_response = wp_remote_post( $token_url, array(
            'timeout' => 20,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'    => $token_body,
        ) );

        if ( is_wp_error( $token_response ) ) {
            return;
        }

        $token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );
        $access_token = $token_data['access_token'] ?? '';
        if ( $access_token === '' ) {
            return;
        }

        $endpoint = $endpoints['update'] ?? '';
        if ( $endpoint === '' ) {
            return;
        }

        $serial = isset( $asset->serial_number ) ? (string) $asset->serial_number : '';
        $asset_id = isset( $asset->forticloud_id ) ? (string) $asset->forticloud_id : '';
        $endpoint = str_replace( array( '{serial}', '{id}' ), array( rawurlencode( $serial ), rawurlencode( $asset_id ) ), $endpoint );
        $update_url = rtrim( $base_url, '/' ) . $endpoint;

        $description = trim( $customer->customer_number . ' - ' . $customer->customer_name );
        $payload = apply_filters(
            'expman_forticloud_update_payload',
            array( 'description' => $description ),
            $asset,
            $customer
        );

        $update_response = wp_remote_request( $update_url, array(
            'method'  => 'PATCH',
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $update_response ) ) {
            $this->logger->log_firewall_event( null, 'forticloud_update', 'שגיאה בעדכון תיאור', array( 'error' => $update_response->get_error_message() ), 'error' );
            return;
        }

        $status = wp_remote_retrieve_response_code( $update_response );
        if ( $status >= 400 ) {
            $this->logger->log_firewall_event(
                null,
                'forticloud_update',
                'שגיאה בעדכון תיאור',
                array(
                    'status' => $status,
                    'body'   => wp_remote_retrieve_body( $update_response ),
                ),
                'error'
            );
        }
    }
}
}
