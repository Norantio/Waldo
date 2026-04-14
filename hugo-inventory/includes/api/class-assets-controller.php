<?php

namespace Hugo_Inventory\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API controller for Assets.
 *
 * Endpoints:
 *   GET    /wp-json/hugo-inventory/v1/assets
 *   GET    /wp-json/hugo-inventory/v1/assets/{id}
 *   POST   /wp-json/hugo-inventory/v1/assets
 *   PUT    /wp-json/hugo-inventory/v1/assets/{id}
 *   DELETE /wp-json/hugo-inventory/v1/assets/{id}
 *   GET    /wp-json/hugo-inventory/v1/assets/lookup?barcode={value}
 *   GET    /wp-json/hugo-inventory/v1/assets/lookup?serial={value}
 */
class Assets_Controller {

    private const NAMESPACE = 'hugo-inventory/v1';
    private const BASE      = 'assets';

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

        register_rest_route( self::NAMESPACE, '/' . self::BASE . '/lookup', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'lookup' ],
                'permission_callback' => [ $this, 'read_permission' ],
                'args'                => [
                    'barcode' => [ 'type' => 'string' ],
                    'serial'  => [ 'type' => 'string' ],
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
     * List assets (paginated, filterable, searchable).
     */
    public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
        $result = \Hugo_Inventory\Models\Asset::list( [
            'organization_id'  => $request->get_param( 'organization_id' ),
            'status'           => $request->get_param( 'status' ),
            'category_id'      => $request->get_param( 'category_id' ),
            'location_id'      => $request->get_param( 'location_id' ),
            'type_id'          => $request->get_param( 'type_id' ),
            'assigned_user_id' => $request->get_param( 'assigned_user_id' ),
            'search'           => $request->get_param( 'search' ) ?? '',
            'orderby'          => $request->get_param( 'orderby' ) ?? 'created_at',
            'order'            => $request->get_param( 'order' ) ?? 'DESC',
            'per_page'         => $request->get_param( 'per_page' ) ?? 50,
            'page'             => $request->get_param( 'page' ) ?? 1,
        ] );

        $response = new \WP_REST_Response( $result['items'], 200 );
        $response->header( 'X-WP-Total', $result['total'] );
        $response->header( 'X-WP-TotalPages', ceil( $result['total'] / max( 1, $request->get_param( 'per_page' ) ?? 50 ) ) );

        return $response;
    }

    /**
     * Get a single asset with related data.
     */
    public function get_item( \WP_REST_Request $request ): \WP_REST_Response {
        $asset = \Hugo_Inventory\Models\Asset::find( (int) $request['id'], true );

        if ( ! $asset ) {
            return new \WP_REST_Response( [ 'message' => 'Asset not found.' ], 404 );
        }

        return new \WP_REST_Response( $asset, 200 );
    }

    /**
     * Create an asset.
     */
    public function create_item( \WP_REST_Request $request ): \WP_REST_Response {
        $result = \Hugo_Inventory\Models\Asset::create( $request->get_json_params() );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
        }

        $asset = \Hugo_Inventory\Models\Asset::find( $result, true );
        return new \WP_REST_Response( $asset, 201 );
    }

    /**
     * Update an asset.
     */
    public function update_item( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request['id'];

        if ( ! \Hugo_Inventory\Models\Asset::find( $id ) ) {
            return new \WP_REST_Response( [ 'message' => 'Asset not found.' ], 404 );
        }

        $updated = \Hugo_Inventory\Models\Asset::update( $id, $request->get_json_params() );

        if ( ! $updated ) {
            return new \WP_REST_Response( [ 'message' => 'No changes applied.' ], 400 );
        }

        return new \WP_REST_Response( \Hugo_Inventory\Models\Asset::find( $id, true ), 200 );
    }

    /**
     * Delete (archive) an asset.
     */
    public function delete_item( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request['id'];

        if ( ! \Hugo_Inventory\Models\Asset::find( $id ) ) {
            return new \WP_REST_Response( [ 'message' => 'Asset not found.' ], 404 );
        }

        \Hugo_Inventory\Models\Asset::archive( $id );
        return new \WP_REST_Response( [ 'archived' => true ], 200 );
    }

    /**
     * Barcode / serial lookup — scan-to-find endpoint.
     *
     * Lookup priority: barcode_value → asset_tag → serial_number.
     */
    public function lookup( \WP_REST_Request $request ): \WP_REST_Response {
        $barcode = $request->get_param( 'barcode' );
        $serial  = $request->get_param( 'serial' );

        $asset = null;

        if ( $barcode ) {
            $asset = \Hugo_Inventory\Models\Asset::lookup( $barcode );
        } elseif ( $serial ) {
            $asset = \Hugo_Inventory\Models\Asset::find_by_serial( $serial );
        }

        if ( $asset ) {
            // Hydrate with related names.
            $hydrated = \Hugo_Inventory\Models\Asset::find( (int) $asset->id, true );

            return new \WP_REST_Response( [
                'found' => true,
                'asset' => [
                    'id'                   => (int) $hydrated->id,
                    'asset_tag'            => $hydrated->asset_tag,
                    'name'                 => $hydrated->name,
                    'organization_name'    => $hydrated->organization_name ?? '',
                    'category_name'        => $hydrated->category_name ?? '',
                    'location_name'        => $hydrated->location_name ?? '',
                    'type_name'            => $hydrated->type_name ?? '',
                    'status'               => $hydrated->status,
                    'serial_number'        => $hydrated->serial_number ?? '',
                    'assigned_user_display' => $hydrated->assigned_user_id
                        ? ( get_userdata( (int) $hydrated->assigned_user_id )->display_name ?? '' )
                        : '',
                    'purchase_date'        => $hydrated->purchase_date ?? '',
                    'purchase_cost'        => $hydrated->purchase_cost ?? '',
                    'warranty_expiration'  => $hydrated->warranty_expiration ?? '',
                    'description'          => $hydrated->description ?? '',
                ],
            ], 200 );
        }

        $scanned = $barcode ?: $serial ?: '';
        return new \WP_REST_Response( [
            'found'        => false,
            'scanned_value' => $scanned,
            'create_url'   => admin_url( 'admin.php?page=hugo-inventory-assets&action=add&barcode=' . urlencode( $scanned ) ),
        ], 200 );
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
            'organization_id'  => [ 'type' => 'integer' ],
            'status'           => [ 'type' => 'string', 'enum' => [ 'available', 'checked_out', 'in_repair', 'retired', 'lost' ] ],
            'category_id'      => [ 'type' => 'integer' ],
            'location_id'      => [ 'type' => 'integer' ],
            'type_id'          => [ 'type' => 'integer' ],
            'assigned_user_id' => [ 'type' => 'integer' ],
            'search'           => [ 'type' => 'string', 'default' => '' ],
            'orderby'          => [ 'type' => 'string', 'default' => 'created_at', 'enum' => [ 'name', 'asset_tag', 'status', 'purchase_date', 'created_at', 'organization' ] ],
            'order'            => [ 'type' => 'string', 'default' => 'DESC', 'enum' => [ 'ASC', 'DESC' ] ],
            'per_page'         => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            'page'             => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
        ];
    }

    /**
     * Create params.
     */
    private function get_create_params(): array {
        return [
            'name'               => [ 'type' => 'string', 'required' => true ],
            'organization_id'    => [ 'type' => 'integer', 'required' => true ],
            'description'        => [ 'type' => 'string' ],
            'serial_number'      => [ 'type' => 'string' ],
            'barcode_value'      => [ 'type' => 'string' ],
            'type_id'            => [ 'type' => 'integer' ],
            'category_id'        => [ 'type' => 'integer' ],
            'location_id'        => [ 'type' => 'integer' ],
            'assigned_user_id'   => [ 'type' => 'integer' ],
            'status'             => [ 'type' => 'string', 'enum' => [ 'available', 'checked_out', 'in_repair', 'retired', 'lost' ] ],
            'purchase_date'      => [ 'type' => 'string', 'format' => 'date' ],
            'purchase_cost'      => [ 'type' => 'number' ],
            'warranty_expiration' => [ 'type' => 'string', 'format' => 'date' ],
        ];
    }

    /**
     * Update params (all optional).
     */
    private function get_update_params(): array {
        return [
            'id'                 => [ 'type' => 'integer', 'required' => true ],
            'name'               => [ 'type' => 'string' ],
            'organization_id'    => [ 'type' => 'integer' ],
            'description'        => [ 'type' => 'string' ],
            'serial_number'      => [ 'type' => 'string' ],
            'barcode_value'      => [ 'type' => 'string' ],
            'type_id'            => [ 'type' => 'integer' ],
            'category_id'        => [ 'type' => 'integer' ],
            'location_id'        => [ 'type' => 'integer' ],
            'assigned_user_id'   => [ 'type' => 'integer' ],
            'status'             => [ 'type' => 'string', 'enum' => [ 'available', 'checked_out', 'in_repair', 'retired', 'lost' ] ],
            'purchase_date'      => [ 'type' => 'string', 'format' => 'date' ],
            'purchase_cost'      => [ 'type' => 'number' ],
            'warranty_expiration' => [ 'type' => 'string', 'format' => 'date' ],
        ];
    }
}
