<?php
/**
 * Clean uninstall handler.
 *
 * Drops all custom tables and removes plugin options.
 * Only runs when the plugin is deleted via WP admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop all plugin tables
$tables = [
    $wpdb->prefix . 'inventory_audit_log',
    $wpdb->prefix . 'inventory_checkouts',
    $wpdb->prefix . 'inventory_asset_meta',
    $wpdb->prefix . 'inventory_assets',
    $wpdb->prefix . 'inventory_type_fields',
    $wpdb->prefix . 'inventory_types',
    $wpdb->prefix . 'inventory_locations',
    $wpdb->prefix . 'inventory_categories',
    $wpdb->prefix . 'inventory_user_orgs',
    $wpdb->prefix . 'inventory_organizations',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// Remove plugin options
delete_option( 'hugo_inventory_settings' );
delete_option( 'hugo_inventory_db_version' );
delete_option( 'hugo_inventory_asset_tag_counter' );
