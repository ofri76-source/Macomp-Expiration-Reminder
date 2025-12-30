<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Domains_Manager' ) ) {
class Expman_Domains_Manager {

    private const TABLE = 'kb_kb_domain_expiry';

    private const CRON_HOOK_0100 = 'expman_domains_whois_scan_0100';
    private const CRON_HOOK_1300 = 'expman_domains_whois_scan_1300';

    private function get_thresholds(): array {
        $settings = get_option( Expiry_Manager_Plugin::OPTION_KEY, array() );
        return array(
            'yellow' => intval( $settings['yellow_threshold'] ?? 90 ),
            'red'    => intval( $settings['red_threshold'] ?? 30 ),
        );
    }

    private function normalize_ui_date_to_db( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) { return ''; }

        $formats = array( 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'Y-m-d H:i:s' );
        foreach ( $formats as $fmt ) {
            $dt = DateTime::createFromFormat( $fmt, $value );
            if ( $dt instanceof DateTime ) {
                return $dt->format( 'Y-m-d' );
            }
        }
        return '';
    }

    private function format_db_date_to_ui( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) { return ''; }

        $dt = DateTime::createFromFormat( 'Y-m-d', $value );
        if ( $dt instanceof DateTime ) { return $dt->format( 'd/m/Y' ); }

        $dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $value );
        if ( $dt instanceof DateTime ) { return $dt->format( 'd/m/Y' ); }

        $ts = strtotime( $value );
        if ( ! $ts ) { return $value; }
        return date_i18n( 'd/m/Y', $ts );
    }

    public function __construct() {
        static $hooks_registered = false;
        if ( $hooks_registered ) { return; }
        $hooks_registered = true;

        // UI / data
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'admin_post_expman_save_domain', array( $this, 'handle_save' ) );
        add_action( 'wp_ajax_expman_domains_fetch', array( $this, 'handle_fetch' ) );
        add_action( 'admin_post_expman_domain_trash', array( $this, 'handle_trash' ) );
        add_action( 'admin_post_expman_domain_restore', array( $this, 'handle_restore' ) );
        add_action( 'admin_post_expman_domain_delete', array( $this, 'handle_delete' ) );

        // Whois scanning
        add_action( self::CRON_HOOK_0100, array( $this, 'cron_scan' ) );
        add_action( self::CRON_HOOK_1300, array( $this, 'cron_scan' ) );
        add_action( 'admin_post_expman_domains_check_all', array( $this, 'handle_check_all' ) );
        add_action( 'admin_post_expman_domain_check_now', array( $this, 'handle_check_one' ) );
    }

    /* -------------------- CRON SCHEDULING -------------------- */

    private static function next_run_timestamp( int $hour, int $minute ): int {
        $tz = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );
        $target = $now->setTime( $hour, $minute, 0 );
        if ( $target <= $now ) {
            $target = $target->modify( '+1 day' );
        }
        return $target->getTimestamp();
    }

    public static function activate_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK_0100 ) ) {
            wp_schedule_event( self::next_run_timestamp( 1, 0 ), 'daily', self::CRON_HOOK_0100 );
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK_1300 ) ) {
            wp_schedule_event( self::next_run_timestamp( 13, 0 ), 'daily', self::CRON_HOOK_1300 );
        }
    }

    public static function deactivate_cron(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK_0100 );
        wp_clear_scheduled_hook( self::CRON_HOOK_1300 );
    }

    public function cron_scan(): void {
        $this->scan_all_domains( 0, false );
    }

    /* -------------------- SCHEMA (MIGRATION SAFE) -------------------- */

    private function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    private function ensure_schema(): void {
        global $wpdb;
        $table = $this->get_table_name();

        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( $exists !== $table ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE `$table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `client_name` VARCHAR(191) NOT NULL,
                `customer_number` VARCHAR(64) NULL,
                `customer_name` VARCHAR(255) NULL,
                `domain` VARCHAR(191) NOT NULL,
                `expiry_date` DATETIME NULL,
                `days_left` INT NULL,
                `manual_expiry` TINYINT(1) NOT NULL DEFAULT 0,
                `free_text_domain` TINYINT(1) NOT NULL DEFAULT 0,
                `archived` TINYINT(1) NOT NULL DEFAULT 0,
                `temp_text_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `temp_text` TEXT NULL,
                `registrar` VARCHAR(191) NULL,
                `ownership` ENUM('ours','not_ours') NOT NULL DEFAULT 'ours',
                `payment` ENUM('ours','customer') NOT NULL DEFAULT 'ours',
                `notes` TEXT NULL,
                `last_whois_checked_at` DATETIME NULL,
                `last_whois_server` VARCHAR(191) NULL,
                `last_whois_error` TEXT NULL,
                `deleted_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_domain` (`domain`),
                KEY `idx_deleted` (`deleted_at`),
                KEY `idx_days_left` (`days_left`),
                KEY `idx_archived` (`archived`),
                PRIMARY KEY (`id`)
            ) $charset;";

            dbDelta( $sql );
            update_option( 'expman_domains_migration_v1_done', 1 );
            return;
        }

        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 );
        $cols = array_map( 'strtolower', (array) $cols );

        $add_col = function( string $name, string $ddl ) use ( $wpdb, $table, $cols ): void {
            if ( ! in_array( strtolower( $name ), $cols, true ) ) {
                $wpdb->query( "ALTER TABLE `$table` ADD COLUMN $ddl" );
            }
        };

        $add_col( 'customer_number', "`customer_number` VARCHAR(64) NULL AFTER `client_name`" );
        $add_col( 'customer_name', "`customer_name` VARCHAR(255) NULL AFTER `customer_number`" );
        $add_col( 'manual_expiry', "`manual_expiry` TINYINT(1) NOT NULL DEFAULT 0 AFTER `days_left`" );
        $add_col( 'free_text_domain', "`free_text_domain` TINYINT(1) NOT NULL DEFAULT 0 AFTER `manual_expiry`" );
        $add_col( 'archived', "`archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `free_text_domain`" );
        $add_col( 'temp_text_enabled', "`temp_text_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `archived`" );
        $add_col( 'temp_text', "`temp_text` TEXT NULL AFTER `temp_text_enabled`" );
        $add_col( 'registrar', "`registrar` VARCHAR(191) NULL AFTER `temp_text`" );
        $add_col( 'ownership', "`ownership` VARCHAR(32) NULL AFTER `registrar`" );
        $add_col( 'payment', "`payment` VARCHAR(32) NULL AFTER `ownership`" );
        $add_col( 'notes', "`notes` TEXT NULL AFTER `payment`" );

        // Whois status
        $add_col( 'last_whois_checked_at', "`last_whois_checked_at` DATETIME NULL AFTER `notes`" );
        $add_col( 'last_whois_server', "`last_whois_server` VARCHAR(191) NULL AFTER `last_whois_checked_at`" );
        $add_col( 'last_whois_error', "`last_whois_error` TEXT NULL AFTER `last_whois_server`" );

        // One-time backfill for legacy installs
        if ( ! get_option( 'expman_domains_migration_v1_done' ) ) {
            $wpdb->query(
                "UPDATE `$table`
                 SET customer_name = client_name
                 WHERE (customer_name IS NULL OR customer_name = '')
                   AND (customer_number IS NULL OR customer_number = '')
                   AND (client_name IS NOT NULL AND client_name <> '')"
            );
            update_option( 'expman_domains_migration_v1_done', 1 );
        }
    }

    private function require_manage_options(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
    }

    /* -------------------- UI RENDERING -------------------- */

    public function admin_assets( $hook ): void {
        if ( empty( $_GET['page'] ) || $_GET['page'] !== 'expman_domains' ) { return; }
        // Reserved for future (if we need enqueue scripts/css as files).
    }

    public function enqueue_front_assets(): void {
        // Reserved for future (if we need enqueue scripts/css as files).
    }

    public function render_admin(): void {
        $this->render_page( 'main' );
    }

    public function render_trash(): void {
        $this->render_page( 'trash' );
    }

    public function render_map(): void {
        $this->render_page( 'map' );
    }

    public function render_settings(): void {
        $this->ensure_schema();
        $check_all_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=expman_domains_check_all' ),
            'expman_domains_check_all'
        );

        echo '<div class="expman-domains-wrap expman-frontend expman-domains" style="direction:rtl;">';
        $this->render_flash_notice();

        echo '<h3 style="margin:10px 0 12px;">הגדרות</h3>';
        echo '<div style="background:#fff;border:1px solid #d9e3f2;border-radius:12px;padding:14px;">';
        echo '<p style="margin:0 0 10px;">בדיקת WHOIS:</p>';
        echo '<a class="expman-btn" href="' . esc_url( $check_all_url ) . '" onclick="return confirm(&quot;להריץ בדיקת WHOIS לכל הרשומות?&quot;);">בדוק עכשיו את כל הרשומות מול WHOIS</a>';
        echo '<p style="margin:12px 0 0;color:#475569;">הסריקה האוטומטית רצה פעמיים ביום (01:00 ו-13:00) באמצעות WP-Cron.</p>';
        echo '</div>';
        echo '</div>';
    }

    private function render_page( string $tab_mode ): void {
        // For the "main" tab we render 2 blocks: main + archive (same page).
        // For other tabs we render single block.
        $this->ensure_schema();

        $styles = $this->base_styles();
        echo $styles;

        echo '<div class="expman-domains-wrap expman-frontend expman-domains" style="direction:rtl;">';

        $this->render_flash_notice();

        // Whois errors notice only on main
        if ( $tab_mode === 'main' ) {
            $this->render_whois_errors_notice();
        }

        if ( $tab_mode === 'main' ) {
            $this->render_block( 'main', 'דומיינים' );
            echo '<div style="margin:18px 0 10px;border-top:1px solid #d9e3f2;"></div>';
            $this->render_block( 'archive', 'ארכיון' );
        } elseif ( $tab_mode === 'trash' ) {
            $this->render_block( 'trash', 'סל מחזור' );
        } elseif ( $tab_mode === 'map' ) {
            $this->render_block( 'map', 'שיוך לקוח' );
        }

        // Shared JS that supports multiple table blocks on the same page
        echo $this->shared_js();

        echo '</div>';
    }

    private function base_styles(): string {
        return '<style>
        .expman-btn{padding:6px 12px !important;font-size:12px !important;border-radius:6px;border:1px solid #254d8c;background:#2f5ea8;color:#fff;display:inline-block;line-height:1.2;box-shadow:0 1px 0 rgba(0,0,0,0.05);cursor:pointer;text-decoration:none;}
        .expman-btn:hover{background:#264f8f;color:#fff;}
        .expman-btn.secondary{background:#eef3fb;border-color:#9fb3d9;color:#1f3b64;}
        .expman-btn.secondary:hover{background:#dfe9f7;color:#1f3b64;}
        .expman-frontend .widefat{border:1px solid #c7d1e0;border-radius:8px;overflow:hidden;background:#fff;table-layout:auto;width:100%;}
        .expman-frontend .widefat thead th{background:#2f5ea8;color:#fff;border-bottom:2px solid #244b86;padding:8px;}
        .expman-frontend .widefat thead th a{color:#fff !important;text-decoration:none;}
        .expman-frontend .widefat thead th a:hover{color:#fff !important;text-decoration:underline;}
        .expman-frontend .widefat tbody td{padding:8px;border-bottom:1px solid #e3e7ef;overflow-wrap:anywhere;word-break:break-word;}
        .expman-frontend .widefat th,.expman-frontend .widefat td{text-align:right;vertical-align:middle;}
        .expman-row-alt td{background:#f6f8fc;}
        .expman-inline-form td{border-top:1px solid #e3e7ef;background:#f9fbff;}
        .expman-filter-row input,.expman-filter-row select{height:24px !important;padding:4px 6px !important;font-size:12px !important;border:1px solid #c7d1e0;border-radius:4px;background:#fff;}
        .expman-filter-row th{background:#e8f0fb !important;border-bottom:2px solid #c7d1e0;}
        .expman-days-cell{text-align:center;}
        .expman-days-badge{display:inline-block;min-width:34px;padding:2px 10px;border-radius:999px;font-weight:700;line-height:1.2;}
        .expman-days-green{background:rgba(16,185,129,0.16);}
        .expman-days-yellow{background:rgba(245,158,11,0.18);}
        .expman-days-red{background:rgba(239,68,68,0.18);}
        .expman-days-unknown{background:rgba(148,163,184,0.18);}
        .expman-domain-cell{text-align:left !important;direction:ltr;}
        .expman-expiry-manual{color:#800020;font-weight:800;}
        .expman-domains-grid input[type=\"text\"],
        .expman-domains-grid input[type=\"date\"],
        .expman-domains-grid input[type=\"number\"],
        .expman-domains-grid select{height:26px;padding:3px 8px;font-size:13px;}
        .expman-domains-grid textarea{min-height:60px;font-size:13px;padding:6px 8px;}
        .expman-domains-wrap table th,
        .expman-domains-wrap table td {padding-top:6px !important;padding-bottom:6px !important;line-height:1.2 !important;vertical-align:middle !important;}
        .expman-domains-wrap tr.expman-details td,
        .expman-domains-wrap tr.expman-edit td {padding-top:10px !important;padding-bottom:10px !important;}
        </style>';
    }

    private function columns_for_mode( string $mode ): array {
        // Hide legacy client_name on main + archive (migration view).
        $show_legacy = ! in_array( $mode, array( 'main', 'archive' ), true );

        $cols = array();
        if ( $show_legacy ) { $cols['client_name'] = 'שם לקוח ישן'; }

        $cols['customer_number'] = 'מספר לקוח חדש';
        $cols['customer_name']   = 'שם לקוח חדש';
        $cols['domain']          = 'שם הדומיין';
        $cols['expiry_date']     = 'תאריך תפוגה';
        $cols['days_left']       = 'ימים לתפוגה';
        $cols['ownership']       = 'ניהול';
        $cols['payment']         = 'תשלום';

        return $cols;
    }

    private function allowed_sort_keys( string $mode ): array {
        $keys = array_keys( $this->columns_for_mode( $mode ) );
        // sorting by expiry_date/days_left etc is ok; domain etc.
        return $keys;
    }

    private function render_block( string $mode, string $title ): void {
        $allowed_sort = $this->allowed_sort_keys( $mode );

        $orderby = sanitize_key( $_GET['orderby'] ?? 'days_left' );
        if ( ! in_array( $orderby, $allowed_sort, true ) ) { $orderby = 'days_left'; }

        $order = strtoupper( sanitize_key( $_GET['order'] ?? 'ASC' ) );
        if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) { $order = 'ASC'; }

        $per_page = intval( $_GET['per_page'] ?? 20 );
        $allowed_pp = array( 20, 50, 200, 500 );
        if ( ! in_array( $per_page, $allowed_pp, true ) ) { $per_page = 20; }
        $paged = max( 1, intval( $_GET['paged'] ?? 1 ) );

        $filters = array(
            'client_name'     => sanitize_text_field( $_GET['f_client_name'] ?? '' ),
            'customer_number' => sanitize_text_field( $_GET['f_customer_number'] ?? '' ),
            'customer_name'   => sanitize_text_field( $_GET['f_customer_name'] ?? '' ),
            'domain'          => sanitize_text_field( $_GET['f_domain'] ?? '' ),
            'expiry_date'     => sanitize_text_field( $_GET['f_expiry_date'] ?? '' ),
            'days_left'       => sanitize_text_field( $_GET['f_days_left'] ?? '' ),
            'ownership'       => sanitize_text_field( $_GET['f_ownership'] ?? '' ),
            'payment'         => sanitize_text_field( $_GET['f_payment'] ?? '' ),
        );

        $total = 0;
        $rows = $this->get_rows( $filters, $orderby, $order, $mode, $per_page, $paged, $total );

        $page_count = max( 1, (int) ceil( max( 0, $total ) / $per_page ) );
        if ( $paged > $page_count ) { $paged = $page_count; }

        $ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
        $nonce = wp_create_nonce( 'expman_domains_fetch' );

        echo '<div class="expman-domains-table-block" data-expman-block="' . esc_attr( $mode ) . '">';
        echo '<h2 style="margin-top:10px;color:#1d2327;">' . esc_html( $title ) . '</h2>';

        if ( $mode === 'main' ) {
            $summary = $this->get_summary_counts();
            echo $this->render_summary_cards( $summary );
            echo '<div style="display:flex;gap:10px;align-items:center;justify-content:flex-end;margin:10px 0;">';
            echo '<button type="button" class="expman-btn" data-expman-new>חדש</button>';
            echo '</div>';
            echo '<div class="expman-domains-add" style="display:none;">';
            $this->render_form();
            echo '</div>';
        }

        echo '<form method="get" action="" class="expman-domains-filter-form" data-expman-table data-ajax="' . esc_attr( $ajax_url ) . '" data-nonce="' . esc_attr( $nonce ) . '" data-mode="' . esc_attr( $mode ) . '">';
        if ( is_admin() ) {
            echo '<input type="hidden" name="page" value="expman_domains">';
            echo '<input type="hidden" name="tab" value="' . esc_attr( $mode === 'trash' ? 'trash' : ( $mode === 'map' ? 'map' : ( $mode === 'archive' ? 'main' : 'main' ) ) ) . '">';
        } else {
            // Frontend shortcode uses expman_tab
            echo '<input type="hidden" name="expman_tab" value="' . esc_attr( ( $mode === 'trash' ? 'trash' : ( $mode === 'map' ? 'map' : 'main' ) ) ) . '">';
        }
        echo '<input type="hidden" name="orderby" value="' . esc_attr( $orderby ) . '">';
        echo '<input type="hidden" name="order" value="' . esc_attr( $order ) . '">';
        echo '<input type="hidden" name="paged" value="' . esc_attr( $paged ) . '">';

        echo '<div class="expman-domains-table-controls" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:10px 0;">';
        echo '<div style="display:flex;gap:8px;align-items:center;">';
        echo '<span style="font-weight:600;">הצג</span>';
        echo '<select name="per_page" style="height:28px;padding:4px 6px;border:1px solid #c7d1e0;border-radius:6px;">';
        foreach ( array( 20, 50, 200, 500 ) as $pp ) {
            echo '<option value="' . esc_attr( $pp ) . '" ' . selected( $per_page, $pp, false ) . '>' . esc_html( $pp ) . '</option>';
        }
        echo '</select>';
        echo '<span>רשומות</span>';
        echo '</div>';

        echo '<div style="display:flex;gap:8px;align-items:center;">';
        echo '<button type="button" class="expman-btn secondary" data-expman-prev ' . ( $paged <= 1 ? 'disabled' : '' ) . '>קודם</button>';
        echo '<span data-expman-page-info>עמוד ' . esc_html( $paged ) . ' מתוך ' . esc_html( $page_count ) . ' (סה״כ ' . esc_html( $total ) . ')</span>';
        echo '<button type="button" class="expman-btn secondary" data-expman-next ' . ( $paged >= $page_count ? 'disabled' : '' ) . '>הבא</button>';
        echo '</div>';

        echo '<div>';
        echo '<button type="submit" class="expman-btn secondary">רענן</button>';
        echo '</div>';
        echo '</div>';

        $cols = $this->columns_for_mode( $mode );
        $colcount = count( $cols );

        $base = remove_query_arg( array( 'orderby', 'order' ) );

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        foreach ( $cols as $key => $label ) {
            $this->th_sort( $key, $label, $orderby, $order, $base );
        }
        echo '</tr>';

        // Filter row: only for columns
        echo '<tr class="expman-filter-row">';
        foreach ( $cols as $key => $label ) {
            if ( $key === 'ownership' ) {
                echo '<th><select name="f_ownership" style="width:100%;">';
                echo '<option value="">הכל</option>';
                echo '<option value="ours" ' . selected( $filters['ownership'], 'ours', false ) . '>שלנו</option>';
                echo '<option value="not_ours" ' . selected( $filters['ownership'], 'not_ours', false ) . '>לא שלנו</option>';
                echo '</select></th>';
            } elseif ( $key === 'payment' ) {
                echo '<th><select name="f_payment" style="width:100%;">';
                echo '<option value="">הכל</option>';
                echo '<option value="ours" ' . selected( $filters['payment'], 'ours', false ) . '>שלנו</option>';
                echo '<option value="customer" ' . selected( $filters['payment'], 'customer', false ) . '>לקוח</option>';
                echo '</select></th>';
            } elseif ( $key === 'expiry_date' ) {
                $expiry_exact_ui = $this->format_db_date_to_ui( (string) $filters['expiry_date'] );
                echo '<th><input style="width:100%" name="f_expiry_date" value="' . esc_attr( $expiry_exact_ui ) . '" placeholder="dd/mm/yyyy"></th>';
            } elseif ( $key === 'days_left' ) {
                echo '<th><input style="width:100%" name="f_days_left" value="' . esc_attr( $filters['days_left'] ) . '" placeholder="ימים..."></th>';
            } else {
                $map = array(
                    'client_name' => 'f_client_name',
                    'customer_number' => 'f_customer_number',
                    'customer_name' => 'f_customer_name',
                    'domain' => 'f_domain',
                );
                $fname = $map[ $key ] ?? '';
                if ( $fname !== '' ) {
                    $val_key = str_replace( 'f_', '', $fname );
                    echo '<th><input style="width:100%" name="' . esc_attr( $fname ) . '" value="' . esc_attr( (string) ( $filters[ $val_key ] ?? '' ) ) . '" placeholder="סינון..."></th>';
                } else {
                    echo '<th></th>';
                }
            }
        }
        echo '</tr>';
        echo '</thead>';

        echo '<tbody data-expman-body data-expman-colcount="' . esc_attr( $colcount ) . '">';
        echo $this->render_rows_html( $rows, $mode, $colcount, array_keys( $cols ) );
        echo '</tbody></table>';
        echo '</form>';
        echo '</div>';
    }

    private function render_summary_cards( array $data ): string {
        static $done = false;
        $out = '';
        if ( ! $done ) {
            $out .= '<style>
            .expman-summary{display:flex;gap:12px;flex-wrap:wrap;align-items:stretch;margin:14px 0;}
            .expman-summary-card{flex:1 1 160px;border-radius:12px;padding:10px 12px;border:1px solid #d9e3f2;background:#fff;min-width:160px;cursor:pointer;text-align:right;}
            .expman-summary-card button{all:unset;cursor:pointer;display:block;width:100%;}
            .expman-summary-card h4{margin:0 0 6px;font-size:14px;color:#2b3f5c;}
            .expman-summary-card .count{display:inline-flex;align-items:center;justify-content:center;min-width:36px;padding:4px 10px;border-radius:999px;font-size:18px;font-weight:700;color:#183153;background:rgba(24,49,83,0.08);}
            .expman-summary-card.green{background:#ecfbf4;border-color:#bfead4;}
            .expman-summary-card.yellow{background:#fff4e7;border-color:#ffd3a6;}
            .expman-summary-card.red{background:#ffecec;border-color:#f3b6b6;}
            .expman-summary-card.green .count{background:#c9f1dd;color:#1b5a39;}
            .expman-summary-card.yellow .count{background:#ffe2c6;color:#7a4c11;}
            .expman-summary-card.red .count{background:#ffd1d1;color:#7a1f1f;}
            .expman-summary-card[data-active=\"1\"]{box-shadow:0 0 0 2px rgba(47,94,168,0.18);}
            .expman-summary-meta{margin-top:8px;padding:8px 12px;border-radius:10px;border:1px solid #d9e3f2;background:#f8fafc;font-weight:600;color:#2b3f5c;}
            .expman-summary-meta button{all:unset;cursor:pointer;}
            </style>';
            $done = true;
        }

        $yellow_label = 'תוקף בין ' . ( (int) $data['red_threshold'] + 1 ) . ' ל-' . (int) $data['yellow_threshold'] . ' יום';

        $out .= '<div class="expman-summary">';
        $out .= '<div class="expman-summary-card green" data-expman-status="green"><button type="button"><h4>תוקף מעל ' . esc_html( $data['yellow_threshold'] ) . ' יום</h4><div class="count">' . esc_html( $data['green'] ) . '</div></button></div>';
        $out .= '<div class="expman-summary-card yellow" data-expman-status="yellow"><button type="button"><h4>' . esc_html( $yellow_label ) . '</h4><div class="count">' . esc_html( $data['yellow'] ) . '</div></button></div>';
        $out .= '<div class="expman-summary-card red" data-expman-status="red"><button type="button"><h4>דורש טיפול מייד</h4><div class="count">' . esc_html( $data['red'] ) . '</div></button></div>';
        $out .= '</div>';
        $out .= '<div class="expman-summary-meta" data-expman-status="all"><button type="button">סה״כ רשומות פעילות: ' . esc_html( $data['total'] ) . '</button></div>';
        return $out;
    }

    private function th_sort( string $key, string $label, string $orderby, string $order, string $base ): void {
        $next_order = ( $orderby === $key && $order === 'ASC' ) ? 'DESC' : 'ASC';
        $url = add_query_arg( array( 'orderby' => $key, 'order' => $next_order ), $base );

        $arrow = '';
        if ( $orderby === $key ) {
            $arrow = ( $order === 'ASC' ) ? ' ▲' : ' ▼';
        }
        echo '<th><a class="expman-sort" data-orderby="' . esc_attr( $key ) . '" data-order="' . esc_attr( $next_order ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label . $arrow ) . '</a></th>';
    }

    private function render_form( int $id = 0, ?array $row = null ): void {
        $data = array(
            'client_name'     => '',
            'customer_number' => '',
            'customer_name'   => '',
            'domain'          => '',
            'expiry_date'     => '',
            'days_left'       => '',
            'manual_expiry'   => '0',
            'free_text_domain'=> '0',
            'archived'        => '0',
            'temp_text_enabled' => '0',
            'ownership'       => 'ours',
            'payment'         => 'ours',
            'registrar'       => '',
            'notes'           => '',
            'temp_text'       => '',
        );

        if ( $row ) {
            foreach ( $data as $k => $v ) {
                if ( array_key_exists( $k, $row ) ) { $data[ $k ] = (string) $row[ $k ]; }
            }
        }

        echo '<style>
            .expman-domains-form{background:#fff;border:1px solid #e3e3e3;border-radius:12px;padding:14px;margin:12px 0}
            .expman-domains-grid{display:grid;grid-template-columns:repeat(2,minmax(200px,1fr));gap:12px;align-items:end}
            .expman-domains-grid .full{grid-column:span 2}
            .expman-domains-grid label{display:block;font-size:12px;color:#333;margin-bottom:4px}
            .expman-domains-actions{display:flex;gap:10px;justify-content:flex-start;margin-top:12px}
            @media (max-width: 900px){ .expman-domains-grid{grid-template-columns:repeat(1,minmax(160px,1fr));} .expman-domains-grid .full{grid-column:span 1} }
        </style>';

        echo '<form method="post" class="expman-domains-form" style="margin:0;" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'expman_save_domain', 'expman_save_domain_nonce' );
        echo '<input type="hidden" name="action" value="expman_save_domain">';
        echo '<input type="hidden" name="domain_id" value="' . esc_attr( $id ) . '">';

        echo '<div class="expman-domains-grid">';
        echo '<div><label>שם לקוח ישן</label><input type="text" name="client_name" value="' . esc_attr( $data['client_name'] ) . '"></div>';
        echo '<div><label>מספר לקוח חדש</label><input type="text" name="customer_number" value="' . esc_attr( $data['customer_number'] ) . '"></div>';
        echo '<div><label>שם לקוח חדש</label><input type="text" name="customer_name" value="' . esc_attr( $data['customer_name'] ) . '"></div>';

        echo '<div><label>שם הדומיין</label><input type="text" name="domain" required value="' . esc_attr( $data['domain'] ) . '"></div>';

        $expiry_ui = $this->format_db_date_to_ui( (string) $data['expiry_date'] );
        echo '<div><label>תאריך תפוגה</label><input type="text" name="expiry_date" value="' . esc_attr( $expiry_ui ) . '" placeholder="dd/mm/yyyy" inputmode="numeric" pattern="\\d{2}\\/\\d{2}\\/\\d{4}"></div>';

        echo '<div><label>ימים לתפוגה</label><input type="number" name="days_left" value="' . esc_attr( $data['days_left'] ) . '"></div>';

        echo '<div class="full"><label>דגלים</label><div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12px;">';
        echo '<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" name="manual_expiry" value="1" ' . checked( $data['manual_expiry'], '1', false ) . '>תוקף ידני</label>';
        echo '<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" name="free_text_domain" value="1" ' . checked( $data['free_text_domain'], '1', false ) . '>שם דומיין ידני</label>';
        echo '<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" name="archived" value="1" ' . checked( $data['archived'], '1', false ) . '>ארכיון</label>';
        echo '<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" name="temp_text_enabled" value="1" ' . checked( $data['temp_text_enabled'], '1', false ) . '>הערה זמנית פעילה</label>';
        echo '</div></div>';

        echo '<div><label>ניהול</label><select name="ownership">';
        echo '<option value="ours" ' . selected( $data['ownership'], 'ours', false ) . '>שלנו</option>';
        echo '<option value="not_ours" ' . selected( $data['ownership'], 'not_ours', false ) . '>לא שלנו</option>';
        echo '</select></div>';

        echo '<div><label>תשלום</label><select name="payment">';
        echo '<option value="ours" ' . selected( $data['payment'], 'ours', false ) . '>שלנו</option>';
        echo '<option value="customer" ' . selected( $data['payment'], 'customer', false ) . '>לקוח</option>';
        echo '</select></div>';

        echo '<div class="full"><label>הערות</label><textarea name="notes" rows="2">' . esc_textarea( (string) $data['notes'] ) . '</textarea></div>';
        echo '<div class="full"><label>הערות זמניות</label><textarea name="temp_text" rows="2">' . esc_textarea( (string) $data['temp_text'] ) . '</textarea></div>';

        echo '</div>';

        echo '<div class="expman-domains-actions">';
        echo '<button type="submit" class="button button-primary">שמור</button>';
        echo '<button type="button" class="button expman-close-form" data-expman-close-form>סגור</button>';
        echo '</div>';
        echo '</form>';
    }

    private function render_rows_html( array $rows, string $mode, int $colcount, array $colkeys ): string {
        if ( empty( $rows ) ) {
            return '<tr><td colspan="' . esc_attr( $colcount ) . '">אין נתונים.</td></tr>';
        }

        $html = '';
        $row_index = 0;

        foreach ( $rows as $row ) {
            $row_index++;
            $row_id = intval( $row['id'] ?? $row_index );

            $days_left = $row['days_left'] ?? '';
            $days_class = 'expman-days-unknown';
            if ( $days_left !== '' && $days_left !== null ) {
                $days_value = intval( $days_left );
                if ( $days_value <= 7 )      { $days_class = 'expman-days-red'; }
                elseif ( $days_value <= 30 ) { $days_class = 'expman-days-yellow'; }
                else                         { $days_class = 'expman-days-green'; }
            }

            $row_class = ( $row_index % 2 === 0 ) ? 'expman-row-alt' : '';
            $status_attr = str_replace( 'expman-days-', '', $days_class );

            $html .= '<tr class="expman-row ' . esc_attr( $row_class ) . '" data-id="' . esc_attr( $row_id ) . '" data-expman-status="' . esc_attr( $status_attr ) . '">';

            foreach ( $colkeys as $k ) {
                if ( $k === 'domain' ) {
                    $html .= '<td class="expman-domain-cell">' . esc_html( (string) ( $row['domain'] ?? '' ) ) . '</td>';
                } elseif ( $k === 'expiry_date' ) {
                    $exp_ui = $this->format_db_date_to_ui( (string) ( $row['expiry_date'] ?? '' ) );
                    $manual = ! empty( $row['manual_expiry'] ) && intval( $row['manual_expiry'] ) === 1;
                    $cls = $manual ? 'expman-expiry-manual' : '';
                    $html .= '<td class="' . esc_attr( $cls ) . '">' . esc_html( $exp_ui ) . '</td>';
                } elseif ( $k === 'days_left' ) {
                    $html .= '<td class="expman-days-cell"><span class="expman-days-badge ' . esc_attr( $days_class ) . '">' . esc_html( (string) $days_left ) . '</span></td>';
                } elseif ( $k === 'ownership' ) {
                    $ownership_raw = (string) ( $row['ownership'] ?? '' );
                    $ownership_label = $ownership_raw;
                    if ( $ownership_raw === 'ours' || $ownership_raw === '1' ) { $ownership_label = 'שלנו'; }
                    elseif ( $ownership_raw === 'not_ours' || $ownership_raw === '0' ) { $ownership_label = 'לא שלנו'; }
                    $html .= '<td>' . esc_html( $ownership_label ) . '</td>';
                } elseif ( $k === 'payment' ) {
                    $payment_raw = (string) ( $row['payment'] ?? '' );
                    $payment_label = $payment_raw;
                    if ( $payment_raw === 'ours' || $payment_raw === '1' ) { $payment_label = 'שלנו'; }
                    elseif ( $payment_raw === 'customer' || $payment_raw === '0' ) { $payment_label = 'לקוח'; }
                    $html .= '<td>' . esc_html( $payment_label ) . '</td>';
                } else {
                    $html .= '<td>' . esc_html( (string) ( $row[ $k ] ?? '' ) ) . '</td>';
                }
            }

            $html .= '</tr>';

            // Details row (second line)
            $html .= '<tr class="expman-inline-form expman-details" data-for="' . esc_attr( $row_id ) . '" style="display:none;">';
            $html .= '<td colspan="' . esc_attr( $colcount ) . '">';
            $html .= '<div style="display:flex;gap:24px;flex-wrap:wrap;">';

            $html .= '<div><strong>הערות:</strong> ' . esc_html( (string) ( $row['notes'] ?? '' ) ) . '</div>';

            $show_temp = ( ! empty( $row['temp_text_enabled'] ) && intval( $row['temp_text_enabled'] ) === 1 ) || ( trim( (string) ( $row['temp_text'] ?? '' ) ) !== '' );
            if ( $show_temp ) {
                $html .= '<div><strong>הערות זמניות:</strong> ' . esc_html( (string) ( $row['temp_text'] ?? '' ) ) . '</div>';
            }

            // Actions
            $html .= '<div class="expman-details-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-right:auto;">';

            // Check now (single record)
            if ( $mode !== 'trash' ) {
                $check_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=expman_domain_check_now&id=' . $row_id ),
                    'expman_domain_check_now_' . $row_id
                );
                $html .= '<a class="expman-btn secondary" href="' . esc_url( $check_url ) . '">בדוק עכשיו</a>';
            }

            $html .= '<button type="button" class="expman-btn secondary expman-toggle-edit" data-id="' . esc_attr( $row_id ) . '">' . ( $mode === 'map' ? 'שייך' : 'עריכה' ) . '</button>';

            if ( $mode === 'trash' ) {
                $restore_url = wp_nonce_url( admin_url( 'admin-post.php?action=expman_domain_restore&id=' . $row_id ), 'expman_domain_restore_' . $row_id );
                $delete_url  = wp_nonce_url( admin_url( 'admin-post.php?action=expman_domain_delete&id=' . $row_id ), 'expman_domain_delete_' . $row_id );
                $html .= '<a class="expman-btn secondary" href="' . esc_url( $restore_url ) . '">שחזר</a>';
                $html .= '<a class="expman-btn secondary" href="' . esc_url( $delete_url ) . '" onclick="return confirm(&quot;למחוק לצמיתות?&quot;);">מחק לצמיתות</a>';
            } else {
                $trash_url = wp_nonce_url( admin_url( 'admin-post.php?action=expman_domain_trash&id=' . $row_id ), 'expman_domain_trash_' . $row_id );
                $html .= '<a class="expman-btn secondary" href="' . esc_url( $trash_url ) . '" onclick="return confirm(&quot;להעביר לסל?&quot;);">סל</a>';
            }

            $html .= '</div>';

            $html .= '</div>';
            $html .= '</td></tr>';

            // Edit row
            $html .= '<tr class="expman-inline-form expman-edit" data-for="' . esc_attr( $row_id ) . '" style="display:none;">';
            $html .= '<td colspan="' . esc_attr( $colcount ) . '">';
            ob_start();
            $this->render_form( $row_id, $row );
            $html .= ob_get_clean();
            $html .= '</td></tr>';
        }

        return $html;
    }

    private function shared_js(): string {
        return '<script>
        (function(){
            const wrap = document.querySelector(".expman-domains-wrap");
            if(!wrap){return;}

            function wireAddToggle(scope){
                const addToggle = scope.querySelector("[data-expman-new]");
                const addForm = scope.querySelector(".expman-domains-add");
                if(addToggle && addForm){
                    addToggle.addEventListener("click", function(){
                        addForm.style.display = (addForm.style.display === "none" || addForm.style.display === "") ? "block" : "none";
                        if(addForm.style.display === "block"){
                            addForm.scrollIntoView({behavior:"smooth", block:"start"});
                        }
                    });
                }
            }

            function wireRowClicks(scope){
                scope.querySelectorAll("tr.expman-row").forEach(function(row){
                    row.addEventListener("click", function(e){
                        if(e.target.closest("button, a, input, select, textarea, label")){return;}
                        var id = row.getAttribute("data-id");
                        var detail = scope.querySelector("tr.expman-details[data-for=\'" + id + "\']");
                        if(!detail){return;}
                        detail.style.display = (detail.style.display === "none" || detail.style.display === "") ? "table-row" : "none";
                    });
                });
            }

            function wireEditButtons(scope){
                scope.querySelectorAll(".expman-toggle-edit").forEach(function(btn){
                    btn.addEventListener("click", function(e){
                        e.preventDefault();
                        var id = btn.getAttribute("data-id");
                        var row = scope.querySelector("tr.expman-edit[data-for=\'" + id + "\']");
                        if(!row){return;}
                        row.style.display = (row.style.display === "none" || row.style.display === "") ? "table-row" : "none";
                        if(row.style.display === "table-row"){
                            row.scrollIntoView({behavior:"smooth", block:"nearest"});
                        }
                    });
                });
            }

            function wireCloseButtons(scope){
                scope.addEventListener("click", function(e){
                    var close = e.target.closest("[data-expman-close-form]");
                    if(close){
                        e.preventDefault();
                        var form = close.closest("form");
                        if(form && form.classList.contains("expman-domains-form")){
                            // If inside edit row - hide it
                            var editRow = close.closest("tr.expman-edit");
                            if(editRow){ editRow.style.display = "none"; return; }
                            // If in \"new\" panel - collapse panel
                            var add = close.closest(".expman-domains-add");
                            if(add){ add.style.display = "none"; return; }
                        }
                    }
                });
            }

            function debounce(fn, delay){
                let t;
                return function(){
                    const args = arguments;
                    clearTimeout(t);
                    t = setTimeout(function(){ fn.apply(null, args); }, delay);
                };
            }

            // Summary cards filter (only affects first block on page - main)
            function initSummary(){
                const block = wrap.querySelector(".expman-domains-table-block[data-expman-block=\'main\']");
                if(!block) return;

                function setActiveSummary(status){
                    block.querySelectorAll(".expman-summary-card, .expman-summary-meta").forEach(function(card){
                        card.setAttribute("data-active", card.getAttribute("data-expman-status") === status ? "1" : "0");
                    });
                }
                function applyStatusFilter(status){
                    block.querySelectorAll("tr.expman-row").forEach(function(row){
                        var rowStatus = row.getAttribute("data-expman-status") || "";
                        var show = (status === "all") || (rowStatus === status);
                        row.style.display = show ? "" : "none";
                        var id = row.getAttribute("data-id");
                        if(id){
                            var detail = block.querySelector("tr.expman-details[data-for=\'" + id + "\']");
                            var edit = block.querySelector("tr.expman-edit[data-for=\'" + id + "\']");
                            if(detail) detail.style.display = "none";
                            if(edit) edit.style.display = "none";
                        }
                    });
                }
                block.querySelectorAll(".expman-summary-card, .expman-summary-meta").forEach(function(card){
                    card.addEventListener("click", function(){
                        var status = card.getAttribute("data-expman-status") || "all";
                        setActiveSummary(status);
                        applyStatusFilter(status);
                    });
                });
                setActiveSummary("all");
            }

            function initTableBlock(block){
                const form = block.querySelector("form[data-expman-table]");
                if(!form) return;

                wireAddToggle(block);
                wireRowClicks(block);
                wireEditButtons(block);

                const ajax = form.getAttribute("data-ajax");
                const nonce = form.getAttribute("data-nonce");
                const mode = form.getAttribute("data-mode");
                const body = form.querySelector("[data-expman-body]");

                const pagedInput = form.querySelector("input[name=\'paged\']");
                const perPageSelect = form.querySelector("select[name=\'per_page\']");
                const pageInfo = block.querySelector("[data-expman-page-info]");
                const prevBtn = block.querySelector("[data-expman-prev]");
                const nextBtn = block.querySelector("[data-expman-next]");

                function setPage(n){
                    if(pagedInput){ pagedInput.value = String(Math.max(1, parseInt(n||1,10) || 1)); }
                }

                function runFetch(){
                    if(!ajax || !body) return;
                    const data = new FormData(form);
                    data.append("action", "expman_domains_fetch");
                    data.append("nonce", nonce || "");
                    data.append("mode", mode || "main");

                    fetch(ajax, { method:"POST", body:data })
                        .then(r=>r.json())
                        .then(function(res){
                            if(!res || !res.success){return;}
                            body.innerHTML = res.data.html || "";
                            wireRowClicks(block);
                            wireEditButtons(block);

                            if(res.data){
                                const total = parseInt(res.data.total||0,10) || 0;
                                const pp = parseInt(res.data.per_page|| (perPageSelect ? perPageSelect.value : 20),10) || 20;
                                const pg = parseInt(res.data.paged|| (pagedInput ? pagedInput.value : 1),10) || 1;
                                const pages = Math.max(1, Math.ceil(total / pp));
                                if(pageInfo){ pageInfo.textContent = "עמוד " + pg + " מתוך " + pages + " (סה״כ " + total + ")"; }
                                if(prevBtn){ prevBtn.disabled = (pg <= 1); }
                                if(nextBtn){ nextBtn.disabled = (pg >= pages); }
                            }
                        })
                        .catch(()=>{});
                }

                if(perPageSelect){
                    perPageSelect.addEventListener("change", function(){ setPage(1); runFetch(); });
                }
                if(prevBtn){
                    prevBtn.addEventListener("click", function(){
                        const cur = pagedInput ? parseInt(pagedInput.value||"1",10) : 1;
                        setPage(cur-1); runFetch();
                    });
                }
                if(nextBtn){
                    nextBtn.addEventListener("click", function(){
                        const cur = pagedInput ? parseInt(pagedInput.value||"1",10) : 1;
                        setPage(cur+1); runFetch();
                    });
                }

                block.addEventListener("click", function(e){
                    var a = e.target.closest("a.expman-sort");
                    if(!a){return;}
                    e.preventDefault();
                    var ob = a.getAttribute("data-orderby") || "";
                    var or = a.getAttribute("data-order") || "";
                    var obInput = form.querySelector("input[name=\'orderby\']");
                    var orInput = form.querySelector("input[name=\'order\']");
                    if(obInput) obInput.value = ob;
                    if(orInput) orInput.value = or;
                    setPage(1);
                    runFetch();
                });

                form.addEventListener("submit", function(e){
                    e.preventDefault();
                    setPage(1);
                    runFetch();
                });

                const debounced = debounce(runFetch, 350);
                form.querySelectorAll(".expman-filter-row input").forEach(function(input){
                    input.addEventListener("input", function(){ setPage(1); debounced(); });
                    input.addEventListener("change", function(){ setPage(1); runFetch(); });
                });
                form.querySelectorAll(".expman-filter-row select").forEach(function(sel){
                    sel.addEventListener("change", function(){ setPage(1); runFetch(); });
                });
            }

            wrap.querySelectorAll(".expman-domains-table-block").forEach(initTableBlock);
            wireCloseButtons(wrap);
            initSummary();
        })();
        </script>';
    }

    private function render_flash_notice(): void {
        $msg = isset( $_GET['expman_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['expman_msg'] ) ) : '';
        $type = isset( $_GET['expman_msg_type'] ) ? sanitize_key( wp_unslash( $_GET['expman_msg_type'] ) ) : 'success';
        if ( $msg === '' ) { return; }

        $cls = ( $type === 'error' ) ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="' . esc_attr( $cls ) . '" style="margin:12px 0;"><p>' . esc_html( $msg ) . '</p></div>';
    }

    private function render_whois_errors_notice(): void {
        global $wpdb;
        $table = $this->get_table_name();

        // Only show for records that failed at least once.
        $rows = $wpdb->get_results(
            "SELECT domain, last_whois_error, last_whois_server, last_whois_checked_at
             FROM `$table`
             WHERE deleted_at IS NULL
               AND last_whois_error IS NOT NULL AND last_whois_error <> ''
             ORDER BY last_whois_checked_at DESC
             LIMIT 5",
            ARRAY_A
        );

        if ( empty( $rows ) ) { return; }

        echo '<div style="margin:12px 0;padding:14px;border-radius:12px;border:2px solid #b91c1c;background:#fee2e2;color:#7f1d1d;font-weight:800;">';
        echo 'שגיאת WHOIS: לא בוצעה בדיקה תקינה עבור: ';
        $parts = array();
        foreach ( $rows as $r ) {
            $domain = (string) ( $r['domain'] ?? '' );
            if ( $domain === '' ) { continue; }
            $parts[] = $domain;
        }
        echo esc_html( implode( ', ', $parts ) );
        echo '</div>';
    }

    /* -------------------- CRUD ACTIONS -------------------- */

    public function handle_trash(): void {
        $this->require_manage_options();
        $id = intval( $_GET['id'] ?? 0 );
        if ( $id <= 0 ) {
            wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=expman_domains' ) );
            exit;
        }
        check_admin_referer( 'expman_domain_trash_' . $id );

        $this->ensure_schema();
        global $wpdb;
        $table = $this->get_table_name();
        $now = current_time( 'mysql' );
        $wpdb->update( $table, array( 'deleted_at' => $now, 'updated_at' => $now ), array( 'id' => $id ) );

        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=expman_domains&tab=trash' ) );
        exit;
    }

    public function handle_restore(): void {
        $this->require_manage_options();
        $id = intval( $_GET['id'] ?? 0 );
        if ( $id <= 0 ) {
            wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=expman_domains' ) );
            exit;
        }
        check_admin_referer( 'expman_domain_restore_' . $id );

        $this->ensure_schema();
        global $wpdb;
        $table = $this->get_table_name();
        $now = current_time( 'mysql' );
        $wpdb->update( $table, array( 'deleted_at' => null, 'updated_at' => $now ), array( 'id' => $id ) );

        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=expman_domains' ) );
        exit;
    }

    public function handle_delete(): void {
        $this->require_manage_options();
        $id = intval( $_GET['id'] ?? 0 );
        if ( $id <= 0 ) {
            wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=expman_domains' ) );
            exit;
        }
        check_admin_referer( 'expman_domain_delete_' . $id );

        $this->ensure_schema();
        global $wpdb;
        $table = $this->get_table_name();
        $wpdb->delete( $table, array( 'id' => $id ) );

        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=expman_domains&tab=trash' ) );
        exit;
    }

    private function flash_redirect( string $url, string $msg, string $type = 'success' ): void {
        $url = add_query_arg(
            array(
                'expman_msg' => rawurlencode( $msg ),
                'expman_msg_type' => $type,
            ),
            $url
        );
        wp_safe_redirect( $url );
        exit;
    }

    private function is_english_domain( string $d ): bool {
        $d = trim( $d );
        if ( $d === '' ) { return false; }
        // Hebrew not allowed unless manual/free-text domain checked.
        if ( preg_match( '/[\x{0590}-\x{05FF}]/u', $d ) ) { return false; }

        $ascii = $d;
        if ( function_exists( 'idn_to_ascii' ) ) {
            $tmp = idn_to_ascii( $d, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
            if ( $tmp ) { $ascii = $tmp; }
        }
        $ascii = strtolower( $ascii );
        // Basic DNS name validation (punycode allowed).
        return (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,}$/', $ascii );
    }

    public function handle_save(): void {
        $this->require_manage_options();

        $nonce = sanitize_text_field( $_POST['expman_save_domain_nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'expman_save_domain' ) ) {
            wp_die( 'Invalid nonce.' );
        }

        $this->ensure_schema();
        global $wpdb;
        $table = $this->get_table_name();

        $id = intval( $_POST['domain_id'] ?? 0 );

        $manual_expiry     = ! empty( $_POST['manual_expiry'] ) ? 1 : 0;
        $free_text_domain  = ! empty( $_POST['free_text_domain'] ) ? 1 : 0;
        $archived          = ! empty( $_POST['archived'] ) ? 1 : 0;
        $temp_text_enabled = ! empty( $_POST['temp_text_enabled'] ) ? 1 : 0;

        $domain_raw = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );
        $domain_raw = trim( $domain_raw );

        if ( ! $free_text_domain ) {
            if ( ! $this->is_english_domain( $domain_raw ) ) {
                $this->flash_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=expman_domains' ), 'שם הדומיין חייב להיות באנגלית (אלא אם מסומן שם דומיין ידני).', 'error' );
            }
            $domain_raw = strtolower( $domain_raw );
        }

        $ownership = sanitize_text_field( wp_unslash( $_POST['ownership'] ?? 'ours' ) );
        if ( ! in_array( $ownership, array( 'ours', 'not_ours' ), true ) ) { $ownership = 'ours'; }

        $payment = sanitize_text_field( wp_unslash( $_POST['payment'] ?? 'ours' ) );
        if ( ! in_array( $payment, array( 'ours', 'customer' ), true ) ) { $payment = 'ours'; }

        $expiry_input      = sanitize_text_field( wp_unslash( $_POST['expiry_date'] ?? '' ) );
        $expiry_date_only  = $this->normalize_ui_date_to_db( $expiry_input );
        $expiry_date       = $expiry_date_only !== '' ? ( $expiry_date_only . ' 00:00:00' ) : null;

        $days_left = null;
        if ( $expiry_date_only !== '' ) {
            try {
                $today  = new DateTimeImmutable( 'today' );
                $expiry = new DateTimeImmutable( $expiry_date_only );
                $days_left = (int) $today->diff( $expiry )->format( '%r%a' );
            } catch ( Exception $e ) {
                $days_left = null;
            }
        }

        $now = current_time( 'mysql' );

        $data = array(
            'client_name' => sanitize_text_field( wp_unslash( $_POST['client_name'] ?? '' ) ),
            'customer_number' => sanitize_text_field( wp_unslash( $_POST['customer_number'] ?? '' ) ),
            'customer_name' => sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) ),
            'domain'      => $domain_raw,
            'expiry_date' => $expiry_date,
            'days_left'   => $days_left,
            'manual_expiry' => $manual_expiry,
            'free_text_domain' => $free_text_domain,
            'archived' => $archived,
            'temp_text_enabled' => $temp_text_enabled,
            'ownership' => $ownership,
            'payment' => $payment,
            'notes' => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
            'temp_text' => sanitize_textarea_field( wp_unslash( $_POST['temp_text'] ?? '' ) ),
            'updated_at'  => $now,
        );

        if ( $id > 0 ) {
            $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $data['created_at'] = $now;
            $wpdb->insert( $table, $data );
        }

        $redirect = wp_get_referer() ?: admin_url( 'admin.php?page=expman_domains' );
        wp_safe_redirect( $redirect );
        exit;
    }

    /* -------------------- FETCH (AJAX) -------------------- */

    public function handle_fetch(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Access denied.' ) );
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'expman_domains_fetch' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
        }

        $mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'main' ) );
        if ( ! in_array( $mode, array( 'main', 'archive', 'trash', 'map' ), true ) ) { $mode = 'main'; }

        $allowed_sort = $this->allowed_sort_keys( $mode );

        $orderby = sanitize_key( wp_unslash( $_POST['orderby'] ?? 'days_left' ) );
        if ( ! in_array( $orderby, $allowed_sort, true ) ) { $orderby = 'days_left'; }

        $order = strtoupper( sanitize_key( wp_unslash( $_POST['order'] ?? 'ASC' ) ) );
        if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) { $order = 'ASC'; }

        $per_page = intval( wp_unslash( $_POST['per_page'] ?? 20 ) );
        $allowed_pp = array( 20, 50, 200, 500 );
        if ( ! in_array( $per_page, $allowed_pp, true ) ) { $per_page = 20; }

        $paged = max( 1, intval( wp_unslash( $_POST['paged'] ?? 1 ) ) );

        $filters = array(
            'client_name'     => sanitize_text_field( wp_unslash( $_POST['f_client_name'] ?? '' ) ),
            'customer_number' => sanitize_text_field( wp_unslash( $_POST['f_customer_number'] ?? '' ) ),
            'customer_name'   => sanitize_text_field( wp_unslash( $_POST['f_customer_name'] ?? '' ) ),
            'domain'          => sanitize_text_field( wp_unslash( $_POST['f_domain'] ?? '' ) ),
            'expiry_date'     => sanitize_text_field( wp_unslash( $_POST['f_expiry_date'] ?? '' ) ),
            'days_left'       => sanitize_text_field( wp_unslash( $_POST['f_days_left'] ?? '' ) ),
            'ownership'       => sanitize_text_field( wp_unslash( $_POST['f_ownership'] ?? '' ) ),
            'payment'         => sanitize_text_field( wp_unslash( $_POST['f_payment'] ?? '' ) ),
        );

        $total = 0;
        $rows = $this->get_rows( $filters, $orderby, $order, $mode, $per_page, $paged, $total );
        $cols = $this->columns_for_mode( $mode );
        $colcount = count( $cols );
        $html = $this->render_rows_html( $rows, $mode, $colcount, array_keys( $cols ) );

        wp_send_json_success( array(
            'html' => $html,
            'total' => intval( $total ),
            'per_page' => intval( $per_page ),
            'paged' => intval( $paged ),
        ) );
    }

    /* -------------------- DATA QUERY -------------------- */

    private function get_rows( array $filters, string $orderby, string $order, string $mode, int $per_page, int $paged, int &$total ): array {
        $this->ensure_schema();
        global $wpdb;
        $table = $this->get_table_name();

        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 );
        $cols = array_map( 'strtolower', (array) $cols );

        $has = function( string $c ) use ( $cols ): bool { return in_array( strtolower( $c ), $cols, true ); };

        $where = array();
        $params = array();

        if ( $mode === 'trash' ) {
            $where[] = 'deleted_at IS NOT NULL';
        } else {
            $where[] = 'deleted_at IS NULL';
        }

        if ( $mode === 'main' || $mode === 'map' ) {
            if ( $has( 'archived' ) ) { $where[] = 'archived = 0'; }
        } elseif ( $mode === 'archive' ) {
            if ( $has( 'archived' ) ) { $where[] = 'archived = 1'; } else { $where[] = '1=0'; }
        }

        // Mapping logic (only for main/map)
        $mapping_clause = ( $has( 'customer_number' ) || $has( 'customer_name' ) )
            ? "( (customer_number IS NOT NULL AND customer_number <> '') OR (customer_name IS NOT NULL AND customer_name <> '') )"
            : '';
        $map_clause = ( $has( 'customer_number' ) || $has( 'customer_name' ) )
            ? "( (customer_number IS NULL OR customer_number = '') AND (customer_name IS NULL OR customer_name = '') )"
            : '';

        $had_mapping_clause = false;
        if ( $mode === 'main' && $mapping_clause !== '' ) {
            $where[] = $mapping_clause;
            $had_mapping_clause = true;
        } elseif ( $mode === 'map' && $map_clause !== '' ) {
            $where[] = $map_clause;
        }

        if ( $has( 'client_name' ) && (string) $filters['client_name'] !== '' ) {
            $where[] = 'client_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like( (string) $filters['client_name'] ) . '%';
        }
        if ( $has( 'customer_number' ) && (string) $filters['customer_number'] !== '' ) {
            $where[] = 'customer_number LIKE %s';
            $params[] = '%' . $wpdb->esc_like( (string) $filters['customer_number'] ) . '%';
        }
        if ( $has( 'customer_name' ) && (string) $filters['customer_name'] !== '' ) {
            $where[] = 'customer_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like( (string) $filters['customer_name'] ) . '%';
        }
        if ( (string) $filters['domain'] !== '' ) {
            $where[] = 'domain LIKE %s';
            $params[] = '%' . $wpdb->esc_like( (string) $filters['domain'] ) . '%';
        }
        if ( (string) $filters['expiry_date'] !== '' ) {
            $needle = (string) $filters['expiry_date'];
            $parsed = $this->normalize_ui_date_to_db( $needle );
            if ( $parsed !== '' ) { $needle = $parsed; }
            $where[] = 'expiry_date LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $needle ) . '%';
        }
        if ( (string) $filters['days_left'] !== '' ) {
            $where[] = 'days_left LIKE %s';
            $params[] = '%' . $wpdb->esc_like( (string) $filters['days_left'] ) . '%';
        }
        if ( (string) $filters['ownership'] !== '' ) {
            $where[] = 'ownership = %s';
            $params[] = (string) $filters['ownership'];
        }
        if ( (string) $filters['payment'] !== '' ) {
            $where[] = 'payment = %s';
            $params[] = (string) $filters['payment'];
        }

        $order_clause = ( $orderby === 'days_left' ) ? "days_left {$order}" : "{$orderby} {$order}, days_left ASC";

        $select_parts = array(
            'id',
            ( $has( 'client_name' ) ? 'client_name' : "'' AS client_name" ),
            ( $has( 'customer_number' ) ? 'customer_number' : "'' AS customer_number" ),
            ( $has( 'customer_name' ) ? 'customer_name' : "'' AS customer_name" ),
            'domain',
            'DATE(expiry_date) AS expiry_date',
            'days_left',
            ( $has( 'registrar' ) ? 'registrar' : "'' AS registrar" ),
            ( $has( 'ownership' ) ? 'ownership' : "'' AS ownership" ),
            ( $has( 'payment' ) ? 'payment' : "'' AS payment" ),
            ( $has( 'notes' ) ? 'notes' : "'' AS notes" ),
            ( $has( 'temp_text' ) ? 'temp_text' : "'' AS temp_text" ),
            ( $has( 'temp_text_enabled' ) ? 'temp_text_enabled' : '0 AS temp_text_enabled' ),
            ( $has( 'manual_expiry' ) ? 'manual_expiry' : '0 AS manual_expiry' ),
            ( $has( 'free_text_domain' ) ? 'free_text_domain' : '0 AS free_text_domain' ),
            ( $has( 'archived' ) ? 'archived' : '0 AS archived' ),
        );
        $select_sql = implode( ', ', $select_parts );

        $per_page = max( 1, (int) $per_page );
        $paged    = max( 1, (int) $paged );
        $offset   = ( $paged - 1 ) * $per_page;

        $where_for_select = $where;
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where_for_select );
        if ( ! empty( $params ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $params );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // Legacy fallback
        if ( $had_mapping_clause && $total === 0 ) {
            $where_for_select = array_values( array_filter( $where, function( $w ) use ( $mapping_clause ) {
                return $w !== $mapping_clause;
            } ) );

            $count_sql2 = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where_for_select );
            if ( ! empty( $params ) ) {
                $count_sql2 = $wpdb->prepare( $count_sql2, $params );
            }
            $total = (int) $wpdb->get_var( $count_sql2 );
        }

        $max_page = max( 1, (int) ceil( max( 0, $total ) / $per_page ) );
        if ( $paged > $max_page ) {
            $paged  = $max_page;
            $offset = ( $paged - 1 ) * $per_page;
        }

        $sql = "SELECT {$select_sql} FROM {$table} WHERE " . implode( ' AND ', $where_for_select ) . " ORDER BY {$order_clause} LIMIT %d OFFSET %d";
        $sql_params = array_merge( $params, array( $per_page, $offset ) );
        $sql = $wpdb->prepare( $sql, $sql_params );

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! empty( $wpdb->last_error ) ) {
            error_log( 'Expman domains query error: ' . $wpdb->last_error );
        }
        if ( empty( $rows ) ) { return array(); }

        foreach ( $rows as &$row ) {
            if ( ( empty( $row['days_left'] ) || $row['days_left'] === null ) && ! empty( $row['expiry_date'] ) ) {
                try {
                    $today = new DateTimeImmutable( 'today' );
                    $expiry = new DateTimeImmutable( (string) $row['expiry_date'] );
                    $row['days_left'] = (string) $today->diff( $expiry )->format( '%r%a' );
                } catch ( Exception $e ) {
                    $row['days_left'] = '';
                }
            }
        }
        unset( $row );

        return $rows;
    }

    private function get_summary_counts(): array {
        $this->ensure_schema();
        global $wpdb;
        $table = $this->get_table_name();
        $thresholds = $this->get_thresholds();

        // Summary only for active (deleted_at IS NULL) and not archived.
        $where_sql = "WHERE deleted_at IS NULL AND (archived = 0 OR archived IS NULL)";

        $counts = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) > %d THEN 1 ELSE 0 END) AS green_count,
                    SUM(CASE WHEN expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) BETWEEN %d AND %d THEN 1 ELSE 0 END) AS yellow_count,
                    SUM(CASE WHEN expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) <= %d THEN 1 ELSE 0 END) AS red_count,
                    COUNT(*) AS total_count
                 FROM {$table}
                 {$where_sql}",
                $thresholds['yellow'],
                $thresholds['red'] + 1,
                $thresholds['yellow'],
                $thresholds['red']
            ),
            ARRAY_A
        );

        return array(
            'green' => intval( $counts['green_count'] ?? 0 ),
            'yellow' => intval( $counts['yellow_count'] ?? 0 ),
            'red' => intval( $counts['red_count'] ?? 0 ),
            'total' => intval( $counts['total_count'] ?? 0 ),
            'yellow_threshold' => $thresholds['yellow'],
            'red_threshold' => $thresholds['red'],
        );
    }

    /* -------------------- WHOIS LOOKUP + SCAN -------------------- */

    private function whois_servers(): array {
        return array(
            'org.co.il'=>array('host'=>'whois.isoc.org.il','query'=>"%s"),
            'co.il'    =>array('host'=>'whois.isoc.org.il','query'=>"%s"),
            'il'       =>array('host'=>'whois.isoc.org.il','query'=>"%s"),
            'co.uk'    =>array('host'=>'whois.nic.uk','query'=>"%s"),
            'com'      =>array('host'=>'whois.verisign-grs.com','query'=>"%s"),
            'vip'      =>array('host'=>'whois.nic.vip','query'=>"%s"),
            'co'       =>array('host'=>'whois.nic.co','query'=>"%s"),
            'in'       =>array('host'=>'whois.registry.in','query'=>"%s"),
            'be'       =>array('host'=>'whois.dns.be','query'=>"%s"),
            'de'       =>array('host'=>'whois.denic.de','query'=>"-T dn,ace %s"),
            'ru'       =>array('host'=>'whois.tcinet.ru','query'=>"%s"),
            'biz'      =>array('host'=>'whois.nic.biz','query'=>"%s"),
            'info'     =>array('host'=>'whois.afilias.net','query'=>"%s"),
            'es'       =>array('host'=>'whois.nic.es','query'=>"%s"),
            'it'       =>array('host'=>'whois.nic.it','query'=>"%s"),
            'kz'       =>array('host'=>'whois.nic.kz','query'=>"%s"),
            'me'       =>array('host'=>'whois.nic.me','query'=>"%s"),
            'nl'       =>array('host'=>'whois.domain-registry.nl','query'=>"%s"),
            'pl'       =>array('host'=>'whois.dns.pl','query'=>"%s"),
        );
    }

    private function whois_lookup( string $domain ): ?array {
        $servers = $this->whois_servers();
        $target = null; $query = null;
        $lower = strtolower( $domain );

        foreach ( array_keys( $servers ) as $suffix ) {
            $needle = '.' . strtolower( $suffix );
            if ( $lower === strtolower( $suffix ) || substr( $lower, -strlen( $needle ) ) === $needle ) {
                $target = $servers[ $suffix ]['host'];
                $query  = sprintf( $servers[ $suffix ]['query'], $domain );
                break;
            }
        }
        if ( ! $target || ! $query ) { return null; }

        $fp = @fsockopen( $target, 43, $errno, $errstr, 10 );
        if ( ! $fp ) { return array( 'error' => 'connect_failed', 'server' => $target ); }

        stream_set_timeout( $fp, 12 );
        fwrite( $fp, $query . "\r\n" );
        $response = '';
        while ( ! feof( $fp ) ) {
            $response .= fgets( $fp, 1024 );
        }
        fclose( $fp );

        if ( $response === '' ) { return array( 'error' => 'empty_response', 'server' => $target ); }

        $exp = null; $reg = null;
        $patterns = array(
            '/expiry date:\s*(.+)/i',
            '/expiration date:\s*(.+)/i',
            '/expiration time:\s*(.+)/i',
            '/paid-till:\s*(.+)/i',
            '/paid till:\s*(.+)/i',
            '/free-date:\s*(.+)/i',
            '/expires:\s*(.+)/i',
            '/expire:\s*(.+)/i',
            '/validity:\s*(.+)/i',
        );
        foreach ( $patterns as $p ) {
            if ( preg_match( $p, $response, $m ) && ! empty( $m[1] ) ) {
                $exp = trim( $m[1] );
                break;
            }
        }
        if ( preg_match( '/Registrar:\s*(.+)/i', $response, $m ) && ! empty( $m[1] ) ) {
            $reg = trim( $m[1] );
        }

        $exp_gmt = null;
        if ( $exp ) {
            if ( preg_match( '/(\d{4}-\d{2}-\d{2})/', $exp, $m ) ) {
                $exp = $m[1];
            }
            $ts = strtotime( $exp );
            if ( $ts !== false ) { $exp_gmt = gmdate( 'Y-m-d H:i:s', $ts ); }
        }

        if ( $exp_gmt || $reg ) {
            return array( 'expiry_gmt' => $exp_gmt, 'registrar' => $reg, 'server' => $target, 'source' => 'whois' );
        }
        return array( 'error' => 'no_data', 'server' => $target );
    }

    private function scan_all_domains( int $single_id = 0, bool $force_manual = false ): array {
        $this->ensure_schema();
        global $wpdb;
        $table = $this->get_table_name();
        $now = current_time( 'mysql' );

        $where = array( 'deleted_at IS NULL' );
        $params = array();

        if ( $single_id > 0 ) {
            $where[] = 'id = %d';
            $params[] = $single_id;
        }

        $sql = "SELECT id, domain, manual_expiry, free_text_domain FROM `$table` WHERE " . implode( ' AND ', $where );
        if ( ! empty( $params ) ) { $sql = $wpdb->prepare( $sql, $params ); }

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( empty( $rows ) ) { return array( 'checked' => 0, 'ok' => 0, 'fail' => 0 ); }

        $checked = 0; $ok = 0; $fail = 0;

        foreach ( $rows as $r ) {
            $checked++;
            $id = (int) ( $r['id'] ?? 0 );
            $domain = trim( (string) ( $r['domain'] ?? '' ) );
            $manual_expiry = ! empty( $r['manual_expiry'] ) && (int) $r['manual_expiry'] === 1;
            $free_text = ! empty( $r['free_text_domain'] ) && (int) $r['free_text_domain'] === 1;

            // Skip WHOIS for manual domain names (free text) unless forced (not required by spec).
            if ( $free_text ) {
                $wpdb->update(
                    $table,
                    array(
                        'last_whois_checked_at' => $now,
                        'last_whois_server' => null,
                        'last_whois_error' => null,
                        'updated_at' => $now,
                    ),
                    array( 'id' => $id )
                );
                $ok++;
                continue;
            }

            if ( ! $this->is_english_domain( $domain ) ) {
                $wpdb->update(
                    $table,
                    array(
                        'last_whois_checked_at' => $now,
                        'last_whois_server' => null,
                        'last_whois_error' => 'invalid_domain',
                        'updated_at' => $now,
                    ),
                    array( 'id' => $id )
                );
                $fail++;
                continue;
            }

            $info = $this->whois_lookup( $domain );
            if ( ! is_array( $info ) ) {
                $wpdb->update(
                    $table,
                    array(
                        'last_whois_checked_at' => $now,
                        'last_whois_server' => null,
                        'last_whois_error' => 'lookup_failed',
                        'updated_at' => $now,
                    ),
                    array( 'id' => $id )
                );
                $fail++;
                continue;
            }

            if ( ! empty( $info['error'] ) ) {
                $wpdb->update(
                    $table,
                    array(
                        'last_whois_checked_at' => $now,
                        'last_whois_server' => (string) ( $info['server'] ?? '' ),
                        'last_whois_error' => (string) $info['error'],
                        'updated_at' => $now,
                    ),
                    array( 'id' => $id )
                );
                $fail++;
                continue;
            }

            $update = array(
                'last_whois_checked_at' => $now,
                'last_whois_server' => (string) ( $info['server'] ?? '' ),
                'last_whois_error' => null,
                'updated_at' => $now,
            );

            // Update registrar always when present
            if ( ! empty( $info['registrar'] ) ) {
                $update['registrar'] = sanitize_text_field( (string) $info['registrar'] );
            }

            // Do not override expiry if manual_expiry is set
            if ( ! $manual_expiry && ! empty( $info['expiry_gmt'] ) ) {
                $ts = strtotime( (string) $info['expiry_gmt'] );
                if ( $ts !== false ) {
                    $date_only = gmdate( 'Y-m-d', $ts );
                    $update['expiry_date'] = $date_only . ' 00:00:00';

                    try {
                        $today  = new DateTimeImmutable( 'today' );
                        $expiry = new DateTimeImmutable( $date_only );
                        $update['days_left'] = (int) $today->diff( $expiry )->format( '%r%a' );
                    } catch ( Exception $e ) {
                        // keep days_left as-is
                    }
                }
            }

            $wpdb->update( $table, $update, array( 'id' => $id ) );
            $ok++;
        }

        return array( 'checked' => $checked, 'ok' => $ok, 'fail' => $fail );
    }

    public function handle_check_all(): void {
        $this->require_manage_options();
        check_admin_referer( 'expman_domains_check_all' );

        $res = $this->scan_all_domains( 0, true );
        $msg = 'בדיקה הסתיימה. נבדקו: ' . (int) ( $res['checked'] ?? 0 ) . ', הצליחו: ' . (int) ( $res['ok'] ?? 0 ) . ', נכשלו: ' . (int) ( $res['fail'] ?? 0 );
        $type = ( (int) ( $res['fail'] ?? 0 ) > 0 ) ? 'error' : 'success';

        $this->flash_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=expman_domains' ), $msg, $type );
    }

    public function handle_check_one(): void {
        $this->require_manage_options();
        $id = intval( $_GET['id'] ?? 0 );
        if ( $id <= 0 ) {
            $this->flash_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=expman_domains' ), 'מזהה רשומה לא תקין.', 'error' );
        }
        check_admin_referer( 'expman_domain_check_now_' . $id );

        $res = $this->scan_all_domains( $id, true );
        $fail = (int) ( $res['fail'] ?? 0 );
        $msg = ( $fail > 0 ) ? 'בדיקת WHOIS נכשלה עבור הרשומה.' : 'בדיקת WHOIS בוצעה בהצלחה.';
        $type = ( $fail > 0 ) ? 'error' : 'success';
        $this->flash_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=expman_domains' ), $msg, $type );
    }

}
}
