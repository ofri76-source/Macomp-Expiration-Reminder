<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Servers_UI' ) ) {
class Expman_Servers_UI {

    private $page;

    public function __construct( $page ) {
        $this->page = $page;
    }

    private function render_summary_cards() {
        $actions = $this->page->get_actions();
        $data = $actions->get_summary_counts();
        self::render_summary_cards_markup( $data );
    }

    private static function render_summary_cards_markup( $data ) {
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
            .expman-summary-card.all{background:#f2f4f7;border-color:#d5dbe4;}
            .expman-summary-card.green .count{background:#c9f1dd;color:#1b5a39;}
            .expman-summary-card.yellow .count{background:#ffe2c6;color:#7a4c11;}
            .expman-summary-card.red .count{background:#ffd1d1;color:#7a1f1f;}
            .expman-summary-card.all .count{background:#e1e5ea;color:#2b3f5c;}
            .expman-summary-card[data-active="1"]{box-shadow:0 0 0 2px rgba(47,94,168,0.18);}
            .expman-summary-meta{margin-top:8px;padding:8px 12px;border-radius:10px;border:1px solid #d9e3f2;background:#f8fafc;font-weight:600;color:#2b3f5c;}
            </style>';
            $summary_css_done = true;
        }

        $yellow_label = 'עד ' . intval( $data['yellow_threshold'] ) . ' ימים';

        echo '<div class="expman-summary">';
        echo '<div class="expman-summary-card all" data-expman-status="all" data-active="1"><button type="button"><h4>הכל</h4><div class="count">' . esc_html( $data['total'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card green" data-expman-status="green" data-active="0"><button type="button"><h4>תוקף מעל ' . esc_html( $data['yellow_threshold'] ) . ' יום</h4><div class="count">' . esc_html( $data['green'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card yellow" data-expman-status="yellow" data-active="0"><button type="button"><h4>' . esc_html( $yellow_label ) . '</h4><div class="count">' . esc_html( $data['yellow'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card red" data-expman-status="red" data-active="0"><button type="button"><h4>דורש טיפול מייד</h4><div class="count">' . esc_html( $data['red'] ) . '</div></button></div>';
        echo '</div>';
        echo '<div class="expman-summary-meta">סה"כ רשומות פעילות: ' . esc_html( $data['total'] ) . ' | בסל מחזור: ' . esc_html( $data['trash'] ) . '</div>';
    }

    public function render() {
        $errors = get_transient( 'expman_servers_errors' );
        delete_transient( 'expman_servers_errors' );

        $imported = get_transient( 'expman_servers_imported' );
        delete_transient( 'expman_servers_imported' );

        echo '<style>
        .expman-filter-row input,.expman-filter-row select{height:24px !important;padding:4px 6px !important;font-size:12px !important;border:1px solid #c7d1e0;border-radius:4px;background:#fff;}
        .expman-filter-row th{background:#e8f0fb !important;border-bottom:2px solid #c7d1e0;}
        .expman-align-left{text-align:left !important;}
        .expman-align-left input,.expman-align-left select,.expman-align-left button{direction:ltr;text-align:left !important;}
        .expman-btn{padding:6px 12px !important;font-size:12px !important;border-radius:6px;border:1px solid #254d8c;background:#2f5ea8;color:#fff;display:inline-block;line-height:1.2;box-shadow:0 1px 0 rgba(0,0,0,0.05);cursor:pointer;text-decoration:none;}
        .expman-btn:hover{background:#264f8f;color:#fff;}
        .expman-btn.secondary{background:#eef3fb;border-color:#9fb3d9;color:#1f3b64;}
        .expman-btn.secondary:hover{background:#dfe9f7;color:#1f3b64;}
        .expman-frontend .widefat{border:1px solid #c7d1e0;border-radius:8px;overflow:hidden;background:#fff;}
        .expman-frontend .widefat{table-layout:auto;width:100%;}
        .expman-frontend .widefat thead th{background:#2f5ea8;color:#fff;border-bottom:2px solid #244b86;padding:8px;}
        .expman-frontend .widefat tbody td{padding:8px;border-bottom:1px solid #e3e7ef;overflow-wrap:anywhere;word-break:break-word;}
        .expman-frontend .widefat th,.expman-frontend .widefat td{text-align:right;vertical-align:middle;}
        .expman-row-alt td{background:#f6f8fc;}
        .expman-inline-form td{border-top:1px solid #e3e7ef;background:#f9fbff;}
        .expman-days-green{background:transparent;}
        .expman-days-yellow{background:#fff4e7;}
        .expman-days-red{background:#ffecec;}
        .expman-days-unknown{background:#f1f3f6;}
        </style>';

        echo '<div class="expman-frontend expman-servers" style="direction:rtl;">';

        if ( class_exists( 'Expman_Nav' ) ) {
            Expman_Nav::render_public_nav( $this->page->get_option_key() );
        }

        echo '<h2 style="margin-top:10px;">שרתים (Dell)</h2>';
        $this->render_summary_cards();
        echo '<script>(function(){
          function setActive(status){
            document.querySelectorAll(".expman-summary-card").forEach(function(card){
              card.setAttribute("data-active", card.getAttribute("data-expman-status")===status ? "1" : "0");
            });
          }
          function applyFilter(status){
            document.querySelectorAll(".expman-table-wrap").forEach(function(wrap){
              wrap.querySelectorAll("tr.expman-row").forEach(function(row){
                var rowStatus=row.getAttribute("data-expman-status")||"";
                var rowId=row.getAttribute("data-expman-row-id");
                var show = (status === "all") || (rowStatus === status);
                row.style.display = show ? "" : "none";
                if(rowId){
                  var inline=wrap.querySelector("tr.expman-inline-form[data-for=\\\"" + rowId + "\\\"]");
                  if(inline){inline.style.display = "none";}
                }
              });
            });
          }
          document.querySelectorAll(".expman-summary-card").forEach(function(card){
            card.addEventListener("click",function(){
              var status=card.getAttribute("data-expman-status");
              if(!status){return;}
              setActive(status);
              applyFilter(status);
            });
          });
        })();</script>';

        if ( ! empty( $errors ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( implode( ' | ', (array) $errors ) ) . '</p></div>';
        }

        if ( $imported ) {
            echo '<div class="notice notice-success"><p>הייבוא הושלם. נוספו ' . esc_html( $imported ) . ' שורות לשלב.</p></div>';
        }

        foreach ( $this->page->get_notices() as $notice ) {
            $type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
            echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>';
        }

        $active = $this->get_active_tab();
        $this->render_internal_tabs( $active );

        echo '<div data-expman-panel="main"' . ( $active === 'main' ? '' : ' style="display:none;"' ) . '>';
        $this->render_main_tab();
        echo '</div>';

        echo '<div data-expman-panel="settings"' . ( $active === 'settings' ? '' : ' style="display:none;"' ) . '>';
        $this->render_settings_tab();
        echo '</div>';

        echo '<div data-expman-panel="logs"' . ( $active === 'logs' ? '' : ' style="display:none;"' ) . '>';
        $this->render_logs_tab();
        echo '</div>';

        echo '<div data-expman-panel="trash"' . ( $active === 'trash' ? '' : ' style="display:none;"' ) . '>';
        $this->render_trash_tab();
        echo '</div>';

        echo '<div data-expman-panel="assign"' . ( $active === 'assign' ? '' : ' style="display:none;"' ) . '>';
        $this->render_assign_tab();
        echo '</div>';

        echo '</div>';
    }

    private function get_active_tab() {
        $allowed = array( 'main', 'settings', 'logs', 'trash', 'assign' );
        $tab = sanitize_key( $_REQUEST['tab'] ?? 'main' );
        return in_array( $tab, $allowed, true ) ? $tab : 'main';
    }

    private function render_internal_tabs( $active ) {
        $tabs = array(
            'main'     => 'רשימה ראשית',
            'settings' => 'הגדרות',
            'logs'     => 'לוגים',
            'trash'    => 'סל מחזור',
            'assign'   => 'שיוך לאחר ייבוא',
        );

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
        })();</script>';
    }

    private function common_filters_from_get() {
        return array(
            'customer_number' => sanitize_text_field( $_GET['f_customer_number'] ?? '' ),
            'customer_name'   => sanitize_text_field( $_GET['f_customer_name'] ?? '' ),
            'service_tag'     => sanitize_text_field( $_GET['f_service_tag'] ?? '' ),
        );
    }

    private function render_main_tab() {
        $actions = $this->page->get_actions();
        $filters = $this->common_filters_from_get();
        $orderby = sanitize_key( $_GET['orderby'] ?? 'ending_on' );
        $order   = sanitize_key( $_GET['order'] ?? 'ASC' );

        $base = remove_query_arg( array( 'expman_msg' ) );
        $clear_url = remove_query_arg( array( 'f_customer_number', 'f_customer_name', 'f_service_tag', 'orderby', 'order' ), $base );

        echo '<div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin:10px 0;">';
        echo '<button type="button" class="expman-btn" id="expman-add-toggle">הוספת שרת</button>';
        echo '</div>';

        echo '<div id="expman-add-form-wrap" style="display:none;border:1px solid #e6e6e6;border-radius:10px;padding:12px;margin:10px 0;background:#fff;">';
        $this->render_form( 0 );
        echo '</div>';

        $rows = $actions->get_servers_rows( $filters, $orderby, $order, false );
        $thresholds = $actions->get_summary_counts();

        echo '<form method="get" style="margin-bottom:10px;">';
        echo '<input type="hidden" name="page" value="expman_servers">';
        echo '<input type="hidden" name="tab" value="main">';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:flex-end;">';
        echo '<label>מספר לקוח <input type="text" name="f_customer_number" value="' . esc_attr( $filters['customer_number'] ) . '"></label>';
        echo '<label>שם לקוח <input type="text" name="f_customer_name" value="' . esc_attr( $filters['customer_name'] ) . '"></label>';
        echo '<label>Service Tag <input type="text" name="f_service_tag" value="' . esc_attr( $filters['service_tag'] ) . '"></label>';
        echo '<button class="expman-btn secondary" type="submit">חיפוש</button>';
        echo '<a class="expman-btn secondary" href="' . esc_url( $clear_url ) . '">ניקוי סינון</a>';
        echo '</div>';
        echo '</form>';

        echo '<div class="expman-table-wrap">';
        echo '<form method="post">';
        wp_nonce_field( 'expman_servers' );
        echo '<input type="hidden" name="expman_action" value="sync_bulk">';
        echo '<table class="widefat" style="margin-bottom:10px;">';
        echo '<thead><tr>';
        echo '<th style="width:28px;"><input type="checkbox" id="expman-select-all"></th>';
        echo '<th>מספר לקוח</th>';
        echo '<th>שם לקוח</th>';
        echo '<th>Service Tag</th>';
        echo '<th>Express Service Code</th>';
        echo '<th>Ship Date</th>';
        echo '<th>Ending on</th>';
        echo '<th>סוג שירות</th>';
        echo '<th>דגם שרת</th>';
        echo '<th>הודעה זמנית</th>';
        echo '<th>הערות</th>';
        echo '<th>פעולות</th>';
        echo '</tr></thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="12" style="text-align:center;">אין נתונים להצגה.</td></tr>';
        }

        $row_index = 0;
        foreach ( (array) $rows as $row ) {
            $row_index++;
            $row_class = ( $row_index % 2 ) ? '' : 'expman-row-alt';
            $days = isset( $row->days_to_end ) ? intval( $row->days_to_end ) : null;
            $status = 'unknown';
            if ( $days !== null ) {
                if ( $days <= $thresholds['red_threshold'] ) {
                    $status = 'red';
                } elseif ( $days <= $thresholds['yellow_threshold'] ) {
                    $status = 'yellow';
                } else {
                    $status = 'green';
                }
            }
            $days_class = 'expman-days-' . $status;

            echo '<tr class="expman-row ' . esc_attr( $row_class ) . '" data-expman-status="' . esc_attr( $status ) . '" data-expman-row-id="' . esc_attr( $row->id ) . '">';
            echo '<td><input type="checkbox" name="server_ids[]" value="' . esc_attr( $row->id ) . '"></td>';
            echo '<td>' . esc_html( $row->customer_number_snapshot ) . '</td>';
            echo '<td>' . esc_html( $row->customer_name_snapshot ) . '</td>';
            echo '<td>' . esc_html( $row->service_tag ) . '</td>';
            echo '<td>' . esc_html( $row->express_service_code ) . '</td>';
            echo '<td>' . esc_html( $row->ship_date ) . '</td>';
            echo '<td class="' . esc_attr( $days_class ) . '">' . esc_html( $row->ending_on ) . '</td>';
            echo '<td>' . esc_html( $row->service_level ) . '</td>';
            echo '<td>' . esc_html( $row->server_model ) . '</td>';
            echo '<td>' . ( intval( $row->temp_notice_enabled ) ? esc_html( $row->temp_notice_text ) : '' ) . '</td>';
            echo '<td>' . esc_html( $row->notes ) . '</td>';
            echo '<td class="expman-align-left" style="white-space:nowrap;">';
            echo '<button type="button" class="button expman-edit-toggle" data-id="' . esc_attr( $row->id ) . '">ערוך</button> ';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field( 'expman_servers' );
            echo '<input type="hidden" name="expman_action" value="sync_single">';
            echo '<input type="hidden" name="server_id" value="' . esc_attr( $row->id ) . '">';
            echo '<button type="submit" class="button">Sync</button>';
            echo '</form> ';
            echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'להעביר לסל המחזור?\');">';
            wp_nonce_field( 'expman_servers' );
            echo '<input type="hidden" name="expman_action" value="trash_server">';
            echo '<input type="hidden" name="server_id" value="' . esc_attr( $row->id ) . '">';
            echo '<button type="submit" class="button">מחק</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';

            echo '<tr class="expman-inline-form" data-for="' . esc_attr( $row->id ) . '" style="display:none;">';
            echo '<td colspan="12">';
            $this->render_form( intval( $row->id ), $row );
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<button type="submit" class="expman-btn">Sync מסומנים</button>';
        echo '</form>';
        echo '</div>';

        echo '<script>(function(){
          const addToggle=document.getElementById("expman-add-toggle");
          if(addToggle){
            addToggle.addEventListener("click",function(){
              const wrap=document.getElementById("expman-add-form-wrap");
              if(wrap){wrap.style.display=wrap.style.display==="none"?"block":"none";}
            });
          }
          const selectAll=document.getElementById("expman-select-all");
          if(selectAll){
            selectAll.addEventListener("change",function(){
              document.querySelectorAll("input[name=\\\"server_ids[]\\\"]").forEach(cb=>{cb.checked=selectAll.checked;});
            });
          }
          document.querySelectorAll(".expman-edit-toggle").forEach(btn=>{
            btn.addEventListener("click",function(){
              const id=btn.getAttribute("data-id");
              const row=document.querySelector("tr.expman-inline-form[data-for=\\\"" + id + "\\\"]");
              if(row){row.style.display=row.style.display==="none"?"table-row":"none";}
            });
          });
        })();</script>';
    }

    private function render_form( $id = 0, $row_obj = null ) {
        $row = array(
            'customer_id' => 0,
            'customer_number_snapshot' => '',
            'customer_name_snapshot' => '',
            'service_tag' => '',
            'notes' => '',
            'temp_notice_enabled' => 0,
            'temp_notice_text' => '',
        );

        if ( $row_obj ) {
            $row['customer_id'] = intval( $row_obj->customer_id );
            $row['customer_number_snapshot'] = (string) ( $row_obj->customer_number_snapshot ?? '' );
            $row['customer_name_snapshot'] = (string) ( $row_obj->customer_name_snapshot ?? '' );
            $row['service_tag'] = (string) ( $row_obj->service_tag ?? '' );
            $row['notes'] = (string) ( $row_obj->notes ?? '' );
            $row['temp_notice_enabled'] = intval( $row_obj->temp_notice_enabled ?? 0 );
            $row['temp_notice_text'] = (string) ( $row_obj->temp_notice_text ?? '' );
        }

        echo '<style>
            .expman-servers-form{background:#fff;border:1px solid #e3e3e3;border-radius:12px;padding:14px}
            .expman-servers-grid{display:grid;grid-template-columns:repeat(6,minmax(140px,1fr));gap:10px;align-items:end}
            .expman-servers-grid .full{grid-column:span 6}
            .expman-servers-grid .span2{grid-column:span 2}
            .expman-servers-grid label{display:block;font-size:12px;color:#333;margin-bottom:4px}
            .expman-servers-grid input,.expman-servers-grid textarea{width:100%;box-sizing:border-box}
            .expman-servers-actions{display:flex;gap:10px;justify-content:flex-start;margin-top:12px}
            @media (max-width: 1100px){ .expman-servers-grid{grid-template-columns:repeat(3,minmax(140px,1fr));} .expman-servers-grid .full{grid-column:span 3} .expman-servers-grid .span2{grid-column:span 3} }
        </style>';

        echo '<form method="post" class="expman-servers-form" style="margin:0;">';
        wp_nonce_field( 'expman_servers' );
        echo '<input type="hidden" name="server_id" value="' . esc_attr( $id ) . '">';
        echo '<input type="hidden" name="customer_id" class="expman-customer-id" value="' . esc_attr( $row['customer_id'] ) . '">';

        echo '<div class="expman-servers-grid">';
        echo '<div class="full">';
        echo '<label>לקוח (חיפוש לפי שם/מספר)</label>';
        echo '<input type="text" class="expman-customer-search" placeholder="הקלד חלק מהשם או המספר..." autocomplete="off">';
        echo '<div class="expman-customer-results" style="margin-top:6px;position:relative;"></div>';
        echo '</div>';

        echo '<div class="span2"><label>מספר לקוח</label><input type="text" name="customer_number" class="expman-customer-number" value="' . esc_attr( $row['customer_number_snapshot'] ) . '" readonly></div>';
        echo '<div class="span2"><label>שם לקוח</label><input type="text" name="customer_name" class="expman-customer-name" value="' . esc_attr( $row['customer_name_snapshot'] ) . '" readonly></div>';
        echo '<div class="span2"><label>Service Tag</label><input type="text" name="service_tag" value="' . esc_attr( $row['service_tag'] ) . '" required></div>';

        echo '<div class="span2"><label>הערות</label><textarea name="notes" rows="2">' . esc_textarea( $row['notes'] ) . '</textarea></div>';
        echo '<div class="span2"><label><input type="checkbox" name="temp_notice_enabled" class="temp_notice_enabled" value="1" ' . checked( $row['temp_notice_enabled'], 1, false ) . '> הודעה זמנית</label></div>';
        echo '<div class="span2 temp_notice_wrap" style="' . ( $row['temp_notice_enabled'] ? '' : 'display:none;' ) . '"><label>טקסט הודעה זמנית</label><textarea name="temp_notice_text" rows="2">' . esc_textarea( $row['temp_notice_text'] ) . '</textarea></div>';

        echo '</div>';

        echo '<div class="expman-servers-actions">';
        echo '<button type="submit" name="expman_action" value="save_server" class="button button-primary">שמירה</button>';
        echo '<button type="button" class="button" data-expman-cancel>ביטול</button>';
        echo '</div>';

        echo '</form>';

        $ajax = esc_url( admin_url( 'admin-ajax.php' ) );
        $nonce = wp_create_nonce( 'expman_customer_search' );

        echo '<script>(function(){
          const ajax="' . esc_js( $ajax ) . '";
          const nonce="' . esc_js( $nonce ) . '";
          function fetchCustomers(query, cb){
            const url=ajax+"?action=expman_customer_search&nonce="+encodeURIComponent(nonce)+"&q="+encodeURIComponent(query);
            fetch(url).then(r=>r.json()).then(d=>cb(d.items||[])).catch(()=>cb([]));
          }
          document.querySelectorAll(".expman-customer-search").forEach(function(input){
            input.addEventListener("input",function(){
              const cell=input.closest("form");
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
                    cell.querySelector(".expman-customer-number").value=it.customer_number;
                    cell.querySelector(".expman-customer-name").value=it.customer_name;
                    results.innerHTML="";
                  });
                  wrap.appendChild(btn);
                });
                results.appendChild(wrap);
              });
            });
          });
          document.addEventListener("click",function(e){
            if(e.target.classList.contains("expman-customer-search")) return;
            document.querySelectorAll(".expman-customer-results").forEach(r=>{r.innerHTML="";});
          });
          document.querySelectorAll(".temp_notice_enabled").forEach(function(chk){
            const form=chk.closest("form");
            const wrap=form.querySelector(".temp_notice_wrap");
            chk.addEventListener("change",function(){
              if(wrap){wrap.style.display=chk.checked?"block":"none";}
            });
          });
          document.querySelectorAll("[data-expman-cancel]").forEach(function(btn){
            btn.addEventListener("click",function(){
              const row=btn.closest("tr");
              if(row && row.classList.contains("expman-inline-form")){
                row.style.display="none";
              }
              const wrap=document.getElementById("expman-add-form-wrap");
              if(wrap){wrap.style.display="none";}
            });
          });
        })();</script>';
    }

    private function render_settings_tab() {
        $settings = $this->page->get_dell_settings();
        $client_id = (string) ( $settings['client_id'] ?? '' );
        $client_secret = (string) ( $settings['client_secret'] ?? '' );
        $api_key = (string) ( $settings['api_key'] ?? '' );
        $red_days = intval( $settings['red_days'] ?? 30 );
        $yellow_days = intval( $settings['yellow_days'] ?? 60 );

        echo '<h3>הגדרות Dell TechDirect</h3>';
        echo '<form method="post" style="max-width:520px;">';
        wp_nonce_field( 'expman_servers' );
        echo '<input type="hidden" name="expman_action" value="save_dell_settings">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Client ID</th><td><input type="text" name="dell_client_id" value="' . esc_attr( $client_id ) . '" class="regular-text"></td></tr>';
        echo '<tr><th>Client Secret</th><td><input type="password" name="dell_client_secret" value="' . esc_attr( $client_secret ) . '" class="regular-text"></td></tr>';
        echo '<tr><th>API Key</th><td><input type="text" name="dell_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text"></td></tr>';
        echo '<tr><th>Red Days</th><td><input type="number" name="dell_red_days" value="' . esc_attr( $red_days ) . '" class="small-text"></td></tr>';
        echo '<tr><th>Yellow Days</th><td><input type="number" name="dell_yellow_days" value="' . esc_attr( $yellow_days ) . '" class="small-text"></td></tr>';
        echo '</tbody></table>';
        echo '<button type="submit" class="button button-primary">שמירה</button>';
        echo '</form>';
    }

    private function render_logs_tab() {
        $actions = $this->page->get_actions();
        $logger = $this->page->get_logger();
        $rows = $actions->get_logs();

        echo '<h3>לוגים</h3>';
        echo '<table class="widefat">';
        echo '<thead><tr><th>תאריך</th><th>פעולה</th><th>רמה</th><th>הודעה</th><th>פרטים</th></tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="5" style="text-align:center;">אין לוגים להצגה.</td></tr>';
        }
        foreach ( (array) $rows as $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $row->created_at ) . '</td>';
            echo '<td>' . esc_html( $row->action ) . '</td>';
            echo '<td>' . esc_html( $row->level ) . '</td>';
            echo '<td>' . esc_html( $row->message ) . '</td>';
            echo '<td>' . $logger->format_log_context( $row->context ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function render_trash_tab() {
        $actions = $this->page->get_actions();
        $rows = $actions->get_trash_rows();

        echo '<h3>סל מחזור</h3>';
        echo '<form method="post" style="margin-bottom:10px;">';
        wp_nonce_field( 'expman_servers' );
        echo '<input type="hidden" name="expman_action" value="empty_trash">';
        echo '<button type="submit" class="button" onclick="return confirm(\'לרוקן את סל המחזור?\');">רוקן סל מחזור</button>';
        echo '</form>';

        echo '<table class="widefat">';
        echo '<thead><tr><th>Service Tag</th><th>לקוח</th><th>Deleted At</th><th>פעולות</th></tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="4" style="text-align:center;">אין פריטים בסל מחזור.</td></tr>';
        }
        foreach ( (array) $rows as $row ) {
            $label = trim( $row->customer_number_snapshot . ' ' . $row->customer_name_snapshot );
            echo '<tr>';
            echo '<td>' . esc_html( $row->service_tag ) . '</td>';
            echo '<td>' . esc_html( $label ) . '</td>';
            echo '<td>' . esc_html( $row->deleted_at ) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field( 'expman_servers' );
            echo '<input type="hidden" name="expman_action" value="restore_server">';
            echo '<input type="hidden" name="server_id" value="' . esc_attr( $row->id ) . '">';
            echo '<button type="submit" class="button">שחזור</button>';
            echo '</form> ';
            echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'למחוק לצמיתות?\');">';
            wp_nonce_field( 'expman_servers' );
            echo '<input type="hidden" name="expman_action" value="delete_server_permanently">';
            echo '<input type="hidden" name="server_id" value="' . esc_attr( $row->id ) . '">';
            echo '<button type="submit" class="button">מחיקה לצמיתות</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function render_assign_tab() {
        $actions = $this->page->get_actions();
        $rows = $actions->get_stage_rows();

        echo '<h3>שיוך לאחר ייבוא</h3>';
        echo '<form method="post" enctype="multipart/form-data" style="margin-bottom:16px;">';
        wp_nonce_field( 'expman_servers' );
        echo '<input type="hidden" name="expman_action" value="import_csv">';
        echo '<input type="file" name="servers_file" accept=".csv">';
        echo '<button type="submit" class="button">ייבוא CSV</button>';
        echo '</form>';

        if ( empty( $rows ) ) {
            echo '<div class="notice notice-info"><p>אין שורות לשיוך.</p></div>';
            return;
        }

        echo '<table class="widefat">';
        echo '<thead><tr><th>Service Tag</th><th>מספר לקוח</th><th>שם לקוח</th><th>הערות</th><th>שיוך</th><th>מחיקה</th></tr></thead><tbody>';
        foreach ( (array) $rows as $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $row->service_tag ) . '</td>';
            echo '<td>' . esc_html( $row->customer_number ) . '</td>';
            echo '<td>' . esc_html( $row->customer_name ) . '</td>';
            echo '<td>' . esc_html( $row->notes ) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
            wp_nonce_field( 'expman_servers' );
            echo '<input type="hidden" name="expman_action" value="assign_import_stage">';
            echo '<input type="hidden" name="stage_id" value="' . esc_attr( $row->id ) . '">';
            echo '<input type="hidden" name="service_tag" value="' . esc_attr( $row->service_tag ) . '">';
            echo '<input type="hidden" name="customer_id" class="expman-customer-id" value="">';
            echo '<input type="text" class="expman-customer-search" placeholder="חפש לקוח..." style="min-width:200px;">';
            echo '<div class="expman-customer-results" style="position:relative;"></div>';
            echo '<input type="text" name="customer_number" class="expman-customer-number" value="' . esc_attr( $row->customer_number ) . '" readonly style="width:110px;">';
            echo '<input type="text" name="customer_name" class="expman-customer-name" value="' . esc_attr( $row->customer_name ) . '" readonly style="width:160px;">';
            echo '<button type="submit" class="button">שיוך</button>';
            echo '</form>';
            echo '</td>';
            echo '<td>';
            echo '<form method="post" onsubmit="return confirm(\'למחוק את השורה?\');">';
            wp_nonce_field( 'expman_servers' );
            echo '<input type="hidden" name="expman_action" value="delete_import_stage">';
            echo '<input type="hidden" name="stage_id" value="' . esc_attr( $row->id ) . '">';
            echo '<button type="submit" class="button">מחיקה</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
}
