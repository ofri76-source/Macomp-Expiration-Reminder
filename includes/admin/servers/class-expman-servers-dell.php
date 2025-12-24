<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Servers_Dell' ) ) {
class Expman_Servers_Dell {

    const TOKEN_URL = 'https://api.dell.com/auth/oauth/v2/token';
    const WARRANTY_URL = 'https://api.dell.com/support/assetinfo/v4/getassetwarranty';

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

    public function get_settings() {
        $settings = get_option( $this->option_key, array() );
        return $settings['dell'] ?? array();
    }

    private function update_settings( $dell_settings ) {
        $settings = get_option( $this->option_key, array() );
        $settings['dell'] = $dell_settings;
        update_option( $this->option_key, $settings );
    }

    public function action_save_dell_settings() {
        $client_id = sanitize_text_field( $_POST['dell_client_id'] ?? '' );
        $client_secret = sanitize_text_field( $_POST['dell_client_secret'] ?? '' );
        $api_key = sanitize_text_field( $_POST['dell_api_key'] ?? '' );
        $red_days = intval( $_POST['dell_red_days'] ?? 30 );
        $yellow_days = intval( $_POST['dell_yellow_days'] ?? 60 );
        $os_list_raw = isset( $_POST['dell_os_list'] ) ? (array) $_POST['dell_os_list'] : array();
        $operating_systems = array();
        foreach ( $os_list_raw as $os ) {
            $os = sanitize_text_field( wp_unslash( $os ) );
            if ( $os === '' ) { continue; }
            $operating_systems[] = $os;
        }
        $contact_names  = isset( $_POST['dell_contact_name'] ) ? (array) $_POST['dell_contact_name'] : array();
        $contact_emails = isset( $_POST['dell_contact_email'] ) ? (array) $_POST['dell_contact_email'] : array();
        $contacts = array();
        $max = max( count( $contact_names ), count( $contact_emails ) );
        for ( $i = 0; $i < $max; $i++ ) {
            $name  = sanitize_text_field( wp_unslash( $contact_names[ $i ] ?? '' ) );
            $email = sanitize_email( wp_unslash( $contact_emails[ $i ] ?? '' ) );
            if ( $name === '' && $email === '' ) { continue; }
            $contacts[] = array( 'name' => $name, 'email' => $email );
        }

        // Backward-compat: support saving single contact (if UI not updated for some reason)
        if ( empty( $contacts ) ) {
            $raw_name  = $_POST['dell_contact_name'] ?? '';
            $raw_email = $_POST['dell_contact_email'] ?? '';
            if ( is_array( $raw_name ) ) {
                $raw_name = $raw_name[0] ?? '';
            }
            if ( is_array( $raw_email ) ) {
                $raw_email = $raw_email[0] ?? '';
            }
            $single_name  = sanitize_text_field( wp_unslash( $raw_name ) );
            $single_email = sanitize_email( wp_unslash( $raw_email ) );
            if ( $single_name !== '' || $single_email !== '' ) {
                $contacts[] = array( 'name' => $single_name, 'email' => $single_email );
            }
        }

        $contact_name  = (string) ( $contacts[0]['name'] ?? '' );
        $contact_email = (string) ( $contacts[0]['email'] ?? '' );

        $prev = $this->get_settings();
        $settings = $prev;
        $settings['client_id'] = $client_id;
        $settings['client_secret'] = $client_secret;
        $settings['api_key'] = $api_key;
        $settings['red_days'] = $red_days;
        $settings['yellow_days'] = $yellow_days;
        $settings['contacts'] = $contacts;
        if ( ! empty( $operating_systems ) ) {
            $settings['operating_systems'] = $operating_systems;
        } else {
            unset( $settings['operating_systems'] );
        }
        // Keep single fields for older code paths
        $settings['contact_name'] = $contact_name;
        $settings['contact_email'] = $contact_email;

        $this->update_settings( $settings );
        $this->add_notice( 'הגדרות Dell TechDirect נשמרו.' );

        $changes = array();
        $fields = array(
            array( 'label' => 'Client ID', 'from' => $prev['client_id'] ?? '', 'to' => $client_id ),
            array( 'label' => 'API Key', 'from' => $prev['api_key'] ?? '', 'to' => $api_key ),
            array( 'label' => 'Red Days', 'from' => $prev['red_days'] ?? '', 'to' => $red_days ),
            array( 'label' => 'Yellow Days', 'from' => $prev['yellow_days'] ?? '', 'to' => $yellow_days ),
            array( 'label' => 'איש קשר להצעות', 'from' => $prev['contact_name'] ?? '', 'to' => $contact_name ),
            array( 'label' => 'מייל איש קשר', 'from' => $prev['contact_email'] ?? '', 'to' => $contact_email ),
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
        if ( $client_secret !== '' && ( $prev['client_secret'] ?? '' ) !== $client_secret ) {
            $changes[] = array(
                'field' => 'Client Secret',
                'from'  => '***',
                'to'    => 'עודכן',
            );
        }

        $this->logger->log_server_event( null, 'settings', 'הגדרות Dell נשמרו', array( 'changes' => $changes ), 'info' );
    }

    private function get_token() {
        $settings = $this->get_settings();
        $client_id = $settings['client_id'] ?? '';
        $client_secret = $settings['client_secret'] ?? '';

        if ( $client_id === '' || $client_secret === '' ) {
            return new WP_Error( 'missing_credentials', 'חסר Client ID או Client Secret.' );
        }

        $cache_key = 'expman_dell_token_' . md5( $client_id );
        $cached = get_transient( $cache_key );
        if ( $cached ) {
            return $cached;
        }

        $response = wp_remote_post(
            self::TOKEN_URL,
            array(
                'timeout' => 20,
                'body'    => array(
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
            return new WP_Error( 'token_error', 'קבלת טוקן נכשלה.' );
        }

        $token = $body['access_token'];
        $expires = intval( $body['expires_in'] ?? 3000 );
        set_transient( $cache_key, $token, max( 60, $expires - 60 ) );

        return $token;
    }

    private function normalize_date( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) {
            return null;
        }
        $ts = strtotime( $value );
        if ( ! $ts ) {
            return null;
        }
        return gmdate( 'Y-m-d', $ts );
    }

    public function fetch_warranty_bulk( $service_tags ) {
        $service_tags = array_values( array_filter( array_map( 'trim', (array) $service_tags ) ) );
        if ( empty( $service_tags ) ) {
            return array();
        }

        $token = $this->get_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $settings = $this->get_settings();
        $api_key = $settings['api_key'] ?? '';

        $url = self::WARRANTY_URL;
        if ( $api_key !== '' ) {
            $url = add_query_arg( 'apikey', $api_key, $url );
        }

        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ),
                'body' => 'ID=' . implode( ',', array_map( 'rawurlencode', $service_tags ) ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'dell_http', 'שגיאת תקשורת מול Dell API (' . $code . ').' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'dell_body', 'תגובה לא תקינה מ-Dell API.' );
        }

        $assets = array();
        if ( isset( $body['AssetWarrantyResponse']['AssetWarranty'] ) ) {
            $assets = $body['AssetWarrantyResponse']['AssetWarranty'];
        } elseif ( isset( $body['AssetWarranty'] ) ) {
            $assets = $body['AssetWarranty'];
        } elseif ( isset( $body['AssetWarrantyResponse'] ) ) {
            $assets = $body['AssetWarrantyResponse'];
        }

        if ( isset( $assets['ServiceTag'] ) ) {
            $assets = array( $assets );
        }

        $results = array();
        foreach ( (array) $assets as $asset ) {
            if ( ! is_array( $asset ) ) {
                continue;
            }
            $tag = $asset['ServiceTag'] ?? $asset['serviceTag'] ?? $asset['service_tag'] ?? '';
            $tag = strtoupper( trim( (string) $tag ) );
            if ( $tag === '' ) {
                continue;
            }

            $express = $asset['ExpressServiceCode'] ?? $asset['expressServiceCode'] ?? '';
            $ship_date = $asset['ShipDate'] ?? $asset['shipDate'] ?? '';
            $model = $asset['MachineDescription'] ?? $asset['SystemDescription'] ?? $asset['machineDescription'] ?? $asset['systemDescription'] ?? '';

            $entitlements = array();
            if ( isset( $asset['Entitlements']['Entitlement'] ) ) {
                $entitlements = $asset['Entitlements']['Entitlement'];
            } elseif ( isset( $asset['Entitlements'] ) ) {
                $entitlements = $asset['Entitlements'];
            }

            if ( isset( $entitlements['EndDate'] ) ) {
                $entitlements = array( $entitlements );
            }

            $max_end = null;
            $service_level = '';
            foreach ( (array) $entitlements as $ent ) {
                if ( ! is_array( $ent ) ) {
                    continue;
                }
                $end = $ent['EndDate'] ?? $ent['endDate'] ?? '';
                $level = $ent['ServiceLevelDescription'] ?? $ent['serviceLevelDescription'] ?? '';
                $normalized = $this->normalize_date( $end );
                if ( $normalized && ( $max_end === null || $normalized > $max_end ) ) {
                    $max_end = $normalized;
                    $service_level = (string) $level;
                }
            }

            if ( $service_level === '' ) {
                $service_level = (string) ( $asset['ServiceLevelDescription'] ?? $asset['serviceLevelDescription'] ?? '' );
            }

            $results[ $tag ] = array(
                'service_tag'          => $tag,
                'express_service_code' => $express !== '' ? (string) $express : null,
                'ship_date'            => $this->normalize_date( $ship_date ),
                'ending_on'            => $max_end,
                'service_level'        => $service_level !== '' ? (string) $service_level : null,
                'server_model'         => $model !== '' ? (string) $model : null,
                'raw_json'             => $asset,
            );
        }

        return $results;
    }
}
}
