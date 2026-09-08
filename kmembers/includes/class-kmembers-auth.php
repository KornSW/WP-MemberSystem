<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class KMembers_Auth {
    const PASSWORD_STATE_META = '_kmembers_password_state';
    const PASSWORD_OFFER_META = '_kmembers_password_offer';
    const STATE_GENERATED = 'generated';
    const STATE_USER_SET = 'user_set';
    const OFFER_PENDING = 'pending';
    const OFFER_DECLINED = 'declined';
    const OFFER_COMPLETED = 'completed';
    const OFFER_LOGGED_IN = 'logged_in';
    const OFFER_PENDING_PREFIX = 'pending:';
    const TOKEN_PREFIX = 'kmembers_login_';
    const OFFER_PREFIX = 'kmembers_offer_';
    const QUERY_VAR = 'kmembers_login';
    const DELETE_REQUEST_META = '_kmembers_delete_requested_at';
    const MAGIC_TOKEN_QUERY_ARG = 'member-login-token';

    private $settings;
    private $access;
    private static $Rendering_Wp_Login_Extensions = false;

    public function __construct( $settings, $access ) {
        $this->settings = $settings;
        $this->access = $access;
        add_action( 'init', array( $this, 'register_rewrite' ) );
        add_action( 'init', array( $this, 'ensure_cleanup_schedule' ) );
        add_filter( 'query_vars', array( $this, 'query_vars' ) );
        add_action( 'template_redirect', array( $this, 'route' ), 0 );
        add_action( 'after_password_reset', array( $this, 'mark_password_set' ), 10, 2 );
        add_action( 'profile_update', array( $this, 'profile_update' ), 10, 2 );
        add_filter( 'login_message', array( $this, 'extended_login_message' ) );
        add_action( 'login_init', array( $this, 'maybe_customize_wp_login' ), 1 );
        add_action( 'login_enqueue_scripts', array( $this, 'enqueue_wp_login_assets' ) );
        add_action( 'wp_login', array( $this, 'mark_user_logged_in' ), 20, 2 );
        add_action( 'kmembers_cleanup_unlogged_users', array( $this, 'cleanup_unlogged_users' ) );
    }

    public static function add_rewrite_rule() {
        add_rewrite_rule( '^member-login/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
    }

    public function register_rewrite() {
        $slug = sanitize_title( $this->settings->get( 'login_slug', 'member-login' ) );
        add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
    }

    public function ensure_cleanup_schedule() {
        if ( ! wp_next_scheduled( 'kmembers_cleanup_unlogged_users' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'kmembers_cleanup_unlogged_users' );
        }
    }

    public function query_vars( $vars ) { $vars[] = self::QUERY_VAR; return $vars; }

    public function mark_password_set( $user, $new_pass ) {
        if ( $user instanceof WP_User ) {
            update_user_meta( $user->ID, self::PASSWORD_STATE_META, self::STATE_USER_SET );
            update_user_meta( $user->ID, self::PASSWORD_OFFER_META, self::OFFER_COMPLETED );
        }
    }

    public function profile_update( $user_id, $old_user_data ) {
        if ( isset( $_POST['pass1'] ) && '' !== (string) $_POST['pass1'] ) {
            update_user_meta( $user_id, self::PASSWORD_STATE_META, self::STATE_USER_SET );
            update_user_meta( $user_id, self::PASSWORD_OFFER_META, self::OFFER_COMPLETED );
        }
    }


    private function is_pending_offer_state( $state ) {
        return is_string( $state ) && 0 === strpos( $state, self::OFFER_PENDING_PREFIX );
    }

    public function mark_user_logged_in( $user_login, $user ) {
        if ( ! ( $user instanceof WP_User ) ) return;
        if ( self::STATE_GENERATED !== get_user_meta( $user->ID, self::PASSWORD_STATE_META, true ) ) return;
        $offer = get_user_meta( $user->ID, self::PASSWORD_OFFER_META, true );
        if ( $this->is_pending_offer_state( $offer ) ) {
            update_user_meta( $user->ID, self::PASSWORD_OFFER_META, self::OFFER_LOGGED_IN );
        }
    }

    public function cleanup_unlogged_users() {
        $days = absint( $this->settings->get( 'cleanup_days', 10 ) );
        if ( $days < 1 ) return;
        $cutoff = time() - ( $days * DAY_IN_SECONDS );
        require_once ABSPATH . 'wp-admin/includes/user.php';

        $delete_requests = get_users( array(
            'fields' => array( 'ID' ),
            'number' => 200,
            'meta_query' => array(
                array( 'key' => self::DELETE_REQUEST_META, 'compare' => 'EXISTS' ),
            ),
        ) );
        foreach ( $delete_requests as $candidate ) {
            $requested_at = absint( get_user_meta( $candidate->ID, self::DELETE_REQUEST_META, true ) );
            if ( $requested_at && $requested_at <= $cutoff ) wp_delete_user( absint( $candidate->ID ) );
        }

        $users = get_users( array(
            'fields' => array( 'ID' ),
            'number' => 200,
            'meta_query' => array(
                'relation' => 'AND',
                array( 'key' => self::PASSWORD_STATE_META, 'value' => self::STATE_GENERATED, 'compare' => '=' ),
                array( 'key' => self::PASSWORD_OFFER_META, 'value' => self::OFFER_PENDING_PREFIX, 'compare' => 'LIKE' ),
            ),
        ) );
        if ( empty( $users ) ) return;
        foreach ( $users as $candidate ) {
            $user_id = absint( $candidate->ID );
            if ( ! $user_id ) continue;
            $state = get_user_meta( $user_id, self::PASSWORD_STATE_META, true );
            $offer = get_user_meta( $user_id, self::PASSWORD_OFFER_META, true );
            if ( self::STATE_GENERATED !== $state || ! $this->is_pending_offer_state( $offer ) ) continue;
            $created_at = absint( substr( $offer, strlen( self::OFFER_PENDING_PREFIX ) ) );
            if ( ! $created_at || $created_at > $cutoff ) continue;
            wp_delete_user( $user_id );
        }
    }

    public function login_url( $return_to = '', $flow_identifier = '', $source_url = '', $defer_onboarding = false ) { return $this->access->login_url( $return_to, $flow_identifier, $source_url, $defer_onboarding ); }

    public function account_url() { return add_query_arg( 'step', 'account-profile', $this->login_url() ); }

    private function internal_url( $url, $fallback = '' ) {
        $fallback = $fallback ?: home_url( '/' );
        if ( ! is_string( $url ) || '' === trim( $url ) ) return $fallback;
        $url = rawurldecode( wp_unslash( $url ) );
        if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) $url = home_url( $url );
        return wp_validate_redirect( esc_url_raw( $url ), $fallback );
    }

    private function requested_return_url() {
        return $this->internal_url( $_REQUEST['return_to'] ?? '', home_url( '/' ) );
    }

    private function requested_flow_identifier() {
        return sanitize_text_field( wp_unslash( $_REQUEST['flow_identifier'] ?? '' ) );
    }

    private function requested_source_url() {
        $source = esc_url_raw( wp_unslash( $_REQUEST['source_url'] ?? '' ) );
        if ( '' !== $source ) return $source;
        $ref = wp_get_referer();
        return $ref ? esc_url_raw( $ref ) : '';
    }

    private function requested_defer_onboarding() {
        $value = strtolower( trim( sanitize_text_field( wp_unslash( $_REQUEST['defer_onboarding'] ?? '' ) ) ) );
        return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
    }

    private function resolve_flow_identifier( $target, $explicit = '' ) {
        $explicit = sanitize_text_field( (string) $explicit );
        if ( '' !== $explicit ) return $explicit;
        $post_id = url_to_postid( $this->internal_url( $target, '' ) );
        return $post_id ? $this->access->get_flow_identifier( $post_id ) : '';
    }

    public function route() {
        if ( get_query_var( self::QUERY_VAR ) || isset( $_GET[ self::MAGIC_TOKEN_QUERY_ARG ] ) || isset( $_GET['kmembers_token'] ) ) {
            nocache_headers();
            if ( isset( $_GET[ self::MAGIC_TOKEN_QUERY_ARG ] ) || isset( $_GET['kmembers_token'] ) ) $this->consume_magic_link();
            if ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) $this->handle_post();
            $this->render_page();
            exit;
        }
    }

    private function rate_ok( $email ) {
        $email_key = 'km_rate_e_' . hash( 'sha256', strtolower( $email ) );
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $ip_key = 'km_rate_i_' . hash( 'sha256', $ip );
        if ( get_transient( $email_key ) || get_transient( $ip_key ) ) return false;
        set_transient( $email_key, 1, 30 );
        set_transient( $ip_key, 1, 10 );
        return true;
    }

    private function handle_post() {
        $action = sanitize_key( wp_unslash( $_POST['kmembers_action'] ?? '' ) );
        if ( ! isset( $_POST['kmembers_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kmembers_nonce'] ) ), 'kmembers_auth' ) ) {
            $this->redirect_message( 'error', 'Aus Sicherheitsgründen müssen Sie sich erneut einloggen.' );
        }
        if ( 'identify' === $action && ! empty( $_POST['website'] ) ) {
            $this->redirect_message( 'success', 'Bitte prüfen Sie gegebenenfalls Ihr E-Mail-Postfach.' );
        }

        if ( 'identify' === $action ) $this->handle_identify();
        if ( 'register' === $action ) $this->handle_register();
        if ( 'password_login' === $action ) $this->handle_password_login();
        if ( 'account_login_link' === $action ) $this->handle_account_login_link();
        if ( 'password_offer_decline' === $action ) $this->handle_password_offer_decline();
        if ( 'password_offer_set' === $action ) $this->handle_password_offer_set();
        if ( 'account_profile_update' === $action ) $this->handle_account_profile_update();
        if ( 'account_password_update' === $action ) $this->handle_account_password_update();
        if ( 'account_delete_request' === $action ) $this->handle_account_delete_request();
        $this->redirect_message( 'error', 'Ungültige Anfrage.' );
    }

    private function redirect_message( $type, $message, $args = array() ) {
        $url = $this->login_url( $this->requested_return_url(), $this->requested_flow_identifier(), $this->requested_source_url(), $this->requested_defer_onboarding() );
        $args = array_merge( $args, array( 'km_msg_type' => $type, 'km_msg' => rawurlencode( $message ) ) );
        wp_safe_redirect( add_query_arg( $args, $url ) );
        exit;
    }

    private function handle_identify() {
        $identifier = trim( sanitize_text_field( wp_unslash( $_POST['email'] ?? '' ) ) );
        if ( '' === $identifier ) $this->redirect_message( 'error', 'Bitte geben Sie Ihre E-Mail-Adresse ein.' );

        $email = sanitize_email( $identifier );
        $user = is_email( $email ) ? get_user_by( 'email', $email ) : false;
        if ( ! $user ) $user = get_user_by( 'login', sanitize_user( $identifier, true ) );

        if ( $user ) {
            $marker = get_user_meta( $user->ID, self::PASSWORD_STATE_META, true );
            if ( self::STATE_GENERATED === $marker ) {
                if ( ! $this->rate_ok( $user->user_email ) ) {
                    $this->redirect_message( 'error', 'Bitte warten Sie kurz, bevor Sie einen neuen Anmeldelink anfordern.' );
                }
                $sent = $this->send_magic( $user, $this->requested_return_url(), false, array(
                    'source_url' => $this->requested_source_url(),
                    'flow_identifier' => $this->requested_flow_identifier(),
                    'defer_onboarding' => $this->requested_defer_onboarding(),
                ) );
                $this->redirect_message(
                    $sent ? 'success' : 'error',
                    $sent ? 'Wir haben Ihnen einen Anmelde-Link per E-Mail geschickt.' : 'Die E-Mail konnte nicht versendet werden.',
                    $sent ? array( 'step' => 'sent', 'km_sent' => '1' ) : array()
                );
            }

            $token = $this->create_context( array(
                'email' => $user->user_email,
                'state' => 'password',
                'return_to' => $this->requested_return_url(),
                'source_url' => $this->requested_source_url(),
                'flow_identifier' => $this->requested_flow_identifier(),
                'defer_onboarding' => $this->requested_defer_onboarding(),
            ) );
            wp_safe_redirect( add_query_arg( array( 'step' => 'account', 'ctx' => $token ), $this->login_url() ) );
            exit;
        }

        if ( ! is_email( $email ) ) {
            $this->redirect_message( 'error', 'Für eine Registrierung geben Sie bitte eine gültige E-Mail-Adresse ein.' );
        }

        $token = $this->create_context( array(
            'email' => $email,
            'state' => 'new',
            'return_to' => $this->requested_return_url(),
            'source_url' => $this->requested_source_url(),
            'flow_identifier' => $this->requested_flow_identifier(),
            'defer_onboarding' => $this->requested_defer_onboarding(),
        ) );
        wp_safe_redirect( add_query_arg( array( 'step' => 'account', 'ctx' => $token ), $this->login_url() ) );
        exit;
    }

    private function create_context( $data ) {
        $token = wp_generate_password( 32, false, false );
        set_transient( 'kmembers_identify_' . hash( 'sha256', $token ), $data, 15 * MINUTE_IN_SECONDS );
        return $token;
    }

    private function get_context() {
        $token = sanitize_text_field( wp_unslash( $_REQUEST['ctx'] ?? '' ) );
        if ( ! preg_match( '/^[A-Za-z0-9]+$/', $token ) ) return false;
        $data = get_transient( 'kmembers_identify_' . hash( 'sha256', $token ) );
        return is_array( $data ) ? array( $token, $data ) : false;
    }

    private function validate_password( $pass, $confirm ) {
        if ( $pass !== $confirm ) return 'Die Passwörter stimmen nicht überein.';
        if ( strlen( $pass ) < 8 ) return 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        if ( ! preg_match( '/[A-Z]/', $pass ) || ! preg_match( '/[a-z]/', $pass ) || ! preg_match( '/[0-9]/', $pass ) || ! preg_match( '/[^A-Za-z0-9]/', $pass ) ) {
            return 'Das Passwort muss Groß- und Kleinbuchstaben, eine Zahl und ein Sonderzeichen enthalten.';
        }
        return true;
    }

    private function unique_username( $email, $first_name = '', $last_name = '' ) {
        $name_parts = array_filter( array( sanitize_user( $first_name, true ), sanitize_user( $last_name, true ) ) );
        $base = implode( '.', $name_parts );
        if ( '' === $base ) {
            $parts = explode( '@', $email );
            $base = sanitize_user( $parts[0], true );
        }
        if ( '' === $base ) $base = 'user';
        $candidate = $base;
        $i = 2;
        while ( username_exists( $candidate ) ) {
            $candidate = $base . '-' . $i;
            $i++;
            if ( $i > 9999 ) {
                $candidate = $base . '-' . strtolower( wp_generate_password( 6, false, false ) );
                break;
            }
        }
        return $candidate;
    }

    private function handle_register() {
        $ctx = $this->get_context();
        if ( ! $ctx ) $this->redirect_message( 'error', 'Die Sitzung ist abgelaufen. Bitte beginnen Sie erneut.' );
        list( $token, $data ) = $ctx;
        if ( 'new' !== $data['state'] ) $this->redirect_message( 'error', 'Die Sitzung ist nicht mehr gültig.' );
        if ( empty( $_POST['consent'] ) ) $this->redirect_message( 'error', 'Bitte bestätigen Sie die Einwilligung.', array( 'step' => 'account', 'ctx' => $token ) );

        $first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        $display_name = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
        $names_required = (bool) $this->settings->get( 'require_names', 0 );

        if ( $names_required && '' === $first_name ) $this->redirect_message( 'error', 'Bitte geben Sie Ihren Vornamen an.', array( 'step' => 'account', 'ctx' => $token ) );
        if ( $names_required && '' === $last_name ) $this->redirect_message( 'error', 'Bitte geben Sie Ihren Nachnamen an.', array( 'step' => 'account', 'ctx' => $token ) );
        if ( '' === $display_name ) $this->redirect_message( 'error', 'Bitte geben Sie einen Anzeigenamen an.', array( 'step' => 'account', 'ctx' => $token ) );
        if ( get_user_by( 'email', $data['email'] ) ) $this->redirect_message( 'error', 'Für diese E-Mail-Adresse besteht inzwischen bereits ein Konto.' );

        $user_id = wp_insert_user( array(
            'user_login' => $this->unique_username( $data['email'], $first_name, $last_name ),
            'user_email' => $data['email'],
            'user_pass' => wp_generate_password( 40, true, true ),
            'role' => get_option( 'default_role', 'subscriber' ),
            'first_name' => $first_name,
            'last_name' => $last_name,
            'nickname' => $display_name,
            'display_name' => $display_name,
        ) );
        if ( is_wp_error( $user_id ) ) $this->redirect_message( 'error', 'Das Benutzerkonto konnte nicht erstellt werden.' );

        update_user_meta( $user_id, self::PASSWORD_STATE_META, self::STATE_GENERATED );
        update_user_meta( $user_id, self::PASSWORD_OFFER_META, self::OFFER_PENDING_PREFIX . time() );
        delete_transient( 'kmembers_identify_' . hash( 'sha256', $token ) );

        $user = get_userdata( $user_id );
        $sent = $this->send_magic( $user, $data['return_to'], true, array(
            'source_url' => $data['source_url'] ?? '',
            'flow_identifier' => $data['flow_identifier'] ?? '',
            'defer_onboarding' => ! empty( $data['defer_onboarding'] ),
        ) );
        $this->redirect_message(
            $sent ? 'success' : 'error',
            $sent
                ? 'Ein Benutzerkonto für Sie wurde angelegt. Zusätzlich haben wir Ihnen einen Anmelde-Link per E-Mail geschickt.'
                : 'Das Benutzerkonto wurde angelegt, die E-Mail mit dem Anmelde-Link konnte jedoch nicht versendet werden.',
            $sent ? array( 'step' => 'sent', 'km_sent' => '1' ) : array()
        );
    }

    private function handle_account_login_link() {
        $ctx = $this->get_context();
        if ( ! $ctx ) $this->redirect_message( 'error', 'Die Sitzung ist abgelaufen.' );
        list( $token, $data ) = $ctx;
        $user = get_user_by( 'email', $data['email'] );
        if ( ! $user ) $this->redirect_message( 'error', 'Anmeldung fehlgeschlagen.' );

        if ( ! $this->rate_ok( $user->user_email ) ) {
            $this->redirect_message( 'error', 'Bitte warten Sie kurz, bevor Sie einen neuen Anmeldelink anfordern.', array( 'step' => 'account', 'ctx' => $token ) );
        }

        $sent = $this->send_magic( $user, $this->account_url(), false, array(
            'source_url' => $data['source_url'] ?? $this->requested_source_url(),
            'flow_identifier' => $data['flow_identifier'] ?? '',
            'defer_onboarding' => ! empty( $data['defer_onboarding'] ),
        ) );
        if ( $sent ) {
            delete_transient( 'kmembers_identify_' . hash( 'sha256', $token ) );
        }
        $this->redirect_message(
            $sent ? 'success' : 'error',
            $sent ? 'Wir haben Ihnen einen Anmelde-Link per E-Mail geschickt. Nach der Anmeldung gelangen Sie direkt zu Ihren Account-Einstellungen.' : 'Die E-Mail konnte nicht versendet werden.',
            $sent ? array( 'step' => 'sent', 'km_sent' => '1' ) : array( 'step' => 'account', 'ctx' => $token )
        );
    }

    private function handle_password_login() {
        $ctx = $this->get_context();
        if ( ! $ctx ) $this->redirect_message( 'error', 'Die Sitzung ist abgelaufen.' );
        list( $token, $data ) = $ctx;
        $user = get_user_by( 'email', $data['email'] );
        if ( ! $user ) $this->redirect_message( 'error', 'Anmeldung fehlgeschlagen.' );

        $signed = wp_signon( array(
            'user_login' => $user->user_login,
            'user_password' => (string) wp_unslash( $_POST['password'] ?? '' ),
            'remember' => ! empty( $this->settings->get( 'remember_me' ) ),
        ), is_ssl() );

        if ( is_wp_error( $signed ) ) {
            $credential_errors = array( 'incorrect_password', 'invalid_username', 'invalid_email', 'empty_username', 'empty_password' );
            if ( array_intersect( $credential_errors, $signed->get_error_codes() ) ) {
                $this->redirect_message( 'error', 'E-Mail-Adresse oder Passwort ist nicht korrekt.', array( 'step' => 'account', 'ctx' => $token ) );
            }
            $this->redirect_to_extended_login( $user, $data['return_to'], $token );
        }
        if ( ! ( $signed instanceof WP_User ) || (int) $signed->ID !== (int) $user->ID ) {
            $this->redirect_to_extended_login( $user, $data['return_to'], $token );
        }

        delete_transient( 'kmembers_identify_' . hash( 'sha256', $token ) );
        wp_safe_redirect( $this->internal_url( $data['return_to'], home_url( '/' ) ) );
        exit;
    }

    private function redirect_to_extended_login( $user, $return_to, $context_token = '' ) {
        if ( $context_token ) delete_transient( 'kmembers_identify_' . hash( 'sha256', $context_token ) );
        wp_clear_auth_cookie();
        wp_set_current_user( 0 );
        $login_url = $this->native_wp_login_url(
            $this->internal_url( $return_to, home_url( '/' ) ),
            array(
                'kmembers_extended_login' => '1',
                'log' => $user instanceof WP_User ? $user->user_login : '',
            )
        );
        wp_safe_redirect( $login_url );
        exit;
    }

    public function extended_login_message( $message ) {
        if ( empty( $_GET['kmembers_extended_login'] ) ) return $message;
        $notice = '<div class="message"><p><strong>' . esc_html__( 'Für Ihren Account ist ein erweiterter Login nötig.', 'kmembers' ) . '</strong><br>' . esc_html__( 'Bitte versuchen Sie die Anmeldung über diese reguläre WordPress-Anmeldeseite erneut.', 'kmembers' ) . '</p></div>';
        return $notice . $message;
    }

    public function enqueue_wp_login_assets() {
        if ( empty( $this->settings->get( 'customize_wp_login', 1 ) ) ) return;
        wp_enqueue_style( 'kmembers', KMEMBERS_URL . 'assets/kmembers.css', array(), KMEMBERS_VERSION );
        wp_enqueue_script( 'kmembers', KMEMBERS_URL . 'assets/kmembers.js', array(), KMEMBERS_VERSION, true );
    }

    private function native_wp_login_url( $return_to = '', $extra_args = array() ) {
        $url = site_url( 'wp-login.php', 'login' );
        $args = array( 'kmembers_native_login' => '1' );
        $return_to = $this->internal_url( $return_to, '' );
        if ( '' !== $return_to ) $args['redirect_to'] = $return_to;
        if ( is_array( $extra_args ) ) {
            foreach ( $extra_args as $key => $value ) {
                $key = sanitize_key( $key );
                if ( '' === $key || null === $value || false === $value || '' === (string) $value ) continue;
                $args[$key] = sanitize_text_field( (string) $value );
            }
        }
        return add_query_arg( $args, $url );
    }

    private function is_native_wp_login_requested() {
        return ! empty( $_REQUEST['kmembers_native_login'] );
    }

    public function maybe_customize_wp_login() {
        if ( self::$Rendering_Wp_Login_Extensions ) return;
        if ( empty( $this->settings->get( 'customize_wp_login', 1 ) ) ) return;
        if ( $this->is_native_wp_login_requested() ) return;

        $method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
        if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) return;

        $action = sanitize_key( wp_unslash( $_REQUEST['action'] ?? 'login' ) );
        if ( '' === $action ) $action = 'login';
        if ( 'login' !== $action ) return;

        // Core-Sonderfälle brauchen die unveränderte wp-login.php-Oberfläche.
        if ( ! empty( $_REQUEST['interim-login'] ) || ! empty( $_REQUEST['reauth'] ) ) return;

        $return_to = $this->internal_url( $_REQUEST['redirect_to'] ?? '', home_url( '/' ) );
        $source_url = esc_url_raw( wp_get_referer() ?: '' );

        if ( ! function_exists( 'login_header' ) || ! function_exists( 'login_footer' ) ) return;

        nocache_headers();
        login_header( __( 'Anmelden', 'kmembers' ) );
        echo '<div class="kmembers-wp-login-custom">';
        echo $this->render_member_login_ui( $return_to, '', $source_url, false, true );
        echo '</div>';
        login_footer();
        exit;
    }

    private function capture_wp_login_extensions( $return_to = '' ) {
        if ( self::$Rendering_Wp_Login_Extensions ) return '';

        self::$Rendering_Wp_Login_Extensions = true;
        $level = ob_get_level();
        ob_start();

        try {
            /**
             * Originaler WordPress-Erweiterungspunkt der regulären Loginmaske.
             * MemberSystem bietet bewusst keinen parallelen Provider-/Button-Contract an.
             */
            do_action( 'login_form' );
            $html = ob_get_clean();
        } finally {
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
            self::$Rendering_Wp_Login_Extensions = false;
        }

        if ( ! isset( $html ) || '' === trim( (string) $html ) ) return '';

        // login_form wird im Core innerhalb des Login-Formulars ausgeführt.
        // Wir geben fremden Erweiterungen deshalb ebenfalls einen eigenen nativen Form-Container.
        return '<div class="kmembers-login-extensions"><form method="post" action="' . esc_url( $this->native_wp_login_url( $return_to ) ) . '">' .
            $html .
            '</form></div>';
    }

    private function render_member_login_ui( $return_to = '', $flow_identifier = '', $source_url = '', $defer_onboarding = false, $include_native_link = true ) {
        $return_to = $this->internal_url( $return_to, home_url( '/' ) );
        $flow_identifier = sanitize_text_field( (string) $flow_identifier );
        $source_url = esc_url_raw( (string) $source_url );
        $action_url = $this->login_url( $return_to, $flow_identifier, $source_url, $defer_onboarding );

        ob_start();
        echo '<div class="kmembers-auth kmembers-auth--embedded"><div class="kmembers-auth__box">';
        echo '<p>Bitte geben Sie zunächst Ihre E-Mail-Adresse ein.</p>';
        echo '<form method="post" action="' . esc_url( $action_url ) . '">';
        wp_nonce_field( 'kmembers_auth', 'kmembers_nonce' );
        echo '<input type="hidden" name="kmembers_action" value="identify">';
        echo '<input type="hidden" name="return_to" value="' . esc_attr( $return_to ) . '">';
        if ( '' !== $flow_identifier ) echo '<input type="hidden" name="flow_identifier" value="' . esc_attr( $flow_identifier ) . '">';
        if ( '' !== $source_url ) echo '<input type="hidden" name="source_url" value="' . esc_attr( $source_url ) . '">';
        if ( $defer_onboarding ) echo '<input type="hidden" name="defer_onboarding" value="1">';
        echo '<div class="kmembers-hp"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
        echo '<p><label>E-Mail-Adresse<br><input type="text" name="email" required autocomplete="email" inputmode="email"></label></p>';
        echo '<button class="kmembers-button" type="submit">Weiter</button>';
        echo '</form>';

        $extensions = $this->capture_wp_login_extensions( $return_to );
        if ( '' !== $extensions ) {
            echo '<div class="kmembers-login-extensions-separator"><span>oder</span></div>';
            echo $extensions;
        }

        if ( $include_native_link ) {
            echo '<p class="kmembers-native-login-link"><a href="' . esc_url( $this->native_wp_login_url( $return_to ) ) . '">Weitere Anmeldemöglichkeiten</a></p>';
        }

        echo '</div></div>';
        return ob_get_clean();
    }

    private function send_magic( $user, $target, $is_initial_signup = false, $extra_context = array() ) {
        try {
            $raw = bin2hex( random_bytes( 32 ) );
        } catch ( Exception $e ) {
            $raw = wp_generate_password( 64, false, false );
        }

        $hash = hash( 'sha256', $raw );
        $ttl = (int) $this->settings->get( 'magic_ttl', 1800 );
        $target_url = $this->internal_url( $target, home_url( '/' ) );
        $flow_identifier = $this->resolve_flow_identifier(
            $target_url,
            $extra_context['flow_identifier'] ?? ''
        );
        $source_url = esc_url_raw( (string) ( $extra_context['source_url'] ?? '' ) );

        $defer_onboarding = ! empty( $extra_context['defer_onboarding'] );

        set_transient( self::TOKEN_PREFIX . $hash, array(
            'user_id' => $user->ID,
            'email' => $user->user_email,
            'target' => $target_url,
            'defer_onboarding' => $defer_onboarding,
        ), $ttl );

        $login_base_url = $this->login_url();
        $link = add_query_arg( self::MAGIC_TOKEN_QUERY_ARG, $raw, $login_base_url );

        $contract_context = array(
            'email' => $user->user_email,
            'login_base_url' => $login_base_url,
            'token_query_param' => self::MAGIC_TOKEN_QUERY_ARG,
            'token' => $raw,
            'source_url' => $source_url,
            'target_url' => $target_url,
            'is_initial_signup' => (bool) $is_initial_signup,
            'flow_identifier' => $flow_identifier,
            'defer_onboarding' => $defer_onboarding,
            'fields' => array(
                'first_name' => (string) $user->first_name,
                'last_name' => (string) $user->last_name,
                'display_name' => (string) $user->display_name,
            ),
        );

        /**
         * Generischer Contract für den Versand einer Member-Login-E-Mail.
         *
         * Ein Handler darf true nur zurückgeben, wenn er den Versand/Flow
         * tatsächlich erfolgreich übernommen hat. Bei false sendet das
         * MemberSystem seine eigene Login-Mail als Fallback.
         *
         * @param bool    $handled Bereits erfolgreich übernommen.
         * @param WP_User $user    Zielbenutzer.
         * @param array   $context Vollständiger Versand-/Login-Kontext.
         */
        $handled = apply_filters( 'member_login_email_send', false, $user, $contract_context );
        if ( true === $handled ) {
            return true;
        }

        $replace = array(
            '{login_link}' => $link,
            '{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            '{site_url}' => home_url( '/' ),
            '{email}' => $user->user_email,
            '{target_url}' => $target_url,
        );
        $subject = strtr( $this->settings->get( 'magic_subject' ), $replace );
        $body = strtr( $this->settings->get( 'magic_body' ), $replace );

        $from_name = sanitize_text_field( (string) $this->settings->get( 'magic_from_name', '' ) );
        if ( '' === trim( $from_name ) ) $from_name = $this->settings->default_mail_from_name();
        $from_email = sanitize_email( (string) $this->settings->get( 'magic_from_email', '' ) );
        if ( '' === $from_email ) $from_email = $this->settings->default_mail_from_email();
        $headers = array( 'From: ' . $from_name . ' <' . $from_email . '>' );

        $ok = wp_mail( $user->user_email, $subject, $body, $headers );
        if ( ! $ok ) delete_transient( self::TOKEN_PREFIX . $hash );
        return $ok;
    }

    private function consume_magic_link() {
        $raw = sanitize_text_field( wp_unslash( $_GET[ self::MAGIC_TOKEN_QUERY_ARG ] ?? ( $_GET['kmembers_token'] ?? '' ) ) );
        if ( ! preg_match( '/^[A-Za-z0-9]+$/', $raw ) ) $this->redirect_message( 'error', 'Dieser Anmeldelink ist ungültig oder abgelaufen.' );
        $key = self::TOKEN_PREFIX . hash( 'sha256', $raw );
        $data = get_transient( $key );
        if ( ! is_array( $data ) ) $this->redirect_message( 'error', 'Dieser Anmeldelink ist ungültig oder abgelaufen.' );
        $user = get_userdata( absint( $data['user_id'] ?? 0 ) );
        if ( ! $user || strtolower( $user->user_email ) !== strtolower( (string) ( $data['email'] ?? '' ) ) ) {
            delete_transient( $key );
            $this->redirect_message( 'error', 'Dieser Anmeldelink ist ungültig oder abgelaufen.' );
        }
        delete_transient( $key );
        $target = $this->internal_url( $data['target'] ?? '', home_url( '/' ) );
        $this->set_auth_session( $user );

        $password_state = get_user_meta( $user->ID, self::PASSWORD_STATE_META, true );
        $offer_state = get_user_meta( $user->ID, self::PASSWORD_OFFER_META, true );
        $offer_open = self::STATE_GENERATED === $password_state
            && ! in_array( $offer_state, array( self::OFFER_DECLINED, self::OFFER_COMPLETED ), true );

        if ( $offer_open ) {
            // Erfolgreicher Login beendet in jedem Fall den Cleanup-Status "nie eingeloggt".
            // Bei defer_onboarding wird das Passwortangebot nur für diesen Token übersprungen.
            update_user_meta( $user->ID, self::PASSWORD_OFFER_META, self::OFFER_LOGGED_IN );

            if ( empty( $data['defer_onboarding'] ) ) {
                $offer_token = wp_generate_password( 32, false, false );
                set_transient( self::OFFER_PREFIX . hash( 'sha256', $offer_token ), array(
                    'user_id' => $user->ID,
                    'target' => $target,
                ), 15 * MINUTE_IN_SECONDS );
                wp_safe_redirect( add_query_arg( array( 'step' => 'password-offer', 'offer' => $offer_token ), $this->login_url() ) );
                exit;
            }
        }

        wp_safe_redirect( $target );
        exit;
    }

    private function get_offer_context() {
        $token = sanitize_text_field( wp_unslash( $_REQUEST['offer'] ?? '' ) );
        if ( ! preg_match( '/^[A-Za-z0-9]+$/', $token ) ) return false;
        $data = get_transient( self::OFFER_PREFIX . hash( 'sha256', $token ) );
        if ( ! is_array( $data ) ) return false;
        if ( ! is_user_logged_in() || get_current_user_id() !== absint( $data['user_id'] ?? 0 ) ) return false;
        return array( $token, $data );
    }

    private function handle_password_offer_decline() {
        $ctx = $this->get_offer_context();
        if ( ! $ctx ) $this->redirect_message( 'error', 'Diese Sitzung ist ungültig oder abgelaufen.' );
        list( $token, $data ) = $ctx;
        update_user_meta( get_current_user_id(), self::PASSWORD_OFFER_META, self::OFFER_DECLINED );
        delete_transient( self::OFFER_PREFIX . hash( 'sha256', $token ) );
        wp_safe_redirect( $this->internal_url( $data['target'] ?? '', home_url( '/' ) ) );
        exit;
    }

    private function handle_password_offer_set() {
        $ctx = $this->get_offer_context();
        if ( ! $ctx ) $this->redirect_message( 'error', 'Diese Sitzung ist ungültig oder abgelaufen.' );
        list( $token, $data ) = $ctx;
        $pass = (string) wp_unslash( $_POST['password'] ?? '' );
        $confirm = (string) wp_unslash( $_POST['password_confirm'] ?? '' );
        $valid = $this->validate_password( $pass, $confirm );
        if ( true !== $valid ) {
            wp_safe_redirect( add_query_arg( array(
                'step' => 'password-offer',
                'offer' => $token,
                'km_msg_type' => 'error',
                'km_msg' => rawurlencode( $valid ),
            ), $this->login_url() ) );
            exit;
        }

        $user_id = get_current_user_id();
        wp_set_password( $pass, $user_id );
        update_user_meta( $user_id, self::PASSWORD_STATE_META, self::STATE_USER_SET );
        update_user_meta( $user_id, self::PASSWORD_OFFER_META, self::OFFER_COMPLETED );
        delete_transient( self::OFFER_PREFIX . hash( 'sha256', $token ) );
        $user = get_userdata( $user_id );
        $this->set_auth_session( $user );
        wp_safe_redirect( $this->internal_url( $data['target'] ?? '', home_url( '/' ) ) );
        exit;
    }


    private function require_logged_in_account() {
        if ( ! is_user_logged_in() ) $this->redirect_message( 'error', 'Bitte melden Sie sich zunächst an.' );
        return wp_get_current_user();
    }

    private function account_redirect( $type, $message ) {
        wp_safe_redirect( add_query_arg( array( 'step' => 'account-profile', 'km_msg_type' => $type, 'km_msg' => rawurlencode( $message ) ), $this->login_url() ) );
        exit;
    }

    private function handle_account_profile_update() {
        $user = $this->require_logged_in_account();
        $display_name = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
        $first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        if ( '' === $display_name ) $this->account_redirect( 'error', 'Bitte geben Sie einen Anzeigenamen an.' );
        if ( $this->settings->get( 'require_names', 0 ) && ( '' === $first_name || '' === $last_name ) ) $this->account_redirect( 'error', 'Vor- und Nachname sind Pflichtfelder.' );
        $result = wp_update_user( array( 'ID' => $user->ID, 'display_name' => $display_name, 'nickname' => $display_name, 'first_name' => $first_name, 'last_name' => $last_name ) );
        if ( is_wp_error( $result ) ) $this->account_redirect( 'error', 'Die Profildaten konnten nicht gespeichert werden.' );
        $this->account_redirect( 'success', 'Ihre Profildaten wurden gespeichert.' );
    }

    private function handle_account_password_update() {
        $user = $this->require_logged_in_account();
        $pass = (string) wp_unslash( $_POST['password'] ?? '' );
        $confirm = (string) wp_unslash( $_POST['password_confirm'] ?? '' );
        $valid = $this->validate_password( $pass, $confirm );
        if ( true !== $valid ) $this->account_redirect( 'error', $valid );
        wp_set_password( $pass, $user->ID );
        update_user_meta( $user->ID, self::PASSWORD_STATE_META, self::STATE_USER_SET );
        update_user_meta( $user->ID, self::PASSWORD_OFFER_META, self::OFFER_COMPLETED );
        $fresh = get_userdata( $user->ID );
        $this->set_auth_session( $fresh );
        $this->account_redirect( 'success', 'Ihr Passwort wurde geändert.' );
    }

    private function handle_account_delete_request() {
        $user = $this->require_logged_in_account();
        if ( empty( $_POST['confirm_delete'] ) ) $this->account_redirect( 'error', 'Bitte bestätigen Sie die gewünschte Kontolöschung.' );
        update_user_meta( $user->ID, self::DELETE_REQUEST_META, time() );
        $days = absint( $this->settings->get( 'cleanup_days', 10 ) );
        $this->account_redirect( 'success', sprintf( 'Die Löschung Ihres Accounts wurde vorgemerkt und kann bis zu %d Tage dauern.', $days ) );
    }

    private function set_auth_session( $user ) {
        wp_clear_auth_cookie();
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, (bool) $this->settings->get( 'remember_me' ), is_ssl() );
        do_action( 'wp_login', $user->user_login, $user );
    }

    private function render_message() {
        if ( empty( $_GET['km_msg'] ) ) return;
        $type = sanitize_key( wp_unslash( $_GET['km_msg_type'] ?? 'info' ) );
        $msg = rawurldecode( sanitize_text_field( wp_unslash( $_GET['km_msg'] ) ) );
        echo '<div class="kmembers-message kmembers-message--' . esc_attr( $type ) . '">' . esc_html( $msg ) . '</div>';
    }

    private function render_page() {
        status_header( 200 );
        get_header();
        $step = sanitize_key( wp_unslash( $_GET['step'] ?? '' ) );
        $ctx = $this->get_context();
        $title = 'Einloggen oder Registrieren';
        if ( 'account' === $step && $ctx && 'new' === ( $ctx[1]['state'] ?? '' ) ) $title = 'Registrieren';
        if ( 'account-profile' === $step && is_user_logged_in() ) $title = 'Mein Account';
        if ( 'password-offer' === $step && is_user_logged_in() ) {
            $current = wp_get_current_user();
            $title = 'Willkommen ' . $current->display_name;
        }
        echo '<main class="kmembers-auth"><div class="kmembers-auth__box"><h1>' . esc_html( $title ) . '</h1>';
        $this->render_message();

        if ( 'sent' === $step || ! empty( $_GET['km_sent'] ) ) {
            echo '<p>Bitte prüfen Sie jetzt Ihr E-Mail-Postfach und verwenden Sie den zugesandten Anmelde-Link.</p>';
            echo '</div></main>';
            get_footer();
            return;
        }


        if ( 'account-profile' === $step ) {
            if ( ! is_user_logged_in() ) {
                wp_safe_redirect( $this->login_url( $this->account_url() ) );
                exit;
            }
            $this->render_account_profile();
            echo '</div></main>';
            get_footer();
            return;
        }

        if ( 'password-offer' === $step ) {
            $offer = $this->get_offer_context();
            if ( $offer ) {
                $this->render_password_offer( $offer );
            } else {
                echo '<p>Diese Sitzung ist ungültig oder abgelaufen.</p>';
            }
            echo '</div></main>';
            get_footer();
            return;
        }

        if ( is_user_logged_in() ) {
            echo '<p>Sie sind bereits eingeloggt.</p><p><a class="kmembers-button" href="' . esc_url( $this->requested_return_url() ) . '">Weiter</a></p>';
            echo '</div></main>';
            get_footer();
            return;
        }

        if ( ! $ctx || 'account' !== $step ) $this->render_identify();
        else $this->render_account( $ctx );
        echo '</div></main>';
        get_footer();
    }

    private function base_fields( $action, $ctx = '', $offer = '' ) {
        wp_nonce_field( 'kmembers_auth', 'kmembers_nonce' );
        echo '<input type="hidden" name="kmembers_action" value="' . esc_attr( $action ) . '">';
        if ( $ctx ) echo '<input type="hidden" name="ctx" value="' . esc_attr( $ctx ) . '">';
        if ( $offer ) echo '<input type="hidden" name="offer" value="' . esc_attr( $offer ) . '">';
        echo '<input type="hidden" name="return_to" value="' . esc_attr( $this->requested_return_url() ) . '">';
        if ( '' !== $this->requested_flow_identifier() ) echo '<input type="hidden" name="flow_identifier" value="' . esc_attr( $this->requested_flow_identifier() ) . '">';
        if ( '' !== $this->requested_source_url() ) echo '<input type="hidden" name="source_url" value="' . esc_attr( $this->requested_source_url() ) . '">';
        if ( $this->requested_defer_onboarding() ) echo '<input type="hidden" name="defer_onboarding" value="1">';
    }

    public function render_embedded_login_form( $return_to = '', $flow_identifier = '', $source_url = '', $defer_onboarding = false ) {
        return $this->render_member_login_ui( $return_to, $flow_identifier, $source_url, $defer_onboarding, false );
    }

    private function render_identify() {
        echo $this->render_member_login_ui(
            $this->requested_return_url(),
            $this->requested_flow_identifier(),
            $this->requested_source_url(),
            $this->requested_defer_onboarding(),
            true
        );
    }

    private function render_account( $ctx ) {
        list( $token, $data ) = $ctx;
        echo '<p><strong>' . esc_html( $data['email'] ) . '</strong></p>';
        if ( 'new' === $data['state'] ) {
            $required = (bool) $this->settings->get( 'require_names', 0 );
            echo '<p>Sie sind neu auf unserer Seite – wir erstellen Ihnen ein Benutzerkonto und senden anschließend einen Anmelde-Link.</p><form method="post">';
            $this->base_fields( 'register', $token );
            echo '<div class="kmembers-profile-fields">';
            echo '<p><label>Anzeigename<br><input type="text" name="display_name" required autocomplete="nickname"></label></p>';
            echo '<p><label>Vorname' . ( $required ? '' : ' (optional)' ) . '<br><input type="text" name="first_name" autocomplete="given-name"' . ( $required ? ' required' : '' ) . '></label></p>';
            echo '<p><label>Nachname' . ( $required ? '' : ' (optional)' ) . '<br><input type="text" name="last_name" autocomplete="family-name"' . ( $required ? ' required' : '' ) . '></label></p></div>';
            echo '<p><label><input type="checkbox" name="consent" value="1" required> ' . esc_html( $this->settings->get( 'consent_text' ) ) . '</label></p>';
            echo '<button class="kmembers-button" type="submit">Registrieren und Anmelde-Link senden</button></form>';
        } else {
            echo '<form method="post">';
            $this->base_fields( 'password_login', $token );
            echo '<p><label>Passwort<br><input type="password" name="password" required autocomplete="current-password"></label></p><div class="kmembers-actions"><button class="kmembers-button" type="submit">Anmelden</button></div></form>';
            echo '<form method="post" class="kmembers-reset-via-mail" style="margin-top:8px">';
            $this->base_fields( 'account_login_link', $token );
            echo '<button class="kmembers-button" type="submit">Passwort zurücksetzen</button></form>';
        }
    }


    private function render_account_embed_page() {
        $slug = sanitize_title( (string) $this->settings->get( 'account_embed_page_slug', '' ) );
        if ( '' === $slug ) return;
        $page = get_page_by_path( $slug, OBJECT, 'page' );
        if ( ! ( $page instanceof WP_Post ) || 'publish' !== $page->post_status ) return;
        $content = apply_filters( 'the_content', $page->post_content );
        if ( '' === trim( wp_strip_all_tags( $content ) ) && '' === trim( $content ) ) return;
        echo '<div class="kmembers-account-embed">' . $content . '</div>';
    }

    private function render_account_profile() {
        $user = wp_get_current_user();
        $required = (bool) $this->settings->get( 'require_names', 0 );
        $days = absint( $this->settings->get( 'cleanup_days', 10 ) );
        $requested = absint( get_user_meta( $user->ID, self::DELETE_REQUEST_META, true ) );
        $this->render_account_embed_page();
        echo '<h2>Account</h2>';
        echo '<p><label>E-Mail-Adresse<br><input type="text" value="' . esc_attr( $user->user_email ) . '" readonly></label></p>';
        echo '<p><label>WordPress-Benutzername<br><input type="text" value="' . esc_attr( $user->user_login ) . '" readonly></label></p>';
        echo '<h2>Profildaten</h2><form method="post">';
        $this->base_fields( 'account_profile_update' );
        echo '<p><label>Anzeigename<br><input type="text" name="display_name" required value="' . esc_attr( $user->display_name ) . '"></label></p>';
        echo '<p><label>Vorname' . ( $required ? '' : ' (optional)' ) . '<br><input type="text" name="first_name"' . ( $required ? ' required' : '' ) . ' value="' . esc_attr( $user->first_name ) . '"></label></p>';
        echo '<p><label>Nachname' . ( $required ? '' : ' (optional)' ) . '<br><input type="text" name="last_name"' . ( $required ? ' required' : '' ) . ' value="' . esc_attr( $user->last_name ) . '"></label></p>';
        echo '<button class="kmembers-button" type="submit">Profildaten speichern</button></form>';
        echo '<hr><h2>Passwort ändern</h2><form method="post">';
        $this->base_fields( 'account_password_update' );
        echo '<p><label>Neues Passwort<br><input type="password" name="password" required autocomplete="new-password"></label></p><p><label>Passwort wiederholen<br><input type="password" name="password_confirm" required autocomplete="new-password"></label></p><p class="description">Mindestens 8 Zeichen sowie Großbuchstabe, Kleinbuchstabe, Zahl und Sonderzeichen.</p><button class="kmembers-button" type="submit">Passwort ändern</button></form>';
        echo '<hr><h2>Account löschen</h2>';
        if ( $requested ) {
            echo '<p>Die Löschung Ihres Accounts wurde bereits vorgemerkt.</p>';
        } else {
            echo '<p>Die Löschung kann bis zu ' . esc_html( $days ) . ' Tage dauern.</p><form method="post">';
            $this->base_fields( 'account_delete_request' );
            echo '<p><label><input type="checkbox" name="confirm_delete" value="1" required> Ich bestätige, dass ich die Löschung meines Accounts anfordern möchte.</label></p><button class="kmembers-button" type="submit">Löschung meines Accounts anfordern</button></form>';
        }
    }

    private function render_password_offer( $ctx ) {
        list( $token, $data ) = $ctx;
        echo '<p>Möchten Sie zusätzlich ein Passwort vergeben? Damit können Sie sich künftig wahlweise per Passwort oder weiterhin per Anmelde-Link einloggen. Diese Frage wird nur einmal angezeigt.</p>';
        echo '<form method="post" class="kmembers-passwordless-choice">';
        $this->base_fields( 'password_offer_decline', '', $token );
        echo '<button class="kmembers-button" type="submit">Auch zukünftig per Mail einloggen</button></form>';
        echo '<form method="post" style="margin-top:12px">';
        $this->base_fields( 'password_offer_set', '', $token );
        echo '<p><label>Passwort<br><input type="password" name="password" required autocomplete="new-password"></label></p>';
        echo '<p><label>Passwort wiederholen<br><input type="password" name="password_confirm" required autocomplete="new-password"></label></p>';
        echo '<p class="description">Mindestens 8 Zeichen sowie Großbuchstabe, Kleinbuchstabe, Zahl und Sonderzeichen.</p>';
        echo '<button class="kmembers-button" type="submit">Passwort speichern und fortfahren</button></form>';
    }
}
