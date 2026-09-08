<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class KMembers_Settings {
    const OPTION = 'kmembers_settings';

    public function default_mail_from_name() {
        $name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        return '' !== trim( $name ) ? $name : 'WordPress';
    }

    public function default_mail_from_email() {
        $host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $host = strtolower( preg_replace( '/^www\./i', '', trim( $host ) ) );
        if ( '' === $host ) {
            $admin_email = sanitize_email( get_option( 'admin_email', '' ) );
            $parts = explode( '@', $admin_email );
            $host = count( $parts ) === 2 ? $parts[1] : 'localhost';
        }
        return 'noreply@' . $host;
    }

    public function __construct() {
        add_action( 'init', array( $this, 'migrate_public_defaults' ), 5 );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_kmembers_add_role', array( $this, 'handle_add_role' ) );
        add_action( 'admin_post_kmembers_delete_role', array( $this, 'handle_delete_role' ) );
        add_filter( 'show_admin_bar', array( $this, 'filter_admin_bar' ) );
    }

    public function migrate_public_defaults() {
        $options = get_option( self::OPTION, array() );
        if ( ! is_array( $options ) ) return;
        if ( isset( $options['login_slug'] ) && 'kmembers-login' === sanitize_title( (string) $options['login_slug'] ) ) {
            $options['login_slug'] = 'member-login';
            update_option( self::OPTION, $options );
            flush_rewrite_rules( false );
        }
    }

    public function defaults() {
        return array(
            'member_page_id'       => 0,
            'logged_in_text'       => 'Eingeloggt als {user}',
            'logged_out_text'      => 'Sie sind nicht eingeloggt',
            'logout_label'         => 'Ausloggen',
            'account_label'        => 'Account',
            'member_label'         => 'Memberbereich',
            'login_label'          => 'Einloggen oder Registrieren',
            'no_access_title'      => 'Kein Zugriff',
            'no_access_text'       => 'Sie haben keine Berechtigung, diese Seite aufzurufen.',
            'login_slug'           => 'member-login',
            'account_embed_page_slug'=> '',
            'consent_text'         => 'Ich erlaube das Speichern meiner Daten und die Verwendung meiner E-Mail-Adresse zur Kontaktaufnahme sowie zur Zustellung von Angeboten unserer Plattform (Newsletter, welcher jederzeit abbestellt werden kann).',
            'magic_subject'        => 'Ihr Anmeldelink',
            'magic_from_name'      => $this->default_mail_from_name(),
            'magic_from_email'     => $this->default_mail_from_email(),
            'magic_body'           => "Hallo,\n\nüber den folgenden Link können Sie sich einmalig anmelden:\n\n{login_link}\n\nDer Link ist 30 Minuten gültig und kann nur einmal verwendet werden.\n\nViele Grüße\n{site_name}",
            'magic_ttl'            => 1800,
            'remember_me'          => 0,
            'require_names'        => 0,
            'cleanup_days'         => 10,
            'admin_bar_hidden_roles'=> array(),
        );
    }

    public function get_all() {
        return wp_parse_args( get_option( self::OPTION, array() ), $this->defaults() );
    }

    public function get( $key, $fallback = null ) {
        $all = $this->get_all();
        return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
    }

    public function admin_menu() {
        add_users_page( 'KornSW MemberSystem', 'MemberSystem (KornSW)', 'manage_options', 'kmembers', array( $this, 'render_page' ) );
    }

    public function register_settings() {
        register_setting( 'kmembers', self::OPTION, array( $this, 'sanitize' ) );
    }

    public function sanitize( $input ) {
        $d = $this->defaults();
        $out = array();
        $out['member_page_id']  = isset( $input['member_page_id'] ) ? absint( $input['member_page_id'] ) : 0;
        $out['account_embed_page_slug'] = sanitize_title( $input['account_embed_page_slug'] ?? $d['account_embed_page_slug'] );
        $out['logged_in_text']  = sanitize_text_field( $input['logged_in_text'] ?? $d['logged_in_text'] );
        $out['logged_out_text'] = sanitize_text_field( $input['logged_out_text'] ?? $d['logged_out_text'] );
        $out['logout_label']    = sanitize_text_field( $input['logout_label'] ?? $d['logout_label'] );
        $out['account_label']   = sanitize_text_field( $input['account_label'] ?? $d['account_label'] );
        $out['member_label']    = sanitize_text_field( $input['member_label'] ?? $d['member_label'] );
        $out['login_label']     = sanitize_text_field( $input['login_label'] ?? $d['login_label'] );
        $out['no_access_title'] = sanitize_text_field( $input['no_access_title'] ?? $d['no_access_title'] );
        $out['no_access_text']  = sanitize_textarea_field( $input['no_access_text'] ?? $d['no_access_text'] );
        $out['login_slug']      = sanitize_title( $input['login_slug'] ?? $d['login_slug'] );
        if ( '' === $out['login_slug'] ) $out['login_slug'] = $d['login_slug'];
        $out['consent_text']    = sanitize_textarea_field( $input['consent_text'] ?? $d['consent_text'] );
        $out['magic_subject']   = sanitize_text_field( $input['magic_subject'] ?? $d['magic_subject'] );
        $out['magic_from_name'] = sanitize_text_field( $input['magic_from_name'] ?? '' );
        $out['magic_from_email'] = sanitize_email( $input['magic_from_email'] ?? '' );
        $out['magic_body']      = sanitize_textarea_field( $input['magic_body'] ?? $d['magic_body'] );
        $out['magic_ttl']       = min( DAY_IN_SECONDS, max( 300, absint( $input['magic_ttl'] ?? $d['magic_ttl'] ) ) );
        $out['remember_me']     = empty( $input['remember_me'] ) ? 0 : 1;
        $out['require_names']    = empty( $input['require_names'] ) ? 0 : 1;
        $out['cleanup_days']     = min( 3650, max( 0, absint( $input['cleanup_days'] ?? $d['cleanup_days'] ) ) );
        $available_roles = array_keys( wp_roles()->roles );
        $selected_roles = isset( $input['admin_bar_hidden_roles'] ) && is_array( $input['admin_bar_hidden_roles'] ) ? array_map( 'sanitize_key', wp_unslash( $input['admin_bar_hidden_roles'] ) ) : array();
        $out['admin_bar_hidden_roles'] = array_values( array_intersect( $selected_roles, $available_roles ) );
        return $out;
    }


    private function default_hidden_admin_bar_roles() {
        $hidden = array();
        foreach ( wp_roles()->roles as $slug => $role ) {
            if ( 0 === strpos( $slug, 'member-' ) || 0 === strpos( (string) $role['name'], 'Member-' ) ) $hidden[] = $slug;
        }
        return $hidden;
    }

    public function hidden_admin_bar_roles() {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) || ! array_key_exists( 'admin_bar_hidden_roles', $raw ) ) return $this->default_hidden_admin_bar_roles();
        return is_array( $raw['admin_bar_hidden_roles'] ) ? array_map( 'sanitize_key', $raw['admin_bar_hidden_roles'] ) : array();
    }

    public function filter_admin_bar( $show ) {
        if ( ! is_user_logged_in() ) return $show;
        $hidden = $this->hidden_admin_bar_roles();
        if ( ! $hidden ) return $show;
        $user = wp_get_current_user();
        return array_intersect( (array) $user->roles, $hidden ) ? false : $show;
    }

    private function member_roles() {
        global $wp_roles;
        $roles = array();
        foreach ( $wp_roles->roles as $slug => $role ) {
            if ( 0 === strpos( $role['name'], 'Member-' ) || 0 === strpos( $slug, 'member-' ) ) {
                $roles[ $slug ] = $role;
            }
        }
        return $roles;
    }

    public function handle_add_role() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Keine Berechtigung.', 'kmembers' ) );
        check_admin_referer( 'kmembers_add_role' );
        $name = sanitize_text_field( wp_unslash( $_POST['role_name'] ?? '' ) );
        $name = preg_replace( '/^Member-\s*/i', '', $name );
        if ( '' !== trim( $name ) ) {
            $display = 'Member-' . trim( $name );
            $slug = sanitize_key( 'member-' . $name );
            if ( ! get_role( $slug ) ) {
                add_role( $slug, $display, array( 'read' => true ) );
                $options = get_option( self::OPTION, array() );
                if ( is_array( $options ) && array_key_exists( 'admin_bar_hidden_roles', $options ) ) {
                    $hidden = is_array( $options['admin_bar_hidden_roles'] ) ? $options['admin_bar_hidden_roles'] : array();
                    $hidden[] = $slug;
                    $options['admin_bar_hidden_roles'] = array_values( array_unique( array_map( 'sanitize_key', $hidden ) ) );
                    update_option( self::OPTION, $options );
                }
            }
        }
        wp_safe_redirect( add_query_arg( 'page', 'kmembers', admin_url( 'users.php' ) ) ); exit;
    }

    public function handle_delete_role() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Keine Berechtigung.', 'kmembers' ) );
        check_admin_referer( 'kmembers_delete_role' );
        $slug = sanitize_key( wp_unslash( $_POST['role_slug'] ?? '' ) );
        $role = get_role( $slug );
        global $wp_roles;
        $name = $wp_roles->roles[ $slug ]['name'] ?? '';
        if ( $role && ( 0 === strpos( $slug, 'member-' ) || 0 === strpos( $name, 'Member-' ) ) ) {
            remove_role( $slug );
        }
        wp_safe_redirect( add_query_arg( 'page', 'kmembers', admin_url( 'users.php' ) ) ); exit;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $s = $this->get_all();
        $roles = $this->member_roles();
        ?>
        <div class="wrap"><h1>KornSW MemberSystem</h1>
            <h2>Dedizierte Member-Rollen</h2>
            <p>Hier werden ausschließlich Rollen mit dem festen Präfix <code>Member-</code> verwaltet.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;margin-bottom:18px">
                <input type="hidden" name="action" value="kmembers_add_role">
                <?php wp_nonce_field( 'kmembers_add_role' ); ?>
                <span>Member-</span><input type="text" name="role_name" required>
                <?php submit_button( 'Rolle anlegen', 'secondary', 'submit', false ); ?>
            </form>
            <table class="widefat striped"><thead><tr><th>Rolle</th><th>Slug</th><th>Aktion</th></tr></thead><tbody>
            <?php if ( empty( $roles ) ) : ?><tr><td colspan="3">Noch keine dedizierten Member-Rollen vorhanden.</td></tr><?php endif; ?>
            <?php foreach ( $roles as $slug => $role ) : ?><tr><td><?php echo esc_html( $role['name'] ); ?></td><td><code><?php echo esc_html( $slug ); ?></code></td><td>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Rolle wirklich löschen? Benutzer verlieren dadurch diese Rolle.');">
                    <input type="hidden" name="action" value="kmembers_delete_role"><input type="hidden" name="role_slug" value="<?php echo esc_attr( $slug ); ?>"><?php wp_nonce_field( 'kmembers_delete_role' ); ?>
                    <?php submit_button( 'Löschen', 'delete', 'submit', false ); ?>
                </form></td></tr><?php endforeach; ?>
            </tbody></table>

            <form method="post" action="options.php" style="margin-top:28px">
                <?php settings_fields( 'kmembers' ); ?>
                <h2>Allgemeine Einstellungen</h2>
                <table class="form-table" role="presentation">
                    <tr><th>Memberbereich</th><td><?php wp_dropdown_pages( array( 'name' => self::OPTION.'[member_page_id]', 'selected' => $s['member_page_id'], 'show_option_none' => '— Nicht ausgewählt —' ) ); ?></td></tr>
                    <tr><th>Account-Erweiterungsseite</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[account_embed_page_slug]" value="<?php echo esc_attr($s['account_embed_page_slug']); ?>" placeholder="z. B. account-details"><p class="description">Optionaler Seiten-Slug. Der Inhalt dieser veröffentlichten Seite wird in der Account-UI direkt oberhalb der MemberSystem-Profildaten eingebettet und kann z. B. Shortcodes anderer Plugins enthalten.</p></td></tr>
                    <tr><th>Login-Pfad</th><td><code><?php echo esc_html( home_url( '/' ) ); ?></code><input name="<?php echo esc_attr( self::OPTION ); ?>[login_slug]" value="<?php echo esc_attr( $s['login_slug'] ); ?>" class="regular-text"><p class="description">Nach Änderung bitte einmal „Einstellungen → Permalinks“ speichern.</p></td></tr>
                    <?php $fields = array('logged_in_text'=>'Text: eingeloggt','logged_out_text'=>'Text: ausgeloggt','logout_label'=>'Link: Ausloggen','account_label'=>'Link: Account','member_label'=>'Link: Memberbereich','login_label'=>'Link: Einloggen/Registrieren','no_access_title'=>'Titel: Kein Zugriff'); foreach($fields as $key=>$label): ?>
                    <tr><th><?php echo esc_html($label); ?></th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION.'['.$key.']'); ?>" value="<?php echo esc_attr($s[$key]); ?>"></td></tr><?php endforeach; ?>
                    <tr><th>Text: Kein Zugriff</th><td><textarea class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION); ?>[no_access_text]"><?php echo esc_textarea($s['no_access_text']); ?></textarea></td></tr>
                    <tr><th>Vor- und Nachname</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[require_names]" value="1" <?php checked($s['require_names'],1); ?>> bei Neuregistrierungen als Pflichtfelder verlangen</label></td></tr>
                    <tr><th>WordPress-Adminleiste</th><td><fieldset><legend class="screen-reader-text">Adminleiste nach Rollen ausblenden</legend><p class="description">Für markierte Rollen wird die schwarze WordPress-Leiste im Frontend ausgeblendet. Dedizierte Member-Rollen sind bei ihrer Anlage standardmäßig markiert.</p><?php $hidden_admin_roles = $this->hidden_admin_bar_roles(); foreach ( wp_roles()->roles as $role_slug => $role_data ) : ?><label style="display:block;margin:5px 0"><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[admin_bar_hidden_roles][]" value="<?php echo esc_attr($role_slug); ?>" <?php checked(in_array($role_slug,$hidden_admin_roles,true)); ?>> <?php echo esc_html($role_data['name']); ?></label><?php endforeach; ?></fieldset></td></tr>
                    <tr><th>Unbenutzte Registrierungen löschen</th><td><input type="number" min="0" max="3650" name="<?php echo esc_attr(self::OPTION); ?>[cleanup_days]" value="<?php echo esc_attr($s['cleanup_days']); ?>"> Tage<p class="description">0 deaktiviert die Bereinigung. Gelöscht werden ausschließlich über KornSW MemberSystem neu angelegte Konten, die sich noch nie erfolgreich angemeldet haben. Extern angelegte oder synchronisierte Benutzer ohne MemberSystem-Markierung werden niemals berücksichtigt.</p></td></tr>
                    <tr><th>Pflicht-Einwilligung</th><td><textarea class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION); ?>[consent_text]"><?php echo esc_textarea($s['consent_text']); ?></textarea></td></tr>
                </table>
                <h2>Anmelde-Link-E-Mail</h2>
                <table class="form-table" role="presentation">
                    <tr><th>Absendername</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[magic_from_name]" value="<?php echo esc_attr($s['magic_from_name']); ?>" placeholder="<?php echo esc_attr($this->default_mail_from_name()); ?>"><p class="description">Leer = Seitentitel: <code><?php echo esc_html($this->default_mail_from_name()); ?></code></p></td></tr>
                    <tr><th>Absender-E-Mail-Adresse</th><td><input type="email" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[magic_from_email]" value="<?php echo esc_attr($s['magic_from_email']); ?>" placeholder="<?php echo esc_attr($this->default_mail_from_email()); ?>"><p class="description">Leer = <code><?php echo esc_html($this->default_mail_from_email()); ?></code></p></td></tr>
                    <tr><th>Betreff</th><td><input class="large-text" name="<?php echo esc_attr(self::OPTION); ?>[magic_subject]" value="<?php echo esc_attr($s['magic_subject']); ?>"></td></tr>
                    <tr><th>Mailtext</th><td><textarea class="large-text code" rows="10" name="<?php echo esc_attr(self::OPTION); ?>[magic_body]"><?php echo esc_textarea($s['magic_body']); ?></textarea><p class="description">Platzhalter: {login_link}, {site_name}, {site_url}, {email}, {target_url}</p></td></tr>
                    <tr><th>Gültigkeit (Sekunden)</th><td><input type="number" min="300" max="86400" name="<?php echo esc_attr(self::OPTION); ?>[magic_ttl]" value="<?php echo esc_attr($s['magic_ttl']); ?>"></td></tr>
                    <tr><th>Remember Me</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[remember_me]" value="1" <?php checked($s['remember_me'],1); ?>> dauerhaftes Login-Cookie setzen</label></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr style="margin:32px 0 24px">
            <h2>Kurzhilfe</h2>
            <p><strong>Inhalte schützen:</strong> In der Bearbeitung von Seiten und Beiträgen findest du den Bereich „MemberSystem (KornSW) – Zugriff“. Dort können eine oder mehrere erlaubte Rollen sowie getrennte Weiterleitungsziele für Gäste und angemeldete Benutzer ohne passende Rolle festgelegt werden.</p>
            <p><code>[kMemberEmbed site="slug" fallback="slug2" flow_identifier="Whitepaper Download" defer_onboarding="true"]</code><br>
            Bettet den Inhalt einer anderen veröffentlichten Seite ein. <code>site</code> ist Pflicht und kann ein Seiten-Slug oder eine Seiten-ID sein. Die für die Zielseite hinterlegten Rollenregeln werden geprüft. Hat der aktuelle Benutzer keinen Zugriff, wird optional die mit <code>fallback</code> angegebene Seite eingebettet; ohne Fallback bleibt die Ausgabe leer. <code>flow_identifier</code> ist optional und überschreibt für diesen Embed-Vorgang den an der Zielseite hinterlegten Flow-Identifier. <code>defer_onboarding="true"</code> überspringt für den durch diesen Embed gestarteten Login das einmalige Passwortangebot und führt direkt zum Ziel; die Entscheidung gilt nur für diesen Login-Token.</p>
            <p><code>[kMemberLogonState]</code><br>
            Zeigt abhängig vom Loginstatus den konfigurierten Status-Text und darunter die passenden Links an. Eingeloggte Benutzer sehen „Ausloggen“, „Account“ und – sofern ausgewählt, erlaubt und nicht gerade geöffnet – den Memberbereich. Ausgeloggte Benutzer erhalten den Link zur MemberSystem-Anmeldung.</p>
        </div><?php
    }
}
