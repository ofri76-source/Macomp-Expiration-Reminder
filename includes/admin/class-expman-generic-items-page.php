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
        echo '<h2>רשימה</h2>';
        $this->render_items_table();
        echo '<hr><h2>הוספה / עריכה</h2>';
        $this->render_item_form();
        echo '</div>';
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

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE type = %s AND deleted_at IS NULL ORDER BY expiry_date ASC",
                $this->type
            )
        );

        echo '<table class="widefat striped"><thead><tr>';
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

            echo '<tr>';
            echo '<td>' . esc_html( $item->id ) . '</td>';
            echo '<td>' . esc_html( $item->customer_id ) . '</td>';
            echo '<td>' . esc_html( $item->name ) . '</td>';
            echo '<td>' . esc_html( $item->identifier ) . '</td>';
            echo '<td>' . esc_html( $item->expiry_date ) . '</td>';
            echo '<td>' . esc_html( $item->ip_address ) . '</td>';
            echo '<td>' . esc_html( mb_substr( (string) $item->notes, 0, 50 ) ) . '</td>';
            echo '<td><a href="' . esc_url( $edit_url ) . '">Edit</a> | <a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'Move to trash?\');">Trash</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
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
        echo '<tr><th><label for="expiry_date">Expiry Date</label></th><td><input type="date" name="expiry_date" id="expiry_date" value="' . esc_attr( $item['expiry_date'] ) . '"></td></tr>';
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
        $data = array(
            'type'        => $this->type,
            'customer_id' => isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : null,
            'name'        => sanitize_text_field( $_POST['name'] ?? '' ),
            'identifier'  => sanitize_text_field( $_POST['identifier'] ?? '' ),
            'expiry_date' => sanitize_text_field( $_POST['expiry_date'] ?? '' ),
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