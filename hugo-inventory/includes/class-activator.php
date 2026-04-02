<?php

namespace Hugo_Inventory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Activation handler.
 *
 * Creates database tables and seeds default data.
 */
class Activator {

    public static function activate(): void {
        // Create / update tables
        DB\Schema::create_tables();

        // Store DB version
        update_option( 'hugo_inventory_db_version', HUGO_INV_DB_VERSION );

        // Seed default asset type if none exists
        self::seed_defaults();

        // Initialize asset tag counter
        if ( get_option( 'hugo_inventory_asset_tag_counter' ) === false ) {
            update_option( 'hugo_inventory_asset_tag_counter', 0 );
        }

        // Initialize default settings
        if ( get_option( 'hugo_inventory_settings' ) === false ) {
            update_option( 'hugo_inventory_settings', self::default_settings() );
        }

        // Flush rewrite rules for REST routes
        flush_rewrite_rules();
    }

    private static function seed_defaults(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'inventory_types';

        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE slug = %s", 'it-asset' )
        );

        if ( ! $exists ) {
            $wpdb->insert( $table, [
                'name'        => 'IT Asset',
                'slug'        => 'it-asset',
                'description' => 'Default asset type for IT equipment',
                'is_default'  => 1,
                'created_at'  => current_time( 'mysql', true ),
            ] );
        }
    }

    public static function default_settings(): array {
        return [
            'asset_tag_prefix'       => 'HUGO',
            'asset_tag_digits'       => 6,
            'default_organization'   => 0,
            'scanner_threshold_ms'   => 50,
            'scanner_min_length'     => 4,
            'qr_payload_format'      => 'tag', // 'tag' or 'url'
            'default_barcode_format' => 'qr',  // 'qr' or 'code128'
            'label_width_mm'         => 50,
            'label_height_mm'        => 25,
            'labels_per_row'         => 4,
            'label_fields'           => [ 'tag', 'name', 'org' ],
            'items_per_page'         => 50,
            'audio_feedback'         => true,
            'visual_feedback'        => 'green_flash',
        ];
    }
}
