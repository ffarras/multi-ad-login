<?php
/**
 * Plugin Name: Multi AD Login
 * Description: Authenticates WordPress users against multiple Active Directory configurations.
 * Version: 1.0.0
 * Author: Lentera Teknologi
 * Author URI: https://lenterateknologi.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: multi-ad-login
 * Domain Path: /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MADL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MADL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MADL_VERSION', '1.0.0' );
define( 'MADL_SETTINGS_SLUG', 'madl-settings' );
define( 'MADL_LOG_FILE', WP_CONTENT_DIR . '/madl-debug.log' ); // Ensure this directory is writable by the web server

// Include necessary files
require_once MADL_PLUGIN_DIR . 'includes/class-madl-logger.php';
require_once MADL_PLUGIN_DIR . 'includes/class-madl-db.php';

// Attempt to load AdLdap library - User must place these files
if ( file_exists( MADL_PLUGIN_DIR . 'includes/lib/AdLdapException.php' ) ) {
    require_once MADL_PLUGIN_DIR . 'includes/lib/AdLdapException.php';
} else {
    MADL_Logger::log( 'ERROR: AdLdapException.php not found in includes/lib/. Please add the library file.', 'ERROR' );
}
if ( file_exists( MADL_PLUGIN_DIR . 'includes/lib/AdLdap.php' ) ) {
    require_once MADL_PLUGIN_DIR . 'includes/lib/AdLdap.php';
} else {
    MADL_Logger::log( 'ERROR: AdLdap.php not found in includes/lib/. Please add the library file.', 'ERROR' );
}

require_once MADL_PLUGIN_DIR . 'includes/class-madl-ldap-handler.php';
require_once MADL_PLUGIN_DIR . 'includes/class-madl-auth.php';
require_once MADL_PLUGIN_DIR . 'includes/class-madl-ldap-data.php';

if ( is_admin() ) {
	require_once MADL_PLUGIN_DIR . 'admin/class-madl-admin-settings.php';
}

/**
 * Activation hook.
 * Creates the necessary database table.
 */
function madl_activate() {
	MADL_Logger::log( 'Plugin activation started.', 'INFO' );
	MADL_Db::create_table();
	MADL_Logger::log( 'Plugin activation completed.', 'INFO' );

    // Add a default AD profile for example purposes (optional)
    // This is commented out by default to avoid pre-populating.
    /*
    $example_profile = array(
        'profile_name' => 'Example Default AD',
        'is_default' => 1,
        'domain_identifier' => 'example.com', // Used if UPNs are like user@example.com
        'base_dn' => 'DC=example,DC=com',
        'domain_controllers' => 'dc1.example.com;dc2.example.com',
        'port' => 389,
        'use_tls' => 0,
        'use_ssl' => 0,
        'allow_self_signed' => 0,
        'network_timeout' => 5,
        'account_suffixes' => '@example.com;@staff.example.com', // For AdLdap's set_account_suffix
        'bind_username' => '', // Optional: cn=binder,cn=users,dc=example,dc=com
        'bind_password' => ''  // Optional
    );
    $db = new MADL_Db();
    $db->add_ad_profile($example_profile);
    MADL_Logger::log( 'Added example AD profile during activation.', 'INFO' );
    */
}
register_activation_hook( __FILE__, 'madl_activate' );

/**
 * Deactivation hook.
 * Can be used to clean up (e.g., remove table or settings).
 */
function madl_deactivate() {
	MADL_Logger::log( 'Plugin deactivation.', 'INFO' );
	// Optionally, remove settings or tables here if desired.
	// delete_option('madl_ad_profiles'); // Example if settings were stored as options
}
register_deactivation_hook( __FILE__, 'madl_deactivate' );

/**
 * Initialize the plugin.
 */
function madl_init() {
    MADL_Logger::log( 'madl_init called.', 'DEBUG' );
	$auth_handler = new MADL_Auth();
	$auth_handler->hooks();

	if ( is_admin() ) {
		$admin_settings = new MADL_Admin_Settings();
		$admin_settings->hooks();
	}
}
add_action( 'plugins_loaded', 'madl_init' );

/**
 * Load plugin textdomain for internationalization.
 */
function madl_load_textdomain() {
    load_plugin_textdomain( 'multi-ad-login', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'madl_load_textdomain' );

?>
