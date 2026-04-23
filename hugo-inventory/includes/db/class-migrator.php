<?php

namespace Hugo_Inventory\DB;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles database version tracking and sequential migrations.
 */
class Migrator {

    /**
     * Run migrations if DB version is behind code version.
     */
    public static function maybe_migrate(): void {
        $db_version   = get_option( 'hugo_inventory_db_version', '0.0.0' );
        $code_version = HUGO_INV_DB_VERSION;

        if ( version_compare( $db_version, $code_version, '>=' ) ) {
            return;
        }

        $migrations = self::get_migrations();

        foreach ( $migrations as $version => $callback ) {
            if ( version_compare( $db_version, $version, '<' ) ) {
                call_user_func( $callback );
            }
        }

        // Re-run schema to pick up any column additions via dbDelta.
        Schema::create_tables();

        update_option( 'hugo_inventory_db_version', $code_version );
    }

    /**
     * List of sequential migrations keyed by target version.
     *
     * To add a migration:
     * 1. Bump HUGO_INV_DB_VERSION in hugo-inventory.php
     * 2. Add an entry here: 'x.y.z' => [ self::class, 'migrate_to_x_y_z' ]
     * 3. Create the static method with raw SQL or $wpdb calls
     *
     * @return array<string, callable>
     */
    private static function get_migrations(): array {
        return [
            '1.1.0' => [ self::class, 'migrate_to_1_1_0' ],
        ];
    }

    /**
     * Migration 1.1.0 — Add Entra ID columns to assets table.
     */
    private static function migrate_to_1_1_0(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'inventory_assets';

        $col = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'assigned_entra_id'" );
        if ( ! $col ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN assigned_entra_id varchar(100) DEFAULT NULL AFTER assigned_user_id" );
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN assigned_entra_name varchar(255) DEFAULT NULL AFTER assigned_entra_id" );
            $wpdb->query( "ALTER TABLE {$table} ADD INDEX assigned_entra_id (assigned_entra_id)" );
        }
    }
}
