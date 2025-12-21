<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Firewalls_UI' ) ) {
class Expman_Firewalls_UI {

    private $page;

    public function __construct( $page ) {
        $this->page = $page;
    }

    public static function render_summary_cards_public( $option_key, $title = '' ) {
        $actions = new Expman_Firewalls_Actions( new Expman_Firewalls_Logger() );
        $data = $actions->get_summary_counts( $option_key );
        self::render_summary_cards_markup( $data, $title );
    }

    private function render_summary_cards( $title = '' ) {
        $actions = $this->page->get_actions();
        $data = $actions->get_summary_counts( $this->page->get_option_key() );
        self::render_summary_cards_markup( $data, $title );
    }

    private static function render_summary_cards_markup( $data, $title = '' ) {
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
            </style>';
            $summary_css_done = true;
        }

        if ( $title !== '' ) {
            echo '<h3 style="margin-bottom:6px;">' . esc_html( $title ) . '</h3>';
        }

        $yellow_label = 'עד ' . intval( $data['yellow_threshold'] ) . ' ימים';

        echo '<div class="expman-summary">';
        echo '<div class="expman-summary-card green" data-expman-status="green" data-active="0"><button type="button"><h4>תוקף מעל ' . esc_html( $data['yellow_threshold'] ) . ' יום</h4><div class="count">' . esc_html( $data['green'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card yellow" data-expman-status="yellow" data-active="0"><button type="button"><h4>' . esc_html( $yellow_label ) . '</h4><div class="count">' . esc_html( $data['yellow'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card red" data-expman-status="red" data-active="0"><button type="button"><h4>דורש טיפול מייד</h4><div class="count">' . esc_html( $data['red'] ) . '</div></button></div>';
        echo '</div>';
        echo '<div class="expman-summary-meta" data-expman-status="all"><button type="button">סה״כ רשומות פעילות: ' . esc_html( $data['total'] ) . ' | בארכיון: ' . esc_html( $data['archived'] ) . '</button></div>';
    }

    public function render() {
        $errors = get_transient( 'expman_firewalls_errors' );
        delete_transient( 'expman_firewalls_errors' );
        $show_bulk_tab = $this->is_bulk_tab_enabled();

        echo '<style>/* expman-compact-fields */
.expman-filter-row input,.expman-filter-row select{height:24px !important;padding:4px 6px !important;font-size:12px !important;border:1px solid #c7d1e0;border-radius:4px;background:#fff;}
.expman-filter-row th{background:#e8f0fb !important;border-bottom:2px solid #c7d1e0;}
.expman-align-left{text-align:left !important;}
.expman-align-left input,
.expman-align-left select,
.expman-align-left button{direction:ltr;text-align:left !important;}
.fw-form input,.fw-form select{height:24px !important;padding:3px 6px !important;font-size:13px !important;}
.fw-form textarea{min-height:60px !important;font-size:13px !important;}
.expman-btn{padding:6px 12px !important;font-size:12px !important;border-radius:6px;border:1px solid #254d8c;background:#2f5ea8;color:#fff;display:inline-block;line-height:1.2;box-shadow:0 1px 0 rgba(0,0,0,0.05);cursor:pointer;text-decoration:none;}
.expman-btn:hover{background:#264f8f;color:#fff;}
.expman-btn.secondary{background:#eef3fb;border-color:#9fb3d9;color:#1f3b64;}
.expman-btn.secondary:hover{background:#dfe9f7;color:#1f3b64;}
.expman-btn-clear{background:transparent;border:0;box-shadow:none;padding:0 !important;color:#2271b1;cursor:pointer;}
.expman-btn-clear:hover{text-decoration:underline;}
.expman-highlight{background:transparent !important;}
.expman-serial-highlight{background:#fff7c0 !important;}
.expman-frontend .widefat{border:1px solid #c7d1e0;border-radius:8px;overflow:hidden;background:#fff;}
.expman-frontend .widefat{table-layout:auto;width:100%;}
.expman-frontend .widefat thead th{background:#2f5ea8;color:#fff;border-bottom:2px solid #244b86;padding:8px;}
.expman-frontend .widefat thead th a{color:#fff;}
.expman-frontend .widefat tbody td{padding:8px;border-bottom:1px solid #e3e7ef;overflow-wrap:anywhere;word-break:break-word;}
.expman-frontend .widefat th,.expman-frontend .widefat td{text-align:right;vertical-align:middle;}
.expman-row-alt td{background:#f6f8fc;}
.expman-inline-form td{border-top:1px solid #e3e7ef;}
.expman-details td{border-top:1px solid #e3e7ef;background:#f4f6fb;}
.expman-frontend .button,.expman-frontend .button.button-primary{border-radius:6px;border:1px solid #254d8c;background:#2f5ea8;color:#fff;box-shadow:0 1px 0 rgba(0,0,0,0.05);padding:6px 12px;height:auto;}
.expman-frontend .button:not(.button-primary){background:#eef3fb;border-color:#9fb3d9;color:#1f3b64;}
.expman-frontend .button:hover,.expman-frontend .button.button-primary:hover{background:#264f8f;color:#fff;}
.expman-frontend .button:not(.button-primary):hover{background:#dfe9f7;color:#1f3b64;}
.expman-days-pill{display:inline-flex;align-items:center;justify-content:center;min-width:36px;padding:3px 10px;border-radius:999px;font-weight:700;font-size:12px;line-height:1;}
.expman-days-green{background:transparent;}
.expman-days-yellow{background:#ffe4b8;color:#7a4c11;}
.expman-days-red{background:#ffd1d1;color:#7a1f1f;}
.expman-days-unknown{background:#e2e6eb;color:#2b3f5c;}
.expman-center{text-align:center !important;}
.expman-serial-col{width:20ch;}
.expman-customer-col{width:8ch;}
</style>';
        echo '<style>.expman-frontend.expman-firewalls input,.expman-frontend.expman-firewalls select{height:28px!important;line-height:28px!important;padding:2px 6px!important;font-size:13px!important}.expman-frontend.expman-firewalls textarea{min-height:60px!important;font-size:13px!important;padding:6px!important}.expman-frontend.expman-firewalls .button{padding:4px 10px!important;height:30px!important}</style>';
        echo '<div class="expman-frontend expman-firewalls" style="direction:rtl;">';

        if ( class_exists( 'Expman_Nav' ) ) {
            Expman_Nav::render_public_nav( $this->page->get_option_key() );
        }

        echo '<h2 style="margin-top:10px;">חומות אש</h2>';
        $this->render_summary_cards();
        echo '<script>(function(){
          function setActive(status){
            document.querySelectorAll(".expman-summary-card").forEach(function(card){
              card.setAttribute("data-active", card.getAttribute("data-expman-status")===status ? "1" : "0");
            });
          }
          function applyFilter(status){
            document.querySelectorAll(".expman-table-wrap").forEach(function(wrap){
              wrap.dataset.expmanStatusFilter = status;
              wrap.querySelectorAll("tr.expman-row").forEach(function(row){
                var rowStatus=row.getAttribute("data-expman-status")||"";
                var rowId=row.getAttribute("data-expman-row-id");
                var show = (status === "all") || (rowStatus === status);
                row.style.display = show ? "" : "none";
                if(rowId){
                  var inline=wrap.querySelector("tr.expman-inline-form[data-for=\'"+rowId+"\']");
                  var detail=wrap.querySelector("tr.expman-details[data-for=\'"+rowId+"\']");
                  if(inline){inline.style.display = "none";}
                  if(detail){detail.style.display = "none";}
                }
              });
            });
          }
          document.querySelectorAll(".expman-summary-card, .expman-summary-meta").forEach(function(card){
            card.addEventListener("click",function(){
              var status=card.getAttribute("data-expman-status");
              if(!status){return;}
              setActive(status);
              applyFilter(status);
            });
          });
          setActive("all");
          applyFilter("all");
        })();</script>';

        if ( ! empty( $errors ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( implode( ' | ', (array) $errors ) ) . '</p></div>';
        }

        $assign_failures = get_transient( 'expman_firewalls_assign_failures' );
        if ( ! empty( $assign_failures ) ) {
            delete_transient( 'expman_firewalls_assign_failures' );
            $lines = array();
            foreach ( (array) $assign_failures as $failure ) {
                $serial = $failure['serial_number'] ?? '';
                $msg = $failure['error'] ?? '';
                $label = $serial !== '' ? "({$serial})" : '';
                $lines[] = trim( "שורה {$failure['stage_id']} {$label}: {$msg}" );
            }
            echo '<div class="notice notice-error"><p>' . esc_html( implode( ' | ', $lines ) ) . '</p></div>';
        }

        $batch_id = get_transient( 'expman_firewalls_import_batch' );
        if ( $batch_id ) {
            delete_transient( 'expman_firewalls_import_batch' );
            $assign_url = add_query_arg(
                array(
                    'tab'   => 'assign',
                    'batch' => $batch_id,
                ),
                remove_query_arg( array( 'expman_msg', 'tab', 'batch' ) )
            );
            echo '<div class="notice notice-info"><p>';
            echo 'ייבוא הועבר לטבלת שיוכים. ';
            echo '<a class="button" href="' . esc_url( $assign_url ) . '">מעבר לשיוכים</a>';
            echo '</p></div>';
        }

        if ( ! empty( $_GET['expman_msg'] ) ) {
            $msg = rawurldecode( (string) wp_unslash( $_GET['expman_msg'] ) );
            echo '<div class="notice notice-success"><p>' . esc_html( sanitize_text_field( $msg ) ) . '</p></div>';
        }

        foreach ( $this->page->get_notices() as $notice ) {
            $type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
            echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>';
        }

        $active = $this->get_active_tab( $show_bulk_tab );
        $this->render_internal_tabs( $active, $show_bulk_tab );

        echo '<div data-expman-panel="main"' . ( $active === 'main' ? '' : ' style="display:none;"' ) . '>';
        $this->render_main_tab();
        echo '</div>';

        if ( $show_bulk_tab ) {
            echo '<div data-expman-panel="bulk"' . ( $active === 'bulk' ? '' : ' style="display:none;"' ) . '>';
            $this->render_bulk_tab();
            echo '</div>';
        }

        echo '<div data-expman-panel="assign"' . ( $active === 'assign' ? '' : ' style="display:none;"' ) . '>';
        $this->render_assign_tab();
        echo '</div>';

        echo '<div data-expman-panel="settings"' . ( $active === 'settings' ? '' : ' style="display:none;"' ) . '>';
        $this->render_settings_tab();
        echo '</div>';

        echo '<div data-expman-panel="trash"' . ( $active === 'trash' ? '' : ' style="display:none;"' ) . '>';
        $this->render_trash_tab();
        echo '</div>';

        echo '<div data-expman-panel="archive"' . ( $active === 'archive' ? '' : ' style="display:none;"' ) . '>';
        $this->render_archive_tab();
        echo '</div>';

        echo '<div data-expman-panel="logs"' . ( $active === 'logs' ? '' : ' style="display:none;"' ) . '>';
        $this->render_logs_tab();
        echo '</div>';

        echo '<script>(function(){document.addEventListener("click",function(e){var t=e.target;if(t && t.matches("[data-expman-cancel-new]")){e.preventDefault();var f=document.querySelector(".expman-fw-form-wrap");if(f){f.style.display="none";}}if(t && t.matches("[data-expman-cancel-edit]")){e.preventDefault();document.querySelectorAll(".expman-inline-edit").forEach(function(r){r.remove();});}});})();</script>';
        echo '<div style="margin-top:14px;font-size:11px;color:#666;">Expiry Manager v ' . esc_html( $this->page->get_version() ) . '</div>';
        echo '</div>';
    }

    private function get_active_tab( $show_bulk_tab = true ) {
        $allowed = array( 'main', 'assign', 'settings', 'trash', 'logs', 'archive' );
        if ( $show_bulk_tab ) {
            $allowed[] = 'bulk';
        }
        $tab = sanitize_key( $_REQUEST['tab'] ?? 'main' );
        return in_array( $tab, $allowed, true ) ? $tab : 'main';
    }

    private function render_internal_tabs( $active, $show_bulk_tab ) {
        // JS tabs (no page reload)
        $tabs = array(
            'main'     => 'רשימה ראשית',
        );
        if ( $show_bulk_tab ) {
            $tabs['bulk'] = 'עריכה קבוצתית';
        }
        $tabs['assign'] = 'שיוך לאחר ייבוא';
        $tabs['settings'] = 'הגדרות';
        $tabs['logs'] = 'לוגים';
        $tabs['trash'] = 'סל מחזור';
        $tabs['archive'] = 'ארכיון';

        echo '<style>
        .expman-internal-tabs{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin:10px 0;padding:8px;border-radius:10px;background:#f4f7fb;border:1px solid #d9e3f2;}
        .expman-internal-tabs .expman-tab-btn{display:inline-block;padding:8px 14px;border-radius:8px;border:1px solid #9fb3d9;text-decoration:none;font-weight:700;background:#eef3fb;color:#1f3b64;}
        .expman-internal-tabs .expman-tab-btn[data-active="1"]{background:#2f5ea8;color:#fff;border-color:#2f5ea8;}
        </style>';

        echo '<div class="expman-internal-tabs">';
        foreach ( $tabs as $k => $label ) {
            $is = ( $k === $active ) ? '1' : '0';
            $href = add_query_arg( 'tab', $k, remove_query_arg( 'tab' ) );
            echo '<a href="' . esc_url( $href ) . '" class="expman-tab-btn" data-expman-tab="' . esc_attr( $k ) . '" data-active="' . esc_attr( $is ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div>';

        echo '<script>(function(){
          const dataEl = document.currentScript.previousElementSibling;
          function show(k){
            document.querySelectorAll("[data-expman-panel]").forEach(p=>{p.style.display=(p.getAttribute("data-expman-panel")==k)?"block":"none";});
            document.querySelectorAll(".expman-tab-btn").forEach(a=>{a.style.opacity=(a.getAttribute("data-expman-tab")==k)?"1":"0.85";});
            const url=new URL(window.location.href);
            url.searchParams.set("tab",k);
            history.replaceState(null,"",url.toString());
          }
          document.querySelectorAll(".expman-tab-btn").forEach(a=>a.addEventListener("click",function(e){e.preventDefault();show(a.getAttribute("data-expman-tab"));}));
          const params=new URLSearchParams(window.location.search);
          var k=params.get("tab")||"main";
          if(!document.querySelector("[data-expman-panel=\""+k+"\"]")){k="main";}
          show(k);
        })();</script>' . "\n";
    }

    private function is_bulk_tab_enabled() {
        $settings = get_option( $this->page->get_option_key(), array() );
        return ! isset( $settings['show_bulk_tab'] ) || $settings['show_bulk_tab'];
    }

    private function common_filters_from_get() {
        return array(
            'customer_number' => sanitize_text_field( $_GET['f_customer_number'] ?? '' ),
            'customer_name'   => sanitize_text_field( $_GET['f_customer_name'] ?? '' ),
            'branch'          => sanitize_text_field( $_GET['f_branch'] ?? '' ),
            'serial_number'   => sanitize_text_field( $_GET['f_serial_number'] ?? '' ),
            'is_managed'      => isset( $_GET['f_is_managed'] ) ? sanitize_text_field( $_GET['f_is_managed'] ) : '',
            'track_only'      => isset( $_GET['f_track_only'] ) ? sanitize_text_field( $_GET['f_track_only'] ) : '',
            'vendor'          => sanitize_text_field( $_GET['f_vendor'] ?? '' ),
            'model'           => sanitize_text_field( $_GET['f_model'] ?? '' ),
            'expiry_date'     => sanitize_text_field( $_GET['f_expiry_date'] ?? '' ),
        );
    }

    private function get_per_page() {
        $per_page = intval( $_GET['per_page'] ?? 20 );
        $allowed = array( 20, 50, 100, 200 );
        if ( ! in_array( $per_page, $allowed, true ) ) {
            $per_page = 20;
        }
        return $per_page;
    }

    private function render_main_tab() {
        $actions = $this->page->get_actions();
        $filters = $this->common_filters_from_get();
        $orderby = sanitize_key( $_GET['orderby'] ?? 'expiry_date' );
        $order   = sanitize_key( $_GET['order'] ?? 'ASC' );
        $per_page = $this->get_per_page();

        $base = remove_query_arg( array( 'expman_msg' ) );
        $clear_url = remove_query_arg( array(
            'f_customer_number','f_customer_name','f_branch','f_serial_number','f_is_managed','f_track_only','f_vendor','f_model','f_expiry_date','orderby','order','highlight','per_page'
        ), $base );

        echo '<div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin:10px 0;">';
        echo '<button type="button" class="expman-btn" id="expman-add-toggle">הוספת חומת אש</button>';
        echo '</div>';

        // Add form container (hidden by default)
        echo '<div id="expman-add-form-wrap" style="display:none;border:1px solid #e6e6e6;border-radius:10px;padding:12px;margin:10px 0;background:#fff;">';
        $this->render_form( 0 );
        echo '</div>';

        $managed_filters = $filters;
        $managed_filters['is_managed'] = '1';

        echo '<h3>חומות אש בניהול</h3>';
        $managed_orderby = $orderby !== 'expiry_date' ? $orderby : 'days_to_renew';
        $managed_order   = $orderby !== 'expiry_date' ? $order : 'ASC';
        $rows = $actions->get_firewalls_rows( $managed_filters, $managed_orderby, $managed_order, 'active', 0, $per_page, 0 );
        $this->render_table(
            $rows,
            $managed_filters,
            $managed_orderby,
            $managed_order,
            'active',
            $clear_url,
            array(
                'show_filters'        => true,
                'show_status_cols'    => false,
                'show_status_filters' => false,
                'hidden_filters'      => array(
                    'f_is_managed' => '1',
                    'f_track_only' => '0',
                ),
            )
        );

        $tracking_filters = $filters;
        $tracking_filters['is_managed'] = '';
        $tracking_filters['track_only'] = '1';

        echo '<h3 style="margin-top:18px;">חומות אש למעקב</h3>';
        $rows = $actions->get_firewalls_rows( $tracking_filters, $orderby, $order, 'active', 1, $per_page, 0 );
        $this->render_table(
            $rows,
            $tracking_filters,
            $orderby,
            $order,
            'active',
            $clear_url,
            array(
                'show_filters'        => true,
                'show_status_cols'    => false,
                'show_status_filters' => false,
                'hidden_filters'      => array(
                    'f_track_only' => '1',
                ),
            )
        );

        // JS: toggle add form + inline edit
        echo '<script>(function(){
          const dataEl = document.currentScript.previousElementSibling;';
        echo 'const btn=document.getElementById("expman-add-toggle"); const wrap=document.getElementById("expman-add-form-wrap");';
        echo 'if(btn&&wrap){btn.addEventListener("click",()=>{wrap.style.display=(wrap.style.display==="none"||wrap.style.display==="")?"block":"none"; window.scrollTo({top:wrap.getBoundingClientRect().top+window.scrollY-80,behavior:"smooth"});});}';
        echo "document.addEventListener('click',function(e){var c=e.target.closest('.expman-cancel-inline'); if(!c) return; e.preventDefault(); var tr=c.closest('tr.expman-inline-form'); if(tr) tr.style.display='none';});";
        echo 'document.querySelectorAll(".expman-edit-btn").forEach(function(b){b.addEventListener("click",function(e){e.preventDefault();e.stopPropagation();';
        echo 'const tr=b.closest("tr"); const id=b.getAttribute("data-id"); const target=document.querySelector("tr.expman-inline-form[data-for=\'"+id+"\']");';
        echo 'document.querySelectorAll("tr.expman-inline-form").forEach(x=>{if(x!==target) x.style.display="none";});';
        echo 'if(target){target.style.display=(target.style.display==="none"||target.style.display==="")?"table-row":"none";';
        echo 'if(target.style.display==="table-row"){window.scrollTo({top:target.getBoundingClientRect().top+window.scrollY-120,behavior:"smooth"});} }';
        echo '});});';
        echo '})();</script>';
    }

    private function render_bulk_tab() {
        global $wpdb;
        $assets_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FORTICLOUD_ASSETS;

        $assets = $wpdb->get_results(
            "SELECT * FROM {$assets_table} WHERE customer_id IS NULL ORDER BY updated_at DESC, id DESC"
        );

        echo '<div style="margin-bottom:14px;">';
        echo '<form method="post" style="display:inline-block;margin:0 0 10px 0;">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="expman_action" value="sync_forticloud_assets">';
        echo '<input type="hidden" name="tab" value="bulk">';
        echo '<button type="submit" class="button button-primary">סנכרן נכסים מ-FortiCloud</button>';
        echo '</form>';
        echo '<p style="margin-top:6px;color:#555;">מוצגים נכסים שאינם משויכים. לאחר שיוך ייווצר/יעודכן רישום בטבלת חומות אש.</p>';
        echo '</div>';

        if ( empty( $assets ) ) {
            echo '<div class="notice notice-info"><p>אין נכסים לא משויכים.</p></div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>מספר סידורי</th>';
        echo '<th>שם לקוח</th>';
        echo '<th>סניף</th>';
        echo '<th>תיאור</th>';
        echo '<th>שייך ללקוח</th>';
        echo '</tr></thead><tbody>';

        foreach ( $assets as $asset ) {
            echo '<tr>';
            $customer_label_parts = array();
            if ( ! empty( $asset->customer_number_snapshot ) ) {
                $customer_label_parts[] = $asset->customer_number_snapshot;
            }
            if ( ! empty( $asset->customer_name_snapshot ) ) {
                $customer_label_parts[] = $asset->customer_name_snapshot;
            }
            $customer_label = trim( implode( ' - ', $customer_label_parts ) );
            $branch_label = '';
            if ( ! empty( $asset->asset_groups ) ) {
                $branch_label = $asset->asset_groups;
            } elseif ( ! empty( $asset->folder_id ) ) {
                $branch_label = $asset->folder_id;
            }

            echo '<td>' . esc_html( $asset->serial_number ) . '</td>';
            echo '<td>' . esc_html( $customer_label ) . '</td>';
            echo '<td>' . esc_html( $branch_label ) . '</td>';
            echo '<td>' . esc_html( $asset->description ) . '</td>';
            echo '<td class="expman-customer-cell">';
            echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0;">';
            wp_nonce_field( 'expman_firewalls' );
            echo '<input type="hidden" name="expman_action" value="map_forticloud_asset">';
            echo '<input type="hidden" name="tab" value="bulk">';
            echo '<input type="hidden" name="asset_id" value="' . esc_attr( $asset->id ) . '">';
            echo '<input type="hidden" name="customer_id" class="expman-customer-id" value="">';
            echo '<input type="text" class="expman-customer-search" placeholder="חפש לקוח..." style="min-width:220px;">';
            echo '<div class="expman-customer-results" style="position:relative;"></div>';
            echo '<button type="submit" class="button">שייך לקוח</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        $ajax = esc_url( admin_url( 'admin-ajax.php' ) );
        $nonce = wp_create_nonce( 'expman_customer_search' );
        echo '<script>(function(){
          const ajax="' . esc_js( $ajax ) . '";
          const nonce="' . esc_js( $nonce ) . '";
          function fetchCustomers(query, cb){
            const url=ajax+"?action=expman_customer_search&nonce="+encodeURIComponent(nonce)+"&q="+encodeURIComponent(query);
            fetch(url).then(r=>r.json()).then(d=>cb(d.items||[])).catch(()=>cb([]));
          }
          document.addEventListener("input",function(e){
            if(!e.target.classList.contains("expman-customer-search")) return;
            const input=e.target;
            const cell=input.closest(".expman-customer-cell");
            const results=cell.querySelector(".expman-customer-results");
            const q=input.value.trim();
            results.innerHTML="";
            if(q.length<2){return;}
            fetchCustomers(q,function(items){
              results.innerHTML="";
              const wrap=document.createElement("div");
              wrap.style.position="absolute";
              wrap.style.background="#fff";
              wrap.style.border="1px solid #ddd";
              wrap.style.borderRadius="6px";
              wrap.style.zIndex="999";
              wrap.style.minWidth="220px";
              items.forEach(function(it){
                const btn=document.createElement("button");
                btn.type="button";
                btn.textContent=it.customer_number+" - "+it.customer_name;
                btn.style.display="block";
                btn.style.width="100%";
                btn.style.textAlign="right";
                btn.style.padding="6px 8px";
                btn.style.border="0";
                btn.style.background="transparent";
                btn.addEventListener("click",function(){
                  input.value=it.customer_number+" - "+it.customer_name;
                  cell.querySelector(".expman-customer-id").value=it.id;
                  results.innerHTML="";
                });
                wrap.appendChild(btn);
              });
              results.appendChild(wrap);
            });
          });
          document.addEventListener("click",function(e){
            if(e.target.classList.contains("expman-customer-search")) return;
            document.querySelectorAll(".expman-customer-results").forEach(r=>{r.innerHTML="";});
          });
        })();</script>';
    }

    private function render_assign_tab() {
        $actions = $this->page->get_actions();
        $batch_id = sanitize_text_field( $_GET['batch'] ?? '' );

        echo '<h3>שיוך לאחר ייבוא</h3>';
        echo '<p style="color:#555;">בחר באצ׳ ייבוא להצגה, ערוך את השדות ולחץ “שיוך” לשמירה.</p>';

        echo '<form method="get" style="margin-bottom:12px;">';
        echo '<input type="hidden" name="tab" value="assign">';
        echo '<label style="display:inline-block;margin-left:8px;">Batch ID:</label>';
        echo '<input type="text" name="batch" value="' . esc_attr( $batch_id ) . '" style="min-width:280px;">';
        echo '<button class="button" type="submit" style="margin-right:6px;">טען</button>';
        echo '</form>';

        if ( $batch_id === '' ) {
            echo '<div class="notice notice-info"><p>הזן Batch ID כדי להציג שורות שיוך.</p></div>';
            return;
        }

        $rows = $actions->get_import_stage_rows( $batch_id );
        if ( empty( $rows ) ) {
            echo '<div class="notice notice-warning"><p>לא נמצאו שורות עבור ה־Batch הזה.</p></div>';
            return;
        }

        echo '<form method="post" style="margin-bottom:12px;" id="expman-assign-bulk">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="expman_action" value="assign_import_stage_bulk">';
        echo '<input type="hidden" name="batch" value="' . esc_attr( $batch_id ) . '">';
        echo '<div class="expman-bulk-inputs"></div>';
        echo '<button class="button button-primary" type="submit">שיוך הכל</button>';
        echo '</form>';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>סטטוס</th>';
        echo '<th>לקוח (חיפוש)</th>';
        echo '<th>מספר לקוח</th>';
        echo '<th>שם לקוח</th>';
        echo '<th>מספר סידורי</th>';
        echo '<th>סניף</th>';
        echo '<th>ניהול</th>';
        echo '<th>מעקב</th>';
        echo '<th>דגם</th>';
        echo '<th>תפוגה</th>';
        echo '<th>גישה</th>';
        echo '<th>הערות</th>';
        echo '<th>פעולה</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $status = (string) ( $row->status ?? 'pending' );
            echo '<tr data-expman-stage="' . esc_attr( $row->id ) . '" data-expman-customer-number="' . esc_attr( $row->customer_number ?? '' ) . '" data-expman-customer-name="' . esc_attr( $row->customer_name ?? '' ) . '">';
            echo '<td>' . esc_html( $status ) . '</td>';
            echo '<td>';
            echo '<input type="text" class="expman-stage-customer-search" placeholder="חפש לקוח..." style="min-width:200px;">';
            echo '<div class="expman-stage-customer-results" style="position:relative;"></div>';
            echo '</td>';
            echo '<td><input type="text" name="customer_number" value="' . esc_attr( $row->customer_number ?? '' ) . '" form="expman-stage-' . esc_attr( $row->id ) . '" class="expman-stage-customer-number"></td>';
            echo '<td><input type="text" name="customer_name" value="' . esc_attr( $row->customer_name ?? '' ) . '" form="expman-stage-' . esc_attr( $row->id ) . '" class="expman-stage-customer-name"></td>';
            echo '<td><input type="text" name="serial_number" value="' . esc_attr( $row->serial_number ?? '' ) . '" form="expman-stage-' . esc_attr( $row->id ) . '"></td>';
            echo '<td><input type="text" name="branch" value="' . esc_attr( $row->branch ?? '' ) . '" form="expman-stage-' . esc_attr( $row->id ) . '"></td>';
            echo '<td><select name="is_managed" form="expman-stage-' . esc_attr( $row->id ) . '">';
            echo '<option value="1" ' . selected( $row->is_managed, 1, false ) . '>שלנו</option>';
            echo '<option value="0" ' . selected( $row->is_managed, 0, false ) . '>לא שלנו</option>';
            echo '</select></td>';
            echo '<td><select name="track_only" form="expman-stage-' . esc_attr( $row->id ) . '">';
            echo '<option value="0" ' . selected( $row->track_only, 0, false ) . '>לא</option>';
            echo '<option value="1" ' . selected( $row->track_only, 1, false ) . '>כן</option>';
            echo '</select></td>';
            echo '<td><input type="text" name="model" value="' . esc_attr( $row->model ?? '' ) . '" form="expman-stage-' . esc_attr( $row->id ) . '"></td>';
            echo '<td><input type="date" name="expiry_date" value="' . esc_attr( $row->expiry_date ?? '' ) . '" form="expman-stage-' . esc_attr( $row->id ) . '"></td>';
            echo '<td><input type="text" name="access_url" value="' . esc_attr( $row->access_url ?? '' ) . '" form="expman-stage-' . esc_attr( $row->id ) . '"></td>';
            echo '<td><textarea name="notes" rows="2" form="expman-stage-' . esc_attr( $row->id ) . '">' . esc_textarea( $row->notes ?? '' ) . '</textarea></td>';
            echo '<td>';
            echo '<form method="post" id="expman-stage-' . esc_attr( $row->id ) . '">';
            wp_nonce_field( 'expman_firewalls' );
            echo '<input type="hidden" name="expman_action" value="assign_import_stage">';
            echo '<input type="hidden" name="tab" value="assign">';
            echo '<input type="hidden" name="batch" value="' . esc_attr( $batch_id ) . '">';
            echo '<input type="hidden" name="stage_id" value="' . esc_attr( $row->id ) . '">';
            echo '<input type="hidden" name="customer_id" class="expman-stage-customer-id" value="' . esc_attr( $row->customer_id ?? '' ) . '">';
            echo '<button class="button button-primary" type="submit">שיוך</button>';
            if ( $status === 'failed' && ! empty( $row->last_error ) ) {
                echo '<div style="margin-top:6px;color:#b32d2e;font-size:12px;">' . esc_html( $row->last_error ) . '</div>';
            }
            echo '</form>';
            echo '<form method="post" style="margin-top:6px;">';
            wp_nonce_field( 'expman_firewalls' );
            echo '<input type="hidden" name="expman_action" value="delete_import_stage">';
            echo '<input type="hidden" name="tab" value="assign">';
            echo '<input type="hidden" name="batch" value="' . esc_attr( $batch_id ) . '">';
            echo '<input type="hidden" name="stage_id" value="' . esc_attr( $row->id ) . '">';
            echo '<button class="button" type="submit" onclick="return confirm(\'למחוק שורת שיוך?\');">מחיקה</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        $ajax = esc_url( admin_url( 'admin-ajax.php' ) );
        $nonce = wp_create_nonce( 'expman_customer_search' );
        echo '<script>(function(){
          const ajax="' . esc_js( $ajax ) . '";
          const nonce="' . esc_js( $nonce ) . '";
          function fetchCustomers(query, cb){
            const url=ajax+"?action=expman_customer_search&nonce="+encodeURIComponent(nonce)+"&q="+encodeURIComponent(query);
            fetch(url).then(r=>r.json()).then(d=>cb(d.items||[])).catch(()=>cb([]));
          }
          function markRowChanged(row){
            if(!row){return;}
            row.dataset.expmanChanged="1";
          }
          document.querySelectorAll(".expman-stage-customer-number, .expman-stage-customer-name").forEach(function(input){
            input.addEventListener("input",function(){
              const row=input.closest("tr");
              markRowChanged(row);
            });
          });
          document.querySelectorAll(".expman-stage-customer-search").forEach(function(input){
            input.addEventListener("input",function(){
              const cell=input.closest("tr");
              const results=cell.querySelector(".expman-stage-customer-results");
              const q=input.value.trim();
              results.innerHTML="";
              if(q.length<2){return;}
              fetchCustomers(q,function(items){
                results.innerHTML="";
                const wrap=document.createElement("div");
                wrap.style.position="absolute";
                wrap.style.background="#fff";
                wrap.style.border="1px solid #ddd";
                wrap.style.borderRadius="6px";
                wrap.style.zIndex="999";
                wrap.style.minWidth="220px";
                items.forEach(function(it){
                  const btn=document.createElement("button");
                  btn.type="button";
                  btn.textContent=it.customer_number+" - "+it.customer_name;
                  btn.style.display="block";
                  btn.style.width="100%";
                  btn.style.textAlign="right";
                  btn.style.padding="6px 8px";
                  btn.style.border="0";
                  btn.style.background="transparent";
                  btn.addEventListener("click",function(){
                    input.value=it.customer_number+" - "+it.customer_name;
                    cell.querySelector(".expman-stage-customer-id").value=it.id;
                    cell.querySelector("input[name=customer_number]").value=it.customer_number;
                    cell.querySelector("input[name=customer_name]").value=it.customer_name;
                    markRowChanged(cell);
                    results.innerHTML="";
                  });
                  wrap.appendChild(btn);
                });
                results.appendChild(wrap);
              });
            });
          });
          document.addEventListener("click",function(e){
            if(e.target.classList.contains("expman-stage-customer-search")) return;
            document.querySelectorAll(".expman-stage-customer-results").forEach(r=>{r.innerHTML="";});
          });
          const bulkForm=document.getElementById("expman-assign-bulk");
          if(bulkForm){
            bulkForm.addEventListener("submit",function(e){
              const inputsWrap=bulkForm.querySelector(".expman-bulk-inputs");
              inputsWrap.innerHTML="";
              let added=0;
              document.querySelectorAll("tr[data-expman-stage]").forEach(function(row){
                if(row.dataset.expmanChanged!=="1"){return;}
                const stageId=row.getAttribute("data-expman-stage");
                const customerNumber=row.querySelector(".expman-stage-customer-number").value.trim();
                const customerName=row.querySelector(".expman-stage-customer-name").value.trim();
                const customerId=row.querySelector(".expman-stage-customer-id").value;
                if(!customerNumber || !customerName){return;}
                const idInput=document.createElement("input");
                idInput.type="hidden";
                idInput.name="bulk_ids[]";
                idInput.value=stageId;
                inputsWrap.appendChild(idInput);
                [["customer_number", customerNumber], ["customer_name", customerName], ["customer_id", customerId]].forEach(function(pair){
                  const inp=document.createElement("input");
                  inp.type="hidden";
                  inp.name="bulk["+stageId+"]["+pair[0]+"]";
                  inp.value=pair[1];
                  inputsWrap.appendChild(inp);
                });
                added++;
              });
              if(added === 0){
                e.preventDefault();
                alert("לא נמצאו שורות שעודכנו לשיוך.");
              }
            });
          }
        })();</script>';
    }

    private function render_settings_tab() {
        $forticloud = $this->page->get_forticloud();
        $settings = $forticloud ? $forticloud->get_forticloud_settings() : array();
        $api_id = $settings['api_id'] ?? '';
        $client_id = $settings['client_id'] ?? 'assetmanagement';
        $base_url = $settings['base_url'] ?? '';
        $has_secret = ! empty( $settings['api_secret']['value'] );

        echo '<form method="post" style="max-width:680px;">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="expman_action" value="save_forticloud_settings">';
        echo '<input type="hidden" name="tab" value="settings">';

        echo '<h3>FortiCloud</h3>';
        echo '<p style="max-width:640px;color:#555;">כדי שהסנכרון יעבוד יש ליצור IAM API User עם הרשאות Asset Management ולוודא שהוגדר Client ID כ-<strong>assetmanagement</strong>. לאחר שמירת ההגדרות ניתן לבצע סנכרון מהטאב עריכה קבוצתית.</p>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="forticloud_api_id">forticloud_api_id</label></th><td><input type="text" id="forticloud_api_id" name="forticloud_api_id" value="' . esc_attr( $api_id ) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="forticloud_client_id">forticloud_client_id</label></th><td><input type="text" id="forticloud_client_id" name="forticloud_client_id" value="' . esc_attr( $client_id ) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="forticloud_base_url">forticloud_base_url</label></th><td><input type="url" id="forticloud_base_url" name="forticloud_base_url" value="' . esc_attr( $base_url ) . '" class="regular-text" placeholder="https://api.forticloud.com"></td></tr>';
        echo '<tr><th>forticloud_api_secret</th><td>';
        if ( $has_secret ) {
            echo '<div style="margin-bottom:6px;color:#666;">סיסמה שמורה.</div>';
        }
        echo '<button type="button" class="button" id="expman-toggle-secret">עדכן סיסמה</button>';
        echo '<div id="expman-secret-wrap" style="display:none;margin-top:8px;">';
        echo '<input type="password" name="forticloud_api_secret" autocomplete="new-password" class="regular-text" placeholder="סיסמה חדשה">';
        echo '</div>';
        echo '</td></tr>';
        echo '</tbody></table>';

        echo '<p><button type="submit" class="button button-primary">שמירה</button></p>';
        echo '</form>';

        echo '<script>(function(){var btn=document.getElementById("expman-toggle-secret");var wrap=document.getElementById("expman-secret-wrap");if(btn&&wrap){btn.addEventListener("click",function(){wrap.style.display=wrap.style.display==="none"?"block":"none";});}})();</script>';

        echo '<hr style="margin:24px 0;">';
        echo '<h3>ייבוא / ייצוא (Excel CSV)</h3>';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">';
        echo '<form method="post" style="margin:0;">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="expman_action" value="export_csv">';
        echo '<button class="expman-btn secondary" type="submit">ייצוא ל-Excel (CSV)</button>';
        echo '</form>';

        echo '<form method="post" enctype="multipart/form-data" style="margin:0;">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="expman_action" value="import_csv">';
        echo '<input type="hidden" name="tab" value="assign">';
        echo '<input type="file" name="firewalls_file" accept=".csv" required>';
        echo '<button class="expman-btn secondary" type="submit">ייבוא מ-Excel (CSV)</button>';
        echo '</form>';
        echo '</div>';

        $actions = $this->page->get_actions();
        $types = $actions->get_box_types();
        $vendor_contacts = $actions->get_vendor_contacts();

        echo '<hr style="margin:24px 0;">';
        echo '<h3>פרטי ספק</h3>';
        echo '<form method="post" style="max-width:680px;">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="expman_action" value="save_vendor_contacts">';
        echo '<input type="hidden" name="tab" value="settings">';
        echo '<table class="widefat striped" style="margin-top:10px;">';
        echo '<thead><tr><th>שם איש קשר</th><th>מייל</th></tr></thead><tbody>';
        if ( empty( $vendor_contacts ) ) {
            $vendor_contacts = array( array( 'name' => '', 'email' => '' ) );
        }
        foreach ( $vendor_contacts as $contact ) {
            echo '<tr>';
            echo '<td><input type="text" name="vendor_contact_name[]" value="' . esc_attr( $contact['name'] ?? '' ) . '" style="width:100%;"></td>';
            echo '<td><input type="email" name="vendor_contact_email[]" value="' . esc_attr( $contact['email'] ?? '' ) . '" style="width:100%;"></td>';
            echo '</tr>';
        }
        echo '<tr>';
        echo '<td><input type="text" name="vendor_contact_name[]" placeholder="שם חדש" style="width:100%;"></td>';
        echo '<td><input type="email" name="vendor_contact_email[]" placeholder="email@example.com" style="width:100%;"></td>';
        echo '</tr>';
        echo '</tbody></table>';
        echo '<p style="margin-top:10px;"><button type="submit" class="button button-primary">שמירה</button></p>';
        echo '</form>';

        echo '<hr style="margin:24px 0;">';
        echo '<h3>טבלת יצרן ודגם</h3>';
        echo '<form method="post" style="max-width:680px;">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="expman_action" value="save_box_types">';
        echo '<input type="hidden" name="tab" value="settings">';
        echo '<table class="widefat striped" style="margin-top:10px;">';
        echo '<thead><tr><th>יצרן</th><th>דגם</th><th>מחיקה</th></tr></thead><tbody>';

        if ( empty( $types ) ) {
            echo '<tr><td colspan="3" style="color:#666;">אין רשומות בטבלה.</td></tr>';
        } else {
            foreach ( $types as $type ) {
                echo '<tr>';
                echo '<td><input type="text" name="box_type_vendor[' . esc_attr( $type->id ) . ']" value="' . esc_attr( $type->vendor ) . '" style="width:100%;"></td>';
                echo '<td><input type="text" name="box_type_model[' . esc_attr( $type->id ) . ']" value="' . esc_attr( $type->model ) . '" style="width:100%;"></td>';
                echo '<td style="text-align:center;"><input type="checkbox" name="box_type_delete[' . esc_attr( $type->id ) . ']" value="1"></td>';
                echo '</tr>';
            }
        }

        echo '<tr>';
        echo '<td><input type="text" name="new_box_type_vendor[]" placeholder="יצרן חדש" style="width:100%;"></td>';
        echo '<td><input type="text" name="new_box_type_model[]" placeholder="דגם חדש" style="width:100%;"></td>';
        echo '<td></td>';
        echo '</tr>';
        echo '</tbody></table>';
        echo '<p style="margin-top:10px;"><button type="submit" class="button button-primary">שמירה</button></p>';
        echo '</form>';
    }

    private function render_trash_tab() {
        $actions = $this->page->get_actions();
        $filters = $this->common_filters_from_get();
        $orderby = sanitize_key( $_GET['orderby'] ?? 'deleted_at' );
        $order   = sanitize_key( $_GET['order'] ?? 'DESC' );

        echo '<h3>סל מחזור (חומות אש)</h3>';
        $rows = $actions->get_firewalls_rows( $filters, $orderby, $order, 'trash', null, $this->get_per_page(), 0 );
        $this->render_table( $rows, $filters, $orderby, $order, 'trash', null );
    }

    private function render_archive_tab() {
        $actions = $this->page->get_actions();
        $filters = $this->common_filters_from_get();
        $orderby = sanitize_key( $_GET['orderby'] ?? 'archived_at' );
        $order   = sanitize_key( $_GET['order'] ?? 'DESC' );

        echo '<h3>ארכיון (חומות אש)</h3>';
        $rows = $actions->get_firewalls_rows( $filters, $orderby, $order, 'archive', null, $this->get_per_page(), 0 );
        $base = remove_query_arg( array( 'expman_msg' ) );
        $clear_url = remove_query_arg( array(
            'f_customer_number','f_customer_name','f_branch','f_serial_number','f_is_managed','f_track_only','f_vendor','f_model','f_expiry_date','orderby','order','highlight','per_page'
        ), $base );
        $this->render_table( $rows, $filters, $orderby, $order, 'archive', $clear_url );
    }

    private function render_logs_tab() {
        global $wpdb;
        $logger = $this->page->get_logger();
        $logs_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALL_LOGS;
        $fw_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $logs = $wpdb->get_results( "SELECT l.*, fw.customer_name, fw.serial_number FROM {$logs_table} l LEFT JOIN {$fw_table} fw ON fw.id = l.firewall_id ORDER BY l.id DESC LIMIT 200" );

        echo '<h3>לוגים (חומות אש)</h3>';
        echo '<p style="color:#555;">מציג 200 רשומות אחרונות של יצירה, עדכון, מחיקה וסנכרון.</p>';

        if ( empty( $logs ) ) {
            echo '<div class="notice notice-info"><p>אין לוגים להצגה.</p></div>';
            return;
        }

        echo '<div style="width:100%;overflow-x:auto;">';
        echo '<table class="widefat striped" style="width:100%;">';
        echo '<thead><tr>';
        echo '<th>זמן</th>';
        echo '<th>פעולה</th>';
        echo '<th>רמה</th>';
        echo '<th>שם לקוח</th>';
        echo '<th>מספר סידורי</th>';
        echo '<th>הודעה</th>';
        echo '<th>פרטים</th>';
        echo '</tr></thead><tbody>';

        foreach ( $logs as $log ) {
            $context = $logger->format_log_context( $log->context ?? '' );
            $created_at = '';
            if ( ! empty( $log->created_at ) ) {
                $created_at = date_i18n( 'd/m/Y H:i', strtotime( $log->created_at ) );
            }
            echo '<tr>';
            echo '<td>' . esc_html( $created_at ) . '</td>';
            echo '<td>' . esc_html( $log->action ) . '</td>';
            echo '<td>' . esc_html( $log->level ) . '</td>';
            echo '<td>' . esc_html( $log->customer_name ?? '' ) . '</td>';
            echo '<td>' . esc_html( $log->serial_number ?? '' ) . '</td>';
            echo '<td>' . esc_html( $log->message ) . '</td>';
            echo '<td>' . $context . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_table( $rows, $filters, $orderby, $order, $table_mode, $clear_url = null, $options = array() ) {
        $actions = $this->page->get_actions();
        $vendor_contacts = $actions->get_vendor_contacts();
        $summary = $actions->get_summary_counts( $this->page->get_option_key() );
        $yellow_threshold = intval( $summary['yellow_threshold'] ?? 60 );
        $red_threshold = intval( $summary['red_threshold'] ?? 30 );
        $base = remove_query_arg( array( 'expman_msg' ) );
        $uid  = wp_generate_uuid4();
        $options = wp_parse_args(
            $options,
            array(
                'show_filters'        => true,
                'show_status_cols'    => true,
                'show_status_filters' => true,
                'hidden_filters'      => array(),
            )
        );
        $show_filters = (bool) $options['show_filters'];
        $show_status_cols = (bool) $options['show_status_cols'];
        $show_status_filters = (bool) $options['show_status_filters'];
        $hidden_filters = (array) $options['hidden_filters'];
        $skip_keys = array_fill_keys( array_keys( $hidden_filters ), true );

        echo '<div class="expman-table-wrap" data-expman-table="' . esc_attr( $uid ) . '">';
        if ( $show_filters ) {
            echo '<form method="get" class="expman-filter-form" style="margin:0 0 10px 0;">';
        }

        $vendor_opts = $actions->get_distinct_type_values( 'vendor' );
        $model_opts  = $actions->get_distinct_type_values( 'model' );
        $vendor_sel  = array_filter( array_map( 'trim', explode( ',', (string) ( $filters['vendor'] ?? '' ) ) ) );
        $model_sel   = array_filter( array_map( 'trim', explode( ',', (string) ( $filters['model'] ?? '' ) ) ) );

        $ms_script = '';
        if ( $show_filters ) {
            echo '<div id="expman-ms-data-' . esc_attr( $uid ) . '" style="display:none"'
                . ' data-vendor-options="' . esc_attr( wp_json_encode( $vendor_opts ) ) . '"'
                . ' data-model-options="'  . esc_attr( wp_json_encode( $model_opts ) )  . '"'
                . ' data-vendor-selected="' . esc_attr( wp_json_encode( array_values( $vendor_sel ) ) ) . '"'
                . ' data-model-selected="'  . esc_attr( wp_json_encode( array_values( $model_sel ) ) )  . '"'
                . '></div>';

            $ms_script = '<script>
            (function(){
              const dataEl = document.getElementById("expman-ms-data-' . esc_js( $uid ) . '");
              if(!dataEl){return;}
              const wrap = dataEl.closest(".expman-table-wrap");
              function get(key){try{return JSON.parse(dataEl.dataset[key]||"[]")}catch(e){return []}}
              const optsVendor=get("vendorOptions"), optsModel=get("modelOptions");
              const selVendor=new Set(get("vendorSelected")), selModel=new Set(get("modelSelected"));

              function build(th, key, options, selected){
                if(th.dataset.expmanMsBuilt){return;}
                th.dataset.expmanMsBuilt="1";
                const hidden=document.createElement("input");
                hidden.type="hidden"; hidden.name = key==="vendor" ? "f_vendor" : "f_model";
                hidden.value=Array.from(selected).join(",");

                const btn=document.createElement("button");
                btn.type="button";
                btn.className="expman-btn secondary";
                btn.style.width="100%"; btn.style.height="32px";
                btn.textContent = selected.size ? ("נבחרו " + selected.size) : "בחר...";

                const panel=document.createElement("div");
                panel.className="expman-ms-panel";
                panel.style.position="absolute"; panel.style.zIndex="9999";
                panel.style.background="#fff"; panel.style.border="1px solid #ccc";
                panel.style.borderRadius="10px"; panel.style.padding="10px";
                panel.style.minWidth="240px"; panel.style.maxHeight="260px";
                panel.style.overflow="auto"; panel.style.display="none";

                const search=document.createElement("input");
                search.type="text"; search.placeholder="חיפוש...";
                search.style.width="100%"; search.style.marginBottom="8px";

                const list=document.createElement("div");
                function render(q){
                  list.innerHTML="";
                  const qq=(q||"").toLowerCase();
                  options.filter(v=>!qq||String(v).toLowerCase().includes(qq)).forEach(v=>{
                    const label=document.createElement("label");
                    label.style.display="flex"; label.style.gap="8px"; label.style.alignItems="center";
                    label.style.margin="4px 0";
                    const cb=document.createElement("input");
                    cb.type="checkbox"; cb.checked=selected.has(v);
                    cb.addEventListener("change",()=>{cb.checked?selected.add(v):selected.delete(v);});
                    const span=document.createElement("span"); span.textContent=String(v); span.style.color="#1f3b64";
                    label.appendChild(cb); label.appendChild(span);
                    list.appendChild(label);
                  });
                }
                search.addEventListener("input",()=>render(search.value));
                render("");
                const actions=document.createElement("div");
                actions.style.display="flex"; actions.style.gap="8px"; actions.style.marginTop="10px";

                const clear=document.createElement("button");
                clear.type="button"; clear.className="expman-btn expman-btn-clear"; clear.textContent="נקה";
                clear.addEventListener("click",()=>{selected.clear(); render(search.value);});

                actions.appendChild(clear);
                panel.appendChild(search); panel.appendChild(list); panel.appendChild(actions);

                th.style.position="relative";
                th.appendChild(hidden); th.appendChild(btn); th.appendChild(panel);

                btn.addEventListener("click",()=>{ panel.style.display = panel.style.display==="none"?"block":"none"; });
                document.addEventListener("click",(e)=>{ if(!th.contains(e.target)) panel.style.display="none"; });

                th.closest("form").addEventListener("submit",()=>{ hidden.value=Array.from(selected).join(","); });
              }

              if(!wrap){return;}
              wrap.querySelectorAll("th.expman-ms-wrap[data-ms=vendor]").forEach(th=>build(th,"vendor",optsVendor,selVendor));
              wrap.querySelectorAll("th.expman-ms-wrap[data-ms=model]").forEach(th=>build(th,"model",optsModel,selModel));
            })();
            </script>';

            foreach ( $hidden_filters as $name => $value ) {
                echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
            }

            foreach ( $_GET as $k => $v ) {
                if ( isset( $skip_keys[ $k ] ) ) { continue; }
                if ( strpos( $k, 'f_' ) === 0 || in_array( $k, array( 'orderby','order' ), true ) ) { continue; }
                echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
            }
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';

        $this->th_sort( 'customer_number', 'מספר לקוח', $orderby, $order, $base, 'expman-customer-col' );
        $this->th_sort( 'customer_name', 'שם לקוח', $orderby, $order, $base );
        $this->th_sort( 'branch', 'סניף', $orderby, $order, $base );
        $this->th_sort( 'serial_number', 'מספר סידורי', $orderby, $order, $base, 'expman-align-left expman-serial-col' );
        $this->th_sort( 'days_to_renew', 'ימים לחידוש', $orderby, $order, $base, 'expman-center' );
        $this->th_sort( 'vendor', 'יצרן', $orderby, $order, $base, 'expman-align-left' );
        $this->th_sort( 'model', 'דגם', $orderby, $order, $base, 'expman-align-left' );
        if ( $show_status_cols ) {
            $this->th_sort( 'is_managed', 'ניהול', $orderby, $order, $base );
            $this->th_sort( 'track_only', 'מעקב', $orderby, $order, $base );
        }
        echo '<th>URL</th>';
        echo '<th>פעולות</th>';
        echo '</tr>';

        if ( $show_filters ) {
            // filter row
            echo '<tr class="expman-filter-row">';
            echo '<th class="expman-customer-col"><input style="width:100%" name="f_customer_number" value="' . esc_attr( $filters['customer_number'] ) . '" placeholder="סינון..."></th>';
            echo '<th><input style="width:100%" name="f_customer_name" value="' . esc_attr( $filters['customer_name'] ) . '" placeholder="סינון..."></th>';
            echo '<th><input style="width:100%" name="f_branch" value="' . esc_attr( $filters['branch'] ) . '" placeholder="סינון..."></th>';
            echo '<th class="expman-align-left expman-serial-col"><input style="width:100%" name="f_serial_number" value="' . esc_attr( $filters['serial_number'] ) . '" placeholder="סינון..."></th>';
            echo '<th></th>'; // days_to_renew
            echo '<th class="expman-ms-wrap expman-align-left" data-ms="vendor" style="text-align:left;"></th>';
            echo '<th class="expman-ms-wrap expman-align-left" data-ms="model" style="text-align:left;"></th>';
            if ( $show_status_cols && $show_status_filters ) {
                echo '<th><select name="f_is_managed" style="width:100%;">';
                echo '<option value="">הכל</option>';
                echo '<option value="1" ' . selected( $filters['is_managed'], '1', false ) . '>שלנו</option>';
                echo '<option value="0" ' . selected( $filters['is_managed'], '0', false ) . '>לא שלנו</option>';
                echo '</select></th>';
                echo '<th><select name="f_track_only" style="width:100%;">';
                echo '<option value="">הכל</option>';
                echo '<option value="1" ' . selected( $filters['track_only'], '1', false ) . '>כן</option>';
                echo '<option value="0" ' . selected( $filters['track_only'], '0', false ) . '>לא</option>';
                echo '</select></th>';
            } elseif ( $show_status_cols ) {
                echo '<th></th>';
                echo '<th></th>';
            }
            echo '<th></th>'; // access
            echo '<th style="white-space:nowrap;">';
            $per_page = $this->get_per_page();
            echo '<label style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;">';
            echo '<span style="font-size:12px;color:#1f3b64;">הצג</span>';
            echo '<select name="per_page" style="min-width:80px;">';
            foreach ( array( 20, 50, 100, 200 ) as $opt ) {
                echo '<option value="' . esc_attr( $opt ) . '" ' . selected( $per_page, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
            }
            echo '</select>';
            echo '</label>';
            if ( $clear_url ) {
                echo '<a class="expman-btn secondary" style="display:inline-block;text-decoration:none;" href="' . esc_url( $clear_url ) . '">נקה</a>';
            }
            echo '</th>';
            echo '</tr>';
        }

        echo '</thead><tbody>';

        $column_count = $show_status_cols ? 11 : 9;
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="' . esc_attr( $column_count ) . '">אין נתונים.</td></tr></tbody></table>';
            if ( $show_filters ) {
                echo '</form>';
            }
            echo '</div>';
            return;
        }

        $row_index = 0;
        foreach ( (array) $rows as $r ) {
            $row_index++;
            if ( ! is_null( $r->days_to_renew ) ) {
                $days = intval( $r->days_to_renew );
            } elseif ( ! empty( $r->expiry_date ) ) {
                $today = new DateTimeImmutable( 'today' );
                $expiry = new DateTimeImmutable( $r->expiry_date );
                $days = (int) $today->diff( $expiry )->format( '%r%a' );
            } else {
                $days = '';
            }
            $days_class = '';
            $status_key = 'unknown';
            if ( $days !== '' ) {
                if ( $days <= $red_threshold ) {
                    $days_class = 'expman-days-red';
                    $status_key = 'red';
                } elseif ( $days <= $yellow_threshold ) {
                    $days_class = 'expman-days-yellow';
                    $status_key = 'yellow';
                } else {
                    $status_key = 'green';
                }
            } else {
                $days_class = 'expman-days-unknown';
            }
            if ( intval( $r->track_only ) === 1 ) {
                $days_class = 'expman-days-unknown';
                $status_key = 'unknown';
            }
            if ( intval( $r->track_only ) === 1 ) {
                $days_class = 'expman-days-unknown';
                $status_key = 'unknown';
            }
            $status_key = $days_class !== '' ? str_replace( 'expman-days-', '', $days_class ) : 'unknown';

            $access_btn = '';
            if ( ! empty( $r->access_url ) ) {
                $u = (string) $r->access_url;
                if ( ! preg_match( '/^https?:\/\//i', $u ) ) { $u = 'https://' . ltrim( $u ); }
                $access_btn = '<a class="expman-btn secondary" style="text-decoration:none;padding:6px 10px;" href="' . esc_url( $u ) . '" target="_blank" rel="noopener noreferrer">URL</a>';
            }

            $managed_label = intval( $r->is_managed ) ? 'שלנו' : 'לא שלנו';

            $highlight = '';
            if ( ! empty( $_GET['highlight'] ) && (string) $r->serial_number === (string) sanitize_text_field( wp_unslash( $_GET['highlight'] ) ) ) {
                $highlight = ' expman-serial-highlight';
            }
            $alt_class = ( $row_index % 2 === 0 ) ? ' expman-row-alt' : '';
            echo '<tr class="expman-row' . esc_attr( $alt_class ) . '" style="cursor:pointer;" data-expman-row-id="' . esc_attr( $r->id ) . '" data-expman-status="' . esc_attr( $status_key ) . '" data-expman-customer-number="' . esc_attr( $r->customer_number ?? '' ) . '" data-expman-customer-name="' . esc_attr( $r->customer_name ?? '' ) . '" data-expman-branch="' . esc_attr( $r->branch ?? '' ) . '" data-expman-serial="' . esc_attr( $r->serial_number ?? '' ) . '">';
            echo '<td class="expman-customer-col">' . esc_html( $r->customer_number ?? '' ) . '</td>';
            echo '<td>' . esc_html( $r->customer_name ?? '' ) . '</td>';
            echo '<td>' . esc_html( $r->branch ?? '' ) . '</td>';
            echo '<td class="expman-align-left expman-serial-col' . esc_attr( $highlight ) . '">' . esc_html( $r->serial_number ) . '</td>';
            $days_label = $days !== '' ? $days : '—';
            $pill_class = $days_class !== '' ? $days_class : 'expman-days-green';
            echo '<td class="expman-center"><span class="expman-days-pill ' . esc_attr( $pill_class ) . '">' . esc_html( $days_label ) . '</span></td>';
            echo '<td class="expman-align-left">' . esc_html( $r->vendor ?? '' ) . '</td>';
            echo '<td class="expman-align-left">' . esc_html( $r->model ?? '' ) . '</td>';
            if ( $show_status_cols ) {
                echo '<td>' . esc_html( $managed_label ) . '</td>';
                echo '<td>' . esc_html( intval( $r->track_only ) ? 'כן' : 'לא' ) . '</td>';
            }
            echo '<td></td>';

            echo '<td style="white-space:nowrap;" onclick="event.stopPropagation();">';
            echo '<a href="#" class="expman-btn expman-edit-btn" style="text-decoration:none;padding:6px 10px;" data-id="' . esc_attr( $r->id ) . '">עריכה</a> ';
            if ( $table_mode === 'trash' ) {
                echo '<form method="post" style="display:inline;">';
                wp_nonce_field( 'expman_firewalls' );
                echo '<input type="hidden" name="expman_action" value="restore_firewall">';
                echo '<input type="hidden" name="firewall_id" value="' . esc_attr( $r->id ) . '">';
                echo '<button class="expman-btn secondary" type="submit" style="padding:6px 10px;">שחזור</button>';
                echo '</form>';
                echo '<form method="post" style="display:inline;margin-right:6px;">';
                wp_nonce_field( 'expman_firewalls' );
                echo '<input type="hidden" name="expman_action" value="delete_firewall_permanently">';
                echo '<input type="hidden" name="tab" value="trash">';
                echo '<input type="hidden" name="firewall_id" value="' . esc_attr( $r->id ) . '">';
                echo '<button class="expman-btn secondary" type="submit" style="padding:6px 10px;" onclick="return confirm(\'למחוק לצמיתות?\');">מחיקה לצמיתות</button>';
                echo '</form>';
            } elseif ( $table_mode === 'archive' ) {
                echo '<form method="post" style="display:inline;">';
                wp_nonce_field( 'expman_firewalls' );
                echo '<input type="hidden" name="expman_action" value="restore_archive">';
                echo '<input type="hidden" name="firewall_id" value="' . esc_attr( $r->id ) . '">';
                echo '<button class="expman-btn secondary" type="submit" style="padding:6px 10px;">שחזור</button>';
                echo '</form>';
                echo '<form method="post" style="display:inline;margin-right:6px;">';
                wp_nonce_field( 'expman_firewalls' );
                echo '<input type="hidden" name="expman_action" value="trash_firewall">';
                echo '<input type="hidden" name="firewall_id" value="' . esc_attr( $r->id ) . '">';
                echo '<button class="expman-btn secondary" type="submit" style="padding:6px 10px;" onclick="return confirm(\'להעביר ל־Trash?\');">Trash</button>';
                echo '</form>';
            } else {
                echo '<form method="post" style="display:inline;">';
                wp_nonce_field( 'expman_firewalls' );
                echo '<input type="hidden" name="expman_action" value="trash_firewall">';
                echo '<input type="hidden" name="firewall_id" value="' . esc_attr( $r->id ) . '">';
                echo '<button class="expman-btn secondary" type="submit" style="padding:6px 10px;" onclick="return confirm(\'להעביר ל־Trash?\');">Trash</button>';
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';

            // Inline edit form row (hidden)
            echo '<tr class="expman-inline-form" data-for="' . esc_attr( $r->id ) . '" style="display:none;background:#fff;">';
            echo '<td colspan="' . esc_attr( $column_count ) . '">';
            $this->render_form( intval( $r->id ), $r );
            echo '</td></tr>';

            // Details row (click row expands)
            echo '<tr class="expman-details" data-for="' . esc_attr( $r->id ) . '" style="display:none;background:#fafafa;">';
            echo '<td colspan="' . esc_attr( $column_count ) . '">';
            $created_label = '';
            if ( ! empty( $r->created_at ) ) {
                $created_label = date_i18n( 'd-m-Y', strtotime( $r->created_at ) );
            }
            $contact_button = '';
            if ( ! empty( $vendor_contacts ) ) {
                $contact = $vendor_contacts[0];
                $contact_name = (string) ( $contact['name'] ?? '' );
                $contact_email = (string) ( $contact['email'] ?? '' );
                if ( $contact_email !== '' ) {
                    $subject = 'הצעת מחיר לחידוש חומת אש ' . (string) ( $r->customer_name ?? '' );
                    $body = "שלום {$contact_name}\nאני מבקש הצעת מחיר עבור {$r->serial_number} לשנה נוספת\n\nתודה";
                    $mailto = 'mailto:' . rawurlencode( $contact_email )
                        . '?subject=' . rawurlencode( $subject )
                        . '&body=' . rawurlencode( $body );
                    $contact_button = '<a class="expman-btn secondary" style="text-decoration:none;padding:6px 10px;" href="' . esc_url( $mailto ) . '">שליחת מייל</a>';
                }
            }

            echo '<div style="display:flex;gap:20px;flex-wrap:wrap;">';
            echo '<div style="min-width:260px;"><strong>הערה קבועה:</strong><div style="white-space:pre-wrap;">' . esc_html( (string) $r->notes ) . '</div></div>';
            echo '<div style="min-width:260px;"><strong>הודעה זמנית:</strong><div style="white-space:pre-wrap;">' . esc_html( intval( $r->temp_notice_enabled ) ? (string) $r->temp_notice : '' ) . '</div></div>';
            echo '<div style="min-width:180px;"><strong>תאריך לחידוש:</strong> ' . esc_html( ( ! empty( $r->expiry_date ) ? date_i18n( 'd-m-Y', strtotime( $r->expiry_date ) ) : '' ) ) . '</div>';
            echo '<div style="min-width:200px;"><strong>תאריך רישום:</strong> ' . esc_html( $created_label ) . '</div>';
            echo '<div style="min-width:200px;"><strong>URL:</strong> ' . $access_btn . '</div>';
            if ( $contact_button !== '' ) {
                echo '<div style="min-width:160px;">' . $contact_button . '</div>';
            }
            echo '</div>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        if ( $show_filters ) {
            echo '</form>';
        }
        if ( $ms_script !== '' ) {
            echo $ms_script;
        }
        echo '</div>';

        echo '<script>(function(){
          const wrap = document.querySelector("[data-expman-table=\"' . esc_js( $uid ) . '\"]");
          if(!wrap){return;}
          const filterForm = wrap.querySelector("form.expman-filter-form");
          if(filterForm){
            let t=null;
            const applyLocal=()=>{
              const statusFilter = wrap.dataset.expmanStatusFilter || "all";
              const fNumber=(filterForm.querySelector("input[name=f_customer_number]")?.value||"").toLowerCase();
              const fName=(filterForm.querySelector("input[name=f_customer_name]")?.value||"").toLowerCase();
              const fBranch=(filterForm.querySelector("input[name=f_branch]")?.value||"").toLowerCase();
              const fSerial=(filterForm.querySelector("input[name=f_serial_number]")?.value||"").toLowerCase();
              wrap.querySelectorAll("tr.expman-row").forEach(function(row){
                const n=(row.dataset.expmanCustomerNumber||"").toLowerCase();
                const nm=(row.dataset.expmanCustomerName||"").toLowerCase();
                const b=(row.dataset.expmanBranch||"").toLowerCase();
                const s=(row.dataset.expmanSerial||"").toLowerCase();
                const statusOk = statusFilter === "all" || (row.dataset.expmanStatus || "") === statusFilter;
                const show = statusOk
                  && (!fNumber || n.includes(fNumber))
                  && (!fName || nm.includes(fName))
                  && (!fBranch || b.includes(fBranch))
                  && (!fSerial || s.includes(fSerial));
                row.style.display = show ? "" : "none";
              });
            };
            const submit=()=>{ applyLocal(); if(t){clearTimeout(t);} t=setTimeout(()=>filterForm.submit(), 150); };
            wrap.querySelectorAll(".expman-filter-row input").forEach(function(input){
              input.addEventListener("input", submit);
            });
            wrap.querySelectorAll(".expman-filter-row select").forEach(function(select){
              select.addEventListener("change", submit);
            });
            applyLocal();
          }';
        echo 'wrap.querySelectorAll("tr.expman-row").forEach(function(tr){tr.addEventListener("click",function(){';
        echo 'var id=tr.querySelector("a.expman-edit-btn")?tr.querySelector("a.expman-edit-btn").getAttribute("data-id"):null;';
        echo 'if(!id) return; var d=wrap.querySelector("tr.expman-details[data-for=\'"+id+"\']"); if(!d) return;';
        echo 'd.style.display=(d.style.display==="none"||d.style.display==="")?"table-row":"none";';
        echo '});});';
        echo '})();</script>';
    }

    private function th_sort( $key, $label, $orderby, $order, $base, $class = '' ) {
        $next_order = ( $orderby === $key && strtoupper( $order ) === 'ASC' ) ? 'DESC' : 'ASC';
        $url = add_query_arg( array( 'orderby' => $key, 'order' => $next_order ), $base );
        $class_attr = $class !== '' ? ' class="' . esc_attr( $class ) . '"' : '';
        echo '<th' . $class_attr . '><a href="' . esc_url( $url ) . '" style="text-decoration:none;">' . esc_html( $label ) . '</a></th>';
    }

    private function render_form( $id = 0, $row_obj = null ) {
        $actions = $this->page->get_actions();
        $types = $actions->get_box_types();

        $row = array(
            'customer_id'          => 0,
            'customer_number'      => '',
            'customer_name'        => '',
            'branch'               => '',
            'serial_number'        => '',
            'expiry_date'          => '',
            'is_managed'           => 1,
            'track_only'           => 0,
            'access_url'           => '',
            'notes'                => '',
            'temp_notice_enabled'  => 0,
            'temp_notice'          => '',
        );

        $vendor = '';
        $model  = '';

        if ( $row_obj ) {
            $row['customer_id']         = intval( $row_obj->customer_id );
            $row['customer_number']     = (string) ( $row_obj->customer_number ?? '' );
            $row['customer_name']       = (string) ( $row_obj->customer_name ?? '' );
            $row['branch']              = (string) ( $row_obj->branch ?? '' );
            $row['serial_number']       = (string) ( $row_obj->serial_number ?? '' );
            $row['expiry_date']         = (string) ( $row_obj->expiry_date ?? '' );
            $row['is_managed']          = intval( $row_obj->is_managed ?? 1 );
            $row['track_only']          = intval( $row_obj->track_only ?? 0 );
            $row['access_url']          = (string) ( $row_obj->access_url ?? '' );
            $row['notes']               = (string) ( $row_obj->notes ?? '' );
            $row['temp_notice_enabled'] = intval( $row_obj->temp_notice_enabled ?? 0 );
            $row['temp_notice']         = (string) ( $row_obj->temp_notice ?? '' );
            $vendor = (string) ( $row_obj->vendor ?? '' );
            $model  = (string) ( $row_obj->model ?? '' );
        }

        $vendors = array();
        foreach ( (array) $types as $t ) { $vendors[ (string) $t->vendor ] = true; }
        $vendors = array_keys( $vendors );
        sort( $vendors, SORT_NATURAL | SORT_FLAG_CASE );

        echo '<style>
            .expman-fw-form{background:#fff;border:1px solid #e3e3e3;border-radius:12px;padding:14px}
            .expman-fw-grid{display:grid;grid-template-columns:repeat(6,minmax(140px,1fr));gap:10px;align-items:end}
            .expman-fw-grid .full{grid-column:span 6}
            .expman-fw-grid .span2{grid-column:span 2}
            .expman-fw-grid .span3{grid-column:span 3}
            .expman-fw-grid label{display:block;font-size:12px;color:#333;margin-bottom:4px}
            .expman-fw-grid input,.expman-fw-grid select,.expman-fw-grid textarea{width:100%;box-sizing:border-box}
            .expman-fw-actions{display:flex;gap:10px;justify-content:flex-start;margin-top:12px}
            .expman-fw-actions .button{padding:8px 16px}
            @media (max-width: 1100px){ .expman-fw-grid{grid-template-columns:repeat(3,minmax(140px,1fr));} .expman-fw-grid .full{grid-column:span 3} .expman-fw-grid .span2{grid-column:span 3} .expman-fw-grid .span3{grid-column:span 3} }
        </style>';

        echo '<form method="post" class="expman-fw-form" style="margin:0;">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="firewall_id" value="' . esc_attr( $id ) . '">';
        echo '<input type="hidden" name="customer_id" class="customer_id" value="' . esc_attr( $row['customer_id'] ) . '">';

        echo '<div class="expman-fw-grid">';

        echo '<div class="full">';
        echo '<label>לקוח (חיפוש לפי שם/מספר)</label>';
        echo '<input type="text" class="customer_search" placeholder="הקלד חלק מהשם או המספר..." autocomplete="off">';
        echo '<div class="customer_results" style="margin-top:6px;"></div>';
        echo '</div>';

        echo '<div class="span2"><label>מספר לקוח</label><input type="text" name="customer_number" class="customer_number" value="' . esc_attr( $row['customer_number'] ) . '" readonly></div>';
        echo '<div class="span2"><label>שם לקוח</label><input type="text" name="customer_name" class="customer_name" value="' . esc_attr( $row['customer_name'] ) . '" readonly></div>';
        echo '<div class="span2"><label>סניף</label><input type="text" name="branch" value="' . esc_attr( $row['branch'] ) . '"></div>';

        echo '<div class="span2"><label>מספר סידורי</label><input type="text" name="serial_number" value="' . esc_attr( $row['serial_number'] ) . '" required></div>';
        echo '<div class="span2"><label>תאריך תפוגה</label><input type="date" name="expiry_date" value="' . esc_attr( $row['expiry_date'] ) . '"></div>';

        echo '<div class="span2"><label>ניהול</label><select name="is_managed"><option value="1" ' . selected( $row['is_managed'], 1, false ) . '>שלנו</option><option value="0" ' . selected( $row['is_managed'], 0, false ) . '>לא שלנו</option></select></div>';

        // Vendor / Model selects
        echo '<div class="span3"><label>יצרן</label><select name="vendor" class="fw_vendor"><option value="">בחר יצרן</option>';
        foreach ( $vendors as $v ) {
            echo '<option value="' . esc_attr( $v ) . '" ' . selected( $vendor, $v, false ) . '>' . esc_html( $v ) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="span3"><label>דגם</label><select name="model" class="fw_model"><option value="">בחר דגם</option>';
        foreach ( (array) $types as $t ) {
            if ( $vendor && (string) $t->vendor !== (string) $vendor ) { continue; }
            $m = (string) $t->model;
            echo '<option value="' . esc_attr( $m ) . '" ' . selected( $model, $m, false ) . '>' . esc_html( $m ) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="span3"><label>URL לגישה מהירה</label><input type="url" name="access_url" value="' . esc_attr( $row['access_url'] ) . '" placeholder="https://..."></div>';

        echo '<div class="span3"><label><input type="checkbox" name="track_only" value="1" ' . checked( $row['track_only'], 1, false ) . '> לקוח למעקב</label></div>';

        echo '<div class="span3"><label>הערה קבועה</label><textarea name="notes" rows="2">' . esc_textarea( $row['notes'] ) . '</textarea></div>';

        echo '<div class="span3"><label><input type="checkbox" name="temp_notice_enabled" class="temp_notice_enabled" value="1" ' . checked( $row['temp_notice_enabled'], 1, false ) . '> הודעה זמנית</label></div>';
        echo '<div class="span3 temp_notice_wrap" style="' . ( $row['temp_notice_enabled'] ? '' : 'display:none;' ) . '"><label>טקסט הודעה זמנית</label><textarea name="temp_notice" rows="2" placeholder="מופיע עד חידוש או ביטול...">' . esc_textarea( $row['temp_notice'] ) . '</textarea></div>';

        echo '</div>'; // grid

        echo '<div class="expman-fw-actions">';
        echo '<button type="submit" name="expman_action" value="save_firewall" class="button button-primary">שמירה</button>';
        if ( $id > 0 ) {
            echo '<button type="submit" name="expman_action" value="archive_firewall" class="button" onclick="return confirm(\'להעביר לארכיון?\');">העבר לארכיון</button>';
        }
        echo '<button type="button" class="button" data-expman-cancel-new>ביטול</button>';
        echo '</div>';

        echo '</form>';

        // Per-form JS (delegated)

        $ajax  = esc_url( admin_url( 'admin-ajax.php' ) );
        $nonce = wp_create_nonce( 'expman_customer_search' );
        $types_js = array();
        foreach ( (array) $types as $t ) { $types_js[] = array( 'vendor' => (string) $t->vendor, 'model' => (string) $t->model ); }

        echo '<script>(function(){
          const dataEl = document.currentScript.previousElementSibling;';
        echo 'const TYPES=' . wp_json_encode( $types_js ) . ';';
        echo 'document.querySelectorAll("form").forEach(function(f){';
        echo 'const chk=f.querySelector(".temp_notice_enabled"); const wrap=f.querySelector(".temp_notice_wrap");';
        echo 'if(chk&&wrap){chk.addEventListener("change",()=>{wrap.style.display=chk.checked?"block":"none";});}';
        echo 'const v=f.querySelector(".fw_vendor"); const m=f.querySelector(".fw_model");';
        echo 'if(v&&m){function rebuild(){const vv=v.value; m.innerHTML=""; const o0=document.createElement("option"); o0.value=""; o0.textContent="בחר דגם"; m.appendChild(o0);';
        echo 'TYPES.filter(t=>!vv||t.vendor===vv).forEach(t=>{const o=document.createElement("option"); o.value=t.model; o.textContent=t.model; m.appendChild(o);});}';
        echo 'v.addEventListener("change",()=>{m.value=""; rebuild();});}';
        echo '});';
        echo 'document.querySelectorAll(".customer_search").forEach(function(input){';
        echo 'const f=input.closest("form"); const results=f.querySelector(".customer_results"); const hidden=f.querySelector(".customer_id");';
        echo 'let t=null; function render(list){results.innerHTML=""; if(!list||!list.length) return;';
        echo 'const wrap=document.createElement("div"); wrap.style.border="1px solid #dcdcde"; wrap.style.borderRadius="6px"; wrap.style.background="#fff"; wrap.style.maxWidth="520px";';
        echo 'list.forEach(it=>{const btn=document.createElement("button"); btn.type="button"; btn.textContent=it.customer_number+" - "+it.customer_name;';
        echo 'btn.style.display="block"; btn.style.width="100%"; btn.style.textAlign="right"; btn.style.padding="6px 10px"; btn.style.border="0"; btn.style.borderBottom="1px solid #eee"; btn.style.background="transparent";';
        echo "btn.addEventListener(\"click\",()=>{input.value=it.customer_number+\" - \"+it.customer_name; hidden.value=it.id; var num=f.querySelector('.customer_number'); var name=f.querySelector('.customer_name'); if(num) num.value=it.customer_number||''; if(name) name.value=it.customer_name||''; results.innerHTML=\"\";}); wrap.appendChild(btn);});";
        echo 'results.appendChild(wrap);}';
        echo 'async function search(q){const url="' . esc_js( $ajax ) . '?action=expman_customer_search&nonce=' . esc_js( $nonce ) . '&q="+encodeURIComponent(q);';
        echo 'const res=await fetch(url,{credentials:"same-origin"}); if(!res.ok) return; const data=await res.json(); render(data.items||[]);}';
        echo 'input.addEventListener("input",()=>{const q=input.value.trim(); if(q.length<2){results.innerHTML=""; return;} clearTimeout(t); t=setTimeout(()=>search(q),250);});';
        echo '});';
        echo '})();</script>';
    }
}
}
