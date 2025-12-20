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

    private function render_settings_form( $settings, $public_urls, $env ) {
        echo '<form method="post">';
        wp_nonce_field( 'expman_save_settings' );

        echo '<h3>Environment</h3>';
        echo '<table class="form-table"><tr><th><label for="env_test">Server is TEST</label></th><td>';
        echo '<label><input type="checkbox" name="env_test" id="env_test" value="1" ' . checked( $env, 'test', false ) . ' /> Use TEST URLs</label>';
        echo '</td></tr></table>';

        echo '<h3>Thresholds</h3>';
        echo '<table class="form-table">';
        echo '<tr><th><label for="yellow_threshold">Yellow threshold (days)</label></th><td><input type="number" name="yellow_threshold" id="yellow_threshold" value="' . esc_attr( $settings['yellow_threshold'] ?? 60 ) . '" /></td></tr>';
        echo '<tr><th><label for="red_threshold">Red threshold (days)</label></th><td><input type="number" name="red_threshold" id="red_threshold" value="' . esc_attr( $settings['red_threshold'] ?? 30 ) . '" /></td></tr>';
        echo '<tr><th><label for="log_retention_days">Log retention (days)</label></th><td><input type="number" name="log_retention_days" id="log_retention_days" value="' . esc_attr( $settings['log_retention_days'] ?? 90 ) . '" /></td></tr>';
        echo '<tr><th><label for="customers_table">Customers table name</label></th><td><input type="text" name="customers_table" id="customers_table" value="' . esc_attr( $settings['customers_table'] ?? '' ) . '" class="regular-text" /></td></tr>';
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
    }

    public function render_page() {
        $settings = get_option( $this->option_key, array() );

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
