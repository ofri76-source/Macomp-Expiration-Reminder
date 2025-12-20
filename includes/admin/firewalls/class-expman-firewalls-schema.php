<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Firewalls_Schema' ) ) {
class Expman_Firewalls_Schema {

    private function column_exists( $table, $column ) {
        global $wpdb;
        $col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
        return ! empty( $col );
    }

    /**
     * Convenience wrapper for checking a column on the firewalls table.
     */
    private function fw_column_exists( $column ) {
        global $wpdb;
        $fw_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        return $this->column_exists( $fw_table, $column );
    }

    public function ensure_schema() {
        global $wpdb;
        $fw_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;

        // Ensure base tables exist
        self::install_tables();

        // Add missing columns on existing installs
        $wanted = array(
            'customer_number' => "ALTER TABLE {$fw_table} ADD COLUMN customer_number VARCHAR(6) NULL",
            'customer_name'   => "ALTER TABLE {$fw_table} ADD COLUMN customer_name VARCHAR(255) NULL",
            'track_only'      => "ALTER TABLE {$fw_table} ADD COLUMN track_only TINYINT(1) NOT NULL DEFAULT 0",
            'access_url'      => "ALTER TABLE {$fw_table} ADD COLUMN access_url VARCHAR(2048) NULL",
            'temp_notice_enabled' => "ALTER TABLE {$fw_table} ADD COLUMN temp_notice_enabled TINYINT(1) NOT NULL DEFAULT 0",
            'temp_notice'     => "ALTER TABLE {$fw_table} ADD COLUMN temp_notice TEXT NULL",
            'archived_at'     => "ALTER TABLE {$fw_table} ADD COLUMN archived_at DATETIME NULL",
            'deleted_at'      => "ALTER TABLE {$fw_table} ADD COLUMN deleted_at DATETIME NULL",
        );

        foreach ( $wanted as $col => $sql ) {
            if ( ! $this->column_exists( $fw_table, $col ) ) {
                $wpdb->query( $sql );
            }
        }
    }

    private static function maybe_add_column( $table, $definition, $column_name ) {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column_name ) );
        if ( empty( $exists ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$definition}" );
        }
    }

    public static function install_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $fw_table    = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $types_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_TYPES;
        $assets_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FORTICLOUD_ASSETS;
        $logs_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALL_LOGS;
        $stage_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALL_IMPORT_STAGE;

        $sql_fw = "CREATE TABLE {$fw_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT(20) NULL,
            customer_number VARCHAR(6) NULL,
            customer_name VARCHAR(255) NULL,
            branch VARCHAR(255) NULL,
            serial_number VARCHAR(255) NOT NULL,
            is_managed TINYINT(1) NOT NULL DEFAULT 1,
            track_only TINYINT(1) NOT NULL DEFAULT 0,
            box_type_id BIGINT(20) NULL,
            expiry_date DATE NULL,
            access_url VARCHAR(255) NULL,
            notes TEXT NULL,
            temp_notice_enabled TINYINT(1) NOT NULL DEFAULT 0,
            temp_notice TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            archived_at DATETIME NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY expiry_date (expiry_date),
            KEY serial_number (serial_number),
            KEY is_managed (is_managed),
            KEY track_only (track_only)
        ) {$charset_collate};";

        $sql_types = "CREATE TABLE {$types_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor VARCHAR(255) NOT NULL,
            model VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY unique_vendor_model (vendor, model),
            PRIMARY KEY (id)
        ) {$charset_collate};";

        dbDelta( $sql_fw );

        // Migrations for new columns
        self::maybe_add_column( $fw_table, "customer_number VARCHAR(6) NULL", "customer_number" );
        self::maybe_add_column( $fw_table, "customer_name VARCHAR(255) NULL", "customer_name" );

        $sql_assets = "CREATE TABLE {$assets_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            forticloud_id VARCHAR(255) NULL,
            serial_number VARCHAR(255) NOT NULL,
            category_name VARCHAR(255) NULL,
            model_name VARCHAR(255) NULL,
            registration_date DATE NULL,
            ship_date DATE NULL,
            expiration_date DATE NULL,
            description TEXT NULL,
            folder_id VARCHAR(255) NULL,
            asset_groups TEXT NULL,
            raw_json LONGTEXT NULL,
            customer_id BIGINT(20) NULL,
            customer_number_snapshot VARCHAR(64) NULL,
            customer_name_snapshot VARCHAR(255) NULL,
            mapped_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY serial_number (serial_number),
            KEY customer_id (customer_id)
        ) {$charset_collate};";

        $sql_logs = "CREATE TABLE {$logs_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            firewall_id BIGINT(20) NULL,
            action VARCHAR(50) NOT NULL,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message TEXT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY firewall_id (firewall_id),
            KEY action (action),
            KEY level (level)
        ) {$charset_collate};";

        $sql_stage = "CREATE TABLE {$stage_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            import_batch_id VARCHAR(64) NOT NULL,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            imported_by_user_id BIGINT(20) NULL,
            assigned_at DATETIME NULL,
            assigned_by_user_id BIGINT(20) NULL,
            firewall_id BIGINT(20) NULL,
            serial_number VARCHAR(255) NOT NULL,
            customer_id BIGINT(20) NULL,
            customer_number VARCHAR(6) NULL,
            customer_name VARCHAR(255) NULL,
            branch VARCHAR(255) NULL,
            is_managed TINYINT(1) NOT NULL DEFAULT 1,
            track_only TINYINT(1) NOT NULL DEFAULT 0,
            vendor VARCHAR(255) NULL,
            model VARCHAR(255) NULL,
            box_type_id BIGINT(20) NULL,
            expiry_date DATE NULL,
            access_url VARCHAR(2048) NULL,
            notes TEXT NULL,
            temp_notice_enabled TINYINT(1) NOT NULL DEFAULT 0,
            temp_notice TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            archived_at DATETIME NULL,
            deleted_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            last_error TEXT NULL,
            last_error_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY import_batch_id (import_batch_id),
            KEY status (status),
            KEY serial_number (serial_number)
        ) {$charset_collate};";

        dbDelta( $sql_assets );
        dbDelta( $sql_types );
        dbDelta( $sql_logs );
        dbDelta( $sql_stage );
    }
}
}
