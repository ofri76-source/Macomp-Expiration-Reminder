<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-expman-ssl-logs.php';

class Expman_SSLCerts_Page {

    const CERTS_TABLE = 'ssl_em_certificates';
    const QUEUE_TABLE = 'ssl_em_task_queue';
    const TOKENS_OPTION = 'ssl_em_api_token';

    private static function get_thresholds() {
        // UI thresholds (fixed as requested):
        // Red: <= 30 days, Yellow: 31-60 days, Green: >= 61 days
        return array( 60, 30 );
    }

    private static function render_tabs( $active_tab ) {
        // Client-side tabs (M365 behavior): no reload, instant switching.
        $tabs = array(
            'main'     => 'ראשי',
            'trash'    => 'סל מחזור',
            'settings' => 'הגדרות',
            'log'      => 'לוג',
        );

        echo '<div class="expman-domains-frontend-tabs" style="direction:rtl;text-align:right;margin:12px 0 16px;">';
        foreach ( $tabs as $key => $label ) {
            $is_active = ( $active_tab === $key );
            $cls = 'button expman-ssl-tab-btn' . ( $is_active ? ' button-primary is-active' : '' );
            echo '<button type="button" class="' . esc_attr( $cls ) . '" style="margin-left:6px;" data-tab="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</button>';
        }
        echo '</div>';
    }

    private static function render_summary_cards( $counts ) {
        static $css_done = false;
        if ( ! $css_done ) {
            echo '<style>

/* Customer dropdown (inline, RTL-safe) */
.expman-ssl-cust-wrap{ position:relative !important; overflow:visible !important; }
.expman-ssl-cust-dd{
  position:absolute !important;
  top: calc(100% + 6px) !important;
  right:0 !important;
  left:0 !important;
  z-index: 999999 !important;
  background:#fff !important;
  border:1px solid #d0d7de !important;
  border-radius:10px !important;
  box-shadow:0 10px 30px rgba(0,0,0,.12) !important;
  max-height:240px !important;
  overflow:auto !important;
  direction:rtl !important;
  text-align:right !important;
}
.expman-ssl-cust-dd .expman-ssl-cust-item{ padding:8px 10px; cursor:pointer; border-bottom:1px solid #eef2f6; }
.expman-ssl-cust-dd .expman-ssl-cust-item:hover{ background:#f6f9ff; }
            .expman-summary{display:flex;gap:12px;flex-wrap:wrap;align-items:stretch;margin:14px 0;}
            .expman-summary-card{flex:1 1 160px;border-radius:12px;padding:10px 12px;border:1px solid #d9e3f2;background:#fff;min-width:160px;cursor:pointer;text-align:right;}
            .expman-summary-card button{all:unset;cursor:pointer;display:block;width:100%;}
            .expman-summary-card h4{margin:0 0 6px;font-size:14px;color:#2b3f5c;}
            .expman-summary-card .count{display:inline-flex;align-items:center;justify-content:center;min-width:36px;padding:4px 10px;border-radius:999px;font-size:18px;font-weight:700;color:#183153;background:rgba(24,49,83,0.08);}
            .expman-summary-card.gray{background:#eef2f6;border-color:#d2dae6;}
            .expman-summary-card.green{background:#ecfbf4;border-color:#bfead4;}
            .expman-summary-card.yellow{background:#fff4e7;border-color:#ffd3a6;}
            .expman-summary-card.red{background:#ffecec;border-color:#f3b6b6;}
            .expman-summary-card.gray .count{background:#dde6f1;color:#2b3f5c;}
            .expman-summary-card.green .count{background:#c9f1dd;color:#1b5a39;}
            .expman-summary-card.yellow .count{background:#ffe2c6;color:#7a4c11;}
            .expman-summary-card.red .count{background:#ffd1d1;color:#7a1f1f;}
            .expman-summary-card[data-active="1"]{box-shadow:0 0 0 2px rgba(47,94,168,0.18);}
            .expman-summary-meta{margin-top:8px;padding:8px 12px;border-radius:10px;border:1px solid #d9e3f2;background:#f8fafc;font-weight:600;color:#2b3f5c;}
            .expman-summary-meta button{all:unset;cursor:pointer;}
              .expman-group-toggle{display:flex;align-items:center;gap:6px;justify-content:flex-start;white-space:nowrap;}
  .expman-group-toggle input{margin:0;}
  .expman-group-header td{background:#f7f9ff;font-weight:700;border-top:1px solid #d5dee8;}
  .expman-group-header[data-level="1"] td{padding-right:26px;}
  .expman-group-header[data-level="2"] td{padding-right:46px;}

/* Days coloring */
.expman-ssl-days{font-weight:700;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}


/* Type pill */
.expman-pill{display:inline-block;padding:3px 10px;border-radius:999px;color:#fff;font-weight:700;font-size:12px;line-height:18px;white-space:nowrap;}
/* Actions column */
.expman-ssl-table td:last-child{min-width:220px;white-space:normal;}
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;}
.expman-ssl-actions .button, .expman-ssl-actions a.button{margin:0 !important;}
.expman-btn-danger{background:#d63638 !important;border-color:#d63638 !important;color:#fff !important;}
.expman-btn-danger:hover{background:#b32d2e !important;border-color:#b32d2e !important;color:#fff !important;}


/* Days coloring */
.expman-ssl-days{font-weight:800;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}

/* Actions column */
.expman-ssl-table td:last-child{min-width:220px;white-space:normal;}
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;}
.expman-ssl-actions a.button,.expman-ssl-actions button.button{margin:0 !important;}
.expman-btn-danger{background:#d63638 !important;border-color:#d63638 !important;color:#fff !important;}
.expman-btn-danger:hover{background:#b32d2e !important;border-color:#b32d2e !important;color:#fff !important;}


/* Days coloring */
.expman-ssl-days{font-weight:800;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}

/* Actions column */
/* removed fixed min-width for last column */
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px;}
.expman-ssl-actions .button{margin:0 !important;}
.expman-btn-danger{background:#d63638;border-color:#d63638;color:#fff;}
.expman-btn-danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff;}


/* Days coloring */
.expman-ssl-days{font-weight:800;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}

/* Actions column */
/* removed fixed min-width for last column */
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px;}
.expman-ssl-actions .button{margin:0 !important;}
.expman-btn-danger{background:#d63638;border-color:#d63638;color:#fff;}
.expman-btn-danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff;}


/* Days coloring */
.expman-ssl-days{font-weight:800;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}

/* Actions column */
/* removed fixed min-width for last column */
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px;}
.expman-ssl-actions .button{margin:0 !important;}
.expman-btn-danger{background:#d63638;border-color:#d63638;color:#fff;}
.expman-btn-danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff;}


/* Days coloring */
.expman-ssl-days{font-weight:800;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}

/* Actions column */
/* removed fixed min-width for last column */
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px;}
.expman-ssl-actions .button{margin:0 !important;}
.expman-btn-danger{background:#d63638;border-color:#d63638;color:#fff;}
.expman-btn-danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff;}


/* --- Compact overrides (latest) --- */
.expman-ssl-table{table-layout:auto;}
.expman-ssl-table th, .expman-ssl-table td{padding:6px 8px;}
.expman-ssl-table th.col-actions, .expman-ssl-table td.col-actions{width:90px;min-width:90px;white-space:nowrap;}
.expman-ssl-table th.col-management, .expman-ssl-table td.col-management{width:80px;min-width:80px;white-space:nowrap;}
.expman-ssl-actions{gap:4px;flex-wrap:nowrap;justify-content:flex-start;}
.expman-ssl-actions .button.button-small{padding:0 8px;height:24px;line-height:22px;font-size:12px;}
.expman-ssl-table thead tr.expman-ssl-filter-row th{height:26px !important;padding:3px 4px !important;vertical-align:middle !important;border-top:6px solid #e9eff7;}
.expman-ssl-filter-row input,.expman-ssl-filter-row select{height:22px !important;line-height:20px !important;padding:0 4px !important;font-size:11px !important;box-sizing:border-box;}
.expman-group-toggle{display:flex;align-items:center;gap:6px;white-space:nowrap;}
.expman-col-controls{display:inline-flex;gap:2px;margin-right:6px;}
.expman-col-btn{width:18px;height:18px;line-height:16px;font-size:12px;padding:0;border-radius:6px;}


.expman-ssl-table th.col-actions, .expman-ssl-table td.col-actions{width:90px !important;white-space:nowrap !important;}
.expman-ssl-table th.col-management, .expman-ssl-table td.col-management{width:90px !important;white-space:nowrap !important;}

</style>';
            $css_done = true;
        }

        $red_label = 'מתחת ל-30 יום';
        $yellow_label = '31-60 יום';
        $green_label = 'מעל 61 יום';
        echo '<div class="expman-summary">';
        echo '<div class="expman-summary-card gray" data-expman-status="nodate" data-active="0"><button type="button"><h4>ללא תאריך</h4><div class="count">' . esc_html( $counts['nodate'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card green" data-expman-status="green" data-active="0"><button type="button"><h4>' . esc_html( $green_label ) . '</h4><div class="count">' . esc_html( $counts['green'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card yellow" data-expman-status="yellow" data-active="0"><button type="button"><h4>' . esc_html( $yellow_label ) . '</h4><div class="count">' . esc_html( $counts['yellow'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card red" data-expman-status="red" data-active="0"><button type="button"><h4>' . esc_html( $red_label ) . '</h4><div class="count">' . esc_html( $counts['red'] ) . '</div></button></div>';
        echo '</div>';
        echo '<div class="expman-summary-meta" data-expman-status="all"><button type="button">סה"כ רשומות: ' . esc_html( $counts['total'] ) . ' (בארכיון: ' . esc_html( $counts['archived'] ) . ')</button></div>';
    }

    private static function render_ssl_log_tab() {
        global $wpdb;

        echo '<div style="background:#fff;border:1px solid #d9e3f2;border-radius:14px;padding:14px 14px 10px;">';
        echo '<h3 style="margin:0 0 10px;">לוג תעודות אבטחה</h3>';

        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $level  = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
        $action = isset( $_GET['action_key'] ) ? sanitize_key( wp_unslash( $_GET['action_key'] ) ) : '';
        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
        $date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
        $per_page = isset( $_GET['per_page'] ) ? max( 10, min( 500, intval( $_GET['per_page'] ) ) ) : 50;
        $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

        // Actions list (distinct)
        $actions = array();
        $table = Expman_SSL_Logs::table_name();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists ) {
            $actions = $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action ASC" );
        }

        // Filters form
        echo '<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:12px;">';
        foreach ( array( 'expman_view', 'expman_tab' ) as $keep ) {
            if ( isset( $_GET[ $keep ] ) ) {
                echo '<input type="hidden" name="' . esc_attr( $keep ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET[ $keep ] ) ) ) . '">';
            }
        }
        echo '<input type="hidden" name="expman_tab" value="log">';

        echo '<div><label style="display:block;font-weight:700;">חיפוש</label><input type="text" name="s" value="' . esc_attr( $search ) . '" style="width:240px;height:34px;"></div>';

        echo '<div><label style="display:block;font-weight:700;">רמה</label><select name="level" style="width:140px;height:34px;">';
        $levels = array( '' => 'הכל', 'info' => 'info', 'warn' => 'warn', 'error' => 'error', 'debug' => 'debug' );
        foreach ( $levels as $k => $lbl ) {
            echo '<option value="' . esc_attr( $k ) . '"' . selected( $level, $k, false ) . '>' . esc_html( $lbl ) . '</option>';
        }
        echo '</select></div>';

        echo '<div><label style="display:block;font-weight:700;">פעולה</label><select name="action_key" style="width:180px;height:34px;">';
        echo '<option value="">הכל</option>';
        foreach ( (array) $actions as $a ) {
            $a = sanitize_key( (string) $a );
            echo '<option value="' . esc_attr( $a ) . '"' . selected( $action, $a, false ) . '>' . esc_html( $a ) . '</option>';
        }
        echo '</select></div>';

        echo '<div><label style="display:block;font-weight:700;">מתאריך</label><input type="date" name="date_from" value="' . esc_attr( $date_from ) . '" style="height:34px;"></div>';
        echo '<div><label style="display:block;font-weight:700;">עד תאריך</label><input type="date" name="date_to" value="' . esc_attr( $date_to ) . '" style="height:34px;"></div>';

        echo '<div><label style="display:block;font-weight:700;">לדף</label><select name="per_page" style="width:110px;height:34px;">';
        foreach ( array( 25, 50, 100, 200, 500 ) as $pp ) {
            echo '<option value="' . esc_attr( $pp ) . '"' . selected( $per_page, $pp, false ) . '>' . esc_html( $pp ) . '</option>';
        }
        echo '</select></div>';

        echo '<div><button class="button button-primary" type="submit">סנן</button></div>';
        echo '</form>';

        $res = Expman_SSL_Logs::query( array(
            'search' => $search,
            'level' => $level,
            'action' => $action,
            'date_from' => $date_from ? $date_from . ' 00:00:00' : '',
            'date_to' => $date_to ? $date_to . ' 23:59:59' : '',
            'paged' => $paged,
            'per_page' => $per_page,
        ) );


        echo '<div style="width:100%;overflow-x:auto;">';
        echo '<table class="widefat striped" style="width:100%;min-width:0;table-layout:fixed;direction:rtl;text-align:right;">';
        echo '<thead><tr>';
        echo '<th style="width:170px;">תאריך</th>';
        echo '<th style="width:80px;">רמה</th>';
        echo '<th style="width:160px;">פעולה</th>';
        echo '<th style="width:35%;">הודעה</th>';
        echo '<th style="width:35%;">Context</th>';
        echo '</tr></thead><tbody>';

        if ( empty( $res['rows'] ) ) {
            echo '<tr><td colspan="5" style="padding:16px;text-align:center;">אין לוגים</td></tr>';
        } else {
            foreach ( $res['rows'] as $r ) {
                $ctx = isset( $r['context'] ) ? (string) $r['context'] : '';
                $ctx_pre = $ctx !== '' ? esc_html( $ctx ) : '';
                echo '<tr>';
                echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
                echo '<td><code>' . esc_html( $r['level'] ) . '</code></td>';
                echo '<td><code>' . esc_html( $r['action'] ) . '</code></td>';
                echo '<td style="word-break:break-word;">' . esc_html( (string) $r['message'] ) . '</td>';
                echo '<td>';
                if ( $ctx_pre !== '' ) {
                    echo '<pre class="expman-ssl-log-context" style="white-space:pre-wrap;word-break:break-word;margin:0;max-width:none;">' . $ctx_pre . '</pre>';
                }
                echo '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';

        // Pagination
        $pages = max( 1, intval( $res['pages'] ) );
        if ( $pages > 1 ) {
            $base = remove_query_arg( array( 'paged' ) );
            echo '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:flex-end;margin-top:12px;">';
            for ( $p = 1; $p <= $pages; $p++ ) {
                $url = add_query_arg( array( 'paged' => $p ), $base );
                $cls = 'button' . ( $p === $paged ? ' button-primary' : '' );
                echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . intval( $p ) . '</a>';
            }
            echo '</div>';
        }

        echo '</div>';
    }

    private static function get_summary_counts( $status = 'publish' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::CERTS_TABLE;
        list( $yellow, $red ) = self::get_thresholds();

        // Performance: counts are used for UI cards and are requested frequently when switching tabs / refreshing.
        // Cache briefly to avoid repeated full-table scans.
        $cache_key = 'expman_ssl_summary_counts_' . md5( $table . '|' . $status . '|' . $yellow . '|' . $red );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $counts = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN (expiry_ts IS NULL OR expiry_ts=0) THEN 1 ELSE 0 END) AS nodate_count,
                    SUM(CASE WHEN (expiry_ts IS NOT NULL AND expiry_ts>0) AND DATEDIFF(FROM_UNIXTIME(expiry_ts), UTC_DATE()) > %d THEN 1 ELSE 0 END) AS green_count,
                    SUM(CASE WHEN (expiry_ts IS NOT NULL AND expiry_ts>0) AND DATEDIFF(FROM_UNIXTIME(expiry_ts), UTC_DATE()) BETWEEN %d AND %d THEN 1 ELSE 0 END) AS yellow_count,
                    SUM(CASE WHEN (expiry_ts IS NOT NULL AND expiry_ts>0) AND DATEDIFF(FROM_UNIXTIME(expiry_ts), UTC_DATE()) <= %d THEN 1 ELSE 0 END) AS red_count,
                    COUNT(*) AS total_count
                 FROM {$table}
                 WHERE status=%s",
                $yellow,
                $red + 1,
                $yellow,
                $red,
                $status
            ),
            ARRAY_A
        );

        $archived = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status=%s", 'trash' ) );

        $result = array(
            'nodate' => intval( $counts['nodate_count'] ?? 0 ),
            'green'  => intval( $counts['green_count'] ?? 0 ),
            'yellow' => intval( $counts['yellow_count'] ?? 0 ),
            'red'    => intval( $counts['red_count'] ?? 0 ),
            'total'  => intval( $counts['total_count'] ?? 0 ),
            'archived' => intval( $archived ),
            'yellow_threshold' => $yellow,
            'red_threshold'    => $red,
        );

        set_transient( $cache_key, $result, 30 );
        return $result;
    }

    public static function render() {
        $view = isset( $_GET['expman_view'] ) ? sanitize_key( $_GET['expman_view'] ) : 'new';
        $tab  = isset( $_GET['expman_tab'] ) ? sanitize_key( $_GET['expman_tab'] ) : 'main';
        if ( ! in_array( $tab, array( 'main', 'trash', 'settings', 'log' ), true ) ) { $tab = 'main'; }

        $option_key = ( defined( 'Expiry_Manager_Plugin::OPTION_KEY' ) ? Expiry_Manager_Plugin::OPTION_KEY : 'expman_settings' );

        // SSL logs retention (separate table)
        $settings = get_option( $option_key, array() );
        $keep_days = isset( $settings['ssl_log_keep_days'] ) ? intval( $settings['ssl_log_keep_days'] ) : 120;
        if ( $keep_days < 1 ) { $keep_days = 120; }
        // Performance: cleanup can be expensive on large log tables. Run at most once every 6 hours.
        static $ssl_logs_cleaned = false;
        if ( ! $ssl_logs_cleaned ) {
            $ssl_logs_cleaned = true;
            $cleanup_key = 'expman_ssl_logs_cleanup_v1';
            if ( ! get_transient( $cleanup_key ) ) {
                set_transient( $cleanup_key, 1, 6 * HOUR_IN_SECONDS );
                Expman_SSL_Logs::cleanup_older_than_days( $keep_days );
            }
        }

        // Legacy renderer (old plugin) - useful in parallel mode
        if ( $view === 'legacy' && shortcode_exists( 'ssl_cert_table' ) ) {
            ob_start();
            if ( class_exists( 'Expman_Nav' ) ) { Expman_Nav::render_public_nav( $option_key ); }
            echo '<div class="expman-wrap" style="max-width:1400px;margin:0 auto;">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:14px 0;">';
            echo '<h2 style="margin:0;">תעודות אבטחה</h2>';
            echo '<a class="button" href="' . esc_url( remove_query_arg( 'expman_view' ) ) . '">תצוגה חדשה</a>';
            echo '</div>';
            self::render_tabs( $tab );
            echo do_shortcode( '[ssl_cert_table]' );
            echo '</div>';
            return ob_get_clean();
        }

        global $wpdb;
        // Performance: avoid SHOW COLUMNS / ALTER checks on every page load.
        self::ensure_cert_snapshot_columns();
        $table = $wpdb->prefix . self::CERTS_TABLE;
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );

        ob_start();
        if ( class_exists( 'Expman_Nav' ) ) { Expman_Nav::render_public_nav( $option_key ); }

        echo '<div class="expman-wrap" style="max-width:1400px;margin:0 auto;direction:rtl;text-align:right;">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:14px 0;">';
        echo '<h2 style="margin:0;">תעודות אבטחה</h2>';

        if ( shortcode_exists( 'ssl_cert_table' ) ) {
            $legacy_url = add_query_arg( 'expman_view', 'legacy', remove_query_arg( 'expman_view' ) );
            echo '<a class="button" href="' . esc_url( $legacy_url ) . '">תצוגה ישנה</a>';
        }

        echo '</div>';

        self::render_tabs( $tab );

        if ( ! $exists ) {
            echo '<div class="notice notice-error" style="padding:12px;">';
            echo '<div><strong>לא נמצאה טבלת תעודות:</strong> ' . esc_html( $table ) . '</div>';
            echo '<div>במצב מקביל, ודא שהתוסף הישן של התעודות מופעל ויצר את הטבלאות.</div>';
            echo '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        // Notices
        if ( ! empty( $_GET['expman_ssl_action'] ) ) {
            $action = sanitize_key( (string) wp_unslash( $_GET['expman_ssl_action'] ) );
            $ok = ! empty( $_GET['expman_ssl_ok'] );
            $type = $ok ? 'success' : 'error';
            $msg_map = array(
                'trash'   => $ok ? 'הרשומה הועברה לסל מחזור.' : 'שגיאה בהעברה לסל מחזור.',
                'restore' => $ok ? 'הרשומה שוחזרה.' : 'שגיאה בשחזור.',
                'delete'  => $ok ? 'הרשומה נמחקה לצמיתות.' : 'שגיאה במחיקה לצמיתות.',
            );
            if ( isset( $msg_map[ $action ] ) ) {
                echo '<div class="notice notice-' . esc_attr( $type ) . '" style="padding:10px;margin:10px 0;">' . esc_html( $msg_map[ $action ] ) . '</div>';
            }
        }
        if ( ! empty( $_GET['expman_ssl_msg'] ) ) {
            $msg    = rawurldecode( (string) wp_unslash( $_GET['expman_ssl_msg'] ) );
            $status = isset( $_GET['expman_ssl_status'] ) ? sanitize_key( wp_unslash( $_GET['expman_ssl_status'] ) ) : 'info';
            $cls    = 'notice notice-info';
            if ( $status === 'success' ) { $cls = 'notice notice-success'; }
            if ( $status === 'error' ) { $cls = 'notice notice-error'; }
            echo '<div class="' . esc_attr( $cls ) . '" style="padding:10px;margin:10px 0;">' . esc_html( sanitize_text_field( $msg ) ) . '</div>';
        }
        // Settings tab content is rendered once and toggled client-side (M365 behavior)
        echo '<div id="expman-ssl-tab-settings" class="expman-ssl-tab-pane" style="display:none;">';
            // Settings tab (export/import)
          echo '<div style="background:transparent;border-radius:0;margin:0;max-width:none;width:100%;box-shadow:none;direction:rtl;text-align:right;">';
            echo '<h3 style="margin-top:0;">הגדרות</h3>';
            echo '<p style="margin:0 0 12px;">ייצוא/ייבוא של כל הרשומות וכל השדות (CSV – נפתח באקסל).</p>';

            $export_url = add_query_arg(
                array(
                    'action' => 'expman_ssl_export',
                    'expman_ssl_export_nonce' => wp_create_nonce( 'expman_ssl_export' ),
                    'redirect_to' => self::current_url(),
                ),
                admin_url( 'admin-ajax.php' )
            );
            echo '<a class="button button-primary" href="' . esc_url( $export_url ) . '">ייצוא לאקסל (CSV)</a>';

            echo '<hr style="margin:16px 0;">';
            echo '<h4 style="margin:0 0 10px;">ייבוא (CSV)</h4>';
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="expman_ssl_import">';
            echo '<input type="hidden" name="redirect_to" value="' . esc_attr( self::current_url() ) . '">';
            echo '<input type="hidden" name="expman_ssl_import_nonce" value="' . esc_attr( wp_create_nonce( 'expman_ssl_import' ) ) . '">';
            echo '<input type="file" name="expman_ssl_csv" accept=".csv" required style="margin:0 0 10px;">';
            echo '<div><button class="button" type="submit">ייבוא</button></div>';
            echo '</form>';
            echo '<p style="margin:12px 0 0;color:#5a6b82;">שמות העמודות חייבים להתאים לשמות השדות בטבלה (כמו בקובץ שמופק בייצוא).</p>';
            
            // Types colors
            self::get_type_names(); // seed types from existing records
            $type_rows = self::get_types_with_colors();
            echo '<hr style="margin:16px 0;">';
            echo '<h4 style="margin:0 0 10px;">סוגים וצבעים</h4>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="expman_ssl_update_type_colors">';
            echo '<input type="hidden" name="redirect_to" value="' . esc_attr( self::current_url() ) . '">';
            echo '<input type="hidden" name="expman_ssl_types_nonce" value="' . esc_attr( wp_create_nonce( 'expman_ssl_types' ) ) . '">';

            echo '<div style="display:grid;grid-template-columns:1fr 140px;gap:10px;max-width:520px;">';
            foreach ( (array) $type_rows as $tr ) {
                $tn = (string) ( $tr['name'] ?? '' );
                $tc = (string) ( $tr['color'] ?? '#2f5ea8' );
                echo '<div style="display:flex;align-items:center;gap:10px;">' . self::render_type_pill( $tn ) . '<span style="color:#5a6b82;">' . esc_html( $tn ) . '</span></div>';
                echo '<div><input type="hidden" name="type_name[]" value="' . esc_attr( $tn ) . '"><input type="color" name="type_color[]" value="' . esc_attr( $tc ) . '" style="width:100%;height:36px;"></div>';
            }
            echo '</div>';

            echo '<div style="margin-top:12px;"><button class="button button-primary" type="submit">שמור צבעים</button></div>';
            echo '</form>';

            // SSL logs settings
            $settings = get_option( $option_key, array() );
            $keep_days = isset( $settings['ssl_log_keep_days'] ) ? intval( $settings['ssl_log_keep_days'] ) : 120;
            if ( $keep_days < 1 ) { $keep_days = 120; }

            echo '<hr style="margin:16px 0;">';
            echo '<h4 style="margin:0 0 10px;">לוגים (תעודות אבטחה)</h4>';

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" style="max-width:520px;">';
            echo '<input type="hidden" name="action" value="expman_ssl_save_log_settings">';
            echo '<input type="hidden" name="redirect_to" value="' . esc_attr( self::current_url() ) . '">';
            echo '<div style="display:grid;grid-template-columns:1fr 160px;gap:10px;align-items:center;">';
            echo '<div><label style="font-weight:700;">כמה ימים לשמור לוגים</label><div style="color:#5a6b82;font-size:12px;">לדוגמה 120 – לוגים ישנים יותר יימחקו אוטומטית</div></div>';
            echo '<div><input type="number" min="1" max="3650" name="ssl_log_keep_days" value="' . esc_attr( $keep_days ) . '" style="width:100%;height:36px;"></div>';
            echo '</div>';
            echo '<div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">';
            echo '<button class="button button-primary" type="submit">שמור</button>';

            $clear_url = add_query_arg(
                array(
                    'action' => 'expman_ssl_clear_logs',
                    'redirect_to' => self::current_url(),
                ),
                admin_url( 'admin-ajax.php' )
            );
            echo '<a class="button expman-btn-danger" href="' . esc_url( $clear_url ) . '" onclick="return confirm(\'למחוק את כל לוגי תעודות אבטחה?\')">נקה LOG SSL</a>';

            $log_tab_url = add_query_arg( array( 'expman_tab' => 'log' ), remove_query_arg( array( 'paged' ) ) );
            echo '<a class="button" href="' . esc_url( $log_tab_url ) . '">פתח לוג</a>';
            echo '</div>';
            echo '</form>';

echo '</div>';

            echo '</div>';
            echo '</div>'; // expman-ssl-tab-settings

        // Log tab placeholder (loaded on demand via AJAX)
        echo '<div id="expman-ssl-tab-log" class="expman-ssl-tab-pane" style="display:none;">';
        echo '<div id="expman-ssl-log-container"></div>';
        echo '</div>';

        // List (main/trash) - rendered once and toggled client-side
        echo '<div id="expman-ssl-tab-list" class="expman-ssl-tab-pane">';

        // IMPORTANT: the SSL list uses *client-side* filtering (instant, like the M365 module).
        // We load both "main" and "trash" rows once, and JS toggles which dataset is visible.
        $initial_tab   = in_array( $tab, array( 'main', 'trash', 'settings', 'log' ), true ) ? $tab : 'main';
        $initial_group = ( $initial_tab === 'trash' ) ? 'trash' : 'main';

        // Summary cards (top) - pre-render both states and toggle instantly.
        $counts_main = self::get_summary_counts( 'publish' );
        // Backward-compat: some restored rows were stored as "active". Treat them as main.
        $counts_active = self::get_summary_counts( 'active' );
        foreach ( $counts_active as $k => $v ) {
            if ( ! isset( $counts_main[ $k ] ) ) { $counts_main[ $k ] = 0; }
            $counts_main[ $k ] += intval( $v );
        }

        echo '<div id="expman-ssl-summary-main">';
        self::render_summary_cards( $counts_main );
        echo '</div>';
        echo '<div id="expman-ssl-summary-trash" style="display:none;">';
        self::render_summary_cards( self::get_summary_counts( 'trash' ) );
        echo '</div>';

        // Client-side pagination uses this as the initial page size (no server paging).
        $per_page = isset( $_GET['per_page'] ) ? intval( $_GET['per_page'] ) : 25;
        if ( $per_page < 5 ) { $per_page = 5; }
        if ( $per_page > 1000 ) { $per_page = 1000; }

        // Load ALL rows (main + trash) once; JS toggles instantly between tabs.
        $sql = "SELECT *
                FROM {$table}
                WHERE status IN (%s,%s,%s)
                ORDER BY (CASE WHEN expiry_ts IS NULL OR expiry_ts=0 THEN 1 ELSE 0 END) ASC, expiry_ts ASC, id DESC";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, 'publish', 'active', 'trash' ), ARRAY_A );

        // Client-side controls (filter + grouping)
        


        echo '<style>
.expman-ssl-table thead tr:first-child th{background:#2f5fa3;color:#fff;font-weight:700;border:0;padding:12px 10px;height:48px;vertical-align:middle;}
.expman-ssl-table thead tr.expman-ssl-filter-row th{height:26px !important;padding:3px 4px !important;vertical-align:middle !important;border-top:6px solid #e9eff7;}
.expman-ssl-filter-row input,.expman-ssl-filter-row select{height:22px !important;line-height:20px !important;padding:0 4px !important;font-size:11px !important;box-sizing:border-box;}
.expman-ssl-filter-row th{vertical-align:middle;}
          .expman-ssl-table td,.expman-ssl-table th{vertical-align:middle;}
          .expman-ssl-detail{display:none;background:#fbfdff;}
          .expman-ssl-detail .expman-kv{display:grid;grid-template-columns:160px 1fr;gap:6px 12px;}
          .expman-ssl-detail .expman-kv div{padding:2px 0;}
          .expman-ssl-detail .expman-kv .k{font-weight:700;color:#2b3f5c;}
          .expman-group-header td{background:#eef3fb;font-weight:700;border-top:1px solid #d5dee8;}
          .expman-group-toggle{display:flex;align-items:center;gap:6px;justify-content:flex-start;white-space:nowrap;}
  .expman-group-toggle input{margin:0;}
  .expman-group-header td{background:#f7f9ff;font-weight:700;border-top:1px solid #d5dee8;}
  .expman-group-header[data-level="1"] td{padding-right:26px;}
  .expman-group-header[data-level="2"] td{padding-right:46px;}


/* Compact table + visible row boundaries */
.expman-ssl-table{border-collapse:collapse;width:100%;}
.expman-ssl-table th,.expman-ssl-table td{padding:4px 6px;font-size:11px;line-height:1.15;}
.expman-ssl-table thead tr:first-child th{height:34px;padding:6px 6px;font-size:12px;}
.expman-ssl-table thead tr.expman-ssl-filter-row th{height:26px !important;padding:3px 4px !important;vertical-align:middle !important;border-top:6px solid #e9eff7;}
.expman-ssl-filter-row input,.expman-ssl-filter-row select{height:22px !important;line-height:20px !important;padding:0 4px !important;font-size:11px !important;box-sizing:border-box;}
.expman-ssl-table tbody td{border-bottom:1px solid #d5dee8;}
.expman-ssl-table tbody tr.expman-ssl-row:hover td{background:#f6f9ff;}
.expman-ssl-table td:last-child{white-space:nowrap;}
.expman-ssl-table .button.button-small{padding:1px 6px;font-size:10px;line-height:1.15;min-height:0;height:auto;}
/* Group headers */
.expman-group-header td{border-bottom:1px solid #d5dee8;}
.expman-group-header .expman-group-btn{width:22px;height:22px;min-height:0;padding:0;border-radius:6px;border:1px solid #cbd7e7;background:#fff;cursor:pointer;font-weight:700;line-height:1;}
.expman-group-header .expman-group-title{margin-right:8px;}
/* Header controls (+ / -) near titles */
.expman-col-controls{display:inline-flex;gap:4px;margin-right:6px;}
.expman-col-btn{width:18px;height:18px;min-height:0;padding:0;border-radius:5px;border:1px solid rgba(255,255,255,0.55);background:rgba(255,255,255,0.18);color:#fff;cursor:pointer;font-weight:700;line-height:1;}
/* Sorting UX */
.expman-ssl-table thead tr:first-child th{user-select:none;}
.expman-ssl-table thead tr:first-child th.expman-sortable{cursor:pointer;}
.expman-sort-ind{display:inline-block;margin-right:6px;opacity:.9;font-size:11px;}


/* Days coloring */
.expman-ssl-days{font-weight:800;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}

/* Actions column */
/* removed fixed min-width for last column */
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px;}
.expman-ssl-actions .button{margin:0 !important;}
.expman-btn-danger{background:#d63638;border-color:#d63638;color:#fff;}
.expman-btn-danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff;}


/* Days coloring */
.expman-ssl-days{font-weight:800;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}

/* Actions column */
/* removed fixed min-width for last column */
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px;}
.expman-ssl-actions .button{margin:0 !important;}
.expman-btn-danger{background:#d63638;border-color:#d63638;color:#fff;}
.expman-btn-danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff;}

/* Days coloring */
.expman-ssl-days{font-weight:800;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}

/* Actions column */
/* removed fixed min-width for last column */
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px;}
.expman-ssl-actions .button{margin:0 !important;}
.expman-btn-danger{background:#d63638;border-color:#d63638;color:#fff;}
.expman-btn-danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff;}

/* Days coloring */
.expman-ssl-days{font-weight:800;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}

/* Actions column */
/* removed fixed min-width for last column */
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px;}
.expman-ssl-actions .button{margin:0 !important;}
.expman-btn-danger{background:#d63638;border-color:#d63638;color:#fff;}
.expman-btn-danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff;}

/* Days coloring */
.expman-ssl-days{font-weight:800;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}

/* Actions column */
/* removed fixed min-width for last column */
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px;}
.expman-ssl-actions .button{margin:0 !important;}
.expman-btn-danger{background:#d63638;border-color:#d63638;color:#fff;}
.expman-btn-danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff;}

/* Days coloring */
.expman-ssl-days{font-weight:800;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}

/* Actions column */
/* removed fixed min-width for last column */
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px;}
.expman-ssl-actions .button{margin:0 !important;}
.expman-btn-danger{background:#d63638;border-color:#d63638;color:#fff;}
.expman-btn-danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff;}

/* Days coloring */
.expman-ssl-days{font-weight:800;}
.expman-ssl-row[data-expman-status="red"] td.expman-ssl-days{color:#b42318;}
.expman-ssl-row[data-expman-status="yellow"] td.expman-ssl-days{color:#7a4c11;}
.expman-ssl-row[data-expman-status="green"] td.expman-ssl-days{color:#1b5a39;}
.expman-ssl-row[data-expman-status="nodate"] td.expman-ssl-days{color:#5a6b82;}

/* Actions column */
/* removed fixed min-width for last column */
.expman-ssl-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px;}
.expman-ssl-actions .button{margin:0 !important;}
.expman-btn-danger{background:#d63638;border-color:#d63638;color:#fff;}
.expman-btn-danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff;}


.expman-ssl-table th.col-actions, .expman-ssl-table td.col-actions{width:90px !important;white-space:nowrap !important;}
.expman-ssl-table th.col-management, .expman-ssl-table td.col-management{width:90px !important;white-space:nowrap !important;}

</style>';

        
        // Lookup options for the Add/Edit panel (must appear near the top of the view)
        $mgmt_options = self::get_management_options();
        $type_names   = self::get_type_names();

        // Top controls (above table header)
        // Build URL from plugin main file to avoid wrong base when called from nested SSL folder.
        $cyberssl_logo_url = plugins_url( '../assets/cyberssl.png', __FILE__ );

        echo '<div id="expman-ssl-top-controls" class="expman-ssl-topbar" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:10px 0 8px 0;">';
          echo '<div style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;">';
            echo '<div style="display:flex;flex-direction:column;gap:6px;">';
              echo '<a href="https://www.cyberssl.com/account/orders" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;padding:4px 10px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;line-height:1;">'
                 . '<img src="' . esc_url( $cyberssl_logo_url ) . '" alt="CYBERSSL" style="height:26px;width:auto;display:block;">'
                 . '</a>';
              // Use both JS listener + inline onclick for maximum reliability.
              echo '<button type="button" class="button button-primary" id="expman-ssl-add-new" onclick="if(window.expmanSslOpenAdd){window.expmanSslOpenAdd();}">הוספה</button>';
            echo '</div>';
          echo '</div>';

          echo '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
            // Client-side paging (instant) – no form submit / reload.
            echo '<label style="display:flex;align-items:center;gap:6px;font-weight:700;">לדף';
            echo '<select id="expman-ssl-per-page" style="min-width:110px;height:36px;">';
              $pp_opts = array( 25, 50, 100, 200, 500, 1000 );
              foreach ( $pp_opts as $pp ) {
                echo '<option value="' . esc_attr( (string) $pp ) . '"' . selected( $per_page, $pp, false ) . '>' . esc_html( (string) $pp ) . '</option>';
              }
            echo '</select>';
            echo '</label>';
            echo '<div id="expman-ssl-pager" style="display:flex;align-items:center;gap:6px;">'
              . '<button type="button" class="button" id="expman-ssl-page-prev">&lsaquo;</button>'
              . '<span id="expman-ssl-page-info" style="min-width:140px;text-align:center;"></span>'
              . '<button type="button" class="button" id="expman-ssl-page-next">&rsaquo;</button>'
              . '</div>';
          echo '</div>';
        echo '</div>';

        // Add/Edit form panel (inline, top of page)
        self::render_ssl_form_panel( $mgmt_options, $type_names );

echo '<div class="expman-table-wrap" style="overflow:auto;border:1px solid #d5dee8;border-radius:10px;background:#fff;">';
        echo '<table class="widefat striped expman-ssl-table" style="min-width:900px;border:0;">';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="width:42px;"><input type="checkbox" id="expman-ssl-check-all" aria-label="בחר הכל"></th>';
        echo '<th><label class="expman-group-toggle"><input type="checkbox" class="expman-ssl-groupchk" data-col="client_name" aria-label="קיבוץ לפי שם הלקוח"><span>שם הלקוח</span></label></th>';

        echo '<th><label class="expman-group-toggle"><input type="checkbox" class="expman-ssl-groupchk" data-col="site_url" aria-label="קיבוץ לפי URL"><span>URL</span></label></th>';

        echo '<th><label class="expman-group-toggle"><input type="checkbox" class="expman-ssl-groupchk" data-col="common_name" aria-label="קיבוץ לפי CN"><span>CN</span></label></th>';

        echo '<th><label class="expman-group-toggle"><input type="checkbox" class="expman-ssl-groupchk" data-col="expiry_date" aria-label="קיבוץ לפי תאריך תפוגה"><span>תאריך תפוגה</span></label></th>';

        echo '<th style="width:70px;"><label class="expman-group-toggle"><input type="checkbox" class="expman-ssl-groupchk" data-col="days_left" aria-label="קיבוץ לפי ימים"><span>ימים</span></label></th>';

        echo '<th><label class="expman-group-toggle"><input type="checkbox" class="expman-ssl-groupchk" data-col="cert_type" aria-label="קיבוץ לפי סוג"><span>סוג</span></label></th>';

        echo '<th class="col-management"><label class="expman-group-toggle"><input type="checkbox" class="expman-ssl-groupchk" data-col="management_owner" aria-label="קיבוץ לפי ניהול"><span>ניהול</span></label></th>';
        echo '<th class="col-actions">פעולות</th>';

        echo '</tr>';

        // Filter row (client-side, under the header like other modules)
        echo '<tr class="expman-ssl-filter-row">';
        // Checkbox column (no filter)
        echo '<th></th>';
        echo '<th><input class="expman-ssl-colfilter" data-col="client_name" type="text" placeholder="סינון..." value="" style="height:18px;font-size:11px;padding:2px 6px;box-sizing:border-box;width:100%;" /></th>';
        echo '<th><input class="expman-ssl-colfilter" data-col="site_url" type="text" placeholder="סינון..." value="" style="height:18px;font-size:11px;padding:2px 6px;box-sizing:border-box;width:100%;" /></th>';
        echo '<th><input class="expman-ssl-colfilter" data-col="common_name" type="text" placeholder="סינון..." value="" style="height:18px;font-size:11px;padding:2px 6px;box-sizing:border-box;width:100%;" /></th>';
        echo '<th><input class="expman-ssl-colfilter" data-col="expiry_ts" type="text" placeholder="dd-mm-yyyy" value="" style="height:18px;font-size:11px;padding:2px 6px;box-sizing:border-box;width:100%;" /></th>';
        echo '<th><input class="expman-ssl-colfilter" data-col="days_left" type="text" placeholder="ימים" value="" style="height:18px;font-size:11px;padding:2px 6px;box-sizing:border-box;width:100%;" /></th>';
        echo '<th><input class="expman-ssl-colfilter" data-col="cert_type" type="text" placeholder="סינון..." value="" style="height:18px;font-size:11px;padding:2px 6px;box-sizing:border-box;width:100%;" /></th>';
        echo '<th><input class="expman-ssl-colfilter" data-col="management_owner" type="text" placeholder="סינון..." value="" style="height:18px;font-size:11px;padding:2px 6px;box-sizing:border-box;width:100%;" /></th>';
        // Actions column: clear button on the LEFT (in RTL tables this is the last th)
        echo '<th style="white-space:nowrap;"><button type="button" class="button" id="expman-ssl-clear-filters" style="height:18px;line-height:16px;padding:0 8px;font-size:11px;">נקה</button></th>';
        echo '</tr>';
        echo '</thead><tbody id="expman-ssl-body">';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="9" style="padding:14px;">אין נתונים</td></tr>';
        } else {
            $now = time();
            $js_rows = array();
            foreach ( $rows as $r ) {
                $expiry_ts = isset( $r['expiry_ts'] ) ? intval( $r['expiry_ts'] ) : 0;
                $expiry_sort = $expiry_ts > 0 ? gmdate( 'Y-m-d', $expiry_ts ) : '';
                $expiry_display = $expiry_ts > 0 ? gmdate( 'd-m-Y', $expiry_ts ) . '' : '';
                $days_left = $expiry_ts > 0 ? intval( floor( ($expiry_ts - $now) / 86400 ) ) : '';
                $site = (string)($r['site_url'] ?? '');
                $site_html = $site ? '<a href="' . esc_url( $site ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( self::shorten( $site, 64 ) ) . '</a>' : '';

                $post_id = intval( $r['post_id'] ?? 0 );
                $record_status = isset( $r['status'] ) ? (string) $r['status'] : 'publish';
                $record_group  = ( $record_status === 'trash' ) ? 'trash' : 'main';
                $can_check = $post_id > 0 && ! intval( $r['manual_mode'] ?? 0 );
                $check_link = '';
                if ( $can_check ) {
                    $check_link = add_query_arg( array(
                        'action' => 'expman_ssl_single_check',
                        'post_id' => $post_id,
                        'redirect_to' => self::current_url(),
                    ), admin_url( 'admin-ajax.php' ) );
                }

                // Row status for the summary cards filter
                $row_status = 'nodate';
                if ( $expiry_ts > 0 ) {
                    list( $yellow, $red ) = self::get_thresholds();
                    if ( $days_left <= $red ) { $row_status = 'red'; }
                    elseif ( $days_left <= $yellow ) { $row_status = 'yellow'; }
                    else { $row_status = 'green'; }
                }
                $days_style = ( $row_status === 'red' ? 'color:#b42318;font-weight:800;' : ( $row_status === 'yellow' ? 'color:#7a4c11;font-weight:800;' : ( $row_status === 'green' ? 'color:#1b5a39;font-weight:800;' : 'color:#5a6b82;font-weight:700;' ) ) );

                // Build a searchable blob from fields (skip very large blobs)
                $search_blob = '';
                foreach ( $r as $k => $v ) {
                    if ( $v === null || $v === '' ) { continue; }
                    if ( in_array( $k, array( 'images', 'last_error', 'notes', 'temporary_note' ), true ) ) { continue; }
                    if ( is_scalar( $v ) ) {
                        $sv = (string) $v;
                        if ( mb_strlen( $sv, 'UTF-8' ) > 300 ) { continue; }
                        $search_blob .= ' ' . $k . ':' . $sv;
                    }
                }
                $search_blob = trim( $search_blob );

                $row_id = intval( $r['id'] );

                echo '<tr class="expman-ssl-row" data-row-id="' . esc_attr( (string) $row_id ) . '" data-record-group="' . esc_attr( $record_group ) . '" data-expman-status="' . esc_attr( $row_status ) . '" data-search="' . esc_attr( mb_strtolower( $search_blob, 'UTF-8' ) ) . '"'
                    . ' data-client_name="' . esc_attr( (string)($r['client_name'] ?? '') ) . '"'
                    . ' data-site_url="' . esc_attr( (string)($r['site_url'] ?? '') ) . '"'
                    . ' data-common_name="' . esc_attr( (string)($r['common_name'] ?? '') ) . '"'
	                    . ' data-expiry_date="' . esc_attr( $expiry_sort ) . '"'	                    . ' data-expiry_display="' . esc_attr( $expiry_display ) . '"'
                    . ' data-days_left="' . esc_attr( (string)$days_left ) . '"'
                    . ' data-cert_type="' . esc_attr( (string)($r['cert_type'] ?? '') ) . '"'
                    . ' data-management_owner="' . esc_attr( (string)($r['management_owner'] ?? '') ) . '"'
                    . ' data-status="' . esc_attr( (string)$row_status ) . '">';
                $r['status_color'] = $row_status;
                $js_rows[ $row_id ] = $r;

                echo '<td><input class="expman-ssl-check" type="checkbox" value="' . esc_attr( (string) $row_id ) . '" aria-label="בחר רשומה"></td>';
                echo '<td>' . esc_html( (string)($r['client_name'] ?? '') ) . '</td>';
                echo '<td>' . $site_html . '</td>';
                echo '<td>' . esc_html( (string)($r['common_name'] ?? '') ) . '</td>';
                echo '<td>' . esc_html( $expiry_display ) . '</td>';
                echo '<td class="expman-ssl-days" style="' . esc_attr( $days_style ) . '">' . esc_html( (string)$days_left ) . '</td>';
                echo '<td>' . self::render_type_pill( (string)($r['cert_type'] ?? '') ) . '</td>';
                echo '<td class="col-management">';
                $owner = (string) ( $r['management_owner'] ?? '' );
                $owner_label = trim( $owner );
                $owner_key = strtolower( $owner_label );
                if ( $owner_key === 'ours' || $owner_label === 'שלנו' ) { $owner_label = 'שלנו'; }
                elseif ( $owner_key === 'customer' || $owner_key === 'client' || $owner_label === 'של הלקוח' ) { $owner_label = 'של הלקוח'; }
                echo esc_html( $owner_label );
                echo '</td>';

                echo '<td class="col-actions">';
                echo '<div class="expman-ssl-actions" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">';
                echo '<button type="button" class="button button-small expman-ssl-edit" data-row-id="' . esc_attr( (string) $row_id ) . '" onclick="if(window.expmanSslOpenEdit){window.expmanSslOpenEdit(\'' . esc_attr( (string) $row_id ) . '\');}">ערוך</button>';

                $restore_link = '';
                $delete_link = '';

                if ( $record_group === 'trash' ) {
                    // Trash tab actions
                    $restore_link = add_query_arg( array(
                        'action' => 'expman_ssl_restore',
                        'post_id' => $post_id,
                        'row_id'  => $row_id,
                        'redirect_to' => self::current_url(),
                        '_wpnonce' => wp_create_nonce( 'expman_ssl_restore_' . $row_id ),
                    ), admin_url( 'admin-ajax.php' ) );

                    $delete_link = add_query_arg( array(
                        'action' => 'expman_ssl_delete_permanent',
                        'post_id' => $post_id,
                        'row_id'  => $row_id,
                        'redirect_to' => self::current_url(),
                        '_wpnonce' => wp_create_nonce( 'expman_ssl_delete_permanent_' . $row_id ),
                    ), admin_url( 'admin-ajax.php' ) );
                }

                if ( $check_link ) {
                    echo '<a class="button button-small" href="' . esc_url( $check_link ) . '">בדיקה</a>';
                }

                if ( $record_group === 'trash' ) {
                    if ( $restore_link ) {
                        echo '<a class="button button-small" href="' . esc_url( $restore_link ) . '">שחזר</a>';
                    }
                    if ( $delete_link ) {
                        echo '<a class="button button-small button-danger" onclick="return confirm(\'למחוק לצמיתות?\')" href="' . esc_url( $delete_link ) . '">מחק לצמיתות</a>';
                    }
                }

                if ( ! $check_link && $record_group !== 'trash' ) {
                    echo '<span style="opacity:0.6;">—</span>';
                }

                echo '</div>';
                echo '</td>';
                echo '</tr>';

                // Details row (all fields from the old plugin table)
                echo '<tr class="expman-ssl-detail" data-detail-for="' . esc_attr( (string) $row_id ) . '"><td colspan="9" style="padding:12px 14px;">';
                echo '<div class="expman-kv">';
                $detail_fields = array(
                    'id' => 'ID',
                    'post_id' => 'Post ID',
                    'client_name' => 'שם הלקוח',
                    'site_url' => 'אתר',
                    'common_name' => 'CN',
                    'issuer_name' => 'Issuer',
                    'expiry_ts' => 'Expiry TS',
                    'expiry_ts_checked_at' => 'Checked At',
                    'source' => 'Source',
                    'cert_type' => 'סוג',
                    'management_owner' => 'ניהול',
                    'manual_mode' => 'Manual Mode',
                    'allow_duplicate_site' => 'Allow Duplicate Site',
                    'follow_up' => 'Follow Up',
                    'notes' => 'הערה',
                    'temporary_enabled' => 'הערה זמנית פעילה',
                    'temporary_note' => 'הערה זמנית',
                    'guide_url' => 'Guide URL',
                    'agent_token' => 'Agent Token',
                    'last_error' => 'Last Error',
                    'images' => 'Images',
                    'created_at' => 'Created At',
                    'updated_at' => 'Updated At',
                    'status' => 'Status',
                );

                foreach ( $detail_fields as $key => $label ) {
                    $val = $r[ $key ] ?? '';
                    if ( $key === 'expiry_ts' ) {
                        $val = ( intval( $val ) > 0 ) ? (string) intval( $val ) . ' (' . gmdate( 'd-m-Y', intval( $val ) ) . ')' : '';
                    }
                    if ( $key === 'expiry_ts_checked_at' ) {
                        $val = ( intval( $val ) > 0 ) ? (string) intval( $val ) . ' (' . gmdate( 'd-m-Y H:i', intval( $val ) ) . ')' : '';
                    }
                    if ( is_bool( $val ) ) { $val = $val ? '1' : '0'; }
                    if ( is_scalar( $val ) ) {
                        $val = (string) $val;
                    } else {
                        $val = wp_json_encode( $val );
                    }
                    echo '<div class="k">' . esc_html( $label ) . '</div><div class="v">' . esc_html( $val ) . '</div>';
                }

                // Any extra columns returned from SQL but not in map
                foreach ( $r as $k => $v ) {
                    if ( isset( $detail_fields[ $k ] ) ) { continue; }
                    if ( $v === null || $v === '' ) { continue; }
                    $vv = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
                    echo '<div class="k">' . esc_html( $k ) . '</div><div class="v">' . esc_html( $vv ) . '</div>';
                }

                echo '</div>';
                echo '</td></tr>';
            }
        }

        echo '</tbody></table></div>';

        // NOTE: server-side pagination removed; JS paginates instantly.
        echo "<script type=\"application/json\" id=\"expman-ssl-rowdata-json\">" . wp_json_encode( $js_rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . "</script>";
        // Config for external UI JS (customer-search uses the same proven endpoint as Firewalls module).
        $cfg = array(
            'ajaxurl'        => admin_url( 'admin-ajax.php' ),
            'customer_nonce' => wp_create_nonce( 'expman_customer_search' ),
            'initial_tab'    => $initial_tab,
            'log_nonce'      => wp_create_nonce( 'expman_ssl_render_logs' ),
        );
        echo '<script>window.expmanSslCfg = window.expmanSslCfg || {};' .
            'window.expmanSslCfg.ajaxurl = ' . wp_json_encode( $cfg['ajaxurl'] ) . ';' .
            'window.expmanSslCfg.customer_nonce = ' . wp_json_encode( $cfg['customer_nonce'] ) . ';' .
            'window.expmanSslCfg.initial_tab = ' . wp_json_encode( $cfg['initial_tab'] ) . ';' .
            'window.expmanSslCfg.log_nonce = ' . wp_json_encode( $cfg['log_nonce'] ) . ';' .
            '</script>';
        // Load SSL UI logic from an external script (prevents inline-script parse issues).
        $plugin_file = dirname( __FILE__, 4 ) . '/Macomp-Expiration-Reminder.php';
        $src = plugins_url( 'assets/expman-ssl-ui.js', $plugin_file );
        echo '<script src="' . esc_url( $src ) . '"></script>';

        echo '</div>'; // expman-ssl-tab-list

        echo '</div>';

        return ob_get_clean();
    }


    private static function render_ssl_form_panel( $mgmt_options, $type_names ) {
        // Inline form panel (no popup) - fully self-styled (do not rely on theme/admin styles)
        $ajax_url = admin_url( 'admin-ajax.php' );

        $plugin_file = dirname( __FILE__, 4 ) . '/Macomp-Expiration-Reminder.php';
        $css = plugins_url( 'assets/expman-ssl-form.css', $plugin_file );
        echo '<link rel="stylesheet" href="' . esc_url( $css ) . '">';

        echo '<div id="expman-ssl-modal" class="expman-ssl-form" style="display:none;">';
        echo '<div class="expman-ssl-form__header">';
        echo '<div class="expman-ssl-form__title" data-expman-modal-title>הוספת רשומה חדשה</div>';
        echo '<button type="button" id="expman-ssl-modal-close" class="expman-ui-btn expman-ui-btn--ghost">סגור</button>';
        echo '</div>';

        echo '<div id="expman-ssl-form-msg" class="expman-ssl-form__msg"></div>';

        echo '<form id="expman-ssl-modal-form" method="post" enctype="multipart/form-data" action="' . esc_url( $ajax_url ) . '">';
        echo '<input type="hidden" name="action" value="expman_ssl_save_record">';
        echo '<input type="hidden" name="redirect_to" value="' . esc_attr( self::current_url() ) . '">';
        echo '<input type="hidden" name="row_id" value="">';
        echo '<input type="hidden" name="post_id" value="">';
        echo wp_nonce_field( 'expman_ssl_save_record', '_wpnonce', true, false );

        // datalist for types (keeps ability to type new values)
        echo '<datalist id="expman-ssl-type-list">';
        foreach ( $type_names as $tn ) {
            echo '<option value="' . esc_attr( $tn ) . '"></option>';
        }
        echo '</datalist>';

        echo '<div class="expman-ssl-form__grid">';

        // Column A (left) - management / meta
        echo '<div class="expman-ssl-form__col expman-ssl-form__col--a">';
        echo '<div class="expman-ssl-form__row"><div class="expman-ssl-form__label">סוג:</div><div class="expman-ssl-form__control"><input name="cert_type" list="expman-ssl-type-list" type="text" placeholder="בחר/הקלד סוג"></div></div>';

        echo '<div class="expman-ssl-form__row"><div class="expman-ssl-form__label">ניהול:</div><div class="expman-ssl-form__control expman-ssl-form__control--stack"><select name="management_owner">';
        foreach ( $mgmt_options as $opt ) {
            echo '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
        }
        echo '</select>'
            . '<button type="button" id="expman-ssl-fill-admin-email" class="expman-ui-btn expman-ui-btn--ghost">מלא admin@macomp.co.il</button>'
            . '</div></div>';

        echo '<div class="expman-ssl-form__row"><div class="expman-ssl-form__label">קישור למדריך:</div><div class="expman-ssl-form__control"><input name="guide_url" type="text" placeholder="https://help.example.com"></div></div>';
        echo '<div class="expman-ssl-form__row"><div class="expman-ssl-form__label">מייל Admin:</div><div class="expman-ssl-form__control"><input name="admin_email" type="text" placeholder="admin@macomp.co.il"></div></div>';
        echo '<div class="expman-ssl-form__row"><div class="expman-ssl-form__label">Admin:</div><div class="expman-ssl-form__control"><input name="admin_contact_name" type="text" placeholder="שם איש קשר"></div></div>';
        echo '<div class="expman-ssl-form__row"><div class="expman-ssl-form__label">AGENT:</div><div class="expman-ssl-form__control"><input name="agent_token" type="text"></div></div>';
        echo '</div>';

        // Column B (middle) - certificate fields
        echo '<div class="expman-ssl-form__col expman-ssl-form__col--b">';
        echo '<div class="expman-ssl-form__row"><div class="expman-ssl-form__label">אתר (URL):</div><div class="expman-ssl-form__control"><input name="site_url" type="text" placeholder="https://domain.vip:10443/ או domain.vip"></div></div>';
        echo '<div class="expman-ssl-form__row"><div class="expman-ssl-form__label">CN של התעודה:</div><div class="expman-ssl-form__control"><input name="common_name" type="text"></div></div>';
        echo '<div class="expman-ssl-form__row"><div class="expman-ssl-form__label">תאריך תפוגה:</div><div class="expman-ssl-form__control"><div class="expman-ssl-form__inline">'
            . '<input id="expman-ssl-expiry-date" name="expiry_date" type="text" placeholder="dd-mm-yyyy">'
            . '<button type="button" id="expman-ssl-add-year" class="expman-ui-btn expman-ui-btn--ghost" style="display:none;">+שנה</button>'
            . '</div></div></div>';
        echo '<div class="expman-ssl-form__row"><div class="expman-ssl-form__label">Issuer:</div><div class="expman-ssl-form__control"><input name="issuer_name" type="text"></div></div>';

        echo '<div class="expman-ssl-form__checks expman-ssl-form__checks--compact">';
        echo '<label class="expman-ssl-form__check"><input type="checkbox" name="manual_mode" value="1">ידני (ללא בדיקות אוטומטיות)</label>';
        echo '<label class="expman-ssl-form__check"><input type="checkbox" name="temporary_enabled" value="1">הפעל טקסט זמני</label>';
        echo '</div>';
        echo '</div>';

        // Customer box (right)
        echo '<div class="expman-ssl-form__customerbox">';
        echo '<div class="expman-ssl-form__custrow"><div class="expman-ssl-form__label">חיפוש לקוח:</div>'
        . '<div class="expman-ssl-form__control">'
        . '<input id="expman-ssl-customer-search" class="expman-customer-search" type="text" autocomplete="off" placeholder="התחל להקליד שם או מספר לקוח">'
        . '<div id="expman-ssl-cust-dd-inline" class="expman-ssl-cust-dd" style="display:none;"></div>'
        . '</div></div>';
        echo '<div class="expman-ssl-form__custrow"><div class="expman-ssl-form__label">מספר לקוח:</div><div class="expman-ssl-form__control"><input id="expman-ssl-customer-number" name="customer_number_snapshot" type="text" placeholder=""></div></div>';
        echo '<div class="expman-ssl-form__custrow"><div class="expman-ssl-form__label">שם הלקוח:</div><div class="expman-ssl-form__control"><input id="expman-ssl-customer-name" name="client_name" type="text" readonly></div></div>';
        echo '<div class="expman-ssl-form__hint">הערה: חיפוש לקוח ממלא אוטומטית שם+מספר.</div>';
        echo '</div>';

        echo '<div class="expman-ssl-form__widegrid">';
        echo '<div class="expman-ssl-form__widecol">';
        echo '<div class="expman-ssl-form__sectiontitle">טקסט זמני</div>';
        echo '<textarea name="temporary_note" rows="6"></textarea>';
        echo '<div class="expman-ssl-form__hint">הטקסט מוצג כל עוד התעודה אינה ירוקה.</div>';
        echo '</div>';

        echo '<div class="expman-ssl-form__widecol">';
        echo '<div class="expman-ssl-form__sectiontitle">הערות</div>';
        echo '<textarea name="notes" rows="6"></textarea>';
        echo '</div>';
        echo '</div>';

        echo '<div class="expman-ssl-form__file">';
        echo '<div class="expman-ssl-form__sectiontitle">תמונות</div>';
        echo '<input class="expman-ui-file" type="file" name="images[]" multiple accept="image/*">';
        echo '</div>';

        echo '</div>'; // grid

        echo '<div class="expman-ssl-form__actions">';
        echo '<button type="button" id="expman-ssl-modal-delete" class="expman-ui-btn expman-ui-btn--danger" style="display:none;">מחק</button>';
        echo '<button type="submit" class="expman-ui-btn expman-ui-btn--primary">שמור</button>';
        echo '<button type="button" id="expman-ssl-form-cancel" class="expman-ui-btn">ביטול</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>'; // panel
    }

    public static function enqueue_check_task( $post_id, $context = 'manual' ) {
        $post_id = intval( $post_id );
        if ( $post_id <= 0 ) { return false; }

        $url = (string) get_post_meta( $post_id, 'site_url', true );
        if ( $url === '' ) { return false; }

        $manual = (int) get_post_meta( $post_id, 'manual_mode', true );
        if ( $manual ) { return false; }

        $agent_token_id = (string) get_post_meta( $post_id, 'agent_token', true );
        if ( $agent_token_id === '' ) {
            $agent_token_id = self::get_default_agent_token_id();
            if ( $agent_token_id !== '' ) {
                update_post_meta( $post_id, 'agent_token', $agent_token_id );
            }
        }

        $request_id  = 'job' . wp_generate_password( 10, false, false );
        $client_name = (string) get_post_meta( $post_id, 'client_name', true );

        global $wpdb;
        $queue = $wpdb->prefix . self::QUEUE_TABLE;

        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $queue ) );
        if ( ! $exists ) { return false; }

        $ok = $wpdb->replace(
            $queue,
            array(
                'post_id'     => $post_id,
                'site_url'    => esc_url_raw( $url ),
                'client_name' => $client_name,
                'context'     => sanitize_text_field( $context ),
                'agent_token' => sanitize_text_field( $agent_token_id ),
                'enqueued_at' => time(),
                'request_id'  => $request_id,
                'status'      => 'queued',
                'claimed_at'  => 0,
                'attempts'    => 0,
            ),
            array( '%d','%s','%s','%s','%s','%d','%s','%s','%d','%d' )
        );

        if ( $ok === false ) { return false; }

        update_post_meta( $post_id, 'expiry_ts_checked_at', time() );

        // Best-effort update in the certs table too
        $certs = $wpdb->prefix . self::CERTS_TABLE;
        $wpdb->update(
            $certs,
            array(
                'expiry_ts_checked_at' => time(),
                'last_error'           => '',
            ),
            array( 'post_id' => $post_id ),
            array( '%d','%s' ),
            array( '%d' )
        );

        return true;
    }

    private static function get_default_agent_token_id() {
        $tokens = get_option( self::TOKENS_OPTION, array() );
        if ( ! is_array( $tokens ) || empty( $tokens ) ) { return ''; }
        $t0 = $tokens[0];
        if ( is_array( $t0 ) && ! empty( $t0['id'] ) ) {
            return sanitize_text_field( (string) $t0['id'] );
        }
        return '';
    }

    

    /* ===== Lookup tables (Management / Types) ===== */

    private static function ensure_lookup_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $types = $wpdb->prefix . 'expman_ssl_types';
        $mgmt  = $wpdb->prefix . 'expman_ssl_management';

        $sql_types = "CREATE TABLE {$types} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            color VARCHAR(7) NOT NULL DEFAULT '#2f5ea8',
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY name (name)
        ) {$charset};";

        $sql_mgmt = "CREATE TABLE {$mgmt} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY name (name)
        ) {$charset};";

        dbDelta( $sql_types );
        dbDelta( $sql_mgmt );

        // defaults
        $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$mgmt} (name) VALUES (%s),(%s)", 'שלנו', 'של הלקוח' ) );
    }

    private static function random_color() {
        return sprintf( '#%06X', random_int( 0, 0xFFFFFF ) );
    }

    private static function get_management_options() {
        global $wpdb;
        self::ensure_lookup_tables();
        $mgmt  = $wpdb->prefix . 'expman_ssl_management';
        $rows = $wpdb->get_col( "SELECT name FROM {$mgmt} ORDER BY id ASC" );
        $rows = array_values( array_filter( array_map( 'strval', (array) $rows ) ) );
        if ( empty( $rows ) ) { $rows = array( 'שלנו', 'של הלקוח' ); }
        return $rows;
    }

    private static function get_type_names() {
        global $wpdb;
        self::ensure_lookup_tables();

        $types = $wpdb->prefix . 'expman_ssl_types';
        $certs = $wpdb->prefix . self::CERTS_TABLE;

        // Seed from existing certs
        $existing = $wpdb->get_col( "SELECT DISTINCT cert_type FROM {$certs} WHERE cert_type IS NOT NULL AND cert_type <> ''" );
        foreach ( (array) $existing as $t ) {
            $t = trim( (string) $t );
            if ( $t === '' ) { continue; }
            $has = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$types} WHERE name=%s", $t ) );
            if ( ! $has ) {
                $wpdb->insert( $types, array( 'name' => $t, 'color' => self::random_color(), 'updated_at' => current_time( 'mysql' ) ), array( '%s','%s','%s' ) );
            }
        }

        $rows = $wpdb->get_col( "SELECT name FROM {$types} ORDER BY name ASC" );
        return array_values( array_filter( array_map( 'strval', (array) $rows ) ) );
    }

    private static function get_type_color( $type ) {
        $type = trim( (string) $type );
        if ( $type === '' ) { return '#475569'; }

        global $wpdb;
        self::ensure_lookup_tables();
        $types = $wpdb->prefix . 'expman_ssl_types';

        $color = $wpdb->get_var( $wpdb->prepare( "SELECT color FROM {$types} WHERE name=%s", $type ) );
        if ( ! $color ) {
            $color = self::random_color();
            $wpdb->insert( $types, array( 'name' => $type, 'color' => $color, 'updated_at' => current_time( 'mysql' ) ), array( '%s','%s','%s' ) );
        }
        $color = (string) $color;
        if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
            $color = '#2f5ea8';
        }
        return $color;
    }


    private static function get_types_with_colors() {
        global $wpdb;
        self::ensure_lookup_tables();
        $types = $wpdb->prefix . 'expman_ssl_types';
        $rows = $wpdb->get_results( "SELECT name, color FROM {$types} ORDER BY name ASC", ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    public static function render_type_pill( $type ) {
        $type = trim( (string) $type );
        if ( $type === '' ) { return ''; }
        $color = self::get_type_color( $type );
        return '<span class="expman-pill" style="background:' . esc_attr( $color ) . ';">' . esc_html( $type ) . '</span>';
    }

    
    private static function normalize_site_url( $input ) {
        $input = trim( (string) $input );
        if ( $input === '' ) { return ''; }

        // Accept: domain.tld, https://domain.tld, https://domain.tld:10443/ and optional paths.
        $candidate = $input;
        if ( strpos( $candidate, '://' ) === false ) {
            $candidate = 'https://' . $candidate;
        }

        $parts = wp_parse_url( $candidate );
        if ( ! is_array( $parts ) ) { return sanitize_text_field( $input ); }

        $scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
        if ( $scheme !== 'http' && $scheme !== 'https' ) { $scheme = 'https'; }

        $host = isset( $parts['host'] ) ? (string) $parts['host'] : '';
        if ( $host === '' ) {
            // Fallback: strip scheme manually
            $host = preg_replace( '#^https?://#i', '', $candidate );
            $host = preg_replace( '#/.*$#', '', $host );
        }
        $host = strtolower( $host );
        $host = preg_replace( '#^www\.#i', '', $host );

        $port = isset( $parts['port'] ) ? intval( $parts['port'] ) : 0;
        if ( $port < 1 || $port > 65535 ) { $port = 0; }

        $path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
        if ( $path === '' ) { $path = '/'; }
        if ( $path[0] !== '/' ) { $path = '/' . $path; }

        // If user provided no path, keep trailing slash for consistency.
        if ( $path === '' ) { $path = '/'; }

        $out = $scheme . '://' . $host;
        if ( $port ) { $out .= ':' . $port; }
        $out .= $path;

        // Collapse repeated slashes in path (keep scheme intact).
        $out = preg_replace( '#(?<!:)/{2,}#', '/', $out );

        return sanitize_text_field( $out );
    }


    public static function register_ssl_cert_post_type_if_missing() {
        if ( post_type_exists( 'ssl_cert' ) ) { return; }

        register_post_type( 'ssl_cert', array(
            'labels' => array(
                'name'          => 'SSL Certificates',
                'singular_name' => 'SSL Certificate',
            ),
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'supports'            => array( 'title' ),
        ) );
    }

private static function get_table_columns( $table_name ) {
        global $wpdb;
        $cols = $wpdb->get_col( "DESCRIBE {$table_name}", 0 );
        if ( ! is_array( $cols ) ) { return array(); }
        return array_map( 'strval', $cols );
    }

    private static function pick_columns( array $data, array $columns ) {
        $out = array();
        $colset = array_flip( $columns );
        foreach ( $data as $k => $v ) {
            if ( isset( $colset[ $k ] ) ) {
                $out[ $k ] = $v;
            }
        }
        return $out;
    }

    private static function parse_dmy_date_to_ts( $dmy ) {
        $dmy = trim( (string) $dmy );
        if ( $dmy === '' ) { return null; }
        // allow yyyy-mm-dd too
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dmy ) ) {
            $dmy = preg_replace( '/^(\d{4})-(\d{2})-(\d{2})$/', '$3-$2-$1', $dmy );
        }
        $tz = wp_timezone();
        $dt = \DateTime::createFromFormat( 'd-m-Y H:i:s', $dmy . ':00', $tz );
        if ( ! $dt ) { return null; }
        return $dt->getTimestamp();
    }

    private static function compute_status_from_days( $days_left ) {
        if ( $days_left === null ) { return 'no_date'; }
        $t = self::get_thresholds();
        $yellow = intval( $t['yellow'] );
        $red    = intval( $t['red'] );
        if ( $days_left <= $red ) { return 'red'; }
        if ( $days_left <= $yellow ) { return 'yellow'; }
        return 'green';
    }

    /* ===== AJAX handlers (public, no auth) ===== */

    public static function ajax_save_record() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) { wp_die( 'Bad request', 400 ); }

        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : home_url( '/' );
        $nonce    = isset( $_POST['_wpnonce'] ) ? (string) wp_unslash( $_POST['_wpnonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'expman_ssl_save_record' ) ) {
            Expman_SSL_Logs::add( 'warn', 'save_record', 'nonce validation failed', array( 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );
            wp_safe_redirect( add_query_arg( array( 'expman_ssl_status' => 'error', 'expman_ssl_msg' => rawurlencode( 'שגיאת אבטחה (nonce)' ) ), $redirect ) );
            exit;
        }

        global $wpdb;
        self::ensure_cert_snapshot_columns();
        $certs = $wpdb->prefix . self::CERTS_TABLE;

        $columns = self::get_table_columns( $certs );

        $row_id  = isset( $_POST['row_id'] ) ? intval( wp_unslash( $_POST['row_id'] ) ) : 0;
        $post_id = isset( $_POST['post_id'] ) ? intval( wp_unslash( $_POST['post_id'] ) ) : 0;

        // Ensure we have a valid post_id (table requires NOT NULL).
        if ( $post_id <= 0 && $row_id > 0 ) {
            $existing_pid = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$certs} WHERE id = %d LIMIT 1", $row_id ) );
            if ( $existing_pid ) { $post_id = intval( $existing_pid ); }
        }

        $client_name = sanitize_text_field( wp_unslash( $_POST['client_name'] ?? '' ) );
        $customer_number = sanitize_text_field( wp_unslash( $_POST['customer_number_snapshot'] ?? '' ) );
        $site_url_raw = sanitize_text_field( wp_unslash( $_POST['site_url'] ?? '' ) );
        $site_url     = self::normalize_site_url( $site_url_raw );
        $common_name = sanitize_text_field( wp_unslash( $_POST['common_name'] ?? '' ) );
        $cert_type   = sanitize_text_field( wp_unslash( $_POST['cert_type'] ?? '' ) );
        $mgmt_owner  = sanitize_text_field( wp_unslash( $_POST['management_owner'] ?? '' ) );
        $agent_token = sanitize_text_field( wp_unslash( $_POST['agent_token'] ?? '' ) );
        $guide_url   = sanitize_text_field( wp_unslash( $_POST['guide_url'] ?? '' ) );
        $issuer_name = sanitize_text_field( wp_unslash( $_POST['issuer_name'] ?? '' ) );
        $admin_email_in = (string) wp_unslash( $_POST['admin_email'] ?? '' );
        $admin_email_in = trim( $admin_email_in );
        $admin_email = $admin_email_in !== '' ? sanitize_text_field( $admin_email_in ) : '';
        $admin_contact_name = sanitize_text_field( wp_unslash( $_POST['admin_contact_name'] ?? '' ) );
        $notes       = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

        $temporary_enabled = isset( $_POST['temporary_enabled'] ) ? 1 : 0;
        $temporary_note    = sanitize_textarea_field( wp_unslash( $_POST['temporary_note'] ?? '' ) );

        $manual_mode = isset( $_POST['manual_mode'] ) ? 1 : 0;

        // Ensure we have a valid post_id (table requires NOT NULL).
        if ( $post_id <= 0 ) {
            self::register_ssl_cert_post_type_if_missing();
            $pid = wp_insert_post( array(
                'post_type'   => 'ssl_cert',
                'post_status' => 'publish',
                'post_title'  => $site_url !== '' ? $site_url : ( $common_name !== '' ? $common_name : 'SSL Cert' ),
            ), true );
            if ( is_wp_error( $pid ) ) {
                $err = $pid->get_error_message();
                Expman_SSL_Logs::add( 'error', 'save_record', 'failed creating ssl_cert post', array( 'error' => $err, 'client_name' => $client_name, 'site_url' => $site_url ) );
                wp_safe_redirect( add_query_arg( array( 'expman_ssl_status' => 'error', 'expman_ssl_msg' => rawurlencode( 'שגיאה ביצירת רשומה: ' . $err ) ), $redirect ) );
                exit;
            }
            $post_id = (int) $pid;
        }

        // ensure lookups exist + create type if new
        if ( $cert_type !== '' ) { self::get_type_color( $cert_type ); }

        $expiry_ts = self::parse_dmy_date_to_ts( wp_unslash( $_POST['expiry_date'] ?? '' ) );
        $days_left = null;
        if ( $expiry_ts ) {
            $days_left = (int) floor( ( $expiry_ts - time() ) / 86400 );
        }
        $status = 'publish';

        // Images upload (best effort)
        $images_json = null;
        if ( ! empty( $_FILES['images'] ) && is_array( $_FILES['images']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $urls = array();
            for ( $i = 0; $i < count( $_FILES['images']['name'] ); $i++ ) {
                if ( empty( $_FILES['images']['name'][ $i ] ) ) { continue; }
                $file = array(
                    'name'     => $_FILES['images']['name'][ $i ],
                    'type'     => $_FILES['images']['type'][ $i ],
                    'tmp_name' => $_FILES['images']['tmp_name'][ $i ],
                    'error'    => $_FILES['images']['error'][ $i ],
                    'size'     => $_FILES['images']['size'][ $i ],
                );
                $move = wp_handle_upload( $file, array( 'test_form' => false ) );
                if ( is_array( $move ) && ! empty( $move['url'] ) ) {
                    $urls[] = esc_url_raw( $move['url'] );
                }
            }
            if ( $urls ) {
                $images_json = wp_json_encode( $urls );
            }
        }

        
        // Auto-fill customer number by exact name match (only if number not provided)
        if ( $customer_number === '' ) {
            $settings = get_option( ( defined( 'Expiry_Manager_Plugin::OPTION_KEY' ) ? Expiry_Manager_Plugin::OPTION_KEY : 'expman_settings' ), array() );
            $cust_table = isset( $settings['customers_table'] ) ? sanitize_text_field( (string) $settings['customers_table'] ) : '';
            if ( $cust_table !== '' ) {
                $cust_table_full = ( strpos( $cust_table, $wpdb->prefix ) === 0 ) ? $cust_table : ( $wpdb->prefix . $cust_table );
                $wpdb->hide_errors();
                $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cust_table_full ) );
                if ( $exists !== $cust_table_full ) {
                    $wpdb->show_errors();
                    // customers table not found
                    $cols = array();
                } else {
                    $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$cust_table_full}", 0 );
                    $wpdb->show_errors();
                }
                $name_col = null; $num_col = null;
                if ( is_array( $cols ) ) {
                    foreach ( array( 'customer_name', 'name', 'client_name' ) as $c ) { if ( in_array( $c, $cols, true ) ) { $name_col = $c; break; } }
                    foreach ( array( 'customer_number', 'number', 'customer_no', 'cust_number' ) as $c ) { if ( in_array( $c, $cols, true ) ) { $num_col = $c; break; } }
                }
                if ( $name_col && $num_col && $client_name !== '' ) {
                    $found = $wpdb->get_var( $wpdb->prepare( "SELECT {$num_col} FROM {$cust_table_full} WHERE {$name_col} = %s LIMIT 1", $client_name ) );
                    if ( $found !== null && $found !== '' ) {
                        $customer_number = (string) $found;
                    }
                }
            }
        }

$data = array(
            'client_name'       => $client_name,
            'customer_name_snapshot'   => $client_name,
            'customer_number_snapshot' => $customer_number,
            'site_url'          => $site_url,
            'common_name'       => $common_name,
            'cert_type'         => $cert_type,
            'management_owner'  => $mgmt_owner,
            'agent_token'       => $agent_token,
            'guide_url'         => $guide_url,
            'issuer_name'       => $issuer_name,
            'admin_email' => $admin_email,
            'admin_contact_name' => $admin_contact_name,
            'notes'             => $notes,
            'manual_mode'       => $manual_mode,
            'expiry_ts'         => $expiry_ts ? intval( $expiry_ts ) : null,
            'status'            => $status,
            'post_id'           => intval( $post_id ),
            'temporary_enabled' => $temporary_enabled,
            'temporary_note'    => $temporary_note,
            'updated_at'        => current_time( 'mysql' ),
        );

        if ( $images_json !== null ) {
            $data['images'] = $images_json;
        }

        $data = self::pick_columns( $data, $columns );

        $ok = false;
        if ( $row_id > 0 ) {
            $res = $wpdb->update( $certs, $data, array( 'id' => $row_id ) );
            $ok  = ( $res !== false );
        } else {
            $res = $wpdb->insert( $certs, $data );
            $ok  = ( $res !== false );
            if ( $ok ) { $row_id = (int) $wpdb->insert_id; }
        }

        if ( ! $ok ) {
            $err = ! empty( $wpdb->last_error ) ? $wpdb->last_error : 'שגיאה לא ידועה בשמירה';
            Expman_SSL_Logs::add( 'error', 'save_record', 'db save failed', array( 'error' => $err, 'row_id' => $row_id, 'post_id' => $post_id, 'client_name' => $client_name, 'site_url' => $site_url ) );
            wp_safe_redirect( add_query_arg( array(
                'expman_ssl_status' => 'error',
                'expman_ssl_msg'    => rawurlencode( $err ),
            ), $redirect ) );
            exit;
        }

        Expman_SSL_Logs::add( 'info', ( $row_id > 0 ? 'record_update' : 'record_create' ), 'record saved', array( 'row_id' => $row_id, 'post_id' => $post_id, 'client_name' => $client_name, 'site_url' => $site_url ) );

        if ( $post_id > 0 ) {
            update_post_meta( $post_id, 'admin_email', $admin_email );
            update_post_meta( $post_id, 'admin_contact_name', $admin_contact_name );
        }

        wp_safe_redirect( add_query_arg( array(
            'expman_ssl_status' => 'success',
            'expman_ssl_ok'     => 1,
            'expman_ssl_msg'    => rawurlencode( 'הרשומה נשמרה בהצלחה' ),
        ), $redirect ) );
        exit;
    }

    public static function ajax_export() {
        $nonce = isset( $_GET['expman_ssl_export_nonce'] ) ? (string) wp_unslash( $_GET['expman_ssl_export_nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'expman_ssl_export' ) ) { wp_die( 'Bad nonce', 403 ); }

        global $wpdb;
        $certs = $wpdb->prefix . self::CERTS_TABLE;
        $rows  = $wpdb->get_results( "SELECT * FROM {$certs} ORDER BY id DESC", ARRAY_A );

        $cols = array();
        if ( $rows ) {
            $cols = array_keys( $rows[0] );
        } else {
            $cols = self::get_table_columns( $certs );
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="ssl_certs_export.csv"' );
        echo "\xEF\xBB\xBF"; // BOM for Excel

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, $cols );
        foreach ( (array) $rows as $r ) {
            $line = array();
            foreach ( $cols as $c ) {
                $line[] = isset( $r[ $c ] ) ? $r[ $c ] : '';
            }
            fputcsv( $out, $line );
        }
        fclose( $out );
        exit;
    }

    public static function ajax_import() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) { wp_die( 'Bad request', 400 ); }
        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : home_url( '/' );
        $nonce    = isset( $_POST['expman_ssl_import_nonce'] ) ? (string) wp_unslash( $_POST['expman_ssl_import_nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'expman_ssl_import' ) ) {
            wp_safe_redirect( add_query_arg( array( 'expman_ssl_msg' => rawurlencode( 'שגיאת אבטחה (nonce)' ) ), $redirect ) );
            exit;
        }

        if ( empty( $_FILES['expman_ssl_csv']['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( array( 'expman_ssl_msg' => rawurlencode( 'לא נבחר קובץ' ) ), $redirect ) );
            exit;
        }

        $tmp = $_FILES['expman_ssl_csv']['tmp_name'];
        $fh = fopen( $tmp, 'r' );
        if ( ! $fh ) {
            wp_safe_redirect( add_query_arg( array( 'expman_ssl_msg' => rawurlencode( 'לא ניתן לקרוא את הקובץ' ) ), $redirect ) );
            exit;
        }

        global $wpdb;
        $certs = $wpdb->prefix . self::CERTS_TABLE;
        $columns = self::get_table_columns( $certs );

        // Read header
        $header = fgetcsv( $fh );
        if ( ! $header ) {
            fclose( $fh );
            wp_safe_redirect( add_query_arg( array( 'expman_ssl_msg' => rawurlencode( 'CSV ריק' ) ), $redirect ) );
            exit;
        }

        $count = 0;
        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            
        // Auto-fill customer number by exact name match (only if number not provided)
        if ( $customer_number === '' ) {
            $settings = get_option( ( defined( 'Expiry_Manager_Plugin::OPTION_KEY' ) ? Expiry_Manager_Plugin::OPTION_KEY : 'expman_settings' ), array() );
            $cust_table = isset( $settings['customers_table'] ) ? sanitize_text_field( (string) $settings['customers_table'] ) : '';
            if ( $cust_table !== '' ) {
                $cust_table_full = ( strpos( $cust_table, $wpdb->prefix ) === 0 ) ? $cust_table : ( $wpdb->prefix . $cust_table );
                $wpdb->hide_errors();
                $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cust_table_full ) );
                if ( $exists !== $cust_table_full ) {
                    $wpdb->show_errors();
                    // customers table not found
                    $cols = array();
                } else {
                    $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$cust_table_full}", 0 );
                    $wpdb->show_errors();
                }
                $name_col = null; $num_col = null;
                if ( is_array( $cols ) ) {
                    foreach ( array( 'customer_name', 'name', 'client_name' ) as $c ) { if ( in_array( $c, $cols, true ) ) { $name_col = $c; break; } }
                    foreach ( array( 'customer_number', 'number', 'customer_no', 'cust_number' ) as $c ) { if ( in_array( $c, $cols, true ) ) { $num_col = $c; break; } }
                }
                if ( $name_col && $num_col && $client_name !== '' ) {
                    $found = $wpdb->get_var( $wpdb->prepare( "SELECT {$num_col} FROM {$cust_table_full} WHERE {$name_col} = %s LIMIT 1", $client_name ) );
                    if ( $found !== null && $found !== '' ) {
                        $customer_number = (string) $found;
                    }
                }
            }
        }

$data = array();
            for ( $i = 0; $i < count( $header ); $i++ ) {
                $k = (string) $header[ $i ];
                $data[ $k ] = $row[ $i ] ?? '';
            }
            $data = self::pick_columns( $data, $columns );

            $id = isset( $data['id'] ) ? intval( $data['id'] ) : 0;
            if ( $id > 0 ) {
                $wpdb->update( $certs, $data, array( 'id' => $id ) );
            } else {
                $wpdb->insert( $certs, $data );
            }
            $count++;
        }
        fclose( $fh );

        wp_safe_redirect( add_query_arg( array( 'expman_ssl_msg' => rawurlencode( 'ייבוא הושלם: ' . $count . ' רשומות' ) ), $redirect ) );
        exit;
    }

    public static function ajax_update_type_colors() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) { wp_die( 'Bad request', 400 ); }
        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : home_url( '/' );
        $nonce    = isset( $_POST['expman_ssl_types_nonce'] ) ? (string) wp_unslash( $_POST['expman_ssl_types_nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'expman_ssl_types' ) ) {
            wp_safe_redirect( add_query_arg( array( 'expman_ssl_msg' => rawurlencode( 'שגיאת אבטחה (nonce)' ) ), $redirect ) );
            exit;
        }

        global $wpdb;
        self::ensure_lookup_tables();
        $types = $wpdb->prefix . 'expman_ssl_types';

        $names  = (array) ( $_POST['type_name'] ?? array() );
        $colors = (array) ( $_POST['type_color'] ?? array() );

        for ( $i = 0; $i < count( $names ); $i++ ) {
            $n = sanitize_text_field( wp_unslash( $names[ $i ] ?? '' ) );
            $c = sanitize_text_field( wp_unslash( $colors[ $i ] ?? '' ) );
            if ( $n === '' ) { continue; }
            if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $c ) ) { continue; }
            $wpdb->update( $types, array( 'color' => $c, 'updated_at' => current_time( 'mysql' ) ), array( 'name' => $n ) );
        }

        wp_safe_redirect( add_query_arg( array( 'expman_ssl_msg' => rawurlencode( 'עודכן צבעי סוגים' ) ), $redirect ) );
        exit;
    }

    public static function ajax_trash() {
        $row_id = isset( $_GET['row_id'] ) ? intval( wp_unslash( $_GET['row_id'] ) ) : 0;
        $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : self::current_url();

        $nonce = isset( $_GET['_wpnonce'] ) ? (string) wp_unslash( $_GET['_wpnonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'expman_ssl_trash_' . $row_id ) ) { wp_die( 'Bad nonce', 403 ); }

        global $wpdb;
        $certs = $wpdb->prefix . self::CERTS_TABLE;
        $wpdb->update( $certs, array( 'status' => 'trash' ), array( 'id' => $row_id ) );

        Expman_SSL_Logs::add( 'info', 'trash_record', 'moved record to trash', array( 'row_id' => $row_id ) );

        wp_safe_redirect( add_query_arg( array( 'expman_ssl_action' => 'trash', 'expman_ssl_ok' => 1 ), $redirect ) );
        exit;
    }

    public static function ajax_restore() {
        $row_id = isset( $_GET['row_id'] ) ? intval( wp_unslash( $_GET['row_id'] ) ) : 0;
        $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : self::current_url();

        $nonce = isset( $_GET['_wpnonce'] ) ? (string) wp_unslash( $_GET['_wpnonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'expman_ssl_restore_' . $row_id ) ) { wp_die( 'Bad nonce', 403 ); }

        global $wpdb;
        $certs = $wpdb->prefix . self::CERTS_TABLE;
        // Keep compatibility with the rest of the module which uses publish/trash.
        $wpdb->update( $certs, array( 'status' => 'publish' ), array( 'id' => $row_id ) );

        Expman_SSL_Logs::add( 'info', 'restore_record', 'restored record from trash', array( 'row_id' => $row_id ) );

        wp_safe_redirect( add_query_arg( array( 'expman_ssl_action' => 'restore', 'expman_ssl_ok' => 1 ), $redirect ) );
        exit;
    }

    public static function ajax_delete_permanent() {
        $row_id = isset( $_GET['row_id'] ) ? intval( wp_unslash( $_GET['row_id'] ) ) : 0;
        $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : self::current_url();

        $nonce = isset( $_GET['_wpnonce'] ) ? (string) wp_unslash( $_GET['_wpnonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'expman_ssl_delete_permanent_' . $row_id ) ) { wp_die( 'Bad nonce', 403 ); }

        global $wpdb;
        $certs = $wpdb->prefix . self::CERTS_TABLE;
        $wpdb->delete( $certs, array( 'id' => $row_id ) );

        Expman_SSL_Logs::add( 'warn', 'delete_permanent', 'deleted record permanently', array( 'row_id' => $row_id ) );

        Expman_SSL_Logs::add( 'warn', 'delete_record', 'permanently deleted record', array( 'row_id' => $row_id ) );

        wp_safe_redirect( add_query_arg( array( 'expman_ssl_action' => 'delete_permanent', 'expman_ssl_ok' => 1 ), $redirect ) );
        exit;
    }


public static function ajax_single_check() {
    $post_id = isset( $_GET['post_id'] ) ? intval( wp_unslash( $_GET['post_id'] ) ) : 0;
    $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : self::current_url();

    if ( $post_id > 0 ) {
        Expman_SSL_Logs::add( 'info', 'single_check', 'queued single check', array( 'post_id' => $post_id ) );
        self::queue_single_check( $post_id );
        wp_safe_redirect( add_query_arg( array( 'expman_ssl_action' => 'single_check', 'expman_ssl_ok' => 1 ), $redirect ) );
        exit;
    }

    Expman_SSL_Logs::add( 'error', 'single_check', 'invalid post_id for single check', array( 'post_id' => $post_id ) );

    wp_safe_redirect( add_query_arg( array( 'expman_ssl_action' => 'single_check', 'expman_ssl_ok' => 0 ), $redirect ) );
    exit;
}

    public static function ajax_save_log_settings() {
        $option_key = ( defined( 'Expiry_Manager_Plugin::OPTION_KEY' ) ? Expiry_Manager_Plugin::OPTION_KEY : 'expman_settings' );
        $settings = get_option( $option_key, array() );
        $keep_days = isset( $_POST['ssl_log_keep_days'] ) ? intval( $_POST['ssl_log_keep_days'] ) : 120;
        if ( $keep_days < 1 ) { $keep_days = 120; }
        $settings['ssl_log_keep_days'] = $keep_days;
        update_option( $option_key, $settings );
        Expman_SSL_Logs::add( 'info', 'log_settings', 'updated ssl log retention', array( 'keep_days' => $keep_days ) );
        $redirect = isset( $_POST['redirect_to'] ) ? (string) wp_unslash( $_POST['redirect_to'] ) : '';
        if ( $redirect ) {
            wp_safe_redirect( add_query_arg( array( 'expman_ssl_msg' => rawurlencode( 'ההגדרות נשמרו' ), 'expman_ssl_status' => 'success' ), $redirect ) );
            exit;
        }
        wp_send_json_success( array( 'keep_days' => $keep_days ) );
    }

    public static function ajax_clear_logs() {
        Expman_SSL_Logs::clear_all();
        Expman_SSL_Logs::add( 'warn', 'log_clear', 'ssl logs cleared', array() );
        $redirect = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : '';
        if ( $redirect ) {
            wp_safe_redirect( add_query_arg( array( 'expman_ssl_msg' => rawurlencode( 'לוגי תעודות אבטחה נוקו' ), 'expman_ssl_status' => 'success' ), $redirect ) );
            exit;
        }
        wp_send_json_success();
    }

    // Render logs tab HTML (loaded on demand for instant tab switching)
    public static function ajax_render_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }
        $nonce = isset( $_POST['nonce'] ) ? (string) wp_unslash( $_POST['nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'expman_ssl_render_logs' ) ) {
            wp_send_json_error( array( 'message' => 'Bad nonce' ), 403 );
        }
        ob_start();
        self::render_ssl_log_tab();
        $html = ob_get_clean();
        wp_send_json_success( array( 'html' => $html ) );
    }

    private static function queue_single_check( $post_id ) {
    $post_id = intval( $post_id );
    if ( $post_id <= 0 ) { return false; }

    global $wpdb;
    $certs = $wpdb->prefix . self::CERTS_TABLE;
    $queue = $wpdb->prefix . self::QUEUE_TABLE;

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT site_url, client_name, agent_token FROM {$certs} WHERE post_id = %d LIMIT 1", $post_id ), ARRAY_A );

    $site_url = is_array( $row ) ? (string) ( $row['site_url'] ?? '' ) : (string) get_post_meta( $post_id, 'site_url', true );
    $client_name = is_array( $row ) ? (string) ( $row['client_name'] ?? '' ) : (string) get_post_meta( $post_id, 'client_name', true );
    $agent_token = is_array( $row ) ? (string) ( $row['agent_token'] ?? '' ) : (string) get_post_meta( $post_id, 'agent_token', true );

    $request_id = wp_generate_password( 12, false, false ) . '-' . time();

    // Intentionally: no customer auto-fill here (handled during record save).

$data = array(
        'post_id'     => $post_id,
        'site_url'    => $site_url,
        'client_name' => $client_name,
        'context'     => 'manual',
        'agent_token' => $agent_token,
        'enqueued_at' => time(),
        'request_id'  => $request_id,
        'status'      => 'queued',
        'claimed_at'  => 0,
        'attempts'    => 0,
    );

    $formats = array( '%d','%s','%s','%s','%s','%d','%s','%s','%d','%d' );
    $wpdb->replace( $queue, $data, $formats );
    if ( ! empty( $wpdb->last_error ) ) {
        Expman_SSL_Logs::add( 'error', 'queue_enqueue', 'failed to enqueue check', array( 'post_id' => $post_id, 'site_url' => $site_url, 'error' => $wpdb->last_error ) );
    } else {
        Expman_SSL_Logs::add( 'info', 'queue_enqueue', 'enqueued check', array( 'post_id' => $post_id, 'site_url' => $site_url, 'request_id' => $request_id, 'agent_token' => $agent_token ? 'set' : 'missing' ) );
    }


    // Update timestamps immediately so the UI reflects that a check was requested.
    $cert_cols = self::get_table_columns( $certs );
    $upd = array();
    $fmt_u = array();
    if ( in_array( 'expiry_ts_checked_at', (array) $cert_cols, true ) ) {
        $upd['expiry_ts_checked_at'] = time();
        $fmt_u[] = '%d';
    }
    if ( in_array( 'updated_at', (array) $cert_cols, true ) ) {
        $upd['updated_at'] = current_time( 'mysql', true );
        $fmt_u[] = '%s';
    }
    if ( ! empty( $upd ) ) {
        $wpdb->update( $certs, $upd, array( 'post_id' => $post_id ), $fmt_u, array( '%d' ) );
    }

    return true;
}

private static function current_url() {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        return esc_url_raw( $scheme . '://' . $host . $uri );
    }

    private static function shorten( $s, $max = 60 ) {
        $s = (string) $s;
        if ( mb_strlen( $s ) <= $max ) { return $s; }
        return mb_substr( $s, 0, $max - 1 ) . '…';
    }

    /* ===========================
     * Agent REST API (ssl-agent/v1) - parallel safe
     * =========================== */

    public static function register_agent_rest_if_needed() {
        // Register only if another plugin (old SSL plugin) didn't already register these routes.
        if ( self::agent_routes_exist() ) {
            return;
        }

        register_rest_route( 'ssl-agent/v1', '/poll', array(
            'methods'  => array( 'GET', 'POST' ),
            'permission_callback' => '__return_true',
            'callback' => array( __CLASS__, 'rest_agent_poll' ),
        ) );

        register_rest_route( 'ssl-agent/v1', '/ack', array(
            'methods'  => array( 'POST' ),
            'permission_callback' => '__return_true',
            'callback' => array( __CLASS__, 'rest_agent_ack' ),
        ) );

        register_rest_route( 'ssl-agent/v1', '/report', array(
            'methods'  => array( 'POST' ),
            'permission_callback' => '__return_true',
            'callback' => array( __CLASS__, 'rest_report' ),
        ) );
    }

    private static function agent_routes_exist() {
        if ( ! function_exists( 'rest_get_server' ) ) {
            return false;
        }
        $server = rest_get_server();
        if ( ! $server || ! method_exists( $server, 'get_routes' ) ) {
            return false;
        }
        $routes = $server->get_routes();
        // WP stores routes with leading slash.
        return isset( $routes['/ssl-agent/v1/poll'] ) || isset( $routes['/ssl-agent/v1/ack'] ) || isset( $routes['/ssl-agent/v1/report'] );
    }
    private static function rest_auth( WP_REST_Request $req ) {
        $token = $req->get_header( 'x-agent-token' );
        if ( ! $token ) {
            // Some servers normalize headers differently
            $token = isset( $_SERVER['HTTP_X_AGENT_TOKEN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AGENT_TOKEN'] ) ) : '';
        }
        $token = is_string( $token ) ? trim( $token ) : '';
        if ( $token === '' ) {
            Expman_SSL_Logs::add( 'warn', 'agent_auth', 'missing x-agent-token', array( 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );
            return new WP_Error( 'forbidden', 'missing token', array( 'status' => 403 ) );
        }

        // 1) PRIMARY: old model compatibility (table: wp_{prefix}ssl_agents)
        global $wpdb;
        $agents_table  = $wpdb->prefix . 'ssl_agents';
        $agents_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $agents_table ) );
        if ( $agents_exists === $agents_table ) {
            $agent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$agents_table} WHERE token = %s LIMIT 1", $token ), ARRAY_A );
            if ( is_array( $agent ) && ! empty( $agent['id'] ) ) {
                $wpdb->update(
                    $agents_table,
                    array(
                        'last_seen' => current_time( 'mysql', true ),
                        'status'    => 'online',
                    ),
                    array( 'id' => intval( $agent['id'] ) ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );

                return array(
                    'agent' => $agent,
                    'token' => array(
                        'id'    => (string) ( $agent['id'] ?? '' ),
                        'name'  => (string) ( $agent['name'] ?? '' ),
                        'token' => (string) ( $agent['token'] ?? '' ),
                    ),
                );
            }
        }

        // 2) FALLBACK: new tokens option (kept for backward compatibility in this plugin)
        $stored = get_option( self::TOKENS_OPTION, array() );

        // Support: single string token.
        if ( is_string( $stored ) && $stored !== '' ) {
            if ( hash_equals( $stored, $token ) ) {
                return array( 'token' => array( 'token' => $stored, 'id' => 'default', 'name' => 'default' ) );
            }
            Expman_SSL_Logs::add( 'warn', 'agent_auth', 'invalid token (single)', array( 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );
            return new WP_Error( 'forbidden', 'invalid token', array( 'status' => 403 ) );
        }

        if ( is_array( $stored ) ) {
            foreach ( $stored as $row ) {
                if ( is_array( $row ) && ! empty( $row['token'] ) && hash_equals( (string) $row['token'], $token ) ) {
                    return array( 'token' => $row );
                }
            }
        }

        Expman_SSL_Logs::add( 'warn', 'agent_auth', 'invalid token', array( 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );
        return new WP_Error( 'forbidden', 'invalid token', array( 'status' => 403 ) );
    }

    private static function queue_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::QUEUE_TABLE;
    }

    private static function certs_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::CERTS_TABLE;
    }

    private static function table_columns( $table ) {
        static $cache = array();
        if ( isset( $cache[ $table ] ) ) {
            return $cache[ $table ];
        }
        global $wpdb;
        $cols = array();
        $rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                if ( isset( $r['Field'] ) ) {
                    $cols[ $r['Field'] ] = true;
                }
            }
        }
        $cache[ $table ] = $cols;
        return $cols;
    }

    // Extend old SSL table with customer snapshot columns (safe: old plugin ignores extra columns)
    private static function ensure_cert_snapshot_columns(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::CERTS_TABLE;

        // Performance: SHOW COLUMNS on large tables is expensive and was previously executed on every render.
        // We only need to ensure these columns exist once per site (or after a table recreation).
        // Bump version when adding new snapshot columns.
        $opt_key = 'expman_ssl_snapshot_cols_done_v2';
        $done = get_option( $opt_key, array() );
        if ( ! is_array( $done ) ) { $done = array(); }
        $table_key = md5( $table );
        if ( ! empty( $done[ $table_key ] ) ) {
            return;
        }

        // Also guard with a short transient to avoid stampedes if multiple admins load the page together.
        $lock_key = 'expman_ssl_snapshot_cols_lock_' . $table_key;
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

        $existing = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        if ( ! is_array( $existing ) ) { $existing = array(); }
        $existing = array_fill_keys( $existing, true );

        $queries = array();
        if ( ! isset( $existing['customer_number_snapshot'] ) ) {
            $queries[] = "ALTER TABLE {$table} ADD COLUMN customer_number_snapshot VARCHAR(64) NULL";
        }
        if ( ! isset( $existing['customer_name_snapshot'] ) ) {
            $queries[] = "ALTER TABLE {$table} ADD COLUMN customer_name_snapshot VARCHAR(255) NULL";
        }
        if ( ! isset( $existing['admin_email'] ) ) {
            $queries[] = "ALTER TABLE {$table} ADD COLUMN admin_email VARCHAR(255) NULL";
        }
        if ( ! isset( $existing['admin_contact_name'] ) ) {
            $queries[] = "ALTER TABLE {$table} ADD COLUMN admin_contact_name VARCHAR(255) NULL";
        }

        foreach ( $queries as $q ) { $wpdb->query( $q ); }

        // Mark as done even if nothing was changed (next loads skip SHOW COLUMNS).
        $done[ $table_key ] = time();
        update_option( $opt_key, $done, false );
        delete_transient( $lock_key );
    }



    private static function release_stale_claims() {
        global $wpdb;
        $table = self::queue_table_name();
        // If table doesn't exist, do nothing.
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( $exists !== $table ) {
            return;
        }

        $now = time();
        $ttl = 300; // 5 minutes
        $stale_before = max( 0, $now - $ttl );

        // status: 1=claimed
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = 0, claimed_at = 0 WHERE status = %d AND claimed_at > 0 AND claimed_at < %d",
                1,
                $stale_before
            )
        );
    }
    public static function rest_agent_poll( WP_REST_Request $req ) {
        $auth = self::rest_auth( $req );
        if ( is_wp_error( $auth ) ) {
            return $auth;
        }

        $limit = intval( $req->get_param( 'limit' ) );
        if ( $limit <= 0 ) { $limit = 50; }
        if ( $limit > 100 ) { $limit = 100; }

        $force = intval( $req->get_param( 'force' ) ) === 1;

        $token_data  = ( is_array( $auth ) && isset( $auth['token'] ) && is_array( $auth['token'] ) ) ? $auth['token'] : array();
        $token_label = '';
        if ( ! empty( $token_data['name'] ) ) {
            $token_label = (string) $token_data['name'];
        } elseif ( ! empty( $token_data['id'] ) ) {
            $token_label = (string) $token_data['id'];
        }
        $agent_filter = ! empty( $token_data['id'] ) ? sanitize_text_field( (string) $token_data['id'] ) : '';

        self::release_stale_claims();

        global $wpdb;
        $table = self::queue_table_name();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return new WP_REST_Response( array( 'jobs' => array(), 'tasks' => array(), 'count' => 0, 'pending' => 0 ), 200 );
        }

        $now = time();
        $max_attempts = 5;

        // Old model compatibility: compare status as numeric. In MySQL, 'queued' will cast to 0.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id AS queue_id, post_id, site_url, client_name, context, enqueued_at, request_id, status, attempts
                 FROM {$table}
                 WHERE status = %d AND attempts < %d
                 ORDER BY enqueued_at ASC, id ASC
                 LIMIT %d",
                0,
                $max_attempts,
                $limit
            ),
            ARRAY_A
        );

        $jobs = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $queue_id = intval( $row['queue_id'] ?? 0 );
                $post_id  = intval( $row['post_id'] ?? 0 );
                $attempts = intval( $row['attempts'] ?? 0 ) + 1;

                $updated = $wpdb->update(
                    $table,
                    array(
                        'status'     => 1,
                        'claimed_at' => $now,
                        'attempts'   => $attempts,
                    ),
                    array( 'id' => $queue_id ),
                    array( '%d','%d','%d' ),
                    array( '%d' )
                );
                if ( false === $updated ) {
                    continue;
                }

                $jobs[] = array(
                    'queue_id'    => $queue_id,
                    'id'          => $post_id,
                    'post_id'     => $post_id,
                    'site_url'    => (string) ( $row['site_url'] ?? '' ),
                    'client_name' => (string) ( $row['client_name'] ?? '' ),
                    'request_id'  => (string) ( $row['request_id'] ?? '' ),
                    'context'     => ( $row['context'] ?? 'manual' ),
                    'callback'    => rest_url( 'ssl-agent/v1/report' ),
                    'queue_key'   => $queue_id,
                    'agent_token' => $agent_filter,
                );
            }
        }

        $pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %d AND attempts < %d", 0, $max_attempts ) );

        if ( $force ) {
            Expman_SSL_Logs::add( 'info', 'agent_poll_force', 'agent forced poll', array( 'limit' => $limit, 'token' => $token_label, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );
        }
        Expman_SSL_Logs::add( 'info', 'agent_poll', 'agent polled queue', array( 'sent' => count( $jobs ), 'pending' => $pending, 'token' => $token_label, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );

        return new WP_REST_Response( array( 'jobs' => $jobs, 'tasks' => $jobs, 'count' => count( $jobs ), 'pending' => $pending ), 200 );
    }

    public static function rest_agent_ack( WP_REST_Request $req ) {
        $auth = self::rest_auth( $req );
        if ( is_wp_error( $auth ) ) {
            return $auth;
        }

        $data = $req->get_json_params();
        if ( ! is_array( $data ) ) {
            $data = array();
        }

        $rows = array();
        if ( isset( $data['tasks'] ) && is_array( $data['tasks'] ) ) {
            $rows = $data['tasks'];
        } elseif ( isset( $data['acks'] ) && is_array( $data['acks'] ) ) {
            $rows = $data['acks'];
        } elseif ( isset( $data['acknowledged'] ) && is_array( $data['acknowledged'] ) ) {
            $rows = $data['acknowledged'];
        }

        $acknowledged = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $id = isset( $row['id'] ) ? intval( $row['id'] ) : 0;
            if ( $id <= 0 ) { continue; }
            $acknowledged[] = array(
                'id'         => $id,
                'request_id' => isset( $row['request_id'] ) ? sanitize_text_field( (string) $row['request_id'] ) : '',
            );
        }

        $token_data  = ( is_array( $auth ) && isset( $auth['token'] ) && is_array( $auth['token'] ) ) ? $auth['token'] : array();
        $token_label = '';
        if ( ! empty( $token_data['name'] ) ) {
            $token_label = (string) $token_data['name'];
        } elseif ( ! empty( $token_data['id'] ) ) {
            $token_label = (string) $token_data['id'];
        }

        if ( ! empty( $acknowledged ) ) {
            $preview = array_slice( $acknowledged, 0, 10 );
            Expman_SSL_Logs::add( 'info', 'agent_ack', 'agent acknowledged tasks', array( 'token' => $token_label, 'count' => count( $acknowledged ), 'preview' => $preview, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );
        } else {
            Expman_SSL_Logs::add( 'debug', 'agent_ack', 'agent ack (empty)', array( 'token' => $token_label, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );
        }

        return new WP_REST_Response( array( 'ok' => true, 'acknowledged' => count( $acknowledged ) ), 200 );
    }

    public static function rest_report( WP_REST_Request $req ) {
        $auth = self::rest_auth( $req );
        if ( is_wp_error( $auth ) ) {
            return $auth;
        }

        $payload = $req->get_json_params();
        if ( ! is_array( $payload ) ) {
            $payload = array();
        }

        $results = array();
        if ( isset( $payload['results'] ) && is_array( $payload['results'] ) ) {
            $results = $payload['results'];
        } elseif ( isset( $payload['result'] ) && is_array( $payload['result'] ) ) {
            $results = array( $payload['result'] );
        } elseif ( isset( $payload[0] ) && is_array( $payload[0] ) ) {
            $results = $payload;
        }

        $token_data  = ( is_array( $auth ) && isset( $auth['token'] ) && is_array( $auth['token'] ) ) ? $auth['token'] : array();
        $token_label = '';
        if ( ! empty( $token_data['name'] ) ) {
            $token_label = (string) $token_data['name'];
        } elseif ( ! empty( $token_data['id'] ) ) {
            $token_label = (string) $token_data['id'];
        }

        if ( empty( $results ) ) {
            Expman_SSL_Logs::add( 'warn', 'agent_report', 'agent report received (empty)', array( 'token' => $token_label, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );
            return new WP_REST_Response( array( 'ok' => true, 'updated' => 0 ), 200 );
        }

        Expman_SSL_Logs::add( 'info', 'agent_report', 'received report', array( 'count' => count( $results ), 'token' => $token_label, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );

        global $wpdb;
        $queue_table = self::queue_table_name();
        $cert_table  = self::certs_table_name();

        $queue_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) ) === $queue_table;
        $cert_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cert_table ) ) === $cert_table;
        $cert_cols    = $cert_exists ? self::table_columns( $cert_table ) : array();

        $updated = 0;
        $now     = time();

        foreach ( $results as $r ) {
            if ( ! is_array( $r ) ) { continue; }

            $queue_id = intval( $r['queue_id'] ?? ( $r['queue_key'] ?? 0 ) );
            $post_id  = intval( $r['post_id'] ?? ( $r['id'] ?? 0 ) );

            if ( ! $post_id && $queue_exists && $queue_id > 0 ) {
                $found = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$queue_table} WHERE id = %d LIMIT 1", $queue_id ) );
                if ( $found ) { $post_id = intval( $found ); }
            }
            if ( $post_id <= 0 ) { continue; }

            $expiry_ts = 0;
            $candidates = array(
                $r['expiry_ts'] ?? null,
                $r['expiryts'] ?? null,
                $r['expiry'] ?? null,
                $r['not_after'] ?? null,
                $r['notafter'] ?? null,
            );
            foreach ( $candidates as $cand ) {
                if ( $cand === null || $cand === '' ) { continue; }
                if ( is_numeric( $cand ) ) {
                    $expiry_ts = intval( $cand );
                } else {
                    $expiry_ts = intval( strtotime( (string) $cand ) );
                }
                if ( $expiry_ts > 1000000000 ) { break; }
                $expiry_ts = 0;
            }

            $status = isset( $r['status'] ) ? sanitize_text_field( (string) $r['status'] ) : '';
            $error  = isset( $r['error'] ) ? sanitize_text_field( (string) $r['error'] ) : '';
            $cn     = '';
            if ( ! empty( $r['common_name'] ) ) {
                $cn = sanitize_text_field( (string) $r['common_name'] );
            } elseif ( ! empty( $r['cn'] ) ) {
                $cn = sanitize_text_field( (string) $r['cn'] );
            }
            $issuer = '';
            if ( ! empty( $r['issuer_name'] ) ) {
                $issuer = sanitize_text_field( (string) $r['issuer_name'] );
            } elseif ( ! empty( $r['issuer'] ) ) {
                $issuer = sanitize_text_field( (string) $r['issuer'] );
            } elseif ( ! empty( $r['ca'] ) ) {
                $issuer = sanitize_text_field( (string) $r['ca'] );
            }

            if ( $cert_exists ) {
                $data = array();
                $fmt  = array();

                if ( $expiry_ts > 0 && isset( $cert_cols['expiry_ts'] ) ) {
                    $data['expiry_ts'] = $expiry_ts; $fmt[] = '%d';
                }
                if ( $expiry_ts > 0 && isset( $cert_cols['expiration_date'] ) ) {
                    $data['expiration_date'] = gmdate( 'Y-m-d', $expiry_ts ); $fmt[] = '%s';
                }
                if ( $cn !== '' && isset( $cert_cols['common_name'] ) ) {
                    $data['common_name'] = $cn; $fmt[] = '%s';
                }
                if ( $issuer !== '' && isset( $cert_cols['issuer_name'] ) ) {
                    $data['issuer_name'] = $issuer; $fmt[] = '%s';
                }
                if ( $status !== '' && isset( $cert_cols['status'] ) ) {
                    $data['status'] = $status; $fmt[] = '%s';
                }
                if ( isset( $cert_cols['last_error'] ) ) {
                    $data['last_error'] = $error; $fmt[] = '%s';
                }
                if ( isset( $cert_cols['expiry_ts_checked_at'] ) ) {
                    $data['expiry_ts_checked_at'] = $now; $fmt[] = '%d';
                }

                if ( isset( $cert_cols['updated_at'] ) ) {
                    $data['updated_at'] = current_time( 'mysql', true ); $fmt[] = '%s';
                }
                if ( isset( $cert_cols['source'] ) ) {
                    $data['source'] = 'agent'; $fmt[] = '%s';
                }

                if ( ! empty( $data ) ) {
                    $wpdb->update( $cert_table, $data, array( 'post_id' => $post_id ), $fmt, array( '%d' ) );
                }
            }

            // also update post meta for compatibility
            if ( $expiry_ts > 0 ) {
                update_post_meta( $post_id, 'expiry_ts', $expiry_ts );
                update_post_meta( $post_id, 'expiry_ts_checked_at', $now );
            }
            if ( $cn !== '' ) { update_post_meta( $post_id, 'cert_cn', $cn ); }
            if ( $issuer !== '' ) { update_post_meta( $post_id, 'cert_ca', $issuer ); }
            if ( $error !== '' ) { update_post_meta( $post_id, 'last_error', $error ); } else { delete_post_meta( $post_id, 'last_error' ); }

            if ( $queue_exists && $queue_id > 0 ) {
                $wpdb->update(
                    $queue_table,
                    array(
                        'status'     => 2,
                        'claimed_at' => 0,
                    ),
                    array( 'id' => $queue_id ),
                    array( '%d','%d' ),
                    array( '%d' )
                );
            }

            $updated++;
        }

        Expman_SSL_Logs::add( 'info', 'agent_report', 'processed report', array( 'updated' => $updated, 'token' => $token_label ) );
        return new WP_REST_Response( array( 'ok' => true, 'updated' => $updated ), 200 );
    }




    // Public: customer autocomplete from settings customers_table
    public static function ajax_customer_search() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
            wp_send_json_error( array( 'error' => 'bad_method' ), 405 );
        }

        $q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        if ( $q === '' || strlen( $q ) < 2 ) {
            wp_send_json_success( array( 'items' => array() ) );
        }

        $option_key = ( defined( 'Expiry_Manager_Plugin::OPTION_KEY' ) ? Expiry_Manager_Plugin::OPTION_KEY : 'expman_settings' );
        $settings   = get_option( $option_key, array() );
        $cust_table = isset( $settings['customers_table'] ) ? sanitize_text_field( (string) $settings['customers_table'] ) : '';

        if ( $cust_table === '' ) {
            wp_send_json_success( array( 'items' => array() ) );
        }

        global $wpdb;
        // Allow saving full table name (with prefix) or without.
        $table = ( strpos( $cust_table, $wpdb->prefix ) === 0 ) ? $cust_table : ( $wpdb->prefix . $cust_table );

        // Avoid breaking AJAX JSON on DB errors
        $wpdb->hide_errors();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            wp_send_json_success( array( 'items' => array() ) );
        }

        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        $wpdb->show_errors();
        if ( ! is_array( $cols ) ) { $cols = array(); }

        // Case-insensitive column matching + flexible fallbacks.
        $cols_map = array();
        foreach ( $cols as $c ) {
            $cols_map[ strtolower( (string) $c ) ] = (string) $c;
        }

        $name_col = null;
        $num_col  = null;

        foreach ( array( 'customer_name', 'customername', 'name', 'client_name', 'clientname', 'company_name', 'companyname', 'title', 'customer', 'client', 'company' ) as $c ) {
            $lc = strtolower( $c );
            if ( isset( $cols_map[ $lc ] ) ) { $name_col = $cols_map[ $lc ]; break; }
        }
        foreach ( array( 'customer_number', 'customernumber', 'number', 'customer_no', 'customerno', 'cust_number', 'custnumber', 'customer_id', 'cust_id', 'client_id', 'id' ) as $c ) {
            $lc = strtolower( $c );
            if ( isset( $cols_map[ $lc ] ) ) { $num_col = $cols_map[ $lc ]; break; }
        }

        // Fallback: find first column containing a hint.
        if ( ! $name_col ) {
            foreach ( $cols_map as $lc => $orig ) {
                if ( strpos( $lc, 'name' ) !== false || strpos( $lc, 'client' ) !== false || strpos( $lc, 'customer' ) !== false || strpos( $lc, 'company' ) !== false || strpos( $lc, 'title' ) !== false ) {
                    $name_col = $orig;
                    break;
                }
            }
        }
        if ( ! $num_col ) {
            foreach ( $cols_map as $lc => $orig ) {
                if ( strpos( $lc, 'number' ) !== false || strpos( $lc, 'num' ) !== false || $lc === 'id' || substr( $lc, -3 ) === '_id' ) {
                    $num_col = $orig;
                    break;
                }
            }
        }

        if ( ! $name_col && ! $num_col ) { wp_send_json_success( array( 'items' => array() ) ); }

        $like = '%' . $wpdb->esc_like( $q ) . '%';

        $select = '';
        if ( $name_col ) { $select .= "{$name_col} AS name"; }
        if ( $num_col ) { $select .= ( $select ? ', ' : '' ) . "{$num_col} AS num"; }

        $where = array();
        $params = array();
        if ( $name_col ) { $where[] = "{$name_col} LIKE %s"; $params[] = $like; }
        if ( $num_col ) { $where[] = "CAST({$num_col} AS CHAR) LIKE %s"; $params[] = $like; }

        $order = $name_col ? $name_col : $num_col;

        $sql = "SELECT {$select} FROM {$table} WHERE (" . implode( ' OR ', $where ) . ") ORDER BY {$order} ASC LIMIT 20";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        $items = array();
        foreach ( $rows as $r ) {
            $items[] = array(
                'name'   => (string) ( $r['name'] ?? '' ),
                'number' => (string) ( $r['num'] ?? '' ),
            );
        }

        wp_send_json_success( array( 'items' => $items ) );
    }

    // Prefetch: return a larger list once so client-side filtering is instant.
    public static function ajax_customer_prefetch() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
            wp_send_json_error( array( 'error' => 'bad_method' ), 405 );
        }

        $option_key = ( defined( 'Expiry_Manager_Plugin::OPTION_KEY' ) ? Expiry_Manager_Plugin::OPTION_KEY : 'expman_settings' );
        $settings   = get_option( $option_key, array() );
        $cust_table = isset( $settings['customers_table'] ) ? sanitize_text_field( (string) $settings['customers_table'] ) : '';
        if ( $cust_table === '' ) { wp_send_json_success( array( 'items' => array() ) ); }

        global $wpdb;
        $table = ( strpos( $cust_table, $wpdb->prefix ) === 0 ) ? $cust_table : ( $wpdb->prefix . $cust_table );
        $wpdb->hide_errors();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) { wp_send_json_success( array( 'items' => array() ) ); }
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        $wpdb->show_errors();
        if ( ! is_array( $cols ) ) { $cols = array(); }

        $cols_map = array();
        foreach ( $cols as $c ) { $cols_map[ strtolower( (string) $c ) ] = (string) $c; }

        $name_col = null;
        $num_col  = null;
        foreach ( array( 'customer_name', 'customername', 'name', 'client_name', 'clientname', 'company_name', 'companyname', 'title', 'customer', 'client', 'company' ) as $c ) {
            $lc = strtolower( $c );
            if ( isset( $cols_map[ $lc ] ) ) { $name_col = $cols_map[ $lc ]; break; }
        }
        foreach ( array( 'customer_number', 'customernumber', 'number', 'customer_no', 'customerno', 'cust_number', 'custnumber', 'customer_id', 'cust_id', 'client_id', 'id' ) as $c ) {
            $lc = strtolower( $c );
            if ( isset( $cols_map[ $lc ] ) ) { $num_col = $cols_map[ $lc ]; break; }
        }
        if ( ! $name_col ) {
            foreach ( $cols_map as $lc => $orig ) {
                if ( strpos( $lc, 'name' ) !== false || strpos( $lc, 'client' ) !== false || strpos( $lc, 'customer' ) !== false || strpos( $lc, 'company' ) !== false || strpos( $lc, 'title' ) !== false ) { $name_col = $orig; break; }
            }
        }
        if ( ! $num_col ) {
            foreach ( $cols_map as $lc => $orig ) {
                if ( strpos( $lc, 'number' ) !== false || strpos( $lc, 'num' ) !== false || $lc === 'id' || substr( $lc, -3 ) === '_id' ) { $num_col = $orig; break; }
            }
        }
        if ( ! $name_col && ! $num_col ) { wp_send_json_success( array( 'items' => array() ) ); }

        $select = '';
        if ( $name_col ) { $select .= "{$name_col} AS name"; }
        if ( $num_col ) { $select .= ( $select ? ', ' : '' ) . "{$num_col} AS num"; }
        $order = $name_col ? $name_col : $num_col;

        // Reasonable cap to keep payload sane; the UI filters locally.
        $rows = $wpdb->get_results( "SELECT {$select} FROM {$table} ORDER BY {$order} ASC LIMIT 5000", ARRAY_A );

        $items = array();
        foreach ( (array) $rows as $r ) {
            $items[] = array(
                'name'   => (string) ( $r['name'] ?? '' ),
                'number' => (string) ( $r['num'] ?? '' ),
            );
        }
        wp_send_json_success( array( 'items' => $items ) );
    }


}


// Public AJAX endpoints (shortcode is public)
add_action( 'wp_ajax_nopriv_expman_ssl_save_record', array( 'Expman_SSLCerts_Page', 'ajax_save_record' ) );
add_action( 'wp_ajax_expman_ssl_save_record', array( 'Expman_SSLCerts_Page', 'ajax_save_record' ) );

add_action( 'wp_ajax_nopriv_expman_ssl_customer_search', array( 'Expman_SSLCerts_Page', 'ajax_customer_search' ) );
add_action( 'wp_ajax_expman_ssl_customer_search', array( 'Expman_SSLCerts_Page', 'ajax_customer_search' ) );

add_action( 'wp_ajax_nopriv_expman_ssl_customer_prefetch', array( 'Expman_SSLCerts_Page', 'ajax_customer_prefetch' ) );
add_action( 'wp_ajax_expman_ssl_customer_prefetch', array( 'Expman_SSLCerts_Page', 'ajax_customer_prefetch' ) );

add_action( 'wp_ajax_nopriv_expman_ssl_export', array( 'Expman_SSLCerts_Page', 'ajax_export' ) );
add_action( 'wp_ajax_expman_ssl_export', array( 'Expman_SSLCerts_Page', 'ajax_export' ) );

add_action( 'wp_ajax_nopriv_expman_ssl_import', array( 'Expman_SSLCerts_Page', 'ajax_import' ) );
add_action( 'wp_ajax_expman_ssl_import', array( 'Expman_SSLCerts_Page', 'ajax_import' ) );

add_action( 'wp_ajax_nopriv_expman_ssl_update_type_colors', array( 'Expman_SSLCerts_Page', 'ajax_update_type_colors' ) );
add_action( 'wp_ajax_expman_ssl_update_type_colors', array( 'Expman_SSLCerts_Page', 'ajax_update_type_colors' ) );

add_action( 'wp_ajax_nopriv_expman_ssl_trash', array( 'Expman_SSLCerts_Page', 'ajax_trash' ) );
add_action( 'wp_ajax_expman_ssl_trash', array( 'Expman_SSLCerts_Page', 'ajax_trash' ) );

add_action( 'wp_ajax_nopriv_expman_ssl_restore', array( 'Expman_SSLCerts_Page', 'ajax_restore' ) );
add_action( 'wp_ajax_expman_ssl_restore', array( 'Expman_SSLCerts_Page', 'ajax_restore' ) );

add_action( 'wp_ajax_nopriv_expman_ssl_delete_permanent', array( 'Expman_SSLCerts_Page', 'ajax_delete_permanent' ) );
add_action( 'wp_ajax_expman_ssl_delete_permanent', array( 'Expman_SSLCerts_Page', 'ajax_delete_permanent' ) );

add_action( 'wp_ajax_nopriv_expman_ssl_single_check', array( 'Expman_SSLCerts_Page', 'ajax_single_check' ) );
add_action( 'wp_ajax_expman_ssl_single_check', array( 'Expman_SSLCerts_Page', 'ajax_single_check' ) );

add_action( 'wp_ajax_nopriv_expman_ssl_save_log_settings', array( 'Expman_SSLCerts_Page', 'ajax_save_log_settings' ) );
add_action( 'wp_ajax_expman_ssl_save_log_settings', array( 'Expman_SSLCerts_Page', 'ajax_save_log_settings' ) );
add_action( 'wp_ajax_nopriv_expman_ssl_clear_logs', array( 'Expman_SSLCerts_Page', 'ajax_clear_logs' ) );
add_action( 'wp_ajax_expman_ssl_clear_logs', array( 'Expman_SSLCerts_Page', 'ajax_clear_logs' ) );

add_action( 'wp_ajax_nopriv_expman_ssl_render_logs', array( 'Expman_SSLCerts_Page', 'ajax_render_logs' ) );
add_action( 'wp_ajax_expman_ssl_render_logs', array( 'Expman_SSLCerts_Page', 'ajax_render_logs' ) );

add_action( 'rest_api_init', array( 'Expman_SSLCerts_Page', 'register_agent_rest_if_needed' ), 99 );
add_action( 'init', array( 'Expman_SSLCerts_Page', 'register_ssl_cert_post_type_if_missing' ), 5 );
