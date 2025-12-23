<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'DRM_Manager' ) ) {
class DRM_Manager {

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
        .expman-domains-wrap .section-title,
        .expman-domains-wrap label {color:#fff !important;}
        .expman-domains-wrap table th,
        .expman-domains-wrap table td {padding-top:6px !important;padding-bottom:6px !important;line-height:1.2 !important;vertical-align:middle !important;}
        .expman-domains-wrap tr.expman-details td,
        .expman-domains-wrap tr.expman-edit td {padding-top:10px !important;padding-bottom:10px !important;}
        </style>';

        echo '<div class="expman-domains-wrap expman-frontend expman-domains" style="direction:rtl;">';
        echo '<h2 style="margin-top:10px;">דומיינים</h2>';

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
        echo '<option value="1" ' . selected( $filters['ownership'], '1', false ) . '>שלנו</option>';
        echo '<option value="0" ' . selected( $filters['ownership'], '0', false ) . '>לא שלנו</option>';
        echo '</select></th>';
        echo '<th><select name="f_payment" style="width:100%;">';
        echo '<option value="">הכל</option>';
        echo '<option value="1" ' . selected( $filters['payment'], '1', false ) . '>שלנו</option>';
        echo '<option value="0" ' . selected( $filters['payment'], '0', false ) . '>לא שלנו</option>';
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
                        })
                        .catch(()=>{});
                };

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
            'ownership'       => '',
            'payment'         => '',
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
        echo '<div><label>שם לקוח ישן</label><input type="text" name="client_name" value="' . esc_attr( $data['client_name'] ) . '"></div>';
        echo '<div><label>מספר לקוח חדש</label><input type="text" name="customer_number" value="' . esc_attr( $data['customer_number'] ) . '"></div>';
        echo '<div><label>שם לקוח חדש</label><input type="text" name="customer_name" value="' . esc_attr( $data['customer_name'] ) . '"></div>';
        echo '<div><label>שם הדומיין</label><input type="text" name="domain" value="' . esc_attr( $data['domain'] ) . '"></div>';
        $expiry_ui = $this->format_db_date_to_ui( $data['expiry_date'] );
        echo '<div><label>תאריך תפוגה</label><input type="text" name="expiry_date" value="' . esc_attr( $expiry_ui ) . '" placeholder="dd/mm/yyyy" inputmode="numeric" pattern="\\d{2}\\/\\d{2}\\/\\d{4}"></div>';
        echo '<div><label>ימים לתפוגה</label><input type="number" name="days_left" value="' . esc_attr( $data['days_left'] ) . '"></div>';
        echo '<div><label>רשם הדומיין</label><input type="text" name="registrar" value="' . esc_attr( $data['registrar'] ) . '"></div>';
        echo '<div><label>ניהול</label><select name="ownership">';
        echo '<option value="">בחר</option>';
        echo '<option value="1" ' . selected( $data['ownership'], '1', false ) . '>שלנו</option>';
        echo '<option value="0" ' . selected( $data['ownership'], '0', false ) . '>לא שלנו</option>';
        echo '</select></div>';
        echo '<div><label>תשלום</label><select name="payment">';
        echo '<option value="">בחר</option>';
        echo '<option value="1" ' . selected( $data['payment'], '1', false ) . '>שלנו</option>';
        echo '<option value="0" ' . selected( $data['payment'], '0', false ) . '>לא שלנו</option>';
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
        echo '<th' . $class_attr . '><a href="' . esc_url( $url ) . '" style="text-decoration:none;">' . esc_html( $label ) . '</a></th>';
    }

    private function ensure_customer_columns(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'kb_kb_domain_expiry';
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
        $cols = array_map( 'strtolower', (array) $cols );

        if ( ! in_array( 'customer_number', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `customer_number` VARCHAR(64) NULL AFTER `client_name`" );
        }

        if ( ! in_array( 'customer_name', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `customer_name` VARCHAR(255) NULL AFTER `customer_number`" );
        }
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
        $table = $wpdb->prefix . 'kb_kb_domain_expiry';
        $this->ensure_customer_columns();

        $id = intval( $_POST['domain_id'] ?? 0 );
        $expiry_input = sanitize_text_field( wp_unslash( $_POST['expiry_date'] ?? '' ) );
        $expiry_date = $this->normalize_ui_date_to_db( $expiry_input );
        $days_left = null;
        if ( $expiry_date !== '' ) {
            try {
                $today = new DateTimeImmutable( 'today' );
                $expiry = new DateTimeImmutable( $expiry_date );
                $days_left = (int) $today->diff( $expiry )->format( '%r%a' );
            } catch ( Exception $e ) {
                $days_left = null;
            }
        }

        $data = array(
            'client_name'     => sanitize_text_field( wp_unslash( $_POST['client_name'] ?? '' ) ),
            'customer_number' => sanitize_text_field( wp_unslash( $_POST['customer_number'] ?? '' ) ),
            'customer_name'   => sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) ),
            'domain'          => sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ),
            'expiry_date'     => $expiry_date !== '' ? $expiry_date : null,
            'days_left'       => $days_left,
            'registrar'       => sanitize_text_field( wp_unslash( $_POST['registrar'] ?? '' ) ),
            'ownership'       => sanitize_text_field( wp_unslash( $_POST['ownership'] ?? '' ) ),
            'payment'         => sanitize_text_field( wp_unslash( $_POST['payment'] ?? '' ) ),
            'notes'           => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
            'temp_text'       => sanitize_textarea_field( wp_unslash( $_POST['temp_text'] ?? '' ) ),
        );

        if ( $id > 0 ) {
            $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $wpdb->insert( $table, $data );
        }

        $redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=expman_domains' );
        wp_safe_redirect( $redirect );
        exit;
    }

    private function get_rows( array $filters, $orderby, $order, $mode ) {
        $this->ensure_customer_columns();
        global $wpdb;
        $table = $wpdb->prefix . 'kb_kb_domain_expiry';

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
                $params[] = $from_db;
            }
        }
        if ( $filters['expiry_to'] !== '' ) {
            $to_db = $this->normalize_ui_date_to_db( (string) $filters['expiry_to'] );
            if ( $to_db !== '' ) {
                $where[] = 'expiry_date <= %s';
                $params[] = $to_db;
            }
        }

        if ( $orderby === 'days_left' ) {
            $order = 'ASC';
        }

        $order_clause = ( $orderby === 'days_left' ) ? 'days_left ASC' : "{$orderby} {$order}, days_left ASC";
        $sql = "SELECT id, client_name, customer_number, customer_name, domain, DATE(expiry_date) AS expiry_date, days_left, registrar, ownership, payment, notes, temp_text FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order_clause}";
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
            $html .= '<tr class="expman-row ' . esc_attr( $row_class ) . '" data-id="' . esc_attr( $row_id ) . '">';
            $html .= '<td>' . esc_html( $row['client_name'] ?? '' ) . '</td>';
            $html .= '<td>' . esc_html( $row['customer_number'] ?? '' ) . '</td>';
            $html .= '<td>' . esc_html( $row['customer_name'] ?? '' ) . '</td>';
            $html .= '<td>' . esc_html( $row['domain'] ?? '' ) . '</td>';
            $exp_ui = $this->format_db_date_to_ui( $row['expiry_date'] ?? '' );
            $html .= '<td>' . esc_html( $exp_ui ) . '</td>';
            $html .= '<td class="' . esc_attr( $days_class ) . '">' . esc_html( $days_left ) . '</td>';
            $html .= '<td>' . esc_html( $row['ownership'] ?? '' ) . '</td>';
            $html .= '<td>' . esc_html( $row['payment'] ?? '' ) . '</td>';
            $html .= '<td style="display:flex;gap:6px;flex-wrap:wrap;">';
            $html .= '<button type="button" class="expman-btn secondary expman-toggle-edit" data-id="' . esc_attr( $row_id ) . '">' . ( $mode === 'map' ? 'שייך' : 'עריכה' ) . '</button>';
            $html .= '</td>';
            $html .= '</tr>';

            $html .= '<tr class="expman-inline-form expman-details" data-for="' . esc_attr( $row_id ) . '" style="display:none;">';
            $html .= '<td colspan="9">';
            $html .= '<div style="display:flex;gap:24px;flex-wrap:wrap;">';
            $html .= '<div><strong>רשם הדומיין:</strong> ' . esc_html( $row['registrar'] ?? '' ) . '</div>';
            $html .= '<div><strong>הערות:</strong> ' . esc_html( $row['notes'] ?? '' ) . '</div>';
            $html .= '<div><strong>הערות זמניות:</strong> ' . esc_html( $row['temp_text'] ?? '' ) . '</div>';
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
