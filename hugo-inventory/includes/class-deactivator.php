<?php

namespace Hugo_Inventory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deactivation handler.
 *
 * Cleans up transients and scheduled events. Does NOT drop tables.
 */
class Deactivator {

    public static function deactivate(): void {
        // Clear any scheduled cron events
        wp_clear_scheduled_hook( 'hugo_inventory_daily_maintenance' );

        flush_rewrite_rules();
    }
}
