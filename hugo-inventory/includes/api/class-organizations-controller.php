<?php

namespace Hugo_Inventory\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API controller for Organizations.
 *
 * Endpoints:
 *   GET    /wp-json/hugo-inventory/v1/organizations
 *   GET    /wp-json/hugo-inventory/v1/organizations/{id}
 *   POST   /wp-json/hugo-inventory/v1/organizations
 *   PUT    /wp-json/hugo-inventory/v1/organizations/{id}
 *   DELETE /wp-json/hugo-inventory/v1/organizations/{id}
 */
class Organizations_Controller {

    private const NAMESPACE = 'hugo-inventory/v1';
    private const BASE      = 'organizations';

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
     * List organizations.
     */
    public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
        $result = \Hugo_Inventory\Models\Organization::list( [
            'active_only' => $request->get_param( 'active_only' ) ?? true,
            'search'      => $request->get_param( 'search' ) ?? '',
            'orderby'     => $request->get_param( 'orderby' ) ?? 'name',
            'order'       => $request->get_param( 'order' ) ?? 'ASC',
            'per_page'    => $request->get_param( 'per_page' ) ?? 50,
            'page'        => $request->get_param( 'page' ) ?? 1,
        ] );

        $response = new \WP_REST_Response( $result['items'], 200 );
        $response->header( 'X-WP-Total', $result['total'] );
        $response->header( 'X-WP-TotalPages', ceil( $result['total'] / max( 1, $request->get_param( 'per_page' ) ?? 50 ) ) );

        return $response;
    }

    /**
     * Get a single organization.
     */
    public function get_item( \WP_REST_Request $request ): \WP_REST_Response {
        $org = \Hugo_Inventory\Models\Organization::find( (int) $request['id'] );

        if ( ! $org ) {
            return new \WP_REST_Response( [ 'message' => 'Organization not found.' ], 404 );
        }

        return new \WP_REST_Response( $org, 200 );
    }

    /**
     * Create an organization.
     */
    public function create_item( \WP_REST_Request $request ): \WP_REST_Response {
        $id = \Hugo_Inventory\Models\Organization::create( $request->get_json_params() );

        if ( ! $id ) {
            return new \WP_REST_Response( [ 'message' => 'Failed to create organization.' ], 400 );
        }

        $org = \Hugo_Inventory\Models\Organization::find( $id );
        return new \WP_REST_Response( $org, 201 );
    }

    /**
     * Update an organization.
     */
    public function update_item( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request['id'];

        if ( ! \Hugo_Inventory\Models\Organization::find( $id ) ) {
            return new \WP_REST_Response( [ 'message' => 'Organization not found.' ], 404 );
        }

        $updated = \Hugo_Inventory\Models\Organization::update( $id, $request->get_json_params() );

        if ( ! $updated ) {
            return new \WP_REST_Response( [ 'message' => 'No changes applied.' ], 400 );
        }

        return new \WP_REST_Response( \Hugo_Inventory\Models\Organization::find( $id ), 200 );
    }

    /**
     * Delete an organization.
     */
    public function delete_item( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request['id'];

        if ( ! \Hugo_Inventory\Models\Organization::find( $id ) ) {
            return new \WP_REST_Response( [ 'message' => 'Organization not found.' ], 404 );
        }

        $result = \Hugo_Inventory\Models\Organization::delete( $id );

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
            'active_only' => [ 'type' => 'boolean', 'default' => true ],
            'search'      => [ 'type' => 'string', 'default' => '' ],
            'orderby'     => [ 'type' => 'string', 'default' => 'name', 'enum' => [ 'name', 'created_at', 'id' ] ],
            'order'       => [ 'type' => 'string', 'default' => 'ASC', 'enum' => [ 'ASC', 'DESC' ] ],
            'per_page'    => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            'page'        => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
        ];
    }

    /**
     * Create params.
     */
    private function get_create_params(): array {
        return [
            'name'          => [ 'type' => 'string', 'required' => true ],
            'contact_name'  => [ 'type' => 'string' ],
            'contact_email' => [ 'type' => 'string', 'format' => 'email' ],
            'notes'         => [ 'type' => 'string' ],
            'is_active'     => [ 'type' => 'boolean', 'default' => true ],
        ];
    }

    /**
     * Update params (all optional).
     */
    private function get_update_params(): array {
        return [
            'id'            => [ 'type' => 'integer', 'required' => true ],
            'name'          => [ 'type' => 'string' ],
            'slug'          => [ 'type' => 'string' ],
            'contact_name'  => [ 'type' => 'string' ],
            'contact_email' => [ 'type' => 'string', 'format' => 'email' ],
            'notes'         => [ 'type' => 'string' ],
            'is_active'     => [ 'type' => 'boolean' ],
        ];
    }
}
