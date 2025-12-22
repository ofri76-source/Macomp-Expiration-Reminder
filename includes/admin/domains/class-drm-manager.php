<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'DRM_Manager' ) ) {
class DRM_Manager {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'admin_post_expman_save_domain', array( $this, 'handle_save' ) );
        add_action( 'admin_init', array( $this, 'maybe_upgrade_table' ) );
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
        );

        $rows = $this->get_rows( $filters, $orderby, $order );

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
        $this->render_form();

        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="expman_domains">';
        echo '<input type="hidden" name="tab" value="main">';

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
        echo '<th><input style="width:100%" name="f_expiry_date" value="' . esc_attr( $filters['expiry_date'] ) . '" placeholder="YYYY-MM-DD"></th>';
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

        echo '</thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="9">אין נתונים.</td></tr>';
        } else {
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
                echo '<tr class="expman-row ' . esc_attr( $row_class ) . '" data-id="' . esc_attr( $row_id ) . '">';
                echo '<td>' . esc_html( $row['client_name'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $row['customer_number'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $row['customer_name'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $row['domain'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $row['expiry_date'] ?? '' ) . '</td>';
                echo '<td class="' . esc_attr( $days_class ) . '">' . esc_html( $days_left ) . '</td>';
                echo '<td>' . esc_html( $row['ownership'] ?? '' ) . '</td>';
                echo '<td>' . esc_html( $row['payment'] ?? '' ) . '</td>';
                echo '<td style="display:flex;gap:6px;flex-wrap:wrap;">';
                echo '<button type="button" class="expman-btn secondary expman-toggle-details" data-id="' . esc_attr( $row_id ) . '">פרטים</button>';
                echo '<button type="button" class="expman-btn secondary expman-toggle-edit" data-id="' . esc_attr( $row_id ) . '">עריכה</button>';
                echo '</td>';
                echo '</tr>';

                echo '<tr class="expman-inline-form expman-details" data-for="' . esc_attr( $row_id ) . '" style="display:none;">';
                echo '<td colspan="9">';
                echo '<div style="display:flex;gap:24px;flex-wrap:wrap;">';
                echo '<div><strong>רשם הדומיין:</strong> ' . esc_html( $row['registrar'] ?? '' ) . '</div>';
                echo '<div><strong>הערות:</strong> ' . esc_html( $row['notes'] ?? '' ) . '</div>';
                echo '<div><strong>הערות זמניות:</strong> ' . esc_html( $row['temp_notes'] ?? '' ) . '</div>';
                echo '</div>';
                echo '</td></tr>';

                echo '<tr class="expman-inline-form expman-edit" data-for="' . esc_attr( $row_id ) . '" style="display:none;">';
                echo '<td colspan="9">';
                $this->render_form( $row_id, $row );
                echo '</td></tr>';
            }
        }

        echo '</tbody></table>';
        echo '</form>';

        echo '<script>
        (function(){
            function toggleRows(selector){
                document.querySelectorAll(selector).forEach(function(btn){
                    btn.addEventListener("click", function(e){
                        e.preventDefault();
                        var id = btn.getAttribute("data-id");
                        var row = document.querySelector("tr" + selector.replace(".expman-toggle-", ".expman-") + "[data-for=\'" + id + "\']");
                        if(!row){return;}
                        row.style.display = (row.style.display === "none" || row.style.display === "") ? "table-row" : "none";
                    });
                });
            }
            toggleRows(".expman-toggle-details");
            toggleRows(".expman-toggle-edit");
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
            'temp_notes'      => '',
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
            $data['temp_notes']      = (string) ( $row['temp_notes'] ?? '' );
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
        echo '<div><label>תאריך תפוגה</label><input type="date" name="expiry_date" value="' . esc_attr( $data['expiry_date'] ) . '"></div>';
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
        echo '<div><label>הערות זמניות</label><textarea name="temp_notes" rows="2">' . esc_textarea( $data['temp_notes'] ) . '</textarea></div>';
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

    private function maybe_upgrade_table() {
        if ( empty( $_GET['page'] ) || $_GET['page'] !== 'expman_domains' ) {
            return;
        }
        $this->ensure_customer_columns();
    }

    private function ensure_customer_columns() {
        global $wpdb;
        $table = $wpdb->prefix . 'kb_kb_domain_expiry';
        $db = DB_NAME;

        $has_customer_number = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='customer_number'",
                $db,
                $table
            )
        );

        if ( ! $has_customer_number ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `customer_number` VARCHAR(64) NULL AFTER `client_name`" );
        }

        $has_customer_name = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='customer_name'",
                $db,
                $table
            )
        );

        if ( ! $has_customer_name ) {
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

        $id = intval( $_POST['domain_id'] ?? 0 );
        $expiry_date = sanitize_text_field( $_POST['expiry_date'] ?? '' );
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
            'client_name'     => sanitize_text_field( $_POST['client_name'] ?? '' ),
            'customer_number' => sanitize_text_field( $_POST['customer_number'] ?? '' ),
            'customer_name'   => sanitize_text_field( $_POST['customer_name'] ?? '' ),
            'domain'          => sanitize_text_field( $_POST['domain'] ?? '' ),
            'expiry_date'     => $expiry_date !== '' ? $expiry_date : null,
            'days_left'       => $days_left,
            'registrar'       => sanitize_text_field( $_POST['registrar'] ?? '' ),
            'ownership'       => sanitize_text_field( $_POST['ownership'] ?? '' ),
            'payment'         => sanitize_text_field( $_POST['payment'] ?? '' ),
            'notes'           => sanitize_textarea_field( $_POST['notes'] ?? '' ),
            'temp_notes'      => sanitize_textarea_field( $_POST['temp_notes'] ?? '' ),
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

    private function get_rows( array $filters, $orderby, $order ) {
        global $wpdb;
        $table = $wpdb->prefix . 'kb_kb_domain_expiry';

        $where = array( 'deleted_at IS NULL' );
        $params = array();

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
            $where[] = 'expiry_date LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['expiry_date'] ) . '%';
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

        if ( $orderby === 'days_left' ) {
            $order = 'ASC';
        }

        $sql = "SELECT id, client_name, customer_number, customer_name, domain, expiry_date, days_left, registrar, ownership, payment, notes, temp_notes FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$orderby} {$order}, days_left ASC";
        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params );
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A );
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
}
}
