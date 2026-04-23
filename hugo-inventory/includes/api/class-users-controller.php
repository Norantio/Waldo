<?php

namespace Hugo_Inventory\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API controller for user search — unified WordPress + Entra ID lookup.
 *
 * Endpoint:
 *   GET /wp-json/hugo-inventory/v1/users/search?q={term}
 */
class Users_Controller {

    private const NAMESPACE = 'hugo-inventory/v1';

    /**
     * Register REST routes.
     */
    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/users/search', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'search' ],
                'permission_callback' => [ $this, 'permission_check' ],
                'args'                => [
                    'q' => [
                        'type'     => 'string',
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ] );
    }

    /**
     * Search for users across WordPress and Entra ID.
     */
    public function search( \WP_REST_Request $request ): \WP_REST_Response {
        $query   = trim( $request->get_param( 'q' ) );
        $results = [];

        // WordPress users — always search.
        $wp_users = get_users( [
            'search'         => '*' . $query . '*',
            'search_columns' => [ 'user_login', 'user_email', 'user_nicename', 'display_name' ],
            'fields'         => [ 'ID', 'display_name', 'user_email' ],
            'orderby'        => 'display_name',
            'number'         => 10,
        ] );

        foreach ( $wp_users as $u ) {
            $results[] = [
                'id'     => (int) $u->ID,
                'text'   => $u->display_name,
                'email'  => $u->user_email,
                'source' => 'wp',
            ];
        }

        // Entra ID users — search if configured.
        $entra = \Hugo_Inventory\Services\Entra_Client::from_settings();
        if ( $entra && mb_strlen( $query ) >= 2 ) {
            $entra_users = $entra->search_users( $query, 10 );
            foreach ( $entra_users as $eu ) {
                $results[] = [
                    'id'     => $eu['id'],  // Entra Object ID (UUID).
                    'text'   => $eu['displayName'],
                    'email'  => $eu['mail'] ?? $eu['userPrincipalName'] ?? '',
                    'detail' => trim( ( $eu['jobTitle'] ?? '' ) . ( ! empty( $eu['department'] ) ? ' — ' . $eu['department'] : '' ) ),
                    'source' => 'entra',
                ];
            }
        }

        return new \WP_REST_Response( [ 'results' => $results ], 200 );
    }

    /**
     * Permission check — administrators only.
     */
    public function permission_check(): bool {
        return current_user_can( 'manage_options' );
    }
}
