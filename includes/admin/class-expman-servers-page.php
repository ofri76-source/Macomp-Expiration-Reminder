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

    const TABLE_SERVERS = 'exp_dell_servers';
    const TABLE_SERVER_TRACKING = 'exp_dell_server_tracking';
    const TABLE_SERVER_LOGS = 'exp_dell_server_logs';
    const TABLE_SERVER_IMPORT_STAGE = 'exp_dell_servers_import_stage';

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

        $this->logger = new Expman_Servers_Logger();
        $this->importer = new Expman_Servers_Importer( $this->logger, $this->option_key );
        $this->actions = new Expman_Servers_Actions( $this->logger );
        $this->ui = new Expman_Servers_UI( $this );

        $this->schema = new Expman_Servers_Schema();
        $this->dell = new Expman_Servers_Dell( $this->logger, $this->option_key, array( $this, 'add_notice' ) );
        $this->actions->set_dell( $this->dell );
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

    public function get_dell_settings() {
        return $this->dell ? $this->dell->get_settings() : array();
    }

    public static function install_tables() {
        Expman_Servers_Schema::install_tables();
    }

    public static function render_public_page( $option_key, $version = '' ) {
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
        if ( ! current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-error"><p>אין הרשאה.</p></div>';
            return;
        }

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

    private function handle_actions() {
        if ( empty( $_POST['expman_action'] ) ) {
            return;
        }
        if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'expman_servers' ) ) {
            return;
        }

        $action = sanitize_key( $_POST['expman_action'] );
        $redirect_tab = sanitize_key( $_POST['tab'] ?? '' );

        switch ( $action ) {
            case 'save_server':
                $this->actions->action_save_server();
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
        }

        $redirect_url = remove_query_arg( array( 'expman_msg' ) );
        if ( $redirect_tab !== '' ) {
            $redirect_url = add_query_arg( 'tab', $redirect_tab, $redirect_url );
        }

        if ( headers_sent() ) {
            $this->add_notice( 'הפעולה בוצעה, אך לא ניתן לבצע הפניה אוטומטית (headers כבר נשלחו). לחץ לרענון העמוד.', 'warning' );
            echo '<div class="notice notice-warning"><p><a href="' . esc_url( $redirect_url ) . '">לחץ כאן לחזרה לעמוד</a></p></div>';
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
