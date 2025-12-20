<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/firewalls/class-expman-firewalls-logger.php';
require_once __DIR__ . '/firewalls/class-expman-firewalls-importer.php';
require_once __DIR__ . '/firewalls/class-expman-firewalls-actions.php';
require_once __DIR__ . '/firewalls/class-expman-firewalls-ui.php';
require_once __DIR__ . '/firewalls/class-expman-firewalls-schema.php';
require_once __DIR__ . '/firewalls/class-expman-firewalls-forticloud.php';

if ( ! class_exists( 'Expman_Firewalls_Page' ) ) {
class Expman_Firewalls_Page {

    const TABLE_FIREWALLS = 'exp_firewalls';
    const TABLE_TYPES     = 'exp_firewall_box_types';
    const TABLE_FORTICLOUD_ASSETS = 'exp_forticloud_assets';
    const TABLE_FIREWALL_LOGS = 'exp_firewall_logs';
    const TABLE_FIREWALL_IMPORT_STAGE = 'exp_firewalls_import_stage';

    private $option_key;
    private $version;
    private $notices = array();
    private $logger;
    private $importer;
    private $actions;
    private $ui;
    private $schema;
    private $forticloud;

    public function __construct( $option_key, $version = '20.15.40' ) {
        $this->option_key = $option_key;
        $this->version = $version;

        $this->logger   = new Expman_Firewalls_Logger();
        $this->importer = new Expman_Firewalls_Importer( $this->logger );
        $this->actions  = new Expman_Firewalls_Actions( $this->logger );
        $this->ui       = new Expman_Firewalls_UI( $this );

        $this->schema = new Expman_Firewalls_Schema();
        $this->forticloud = new Expman_Firewalls_Forticloud( $this->logger, $this->option_key, array( $this, 'add_notice' ) );
        $this->actions->set_forticloud( $this->forticloud );
        $this->actions->set_notifier( array( $this, 'add_notice' ) );
        $this->actions->set_option_key( $this->option_key );
    }

    public function get_option_key() {
        return $this->option_key;
    }

    public function get_version() {
        return $this->version;
    }

    public function get_notices() {
        return $this->notices;
    }

    public function get_logger() {
        return $this->logger;
    }

    public function get_actions() {
        return $this->actions;
    }

    public function get_forticloud() {
        return $this->forticloud;
    }

    public static function install_tables() {
        Expman_Firewalls_Schema::install_tables();
    }

    public static function render_public_page( $option_key, $version = '' ) {
        // Internal site, but still require login (per earlier requirement)
        if ( ! is_user_logged_in() ) {
            echo '<div class="notice notice-error"><p>אין הרשאה. יש להתחבר.</p></div>';
            return;
        }

        self::install_if_missing();
        $self = new self( (string) $option_key, (string) $version );
        $self->schema->ensure_schema();
        $self->handle_actions();
        $self->render();
    }

    public static function render_summary_cards_public( $option_key, $title = '' ) {
        Expman_Firewalls_UI::render_summary_cards_public( $option_key, $title );
    }

    private static function install_if_missing() {
        global $wpdb;
        $fw_table = $wpdb->prefix . self::TABLE_FIREWALLS;
        $exists   = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $fw_table ) );
        if ( $exists !== $fw_table ) {
            self::install_tables();
            return;
        }

        $assets_table = $wpdb->prefix . self::TABLE_FORTICLOUD_ASSETS;
        $assets_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $assets_table ) );
        if ( $assets_exists !== $assets_table ) {
            self::install_tables();
        }

        $logs_table = $wpdb->prefix . self::TABLE_FIREWALL_LOGS;
        $logs_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $logs_table ) );
        if ( $logs_exists !== $logs_table ) {
            self::install_tables();
        }

        $stage_table = $wpdb->prefix . self::TABLE_FIREWALL_IMPORT_STAGE;
        $stage_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $stage_table ) );
        if ( $stage_exists !== $stage_table ) {
            self::install_tables();
        }

        // Ensure new columns exist (track_only)
        $cols = $wpdb->get_results( "SHOW COLUMNS FROM {$fw_table}" );
        $names = array();
        foreach ( (array) $cols as $c ) { $names[] = $c->Field; }
        if ( ! in_array( 'track_only', $names, true ) ) {
            $wpdb->query( "ALTER TABLE {$fw_table} ADD COLUMN track_only TINYINT(1) NOT NULL DEFAULT 0 AFTER is_managed" );
        }
        if ( ! in_array( 'archived_at', $names, true ) ) {
            $wpdb->query( "ALTER TABLE {$fw_table} ADD COLUMN archived_at DATETIME NULL AFTER updated_at" );
        }
    }

    public function add_notice( $message, $type = 'success' ) {
        $this->notices[] = array(
            'type' => $type,
            'text' => $message,
        );
    }

    private function handle_actions() {
        if ( empty( $_POST['expman_action'] ) ) {
            return;
        }
        if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'expman_firewalls' ) ) {
            return;
        }

        $action = sanitize_key( $_POST['expman_action'] );

        if ( $action === 'export_csv' ) {
            $this->action_export_csv();
            exit;
        }

        $redirect_tab = sanitize_key( $_POST['tab'] ?? '' );

        switch ( $action ) {
            case 'save_firewall':
                $this->action_save_firewall();
                break;
            case 'save_forticloud_settings':
                $this->action_save_forticloud_settings();
                break;
            case 'sync_forticloud_assets':
                $this->action_sync_forticloud_assets();
                break;
            case 'map_forticloud_asset':
                $this->action_map_forticloud_asset();
                break;
            case 'trash_firewall':
                $this->action_trash_firewall();
                break;
            case 'restore_firewall':
                $this->action_restore_firewall();
                break;
            case 'archive_firewall':
                $this->action_archive_firewall();
                break;
            case 'restore_archive':
                $this->action_restore_archive();
                break;
            case 'import_csv':
                $this->action_import_csv();
                $redirect_tab = 'bulk';
                break;
            case 'save_box_types':
                $this->action_save_box_types();
                break;
            case 'assign_import_stage':
                $this->action_assign_import_stage();
                $redirect_tab = 'assign';
                break;
        }

        $redirect_url = remove_query_arg( array( 'expman_msg' ) );
        if ( $redirect_tab !== '' ) {
            $redirect_url = add_query_arg( 'tab', $redirect_tab, $redirect_url );
        }
        if ( $redirect_tab === 'assign' && ! empty( $_POST['batch'] ) ) {
            $redirect_url = add_query_arg( 'batch', sanitize_text_field( $_POST['batch'] ), $redirect_url );
        }
        wp_safe_redirect( $redirect_url );
        exit;
    }

    private function action_save_firewall() {
        $this->actions->action_save_firewall();
    }

    private function action_save_forticloud_settings() {
        if ( $this->forticloud ) {
            $this->forticloud->action_save_forticloud_settings();
        }
    }

    private function action_sync_forticloud_assets() {
        if ( $this->forticloud ) {
            $this->forticloud->action_sync_forticloud_assets();
        }
    }

    private function action_map_forticloud_asset() {
        $this->actions->action_map_forticloud_asset();
    }

    private function action_trash_firewall() {
        $this->actions->action_trash_firewall();
    }

    private function action_restore_firewall() {
        $this->actions->action_restore_firewall();
    }

    private function action_archive_firewall() {
        $this->actions->action_archive_firewall();
    }

    private function action_restore_archive() {
        $this->actions->action_restore_archive();
    }

    private function action_export_csv() {
        $this->actions->action_export_csv();
    }

    private function action_import_csv() {
        return $this->importer->run();
    }

    private function action_save_box_types() {
        $this->actions->action_save_box_types();
    }

    private function action_assign_import_stage() {
        $this->actions->action_assign_import_stage();
    }

    private function render() {
        $this->ui->render();
    }
}
}
