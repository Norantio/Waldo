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
            // '1.1.0' => [ self::class, 'migrate_to_1_1_0' ],
        ];
    }
}
