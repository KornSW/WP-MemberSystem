<?php
/**
 * Plugin Name: KornSW MemberSystem
 * Description: Memberbereiche, rollenbasierter Inhaltsschutz und eigener E-Mail-/Passwort-Login.
 * Version: 1.4.2
 * Author: KornSW
 * Text Domain: kmembers
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KMEMBERS_VERSION', '1.4.2' );
define( 'KMEMBERS_FILE', __FILE__ );
define( 'KMEMBERS_DIR', plugin_dir_path( __FILE__ ) );
define( 'KMEMBERS_URL', plugin_dir_url( __FILE__ ) );

require_once KMEMBERS_DIR . 'includes/class-kmembers-settings.php';
require_once KMEMBERS_DIR . 'includes/class-kmembers-access.php';
require_once KMEMBERS_DIR . 'includes/class-kmembers-auth.php';
require_once KMEMBERS_DIR . 'includes/class-kmembers-shortcodes.php';

final class KMembers {
    private static $instance = null;

    public $settings;
    public $access;
    public $auth;
    public $shortcodes;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings   = new KMembers_Settings();
        $this->access     = new KMembers_Access( $this->settings );
        $this->auth       = new KMembers_Auth( $this->settings, $this->access );
        $this->shortcodes = new KMembers_Shortcodes( $this->settings, $this->access, $this->auth );

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'kmembers', false, dirname( plugin_basename( KMEMBERS_FILE ) ) . '/languages' );
    }

    public function enqueue_assets() {
        wp_enqueue_style( 'kmembers', KMEMBERS_URL . 'assets/kmembers.css', array(), KMEMBERS_VERSION );
        wp_enqueue_script( 'kmembers', KMEMBERS_URL . 'assets/kmembers.js', array(), KMEMBERS_VERSION, true );
    }

    public static function activate() {
        KMembers_Auth::add_rewrite_rule();
        if ( ! wp_next_scheduled( 'kmembers_cleanup_unlogged_users' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'kmembers_cleanup_unlogged_users' );
        }
        flush_rewrite_rules();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'kmembers_cleanup_unlogged_users' );
        flush_rewrite_rules();
    }
}

register_activation_hook( __FILE__, array( 'KMembers', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KMembers', 'deactivate' ) );

function kmembers() {
    return KMembers::instance();
}

kmembers();
