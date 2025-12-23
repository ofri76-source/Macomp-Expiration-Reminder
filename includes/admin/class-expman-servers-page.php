<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/servers/class-expman-servers-logger.php';
require_once __DIR__ . '/servers/class-expman-servers-importer.php';
require_once __DIR__ . '/servers/class-expman-servers-actions.php';
require_once __DIR__ . '/servers/class-expman-servers-ui.php';
require_once __DIR__ . '/servers/class-expman-servers-schema.php';
require_once __DIR__ . '/servers/class-expman-servers-dell.php';

if ( ! class_exists( 'Expman_Servers_Page' ) ) {
class Expman_Servers_Page {

    const TABLE_SERVERS            = 'exp_dell_servers';
    const TABLE_SERVER_TRACKING    = 'exp_dell_server_tracking';
    const TABLE_SERVER_LOGS        = 'exp_dell_server_logs';
    const TABLE_SERVER_IMPORT_STAGE= 'exp_dell_servers_import_stage';

    private $option_key;
    private $version;
    private $notices = array();
    private $logger;
    private $importer;
    private $actions;
    private $ui;
    private $schema;
    private $dell;

    public function __construct( $option_key, $version = '21.9.49' ) {
        $this->option_key = $option_key;
        $this->version = $version;

        $this->logger   = new Expman_Servers_Logger();
        $this->importer = new Expman_Servers_Importer( $this->logger, $this->option_key );
        $this->actions  = new Expman_Servers_Actions( $this->logger );
        $this->ui       = new Expman_Servers_UI( $this );

        $this->schema = new Expman_Servers_Schema();
        $this->dell   = new Expman_Servers_Dell( $this->logger, $this->option_key, array( $this, 'add_notice' ) );

        $this->actions->set_dell( $this->dell );
        $this->actions->set_notifier( array( $this, 'add_notice' ) );
        $this->actions->set_option_key( $this->option_key );
    }

    public function get_option_key() { return $this->option_key; }
    public function get_version() { return $this->version; }
    public function get_notices() { return $this->notices; }
    public function get_logger() { return $this->logger; }
    public function get_actions() { return $this->actions; }
    public function get_dell_settings() { return $this->dell ? $this->dell->get_settings() : array(); }
    public function get_dell() { return $this->dell; }

    public static function install_tables() {
        Expman_Servers_Schema::install_tables();
    }

    public static function render_public_page( $option_key, $version = '' ) {
        self::install_if_missing();
        $self = new self( (string) $option_key, (string) $version );
        $self->schema->ensure_schema();
        $self->handle_actions();
        $self->render();
    }

    private static function install_if_missing() {
        global $wpdb;
        $servers_table = $wpdb->prefix . self::TABLE_SERVERS;
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $servers_table ) );
        if ( $exists !== $servers_table ) {
            self::install_tables();
            return;
        }

        $logs_table = $wpdb->prefix . self::TABLE_SERVER_LOGS;
        $logs_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $logs_table ) );
        if ( $logs_exists !== $logs_table ) {
            self::install_tables();
        }
    }

    public function render_page() {
        $this->schema->ensure_schema();
        $this->handle_actions();
        $this->render();
    }

    public function add_notice( $message, $type = 'success' ) {
        $this->notices[] = array(
            'type' => $type,
            'text' => $message,
        );
    }

    private function resolve_action_from_request() {
        $action = '';
        if ( ! empty( $_POST['expman_action'] ) ) {
            $action = sanitize_key( wp_unslash( $_POST['expman_action'] ) );
        } elseif ( ! empty( $_POST['action'] ) ) {
            $action = sanitize_key( wp_unslash( $_POST['action'] ) );
        } elseif ( ! empty( $_REQUEST['action'] ) ) {
            $action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
        }

        // Backward-compat aliases
        $aliases = array(
            'expman_save_server'          => 'save_server',
            'expman_sync_bulk'            => 'sync_bulk',
            'expman_sync_single'          => 'sync_single',
            'expman_trash_server'         => 'trash_server',
            'expman_restore_server'       => 'restore_server',
            'expman_delete_server'        => 'delete_server_permanently',
            'expman_delete_server_permanently' => 'delete_server_permanently',
        );
        if ( isset( $aliases[ $action ] ) ) {
            $action = $aliases[ $action ];
        }

        return $action;
    }

    private function handle_actions() {
        if ( empty( $_POST ) ) {
            return;
        }

        $action = $this->resolve_action_from_request();
        if ( $action === '' ) {
            return;
        }

        $redirect_tab = sanitize_key( $_POST['tab'] ?? '' );

        $nonce_map = array(
            'save_server'                => array( 'expman_save_server', 'expman_save_server_nonce' ),
            'sync_bulk'                  => array( 'expman_sync_servers_bulk', 'expman_sync_servers_bulk_nonce' ),
            'sync_single'                => array( 'expman_servers_row_action', 'expman_servers_row_action_nonce' ),
            'trash_server'               => array( 'expman_servers_row_action', 'expman_servers_row_action_nonce' ),
            'restore_server'             => array( 'expman_restore_server', 'expman_restore_server_nonce' ),
            'delete_server_permanently'  => array( 'expman_delete_server_permanently', 'expman_delete_server_permanently_nonce' ),
            'empty_trash'                => array( 'expman_empty_servers_trash', 'expman_empty_servers_trash_nonce' ),
            'import_csv'                 => array( 'expman_import_servers_csv', 'expman_import_servers_csv_nonce' ),
            'import_excel_settings'      => array( 'expman_import_servers_excel', 'expman_import_servers_excel_nonce' ),
            'assign_import_stage'        => array( 'expman_assign_servers_stage', 'expman_assign_servers_stage_nonce' ),
            'delete_import_stage'        => array( 'expman_delete_servers_stage', 'expman_delete_servers_stage_nonce' ),
            'save_dell_settings'         => array( 'expman_save_dell_settings', 'expman_save_dell_settings_nonce' ),
            'export_servers_csv'         => array( 'expman_export_servers_csv', 'expman_export_servers_csv_nonce' ),
        );

        if ( isset( $nonce_map[ $action ] ) ) {
            list( $nonce_action, $nonce_field ) = $nonce_map[ $action ];
            check_admin_referer( $nonce_action, $nonce_field );
        }

        switch ( $action ) {
            case 'save_server':
                $this->actions->save_server_from_request();
                $redirect_tab = $redirect_tab ?: 'main';
                break;

            case 'trash_server':
                $this->actions->action_trash_server();
                $redirect_tab = 'main';
                break;

            case 'restore_server':
                $this->actions->action_restore_server();
                $redirect_tab = 'trash';
                break;

            case 'delete_server_permanently':
                $this->actions->action_delete_server_permanently();
                $redirect_tab = 'trash';
                break;

            case 'empty_trash':
                $this->actions->action_empty_trash();
                $redirect_tab = 'trash';
                break;

            case 'sync_single':
                $this->actions->action_sync_server();
                $redirect_tab = 'main';
                break;

            case 'sync_bulk':
                $this->actions->action_sync_bulk();
                $redirect_tab = 'main';
                break;

            case 'import_csv':
                $this->importer->run();
                $redirect_tab = 'assign';
                break;

            case 'import_excel_settings':
                $this->actions->action_import_excel_settings();
                $redirect_tab = 'settings';
                break;

            case 'assign_import_stage':
                $this->actions->action_assign_import_stage();
                $redirect_tab = 'assign';
                break;

            case 'delete_import_stage':
                $this->actions->action_delete_import_stage();
                $redirect_tab = 'assign';
                break;

            case 'save_dell_settings':
                if ( $this->dell ) {
                    $this->dell->action_save_dell_settings();
                }
                $redirect_tab = 'settings';
                break;

            case 'export_servers_csv':
                $this->actions->action_export_csv();
                return;
        }

        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            $redirect_url = remove_query_arg( array( 'expman_msg' ) );
        } else {
            $redirect_url = remove_query_arg( array( 'expman_msg' ), $redirect_url );
        }

        if ( $redirect_tab !== '' ) {
            $redirect_url = add_query_arg( 'tab', $redirect_tab, $redirect_url );
        }

        if ( headers_sent() ) {
            $this->add_notice( 'הפעולה בוצעה, אך לא ניתן לבצע הפניה אוטומטית (headers כבר נשלחו).', 'warning' );
            return;
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    private function render() {
        $this->ui->render();
    }
}
}
