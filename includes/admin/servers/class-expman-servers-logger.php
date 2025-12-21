<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Servers_Logger' ) ) {
class Expman_Servers_Logger {

    public function log_server_event( $server_id, $action, $message = '', $context = array(), $level = 'info' ) {
        global $wpdb;
        $user = wp_get_current_user();
        if ( $user && $user->exists() ) {
            if ( empty( $context['user'] ) || ! is_array( $context['user'] ) ) {
                $context['user'] = array(
                    'id'    => $user->ID,
                    'login' => $user->user_login,
                    'name'  => $user->display_name,
                );
            }
        }
        $logs_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVER_LOGS;
        $wpdb->insert(
            $logs_table,
            array(
                'server_id'  => $server_id ? intval( $server_id ) : null,
                'action'     => sanitize_text_field( $action ),
                'level'      => sanitize_text_field( $level ),
                'message'    => sanitize_text_field( $message ),
                'context'    => ! empty( $context ) ? wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) : null,
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    public function format_log_context( $context_json ) {
        if ( empty( $context_json ) ) {
            return '';
        }

        $context = json_decode( (string) $context_json, true );
        if ( ! is_array( $context ) ) {
            return '<pre style="white-space:pre-wrap;margin:0;">' . esc_html( (string) $context_json ) . '</pre>';
        }

        $html = '';
        if ( ! empty( $context['user'] ) && is_array( $context['user'] ) ) {
            $user = $context['user'];
            $name = (string) ( $user['name'] ?? '' );
            $login = (string) ( $user['login'] ?? '' );
            $label = $name !== '' ? $name : $login;
            if ( $label !== '' ) {
                $html .= '<div><strong>משתמש:</strong> ' . esc_html( $label );
                if ( $login !== '' && $login !== $label ) {
                    $html .= ' (' . esc_html( $login ) . ')';
                }
                $html .= '</div>';
            }
        }

        if ( ! empty( $context['changes'] ) && is_array( $context['changes'] ) ) {
            $items = array();
            foreach ( $context['changes'] as $change ) {
                if ( empty( $change['field'] ) ) {
                    continue;
                }
                $items[] = '<li><strong>' . esc_html( (string) $change['field'] ) . '</strong>: '
                    . esc_html( (string) ( $change['from'] ?? '' ) ) . ' → '
                    . esc_html( (string) ( $change['to'] ?? '' ) ) . '</li>';
            }
            if ( ! empty( $items ) ) {
                $html .= '<div><strong>שינויים:</strong><ul style="margin:4px 0 0 18px;">' . implode( '', $items ) . '</ul></div>';
            }
        }

        $extra = $context;
        unset( $extra['user'], $extra['changes'] );
        if ( ! empty( $extra ) ) {
            $html .= '<pre style="white-space:pre-wrap;margin:6px 0 0;">' . esc_html( wp_json_encode( $extra, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre>';
        }

        return $html;
    }

    public function new_request_id() {
        return 'sync_' . gmdate( 'Ymd_His' ) . '_' . substr( wp_hash( microtime( true ) . rand() ), 0, 10 );
    }

    public function http_debug_context( $url, $response ) {
        if ( is_wp_error( $response ) ) {
            return array(
                'url'   => $url,
                'error' => $response->get_error_message(),
                'data'  => $response->get_error_data(),
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        return array(
            'url'          => $url,
            'http_code'    => $code,
            'body_preview' => mb_substr( (string) $body, 0, 2000 ),
        );
    }
}
}
