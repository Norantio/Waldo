<?php
/**
 * Plugin Name:       Hugo Inventory
 * Plugin URI:        https://github.com/Osidenn/Waldo
 * Description:       Full-featured inventory and IT asset management for Hugo LLC. Barcode/QR scanning, check-in/check-out, multi-org support, custom fields, audit logging.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Hugo LLC
 * Author URI:        https://hugollc.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hugo-inventory
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'HUGO_INV_VERSION', '0.1.0' );
define( 'HUGO_INV_DB_VERSION', '1.0.0' );
define( 'HUGO_INV_PLUGIN_FILE', __FILE__ );
define( 'HUGO_INV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HUGO_INV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HUGO_INV_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Composer autoloader (QR code, barcode libraries)
$vendor_autoload = HUGO_INV_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $vendor_autoload ) ) {
    require_once $vendor_autoload;
}

// Autoloader
spl_autoload_register( function ( string $class ): void {
    $prefix = 'Hugo_Inventory\\';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    $relative = substr( $class, strlen( $prefix ) );
    // Convert namespace separators and class naming convention to file path
    // Hugo_Inventory\DB\Schema  → includes/db/class-schema.php
    // Hugo_Inventory\Models\Organization → includes/models/class-organization.php
    $parts = explode( '\\', $relative );
    $class_file = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
    $path = strtolower( implode( '/', $parts ) );

    $file = HUGO_INV_PLUGIN_DIR . 'includes/' . ( $path ? $path . '/' : '' ) . $class_file;

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// Activation / Deactivation
register_activation_hook( __FILE__, [ 'Hugo_Inventory\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Hugo_Inventory\\Deactivator', 'deactivate' ] );

// Boot the plugin
add_action( 'plugins_loaded', function (): void {
    Hugo_Inventory\Plugin::instance()->init();
} );
