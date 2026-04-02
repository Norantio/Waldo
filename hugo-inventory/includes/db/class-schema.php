<?php

namespace Hugo_Inventory\DB;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database schema — creates all custom tables via dbDelta().
 */
class Schema {

    /**
     * Create all plugin tables.
     *
     * Uses dbDelta() for safe creation and future column additions.
     * Called on activation and during migrations.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Organizations
        dbDelta( "CREATE TABLE {$prefix}inventory_organizations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            contact_name varchar(255) DEFAULT NULL,
            contact_email varchar(255) DEFAULT NULL,
            notes text DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) {$charset};" );

        // 2. Asset Types
        dbDelta( "CREATE TABLE {$prefix}inventory_types (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            description text DEFAULT NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) {$charset};" );

        // 3. Type Custom Field Definitions
        dbDelta( "CREATE TABLE {$prefix}inventory_type_fields (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type_id bigint(20) unsigned NOT NULL,
            field_key varchar(100) NOT NULL,
            field_label varchar(255) NOT NULL,
            field_type varchar(50) NOT NULL DEFAULT 'text',
            field_options text DEFAULT NULL,
            is_required tinyint(1) NOT NULL DEFAULT 0,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY type_id (type_id),
            UNIQUE KEY type_field (type_id,field_key)
        ) {$charset};" );

        // 4. Categories (hierarchical)
        dbDelta( "CREATE TABLE {$prefix}inventory_categories (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            parent_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY parent_id (parent_id)
        ) {$charset};" );

        // 5. Locations (hierarchical, optionally org-scoped)
        dbDelta( "CREATE TABLE {$prefix}inventory_locations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            organization_id bigint(20) unsigned DEFAULT NULL,
            parent_id bigint(20) unsigned DEFAULT NULL,
            address text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY organization_id (organization_id),
            KEY parent_id (parent_id)
        ) {$charset};" );

        // 6. Assets (core table)
        dbDelta( "CREATE TABLE {$prefix}inventory_assets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            organization_id bigint(20) unsigned NOT NULL,
            asset_tag varchar(100) NOT NULL,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            serial_number varchar(255) DEFAULT NULL,
            barcode_value varchar(255) DEFAULT NULL,
            type_id bigint(20) unsigned DEFAULT NULL,
            category_id bigint(20) unsigned DEFAULT NULL,
            location_id bigint(20) unsigned DEFAULT NULL,
            assigned_user_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'available',
            purchase_date date DEFAULT NULL,
            purchase_cost decimal(12,2) DEFAULT NULL,
            warranty_expiration date DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY asset_tag (asset_tag),
            KEY barcode_value (barcode_value),
            KEY serial_number (serial_number),
            KEY assigned_user_id (assigned_user_id),
            KEY org_status (organization_id,status),
            KEY org_type (organization_id,type_id),
            KEY org_location (organization_id,location_id),
            KEY org_category (organization_id,category_id)
        ) {$charset};" );

        // FULLTEXT index (dbDelta doesn't handle FULLTEXT well — add manually)
        self::maybe_add_fulltext_index(
            "{$prefix}inventory_assets",
            'ft_search',
            'name, description, serial_number'
        );

        // 7. Asset Meta (EAV for custom fields)
        dbDelta( "CREATE TABLE {$prefix}inventory_asset_meta (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            asset_id bigint(20) unsigned NOT NULL,
            meta_key varchar(100) NOT NULL,
            meta_value longtext DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY asset_id (asset_id),
            KEY meta_key (meta_key),
            UNIQUE KEY asset_meta (asset_id,meta_key)
        ) {$charset};" );

        // 8. Checkouts (transaction log)
        dbDelta( "CREATE TABLE {$prefix}inventory_checkouts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            asset_id bigint(20) unsigned NOT NULL,
            checked_out_to bigint(20) unsigned NOT NULL,
            checked_out_by bigint(20) unsigned NOT NULL,
            checkout_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expected_return_date date DEFAULT NULL,
            checkin_date datetime DEFAULT NULL,
            checkin_by bigint(20) unsigned DEFAULT NULL,
            checkout_notes text DEFAULT NULL,
            checkin_notes text DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY asset_id (asset_id),
            KEY checked_out_to (checked_out_to),
            KEY checkout_date (checkout_date)
        ) {$charset};" );

        // 9. User-Org mapping (Phase 2, create table now)
        dbDelta( "CREATE TABLE {$prefix}inventory_user_orgs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            organization_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_org (user_id,organization_id)
        ) {$charset};" );

        // 10. Audit Log
        dbDelta( "CREATE TABLE {$prefix}inventory_audit_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            action varchar(50) NOT NULL,
            object_type varchar(50) NOT NULL,
            object_id bigint(20) unsigned NOT NULL,
            field_name varchar(100) DEFAULT NULL,
            old_value text DEFAULT NULL,
            new_value text DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY object_type_id (object_type,object_id),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset};" );
    }

    /**
     * Add a FULLTEXT index if it doesn't already exist.
     *
     * dbDelta() doesn't support FULLTEXT indexes natively.
     */
    private static function maybe_add_fulltext_index( string $table, string $index_name, string $columns ): void {
        global $wpdb;

        $index_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = %s AND table_name = %s AND index_name = %s",
                DB_NAME,
                $table,
                $index_name
            )
        );

        if ( ! $index_exists ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD FULLTEXT `{$index_name}` ({$columns})" ); // phpcs:ignore WordPress.DB.PreparedSQL
        }
    }
}
