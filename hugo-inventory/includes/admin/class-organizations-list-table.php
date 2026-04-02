<?php

namespace Hugo_Inventory\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for Organizations.
 */
class Organizations_List_Table extends \WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => __( 'Organization', 'hugo-inventory' ),
            'plural'   => __( 'Organizations', 'hugo-inventory' ),
            'ajax'     => false,
        ] );
    }

    /**
     * Define columns.
     */
    public function get_columns(): array {
        return [
            'cb'            => '<input type="checkbox">',
            'name'          => __( 'Name', 'hugo-inventory' ),
            'contact_name'  => __( 'Contact', 'hugo-inventory' ),
            'contact_email' => __( 'Email', 'hugo-inventory' ),
            'is_active'     => __( 'Status', 'hugo-inventory' ),
            'created_at'    => __( 'Created', 'hugo-inventory' ),
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
        return sprintf( '<input type="checkbox" name="org_ids[]" value="%d">', $item->id );
    }

    /**
     * Name column with row actions.
     */
    public function column_name( $item ): string {
        $edit_url   = admin_url( 'admin.php?page=hugo-inventory-organizations&action=edit&id=' . $item->id );
        $delete_url = wp_nonce_url(
            admin_url( 'admin.php?page=hugo-inventory-organizations&action=delete&id=' . $item->id ),
            'hugo_inv_delete_org_' . $item->id
        );

        $actions = [
            'edit'   => '<a href="' . esc_url( $edit_url ) . '">' . __( 'Edit', 'hugo-inventory' ) . '</a>',
            'delete' => '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_attr__( 'Are you sure?', 'hugo-inventory' ) . '\')">' . __( 'Delete', 'hugo-inventory' ) . '</a>',
        ];

        return '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $item->name ) . '</a></strong>' . $this->row_actions( $actions );
    }

    /**
     * Status column.
     */
    public function column_is_active( $item ): string {
        return $item->is_active
            ? '<span style="color:green;">' . esc_html__( 'Active', 'hugo-inventory' ) . '</span>'
            : '<span style="color:#999;">' . esc_html__( 'Inactive', 'hugo-inventory' ) . '</span>';
    }

    /**
     * Default column display.
     */
    public function column_default( $item, $column_name ): string {
        return esc_html( $item->$column_name ?? '—' );
    }

    /**
     * Prepare items — query the model.
     */
    public function prepare_items(): void {
        $per_page    = 20;
        $current_page = $this->get_pagenum();
        $search      = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $orderby     = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'name';
        $order       = isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'ASC';

        $result = \Hugo_Inventory\Models\Organization::list( [
            'active_only' => false,
            'search'      => $search,
            'orderby'     => $orderby,
            'order'       => $order,
            'per_page'    => $per_page,
            'page'        => $current_page,
        ] );

        $this->items = $result['items'];

        $this->set_pagination_args( [
            'total_items' => $result['total'],
            'per_page'    => $per_page,
            'total_pages' => ceil( $result['total'] / $per_page ),
        ] );

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }
}
