<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Servers_UI' ) ) {
class Expman_Servers_UI {

    private $page;

    public function __construct( $page ) {
        $this->page = $page;
    }


    private static function fmt_date_short( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) { return ''; }
        // Prefer strict DB formats first (DATE / DATETIME) to avoid strtotime ambiguity.
        $dt = DateTime::createFromFormat( 'Y-m-d', $value );
        if ( $dt instanceof DateTime ) {
            return $dt->format( 'd/m/Y' );
        }
        $dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $value );
        if ( $dt instanceof DateTime ) {
            return $dt->format( 'd/m/Y' );
        }
        $ts = strtotime( $value );
        if ( ! $ts ) { return $value; }
        return date_i18n( 'd/m/Y', $ts );
    }

    private static function fmt_datetime_short( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) { return ''; }
        $dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $value );
        if ( $dt instanceof DateTime ) {
            return $dt->format( 'd/m/Y H:i' );
        }
        $ts = strtotime( $value );
        if ( ! $ts ) { return $value; }
        return date_i18n( 'd/m/Y H:i', $ts );
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
            .expman-summary-card.green .count{background:#c9f1dd;color:#1b5a39;}
            .expman-summary-card.yellow .count{background:#ffe2c6;color:#7a4c11;}
            .expman-summary-card.red .count{background:#ffd1d1;color:#7a1f1f;}
            .expman-summary-card[data-active="1"]{box-shadow:0 0 0 2px rgba(47,94,168,0.18);}
            .expman-summary-meta{margin-top:8px;padding:8px 12px;border-radius:10px;border:1px solid #d9e3f2;background:#f8fafc;font-weight:600;color:#2b3f5c;}
            .expman-summary-meta button{all:unset;cursor:pointer;}
            </style>';
            $summary_css_done = true;
        }

        $yellow_threshold = intval( $data['yellow_threshold'] ?? 60 );
        $red_threshold    = intval( $data['red_threshold'] ?? 30 );
        $yellow_label = 'תוקף בין ' . ( $red_threshold + 1 ) . ' ל-' . $yellow_threshold . ' יום';

        echo '<div class="expman-summary">';
        echo '<div class="expman-summary-card green" data-expman-status="green" data-active="0"><button type="button"><h4>תוקף מעל ' . esc_html( $yellow_threshold ) . ' יום</h4><div class="count">' . esc_html( intval( $data['green'] ?? 0 ) ) . '</div></button></div>';
        echo '<div class="expman-summary-card yellow" data-expman-status="yellow" data-active="0"><button type="button"><h4>' . esc_html( $yellow_label ) . '</h4><div class="count">' . esc_html( intval( $data['yellow'] ?? 0 ) ) . '</div></button></div>';
        echo '<div class="expman-summary-card red" data-expman-status="red" data-active="0"><button type="button"><h4>דורש טיפול מייד</h4><div class="count">' . esc_html( intval( $data['red'] ?? 0 ) ) . '</div></button></div>';
        echo '</div>';

        echo '<div class="expman-summary-meta" data-expman-status="all"><button type="button">סה״כ רשומות פעילות: ' . esc_html( intval( $data['total'] ?? 0 ) ) . ' | ב־Trash: ' . esc_html( intval( $data['trash'] ?? 0 ) ) . '</button></div>';
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
        .expman-details td{border-top:1px solid #e3e7ef;background:#f4f6fb;}
        .expman-inline-form td{border-top:1px solid #e3e7ef;background:#f9fbff;}
        .expman-days-pill{display:inline-flex;align-items:center;justify-content:center;min-width:36px;padding:3px 10px;border-radius:999px;font-weight:700;font-size:12px;line-height:1;}
        .expman-days-green{background:transparent;}
        .expman-days-yellow{background:#ffe4b8;color:#7a4c11;}
        .expman-days-red{background:#ffd1d1;color:#7a1f1f;}
        .expman-days-unknown{background:#e2e6eb;color:#2b3f5c;}
        .expman-actionbar{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-start;align-items:center;margin:10px 0;}
        .expman-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:99999;display:none;align-items:center;justify-content:center;padding:16px;}
        .expman-modal{background:#fff;border-radius:14px;max-width:980px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.25);overflow:hidden;}
        .expman-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #e6e9ef;}
        .expman-modal-title{margin:0;font-size:16px;font-weight:800;}
        .expman-modal-close{all:unset;cursor:pointer;font-size:22px;line-height:1;padding:2px 8px;border-radius:8px;}
        .expman-modal-close:hover{background:#f3f5f8;}
        .expman-modal-body{padding:16px;}
        </style>';

        echo '<div class="expman-frontend expman-servers" style="direction:rtl;">';

        if ( class_exists( 'Expman_Nav' ) ) {
            Expman_Nav::render_public_nav( $this->page->get_option_key() );
        }

        echo '<h2 style="margin-top:10px;">שרתים (Dell)</h2>';
        $this->render_summary_cards();

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

        echo '<div data-expman-panel="trash"' . ( $active === 'trash' ? '' : ' style="display:none;"' ) . '>';
        $this->render_trash_tab();
        echo '</div>';

        echo '<div data-expman-panel="settings"' . ( $active === 'settings' ? '' : ' style="display:none;"' ) . '>';
        $this->render_settings_tab();
        echo '</div>';

        echo '<div data-expman-panel="assign"' . ( $active === 'assign' ? '' : ' style="display:none;"' ) . '>';
        $this->render_assign_tab();
        echo '</div>';

        echo '<div data-expman-panel="logs"' . ( $active === 'logs' ? '' : ' style="display:none;"' ) . '>';
        $this->render_logs_tab();
        echo '</div>';

        $ajax  = esc_url( admin_url( 'admin-ajax.php' ) );
        $nonce = wp_create_nonce( 'expman_customer_search' );
        $ajax_json  = wp_json_encode( $ajax );
        $nonce_json = wp_json_encode( $nonce );

        $js = <<<JS
(function(){
  const ajax = $ajax_json;
  const nonce = $nonce_json;

  // Tabs (no reload)
  document.querySelectorAll("[data-expman-tab]").forEach(btn=>{
    btn.addEventListener("click", (e)=>{
      e.preventDefault();
      const tab = btn.getAttribute("data-expman-tab");
      document.querySelectorAll("[data-expman-tab]").forEach(b=>b.setAttribute("data-active", b===btn ? "1" : "0"));
      document.querySelectorAll("[data-expman-panel]").forEach(p=>{
        p.style.display = (p.getAttribute("data-expman-panel")==tab) ? "" : "none";
      });
      const url = new URL(location.href);
      url.searchParams.set("tab", tab);
      history.replaceState({}, "", url);
    });
  });

  // Summary cards status filter
  function setActiveSummary(status){
    document.querySelectorAll(".expman-summary-card, .expman-summary-meta").forEach(card=>{
      card.setAttribute("data-active", card.getAttribute("data-expman-status")===status ? "1" : "0");
    });
  }
  function applyStatusFilter(status){
    document.querySelectorAll("tr.expman-row").forEach(row=>{
      const rowStatus = row.getAttribute("data-expman-status") || "";
      const rowId = row.getAttribute("data-expman-row-id");
      const show = (status === "all") || (rowStatus === status);
      row.style.display = show ? "" : "none";
      if(rowId){
        const det = document.querySelector('tr.expman-details[data-for="'+rowId+'"]');
        const frm = document.querySelector('tr.expman-inline-form[data-for="'+rowId+'"]');
        if(det) det.style.display = "none";
        if(frm) frm.style.display = "none";
      }
    });
  }
  document.querySelectorAll(".expman-summary-card, .expman-summary-meta").forEach(card=>{
    card.addEventListener("click", ()=>{
      const status = card.getAttribute("data-expman-status") || "all";
      setActiveSummary(status);
      applyStatusFilter(status);
    });
  });

  // Row click -> toggle details
  document.addEventListener("click", (e)=>{
    const row = e.target.closest("tr.expman-row");
    if(!row) return;

    // ignore controls
    if(e.target.closest("button, a, input, select, textarea, label, form")) return;

    const id = row.getAttribute("data-expman-row-id");
    if(!id) return;
    const det = document.querySelector('tr.expman-details[data-for="'+id+'"]');
    if(!det) return;
    const isHidden = window.getComputedStyle(det).display === "none";
    det.style.display = isHidden ? "table-row" : "none";
  });

  // Edit toggle
  document.addEventListener("click", (e)=>{
    const btn = e.target.closest(".expman-edit-toggle");
    if(!btn) return;
    e.preventDefault();
    const id = btn.getAttribute("data-id");
    if(!id) return;
    const frm = document.querySelector('tr.expman-inline-form[data-for="'+id+'"]');
    if(!frm) return;
    const isHidden = window.getComputedStyle(frm).display === "none";
    frm.style.display = isHidden ? "table-row" : "none";
  });

  // Simple table filters (client-side)
  function applyTextFilters(){
    const inputs = document.querySelectorAll(".expman-filter-input");
    const filters = {};
    inputs.forEach(inp=>{
      const k = inp.getAttribute("data-filter");
      const v = (inp.value||"").trim().toLowerCase();
      if(k && v) filters[k]=v;
    });
    document.querySelectorAll("tr.expman-row").forEach(row=>{
      let ok = true;
      Object.keys(filters).forEach(k=>{
        const val = (row.getAttribute("data-"+k) || "").toLowerCase();
        if(val.indexOf(filters[k]) === -1) ok = false;
      });
      row.style.display = ok ? "" : "none";
      const id = row.getAttribute("data-expman-row-id");
      if(!ok && id){
        const det = document.querySelector('tr.expman-details[data-for="'+id+'"]');
        const frm = document.querySelector('tr.expman-inline-form[data-for="'+id+'"]');
        if(det) det.style.display = "none";
        if(frm) frm.style.display = "none";
      }
    });
  }
  document.addEventListener("input",(e)=>{
    if(!e.target.matches(".expman-filter-input")) return;
    applyTextFilters();
  });

  // Bulk check all
  const all = document.getElementById("expman-bulk-check-all");
  if(all){
    all.addEventListener("change", ()=>{
      document.querySelectorAll(".expman-bulk-id").forEach(cb=>cb.checked = all.checked);
    });
  }

  // New server (inline) open/close
  const newWrap = document.getElementById("expman-new-server-inline");
  const openBtn = document.getElementById("expman-open-new-server");
  function closeNew(){
    if(newWrap) newWrap.style.display = "none";
  }
  function toggleNew(){
    if(!newWrap) return;
    const isHidden = window.getComputedStyle(newWrap).display === "none";
    newWrap.style.display = isHidden ? "block" : "none";
    if(isHidden && newWrap.scrollIntoView){
      newWrap.scrollIntoView({behavior:"smooth", block:"start"});
    }
  }
  if(openBtn){ openBtn.addEventListener("click",(e)=>{ e.preventDefault(); toggleNew(); }); }
  document.addEventListener("click",(e)=>{
    if(e.target && e.target.matches("#expman-close-new-server")){ e.preventDefault(); closeNew(); }
  });
  document.addEventListener("keydown",(e)=>{ if(e.key === "Escape") closeNew(); });


  // Temp notice show/hide
  document.addEventListener("change",(e)=>{
    const cb = e.target.closest(".expman-temp-notice-enabled");
    if(!cb) return;
    const form = cb.closest("form");
    if(!form) return;
    const wrap = form.querySelector(".expman-temp-notice-wrap");
    if(!wrap) return;
    wrap.style.display = cb.checked ? "" : "none";
  });

  function normalizeDateInput(value){
    const digits = (value || "").replace(/[^\d]/g, "");
    if(digits.length === 6){
      return digits.slice(0,2) + "/" + digits.slice(2,4) + "/20" + digits.slice(4,6);
    }
    if(digits.length === 8){
      return digits.slice(0,2) + "/" + digits.slice(2,4) + "/" + digits.slice(4,8);
    }
    return value;
  }

  document.addEventListener("input",(e)=>{
    if(!e.target.matches(".expman-date-input")) return;
    const val = e.target.value || "";
    if(/[\/\-.]/.test(val)) return;
    const normalized = normalizeDateInput(val);
    if(normalized !== val){
      e.target.value = normalized;
    }
  });
  document.addEventListener("blur",(e)=>{
    if(!e.target.matches(".expman-date-input")) return;
    const normalized = normalizeDateInput(e.target.value);
    e.target.value = normalized;
  }, true);

  // Customer search
  function fetchCustomers(query){
    const url = ajax + "?action=expman_customer_search&nonce=" + encodeURIComponent(nonce) + "&q=" + encodeURIComponent(query);
    return fetch(url).then(r=>r.json()).then(d => (d && d.items) ? d.items : []).catch(()=>[]);
  }

  document.addEventListener("input", async (e)=>{
    const input = e.target.closest(".expman-customer-search");
    if(!input) return;

    const form = input.closest("form");
    const results = form ? form.querySelector(".expman-customer-results") : null;
    if(!results) return;

    results.innerHTML = "";
    const q = (input.value || "").trim();
    if(q.length < 2) return;

    const items = await fetchCustomers(q);
    const box = document.createElement("div");
    box.style.position="absolute";
    box.style.background="#fff";
    box.style.border="1px solid #ddd";
    box.style.borderRadius="6px";
    box.style.zIndex="9999";
    box.style.width="100%";
    box.style.maxHeight="240px";
    box.style.overflow="auto";

    items.forEach(it=>{
      const b = document.createElement("button");
      b.type="button";
      b.style.display="block";
      b.style.width="100%";
      b.style.textAlign="right";
      b.style.padding="6px 8px";
      b.style.border="0";
      b.style.background="transparent";
      b.textContent = (it.customer_number||"") + " - " + (it.customer_name||"");
      b.addEventListener("click", ()=>{
        input.value = b.textContent;
        const formId = form.getAttribute("id") || "";
        const idField = form.querySelector("input[name=\"customer_id\"]") || (formId ? document.querySelector('input[name="customer_id"][form="'+formId+'"]') : null);
        if(idField) idField.value = it.id || "";
        const num = form.querySelector("input[name=\"customer_number\"]") || (formId ? document.querySelector('input[name="customer_number"][form="'+formId+'"]') : null);
        const name= form.querySelector("input[name=\"customer_name\"]") || (formId ? document.querySelector('input[name="customer_name"][form="'+formId+'"]') : null);
        if(num) num.value = it.customer_number || "";
        if(name) name.value = it.customer_name || "";
        results.innerHTML = "";
      });
      box.appendChild(b);
    });

    results.appendChild(box);
  });

  document.addEventListener("click",(e)=>{
    if(e.target.closest(".expman-customer-search")) return;
    document.querySelectorAll(".expman-customer-results").forEach(r=>r.innerHTML="");
  });

  // Init
  setActiveSummary("all");
  applyStatusFilter("all");
})();
JS;

        echo '<script>' . $js . '</script>';

        echo '</div>'; // .expman-frontend
    }
    private function get_active_tab() {
        $allowed = array( 'main', 'trash', 'settings', 'assign', 'logs' );
        $tab = sanitize_key( $_REQUEST['tab'] ?? 'main' );
        return in_array( $tab, $allowed, true ) ? $tab : 'main';
    }

    private function render_internal_tabs( $active ) {
        $tabs = array(
            'main'     => 'טבלה ראשית',
            'trash'    => 'סל מחזור',
            'settings' => 'הגדרות',
            'assign'   => 'שיוך לקוח',
            'logs'     => 'לוגים',
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
    }

    private function common_filters_from_get() {
        return array(
            'customer_number' => sanitize_text_field( wp_unslash( $_GET['customer_number'] ?? '' ) ),
            'customer_name'   => sanitize_text_field( wp_unslash( $_GET['customer_name'] ?? '' ) ),
            'service_tag'     => sanitize_text_field( wp_unslash( $_GET['service_tag'] ?? '' ) ),
        );
    }

    private function render_main_tab() {
        $actions = $this->page->get_actions();
        $filters = $this->common_filters_from_get();
        $orderby = sanitize_key( $_GET['orderby'] ?? 'days_to_end' );
        $order   = sanitize_key( $_GET['order'] ?? 'ASC' );

        $per_page = intval( $_GET['per_page'] ?? 20 );
        $allowed_per_page = array( 20, 50, 100, 500 );
        if ( ! in_array( $per_page, $allowed_per_page, true ) ) {
            $per_page = 20;
        }
        $page_num = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $offset = ( $page_num - 1 ) * $per_page;

        $total = $actions->get_servers_total( $filters, false );
        $rows = $actions->get_servers_rows( $filters, $orderby, $order, false, $per_page, $offset );
        $thresholds = $actions->get_summary_counts();

        $settings = $this->page->get_dell_settings();
        $contact_name  = sanitize_text_field( $settings['contact_name'] ?? '' );
        $contact_email = sanitize_email( $settings['contact_email'] ?? '' );

        echo '<div class="expman-actionbar">';
        echo '<button type="button" id="expman-open-new-server" class="expman-btn">שרת חדש</button>';
        echo '<button type="submit" class="expman-btn secondary" form="expman-servers-bulk-form">Sync מסומנים</button>';
        echo '<form method="get" style="margin-right:auto;display:flex;align-items:center;gap:8px;">';
        echo '<input type="hidden" name="page" value="' . esc_attr( sanitize_text_field( $_GET['page'] ?? '' ) ) . '">';
        echo '<input type="hidden" name="tab" value="main">';
        echo '<label style="font-weight:600;">הצג</label>';
        echo '<select name="per_page" onchange="this.form.submit()">';
        foreach ( $allowed_per_page as $opt ) {
            $selected = selected( $per_page, $opt, false );
            echo '<option value="' . esc_attr( $opt ) . '"' . $selected . '>' . esc_html( $opt ) . '</option>';
        }
        echo '</select>';
        echo '<span>רשומות</span>';
        echo '</form>';
        echo '</div>';

        // Bulk form
        echo '<form method="post" id="expman-servers-bulk-form">';
        wp_nonce_field( 'expman_sync_servers_bulk', 'expman_sync_servers_bulk_nonce' );
        echo '<input type="hidden" name="expman_action" value="sync_bulk">';
        echo '<input type="hidden" name="tab" value="main">';
        echo '</form>';

        // New server inline form (within page)
        echo '<div id="expman-new-server-inline" style="display:none;margin:12px 0;padding:12px;border:1px solid #d9e3f2;border-radius:12px;background:#fff;">';
        echo '<h3 style="margin:0 0 10px;">שרת חדש</h3>';
        $this->render_form( 0, null, false, true );
        echo '</div>';

        // Table
        echo '<div class="expman-table-wrap">';
        echo '<table class="widefat" style="margin-bottom:10px;">';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="width:28px;"><input type="checkbox" id="expman-bulk-check-all"></th>';
        echo '<th>מספר לקוח</th>';
        echo '<th>שם לקוח</th>';
        echo '<th>Service Tag</th>';
        echo '<th>Ending On</th>';
        echo '<th>תאריך חידוש אחרון</th>';
        echo '<th>ימים</th>';
        echo '<th>Last Sync</th>';
        echo '<th class="expman-align-left">פעולות</th>';
        echo '</tr>';

        echo '<tr class="expman-filter-row">';
        echo '<th></th>';
        echo '<th><input type="text" class="expman-filter-input" data-filter="customer-number" placeholder="סינון"></th>';
        echo '<th><input type="text" class="expman-filter-input" data-filter="customer-name" placeholder="סינון"></th>';
        echo '<th><input type="text" class="expman-filter-input" data-filter="service-tag" placeholder="סינון"></th>';
        echo '<th><input type="text" class="expman-filter-input" data-filter="ending-on" placeholder="סינון"></th>';
        echo '<th><input type="text" class="expman-filter-input" data-filter="last-renewal-date" placeholder="סינון"></th>';
        echo '<th></th><th></th><th></th>';
        echo '</tr>';

        echo '</thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="9" style="text-align:center;">אין נתונים להצגה.</td></tr>';
        }

        $row_index = 0;
        foreach ( (array) $rows as $row ) {
            $row_index++;
            $row_class = ( $row_index % 2 ) ? '' : 'expman-row-alt';
            $days = isset( $row->days_to_end ) ? intval( $row->days_to_end ) : null;

            $status = 'unknown';
            if ( $days !== null && $row->ending_on ) {
                if ( $days <= intval( $thresholds['red_threshold'] ) ) {
                    $status = 'red';
                } elseif ( $days <= intval( $thresholds['yellow_threshold'] ) ) {
                    $status = 'yellow';
                } else {
                    $status = 'green';
                }
            }

            $days_class = 'expman-days-' . $status;
            $days_label = $days !== null && $row->ending_on ? (string) $days : '—';

            $mailto_quote = '';
            if ( $contact_email !== '' ) {
                $subject = 'בקשת הצעה לחידוש אחריות שרת Dell - ' . ( (string) ( $row->customer_name_snapshot ?? $row->service_tag ) );
                $body_lines = array();
                $greeting = $contact_name !== '' ? 'שלום ' . $contact_name : 'שלום';
                $body_lines[] = $greeting;
                $body_lines[] = '';
                $body_lines[] = 'אבקש הצעת מחיר עבור חידוש אחריות לשרת:';
                $body_lines[] = 'Service Tag: ' . (string) $row->service_tag;
                if ( ! empty( $row->express_service_code ) ) {
                    $body_lines[] = 'Express Service Code: ' . (string) $row->express_service_code;
                }
                if ( ! empty( $row->ending_on ) ) {
                    $body_lines[] = 'תאריך סיום נוכחי: ' . self::fmt_date_short( $row->ending_on );
                }
                $body_lines[] = '';
                $body_lines[] = 'תודה,';
                $mailto_quote = 'mailto:' . rawurlencode( $contact_email ) .
                    '?subject=' . rawurlencode( $subject ) .
                    '&body=' . rawurlencode( implode( "
", $body_lines ) );
            }


            echo '<tr class="expman-row ' . esc_attr( $row_class ) . '" data-expman-status="' . esc_attr( $status ) . '" data-expman-row-id="' . esc_attr( $row->id ) . '"';
            echo ' data-customer-number="' . esc_attr( mb_strtolower( (string) $row->customer_number_snapshot ) ) . '"';
            echo ' data-customer-name="' . esc_attr( mb_strtolower( (string) $row->customer_name_snapshot ) ) . '"';
            echo ' data-service-tag="' . esc_attr( mb_strtolower( (string) $row->service_tag ) ) . '"';
            echo ' data-ending-on="' . esc_attr( mb_strtolower( (string) $row->ending_on ) ) . '"';
            echo ' data-last-renewal-date="' . esc_attr( mb_strtolower( (string) $row->last_renewal_date ) ) . '">';
            echo '<td><input type="checkbox" class="expman-bulk-id" form="expman-servers-bulk-form" name="server_ids[]" value="' . esc_attr( $row->id ) . '"></td>';
            echo '<td>' . esc_html( $row->customer_number_snapshot ) . '</td>';
            echo '<td>' . esc_html( $row->customer_name_snapshot ) . '</td>';
            echo '<td>' . esc_html( $row->service_tag ) . '</td>';
            echo '<td>' . esc_html( self::fmt_date_short( $row->ending_on ) ) . '</td>';
            echo '<td>' . esc_html( self::fmt_date_short( $row->last_renewal_date ) ) . '</td>';
            echo '<td><span class="expman-days-pill ' . esc_attr( $days_class ) . '">' . esc_html( $days_label ) . '</span></td>';
            echo '<td>' . esc_html( self::fmt_datetime_short( $row->last_sync_at ) ) . '</td>';
            echo '<td class="expman-align-left" style="white-space:nowrap;">';
            echo '<button type="button" class="button expman-edit-toggle" data-id="' . esc_attr( $row->id ) . '">ערוך</button> ';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field( 'expman_servers_row_action', 'expman_servers_row_action_nonce' );
            echo '<input type="hidden" name="tab" value="main">';
            echo '<input type="hidden" name="server_id" value="' . esc_attr( $row->id ) . '">';
            echo '<button type="submit" name="expman_action" value="sync_single" class="button">Sync</button> ';
            echo '<button type="submit" name="expman_action" value="trash_server" class="button" onclick="return confirm(\'להעביר לסל המחזור?\');">מחק</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';

            echo '<tr class="expman-details" data-for="' . esc_attr( $row->id ) . '" style="display:none;">';
            echo '<td colspan="9">';
            echo '<div style="display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:12px;">';
            echo '<div><strong>Express Service Code:</strong> ' . esc_html( $row->express_service_code ) . '</div>';
            echo '<div><strong>Ship Date:</strong> ' . esc_html( self::fmt_date_short( $row->ship_date ) ) . '</div>';
            echo '<div><strong>סוג שירות:</strong> ' . esc_html( $row->service_level ) . '</div>';
            echo '<div><strong>תאריך חידוש אחרון:</strong> ' . esc_html( self::fmt_date_short( $row->last_renewal_date ) ) . '</div>';
            echo '<div><strong>דגם שרת:</strong> ' . esc_html( $row->server_model ) . '</div>';
            echo '<div><strong>הודעה זמנית:</strong> ' . ( intval( $row->temp_notice_enabled ) ? esc_html( $row->temp_notice_text ) : '' ) . '</div>';
            echo '<div style="grid-column:span 2;"><strong>הערות:</strong> ' . esc_html( $row->notes ) . '</div>';
            if ( $mailto_quote !== '' ) {
                echo '<div><a class="button" href="' . esc_attr( $mailto_quote ) . '">בקשת הצעה</a></div>';
            }

            echo '</div>';
            echo '</td>';
            echo '</tr>';

            echo '<tr class="expman-inline-form" data-for="' . esc_attr( $row->id ) . '" style="display:none;">';
            echo '<td colspan="9">';
            $this->render_form( intval( $row->id ), $row, false );
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        $total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
        if ( $total_pages > 1 ) {
            $base_url = remove_query_arg( array( 'paged' ) );
            echo '<div style="display:flex;gap:8px;align-items:center;justify-content:flex-end;margin:8px 0;">';
            if ( $page_num > 1 ) {
                $prev_url = add_query_arg( array( 'paged' => $page_num - 1, 'per_page' => $per_page, 'tab' => 'main' ), $base_url );
                echo '<a class="button" href="' . esc_url( $prev_url ) . '">הקודם</a>';
            }
            echo '<span>עמוד ' . esc_html( $page_num ) . ' מתוך ' . esc_html( $total_pages ) . '</span>';
            if ( $page_num < $total_pages ) {
                $next_url = add_query_arg( array( 'paged' => $page_num + 1, 'per_page' => $per_page, 'tab' => 'main' ), $base_url );
                echo '<a class="button" href="' . esc_url( $next_url ) . '">הבא</a>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private function render_form( $id = 0, $row_obj = null, $is_new_modal = false, $is_new_inline = false ) {
        $row = array(
            'customer_id' => 0,
            'customer_number_snapshot' => '',
            'customer_name_snapshot' => '',
            'service_tag' => '',
            'express_service_code' => '',
            'ship_date' => '',
            'ending_on' => '',
            'last_renewal_date' => '',
            'service_level' => '',
            'server_model' => '',
            'notes' => '',
            'temp_notice_enabled' => 0,
            'temp_notice_text' => '',
        );

        if ( $row_obj ) {
            $row['customer_id'] = intval( $row_obj->customer_id );
            $row['customer_number_snapshot'] = (string) ( $row_obj->customer_number_snapshot ?? '' );
            $row['customer_name_snapshot'] = (string) ( $row_obj->customer_name_snapshot ?? '' );
            $row['service_tag'] = (string) ( $row_obj->service_tag ?? '' );
            $row['express_service_code'] = (string) ( $row_obj->express_service_code ?? '' );
            $row['ship_date'] = (string) ( $row_obj->ship_date ?? '' );
            $row['ending_on'] = (string) ( $row_obj->ending_on ?? '' );
            $row['last_renewal_date'] = (string) ( $row_obj->last_renewal_date ?? '' );
            $row['service_level'] = (string) ( $row_obj->service_level ?? '' );
            $row['server_model'] = (string) ( $row_obj->server_model ?? '' );
            $row['notes'] = (string) ( $row_obj->notes ?? '' );
            $row['temp_notice_enabled'] = intval( $row_obj->temp_notice_enabled ?? 0 );
            $row['temp_notice_text'] = (string) ( $row_obj->temp_notice_text ?? '' );
        }

        $container_style = ( $is_new_modal || $is_new_inline ) ? '' : 'margin:0;';
        echo '<style>
            .expman-servers-form{background:#fff;border:1px solid #e3e3e3;border-radius:12px;padding:14px}
            .expman-servers-grid{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:12px;align-items:end}
            .expman-servers-grid .full{grid-column:span 3}
            .expman-servers-grid label{display:block;font-size:12px;color:#333;margin-bottom:4px;font-weight:700}
            .expman-servers-grid input,.expman-servers-grid textarea,.expman-servers-grid select{width:100%;box-sizing:border-box}
            .expman-servers-grid .expman-date-input{height:30px}
            .expman-servers-actions{display:flex;gap:10px;justify-content:flex-start;margin-top:12px;flex-wrap:wrap}
            @media (max-width: 900px){ .expman-servers-grid{grid-template-columns:repeat(1,minmax(160px,1fr));} .expman-servers-grid .full{grid-column:span 1} }
        </style>';

        echo '<form method="post" class="expman-servers-form" style="' . esc_attr( $container_style ) . '">';
        wp_nonce_field( 'expman_save_server', 'expman_save_server_nonce' );
        echo '<input type="hidden" name="expman_action" value="save_server">';
        echo '<input type="hidden" name="tab" value="main">';
        echo '<input type="hidden" name="server_id" value="' . esc_attr( $id ) . '">';

        echo '<div class="expman-servers-grid">';

        echo '<div class="full">';
        echo '<label>לקוח (חיפוש לפי שם/מספר)</label>';
        echo '<input type="hidden" name="customer_id" value="' . esc_attr( $row['customer_id'] ) . '">';
        echo '<input type="text" class="expman-customer-search" placeholder="הקלד חלק מהשם או המספר..." autocomplete="off" value="">';
        echo '<div class="expman-customer-results" style="margin-top:6px;position:relative;"></div>';
        echo '</div>';

        echo '<div><label>מספר לקוח</label><input type="text" name="customer_number" value="' . esc_attr( $row['customer_number_snapshot'] ) . '" readonly></div>';
        echo '<div><label>שם לקוח</label><input type="text" name="customer_name" value="' . esc_attr( $row['customer_name_snapshot'] ) . '" readonly></div>';
        echo '<div><label>Service Tag</label><input type="text" name="service_tag" value="' . esc_attr( $row['service_tag'] ) . '" required></div>';

        echo '<div><label>Express Service Code</label><input type="text" name="express_service_code" value="' . esc_attr( $row['express_service_code'] ) . '"></div>';
        $ship_ui  = self::fmt_date_short( $row['ship_date'] );
        $end_ui   = self::fmt_date_short( $row['ending_on'] );
        $renew_ui = self::fmt_date_short( $row['last_renewal_date'] );
        echo '<div><label>Ship Date</label><input type="text" class="expman-date-input" name="ship_date" value="' . esc_attr( $ship_ui ) . '" placeholder="dd/mm/yyyy" inputmode="numeric" pattern="\\d{2}([\\/\\-.]?\\d{2})([\\/\\-.]?\\d{2,4})"></div>';
        echo '<div><label>Ending On</label><input type="text" class="expman-date-input" name="ending_on" value="' . esc_attr( $end_ui ) . '" placeholder="dd/mm/yyyy" inputmode="numeric" pattern="\\d{2}([\\/\\-.]?\\d{2})([\\/\\-.]?\\d{2,4})"></div>';
        echo '<div><label>תאריך חידוש אחרון</label><input type="text" class="expman-date-input" name="last_renewal_date" value="' . esc_attr( $renew_ui ) . '" placeholder="dd/mm/yyyy" inputmode="numeric" pattern="\\d{2}([\\/\\-.]?\\d{2})([\\/\\-.]?\\d{2,4})"></div>';

        echo '<div><label>סוג שירות</label><input type="text" name="service_level" value="' . esc_attr( $row['service_level'] ) . '"></div>';
        echo '<div><label>דגם שרת</label><input type="text" name="server_model" value="' . esc_attr( $row['server_model'] ) . '"></div>';
        echo '<div><label>סנכרון אחרי שמירה</label><label style="font-weight:600;"><input type="checkbox" name="sync_now" value="1"> כן</label></div>';

        echo '<div class="full"><label>הערות</label><textarea name="notes" rows="2">' . esc_textarea( $row['notes'] ) . '</textarea></div>';

        echo '<div><label><input type="checkbox" class="expman-temp-notice-enabled" name="temp_notice_enabled" value="1"' . checked( $row['temp_notice_enabled'], 1, false ) . '> הודעה זמנית</label></div>';
        echo '<div class="full expman-temp-notice-wrap" style="' . ( $row['temp_notice_enabled'] ? '' : 'display:none;' ) . '"><label>טקסט הודעה זמנית</label><textarea name="temp_notice_text" rows="2">' . esc_textarea( $row['temp_notice_text'] ) . '</textarea></div>';

        echo '</div>';

        echo '<div class="expman-servers-actions">';
        echo '<button type="submit" class="button button-primary">שמור</button>';
        if ( $is_new_inline ) {
            echo '<button type="button" class="button" id="expman-close-new-server">סגור</button>';
        } elseif ( $is_new_modal ) {
            echo '<button type="button" class="button" data-expman-modal-close>סגור</button>';
        } else {
            echo '<button type="button" class="button expman-edit-toggle" data-id="' . esc_attr( $id ) . '">סגור עריכה</button>';
        }
        echo '</div>';

        echo '</form>';
    }

    private function render_settings_tab() {
        $settings = $this->page->get_dell_settings();
        $client_id = (string) ( $settings['client_id'] ?? '' );
        $client_secret = (string) ( $settings['client_secret'] ?? '' );
        $api_key = (string) ( $settings['api_key'] ?? '' );
        $red_days = intval( $settings['red_days'] ?? 30 );
        $yellow_days = intval( $settings['yellow_days'] ?? 60 );
        $contacts = array();
        if ( ! empty( $settings['contacts'] ) && is_array( $settings['contacts'] ) ) {
            foreach ( $settings['contacts'] as $c ) {
                if ( ! is_array( $c ) ) { continue; }
                $name  = (string) ( $c['name'] ?? '' );
                $email = (string) ( $c['email'] ?? '' );
                if ( $name === '' && $email === '' ) { continue; }
                $contacts[] = array( 'name' => $name, 'email' => $email );
            }
        }
        // Backward-compat (single contact)
        if ( empty( $contacts ) ) {
            $contact_name  = (string) ( $settings['contact_name'] ?? '' );
            $contact_email = (string) ( $settings['contact_email'] ?? '' );
            if ( $contact_name !== '' || $contact_email !== '' ) {
                $contacts[] = array( 'name' => $contact_name, 'email' => $contact_email );
            }
        }

        echo '<h3>הגדרות Dell TechDirect</h3>';
        echo '<form method="post" style="max-width:520px;">';
        wp_nonce_field( 'expman_save_dell_settings', 'expman_save_dell_settings_nonce' );
        echo '<input type="hidden" name="expman_action" value="save_dell_settings">';
        echo '<input type="hidden" name="tab" value="settings">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Client ID</th><td><input type="text" name="dell_client_id" value="' . esc_attr( $client_id ) . '" class="regular-text"></td></tr>';
        echo '<tr><th>Client Secret</th><td><input type="password" name="dell_client_secret" value="' . esc_attr( $client_secret ) . '" class="regular-text"></td></tr>';
        echo '<tr><th>API Key</th><td><input type="text" name="dell_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text"></td></tr>';
        echo '</tbody></table>';

        echo '<h4 style="margin:16px 0 8px;">אנשי קשר להצעות</h4>';
        echo '<p style="margin:0 0 8px;color:#666;">האיש קשר הראשון ברשימה משמש כברירת מחדל לכפתור "בקשת הצעה".</p>';
        echo '<table class="widefat" id="dell-contacts-table" style="max-width:520px;">';
        echo '<thead><tr><th>שם</th><th>מייל</th><th style="width:90px;">פעולה</th></tr></thead><tbody>';
        if ( empty( $contacts ) ) {
            $contacts = array( array( 'name' => '', 'email' => '' ) );
        }
        foreach ( $contacts as $c ) {
            echo '<tr>';
            echo '<td><input type="text" name="dell_contact_name[]" value="' . esc_attr( (string) ( $c['name'] ?? '' ) ) . '" class="regular-text" style="width:100%;"></td>';
            echo '<td><input type="email" name="dell_contact_email[]" value="' . esc_attr( (string) ( $c['email'] ?? '' ) ) . '" class="regular-text" placeholder="name@example.com" style="width:100%;"></td>';
            echo '<td><button type="button" class="button dell-remove-contact">הסר</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<button type="button" class="button" id="dell-add-contact" style="margin-top:8px;">הוסף איש קשר</button>';

        echo '<table class="form-table" style="max-width:520px;"><tbody>';
        
        echo '<tr><th>Red Days</th><td><input type="number" name="dell_red_days" value="' . esc_attr( $red_days ) . '" class="small-text"></td></tr>';
        echo '<tr><th>Yellow Days</th><td><input type="number" name="dell_yellow_days" value="' . esc_attr( $yellow_days ) . '" class="small-text"></td></tr>';
        echo '</tbody></table>';
        echo '<script>
        (function(){
            var addBtn = document.getElementById("dell-add-contact");
            var table = document.getElementById("dell-contacts-table");
            if(!addBtn || !table){return;}
            table.addEventListener("click", function(e){
                var btn = e.target.closest(".dell-remove-contact");
                if(!btn){return;}
                var tr = btn.closest("tr");
                if(!tr){return;}
                var body = table.querySelector("tbody");
                if(body && body.querySelectorAll("tr").length <= 1){
                    tr.querySelectorAll("input").forEach(function(i){i.value="";});
                    return;
                }
                tr.remove();
            });
            addBtn.addEventListener("click", function(){
                var body = table.querySelector("tbody");
                if(!body){return;}
                var tr = document.createElement("tr");
                tr.innerHTML = "<td><input type=\"text\" name=\"dell_contact_name[]\" value=\"\" class=\"regular-text\" style=\"width:100%;\"></td>"+
                              "<td><input type=\"email\" name=\"dell_contact_email[]\" value=\"\" class=\"regular-text\" placeholder=\"name@example.com\" style=\"width:100%;\"></td>"+
                              "<td><button type=\"button\" class=\"button dell-remove-contact\">הסר</button></td>";
                body.appendChild(tr);
            });
        })();
        </script>';
        echo '<button type="submit" class="button button-primary">שמירה</button>';
        echo '</form>';

        echo '<hr style="margin:24px 0;">';
        echo '<h3>ייבוא / ייצוא לאקסל (CSV)</h3>';
        echo '<p style="color:#666;">הקובץ מיוצא בקידוד UTF-8 עם BOM כדי לתמוך בעברית.</p>';
        echo '<form method="post" style="margin-bottom:12px;">';
        wp_nonce_field( 'expman_export_servers_csv', 'expman_export_servers_csv_nonce' );
        echo '<input type="hidden" name="expman_action" value="export_servers_csv">';
        echo '<input type="hidden" name="tab" value="settings">';
        echo '<button type="submit" class="button">ייצוא לאקסל</button>';
        echo '</form>';

        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field( 'expman_import_servers_excel', 'expman_import_servers_excel_nonce' );
        echo '<input type="hidden" name="expman_action" value="import_excel_settings">';
        echo '<input type="hidden" name="tab" value="settings">';
        echo '<input type="file" name="servers_excel_file" accept=".csv">';
        echo '<button type="submit" class="button">ייבוא מאקסל</button>';
        echo '</form>';
    }

    private function render_logs_tab() {
        $actions = $this->page->get_actions();
        $logger = $this->page->get_logger();
        $rows = $actions->get_logs();

        echo '<h3>לוגים</h3>';
        echo '<table class="widefat">';
        echo '<thead><tr><th>תאריך</th><th>לקוח</th><th>Service Tag</th><th>פעולה</th><th>רמה</th><th>הודעה</th><th>פרטים</th></tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="7" style="text-align:center;">אין לוגים להצגה.</td></tr>';
        }
        foreach ( (array) $rows as $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( self::fmt_datetime_short( $row->created_at ) ) . '</td>';
            echo '<td>' . esc_html( $row->customer_name_snapshot ?? '' ) . '</td>';
            echo '<td>' . esc_html( $row->service_tag ?? '' ) . '</td>';
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
        wp_nonce_field( 'expman_empty_servers_trash', 'expman_empty_servers_trash_nonce' );
        echo '<input type="hidden" name="expman_action" value="empty_trash">';
        echo '<input type="hidden" name="tab" value="trash">';
        echo '<button type="submit" class="button" onclick="return confirm(\'לרוקן את סל המחזור?\');">רוקן סל מחזור</button>';
        echo '</form>';

        echo '<table class="widefat">';
        echo '<thead><tr><th>לקוח</th><th>Service Tag</th><th>נמחק בתאריך</th><th class="expman-align-left">פעולות</th></tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="4" style="text-align:center;">סל המחזור ריק.</td></tr>';
        }
        foreach ( (array) $rows as $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $row->customer_name_snapshot ) . '</td>';
            echo '<td>' . esc_html( $row->service_tag ) . '</td>';
            echo '<td>' . esc_html( self::fmt_datetime_short( $row->deleted_at ) ) . '</td>';
            echo '<td class="expman-align-left" style="white-space:nowrap;">';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field( 'expman_restore_server', 'expman_restore_server_nonce' );
            echo '<input type="hidden" name="expman_action" value="restore_server">';
            echo '<input type="hidden" name="tab" value="trash">';
            echo '<input type="hidden" name="server_id" value="' . esc_attr( $row->id ) . '">';
            echo '<button type="submit" class="button">שחזור</button>';
            echo '</form> ';
            echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'למחוק לצמיתות?\');">';
            wp_nonce_field( 'expman_delete_server_permanently', 'expman_delete_server_permanently_nonce' );
            echo '<input type="hidden" name="expman_action" value="delete_server_permanently">';
            echo '<input type="hidden" name="tab" value="trash">';
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
        wp_nonce_field( 'expman_import_servers_csv', 'expman_import_servers_csv_nonce' );
        echo '<input type="hidden" name="expman_action" value="import_csv">';
        echo '<input type="hidden" name="tab" value="assign">';
        echo '<input type="file" name="servers_file" accept=".csv">';
        echo '<button type="submit" class="button">ייבוא CSV</button>';
        echo '</form>';

        echo '<form method="post" style="margin-bottom:16px;">';
        wp_nonce_field( 'expman_empty_servers_stage', 'expman_empty_servers_stage_nonce' );
        echo '<input type="hidden" name="expman_action" value="empty_import_stage">';
        echo '<input type="hidden" name="tab" value="assign">';
        echo '<button type="submit" class="button" onclick="return confirm(\'לנקות את כל רשימת השיוך?\');">נקה רשימה</button>';
        echo '</form>';

        if ( empty( $rows ) ) {
            echo '<div class="notice notice-info"><p>אין שורות לשיוך.</p></div>';
            return;
        }

        echo '<style>
        .expman-assign-form{display:flex;gap:8px;align-items:center;flex-wrap:nowrap;}
        .expman-assign-form input[type="text"]{height:28px;}
        .expman-assign-form .expman-customer-search{min-width:220px;}
        .expman-assign-field{width:100%;box-sizing:border-box;height:28px;}
        .expman-assign-field[readonly]{background:#f3f5f8;}
        @media (max-width: 900px){ .expman-assign-form{flex-wrap:wrap;} }
        </style>';

        echo '<table class="widefat">';
        echo '<thead><tr><th>חיפוש לקוח</th><th>מספר לקוח</th><th>שם לקוח</th><th>Service Tag</th><th>תאריך חידוש</th><th>הערות</th><th class="expman-align-left">פעולות</th></tr></thead><tbody>';
        foreach ( (array) $rows as $row ) {
            $form_id = 'expman-assign-' . intval( $row->id );
            $last_renewal_ui = self::fmt_date_short( $row->last_renewal_date );
            echo '<tr>';
            echo '<td>';
            echo '<form method="post" id="' . esc_attr( $form_id ) . '" class="expman-assign-form">';
            wp_nonce_field( 'expman_assign_servers_stage', 'expman_assign_servers_stage_nonce' );
            echo '<input type="hidden" name="expman_action" value="assign_import_stage">';
            echo '<input type="hidden" name="tab" value="assign">';
            echo '<input type="hidden" name="stage_id" value="' . esc_attr( $row->id ) . '">';
            echo '<input type="hidden" name="service_tag" value="' . esc_attr( $row->service_tag ) . '">';
            echo '<input type="hidden" name="last_renewal_date" value="' . esc_attr( $row->last_renewal_date ) . '">';
            echo '<input type="hidden" name="notes" value="' . esc_attr( $row->notes ) . '">';
            echo '<input type="hidden" name="customer_id" value="">';
            echo '<input type="text" class="expman-customer-search" placeholder="חפש לקוח..." autocomplete="off">';
            echo '<div class="expman-customer-results" style="position:relative;"></div>';
            echo '</form>';
            echo '</td>';
            echo '<td><input type="text" class="expman-assign-field" name="customer_number" form="' . esc_attr( $form_id ) . '" value="' . esc_attr( $row->customer_number ) . '" readonly></td>';
            echo '<td><input type="text" class="expman-assign-field" name="customer_name" form="' . esc_attr( $form_id ) . '" value="' . esc_attr( $row->customer_name ) . '" readonly></td>';
            echo '<td><input type="text" class="expman-assign-field" form="' . esc_attr( $form_id ) . '" value="' . esc_attr( $row->service_tag ) . '" readonly></td>';
            echo '<td><input type="text" class="expman-assign-field" form="' . esc_attr( $form_id ) . '" value="' . esc_attr( $last_renewal_ui ) . '" readonly></td>';
            echo '<td><input type="text" class="expman-assign-field" form="' . esc_attr( $form_id ) . '" value="' . esc_attr( $row->notes ) . '" readonly></td>';
            echo '<td class="expman-align-left" style="white-space:nowrap;">';
            echo '<button type="submit" class="button" form="' . esc_attr( $form_id ) . '">שיוך</button> ';
            echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'למחוק את השורה?\');">';
            wp_nonce_field( 'expman_delete_servers_stage', 'expman_delete_servers_stage_nonce' );
            echo '<input type="hidden" name="expman_action" value="delete_import_stage">';
            echo '<input type="hidden" name="tab" value="assign">';
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
