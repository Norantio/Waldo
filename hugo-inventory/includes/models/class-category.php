<?php

namespace Hugo_Inventory\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Category model — CRUD operations for inventory_categories table.
 *
 * Supports hierarchical parent/child relationships.
 */
class Category {

    private const TABLE = 'inventory_categories';

    /**
     * Get the full table name with prefix.
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Get a single category by ID.
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
     * Get a single category by slug.
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
     * List categories with optional filters.
     *
     * @param array $args {
     *     @type int    $parent_id  Filter by parent (null = all, 0 = top-level only).
     *     @type string $search     Search name field.
     *     @type string $orderby    Column to order by (default 'name').
     *     @type string $order      ASC or DESC (default 'ASC').
     *     @type int    $per_page   Results per page (default 50).
     *     @type int    $page       Page number (default 1).
     * }
     * @return array{ items: object[], total: int }
     */
    public static function list( array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'parent_id' => null,
            'search'    => '',
            'orderby'   => 'name',
            'order'     => 'ASC',
            'per_page'  => 50,
            'page'      => 1,
        ];
        $args = wp_parse_args( $args, $defaults );

        $where  = [];
        $values = [];

        if ( $args['parent_id'] !== null ) {
            if ( (int) $args['parent_id'] === 0 ) {
                $where[] = 'parent_id IS NULL';
            } else {
                $where[]  = 'parent_id = %d';
                $values[] = (int) $args['parent_id'];
            }
        }

        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = 'name LIKE %s';
            $values[] = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $allowed_orderby = [ 'name', 'created_at', 'id' ];
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
     * Get full category tree as nested structure.
     *
     * @return array Nested array of categories with 'children' key.
     */
    public static function get_tree(): array {
        global $wpdb;
        $table = self::table();
        $rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL

        return self::build_tree( $rows ?: [], null );
    }

    /**
     * Recursively build a nested tree from flat rows.
     *
     * @param object[] $rows      All category rows.
     * @param int|null $parent_id Parent to filter for.
     * @return array
     */
    private static function build_tree( array $rows, ?int $parent_id ): array {
        $branch = [];
        foreach ( $rows as $row ) {
            $row_parent = $row->parent_id ? (int) $row->parent_id : null;
            if ( $row_parent === $parent_id ) {
                $node             = (array) $row;
                $node['children'] = self::build_tree( $rows, (int) $row->id );
                $branch[]         = $node;
            }
        }
        return $branch;
    }

    /**
     * Create a new category.
     *
     * @param array $data {
     *     @type string   $name      Required.
     *     @type string   $slug      Auto-generated from name if omitted.
     *     @type int|null $parent_id Parent category ID (nullable).
     * }
     * @return int|false Inserted ID or false on failure.
     */
    public static function create( array $data ) {
        global $wpdb;

        if ( empty( $data['name'] ) ) {
            return false;
        }

        // Validate parent exists if provided.
        if ( ! empty( $data['parent_id'] ) ) {
            $parent = self::find( (int) $data['parent_id'] );
            if ( ! $parent ) {
                return false;
            }
        }

        $slug = ! empty( $data['slug'] ) ? sanitize_title( $data['slug'] ) : sanitize_title( $data['name'] );
        $slug = self::unique_slug( $slug );

        $result = $wpdb->insert(
            self::table(),
            [
                'name'      => sanitize_text_field( $data['name'] ),
                'slug'      => $slug,
                'parent_id' => ! empty( $data['parent_id'] ) ? (int) $data['parent_id'] : null,
            ],
            [ '%s', '%s', '%d' ]
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing category.
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
            $slug            = sanitize_title( $data['slug'] );
            $slug            = self::unique_slug( $slug, $id );
            $fields['slug']  = $slug;
            $formats[]       = '%s';
        }
        if ( array_key_exists( 'parent_id', $data ) ) {
            if ( ! empty( $data['parent_id'] ) ) {
                // Prevent self-referencing.
                if ( (int) $data['parent_id'] === $id ) {
                    return false;
                }
                // Prevent circular reference — ensure parent is not a descendant.
                if ( self::is_descendant( (int) $data['parent_id'], $id ) ) {
                    return false;
                }
                $fields['parent_id'] = (int) $data['parent_id'];
            } else {
                $fields['parent_id'] = null;
            }
            $formats[] = '%d';
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
     * Delete a category. Prevents deletion if assets reference it.
     *
     * @return true|\WP_Error
     */
    public static function delete( int $id ) {
        global $wpdb;

        // Check for assets using this category.
        $asset_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}inventory_assets WHERE category_id = %d",
                $id
            )
        );

        if ( $asset_count > 0 ) {
            return new \WP_Error(
                'category_has_assets',
                sprintf(
                    __( 'Cannot delete: %d asset(s) are assigned to this category.', 'hugo-inventory' ),
                    $asset_count
                )
            );
        }

        // Re-parent children to this category's parent.
        $cat = self::find( $id );
        if ( $cat ) {
            $wpdb->update(
                self::table(),
                [ 'parent_id' => $cat->parent_id ],
                [ 'parent_id' => $id ],
                [ '%d' ],
                [ '%d' ]
            );
        }

        $result = $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
        return (bool) $result ? true : new \WP_Error( 'delete_failed', __( 'Failed to delete category.', 'hugo-inventory' ) );
    }

    /**
     * Get all categories as id => name pairs (for dropdowns).
     * Indents children with dashes for visual hierarchy.
     *
     * @return array<int, string>
     */
    public static function dropdown_options(): array {
        $tree    = self::get_tree();
        $options = [];
        self::flatten_tree_for_dropdown( $tree, $options, 0 );
        return $options;
    }

    /**
     * Flatten tree into id => "— — Name" format.
     */
    private static function flatten_tree_for_dropdown( array $tree, array &$options, int $depth ): void {
        foreach ( $tree as $node ) {
            $prefix = $depth > 0 ? str_repeat( '— ', $depth ) : '';
            $options[ (int) $node['id'] ] = $prefix . $node['name'];
            if ( ! empty( $node['children'] ) ) {
                self::flatten_tree_for_dropdown( $node['children'], $options, $depth + 1 );
            }
        }
    }

    /**
     * Check if $candidate_id is a descendant of $ancestor_id.
     */
    private static function is_descendant( int $candidate_id, int $ancestor_id ): bool {
        $cat = self::find( $candidate_id );
        $visited = [];
        while ( $cat && $cat->parent_id ) {
            if ( (int) $cat->parent_id === $ancestor_id ) {
                return true;
            }
            if ( isset( $visited[ (int) $cat->parent_id ] ) ) {
                break; // Circular reference detected.
            }
            $visited[ (int) $cat->id ] = true;
            $cat = self::find( (int) $cat->parent_id );
        }
        return false;
    }

    /**
     * Generate a unique slug, appending a counter if needed.
     */
    private static function unique_slug( string $slug, int $exclude_id = 0 ): string {
        global $wpdb;
        $table    = self::table();
        $original = $slug;
        $counter  = 1;

        while ( true ) {
            $sql = $exclude_id
                ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s AND id != %d", $slug, $exclude_id ) // phpcs:ignore WordPress.DB.PreparedSQL
                : $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s", $slug ); // phpcs:ignore WordPress.DB.PreparedSQL

            $exists = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL

            if ( $exists === 0 ) {
                return $slug;
            }
            $slug = $original . '-' . $counter;
            $counter++;
        }
    }
}
