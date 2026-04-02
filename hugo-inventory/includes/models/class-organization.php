<?php

namespace Hugo_Inventory\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Organization model — CRUD operations for inventory_organizations table.
 */
class Organization {

    private const TABLE = 'inventory_organizations';

    /**
     * Get the full table name with prefix.
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Get a single organization by ID.
     *
     * @return object|null
     */
    public static function find( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", self::table(), $id )
        );
        return $row ?: null;
    }

    /**
     * Get a single organization by slug.
     *
     * @return object|null
     */
    public static function find_by_slug( string $slug ): ?object {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM %i WHERE slug = %s", self::table(), $slug )
        );
        return $row ?: null;
    }

    /**
     * List organizations with optional filters.
     *
     * @param array $args {
     *     @type bool   $active_only  Only active orgs (default true).
     *     @type string $search       Search name/contact fields.
     *     @type string $orderby      Column to order by (default 'name').
     *     @type string $order        ASC or DESC (default 'ASC').
     *     @type int    $per_page     Results per page (default 50).
     *     @type int    $page         Page number (default 1).
     * }
     * @return array{ items: object[], total: int }
     */
    public static function list( array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'active_only' => true,
            'search'      => '',
            'orderby'     => 'name',
            'order'       => 'ASC',
            'per_page'    => 50,
            'page'        => 1,
        ];
        $args = wp_parse_args( $args, $defaults );

        $where  = [];
        $values = [];

        if ( $args['active_only'] ) {
            $where[] = 'is_active = 1';
        }

        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(name LIKE %s OR contact_name LIKE %s OR contact_email LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // Whitelist orderby columns.
        $allowed_orderby = [ 'name', 'created_at', 'updated_at', 'id' ];
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'name';
        $order           = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        $table = self::table();

        // Count query.
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        if ( $values ) {
            $count_sql = $wpdb->prepare( $count_sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL
        }
        $total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL

        // Data query.
        $offset   = max( 0, ( (int) $args['page'] - 1 ) ) * (int) $args['per_page'];
        $data_sql = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params   = array_merge( $values, [ (int) $args['per_page'], $offset ] );
        $items    = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL

        return [
            'items' => $items ?: [],
            'total' => $total,
        ];
    }

    /**
     * Create a new organization.
     *
     * @param array $data {
     *     @type string $name          Required.
     *     @type string $slug          Auto-generated from name if omitted.
     *     @type string $contact_name
     *     @type string $contact_email
     *     @type string $notes
     *     @type bool   $is_active     Default true.
     * }
     * @return int|false Inserted ID or false on failure.
     */
    public static function create( array $data ) {
        global $wpdb;

        if ( empty( $data['name'] ) ) {
            return false;
        }

        $slug = ! empty( $data['slug'] ) ? sanitize_title( $data['slug'] ) : sanitize_title( $data['name'] );

        // Ensure unique slug.
        $slug = self::unique_slug( $slug );

        $result = $wpdb->insert(
            self::table(),
            [
                'name'          => sanitize_text_field( $data['name'] ),
                'slug'          => $slug,
                'contact_name'  => isset( $data['contact_name'] ) ? sanitize_text_field( $data['contact_name'] ) : null,
                'contact_email' => isset( $data['contact_email'] ) ? sanitize_email( $data['contact_email'] ) : null,
                'notes'         => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
                'is_active'     => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d' ]
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing organization.
     *
     * @return bool True on success.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;

        $fields  = [];
        $formats = [];

        if ( isset( $data['name'] ) ) {
            $fields['name'] = sanitize_text_field( $data['name'] );
            $formats[]      = '%s';
        }
        if ( isset( $data['slug'] ) ) {
            $slug = sanitize_title( $data['slug'] );
            // Ensure it's unique excluding current record.
            $slug            = self::unique_slug( $slug, $id );
            $fields['slug']  = $slug;
            $formats[]       = '%s';
        }
        if ( isset( $data['contact_name'] ) ) {
            $fields['contact_name'] = sanitize_text_field( $data['contact_name'] );
            $formats[]              = '%s';
        }
        if ( isset( $data['contact_email'] ) ) {
            $fields['contact_email'] = sanitize_email( $data['contact_email'] );
            $formats[]               = '%s';
        }
        if ( isset( $data['notes'] ) ) {
            $fields['notes'] = sanitize_textarea_field( $data['notes'] );
            $formats[]       = '%s';
        }
        if ( isset( $data['is_active'] ) ) {
            $fields['is_active'] = (int) (bool) $data['is_active'];
            $formats[]           = '%d';
        }

        if ( empty( $fields ) ) {
            return false;
        }

        $result = $wpdb->update(
            self::table(),
            $fields,
            [ 'id' => $id ],
            $formats,
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Soft-delete by deactivating. Hard-delete only if no assets reference it.
     *
     * @return bool|string True on success, error message string on failure.
     */
    public static function delete( int $id ) {
        global $wpdb;

        $asset_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}inventory_assets WHERE organization_id = %d",
                $id
            )
        );

        if ( $asset_count > 0 ) {
            // Soft-delete: deactivate instead.
            return self::update( $id, [ 'is_active' => false ] );
        }

        $result = $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
        return (bool) $result;
    }

    /**
     * Get all active organizations as id => name pairs (for dropdowns).
     *
     * @return array<int, string>
     */
    public static function dropdown_options(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, name FROM " . self::table() . " WHERE is_active = 1 ORDER BY name ASC"
        );
        $options = [];
        foreach ( $rows as $row ) {
            $options[ (int) $row->id ] = $row->name;
        }
        return $options;
    }

    /**
     * Generate a unique slug, optionally excluding a specific ID.
     */
    private static function unique_slug( string $slug, int $exclude_id = 0 ): string {
        global $wpdb;

        $table    = self::table();
        $original = $slug;
        $counter  = 1;

        while ( true ) {
            $sql = $exclude_id
                ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s AND id != %d", $slug, $exclude_id )
                : $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s", $slug );

            if ( 0 === (int) $wpdb->get_var( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
                return $slug;
            }

            $slug = $original . '-' . $counter;
            $counter++;
        }
    }
}
