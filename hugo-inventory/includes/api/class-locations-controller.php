<?php

namespace Hugo_Inventory\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API controller for Locations.
 *
 * Endpoints:
 *   GET    /wp-json/hugo-inventory/v1/locations
 *   GET    /wp-json/hugo-inventory/v1/locations/tree
 *   GET    /wp-json/hugo-inventory/v1/locations/{id}
 *   POST   /wp-json/hugo-inventory/v1/locations
 *   PUT    /wp-json/hugo-inventory/v1/locations/{id}
 *   DELETE /wp-json/hugo-inventory/v1/locations/{id}
 */
class Locations_Controller {

    private const NAMESPACE = 'hugo-inventory/v1';
    private const BASE      = 'locations';

    /**
     * Register REST routes.
     */
    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/' . self::BASE, [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_items' ],
                'permission_callback' => [ $this, 'read_permission' ],
                'args'                => $this->get_collection_params(),
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_item' ],
                'permission_callback' => [ $this, 'write_permission' ],
                'args'                => $this->get_create_params(),
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/' . self::BASE . '/tree', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_tree' ],
                'permission_callback' => [ $this, 'read_permission' ],
                'args'                => [
                    'organization_id' => [ 'type' => 'integer' ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/' . self::BASE . '/(?P<id>[\d]+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_item' ],
                'permission_callback' => [ $this, 'read_permission' ],
                'args'                => [
                    'id' => [ 'type' => 'integer', 'required' => true ],
                ],
            ],
            [
                'methods'             => 'PUT, PATCH',
                'callback'            => [ $this, 'update_item' ],
                'permission_callback' => [ $this, 'write_permission' ],
                'args'                => $this->get_update_params(),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_item' ],
                'permission_callback' => [ $this, 'write_permission' ],
                'args'                => [
                    'id' => [ 'type' => 'integer', 'required' => true ],
                ],
            ],
        ] );
    }

    /**
     * List locations (flat, paginated).
     */
    public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
        $result = \Hugo_Inventory\Models\Location::list( [
            'organization_id' => $request->get_param( 'organization_id' ),
            'parent_id'       => $request->get_param( 'parent_id' ),
            'search'          => $request->get_param( 'search' ) ?? '',
            'orderby'         => $request->get_param( 'orderby' ) ?? 'name',
            'order'           => $request->get_param( 'order' ) ?? 'ASC',
            'per_page'        => $request->get_param( 'per_page' ) ?? 50,
            'page'            => $request->get_param( 'page' ) ?? 1,
        ] );

        $response = new \WP_REST_Response( $result['items'], 200 );
        $response->header( 'X-WP-Total', $result['total'] );
        $response->header( 'X-WP-TotalPages', ceil( $result['total'] / max( 1, $request->get_param( 'per_page' ) ?? 50 ) ) );

        return $response;
    }

    /**
     * Get nested location tree, optionally filtered by org.
     */
    public function get_tree( \WP_REST_Request $request ): \WP_REST_Response {
        $org_id = $request->get_param( 'organization_id' );
        $tree   = \Hugo_Inventory\Models\Location::get_tree( $org_id ? (int) $org_id : null );
        return new \WP_REST_Response( $tree, 200 );
    }

    /**
     * Get a single location.
     */
    public function get_item( \WP_REST_Request $request ): \WP_REST_Response {
        $loc = \Hugo_Inventory\Models\Location::find( (int) $request['id'] );

        if ( ! $loc ) {
            return new \WP_REST_Response( [ 'message' => 'Location not found.' ], 404 );
        }

        return new \WP_REST_Response( $loc, 200 );
    }

    /**
     * Create a location.
     */
    public function create_item( \WP_REST_Request $request ): \WP_REST_Response {
        $id = \Hugo_Inventory\Models\Location::create( $request->get_json_params() );

        if ( ! $id ) {
            return new \WP_REST_Response( [ 'message' => 'Failed to create location.' ], 400 );
        }

        $loc = \Hugo_Inventory\Models\Location::find( $id );
        return new \WP_REST_Response( $loc, 201 );
    }

    /**
     * Update a location.
     */
    public function update_item( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request['id'];

        if ( ! \Hugo_Inventory\Models\Location::find( $id ) ) {
            return new \WP_REST_Response( [ 'message' => 'Location not found.' ], 404 );
        }

        $updated = \Hugo_Inventory\Models\Location::update( $id, $request->get_json_params() );

        if ( ! $updated ) {
            return new \WP_REST_Response( [ 'message' => 'No changes applied.' ], 400 );
        }

        return new \WP_REST_Response( \Hugo_Inventory\Models\Location::find( $id ), 200 );
    }

    /**
     * Delete a location.
     */
    public function delete_item( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request['id'];

        if ( ! \Hugo_Inventory\Models\Location::find( $id ) ) {
            return new \WP_REST_Response( [ 'message' => 'Location not found.' ], 404 );
        }

        $result = \Hugo_Inventory\Models\Location::delete( $id );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [ 'message' => $result->get_error_message() ], 409 );
        }

        return new \WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    /**
     * Read permission — any authenticated user.
     */
    public function read_permission(): bool {
        return is_user_logged_in();
    }

    /**
     * Write permission — administrators only.
     */
    public function write_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Collection query params.
     */
    private function get_collection_params(): array {
        return [
            'organization_id' => [ 'type' => 'integer' ],
            'parent_id'       => [ 'type' => 'integer' ],
            'search'          => [ 'type' => 'string', 'default' => '' ],
            'orderby'         => [ 'type' => 'string', 'default' => 'name', 'enum' => [ 'name', 'created_at', 'id' ] ],
            'order'           => [ 'type' => 'string', 'default' => 'ASC', 'enum' => [ 'ASC', 'DESC' ] ],
            'per_page'        => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            'page'            => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
        ];
    }

    /**
     * Create params.
     */
    private function get_create_params(): array {
        return [
            'name'            => [ 'type' => 'string', 'required' => true ],
            'organization_id' => [ 'type' => 'integer' ],
            'parent_id'       => [ 'type' => 'integer' ],
            'address'         => [ 'type' => 'string' ],
        ];
    }

    /**
     * Update params (all optional).
     */
    private function get_update_params(): array {
        return [
            'id'              => [ 'type' => 'integer', 'required' => true ],
            'name'            => [ 'type' => 'string' ],
            'slug'            => [ 'type' => 'string' ],
            'organization_id' => [ 'type' => 'integer' ],
            'parent_id'       => [ 'type' => 'integer' ],
            'address'         => [ 'type' => 'string' ],
        ];
    }
}
