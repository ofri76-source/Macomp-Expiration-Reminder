<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Expman_Settings_Page {

    private $option_key;

    public function __construct( $option_key ) { $this->option_key = $option_key; }

    private function maybe_save_settings( &$settings ) {
        if ( ! isset( $_POST['expman_save_settings'] ) ) {
            return false;
        }

        if ( ! check_admin_referer( 'expman_save_settings' ) ) {
            return false;
        }

        $settings['yellow_threshold']   = intval( $_POST['yellow_threshold'] ?? 60 );
        $settings['red_threshold']      = intval( $_POST['red_threshold'] ?? 30 );
        $settings['log_retention_days'] = intval( $_POST['log_retention_days'] ?? 90 );
        $settings['customers_table']    = sanitize_text_field( $_POST['customers_table'] ?? '' );
        $settings['env']                = isset( $_POST['env_test'] ) ? 'test' : 'prod';
        $settings['show_bulk_tab']      = isset( $_POST['show_bulk_tab'] ) ? 1 : 0;

        $settings['public_urls'] = array(
            'dashboard' => esc_url_raw( $_POST['public_url_dashboard'] ?? '' ),
            'firewalls' => esc_url_raw( $_POST['public_url_firewalls'] ?? '' ),
            'certs'     => esc_url_raw( $_POST['public_url_certs'] ?? '' ),
            'domains'   => esc_url_raw( $_POST['public_url_domains'] ?? '' ),
            'servers'   => esc_url_raw( $_POST['public_url_servers'] ?? '' ),
            'trash'     => esc_url_raw( $_POST['public_url_trash'] ?? '' ),
            'logs'      => esc_url_raw( $_POST['public_url_logs'] ?? '' ),
            'settings'  => esc_url_raw( $_POST['public_url_settings'] ?? '' ),
            'customers' => esc_url_raw( $_POST['public_url_customers'] ?? '' ),
        );

        update_option( $this->option_key, $settings );
        return true;
    }

    private function get_data_tables() {
        global $wpdb;
        return array(
            $wpdb->prefix . 'exp_items',
            $wpdb->prefix . 'exp_logs',
            $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS,
            $wpdb->prefix . Expman_Firewalls_Page::TABLE_TYPES,
            $wpdb->prefix . Expman_Firewalls_Page::TABLE_FORTICLOUD_ASSETS,
            $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALL_LOGS,
            $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALL_IMPORT_STAGE,
        );
    }

    private function export_plugin_data() {
        global $wpdb;
        $payload = array(
            'generated_at' => current_time( 'mysql' ),
            'tables'       => array(),
            'settings'     => get_option( $this->option_key, array() ),
        );

        foreach ( $this->get_data_tables() as $table ) {
            $rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
            $payload['tables'][ $table ] = $rows ? $rows : array();
        }

        $json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
        if ( ! headers_sent() ) {
            nocache_headers();
            header( 'Content-Type: application/json; charset=UTF-8' );
            header( 'Content-Disposition: attachment; filename=expman-data-' . date( 'Ymd-His' ) . '.json' );
        }
        echo $json;
        exit;
    }

    private function import_plugin_data() {
        global $wpdb;
        if ( empty( $_FILES['expman_data_file']['tmp_name'] ) ) {
            return 'לא נבחר קובץ לייבוא.';
        }
        $raw = file_get_contents( $_FILES['expman_data_file']['tmp_name'] );
        if ( ! $raw ) {
            return 'לא ניתן לקרוא את הקובץ.';
        }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || empty( $data['tables'] ) ) {
            return 'פורמט קובץ לא תקין.';
        }

        foreach ( $this->get_data_tables() as $table ) {
            if ( ! isset( $data['tables'][ $table ] ) || ! is_array( $data['tables'][ $table ] ) ) {
                continue;
            }
            $wpdb->query( "TRUNCATE TABLE {$table}" );
            $columns = $wpdb->get_col( "DESC {$table}", 0 );
            foreach ( $data['tables'][ $table ] as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $filtered = array_intersect_key( $row, array_flip( $columns ) );
                if ( ! empty( $filtered ) ) {
                    $wpdb->insert( $table, $filtered );
                }
            }
        }

        if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
            update_option( $this->option_key, $data['settings'] );
        }

        return '';
    }

    private function render_settings_form( $settings, $public_urls, $env ) {
        echo '<form method="post">';
        wp_nonce_field( 'expman_save_settings' );

        echo '<h3>Environment</h3>';
        echo '<table class="form-table"><tr><th><label for="env_test">Server is TEST</label></th><td>';
        echo '<label><input type="checkbox" name="env_test" id="env_test" value="1" ' . checked( $env, 'test', false ) . ' /> Use TEST URLs</label>';
        echo '</td></tr></table>';

        echo '<h3>Thresholds</h3>';
        echo '<table class="form-table">';
        echo '<tr><th><label for="yellow_threshold">Yellow threshold (days)</label></th><td><input type="number" name="yellow_threshold" id="yellow_threshold" value="' . esc_attr( $settings['yellow_threshold'] ?? 90 ) . '" /></td></tr>';
        echo '<tr><th><label for="red_threshold">Red threshold (days)</label></th><td><input type="number" name="red_threshold" id="red_threshold" value="' . esc_attr( $settings['red_threshold'] ?? 30 ) . '" /></td></tr>';
        echo '<tr><th><label for="log_retention_days">Log retention (days)</label></th><td><input type="number" name="log_retention_days" id="log_retention_days" value="' . esc_attr( $settings['log_retention_days'] ?? 90 ) . '" /></td></tr>';
        echo '<tr><th><label for="customers_table">Customers table name</label></th><td><input type="text" name="customers_table" id="customers_table" value="' . esc_attr( $settings['customers_table'] ?? '' ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="show_bulk_tab">הצגת טאב עריכה קבוצתית</label></th><td><label><input type="checkbox" name="show_bulk_tab" id="show_bulk_tab" value="1" ' . checked( ! empty( $settings['show_bulk_tab'] ), true, false ) . ' /> הצג טאב עריכה קבוצתית</label></td></tr>';
        echo '</table>';

        echo '<h3>Production Public URLs</h3>';
        echo '<table class="form-table">';
        echo '<tr><th><label for="public_url_dashboard">Dashboard URL</label></th><td><input type="url" name="public_url_dashboard" id="public_url_dashboard" value="' . esc_attr( $public_urls['dashboard'] ?? '' ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="public_url_firewalls">Firewalls URL</label></th><td><input type="url" name="public_url_firewalls" id="public_url_firewalls" value="' . esc_attr( $public_urls['firewalls'] ?? '' ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="public_url_certs">Certs URL</label></th><td><input type="url" name="public_url_certs" id="public_url_certs" value="' . esc_attr( $public_urls['certs'] ?? '' ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="public_url_domains">Domains URL</label></th><td><input type="url" name="public_url_domains" id="public_url_domains" value="' . esc_attr( $public_urls['domains'] ?? '' ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="public_url_servers">Servers URL</label></th><td><input type="url" name="public_url_servers" id="public_url_servers" value="' . esc_attr( $public_urls['servers'] ?? '' ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="public_url_customers">Customers URL</label></th><td><input type="url" name="public_url_customers" id="public_url_customers" value="' . esc_attr( $public_urls['customers'] ?? '' ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="public_url_trash">Trash URL</label></th><td><input type="url" name="public_url_trash" id="public_url_trash" value="' . esc_attr( $public_urls['trash'] ?? '' ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="public_url_logs">Logs URL</label></th><td><input type="url" name="public_url_logs" id="public_url_logs" value="' . esc_attr( $public_urls['logs'] ?? '' ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="public_url_settings">Settings URL</label></th><td><input type="url" name="public_url_settings" id="public_url_settings" value="' . esc_attr( $public_urls['settings'] ?? '' ) . '" class="regular-text" /></td></tr>';
        echo '</table>';

        echo '<p><button type="submit" name="expman_save_settings" class="button button-primary">Save Settings</button></p>';
        echo '</form>';

        echo '<hr style="margin:24px 0;">';
        echo '<h3>העברת נתונים בין סביבות</h3>';
        echo '<p>ניתן לייצא את הנתונים מה-DB ולהעלות אותם בסביבה אחרת.</p>';
        echo '<form method="post" style="margin-bottom:12px;">';
        wp_nonce_field( 'expman_transfer_data' );
        echo '<input type="hidden" name="expman_export_data" value="1">';
        echo '<button type="submit" class="button">ייצוא נתונים (JSON)</button>';
        echo '</form>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field( 'expman_transfer_data' );
        echo '<input type="file" name="expman_data_file" accept=".json" required>';
        echo '<button type="submit" name="expman_import_data" class="button button-primary">ייבוא נתונים (JSON)</button>';
        echo '</form>';
    }

    public function render_page() {
        $settings = get_option( $this->option_key, array() );

        if ( isset( $_POST['expman_export_data'] ) && check_admin_referer( 'expman_transfer_data' ) ) {
            $this->export_plugin_data();
        }
        if ( isset( $_POST['expman_import_data'] ) && check_admin_referer( 'expman_transfer_data' ) ) {
            $import_error = $this->import_plugin_data();
            if ( $import_error === '' ) {
                echo '<div class="notice notice-success"><p>הנתונים יובאו בהצלחה.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html( $import_error ) . '</p></div>';
            }
        }

        if ( $this->maybe_save_settings( $settings ) ) {
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $public_urls = $settings['public_urls'] ?? array();
        $env         = $settings['env'] ?? 'prod';

        echo '<div class="wrap"><h1>Settings</h1>';
        if ( class_exists('Expman_Nav') ) { Expman_Nav::render_admin_nav( '0.2.0' ); }


        $this->render_settings_form( $settings, $public_urls, $env );
        echo '</div>';
    }

    public function render_public_page() {
        $settings = get_option( $this->option_key, array() );
        $saved = $this->maybe_save_settings( $settings );
        if ( isset( $_POST['expman_export_data'] ) && check_admin_referer( 'expman_transfer_data' ) ) {
            $this->export_plugin_data();
        }
        if ( isset( $_POST['expman_import_data'] ) && check_admin_referer( 'expman_transfer_data' ) ) {
            $import_error = $this->import_plugin_data();
            if ( $import_error === '' ) {
                echo '<div class="notice notice-success"><p>הנתונים יובאו בהצלחה.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html( $import_error ) . '</p></div>';
            }
        }

        $public_urls = $settings['public_urls'] ?? array();
        $env         = $settings['env'] ?? 'prod';

        echo '<div class="expman-frontend expman-settings" style="direction:rtl;">';
        if ( class_exists( 'Expman_Nav' ) ) { Expman_Nav::render_public_nav( $this->option_key ); }
        echo '<h2>הגדרות</h2>';

        if ( $saved ) {
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $this->render_settings_form( $settings, $public_urls, $env );
        echo '</div>';
    }
}
