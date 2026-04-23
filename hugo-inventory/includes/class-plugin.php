<?php

namespace Hugo_Inventory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin singleton.
 *
 * Registers hooks and initializes components.
 */
final class Plugin {

    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Initialize the plugin — called on `plugins_loaded`.
     */
    public function init(): void {
        // Run DB migrations if needed
        DB\Migrator::maybe_migrate();

        // Admin hooks
        if ( is_admin() ) {
            new Admin\Admin();
        }

        // Frontend shortcodes (always loaded — Oxygen renders outside is_admin).
        new Shortcodes();

        // REST API
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /**
     * Register all REST API routes.
     */
    public function register_rest_routes(): void {
        ( new API\Organizations_Controller() )->register_routes();
        ( new API\Categories_Controller() )->register_routes();
        ( new API\Locations_Controller() )->register_routes();
        ( new API\Assets_Controller() )->register_routes();
        ( new API\Dashboard_Controller() )->register_routes();
        ( new API\Users_Controller() )->register_routes();
    }
}
