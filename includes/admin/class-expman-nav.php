<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Expman_Nav {

    private static $rendered = false;

    private static function find_permalink_by_shortcode( $shortcode_tag ) {
        $shortcode = '[' . trim( $shortcode_tag, '[] ' ) . ']';

        // Try WP search first
        $posts = get_posts( array(
            'post_type'      => array( 'page', 'post' ),
            'post_status'    => 'publish',
            'numberposts'    => 1,
            's'              => $shortcode,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'suppress_filters' => false,
        ) );

        if ( ! empty( $posts ) ) {
            return get_permalink( $posts[0]->ID );
        }

        // Fallback: raw LIKE in DB (more reliable for shortcodes)
        global $wpdb;
        $like = '%' . $wpdb->esc_like( $shortcode ) . '%';
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status='publish'
               AND post_type IN ('page','post')
               AND post_content LIKE %s
             ORDER BY ID ASC LIMIT 1",
            $like
        ) );
        if ( $id ) {
            return get_permalink( intval( $id ) );
        }

        return '';
    }

    public static function get_effective_public_urls( $option_key ) {
        $settings = get_option( $option_key, array() );
        $env      = $settings['env'] ?? 'prod';
        $urls     = $settings['public_urls'] ?? array();

        if ( $env === 'test' ) {
            return array(
                'dashboard' => 'https://kbtest.macomp.co.il/?page_id=14004',
                'firewalls' => 'https://kbtest.macomp.co.il/?page_id=14015',
                'certs'     => 'https://kbtest.macomp.co.il/?page_id=14016',
                'domains'   => 'https://kbtest.macomp.co.il/?page_id=14018',
                'servers'   => 'https://kbtest.macomp.co.il/?page_id=14017',
                'trash'     => 'https://kbtest.macomp.co.il/?page_id=14007',
                'logs'      => 'https://kbtest.macomp.co.il/?page_id=14006',
                'settings'  => 'https://kbtest.macomp.co.il/?page_id=14023',
                'customers' => '',
            );
        }

        return array(
            'dashboard' => $urls['dashboard'] ?? '',
            'firewalls' => $urls['firewalls'] ?? '',
            'certs'     => $urls['certs'] ?? '',
            'domains'   => $urls['domains'] ?? '',
            'servers'   => $urls['servers'] ?? '',
            'trash'     => $urls['trash'] ?? '',
            'logs'      => $urls['logs'] ?? '',
            'settings'  => $urls['settings'] ?? '',
            'customers' => $urls['customers'] ?? '',
        );
    }

    public static function render_public_nav( $option_key ) {
        if ( self::$rendered ) { return; }
        self::$rendered = true;

        $urls = self::get_effective_public_urls( $option_key );

        // If URL missing, try to discover by shortcode on-site.
        $fallback = array(
            'dashboard' => 'expman_dashboard',
            'firewalls' => 'expman_firewalls',
            'certs'     => 'expman_certs',
            'domains'   => 'expman_domains',
            'servers'   => 'expman_servers',
            'trash'     => 'expman_trash',
            'logs'      => 'expman_logs',
            'settings'  => 'expman_settings',
            'customers' => 'dc_customers_manager',
        );

        foreach ( $fallback as $k => $sc ) {
            if ( empty( $urls[ $k ] ) ) {
                $p = self::find_permalink_by_shortcode( $sc );
                if ( $p ) { $urls[ $k ] = $p; }
            }
        }

        $items = array(
            'dashboard' => 'Dashboard',
            'firewalls' => 'חומות אש',
            'certs' => 'תעודות אבטחה',
            'domains' => 'דומיינים',
            'servers' => 'שרתים',
            'customers' => 'לקוחות',
            'settings' => 'Settings',
        );

        echo '<style>
        .expman-top-nav{width:100%;box-sizing:border-box;margin:10px 0;padding:12px;border:1px solid #d5deeb;border-radius:12px;background:linear-gradient(180deg,#f7f9fc,#eef3fb);}
        .expman-top-nav ul{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;}
        .expman-top-nav li{margin:0;}
        .expman-top-nav a.expman-nav-btn{display:flex;justify-content:center;align-items:center;width:100%;padding:10px 12px;border:1px solid #9fb3d9;border-radius:20px;text-decoration:none;font-weight:700;background:#2f5ea8;color:#fff;transition:background .15s ease,border-color .15s ease,transform .15s ease}
        .expman-top-nav a.expman-nav-btn:hover{background:#264f8f;border-color:#264f8f;transform:translateY(-1px)}
        .expman-top-nav a.expman-nav-btn.is-active{background:#cfe3ff;color:#1f3b64;border-color:#9fb3d9;}
        .expman-top-nav a.expman-nav-btn.is-disabled{pointer-events:none;opacity:.5;background:#c9d3e6;color:#42536b;border-color:#b9c5d8}
        </style>';

        $current_url = home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '' ) );
        $current_path = untrailingslashit( (string) wp_parse_url( $current_url, PHP_URL_PATH ) );

        echo '<nav class="expman-top-nav" aria-label="Expiry Manager Navigation"><ul>';
        foreach ( $items as $key => $label ) {
            $url = $urls[ $key ] ?? '';
            $cls = 'expman-nav-btn';
            if ( empty( $url ) ) {
                $cls .= ' is-disabled';
                $url = '#';
            } else {
                $url_path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
                if ( $url_path !== '' && $url_path === $current_path ) {
                    $cls .= ' is-active';
                }
            }
            echo '<li><a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
        }
        echo '</ul></nav>';
    }

    public static function render_admin_nav( $version = '' ) {
        if ( self::$rendered ) { return; }
        self::$rendered = true;

        $items = array(
            'expman_dashboard' => 'Dashboard',
            'expman_firewalls' => 'חומות אש',
            'expman_certs'     => 'תעודות אבטחה',
            'expman_domains'   => 'דומיינים',
            'expman_servers'   => 'שרתים',
            'expman_trash'     => 'Trash',
            'expman_logs'      => 'Logs',
            'expman_settings'  => 'Settings',
        );

        echo '<style>
        .expman-admin-nav{margin:10px 0;padding:12px;border:1px solid #d5deeb;border-radius:12px;background:#f7f9fc;}
        .expman-admin-nav ul{list-style:none;margin:0;padding:0;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;}
        .expman-admin-nav a{display:inline-block;padding:6px 10px;border-radius:10px;border:1px solid #9fb3d9;text-decoration:none;font-weight:700;background:#2f5ea8;color:#fff;}
        .expman-admin-nav a:hover{background:#264f8f;border-color:#264f8f;}
        .expman-admin-nav a.is-active{background:#cfe3ff;color:#1f3b64;border-color:#9fb3d9;}
        </style>';

        echo '<nav class="expman-admin-nav" aria-label="Expiry Manager Admin Navigation"><ul>';
        $current_page = sanitize_key( $_GET['page'] ?? '' );
        foreach ( $items as $slug => $label ) {
            $url = admin_url( 'admin.php?page=' . $slug );
            $cls = $slug === $current_page ? 'is-active' : '';
            echo '<li><a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
        }
        echo '</ul></nav>';
    }
}
