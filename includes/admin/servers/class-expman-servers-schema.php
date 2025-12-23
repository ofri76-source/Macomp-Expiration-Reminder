<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Servers_Schema' ) ) {
class Expman_Servers_Schema {

    public function ensure_schema() {
        self::install_tables();
    }

    public static function install_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
        $tracking_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_TRACKING;
        $logs_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_LOGS;
        $stage_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_IMPORT_STAGE;

        $sql_servers = "CREATE TABLE {$servers_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            option_key VARCHAR(64) NOT NULL,
            customer_id BIGINT(20) NULL,
            customer_number_snapshot VARCHAR(64) NULL,
            customer_name_snapshot VARCHAR(255) NULL,
            service_tag VARCHAR(32) NOT NULL,
            express_service_code VARCHAR(64) NULL,
            ship_date DATE NULL,
            ending_on DATE NULL,
            last_renewal_date DATE NULL,
            operating_system VARCHAR(255) NULL,
            service_level VARCHAR(255) NULL,
            server_model VARCHAR(255) NULL,
            temp_notice_enabled TINYINT(1) NOT NULL DEFAULT 0,
            temp_notice_text TEXT NULL,
            notes TEXT NULL,
            raw_json LONGTEXT NULL,
            last_sync_at DATETIME NULL,
            deleted_at DATETIME NULL,
            deleted_by BIGINT(20) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY service_tag (service_tag),
            KEY option_key (option_key),
            KEY customer_id (customer_id),
            KEY deleted_at (deleted_at),
            KEY ending_on (ending_on)
        ) {$charset_collate};";

        $sql_tracking = "CREATE TABLE {$tracking_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            server_id BIGINT(20) NULL,
            action VARCHAR(50) NOT NULL,
            before_json LONGTEXT NULL,
            after_json LONGTEXT NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY server_id (server_id),
            KEY action (action)
        ) {$charset_collate};";

        $sql_logs = "CREATE TABLE {$logs_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            server_id BIGINT(20) NULL,
            action VARCHAR(50) NOT NULL,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message TEXT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY server_id (server_id),
            KEY action (action),
            KEY level (level)
        ) {$charset_collate};";

        $sql_stage = "CREATE TABLE {$stage_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            option_key VARCHAR(64) NOT NULL,
            customer_number VARCHAR(64) NULL,
            customer_name VARCHAR(255) NULL,
            service_tag VARCHAR(32) NOT NULL,
            last_renewal_date DATE NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY option_service_tag (option_key, service_tag)
        ) {$charset_collate};";

        dbDelta( $sql_servers );
        dbDelta( $sql_tracking );
        dbDelta( $sql_logs );
        dbDelta( $sql_stage );
    }
}
}
