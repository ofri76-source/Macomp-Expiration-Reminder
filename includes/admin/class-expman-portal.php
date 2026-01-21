<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Public portal wrapper: renders a single navigation bar and switches sections via query parameter.
 * Keeps everything under one shortcode/page.
 */
class Expman_Portal {

    public static function sections(): array {
        return array(
            'dashboard' => 'Dashboard',
            'firewalls' => 'חומות אש',
            'certs'     => 'תעודות אבטחה',
            'domains'   => 'דומיינים',
            'servers'   => 'שרתים',
            'customers' => 'לקוחות',
            'settings'  => 'Settings',
        );
    }

    private static function get_base_url(): string {
        $id = get_queried_object_id();
        if ( $id ) {
            return (string) get_permalink( $id );
        }
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = $_SERVER['HTTP_HOST'] ?? '';
        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        // Strip query string
        $uri = explode( '?', $uri, 2 )[0];
        return $scheme . $host . $uri;
    }

    public static function shortcode(): string {
        // Signal other modules to skip their own top navigation when embedded in the portal.
        if ( ! defined( 'EXPMAN_PORTAL_ACTIVE' ) ) {
            define( 'EXPMAN_PORTAL_ACTIVE', true );
        }

        $sections = self::sections();
        $section  = isset( $_GET['expman_section'] ) ? sanitize_key( $_GET['expman_section'] ) : 'dashboard';
        if ( ! isset( $sections[ $section ] ) ) {
            $section = 'dashboard';
        }

        $base = self::get_base_url();

        ob_start();

        echo '<style>';
        echo '.expman-portal{margin:10px 0;direction:rtl;}';
        echo '.expman-portal-nav{width:100%;box-sizing:border-box;margin:10px 0;padding:12px;border:1px solid #d5deeb;border-radius:12px;background:linear-gradient(180deg,#f7f9fc,#eef3fb);}';
        echo '.expman-portal-nav ul{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;}';
        echo '.expman-portal-nav li{margin:0;}';
        echo '.expman-portal-nav a.expman-nav-btn{display:flex;justify-content:center;align-items:center;width:100%;padding:10px 12px;border:1px solid #9fb3d9;border-radius:20px;text-decoration:none;font-weight:700;background:#2f5ea8;color:#fff;transition:background .15s ease,border-color .15s ease,transform .15s ease}';
        echo '.expman-portal-nav a.expman-nav-btn:hover{background:#264f8f;border-color:#264f8f;transform:translateY(-1px)}';
        echo '.expman-portal-nav a.expman-nav-btn.is-active{background:#cfe3ff;color:#1f3b64;border-color:#9fb3d9;}';
        echo '</style>';

        echo '<div class="expman-portal">';
        echo '<div class="expman-portal-nav"><ul>';
        foreach ( $sections as $key => $label ) {
            if ( $key === 'customers' && ! shortcode_exists( 'dc_customers_manager' ) ) {
                continue;
            }
            $url = add_query_arg( array( 'expman_section' => $key ), $base );
            $cls = 'expman-nav-btn' . ( $key === $section ? ' is-active' : '' );
            echo '<li><a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
        }
        echo '</ul></div>';

        echo '<div class="expman-portal-body">';
        echo self::render_section( $section );
        echo '</div>';
        echo '</div>';

        return (string) ob_get_clean();
    }

    private static function render_section( string $section ): string {
        switch ( $section ) {
            case 'firewalls':
                return do_shortcode( '[expman_firewalls]' );
            case 'certs':
                return do_shortcode( '[expman_certs]' );
            case 'domains':
                return do_shortcode( '[expman_domains]' );
            case 'servers':
                return do_shortcode( '[expman_servers]' );
            case 'customers':
                return shortcode_exists( 'dc_customers_manager' ) ? do_shortcode( '[dc_customers_manager]' ) : '';
            case 'settings':
                return do_shortcode( '[expman_settings]' );
            case 'dashboard':
            default:
                return do_shortcode( '[expman_dashboard]' );
        }
    }
}
