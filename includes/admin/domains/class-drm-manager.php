<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'DRM_Manager' ) ) {
class DRM_Manager {

    private const TABLE = 'kb_kb_domain_expiry';

    private function get_thresholds() {
        $settings = get_option( Expiry_Manager_Plugin::OPTION_KEY, array() );
        return array(
            'yellow' => intval( $settings['yellow_threshold'] ?? 90 ),
            'red'    => intval( $settings['red_threshold'] ?? 30 ),
        );
    }

    private function render_summary_cards( $data ) {
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

        $yellow_label = 'תוקף בין ' . ( $data['red_threshold'] + 1 ) . ' ל-' . $data['yellow_threshold'] . ' יום';
        echo '<div class="expman-summary">';
        echo '<div class="expman-summary-card green" data-expman-status="green"><button type="button"><h4>תוקף מעל ' . esc_html( $data['yellow_threshold'] ) . ' יום</h4><div class="count">' . esc_html( $data['green'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card yellow" data-expman-status="yellow"><button type="button"><h4>' . esc_html( $yellow_label ) . '</h4><div class="count">' . esc_html( $data['yellow'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card red" data-expman-status="red"><button type="button"><h4>דורש טיפול מייד</h4><div class="count">' . esc_html( $data['red'] ) . '</div></button></div>';
        echo '</div>';
        echo '<div class="expman-summary-meta" data-expman-status="all"><button type="button">סה״כ רשומות פעילות: ' . esc_html( $data['total'] ) . '</button></div>';
    }

    private function normalize_ui_date_to_db( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) { return ''; }

        // UI format: dd/mm/yyyy. Accept common variants and DB format.
        $formats = array( 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'Y-m-d H:i:s' );
        foreach ( $formats as $fmt ) {
            $dt = DateTime::createFromFormat( $fmt, $value );
            if ( $dt instanceof DateTime ) {
                return $dt->format( 'Y-m-d' );
            }
        }

        return '';
    }

    private function format_db_date_to_ui( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) { return ''; }

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

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'admin_post_expman_save_domain', array( $this, 'handle_save' ) );
        add_action( 'wp_ajax_expman_domains_fetch', array( $this, 'handle_fetch' ) );
        add_action( 'admin_post_expman_domain_trash', array( $this, 'handle_trash' ) );
        add_action( 'admin_post_expman_domain_restore', array( $this, 'handle_restore' ) );
        add_action( 'admin_post_expman_domain_delete', array( $this, 'handle_delete' ) );
    }

    public function on_activate() {
        // Domain Expiry Manager activation logic should be implemented here.
    }

    public function on_deactivate() {
        // Domain Expiry Manager deactivation logic should be implemented here.
    }

    public function admin_assets( $hook ) {
        if ( empty( $_GET['page'] ) || $_GET['page'] !== 'expman_domains' ) {
            return;
        }
        // Assets enqueue logic should be implemented here.
    }

    public function enqueue_front_assets(): void {
        // Assets enqueue logic for public view should be implemented here.
    }

    public function render_admin() {
        $this->render_table( 'main' );
    }

    public function render_trash() {
        $this->render_table( 'trash' );
    }

    public function render_map() {
        $this->render_table( 'map' );
    }

    private function render_table( $mode ) {
        $allowed_sort = array(
            'client_name',
            'customer_number',
            'customer_name',
            'domain',
            'expiry_date',
            'days_left',
            'ownership',
            'payment',
        );

        $orderby = sanitize_key( $_GET['orderby'] ?? 'days_left' );
        if ( ! in_array( $orderby, $allowed_sort, true ) ) {
            $orderby = 'days_left';
        }

        $order = strtoupper( sanitize_key( $_GET['order'] ?? 'ASC' ) );
        if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
            $order = 'ASC';
        }

        $filters = array(
            'client_name'     => sanitize_text_field( $_GET['f_client_name'] ?? '' ),
            'customer_number' => sanitize_text_field( $_GET['f_customer_number'] ?? '' ),
            'customer_name'   => sanitize_text_field( $_GET['f_customer_name'] ?? '' ),
            'domain'          => sanitize_text_field( $_GET['f_domain'] ?? '' ),
            'expiry_date'     => sanitize_text_field( $_GET['f_expiry_date'] ?? '' ),
            'days_left'       => sanitize_text_field( $_GET['f_days_left'] ?? '' ),
            'ownership'       => sanitize_text_field( $_GET['f_ownership'] ?? '' ),
            'payment'         => sanitize_text_field( $_GET['f_payment'] ?? '' ),
            'registrar'       => sanitize_text_field( $_GET['f_registrar'] ?? '' ),
            'expiry_from'     => sanitize_text_field( $_GET['f_expiry_from'] ?? '' ),
            'expiry_to'       => sanitize_text_field( $_GET['f_expiry_to'] ?? '' ),
        );

        $rows = $this->get_rows( $filters, $orderby, $order, $mode );
        $summary = $this->get_summary_counts( $mode );

        $base = remove_query_arg( array( 'orderby', 'order' ) );
        $clear_url = remove_query_arg(
            array(
                'f_client_name',
                'f_customer_number',
                'f_customer_name',
                'f_domain',
                'f_expiry_date',
                'f_days_left',
                'f_ownership',
                'f_payment',
                'f_registrar',
                'f_expiry_from',
                'f_expiry_to',
            )
        );

        echo '<style>
        .expman-filter-row input,.expman-filter-row select{height:24px !important;padding:4px 6px !important;font-size:12px !important;border:1px solid #c7d1e0;border-radius:4px;background:#fff;}
        .expman-filter-row th{background:#e8f0fb !important;border-bottom:2px solid #c7d1e0;}
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
        .expman-domains-wrap h1,
.expman-domains-wrap h2,
.expman-domains-wrap h3,
.expman-domains-wrap .section-title {color:#1d2327 !important;}
.expman-domains-filters label,
.expman-domains-grid label {color:#1d2327 !important;}
.expman-frontend .widefat thead th a{color:#fff !important;text-decoration:none;}
.expman-frontend .widefat thead th a:hover{color:#fff !important;text-decoration:underline;}
        .expman-domains-wrap table th,
        .expman-domains-wrap table td {padding-top:6px !important;padding-bottom:6px !important;line-height:1.2 !important;vertical-align:middle !important;}
        .expman-domains-wrap tr.expman-details td,
        .expman-domains-wrap tr.expman-edit td {padding-top:10px !important;padding-bottom:10px !important;}
        </style>';

        echo '<div class="expman-domains-wrap expman-frontend expman-domains" style="direction:rtl;">';
        echo '<h2 style="margin-top:10px;color:#1d2327;">דומיינים</h2>';
        if ( $mode === 'main' ) {
            $this->render_summary_cards( $summary );
        }

        if ( $mode === 'main' ) {
            echo '<div style="display:flex;gap:10px;align-items:center;justify-content:flex-end;margin:10px 0;">';
            echo '<button type="button" class="expman-btn" data-expman-new>חדש</button>';
            echo '</div>';
            echo '<div class="expman-domains-add" style="display:none;">';
            $this->render_form();
            echo '</div>';
        }

        echo '<div class="expman-domains-filters" style="margin:10px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">';
        echo '<div><label>רשם הדומיין</label><input type="text" name="f_registrar" form="expman-domains-filter-form" value="' . esc_attr( $filters['registrar'] ) . '"></div>';
        $expiry_from_ui = $this->format_db_date_to_ui( $filters['expiry_from'] );
        $expiry_to_ui   = $this->format_db_date_to_ui( $filters['expiry_to'] );
        echo '<div><label>תאריך תפוגה מ-</label><input type="text" name="f_expiry_from" form="expman-domains-filter-form" value="' . esc_attr( $expiry_from_ui ) . '" placeholder="dd/mm/yyyy" inputmode="numeric" pattern="\\d{2}\\/\\d{2}\\/\\d{4}"></div>';
        echo '<div><label>תאריך תפוגה עד</label><input type="text" name="f_expiry_to" form="expman-domains-filter-form" value="' . esc_attr( $expiry_to_ui ) . '" placeholder="dd/mm/yyyy" inputmode="numeric" pattern="\\d{2}\\/\\d{2}\\/\\d{4}"></div>';
        echo '</div>';

        $ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
        $nonce = wp_create_nonce( 'expman_domains_fetch' );

        $tab_value = $mode === 'trash' ? 'trash' : ( $mode === 'map' ? 'map' : 'main' );
        echo '<form method="get" action="" id="expman-domains-filter-form" data-expman-table data-ajax="' . esc_attr( $ajax_url ) . '" data-nonce="' . esc_attr( $nonce ) . '" data-mode="' . esc_attr( $mode ) . '">';
        echo '<input type="hidden" name="page" value="expman_domains">';
        echo '<input type="hidden" name="tab" value="' . esc_attr( $tab_value ) . '">';
        echo '<input type="hidden" name="orderby" value="' . esc_attr( $orderby ) . '">';
        echo '<input type="hidden" name="order" value="' . esc_attr( $order ) . '">';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';

        $this->th_sort( 'client_name', 'שם לקוח ישן', $orderby, $order, $base );
        $this->th_sort( 'customer_number', 'מספר לקוח חדש', $orderby, $order, $base );
        $this->th_sort( 'customer_name', 'שם לקוח חדש', $orderby, $order, $base );
        $this->th_sort( 'domain', 'שם הדומיין', $orderby, $order, $base );
        $this->th_sort( 'expiry_date', 'תאריך תפוגה', $orderby, $order, $base );
        $this->th_sort( 'days_left', 'ימים לתפוגה', $orderby, $order, $base );
        $this->th_sort( 'ownership', 'ניהול', $orderby, $order, $base );
        $this->th_sort( 'payment', 'תשלום', $orderby, $order, $base );
        echo '<th>פעולות</th>';
        echo '</tr>';

        echo '<tr class="expman-filter-row">';
        echo '<th><input style="width:100%" name="f_client_name" value="' . esc_attr( $filters['client_name'] ) . '" placeholder="סינון..."></th>';
        echo '<th><input style="width:100%" name="f_customer_number" value="' . esc_attr( $filters['customer_number'] ) . '" placeholder="סינון..."></th>';
        echo '<th><input style="width:100%" name="f_customer_name" value="' . esc_attr( $filters['customer_name'] ) . '" placeholder="סינון..."></th>';
        echo '<th><input style="width:100%" name="f_domain" value="' . esc_attr( $filters['domain'] ) . '" placeholder="סינון..."></th>';
        $expiry_exact_ui = $this->format_db_date_to_ui( $filters['expiry_date'] );
        echo '<th><input style="width:100%" name="f_expiry_date" value="' . esc_attr( $expiry_exact_ui ) . '" placeholder="dd/mm/yyyy"></th>';
        echo '<th><input style="width:100%" name="f_days_left" value="' . esc_attr( $filters['days_left'] ) . '" placeholder="ימים..."></th>';
        
echo '<th><select name="f_ownership" style="width:100%;">';
echo '<option value="">הכל</option>';
echo '<option value="ours" ' . selected( $filters['ownership'], 'ours', false ) . '>שלנו</option>';
echo '<option value="not_ours" ' . selected( $filters['ownership'], 'not_ours', false ) . '>לא שלנו</option>';
echo '</select></th>';
        
echo '<th><select name="f_payment" style="width:100%;">';
echo '<option value="">הכל</option>';
echo '<option value="ours" ' . selected( $filters['payment'], 'ours', false ) . '>שלנו</option>';
echo '<option value="customer" ' . selected( $filters['payment'], 'customer', false ) . '>לקוח</option>';
echo '</select></th>';
        echo '<th style="white-space:nowrap;">';
        if ( $clear_url ) {
            echo '<a class="expman-btn secondary" style="display:inline-block;text-decoration:none;" href="' . esc_url( $clear_url ) . '">נקה</a>';
        }
        echo '</th>';
        echo '</tr>';

        echo '</thead><tbody data-expman-body>';
        echo $this->render_rows_html( $rows, $mode );
        echo '</tbody></table>';
        echo '</form>';

        echo '<script>
        (function(){
            const wrap = document.querySelector(".expman-domains-wrap");
            if(!wrap){return;}

            const addToggle = wrap.querySelector("[data-expman-new]");
            const addForm = wrap.querySelector(".expman-domains-add");
            if(addToggle && addForm){
                addToggle.addEventListener("click", function(){
                    addForm.style.display = (addForm.style.display === "none" || addForm.style.display === "") ? "block" : "none";
                    if(addForm.style.display === "block"){
                        addForm.scrollIntoView({behavior:"smooth", block:"start"});
                    }
                });
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

            function setActiveSummary(status){
                wrap.querySelectorAll(".expman-summary-card, .expman-summary-meta").forEach(function(card){
                    card.setAttribute("data-active", card.getAttribute("data-expman-status") === status ? "1" : "0");
                });
            }

            function applyStatusFilter(status){
                wrap.querySelectorAll("tr.expman-row").forEach(function(row){
                    var rowStatus = row.getAttribute("data-expman-status") || "";
                    var show = (status === "all") || (rowStatus === status);
                    row.style.display = show ? "" : "none";
                    var id = row.getAttribute("data-id");
                    if(id){
                        var detail = wrap.querySelector("tr.expman-details[data-for=\'" + id + "\']");
                        var edit = wrap.querySelector("tr.expman-edit[data-for=\'" + id + "\']");
                        if(detail) detail.style.display = "none";
                        if(edit) edit.style.display = "none";
                    }
                });
            }

            wrap.querySelectorAll(".expman-summary-card, .expman-summary-meta").forEach(function(card){
                card.addEventListener("click", function(){
                    var status = card.getAttribute("data-expman-status") || "all";
                    setActiveSummary(status);
                    applyStatusFilter(status);
                });
            });

            function wireEditButtons(scope){
                scope.querySelectorAll(".expman-toggle-edit").forEach(function(btn){
                    btn.addEventListener("click", function(e){
                        e.preventDefault();
                        var id = btn.getAttribute("data-id");
                        var row = scope.querySelector("tr.expman-edit[data-for=\'" + id + "\']");
                        if(!row){return;}
                        row.style.display = (row.style.display === "none" || row.style.display === "") ? "table-row" : "none";
                    });
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

            const form = wrap.querySelector("form[data-expman-table]");
            if(form){
                const ajax = form.getAttribute("data-ajax");
                const nonce = form.getAttribute("data-nonce");
                const mode = form.getAttribute("data-mode");
                const body = form.querySelector("[data-expman-body]");

                const runFetch = function(){
                    const data = new FormData(form);
                    data.append("action", "expman_domains_fetch");
                    data.append("nonce", nonce || "");
                    data.append("mode", mode || "main");
                    fetch(ajax, { method:"POST", body:data })
                        .then(r=>r.json())
                    .then(function(res){
                        if(!res || !res.success){return;}
                        body.innerHTML = res.data.html || "";
                        wireRowClicks(body);
                        wireEditButtons(body);
                        applyStatusFilter("all");
                    })
                    .catch(()=>{});
            };

                
wrap.addEventListener("click", function(e){
    var a = e.target.closest("a.expman-sort");
    if(!a){return;}
    e.preventDefault();
    var ob = a.getAttribute("data-orderby") || "";
    var or = a.getAttribute("data-order") || "";
    var obInput = form.querySelector("input[name=\"orderby\"]");
    var orInput = form.querySelector("input[name=\"order\"]");
    if(obInput) obInput.value = ob;
    if(orInput) orInput.value = or;
    runFetch();
});

form.addEventListener("submit", function(e){
                    e.preventDefault();
                    runFetch();
                });

                const debounced = debounce(runFetch, 350);
                form.querySelectorAll(".expman-filter-row input").forEach(function(input){
                    input.addEventListener("input", debounced);
                });
                form.querySelectorAll(".expman-filter-row select").forEach(function(sel){
                    sel.addEventListener("change", runFetch);
                });
                wrap.querySelectorAll(".expman-domains-filters input").forEach(function(input){
                    input.addEventListener("input", debounced);
                    input.addEventListener("change", runFetch);
                });
            }

            wireRowClicks(wrap);
            wireEditButtons(wrap);
            setActiveSummary("all");
        })();
        </script>';

        echo '</div>';
    }

    public function render_settings() {
        echo '<div class="notice notice-info"><p>מסך ההגדרות לדומיינים יהיה כאן.</p></div>';
    }

    private function render_form( $id = 0, $row = null ) {
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
            $data['client_name']     = (string) ( $row['client_name'] ?? '' );
            $data['customer_number'] = (string) ( $row['customer_number'] ?? '' );
            $data['customer_name']   = (string) ( $row['customer_name'] ?? '' );
            $data['domain']          = (string) ( $row['domain'] ?? '' );
            $data['expiry_date']     = (string) ( $row['expiry_date'] ?? '' );
            $data['days_left']       = (string) ( $row['days_left'] ?? '' );
            $data['manual_expiry']    = (string) ( $row['manual_expiry'] ?? '0' );
            $data['free_text_domain'] = (string) ( $row['free_text_domain'] ?? '0' );
            $data['archived']         = (string) ( $row['archived'] ?? '0' );
            $data['temp_text_enabled']= (string) ( $row['temp_text_enabled'] ?? '0' );
            $data['ownership']       = (string) ( $row['ownership'] ?? '' );
            $data['payment']         = (string) ( $row['payment'] ?? '' );
            $data['registrar']       = (string) ( $row['registrar'] ?? '' );
            $data['notes']           = (string) ( $row['notes'] ?? '' );
            $data['temp_text']       = (string) ( $row['temp_text'] ?? '' );
        }

        echo '<style>
            .expman-domains-form{background:#fff;border:1px solid #e3e3e3;border-radius:12px;padding:14px;margin:12px 0}
            .expman-domains-grid{display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:12px;align-items:end}
            .expman-domains-grid .full{grid-column:span 3}
            .expman-domains-grid label{display:block;font-size:12px;color:#333;margin-bottom:4px}
            .expman-domains-grid input,.expman-domains-grid textarea,.expman-domains-grid select{width:100%;box-sizing:border-box}
            .expman-domains-actions{display:flex;gap:10px;justify-content:flex-start;margin-top:12px}
            @media (max-width: 900px){ .expman-domains-grid{grid-template-columns:repeat(1,minmax(160px,1fr));} .expman-domains-grid .full{grid-column:span 1} }
        </style>';

        echo '<form method="post" class="expman-domains-form" style="margin:0;" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'expman_save_domain', 'expman_save_domain_nonce' );
        echo '<input type="hidden" name="action" value="expman_save_domain">';
        echo '<input type="hidden" name="domain_id" value="' . esc_attr( $id ) . '">';

        echo '<div class="expman-domains-grid">';
        echo '<div><label>שם לקוח ישן</label><input type="text" name="client_name" required value="' . esc_attr( $data['client_name'] ) . '"></div>';
        echo '<div><label>מספר לקוח חדש</label><input type="text" name="customer_number" value="' . esc_attr( $data['customer_number'] ) . '"></div>';
        echo '<div><label>שם לקוח חדש</label><input type="text" name="customer_name" value="' . esc_attr( $data['customer_name'] ) . '"></div>';
        echo '<div><label>שם הדומיין</label><input type="text" name="domain" required value="' . esc_attr( $data['domain'] ) . '"></div>';
        $expiry_ui = $this->format_db_date_to_ui( $data['expiry_date'] );
        echo '<div><label>תאריך תפוגה</label><input type="text" name="expiry_date" value="' . esc_attr( $expiry_ui ) . '" placeholder="dd/mm/yyyy" inputmode="numeric" pattern="\\d{2}\\/\\d{2}\\/\\d{4}"></div>';
        echo '<div><label>ימים לתפוגה</label><input type="number" name="days_left" value="' . esc_attr( $data['days_left'] ) . '"></div>';
echo '<div><label>דגלים</label><div style="display:flex;flex-direction:column;gap:4px;font-size:12px;">';
echo '<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" name="manual_expiry" value="1" ' . checked( $data['manual_expiry'], '1', false ) . '>תוקף ידני</label>';
echo '<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" name="free_text_domain" value="1" ' . checked( $data['free_text_domain'], '1', false ) . '>דומיין טקסט חופשי</label>';
echo '<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" name="archived" value="1" ' . checked( $data['archived'], '1', false ) . '>בארכיון</label>';
echo '<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" name="temp_text_enabled" value="1" ' . checked( $data['temp_text_enabled'], '1', false ) . '>הערה זמנית פעילה</label>';
echo '</div></div>';
        echo '<div><label>רשם הדומיין</label><input type="text" name="registrar" value="' . esc_attr( $data['registrar'] ) . '"></div>';
        
echo '<div><label>ניהול</label><select name="ownership">';
echo '<option value="ours" ' . selected( $data['ownership'], 'ours', false ) . '>שלנו</option>';
echo '<option value="not_ours" ' . selected( $data['ownership'], 'not_ours', false ) . '>לא שלנו</option>';
echo '</select></div>';
        
echo '<div><label>תשלום</label><select name="payment">';
echo '<option value="ours" ' . selected( $data['payment'], 'ours', false ) . '>שלנו</option>';
echo '<option value="customer" ' . selected( $data['payment'], 'customer', false ) . '>לקוח</option>';
echo '</select></div>';
        echo '<div><label>הערות</label><textarea name="notes" rows="2">' . esc_textarea( $data['notes'] ) . '</textarea></div>';
        echo '<div><label>הערות זמניות</label><textarea name="temp_text" rows="2">' . esc_textarea( $data['temp_text'] ) . '</textarea></div>';
        echo '</div>';

        echo '<div class="expman-domains-actions">';
        echo '<button type="submit" class="button button-primary">שמור</button>';
        echo '</div>';
        echo '</form>';
    }

    private function th_sort( $key, $label, $orderby, $order, $base, $class = '' ) {
    $next_order = ( $orderby === $key && $order === 'ASC' ) ? 'DESC' : 'ASC';
    $url = add_query_arg( array( 'orderby' => $key, 'order' => $next_order ), $base );
    $class_attr = $class !== '' ? ' class="' . esc_attr( $class ) . '"' : '';

    $arrow = '';
    if ( $orderby === $key ) {
        $arrow = ( $order === 'ASC' ) ? ' ▲' : ' ▼';
    }

    echo '<th' . $class_attr . '><a class="expman-sort" data-orderby="' . esc_attr( $key ) . '" data-order="' . esc_attr( $next_order ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label . $arrow ) . '</a></th>';
}

    private function get_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::TABLE;
}

/**
 * Migration-safe schema check:
 * - Reuses existing legacy table (kb_kb_domain_expiry)
 * - Adds missing columns (legacy + new mapping fields) without dropping anything
 */
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
            `deleted_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_domain` (`domain`),
            KEY `idx_deleted` (`deleted_at`),
            KEY `idx_days_left` (`days_left`),
            PRIMARY KEY (`id`)
        ) $charset;";

        dbDelta( $sql );
        return;
    }

    $cols = $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 );
    $cols = array_map( 'strtolower', (array) $cols );

    // New mapping fields (legacy-compatible)
    if ( ! in_array( 'customer_number', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `customer_number` VARCHAR(64) NULL AFTER `client_name`" );
    }
        if ( ! in_array( 'customer_name', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `customer_name` VARCHAR(255) NULL AFTER `customer_number`" );
        }
    }

    
private function require_manage_options(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Access denied.' );
    }
}

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

public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        $nonce = sanitize_text_field( $_POST['expman_save_domain_nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'expman_save_domain' ) ) {
            wp_die( 'Invalid nonce.' );
        }

        global $wpdb;
        $table = $this->get_table_name();
        $this->ensure_schema();

        
$id = intval( $_POST['domain_id'] ?? 0 );

$ownership = sanitize_text_field( wp_unslash( $_POST['ownership'] ?? 'ours' ) );
if ( ! in_array( $ownership, array( 'ours', 'not_ours' ), true ) ) {
    $ownership = 'ours';
}

$payment = sanitize_text_field( wp_unslash( $_POST['payment'] ?? 'ours' ) );
if ( ! in_array( $payment, array( 'ours', 'customer' ), true ) ) {
    $payment = 'ours';
}

$manual_expiry     = ! empty( $_POST['manual_expiry'] ) ? 1 : 0;
$free_text_domain  = ! empty( $_POST['free_text_domain'] ) ? 1 : 0;
$archived          = ! empty( $_POST['archived'] ) ? 1 : 0;
$temp_text_enabled = ! empty( $_POST['temp_text_enabled'] ) ? 1 : 0;

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
            'client_name'     => sanitize_text_field( wp_unslash( $_POST['client_name'] ?? '' ) ),
            'customer_number' => sanitize_text_field( wp_unslash( $_POST['customer_number'] ?? '' ) ),
            'customer_name'   => sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) ),
            'domain'          => sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ),
            'expiry_date'     => $expiry_date,
            'days_left'       => $days_left,
            'manual_expiry'    => $manual_expiry,
            'free_text_domain' => $free_text_domain,
            'archived'         => $archived,
            'temp_text_enabled'=> $temp_text_enabled,
            'registrar'       => sanitize_text_field( wp_unslash( $_POST['registrar'] ?? '' ) ),
            'ownership'       => $ownership,
            'payment'         => $payment,
            'notes'           => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
            'temp_text'       => sanitize_textarea_field( wp_unslash( $_POST['temp_text'] ?? '' ) ),
            'updated_at'      => $now,
        );

        if ( $id > 0 ) {
            $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $data['created_at'] = $now;
            $wpdb->insert( $table, $data );
        }

        $redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=expman_domains' );
        wp_safe_redirect( $redirect );
        exit;
    }

    private function get_rows( array $filters, $orderby, $order, $mode ) {
        $this->ensure_schema();
        global $wpdb;
        $table = $this->get_table_name();

        $where = array();
        $params = array();

        if ( $mode === 'trash' ) {
            $where[] = 'deleted_at IS NOT NULL';
        } else {
            $where[] = 'deleted_at IS NULL';
        }

        if ( $mode === 'main' ) {
            $where[] = "( (customer_number IS NOT NULL AND customer_number <> '') OR (customer_name IS NOT NULL AND customer_name <> '') )";
        } elseif ( $mode === 'map' ) {
            $where[] = "( (customer_number IS NULL OR customer_number = '') AND (customer_name IS NULL OR customer_name = '') )";
        }

        if ( $filters['client_name'] !== '' ) {
            $where[] = 'client_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['client_name'] ) . '%';
        }
        if ( $filters['customer_number'] !== '' ) {
            $where[] = 'customer_number LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['customer_number'] ) . '%';
        }
        if ( $filters['customer_name'] !== '' ) {
            $where[] = 'customer_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['customer_name'] ) . '%';
        }
        if ( $filters['domain'] !== '' ) {
            $where[] = 'domain LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['domain'] ) . '%';
        }
        if ( $filters['expiry_date'] !== '' ) {
            $needle = (string) $filters['expiry_date'];
            // If user provided dd/mm/yyyy, convert to DB format for matching.
            $parsed = $this->normalize_ui_date_to_db( $needle );
            if ( $parsed !== '' ) {
                $needle = $parsed;
            }
            $where[] = 'expiry_date LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $needle ) . '%';
        }
        if ( $filters['days_left'] !== '' ) {
            $where[] = 'days_left LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['days_left'] ) . '%';
        }
        if ( $filters['ownership'] !== '' ) {
            $where[] = 'ownership = %s';
            $params[] = $filters['ownership'];
        }
        if ( $filters['payment'] !== '' ) {
            $where[] = 'payment = %s';
            $params[] = $filters['payment'];
        }
        if ( $filters['registrar'] !== '' ) {
            $where[] = 'registrar LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['registrar'] ) . '%';
        }
        if ( $filters['expiry_from'] !== '' ) {
            $from_db = $this->normalize_ui_date_to_db( (string) $filters['expiry_from'] );
            if ( $from_db !== '' ) {
                $where[] = 'expiry_date >= %s';
                $params[] = $from_db . ' 00:00:00';
            }
        }
        if ( $filters['expiry_to'] !== '' ) {
            $to_db = $this->normalize_ui_date_to_db( (string) $filters['expiry_to'] );
            if ( $to_db !== '' ) {
                $where[] = 'expiry_date <= %s';
                $params[] = $to_db . ' 23:59:59';
            }
        }

        if ( $orderby === 'days_left' ) {
            $order = 'ASC';
        }

        $order_clause = ( $orderby === 'days_left' ) ? 'days_left ASC' : "{$orderby} {$order}, days_left ASC";
        $sql = "SELECT id, client_name, customer_number, customer_name, domain, DATE(expiry_date) AS expiry_date, days_left, registrar, ownership, payment, notes, temp_text, temp_text_enabled, manual_expiry, free_text_domain, archived FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order_clause}";
        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params );
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! empty( $wpdb->last_error ) ) {
            error_log( 'Expman domains query error: ' . $wpdb->last_error );
        }
        if ( empty( $rows ) ) {
            return array();
        }

        foreach ( $rows as &$row ) {
            if ( empty( $row['days_left'] ) && ! empty( $row['expiry_date'] ) ) {
                try {
                    $today = new DateTimeImmutable( 'today' );
                    $expiry = new DateTimeImmutable( $row['expiry_date'] );
                    $row['days_left'] = (string) $today->diff( $expiry )->format( '%r%a' );
                } catch ( Exception $e ) {
                    $row['days_left'] = '';
                }
            }
        }
        unset( $row );

        return $rows;
    }

    private function render_rows_html( array $rows, $mode ) {
        if ( empty( $rows ) ) {
            return '<tr><td colspan="9">אין נתונים.</td></tr>';
        }

        $html = '';
        $row_index = 0;
        foreach ( $rows as $row ) {
            $row_index++;
            $row_id = intval( $row['id'] ?? $row_index );
            $days_left = $row['days_left'] ?? '';
            $days_class = 'expman-days-unknown';
            if ( $days_left !== '' ) {
                $days_value = intval( $days_left );
                if ( $days_value <= 7 ) {
                    $days_class = 'expman-days-red';
                } elseif ( $days_value <= 30 ) {
                    $days_class = 'expman-days-yellow';
                } else {
                    $days_class = 'expman-days-green';
                }
            }

            $row_class = $row_index % 2 === 0 ? 'expman-row-alt' : '';
            $status_attr = str_replace( 'expman-days-', '', $days_class );
            $html .= '<tr class="expman-row ' . esc_attr( $row_class ) . '" data-id="' . esc_attr( $row_id ) . '" data-expman-status="' . esc_attr( $status_attr ) . '">';
            $html .= '<td>' . esc_html( $row['client_name'] ?? '' ) . '</td>';
            $html .= '<td>' . esc_html( $row['customer_number'] ?? '' ) . '</td>';
            $html .= '<td>' . esc_html( $row['customer_name'] ?? '' ) . '</td>';
            $html .= '<td>' . esc_html( $row['domain'] ?? '' ) . '</td>';
            $exp_ui = $this->format_db_date_to_ui( $row['expiry_date'] ?? '' );
            $html .= '<td>' . esc_html( $exp_ui ) . '</td>';
            $html .= '<td class="' . esc_attr( $days_class ) . '">' . esc_html( $days_left ) . '</td>';

$ownership_raw = (string) ( $row['ownership'] ?? '' );
$payment_raw   = (string) ( $row['payment'] ?? '' );

$ownership_label = $ownership_raw;
if ( $ownership_raw === 'ours' || $ownership_raw === '1' ) {
    $ownership_label = 'שלנו';
} elseif ( $ownership_raw === 'not_ours' || $ownership_raw === '0' ) {
    $ownership_label = 'לא שלנו';
}

$payment_label = $payment_raw;
if ( $payment_raw === 'ours' || $payment_raw === '1' ) {
    $payment_label = 'שלנו';
} elseif ( $payment_raw === 'customer' || $payment_raw === '0' ) {
    $payment_label = 'לקוח';
}

$html .= '<td>' . esc_html( $ownership_label ) . '</td>';
$html .= '<td>' . esc_html( $payment_label ) . '</td>';
$html .= '<td style="display:flex;gap:6px;flex-wrap:wrap;">';

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

$html .= '</td>';
            $html .= '</tr>';

            $html .= '<tr class="expman-inline-form expman-details" data-for="' . esc_attr( $row_id ) . '" style="display:none;">';
            $html .= '<td colspan="9">';
            $html .= '<div style="display:flex;gap:24px;flex-wrap:wrap;">';
            $html .= '<div><strong>רשם הדומיין:</strong> ' . esc_html( $row['registrar'] ?? '' ) . '</div>';
            $html .= '<div><strong>הערות:</strong> ' . esc_html( $row['notes'] ?? '' ) . '</div>';
            
$show_temp = ( ! empty( $row['temp_text_enabled'] ) && intval( $row['temp_text_enabled'] ) === 1 ) || ( trim( (string) ( $row['temp_text'] ?? '' ) ) !== '' );
if ( $show_temp ) {
    $html .= '<div><strong>הערות זמניות:</strong> ' . esc_html( $row['temp_text'] ?? '' ) . '</div>';
}
            $html .= '</div>';
            $html .= '</td></tr>';

            $html .= '<tr class="expman-inline-form expman-edit" data-for="' . esc_attr( $row_id ) . '" style="display:none;">';
            $html .= '<td colspan="9">';
            ob_start();
            $this->render_form( $row_id, $row );
            $html .= ob_get_clean();
            $html .= '</td></tr>';
        }

        return $html;
    }

    private function get_summary_counts( $mode ) {
        $this->ensure_schema();
        global $wpdb;
        $table = $this->get_table_name();
        $thresholds = $this->get_thresholds();

        $where = array( 'deleted_at IS NULL' );
        if ( $mode === 'trash' ) {
            $where = array( 'deleted_at IS NOT NULL' );
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );
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

    public function handle_fetch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Access denied.' ) );
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'expman_domains_fetch' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
        }

        $allowed_sort = array(
            'client_name',
            'customer_number',
            'customer_name',
            'domain',
            'expiry_date',
            'days_left',
            'ownership',
            'payment',
        );

        $orderby = sanitize_key( wp_unslash( $_POST['orderby'] ?? 'days_left' ) );
        if ( ! in_array( $orderby, $allowed_sort, true ) ) {
            $orderby = 'days_left';
        }
        $order = strtoupper( sanitize_key( wp_unslash( $_POST['order'] ?? 'ASC' ) ) );
        if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
            $order = 'ASC';
        }

        $filters = array(
            'client_name'     => sanitize_text_field( wp_unslash( $_POST['f_client_name'] ?? '' ) ),
            'customer_number' => sanitize_text_field( wp_unslash( $_POST['f_customer_number'] ?? '' ) ),
            'customer_name'   => sanitize_text_field( wp_unslash( $_POST['f_customer_name'] ?? '' ) ),
            'domain'          => sanitize_text_field( wp_unslash( $_POST['f_domain'] ?? '' ) ),
            'expiry_date'     => sanitize_text_field( wp_unslash( $_POST['f_expiry_date'] ?? '' ) ),
            'days_left'       => sanitize_text_field( wp_unslash( $_POST['f_days_left'] ?? '' ) ),
            'ownership'       => sanitize_text_field( wp_unslash( $_POST['f_ownership'] ?? '' ) ),
            'payment'         => sanitize_text_field( wp_unslash( $_POST['f_payment'] ?? '' ) ),
            'registrar'       => sanitize_text_field( wp_unslash( $_POST['f_registrar'] ?? '' ) ),
            'expiry_from'     => sanitize_text_field( wp_unslash( $_POST['f_expiry_from'] ?? '' ) ),
            'expiry_to'       => sanitize_text_field( wp_unslash( $_POST['f_expiry_to'] ?? '' ) ),
        );

        $mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'main' ) );
        if ( ! in_array( $mode, array( 'main', 'trash', 'map' ), true ) ) {
            $mode = 'main';
        }

        $rows = $this->get_rows( $filters, $orderby, $order, $mode );
        $html = $this->render_rows_html( $rows, $mode );
        wp_send_json_success( array( 'html' => $html ) );
    }
}
}
