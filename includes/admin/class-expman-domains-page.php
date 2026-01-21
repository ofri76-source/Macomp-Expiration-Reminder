<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Expman_Domains_Page {

    private Expman_Domains_Manager $drm;

    public function __construct() {
        $this->drm = new Expman_Domains_Manager();
    }

    public function render_page() {
        if ( class_exists( 'Expman_Nav' ) ) {
            Expman_Nav::render_admin_nav();
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'main';

        $tabs = array(
            'main'     => 'טבלה ראשית',
            'trash'    => 'סל מחזור',
            'settings' => 'הגדרות',
            'map'      => 'שיוך לקוח',
        );

        echo '<div class="wrap">';
        echo '<h1 style="margin:10px 0 16px;">דומיינים</h1>';

        echo '<h2 class="nav-tab-wrapper" style="direction:rtl;text-align:right;">';
        foreach ( $tabs as $key => $label ) {
            $cls = 'nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' );
            echo '<a class="' . esc_attr( $cls ) . '" href="#" data-expman-tab="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</h2>';

        echo '<div style="margin-top:12px;">';
        echo '<div data-expman-panel="main"' . ( $tab === 'main' ? '' : ' style="display:none;"' ) . '>';
        $this->drm->render_admin();
        echo '</div>';

        echo '<div data-expman-panel="trash"' . ( $tab === 'trash' ? '' : ' style="display:none;"' ) . '>';
        $this->drm->render_trash();
        echo '</div>';

        echo '<div data-expman-panel="settings"' . ( $tab === 'settings' ? '' : ' style="display:none;"' ) . '>';
        $this->drm->render_settings();
        echo '</div>';

        echo '<div data-expman-panel="map"' . ( $tab === 'map' ? '' : ' style="display:none;"' ) . '>';
        $this->drm->render_map();
        echo '</div>';
        echo '</div>';

        echo '<script>(function(){\n';
        echo 'const tabs=document.querySelectorAll(".nav-tab-wrapper [data-expman-tab]");\n';
        echo 'function show(tab){\n';
        echo 'document.querySelectorAll("[data-expman-panel]").forEach(function(panel){panel.style.display=(panel.getAttribute("data-expman-panel")===tab)?"block":"none";});\n';
        echo 'tabs.forEach(function(btn){btn.classList.toggle("nav-tab-active", btn.getAttribute("data-expman-tab")===tab);});\n';
        echo 'var url=new URL(window.location.href);url.searchParams.set("tab",tab);history.replaceState(null,"",url.toString());\n';
        echo '}\n';
        echo 'tabs.forEach(function(btn){btn.addEventListener("click",function(e){e.preventDefault();show(btn.getAttribute("data-expman-tab"));});});\n';
        echo '})();</script>';

        echo '</div>';
    }
}
