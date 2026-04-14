<?php

namespace Hugo_Inventory\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for Categories.
 */
class Categories_List_Table extends \WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => __( 'Category', 'hugo-inventory' ),
            'plural'   => __( 'Categories', 'hugo-inventory' ),
            'ajax'     => false,
        ] );
    }

    /**
     * Define columns.
     */
    public function get_columns(): array {
        return [
            'cb'         => '<input type="checkbox">',
            'name'       => __( 'Name', 'hugo-inventory' ),
            'slug'       => __( 'Slug', 'hugo-inventory' ),
            'parent'     => __( 'Parent', 'hugo-inventory' ),
            'created_at' => __( 'Created', 'hugo-inventory' ),
        ];
    }

    /**
     * Sortable columns.
     */
    public function get_sortable_columns(): array {
        return [
            'name'       => [ 'name', true ],
            'created_at' => [ 'created_at', false ],
        ];
    }

    /**
     * Checkbox column.
     */
    public function column_cb( $item ): string {
        return sprintf( '<input type="checkbox" name="cat_ids[]" value="%d">', $item->id );
    }

    /**
     * Name column with row actions.
     */
    public function column_name( $item ): string {
        $edit_url   = admin_url( 'admin.php?page=hugo-inventory-categories&action=edit&id=' . $item->id );
        $delete_url = wp_nonce_url(
            admin_url( 'admin.php?page=hugo-inventory-categories&action=delete&id=' . $item->id ),
            'hugo_inv_delete_cat_' . $item->id
        );

        $actions = [
            'edit'   => '<a href="' . esc_url( $edit_url ) . '">' . __( 'Edit', 'hugo-inventory' ) . '</a>',
            'delete' => '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_attr__( 'Are you sure? Child categories will be re-parented.', 'hugo-inventory' ) . '\')">' . __( 'Delete', 'hugo-inventory' ) . '</a>',
        ];

        // Indent name based on depth.
        $depth  = isset( $item->depth ) ? (int) $item->depth : 0;
        $prefix = $depth > 0 ? str_repeat( '— ', $depth ) : '';

        return '<strong><a href="' . esc_url( $edit_url ) . '">' . $prefix . esc_html( $item->name ) . '</a></strong>' . $this->row_actions( $actions );
    }

    /**
     * Parent column.
     */
    public function column_parent( $item ): string {
        if ( empty( $item->parent_id ) ) {
            return '—';
        }
        $parent = \Hugo_Inventory\Models\Category::find( (int) $item->parent_id );
        return $parent ? esc_html( $parent->name ) : '—';
    }

    /**
     * Default column display.
     */
    public function column_default( $item, $column_name ): string {
        return esc_html( $item->$column_name ?? '—' );
    }

    /**
     * Prepare items — query the model, ordered hierarchically.
     */
    public function prepare_items(): void {
        $per_page     = 50;
        $current_page = $this->get_pagenum();
        $search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $orderby      = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'name';
        $order        = isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'ASC';

        if ( ! empty( $search ) ) {
            // Flat search results when searching.
            $result = \Hugo_Inventory\Models\Category::list( [
                'search'   => $search,
                'orderby'  => $orderby,
                'order'    => $order,
                'per_page' => $per_page,
                'page'     => $current_page,
            ] );
            $this->items = $result['items'];
            $total = $result['total'];
        } else {
            // Hierarchical display — get all, flatten with depth.
            $tree        = \Hugo_Inventory\Models\Category::get_tree();
            $flat        = [];
            $this->flatten_with_depth( $tree, $flat, 0 );
            $total       = count( $flat );
            $this->items = array_slice( $flat, ( $current_page - 1 ) * $per_page, $per_page );
        }

        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total / $per_page ),
        ] );

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    /**
     * Flatten a nested tree into a list with depth indicators.
     */
    private function flatten_with_depth( array $tree, array &$flat, int $depth ): void {
        foreach ( $tree as $node ) {
            $obj        = (object) $node;
            $obj->depth = $depth;
            unset( $obj->children );
            $flat[] = $obj;
            if ( ! empty( $node['children'] ) ) {
                $this->flatten_with_depth( $node['children'], $flat, $depth + 1 );
            }
        }
    }
}
