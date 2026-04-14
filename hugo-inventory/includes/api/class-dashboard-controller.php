<?php

namespace Hugo_Inventory\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API controller for Dashboard widgets.
 *
 * Endpoints:
 *   GET /wp-json/hugo-inventory/v1/dashboard/stats
 *   GET /wp-json/hugo-inventory/v1/dashboard/recent
 *   GET /wp-json/hugo-inventory/v1/dashboard/warranty-alerts
 */
class Dashboard_Controller {

    private const NAMESPACE = 'hugo-inventory/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/dashboard/stats', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_stats' ],
            'permission_callback' => [ $this, 'read_permission' ],
            'args'                => [
                'organization_id' => [ 'type' => 'integer' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/dashboard/recent', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_recent' ],
            'permission_callback' => [ $this, 'read_permission' ],
            'args'                => [
                'organization_id' => [ 'type' => 'integer' ],
                'limit'           => [ 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50 ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/dashboard/warranty-alerts', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_warranty_alerts' ],
            'permission_callback' => [ $this, 'read_permission' ],
            'args'                => [
                'organization_id' => [ 'type' => 'integer' ],
                'days'            => [ 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 365 ],
            ],
        ] );
    }

    /**
     * Stats: counts by status, by organization, by category.
     */
    public function get_stats( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $org_id = $request->get_param( 'organization_id' );
        $t      = $wpdb->prefix . 'inventory_assets';
        $t_org  = $wpdb->prefix . 'inventory_organizations';
        $t_cat  = $wpdb->prefix . 'inventory_categories';

        // Status counts.
        $by_status = \Hugo_Inventory\Models\Asset::count_by_status( $org_id );
        $total     = array_sum( $by_status );

        // By organization.
        $org_where = '';
        $org_params = [];
        if ( $org_id ) {
            $org_where  = 'WHERE a.organization_id = %d';
            $org_params = [ $org_id ];
        }
        $sql_org = "SELECT o.name, COUNT(*) AS cnt FROM {$t} a
                    JOIN {$t_org} o ON a.organization_id = o.id
                    {$org_where}
                    GROUP BY a.organization_id ORDER BY cnt DESC LIMIT 20";
        if ( $org_params ) {
            $sql_org = $wpdb->prepare( $sql_org, ...$org_params );
        }
        $by_org = $wpdb->get_results( $sql_org );

        // By category.
        $cat_where = '';
        $cat_params = [];
        if ( $org_id ) {
            $cat_where  = 'AND a.organization_id = %d';
            $cat_params = [ $org_id ];
        }
        $sql_cat = "SELECT c.name, COUNT(*) AS cnt FROM {$t} a
                    JOIN {$t_cat} c ON a.category_id = c.id
                    WHERE a.category_id IS NOT NULL {$cat_where}
                    GROUP BY a.category_id ORDER BY cnt DESC LIMIT 20";
        if ( $cat_params ) {
            $sql_cat = $wpdb->prepare( $sql_cat, ...$cat_params );
        }
        $by_cat = $wpdb->get_results( $sql_cat );

        return new \WP_REST_Response( [
            'total'           => $total,
            'by_status'       => $by_status,
            'by_organization' => $by_org,
            'by_category'     => $by_cat,
        ], 200 );
    }

    /**
     * Recently added assets.
     */
    public function get_recent( \WP_REST_Request $request ): \WP_REST_Response {
        $org_id = $request->get_param( 'organization_id' );
        $limit  = $request->get_param( 'limit' ) ?? 10;

        $args = [
            'orderby'  => 'created_at',
            'order'    => 'DESC',
            'per_page' => $limit,
            'page'     => 1,
        ];
        if ( $org_id ) {
            $args['organization_id'] = $org_id;
        }

        $result = \Hugo_Inventory\Models\Asset::list( $args );

        return new \WP_REST_Response( $result['items'], 200 );
    }

    /**
     * Assets with warranty expiring within N days.
     */
    public function get_warranty_alerts( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $org_id = $request->get_param( 'organization_id' );
        $days   = $request->get_param( 'days' ) ?? 30;

        $t     = $wpdb->prefix . 'inventory_assets';
        $t_org = $wpdb->prefix . 'inventory_organizations';

        $where  = 'WHERE a.warranty_expiration IS NOT NULL AND a.warranty_expiration <> ""'
                . ' AND a.warranty_expiration <= DATE_ADD(CURDATE(), INTERVAL %d DAY)'
                . ' AND a.warranty_expiration >= CURDATE()'
                . ' AND a.status NOT IN ("retired","lost")';
        $params = [ $days ];

        if ( $org_id ) {
            $where   .= ' AND a.organization_id = %d';
            $params[] = $org_id;
        }

        $sql = $wpdb->prepare(
            "SELECT a.id, a.asset_tag, a.name, a.warranty_expiration, a.status, o.name AS organization_name
             FROM {$t} a
             LEFT JOIN {$t_org} o ON a.organization_id = o.id
             {$where}
             ORDER BY a.warranty_expiration ASC
             LIMIT 50",
            ...$params
        );

        $rows = $wpdb->get_results( $sql );

        return new \WP_REST_Response( $rows, 200 );
    }

    public function read_permission(): bool {
        return is_user_logged_in();
    }
}
