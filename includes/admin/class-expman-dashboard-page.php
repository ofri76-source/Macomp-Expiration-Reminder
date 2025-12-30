<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Expman_Dashboard_Page {

    private $option_key;

    public function __construct( $option_key ) {
        $this->option_key = $option_key;
    }

    private function status_for_days( $days, $yellow, $red ) {
        if ( $days === null ) {
            return 'unknown';
        }
        if ( $days <= $red ) {
            return 'red';
        }
        if ( $days <= $yellow ) {
            return 'yellow';
        }
        return 'green';
    }

    private function summary_cards_markup( $title, $counts, $type ) {
        static $summary_css_done = false;
        if ( ! $summary_css_done ) {
            echo '<style>
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
            .expman-summary-card[data-active="1"]{box-shadow:0 0 0 2px rgba(47,94,168,0.18);}
            .expman-summary-meta{margin-top:8px;padding:8px 12px;border-radius:10px;border:1px solid #d9e3f2;background:#f8fafc;font-weight:600;color:#2b3f5c;}
            .expman-summary-meta button{all:unset;cursor:pointer;}
            .expman-dashboard-grid{display:grid;gap:16px;}
            .expman-dashboard-section{border:1px solid #e1e6ef;background:#fff;border-radius:12px;padding:14px;}
            .expman-dashboard-alerts{margin-top:12px;border-top:1px solid #e1e6ef;padding-top:12px;}
            .expman-dashboard-alerts table{width:100%;border-collapse:collapse;}
            .expman-dashboard-alerts th,.expman-dashboard-alerts td{padding:6px 8px;border-bottom:1px solid #eef2f6;text-align:right;}
            </style>';
            $summary_css_done = true;
        }

        $yellow_label = 'תוקף בין ' . ( $counts['red_threshold'] + 1 ) . ' ל-' . $counts['yellow_threshold'] . ' יום';
        echo '<div class="expman-dashboard-section" data-expman-section="' . esc_attr( $type ) . '">';
        echo '<h2 style="margin:0 0 8px;">' . esc_html( $title ) . '</h2>';
        echo '<div class="expman-summary">';
        echo '<div class="expman-summary-card green" data-expman-status="green" data-expman-type="' . esc_attr( $type ) . '"><button type="button"><h4>תוקף מעל ' . esc_html( $counts['yellow_threshold'] ) . ' יום</h4><div class="count">' . esc_html( $counts['green'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card yellow" data-expman-status="yellow" data-expman-type="' . esc_attr( $type ) . '"><button type="button"><h4>' . esc_html( $yellow_label ) . '</h4><div class="count">' . esc_html( $counts['yellow'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card red" data-expman-status="red" data-expman-type="' . esc_attr( $type ) . '"><button type="button"><h4>דורש טיפול מייד</h4><div class="count">' . esc_html( $counts['red'] ) . '</div></button></div>';
        echo '</div>';
        echo '<div class="expman-summary-meta" data-expman-status="all" data-expman-type="' . esc_attr( $type ) . '"><button type="button">סה״כ רשומות פעילות: ' . esc_html( $counts['total'] ) . '</button></div>';
        echo '<div class="expman-dashboard-alerts" data-expman-alerts="' . esc_attr( $type ) . '" style="display:none;"></div>';
        echo '</div>';
    }

    public function render_page() {
        echo '<div class="wrap"><h1>ניהול תאריכי תפוגה – Dashboard</h1>';
        if ( class_exists('Expman_Nav') ) { Expman_Nav::render_admin_nav( '0.2.0' ); }

        $settings = get_option( $this->option_key, array() );
        $yellow = intval( $settings['yellow_threshold'] ?? 90 );
        $red = intval( $settings['red_threshold'] ?? 30 );

        $dashboard_data = array();

        echo '<div class="expman-dashboard-grid">';

        if ( class_exists( 'Expman_Servers_Actions' ) && class_exists( 'Expman_Servers_Page' ) ) {
            $servers_actions = new Expman_Servers_Actions( new Expman_Servers_Logger() );
            $servers_actions->set_option_key( $this->option_key );
            $servers_actions->set_dell( new Expman_Servers_Dell( new Expman_Servers_Logger(), $this->option_key, null ) );
            $servers_summary = $servers_actions->get_summary_counts();
            $servers_rows = $servers_actions->get_servers_rows( array(), 'days_to_end', 'ASC', false );
            $servers_list = array();
            foreach ( (array) $servers_rows as $row ) {
                $days = isset( $row->days_to_end ) ? intval( $row->days_to_end ) : null;
                $status = $this->status_for_days( $days, intval( $servers_summary['yellow_threshold'] ), intval( $servers_summary['red_threshold'] ) );
                $servers_list[] = array(
                    'title' => (string) ( $row->customer_name_snapshot ?? '' ),
                    'secondary' => (string) ( $row->service_tag ?? '' ),
                    'date' => (string) ( $row->ending_on ?? '' ),
                    'days' => $days,
                    'status' => $status,
                );
            }
            $dashboard_data['servers'] = $servers_list;
            $this->summary_cards_markup( 'שרתים', $servers_summary, 'servers' );
        }

        if ( class_exists( 'Expman_Firewalls_Actions' ) ) {
            $fw_actions = new Expman_Firewalls_Actions();
            $fw_summary = $fw_actions->get_summary_counts( $this->option_key );
            $fw_rows = $fw_actions->get_firewalls_rows( array(), 'days_to_renew', 'ASC', 'active' );
            $fw_list = array();
            foreach ( (array) $fw_rows as $row ) {
                $days = isset( $row->days_to_renew ) ? intval( $row->days_to_renew ) : null;
                $status = $this->status_for_days( $days, $yellow, $red );
                $fw_list[] = array(
                    'title' => (string) ( $row->customer_name ?? '' ),
                    'secondary' => (string) ( $row->serial_number ?? '' ),
                    'date' => (string) ( $row->expiry_date ?? '' ),
                    'days' => $days,
                    'status' => $status,
                );
            }
            $dashboard_data['firewalls'] = $fw_list;
            $this->summary_cards_markup( 'חומות אש', $fw_summary, 'firewalls' );
        }

        // Domains + Certs from exp_items
        global $wpdb;
        $items_table = $wpdb->prefix . 'exp_items';
        $types = array(
            'domains' => 'דומיינים',
            'certs'   => 'תעודות אבטחה',
        );
        foreach ( $types as $type => $label ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT name, identifier, expiry_date FROM {$items_table} WHERE type=%s AND deleted_at IS NULL ORDER BY expiry_date ASC",
                    $type
                )
            );
            $list = array();
            $green = $yellow_count = $red_count = 0;
            foreach ( (array) $rows as $row ) {
                $days = null;
                if ( ! empty( $row->expiry_date ) ) {
                    $days = (int) ( ( strtotime( $row->expiry_date ) - strtotime( gmdate( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
                }
                $status = $this->status_for_days( $days, $yellow, $red );
                if ( $status === 'green' ) { $green++; }
                elseif ( $status === 'yellow' ) { $yellow_count++; }
                elseif ( $status === 'red' ) { $red_count++; }
                $list[] = array(
                    'title' => (string) $row->name,
                    'secondary' => (string) ( $row->identifier ?? '' ),
                    'date' => (string) ( $row->expiry_date ?? '' ),
                    'days' => $days,
                    'status' => $status,
                );
            }
            $summary = array(
                'green' => $green,
                'yellow' => $yellow_count,
                'red' => $red_count,
                'total' => count( $rows ),
                'yellow_threshold' => $yellow,
                'red_threshold' => $red,
            );
            $dashboard_data[ $type ] = $list;
            $this->summary_cards_markup( $label, $summary, $type );
        }

        echo '</div>';

        $data_json = wp_json_encode( $dashboard_data );
        echo '<script>
        (function(){
            const data = ' . $data_json . ';
            function formatDate(value){
                if(!value) return "";
                const d = new Date(value.replace(" ", "T"));
                if(isNaN(d.getTime())) return value;
                const day = String(d.getDate()).padStart(2, "0");
                const month = String(d.getMonth()+1).padStart(2, "0");
                const year = d.getFullYear();
                return day + "/" + month + "/" + year;
            }
            function renderAlerts(type, status){
                const wrap = document.querySelector("[data-expman-alerts=\'"+type+"\']");
                if(!wrap) return;
                const rows = (data[type] || []).filter(r => status === "all" ? true : r.status === status);
                let html = "<table><thead><tr><th>שם לקוח/רשומה</th><th>זיהוי</th><th>תאריך</th><th>ימים</th></tr></thead><tbody>";
                if(rows.length === 0){
                    html += "<tr><td colspan=\\"4\\">אין התראות להצגה.</td></tr>";
                } else {
                    rows.forEach(r => {
                        html += "<tr><td>"+(r.title||"")+"</td><td>"+(r.secondary||"")+"</td><td>"+formatDate(r.date)+"</td><td>"+(r.days ?? "")+"</td></tr>";
                    });
                }
                html += "</tbody></table>";
                wrap.innerHTML = html;
                wrap.style.display = "";
            }
            document.querySelectorAll(".expman-summary-card, .expman-summary-meta").forEach(card => {
                card.addEventListener("click", () => {
                    const type = card.getAttribute("data-expman-type");
                    const status = card.getAttribute("data-expman-status") || "all";
                    renderAlerts(type, status);
                });
            });
        })();
        </script>';
        echo '</div>';
    }
}
