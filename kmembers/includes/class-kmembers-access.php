<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class KMembers_Access {
    const META_ROLES = '_kmembers_roles';
    const META_GUEST_REDIRECT = '_kmembers_guest_redirect';
    const META_ROLE_REDIRECT = '_kmembers_role_redirect';
    const META_HIDE_GUEST = '_kmembers_hide_guest';
    const META_HIDE_ROLE = '_kmembers_hide_role';
    const META_FLOW_IDENTIFIER = '_kmembers_flow_identifier';
    const META_DEFER_ONBOARDING = '_kmembers_defer_onboarding';

    private $settings;

    public function __construct( $settings ) {
        $this->settings = $settings;
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_meta' ) );
        add_action( 'template_redirect', array( $this, 'protect_singular' ), 1 );
        add_filter( 'wp_nav_menu_objects', array( $this, 'filter_menu_items' ), 10, 2 );
    }

    public function add_meta_boxes() {
        foreach ( array( 'post', 'page' ) as $type ) {
            add_meta_box( 'kmembers-access', 'MemberSystem (KornSW) – Zugriff', array( $this, 'render_meta_box' ), $type, 'side', 'default' );
        }
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'kmembers_save_access', 'kmembers_access_nonce' );
        $selected = (array) get_post_meta( $post->ID, self::META_ROLES, true );
        $guest_redirect = absint( get_post_meta( $post->ID, self::META_GUEST_REDIRECT, true ) );
        $role_redirect = absint( get_post_meta( $post->ID, self::META_ROLE_REDIRECT, true ) );
        $hide_guest = (bool) get_post_meta( $post->ID, self::META_HIDE_GUEST, true );
        $hide_role = (bool) get_post_meta( $post->ID, self::META_HIDE_ROLE, true );
        $flow_identifier = (string) get_post_meta( $post->ID, self::META_FLOW_IDENTIFIER, true );
        $defer_onboarding = (bool) get_post_meta( $post->ID, self::META_DEFER_ONBOARDING, true );
        global $wp_roles;
        echo '<p><strong>Zulässige Rollen</strong></p><div style="max-height:180px;overflow:auto;border:1px solid #ddd;padding:7px">';
        foreach ( $wp_roles->roles as $slug => $data ) {
            printf('<label style="display:block"><input type="checkbox" name="kmembers_roles[]" value="%s" %s> %s</label>', esc_attr($slug), checked(in_array($slug,$selected,true),true,false), esc_html(translate_user_role($data['name'])) );
        }
        echo '</div><p class="description">Keine Auswahl = öffentlich. Mehrere Rollen werden als ODER-Verknüpfung ausgewertet.</p>';
        echo '<p><strong>Wenn nicht eingeloggt</strong></p>';
        wp_dropdown_pages(array('name'=>'kmembers_guest_redirect','selected'=>$guest_redirect,'show_option_none'=>'— Plugin-Login —'));
        printf('<p><label><input type="checkbox" name="kmembers_hide_guest" value="1" %s> In Menüs ausblenden</label></p>', checked($hide_guest,true,false));
        echo '<p><strong>Wenn keine passende Rolle</strong></p>';
        wp_dropdown_pages(array('name'=>'kmembers_role_redirect','selected'=>$role_redirect,'show_option_none'=>'— Kein-Zugriff-Seite —'));
        printf('<p><label><input type="checkbox" name="kmembers_hide_role" value="1" %s> In Menüs ausblenden</label></p>', checked($hide_role,true,false));
        echo '<p><strong>Flow-Identifier</strong></p>';
        echo '<input type="text" name="kmembers_flow_identifier" value="' . esc_attr( $flow_identifier ) . '" style="width:100%" placeholder="z. B. Whitepaper Download">';
        echo '<p class="description">Optionaler fachlicher Identifier für externe Login-Mail-Handler. Beeinflusst den Zugriffsschutz selbst nicht.</p>';
        printf(
            '<p><label><input type="checkbox" name="kmembers_defer_onboarding" value="1" %s> <strong>Defer Onboarding</strong></label></p>',
            checked( $defer_onboarding, true, false )
        );
        echo '<p class="description">Wenn aktiv, führt ein Anmelde-Link für diesen Flow nach erfolgreichem Login direkt zum Ziel. Das einmalige Passwortangebot wird für diesen Login nur aufgeschoben, nicht dauerhaft abgelehnt.</p>';
    }

    public function save_meta( $post_id ) {
        if ( ! isset($_POST['kmembers_access_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kmembers_access_nonce'])),'kmembers_save_access') ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! current_user_can('edit_post',$post_id) ) return;
        if ( ! in_array(get_post_type($post_id),array('post','page'),true) ) return;
        $roles = array();
        if ( isset($_POST['kmembers_roles']) && is_array($_POST['kmembers_roles']) ) {
            $editable = wp_roles()->roles;
            foreach ( wp_unslash($_POST['kmembers_roles']) as $role ) {
                $role = sanitize_key($role); if ( isset($editable[$role]) ) $roles[] = $role;
            }
        }
        update_post_meta($post_id,self::META_ROLES,array_values(array_unique($roles)));
        update_post_meta($post_id,self::META_GUEST_REDIRECT,absint($_POST['kmembers_guest_redirect'] ?? 0));
        update_post_meta($post_id,self::META_ROLE_REDIRECT,absint($_POST['kmembers_role_redirect'] ?? 0));
        update_post_meta($post_id,self::META_HIDE_GUEST,empty($_POST['kmembers_hide_guest'])?0:1);
        update_post_meta($post_id,self::META_HIDE_ROLE,empty($_POST['kmembers_hide_role'])?0:1);
        update_post_meta($post_id,self::META_FLOW_IDENTIFIER,sanitize_text_field(wp_unslash($_POST['kmembers_flow_identifier'] ?? '')));
        update_post_meta($post_id,self::META_DEFER_ONBOARDING,empty($_POST['kmembers_defer_onboarding'])?0:1);
    }

    public function get_required_roles( $post_id ) { return array_filter((array)get_post_meta($post_id,self::META_ROLES,true)); }
    public function is_protected( $post_id ) { return ! empty($this->get_required_roles($post_id)); }
    public function get_flow_identifier( $post_id ) { return sanitize_text_field( (string) get_post_meta( $post_id, self::META_FLOW_IDENTIFIER, true ) ); }
    public function get_defer_onboarding( $post_id ) { return (bool) get_post_meta( $post_id, self::META_DEFER_ONBOARDING, true ); }

    public function user_can_access( $post_id, $user_id = 0 ) {
        $roles = $this->get_required_roles($post_id);
        if ( empty($roles) ) return true;
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) return false;
        $user = get_userdata($user_id);
        return $user && (bool) array_intersect($roles,(array)$user->roles);
    }

    public function login_url( $return_url = '', $flow_identifier = '', $source_url = '', $defer_onboarding = false ) {
        $url = home_url( user_trailingslashit( $this->settings->get('login_slug','member-login') ) );
        $args = array();
        if ( $return_url ) $args['return_to'] = rawurlencode( $return_url );
        if ( '' !== trim( (string) $flow_identifier ) ) $args['flow_identifier'] = sanitize_text_field( $flow_identifier );
        if ( '' !== trim( (string) $source_url ) ) $args['source_url'] = esc_url_raw( $source_url );
        if ( $defer_onboarding ) $args['defer_onboarding'] = '1';
        return empty( $args ) ? $url : add_query_arg( $args, $url );
    }

    public function protect_singular() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ! is_singular(array('post','page')) ) return;
        $post_id = get_queried_object_id();
        if ( ! $this->is_protected($post_id) ) return;
        if ( ! is_user_logged_in() ) {
            $dest_id = absint(get_post_meta($post_id,self::META_GUEST_REDIRECT,true));
            $dest = $dest_id ? get_permalink($dest_id) : $this->login_url( get_permalink($post_id), $this->get_flow_identifier($post_id), get_permalink($post_id), $this->get_defer_onboarding($post_id) );
            wp_safe_redirect($dest); exit;
        }
        if ( ! $this->user_can_access($post_id) ) {
            $dest_id = absint(get_post_meta($post_id,self::META_ROLE_REDIRECT,true));
            if ( $dest_id && $dest_id !== $post_id ) { wp_safe_redirect(get_permalink($dest_id)); exit; }
            status_header(403); nocache_headers();
            get_header();
            echo '<main class="kmembers-no-access"><h1>'.esc_html($this->settings->get('no_access_title')).'</h1><p>'.esc_html($this->settings->get('no_access_text')).'</p></main>';
            get_footer(); exit;
        }
    }

    public function filter_menu_items( $items, $args ) {
        foreach ( $items as $key => $item ) {
            if ( 'post_type' !== $item->type || ! in_array($item->object,array('post','page'),true) ) continue;
            $id = absint($item->object_id);
            if ( ! $this->is_protected($id) ) continue;
            if ( ! is_user_logged_in() && get_post_meta($id,self::META_HIDE_GUEST,true) ) unset($items[$key]);
            elseif ( is_user_logged_in() && ! $this->user_can_access($id) && get_post_meta($id,self::META_HIDE_ROLE,true) ) unset($items[$key]);
        }
        return array_values($items);
    }
}
