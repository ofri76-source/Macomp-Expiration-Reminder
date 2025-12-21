<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Expman_Domains_Page {

    private DRM_Manager $drm;

    public function __construct() {
        $this->drm = DRM_Manager::instance();
    }

    public function render_page() {
        if ( class_exists( 'Expman_Nav' ) ) {
            Expman_Nav::render_admin_nav();
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'domains';

        $tabs = array(
            'domains'  => 'ניהול דומיינים',
            'bin'      => 'סל מחזור',
            'io'       => 'ייבוא/ייצוא',
            'settings' => 'הגדרות',
        );

        echo '<div class="wrap">';
        echo '<h1 style="margin:10px 0 16px;">דומיינים</h1>';

        echo '<h2 class="nav-tab-wrapper" style="direction:rtl;text-align:right;">';
        foreach ( $tabs as $key => $label ) {
            $url = admin_url( 'admin.php?page=expman_domains&tab=' . $key );
            $cls = 'nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' );
            echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</h2>';

        echo '<div style="margin-top:12px;">';
        switch ( $tab ) {
            case 'bin':
                $this->drm->render_bin();
                break;
            case 'io':
                $this->drm->render_io();
                break;
            case 'settings':
                $this->drm->render_settings();
                break;
            case 'domains':
            default:
                $this->drm->render_admin();
                break;
        }
        echo '</div>';

        echo '</div>';
    }
}
