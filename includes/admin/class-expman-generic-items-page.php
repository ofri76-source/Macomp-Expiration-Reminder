<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Expman_Generic_Items_Page {

    private $option_key;
    private $type;
    private $title;

    public function __construct( $option_key, $type, $title ) {
        $this->option_key = $option_key;
        $this->type       = $type;
        $this->title      = $title;
    }

    public function render_page() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
        $this->handle_actions( $action );

        echo '<div class="wrap">';
        if ( class_exists('Expman_Nav') ) { Expman_Nav::render_admin_nav( '0.2.0' ); }
        echo '<h1>' . esc_html( $this->title ) . '</h1>';
        $this->render_summary_cards();
        echo '<h2>רשימה</h2>';
        $this->render_items_table();
        echo '<hr><h2>הוספה / עריכה</h2>';
        $this->render_item_form();
        echo '</div>';
    }

    private function get_thresholds() {
        $settings = get_option( $this->option_key, array() );
        return array(
            'yellow' => intval( $settings['yellow_threshold'] ?? 90 ),
            'red'    => intval( $settings['red_threshold'] ?? 30 ),
        );
    }

    private function render_summary_cards() {
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

        $summary = $this->get_summary_counts();
        $yellow_label = 'תוקף בין ' . ( $summary['red_threshold'] + 1 ) . ' ל-' . $summary['yellow_threshold'] . ' יום';
        echo '<div class="expman-summary">';
        echo '<div class="expman-summary-card green" data-expman-status="green"><button type="button"><h4>תוקף מעל ' . esc_html( $summary['yellow_threshold'] ) . ' יום</h4><div class="count">' . esc_html( $summary['green'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card yellow" data-expman-status="yellow"><button type="button"><h4>' . esc_html( $yellow_label ) . '</h4><div class="count">' . esc_html( $summary['yellow'] ) . '</div></button></div>';
        echo '<div class="expman-summary-card red" data-expman-status="red"><button type="button"><h4>דורש טיפול מייד</h4><div class="count">' . esc_html( $summary['red'] ) . '</div></button></div>';
        echo '</div>';
        echo '<div class="expman-summary-meta" data-expman-status="all"><button type="button">סה״כ רשומות פעילות: ' . esc_html( $summary['total'] ) . '</button></div>';
    }

    private function get_summary_counts() {
        global $wpdb;
        $table = $wpdb->prefix . 'exp_items';
        $thresholds = $this->get_thresholds();

        $counts = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) > %d THEN 1 ELSE 0 END) AS green_count,
                    SUM(CASE WHEN expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) BETWEEN %d AND %d THEN 1 ELSE 0 END) AS yellow_count,
                    SUM(CASE WHEN expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) <= %d THEN 1 ELSE 0 END) AS red_count,
                    COUNT(*) AS total_count
                 FROM {$table}
                 WHERE type = %s AND deleted_at IS NULL",
                $thresholds['yellow'],
                $thresholds['red'] + 1,
                $thresholds['yellow'],
                $thresholds['red'],
                $this->type
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

    private function handle_actions( $action ) {
        if ( $action === 'delete' && isset( $_GET['id'] ) && check_admin_referer( 'expman_delete_' . intval( $_GET['id'] ) ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'exp_items';
            $wpdb->update( $table, array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => intval( $_GET['id'] ) ) );
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>הועבר ל־Trash.</p></div>';
            });
        }

        if ( isset( $_POST['expman_save_item'] ) && check_admin_referer( 'expman_save_item_' . $this->type ) ) {
            $this->save_item_from_post();
        }
    }

    private function render_items_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'exp_items';
        $thresholds = $this->get_thresholds();

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE type = %s AND deleted_at IS NULL ORDER BY expiry_date ASC",
                $this->type
            )
        );

        echo '<table class="widefat striped" id="expman-items-table"><thead><tr>';
        echo '<th>ID</th><th>Customer ID</th><th>Name</th><th>Identifier</th><th>Expiry</th><th>IP</th><th>Notes</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        if ( empty( $items ) ) {
            echo '<tr><td colspan="8">No items found.</td></tr></tbody></table>';
            return;
        }

        foreach ( $items as $item ) {
            $edit_url = add_query_arg(
                array( 'page' => 'expman_' . $this->type, 'action' => 'edit', 'id' => $item->id ),
                admin_url( 'admin.php' )
            );

            $delete_url = wp_nonce_url(
                add_query_arg(
                    array( 'page' => 'expman_' . $this->type, 'action' => 'delete', 'id' => $item->id ),
                    admin_url( 'admin.php' )
                ),
                'expman_delete_' . $item->id
            );

            $days = null;
            if ( ! empty( $item->expiry_date ) ) {
                $days = (int) ( ( strtotime( (string) $item->expiry_date ) - strtotime( gmdate( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
            }
            $status = 'unknown';
            if ( $days !== null ) {
                if ( $days <= $thresholds['red'] ) {
                    $status = 'red';
                } elseif ( $days <= $thresholds['yellow'] ) {
                    $status = 'yellow';
                } else {
                    $status = 'green';
                }
            }

            echo '<tr data-expman-status="' . esc_attr( $status ) . '">';
            echo '<td>' . esc_html( $item->id ) . '</td>';
            echo '<td>' . esc_html( $item->customer_id ) . '</td>';
            echo '<td>' . esc_html( $item->name ) . '</td>';
            echo '<td>' . esc_html( $item->identifier ) . '</td>';
            $exp_disp = '';
            if ( ! empty( $item->expiry_date ) ) {
                $ts = strtotime( (string) $item->expiry_date );
                $exp_disp = $ts ? date_i18n( 'd/m/Y', $ts ) : (string) $item->expiry_date;
            }
            echo '<td>' . esc_html( $exp_disp ) . '</td>';
            echo '<td>' . esc_html( $item->ip_address ) . '</td>';
            echo '<td>' . esc_html( mb_substr( (string) $item->notes, 0, 50 ) ) . '</td>';
            echo '<td><a href="' . esc_url( $edit_url ) . '">Edit</a> | <a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'Move to trash?\');">Trash</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<script>
        (function(){
            function setActiveSummary(status){
                document.querySelectorAll(".expman-summary-card, .expman-summary-meta").forEach(function(card){
                    card.setAttribute("data-active", card.getAttribute("data-expman-status") === status ? "1" : "0");
                });
            }
            function applyStatusFilter(status){
                document.querySelectorAll("#expman-items-table tbody tr").forEach(function(row){
                    var rowStatus = row.getAttribute("data-expman-status") || "";
                    row.style.display = (status === "all" || rowStatus === status) ? "" : "none";
                });
            }
            document.querySelectorAll(".expman-summary-card, .expman-summary-meta").forEach(function(card){
                card.addEventListener("click", function(){
                    var status = card.getAttribute("data-expman-status") || "all";
                    setActiveSummary(status);
                    applyStatusFilter(status);
                });
            });
            setActiveSummary("all");
        })();
        </script>';
    }

    private function render_item_form() {
        global $wpdb;
        $table = $wpdb->prefix . 'exp_items';

        $id      = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
        $editing = ( isset($_GET['action']) && $_GET['action'] === 'edit' && $id > 0 );

        $item = array(
            'customer_id' => '',
            'name'        => '',
            'identifier'  => '',
            'expiry_date' => '',
            'ip_address'  => '',
            'notes'       => '',
        );

        if ( $editing ) {
            $db_item = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND type = %s", $id, $this->type )
            );
            if ( $db_item ) { $item = (array) $db_item; }
        }

        echo '<form method="post">';
        wp_nonce_field( 'expman_save_item_' . $this->type );

        echo '<table class="form-table">';
        echo '<tr><th><label for="customer_id">Customer ID</label></th><td><input type="number" name="customer_id" id="customer_id" value="' . esc_attr( $item['customer_id'] ) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="name">Name</label></th><td><input type="text" name="name" id="name" value="' . esc_attr( $item['name'] ) . '" class="regular-text" required></td></tr>';
        echo '<tr><th><label for="identifier">Identifier</label></th><td><input type="text" name="identifier" id="identifier" value="' . esc_attr( $item['identifier'] ) . '" class="regular-text"></td></tr>';
        $expiry_ui = '';
        if ( ! empty( $item['expiry_date'] ) ) {
            $dt = DateTime::createFromFormat( 'Y-m-d', (string) $item['expiry_date'] );
            if ( $dt instanceof DateTime ) {
                $expiry_ui = $dt->format( 'd/m/Y' );
            } else {
                $ts = strtotime( (string) $item['expiry_date'] );
                $expiry_ui = $ts ? date_i18n( 'd/m/Y', $ts ) : (string) $item['expiry_date'];
            }
        }
        echo '<tr><th><label for="expiry_date">Expiry Date</label></th><td><input type="text" name="expiry_date" id="expiry_date" value="' . esc_attr( $expiry_ui ) . '" placeholder="dd/mm/yyyy" inputmode="numeric" pattern="\\d{2}\\/\\d{2}\\/\\d{4}"></td></tr>';
        echo '<tr><th><label for="ip_address">IP Address</label></th><td><input type="text" name="ip_address" id="ip_address" value="' . esc_attr( $item['ip_address'] ) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="notes">Notes</label></th><td><textarea name="notes" id="notes" rows="4" class="large-text">' . esc_textarea( $item['notes'] ) . '</textarea></td></tr>';
        echo '</table>';

        echo '<p><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
        echo '<button type="submit" name="expman_save_item" class="button button-primary">' . ( $editing ? 'Update' : 'Add' ) . '</button></p>';

        echo '</form>';
    }

    private function save_item_from_post() {
        global $wpdb;
        $table = $wpdb->prefix . 'exp_items';

        $id = intval( $_POST['id'] ?? 0 );
        $normalize_date = function( $value ) {
            $value = is_string( $value ) ? trim( $value ) : '';
            if ( $value === '' ) { return null; }
            $formats = array( 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'Y-m-d H:i:s' );
            foreach ( $formats as $fmt ) {
                $dt = DateTime::createFromFormat( $fmt, $value );
                if ( $dt instanceof DateTime ) {
                    return $dt->format( 'Y-m-d' );
                }
            }
            return null;
        };

        $data = array(
            'type'        => $this->type,
            'customer_id' => isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : null,
            'name'        => sanitize_text_field( $_POST['name'] ?? '' ),
            'identifier'  => sanitize_text_field( $_POST['identifier'] ?? '' ),
            'expiry_date' => $normalize_date( sanitize_text_field( $_POST['expiry_date'] ?? '' ) ),
            'ip_address'  => sanitize_text_field( $_POST['ip_address'] ?? '' ),
            'notes'       => wp_kses_post( $_POST['notes'] ?? '' ),
            'updated_at'  => current_time( 'mysql' ),
        );

        if ( $id > 0 ) {
            $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>נשמר בהצלחה.</p></div>';
        });
    }
}
