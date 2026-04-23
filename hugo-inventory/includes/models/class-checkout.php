<?php

namespace Hugo_Inventory\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Checkout {

    private const TABLE = 'inventory_checkouts';

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Check out an asset to a user.
     *
     * @return int|\WP_Error  Checkout record ID or error.
     */
    public static function checkout( array $data ) {
        global $wpdb;

        $asset_id = absint( $data['asset_id'] ?? 0 );
        $user_id  = absint( $data['checked_out_to'] ?? 0 );
        $by       = absint( $data['checked_out_by'] ?? get_current_user_id() );

        if ( ! $asset_id || ! $user_id ) {
            return new \WP_Error( 'missing_fields', __( 'Asset and user are required.', 'hugo-inventory' ) );
        }

        // Verify asset exists and is available.
        $asset = Asset::find( $asset_id );
        if ( ! $asset ) {
            return new \WP_Error( 'not_found', __( 'Asset not found.', 'hugo-inventory' ) );
        }
        if ( $asset->status === 'checked_out' ) {
            return new \WP_Error( 'already_checked_out', __( 'This asset is already checked out.', 'hugo-inventory' ) );
        }
        if ( in_array( $asset->status, [ 'retired', 'lost' ], true ) ) {
            return new \WP_Error( 'unavailable', __( 'This asset is not available for checkout.', 'hugo-inventory' ) );
        }

        $inserted = $wpdb->insert( self::table(), [
            'asset_id'             => $asset_id,
            'checked_out_to'       => $user_id,
            'checked_out_by'       => $by,
            'checkout_date'        => current_time( 'mysql' ),
            'expected_return_date' => ! empty( $data['expected_return_date'] ) ? sanitize_text_field( $data['expected_return_date'] ) : null,
            'checkout_notes'       => ! empty( $data['checkout_notes'] ) ? sanitize_textarea_field( $data['checkout_notes'] ) : null,
        ], [ '%d', '%d', '%d', '%s', '%s', '%s' ] );

        if ( ! $inserted ) {
            return new \WP_Error( 'db_error', __( 'Failed to create checkout record.', 'hugo-inventory' ) . ' ' . $wpdb->last_error );
        }

        // Update asset status + assigned user.
        Asset::update( $asset_id, [
            'status'           => 'checked_out',
            'assigned_user_id' => $user_id,
        ] );

        return (int) $wpdb->insert_id;
    }

    /**
     * Check in (return) an asset.
     *
     * @return true|\WP_Error
     */
    public static function checkin( array $data ) {
        global $wpdb;

        $asset_id = absint( $data['asset_id'] ?? 0 );
        $by       = absint( $data['checkin_by'] ?? get_current_user_id() );

        if ( ! $asset_id ) {
            return new \WP_Error( 'missing_fields', __( 'Asset ID is required.', 'hugo-inventory' ) );
        }

        // Find the open checkout record.
        $record = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM %i WHERE asset_id = %d AND checkin_date IS NULL ORDER BY checkout_date DESC LIMIT 1",
            self::table(),
            $asset_id
        ) );

        if ( ! $record ) {
            return new \WP_Error( 'not_checked_out', __( 'No open checkout found for this asset.', 'hugo-inventory' ) );
        }

        $wpdb->update(
            self::table(),
            [
                'checkin_date'  => current_time( 'mysql' ),
                'checkin_by'    => $by,
                'checkin_notes' => ! empty( $data['checkin_notes'] ) ? sanitize_textarea_field( $data['checkin_notes'] ) : null,
            ],
            [ 'id' => $record->id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );

        // Update asset status back to available, clear assigned user.
        Asset::update( $asset_id, [
            'status'           => 'available',
            'assigned_user_id' => null,
        ] );

        return true;
    }

    /**
     * Get checkout history for an asset.
     */
    public static function history( int $asset_id, int $limit = 20 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, u1.display_name AS checked_out_to_name, u2.display_name AS checked_out_by_name, u3.display_name AS checkin_by_name
             FROM %i c
             LEFT JOIN {$wpdb->users} u1 ON c.checked_out_to = u1.ID
             LEFT JOIN {$wpdb->users} u2 ON c.checked_out_by = u2.ID
             LEFT JOIN {$wpdb->users} u3 ON c.checkin_by = u3.ID
             WHERE c.asset_id = %d
             ORDER BY c.checkout_date DESC
             LIMIT %d",
            self::table(),
            $asset_id,
            $limit
        ) );
        return $rows ?: [];
    }

    /**
     * Assets currently checked out to a specific user.
     */
    public static function checked_out_to_user( int $user_id ): array {
        global $wpdb;
        $t  = self::table();
        $ta = $wpdb->prefix . 'inventory_assets';
        $to = $wpdb->prefix . 'inventory_organizations';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, o.name AS organization_name, c.checkout_date, c.expected_return_date, c.checkout_notes
             FROM {$t} c
             INNER JOIN {$ta} a ON c.asset_id = a.id
             LEFT JOIN {$to} o ON a.organization_id = o.id
             WHERE c.checked_out_to = %d AND c.checkin_date IS NULL
             ORDER BY c.checkout_date DESC",
            $user_id
        ) );
        return $rows ?: [];
    }

    /**
     * Recent checkout/checkin activity feed.
     *
     * Returns up to $limit rows ordered by event_date DESC, each with:
     * event_date, event (checkout|checkin), asset_tag, asset_name, asset_id, user_name, organization_name.
     */
    public static function recent_activity( int $limit = 10, ?int $organization_id = null ): array {
        global $wpdb;
        $t   = self::table();
        $ta  = $wpdb->prefix . 'inventory_assets';
        $to  = $wpdb->prefix . 'inventory_organizations';

        $org_clause = '';
        $params     = [];
        if ( $organization_id !== null ) {
            $org_clause = 'AND a.organization_id = %d';
            $params[]   = (int) $organization_id; // checkout branch
            $params[]   = (int) $organization_id; // checkin branch
        }
        $params[] = $limit;

        $sql = "SELECT * FROM (
            SELECT c.checkout_date AS event_date, 'checkout' AS event,
                   a.asset_tag, a.name AS asset_name, a.id AS asset_id,
                   u.display_name AS user_name, o.name AS organization_name
            FROM {$t} c
            INNER JOIN {$ta} a ON c.asset_id = a.id
            LEFT JOIN {$to} o ON a.organization_id = o.id
            LEFT JOIN {$wpdb->users} u ON c.checked_out_to = u.ID
            WHERE c.checkout_date IS NOT NULL {$org_clause}
            UNION ALL
            SELECT c.checkin_date AS event_date, 'checkin' AS event,
                   a.asset_tag, a.name AS asset_name, a.id AS asset_id,
                   u.display_name AS user_name, o.name AS organization_name
            FROM {$t} c
            INNER JOIN {$ta} a ON c.asset_id = a.id
            LEFT JOIN {$to} o ON a.organization_id = o.id
            LEFT JOIN {$wpdb->users} u ON c.checkin_by = u.ID
            WHERE c.checkin_date IS NOT NULL {$org_clause}
        ) sub
        ORDER BY event_date DESC
        LIMIT %d";

        if ( $params ) {
            $sql = $wpdb->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL
        }

        return $wpdb->get_results( $sql ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /**
     * Overdue checkouts — assets not returned past their expected_return_date.
     *
     * Each row: asset_id, asset_tag, asset_name, organization_name, user_name,
     *           checkout_date, expected_return_date, days_overdue.
     */
    public static function overdue( int $limit = 20, ?int $organization_id = null ): array {
        global $wpdb;
        $t   = self::table();
        $ta  = $wpdb->prefix . 'inventory_assets';
        $to  = $wpdb->prefix . 'inventory_organizations';

        $where  = 'c.checkin_date IS NULL AND c.expected_return_date IS NOT NULL AND c.expected_return_date < CURDATE()';
        $params = [];
        if ( $organization_id !== null ) {
            $where   .= ' AND a.organization_id = %d';
            $params[] = (int) $organization_id;
        }
        $params[] = $limit;

        $sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
            "SELECT a.id AS asset_id, a.asset_tag, a.name AS asset_name,
                    o.name AS organization_name, u.display_name AS user_name,
                    c.checkout_date, c.expected_return_date,
                    DATEDIFF(CURDATE(), c.expected_return_date) AS days_overdue
             FROM {$t} c
             INNER JOIN {$ta} a ON c.asset_id = a.id
             LEFT JOIN {$to} o ON a.organization_id = o.id
             LEFT JOIN {$wpdb->users} u ON c.checked_out_to = u.ID
             WHERE {$where}
             ORDER BY c.expected_return_date ASC
             LIMIT %d",
            ...$params
        );

        return $wpdb->get_results( $sql ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL
    }
}
