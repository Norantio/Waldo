<?php

namespace Hugo_Inventory\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Asset model — CRUD operations for inventory_assets table.
 *
 * Auto-generates asset tags, supports multi-field lookups, and FULLTEXT search.
 */
class Asset {

    private const TABLE = 'inventory_assets';

    private const VALID_STATUSES = [
        'available',
        'checked_out',
        'in_repair',
        'retired',
        'lost',
    ];

    /**
     * Get the full table name with prefix.
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    // ── Single-record lookups ────────────────────────────────────────────

    /**
     * Get a single asset by ID, optionally with related names joined.
     *
     * @return object|null
     */
    public static function find( int $id, bool $hydrate = false ): ?object {
        global $wpdb;

        if ( $hydrate ) {
            return self::find_hydrated( $id );
        }

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", self::table(), $id )
        );
        return $row ?: null;
    }

    /**
     * Get an asset by its unique asset tag.
     *
     * @return object|null
     */
    public static function find_by_tag( string $tag ): ?object {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM %i WHERE asset_tag = %s", self::table(), $tag )
        );
        return $row ?: null;
    }

    /**
     * Get an asset by barcode value.
     *
     * @return object|null
     */
    public static function find_by_barcode( string $barcode ): ?object {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM %i WHERE barcode_value = %s", self::table(), $barcode )
        );
        return $row ?: null;
    }

    /**
     * Get an asset by serial number.
     *
     * @return object|null
     */
    public static function find_by_serial( string $serial ): ?object {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM %i WHERE serial_number = %s", self::table(), $serial )
        );
        return $row ?: null;
    }

    /**
     * Barcode/QR scan lookup — searches barcode_value, then asset_tag, then serial_number.
     *
     * @return object|null The first matching asset.
     */
    public static function lookup( string $value ): ?object {
        return self::find_by_barcode( $value )
            ?? self::find_by_tag( $value )
            ?? self::find_by_serial( $value );
    }

    /**
     * Get a single asset with org/category/location/type names joined.
     */
    private static function find_hydrated( int $id ): ?object {
        global $wpdb;
        $t = self::table();
        $p = $wpdb->prefix;

        $sql = $wpdb->prepare(
            "SELECT a.*,
                    o.name   AS organization_name,
                    c.name   AS category_name,
                    l.name   AS location_name,
                    tp.name  AS type_name
             FROM {$t} a
             LEFT JOIN {$p}inventory_organizations o  ON a.organization_id = o.id
             LEFT JOIN {$p}inventory_categories    c  ON a.category_id     = c.id
             LEFT JOIN {$p}inventory_locations     l  ON a.location_id     = l.id
             LEFT JOIN {$p}inventory_types         tp ON a.type_id         = tp.id
             WHERE a.id = %d",
            $id
        ); // phpcs:ignore WordPress.DB.PreparedSQL

        $row = $wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
        return $row ?: null;
    }

    // ── List / Search ────────────────────────────────────────────────────

    /**
     * List assets with filters, search, and pagination.
     *
     * @param array $args {
     *     @type int    $organization_id Filter by org.
     *     @type string $status          Filter by status.
     *     @type int    $category_id     Filter by category.
     *     @type int    $location_id     Filter by location.
     *     @type int    $type_id         Filter by asset type.
     *     @type int    $assigned_user_id Filter by assigned user.
     *     @type string $search          FULLTEXT / LIKE search.
     *     @type string $orderby         Column (default 'created_at').
     *     @type string $order           ASC or DESC (default 'DESC').
     *     @type int    $per_page        Results per page (default 50).
     *     @type int    $page            Page number (default 1).
     * }
     * @return array{ items: object[], total: int }
     */
    public static function list( array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'organization_id'  => null,
            'status'           => null,
            'category_id'      => null,
            'location_id'      => null,
            'type_id'          => null,
            'assigned_user_id' => null,
            'search'           => '',
            'orderby'          => 'created_at',
            'order'            => 'DESC',
            'per_page'         => 50,
            'page'             => 1,
        ];
        $args = wp_parse_args( $args, $defaults );

        $t = self::table();
        $p = $wpdb->prefix;

        $where  = [];
        $values = [];
        $select_extra = '';

        // Filters.
        if ( $args['organization_id'] !== null ) {
            $where[]  = 'a.organization_id = %d';
            $values[] = (int) $args['organization_id'];
        }
        if ( $args['status'] !== null && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
            $where[]  = 'a.status = %s';
            $values[] = $args['status'];
        }
        if ( $args['category_id'] !== null ) {
            $where[]  = 'a.category_id = %d';
            $values[] = (int) $args['category_id'];
        }
        if ( $args['location_id'] !== null ) {
            $where[]  = 'a.location_id = %d';
            $values[] = (int) $args['location_id'];
        }
        if ( $args['type_id'] !== null ) {
            $where[]  = 'a.type_id = %d';
            $values[] = (int) $args['type_id'];
        }
        if ( $args['assigned_user_id'] !== null ) {
            $where[]  = 'a.assigned_user_id = %d';
            $values[] = (int) $args['assigned_user_id'];
        }

        // Search — FULLTEXT for 3+ chars, LIKE fallback for shorter.
        if ( ! empty( $args['search'] ) ) {
            $search = trim( $args['search'] );
            if ( mb_strlen( $search ) >= 3 ) {
                $where[]      = 'MATCH(a.name, a.description, a.serial_number) AGAINST(%s IN BOOLEAN MODE)';
                $values[]     = '+' . $wpdb->esc_like( $search ) . '*';
                $select_extra = ", MATCH(a.name, a.description, a.serial_number) AGAINST('+{$wpdb->esc_like( $search )}*' IN BOOLEAN MODE) AS relevance";
            } else {
                $like     = '%' . $wpdb->esc_like( $search ) . '%';
                $where[]  = '(a.name LIKE %s OR a.serial_number LIKE %s OR a.asset_tag LIKE %s)';
                $values[] = $like;
                $values[] = $like;
                $values[] = $like;
            }
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // Whitelist orderby.
        $allowed_orderby = [
            'name'         => 'a.name',
            'asset_tag'    => 'a.asset_tag',
            'status'       => 'a.status',
            'purchase_date' => 'a.purchase_date',
            'created_at'   => 'a.created_at',
            'organization' => 'o.name',
        ];
        $order_col = $allowed_orderby[ $args['orderby'] ] ?? 'a.created_at';

        // If search with FULLTEXT and no explicit sort, order by relevance.
        if ( ! empty( $select_extra ) && $args['orderby'] === 'created_at' ) {
            $order_col = 'relevance';
        }

        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        // Count query.
        $count_sql = "SELECT COUNT(*) FROM {$t} a {$where_sql}";
        if ( $values ) {
            $count_sql = $wpdb->prepare( $count_sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL
        }
        $total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL

        // Data query — join related tables for display names.
        $offset   = max( 0, ( (int) $args['page'] - 1 ) ) * (int) $args['per_page'];

        $data_sql = "SELECT a.*,
                            o.name  AS organization_name,
                            c.name  AS category_name,
                            l.name  AS location_name,
                            tp.name AS type_name,
                            u.display_name AS assigned_user_display,
                            COALESCE(u.display_name, a.assigned_entra_name) AS assigned_display
                            {$select_extra}
                     FROM {$t} a
                     LEFT JOIN {$p}inventory_organizations o  ON a.organization_id = o.id
                     LEFT JOIN {$p}inventory_categories    c  ON a.category_id     = c.id
                     LEFT JOIN {$p}inventory_locations     l  ON a.location_id     = l.id
                     LEFT JOIN {$p}inventory_types         tp ON a.type_id         = tp.id
                     LEFT JOIN {$wpdb->users}              u  ON a.assigned_user_id = u.ID
                     {$where_sql}
                     ORDER BY {$order_col} {$order}
                     LIMIT %d OFFSET %d";
        $params = array_merge( $values, [ (int) $args['per_page'], $offset ] );
        $items  = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL

        return [
            'items' => $items ?: [],
            'total' => $total,
        ];
    }

    // ── Create ───────────────────────────────────────────────────────────

    /**
     * Create a new asset.
     *
     * @param array $data {
     *     @type int    $organization_id Required.
     *     @type string $name            Required.
     *     @type string $description
     *     @type string $serial_number
     *     @type string $barcode_value
     *     @type int    $type_id
     *     @type int    $category_id
     *     @type int    $location_id
     *     @type int    $assigned_user_id
     *     @type string $status          Default 'available'.
     *     @type string $purchase_date   Y-m-d.
     *     @type float  $purchase_cost
     *     @type string $warranty_expiration Y-m-d.
     * }
     * @return int|\WP_Error Inserted ID or error.
     */
    public static function create( array $data ) {
        global $wpdb;

        // Required fields.
        if ( empty( $data['name'] ) ) {
            return new \WP_Error( 'missing_name', __( 'Asset name is required.', 'hugo-inventory' ) );
        }
        if ( empty( $data['organization_id'] ) ) {
            return new \WP_Error( 'missing_org', __( 'Organization is required.', 'hugo-inventory' ) );
        }

        // Validate FK — organization must exist.
        if ( ! Organization::find( (int) $data['organization_id'] ) ) {
            return new \WP_Error( 'invalid_org', __( 'Selected organization does not exist.', 'hugo-inventory' ) );
        }

        // Validate optional FKs.
        if ( ! empty( $data['category_id'] ) && ! Category::find( (int) $data['category_id'] ) ) {
            return new \WP_Error( 'invalid_category', __( 'Selected category does not exist.', 'hugo-inventory' ) );
        }
        if ( ! empty( $data['location_id'] ) && ! Location::find( (int) $data['location_id'] ) ) {
            return new \WP_Error( 'invalid_location', __( 'Selected location does not exist.', 'hugo-inventory' ) );
        }

        // Validate status.
        $status = $data['status'] ?? 'available';
        if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
            $status = 'available';
        }

        // Use manually provided asset tag, or auto-generate one.
        $asset_tag = ! empty( $data['asset_tag'] )
            ? sanitize_text_field( $data['asset_tag'] )
            : self::generate_asset_tag();

        // Set barcode_value to asset_tag if not provided.
        $barcode_value = ! empty( $data['barcode_value'] )
            ? sanitize_text_field( $data['barcode_value'] )
            : $asset_tag;

        // Build insert data dynamically — skip null values for nullable FK columns
        // so MySQL uses DEFAULT NULL instead of inserting 0.
        $insert_data    = [];
        $insert_formats = [];

        // Required fields.
        $insert_data['organization_id'] = (int) $data['organization_id'];
        $insert_formats[]               = '%d';

        $insert_data['asset_tag'] = $asset_tag;
        $insert_formats[]         = '%s';

        $insert_data['name'] = sanitize_text_field( $data['name'] );
        $insert_formats[]    = '%s';

        // Optional text/string fields — safe to insert as null.
        if ( ! empty( $data['description'] ) ) {
            $insert_data['description'] = sanitize_textarea_field( $data['description'] );
            $insert_formats[]           = '%s';
        }
        if ( ! empty( $data['serial_number'] ) ) {
            $insert_data['serial_number'] = sanitize_text_field( $data['serial_number'] );
            $insert_formats[]             = '%s';
        }

        $insert_data['barcode_value'] = $barcode_value;
        $insert_formats[]             = '%s';

        // Nullable FK integer fields — only include if they have a real value.
        $type_id = ! empty( $data['type_id'] ) ? (int) $data['type_id'] : self::get_default_type_id();
        if ( $type_id ) {
            $insert_data['type_id'] = $type_id;
            $insert_formats[]       = '%d';
        }
        if ( ! empty( $data['category_id'] ) ) {
            $insert_data['category_id'] = (int) $data['category_id'];
            $insert_formats[]           = '%d';
        }
        if ( ! empty( $data['location_id'] ) ) {
            $insert_data['location_id'] = (int) $data['location_id'];
            $insert_formats[]           = '%d';
        }
        if ( ! empty( $data['assigned_user_id'] ) ) {
            $insert_data['assigned_user_id'] = (int) $data['assigned_user_id'];
            $insert_formats[]                = '%d';
        }
        if ( ! empty( $data['assigned_entra_id'] ) ) {
            $insert_data['assigned_entra_id'] = sanitize_text_field( $data['assigned_entra_id'] );
            $insert_formats[]                 = '%s';
        }
        if ( ! empty( $data['assigned_entra_name'] ) ) {
            $insert_data['assigned_entra_name'] = sanitize_text_field( $data['assigned_entra_name'] );
            $insert_formats[]                   = '%s';
        }

        $insert_data['status'] = $status;
        $insert_formats[]      = '%s';

        if ( ! empty( $data['purchase_date'] ) ) {
            $insert_data['purchase_date'] = sanitize_text_field( $data['purchase_date'] );
            $insert_formats[]             = '%s';
        }
        if ( ! empty( $data['purchase_cost'] ) ) {
            $insert_data['purchase_cost'] = (float) $data['purchase_cost'];
            $insert_formats[]             = '%f';
        }
        if ( ! empty( $data['warranty_expiration'] ) ) {
            $insert_data['warranty_expiration'] = sanitize_text_field( $data['warranty_expiration'] );
            $insert_formats[]                   = '%s';
        }

        $insert_data['created_by'] = get_current_user_id();
        $insert_formats[]          = '%d';

        $result = $wpdb->insert( self::table(), $insert_data, $insert_formats );

        if ( ! $result ) {
            return new \WP_Error(
                'insert_failed',
                __( 'Failed to create asset.', 'hugo-inventory' ) . ' DB: ' . $wpdb->last_error
            );
        }

        return (int) $wpdb->insert_id;
    }

    // ── Update ───────────────────────────────────────────────────────────

    /**
     * Update an existing asset.
     *
     * @return bool True on success.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;

        $fields  = [];
        $formats = [];

        $text_fields = [ 'name', 'serial_number', 'barcode_value', 'asset_tag' ];
        foreach ( $text_fields as $f ) {
            if ( isset( $data[ $f ] ) ) {
                $fields[ $f ] = sanitize_text_field( $data[ $f ] );
                $formats[]    = '%s';
            }
        }

        if ( isset( $data['description'] ) ) {
            $fields['description'] = sanitize_textarea_field( $data['description'] );
            $formats[]             = '%s';
        }

        if ( isset( $data['organization_id'] ) ) {
            $fields['organization_id'] = (int) $data['organization_id'];
            $formats[]                 = '%d';
        }

        $int_fields = [ 'type_id', 'category_id', 'location_id', 'assigned_user_id' ];
        foreach ( $int_fields as $f ) {
            if ( array_key_exists( $f, $data ) ) {
                $fields[ $f ] = ! empty( $data[ $f ] ) ? (int) $data[ $f ] : null;
                $formats[]    = '%d';
            }
        }

        // Entra ID string fields — allow clearing.
        $entra_fields = [ 'assigned_entra_id', 'assigned_entra_name' ];
        foreach ( $entra_fields as $f ) {
            if ( array_key_exists( $f, $data ) ) {
                $fields[ $f ] = ! empty( $data[ $f ] ) ? sanitize_text_field( $data[ $f ] ) : null;
                $formats[]    = '%s';
            }
        }

        if ( isset( $data['status'] ) && in_array( $data['status'], self::VALID_STATUSES, true ) ) {
            $fields['status'] = $data['status'];
            $formats[]        = '%s';
        }

        $date_fields = [ 'purchase_date', 'warranty_expiration' ];
        foreach ( $date_fields as $f ) {
            if ( array_key_exists( $f, $data ) ) {
                $fields[ $f ] = ! empty( $data[ $f ] ) ? sanitize_text_field( $data[ $f ] ) : null;
                $formats[]    = '%s';
            }
        }

        if ( isset( $data['purchase_cost'] ) ) {
            $fields['purchase_cost'] = $data['purchase_cost'] !== '' ? (float) $data['purchase_cost'] : null;
            $formats[]               = '%f';
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

    // ── Archive / Delete ─────────────────────────────────────────────────

    /**
     * Archive (soft-delete) an asset by setting status to 'retired'.
     */
    public static function archive( int $id ): bool {
        return self::update( $id, [ 'status' => 'retired' ] );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Generate the next unique asset tag (e.g. HUGO-000001).
     *
     * Uses an atomic counter in wp_options to prevent duplicates.
     */
    private static function generate_asset_tag(): string {
        global $wpdb;

        $settings = get_option( 'hugo_inventory_settings', [] );
        $prefix   = $settings['asset_tag_prefix'] ?? 'HUGO';
        $digits   = $settings['asset_tag_digits'] ?? 6;
        $table    = self::table();

        // Strategy: ignore the options counter entirely.
        // Query the assets table directly for the highest existing number,
        // then increment. This is always accurate regardless of cache state.
        $max_num = (int) $wpdb->get_var(
            "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(asset_tag, '-', -1) AS UNSIGNED)), 0) FROM {$table}"
        );

        $next = $max_num + 1;

        return $prefix . '-' . str_pad( (string) $next, $digits, '0', STR_PAD_LEFT );
    }

    /**
     * Get the default asset type ID (the "IT Asset" type seeded on activation).
     */
    private static function get_default_type_id(): ?int {
        global $wpdb;
        $id = $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}inventory_types WHERE is_default = 1 LIMIT 1"
        ); // phpcs:ignore WordPress.DB.PreparedSQL
        return $id ? (int) $id : null;
    }

    /**
     * Get valid status values.
     *
     * @return array<string, string> status_key => label
     */
    public static function status_options(): array {
        return [
            'available'   => __( 'Available', 'hugo-inventory' ),
            'checked_out' => __( 'Checked Out', 'hugo-inventory' ),
            'in_repair'   => __( 'In Repair', 'hugo-inventory' ),
            'retired'     => __( 'Retired', 'hugo-inventory' ),
            'lost'        => __( 'Lost', 'hugo-inventory' ),
        ];
    }

    /**
     * Get counts grouped by status, optionally filtered by org.
     *
     * @return array<string, int>
     */
    public static function count_by_status( ?int $organization_id = null ): array {
        global $wpdb;
        $t = self::table();

        $where  = '';
        $params = [];
        if ( $organization_id !== null ) {
            $where    = 'WHERE organization_id = %d';
            $params[] = $organization_id;
        }

        $sql = "SELECT status, COUNT(*) AS cnt FROM {$t} {$where} GROUP BY status";
        if ( $params ) {
            $sql = $wpdb->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL
        }

        $rows   = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
        $counts = array_fill_keys( self::VALID_STATUSES, 0 );
        foreach ( $rows as $row ) {
            $counts[ $row->status ] = (int) $row->cnt;
        }
        return $counts;
    }

    /**
     * Get asset counts grouped by organization, ordered by count descending.
     *
     * @return array  Each item: name (string), cnt (int).
     */
    public static function count_by_organization( int $limit = 15 ): array {
        global $wpdb;
        $t     = self::table();
        $t_org = $wpdb->prefix . 'inventory_organizations';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT o.name, COUNT(*) AS cnt
             FROM {$t} a
             INNER JOIN {$t_org} o ON a.organization_id = o.id
             GROUP BY a.organization_id
             ORDER BY cnt DESC
             LIMIT %d",
            $limit
        ) );

        return $rows ?: [];
    }
}
