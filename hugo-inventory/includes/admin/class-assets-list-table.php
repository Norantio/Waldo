<?php

namespace Hugo_Inventory\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for Assets — the primary inventory view.
 */
class Assets_List_Table extends \WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => __( 'Asset', 'hugo-inventory' ),
            'plural'   => __( 'Assets', 'hugo-inventory' ),
            'ajax'     => false,
        ] );
    }

    /**
     * Define columns.
     */
    public function get_columns(): array {
        return [
            'cb'            => '<input type="checkbox">',
            'asset_tag'     => __( 'Asset Tag', 'hugo-inventory' ),
            'name'          => __( 'Name', 'hugo-inventory' ),
            'organization'  => __( 'Organization', 'hugo-inventory' ),
            'serial_number' => __( 'Serial Number', 'hugo-inventory' ),
            'location'      => __( 'Location', 'hugo-inventory' ),
            'category'      => __( 'Category', 'hugo-inventory' ),
            'status'        => __( 'Status', 'hugo-inventory' ),
            'assigned_user' => __( 'Assigned To', 'hugo-inventory' ),
        ];
    }

    /**
     * Sortable columns.
     */
    public function get_sortable_columns(): array {
        return [
            'asset_tag'    => [ 'asset_tag', false ],
            'name'         => [ 'name', true ],
            'status'       => [ 'status', false ],
            'organization' => [ 'organization', false ],
        ];
    }

    /**
     * Bulk actions.
     */
    public function get_bulk_actions(): array {
        $statuses = \Hugo_Inventory\Models\Asset::status_options();
        $actions  = [];
        foreach ( $statuses as $key => $label ) {
            $actions[ 'status_' . $key ] = sprintf( __( 'Set Status: %s', 'hugo-inventory' ), $label );
        }
        $actions['archive']      = __( 'Archive (Retire)', 'hugo-inventory' );
        $actions['print_labels'] = __( 'Print Labels', 'hugo-inventory' );
        return $actions;
    }

    /**
     * Checkbox column.
     */
    public function column_cb( $item ): string {
        return sprintf( '<input type="checkbox" name="asset[]" value="%d">', $item->id );
    }

    /**
     * Asset tag column.
     */
    public function column_asset_tag( $item ): string {
        return '<code>' . esc_html( $item->asset_tag ) . '</code>';
    }

    /**
     * Name column with row actions.
     */
    public function column_name( $item ): string {
        $edit_url = admin_url( 'admin.php?page=hugo-inventory-assets&action=edit&id=' . $item->id );
        $archive_url = wp_nonce_url(
            admin_url( 'admin.php?page=hugo-inventory-assets&action=archive&id=' . $item->id ),
            'hugo_inv_archive_asset_' . $item->id
        );

        $print_url = wp_nonce_url(
            admin_url( 'admin-ajax.php?action=hugo_inv_print_labels&ids=' . $item->id ),
            'hugo_inv_print_labels'
        );

        $actions = [
            'edit'        => '<a href="' . esc_url( $edit_url ) . '">' . __( 'Edit', 'hugo-inventory' ) . '</a>',
            'print_label' => '<a href="' . esc_url( $print_url ) . '" target="_blank">' . __( 'Print Label', 'hugo-inventory' ) . '</a>',
            'archive'     => '<a href="' . esc_url( $archive_url ) . '" onclick="return confirm(\'' . esc_attr__( 'Archive this asset?', 'hugo-inventory' ) . '\')">' . __( 'Archive', 'hugo-inventory' ) . '</a>',
        ];

        return '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $item->name ) . '</a></strong>' . $this->row_actions( $actions );
    }

    /**
     * Organization column.
     */
    public function column_organization( $item ): string {
        return esc_html( $item->organization_name ?? '—' );
    }

    /**
     * Location column.
     */
    public function column_location( $item ): string {
        return esc_html( $item->location_name ?? '—' );
    }

    /**
     * Category column.
     */
    public function column_category( $item ): string {
        return esc_html( $item->category_name ?? '—' );
    }

    /**
     * Status column — colored badge.
     */
    public function column_status( $item ): string {
        $colors = [
            'available'   => '#2ecc71',
            'checked_out' => '#3498db',
            'in_repair'   => '#f39c12',
            'retired'     => '#95a5a6',
            'lost'        => '#e74c3c',
        ];
        $labels = \Hugo_Inventory\Models\Asset::status_options();
        $color  = $colors[ $item->status ] ?? '#999';
        $label  = $labels[ $item->status ] ?? $item->status;

        return sprintf(
            '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:%s;color:#fff;font-size:12px;">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );
    }

    /**
     * Assigned user column.
     */
    public function column_assigned_user( $item ): string {
        if ( empty( $item->assigned_user_id ) ) {
            return '—';
        }
        $user = get_userdata( (int) $item->assigned_user_id );
        return $user ? esc_html( $user->display_name ) : '—';
    }

    /**
     * Default column display.
     */
    public function column_default( $item, $column_name ): string {
        return esc_html( $item->$column_name ?? '—' );
    }

    /**
     * Extra filter dropdowns above the table.
     */
    protected function extra_tablenav( $which ): void {
        if ( 'top' !== $which ) {
            return;
        }

        $orgs       = \Hugo_Inventory\Models\Organization::dropdown_options();
        $cats       = \Hugo_Inventory\Models\Category::dropdown_options();
        $locs       = \Hugo_Inventory\Models\Location::dropdown_options();
        $statuses   = \Hugo_Inventory\Models\Asset::status_options();

        $cur_org    = isset( $_REQUEST['filter_org'] )    ? (int) $_REQUEST['filter_org']           : 0;
        $cur_cat    = isset( $_REQUEST['filter_cat'] )    ? (int) $_REQUEST['filter_cat']           : 0;
        $cur_loc    = isset( $_REQUEST['filter_loc'] )    ? (int) $_REQUEST['filter_loc']           : 0;
        $cur_status = isset( $_REQUEST['filter_status'] ) ? sanitize_key( $_REQUEST['filter_status'] ) : '';

        echo '<div class="alignleft actions">';

        // Organization filter.
        echo '<select name="filter_org">';
        echo '<option value="0">' . esc_html__( 'All Organizations', 'hugo-inventory' ) . '</option>';
        foreach ( $orgs as $oid => $oname ) {
            printf( '<option value="%d" %s>%s</option>', $oid, selected( $cur_org, $oid, false ), esc_html( $oname ) );
        }
        echo '</select>';

        // Status filter.
        echo '<select name="filter_status">';
        echo '<option value="">' . esc_html__( 'All Statuses', 'hugo-inventory' ) . '</option>';
        foreach ( $statuses as $skey => $slabel ) {
            printf( '<option value="%s" %s>%s</option>', esc_attr( $skey ), selected( $cur_status, $skey, false ), esc_html( $slabel ) );
        }
        echo '</select>';

        // Category filter.
        echo '<select name="filter_cat">';
        echo '<option value="0">' . esc_html__( 'All Categories', 'hugo-inventory' ) . '</option>';
        foreach ( $cats as $cid => $cname ) {
            printf( '<option value="%d" %s>%s</option>', $cid, selected( $cur_cat, $cid, false ), esc_html( $cname ) );
        }
        echo '</select>';

        // Location filter.
        echo '<select name="filter_loc">';
        echo '<option value="0">' . esc_html__( 'All Locations', 'hugo-inventory' ) . '</option>';
        foreach ( $locs as $lid => $lname ) {
            printf( '<option value="%d" %s>%s</option>', $lid, selected( $cur_loc, $lid, false ), esc_html( $lname ) );
        }
        echo '</select>';

        submit_button( __( 'Filter', 'hugo-inventory' ), '', 'filter_action', false );
        echo '</div>';
    }

    /**
     * Prepare items — query the Asset model.
     */
    public function prepare_items(): void {
        $per_page     = 50;
        $current_page = $this->get_pagenum();
        $search       = isset( $_REQUEST['s'] )             ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) )        : '';
        $orderby      = isset( $_REQUEST['orderby'] )       ? sanitize_key( $_REQUEST['orderby'] )                       : 'created_at';
        $order        = isset( $_REQUEST['order'] )          ? sanitize_key( $_REQUEST['order'] )                         : 'DESC';
        $filter_org   = isset( $_REQUEST['filter_org'] )     ? (int) $_REQUEST['filter_org']                              : null;
        $filter_cat   = isset( $_REQUEST['filter_cat'] )     ? (int) $_REQUEST['filter_cat']                              : null;
        $filter_loc   = isset( $_REQUEST['filter_loc'] )     ? (int) $_REQUEST['filter_loc']                              : null;
        $filter_status = isset( $_REQUEST['filter_status'] ) ? sanitize_key( $_REQUEST['filter_status'] )                 : null;

        if ( $filter_org === 0 )  $filter_org = null;
        if ( $filter_cat === 0 )  $filter_cat = null;
        if ( $filter_loc === 0 )  $filter_loc = null;
        if ( $filter_status === '' ) $filter_status = null;

        $result = \Hugo_Inventory\Models\Asset::list( [
            'organization_id' => $filter_org,
            'category_id'     => $filter_cat,
            'location_id'     => $filter_loc,
            'status'          => $filter_status,
            'search'          => $search,
            'orderby'         => $orderby,
            'order'           => $order,
            'per_page'        => $per_page,
            'page'            => $current_page,
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
