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
            'trash' => 'Trash',
            'logs' => 'Logs',
            'settings' => 'Settings',
        );

        echo '<style>
        .expman-top-nav{width:100%;box-sizing:border-box;margin:10px 0;padding:10px;border:1px solid #e3e3e3;border-radius:10px;background:#fff;}
        .expman-top-nav ul{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;}
        .expman-top-nav li{margin:0;}
        .expman-top-nav a.expman-nav-btn{display:flex;justify-content:center;align-items:center;width:100%;padding:10px 10px;border:0;border-radius:20px;text-decoration:none;font-weight:700;background:#cfcfcf;color:#111}
        .expman-top-nav a.expman-nav-btn:hover{filter:brightness(.95)}
        .expman-top-nav a.expman-nav-btn.is-disabled{pointer-events:none;opacity:.55}
        </style>';

        echo '<nav class="expman-top-nav" aria-label="Expiry Manager Navigation"><ul>';
        foreach ( $items as $key => $label ) {
            $url = $urls[ $key ] ?? '';
            $cls = 'expman-nav-btn';
            if ( empty( $url ) ) { $cls .= ' is-disabled'; $url = '#'; }
            echo '<li><a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
        }
        echo '</ul></nav>';
    }
}
