<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'DRM_Manager' ) ) {
class DRM_Manager {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
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

    public function render_admin() {
        $allowed_sort = array(
            'customer_number',
            'customer_name',
            'domain_name',
            'expiry_date',
            'days_to_expiry',
            'is_managed',
            'is_paid',
        );

        $orderby = sanitize_key( $_GET['orderby'] ?? 'customer_number' );
        if ( ! in_array( $orderby, $allowed_sort, true ) ) {
            $orderby = 'customer_number';
        }

        $order = strtoupper( sanitize_key( $_GET['order'] ?? 'ASC' ) );
        if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
            $order = 'ASC';
        }

        $filters = array(
            'customer_number' => sanitize_text_field( $_GET['f_customer_number'] ?? '' ),
            'customer_name'   => sanitize_text_field( $_GET['f_customer_name'] ?? '' ),
            'domain_name'     => sanitize_text_field( $_GET['f_domain_name'] ?? '' ),
            'expiry_date'     => sanitize_text_field( $_GET['f_expiry_date'] ?? '' ),
            'days_to_expiry'  => sanitize_text_field( $_GET['f_days_to_expiry'] ?? '' ),
            'is_managed'      => sanitize_text_field( $_GET['f_is_managed'] ?? '' ),
            'is_paid'         => sanitize_text_field( $_GET['f_is_paid'] ?? '' ),
        );

        $rows = array();

        $base = remove_query_arg( array( 'orderby', 'order' ) );
        $clear_url = remove_query_arg(
            array(
                'f_customer_number',
                'f_customer_name',
                'f_domain_name',
                'f_expiry_date',
                'f_days_to_expiry',
                'f_is_managed',
                'f_is_paid',
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
        </style>';

        echo '<div class="expman-frontend expman-domains" style="direction:rtl;">';
        echo '<h2 style="margin-top:10px;">דומיינים</h2>';

        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="expman_domains">';
        echo '<input type="hidden" name="tab" value="main">';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';

        $this->th_sort( 'customer_number', 'מספר לקוח', $orderby, $order, $base );
        $this->th_sort( 'customer_name', 'שם לקוח', $orderby, $order, $base );
        $this->th_sort( 'domain_name', 'שם הדומיין', $orderby, $order, $base );
        $this->th_sort( 'expiry_date', 'תאריך תפוגה', $orderby, $order, $base );
        $this->th_sort( 'days_to_expiry', 'ימים לתפוגה', $orderby, $order, $base );
        $this->th_sort( 'is_managed', 'ניהול', $orderby, $order, $base );
        $this->th_sort( 'is_paid', 'תשלום', $orderby, $order, $base );
        echo '<th>פעולות</th>';
        echo '</tr>';

        echo '<tr class="expman-filter-row">';
        echo '<th><input style="width:100%" name="f_customer_number" value="' . esc_attr( $filters['customer_number'] ) . '" placeholder="סינון..."></th>';
        echo '<th><input style="width:100%" name="f_customer_name" value="' . esc_attr( $filters['customer_name'] ) . '" placeholder="סינון..."></th>';
        echo '<th><input style="width:100%" name="f_domain_name" value="' . esc_attr( $filters['domain_name'] ) . '" placeholder="סינון..."></th>';
        echo '<th><input style="width:100%" name="f_expiry_date" value="' . esc_attr( $filters['expiry_date'] ) . '" placeholder="YYYY-MM-DD"></th>';
        echo '<th><input style="width:100%" name="f_days_to_expiry" value="' . esc_attr( $filters['days_to_expiry'] ) . '" placeholder="ימים..."></th>';
        echo '<th><select name="f_is_managed" style="width:100%;">';
        echo '<option value="">הכל</option>';
        echo '<option value="1" ' . selected( $filters['is_managed'], '1', false ) . '>שלנו</option>';
        echo '<option value="0" ' . selected( $filters['is_managed'], '0', false ) . '>לא שלנו</option>';
        echo '</select></th>';
        echo '<th><select name="f_is_paid" style="width:100%;">';
        echo '<option value="">הכל</option>';
        echo '<option value="1" ' . selected( $filters['is_paid'], '1', false ) . '>שלנו</option>';
        echo '<option value="0" ' . selected( $filters['is_paid'], '0', false ) . '>לא שלנו</option>';
        echo '</select></th>';
        echo '<th style="white-space:nowrap;">';
        if ( $clear_url ) {
            echo '<a class="expman-btn secondary" style="display:inline-block;text-decoration:none;" href="' . esc_url( $clear_url ) . '">נקה</a>';
        }
        echo '</th>';
        echo '</tr>';

        echo '</thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="8">אין נתונים.</td></tr>';
        } else {
            $row_index = 0;
            foreach ( $rows as $row ) {
                $row_index++;
                $row_id = intval( $row['id'] ?? $row_index );
                $days_to_expiry = $row['days_to_expiry'] ?? '';
                $days_class = 'expman-days-unknown';
                if ( $days_to_expiry !== '' ) {
                    $days_value = intval( $days_to_expiry );
                    if ( $days_value <= 7 ) {
                        $days_class = 'expman-days-red';
                    } elseif ( $days_value <= 30 ) {
                        $days_class = 'expman-days-yellow';
                    } else {
                        $days_class = 'expman-days-green';
                    }
                }

                $row_class = $row_index % 2 === 0 ? 'expman-row-alt' : '';
                echo '<tr class="expman-row ' . esc_attr( $row_class ) . '" data-id="' . esc_attr( $row_id ) . '">';
                echo '<td>' . esc_html( $row['customer_number'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $row['customer_name'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $row['domain_name'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $row['expiry_date'] ?? '' ) . '</td>';
                echo '<td class="' . esc_attr( $days_class ) . '">' . esc_html( $days_to_expiry ) . '</td>';
                echo '<td>' . esc_html( $row['is_managed'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $row['is_paid'] ?? '' ) . '</td>';
                echo '<td><button type="button" class="expman-btn secondary expman-toggle-details" data-id="' . esc_attr( $row_id ) . '">פרטים</button></td>';
                echo '</tr>';

                echo '<tr class="expman-inline-form expman-details" data-for="' . esc_attr( $row_id ) . '" style="display:none;">';
                echo '<td colspan="8">';
                echo '<div style="display:flex;gap:24px;flex-wrap:wrap;">';
                echo '<div><strong>רשם הדומיין:</strong> ' . esc_html( $row['registrar'] ?? '' ) . '</div>';
                echo '<div><strong>הערות:</strong> ' . esc_html( $row['notes'] ?? '' ) . '</div>';
                echo '<div><strong>הערות זמניות:</strong> ' . esc_html( $row['temp_notes'] ?? '' ) . '</div>';
                echo '</div>';
                echo '</td></tr>';
            }
        }

        echo '</tbody></table>';
        echo '</form>';

        echo '<script>
        (function(){
            document.querySelectorAll(".expman-toggle-details").forEach(function(btn){
                btn.addEventListener("click", function(e){
                    e.preventDefault();
                    var id = btn.getAttribute("data-id");
                    var row = document.querySelector("tr.expman-details[data-for=\'" + id + "\']");
                    if(!row){return;}
                    row.style.display = (row.style.display === "none" || row.style.display === "") ? "table-row" : "none";
                });
            });
        })();
        </script>';

        echo '</div>';
    }

    public function render_bin() {
        echo '<div class="notice notice-info"><p>סל מחזור לדומיינים יופיע כאן.</p></div>';
    }

    public function render_io() {
        echo '<div class="notice notice-warning"><p>DRM Manager import/export is not configured.</p></div>';
    }

    public function render_settings() {
        echo '<div class="notice notice-info"><p>מסך ההגדרות לדומיינים יהיה כאן.</p></div>';
    }

    public function render_assign() {
        echo '<div class="notice notice-info"><p>טאב שיוך לקוח לדומיינים יופיע כאן.</p></div>';
    }

    private function th_sort( $key, $label, $orderby, $order, $base, $class = '' ) {
        $next_order = ( $orderby === $key && $order === 'ASC' ) ? 'DESC' : 'ASC';
        $url = add_query_arg( array( 'orderby' => $key, 'order' => $next_order ), $base );
        $class_attr = $class !== '' ? ' class="' . esc_attr( $class ) . '"' : '';
        echo '<th' . $class_attr . '><a href="' . esc_url( $url ) . '" style="text-decoration:none;">' . esc_html( $label ) . '</a></th>';
    }
}
}
