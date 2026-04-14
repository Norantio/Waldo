<?php

namespace Hugo_Inventory\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin bootstrap — registers menus, enqueues scripts, handles page routing.
 */
class Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Register label printer AJAX handler.
        Label_Printer::register();
    }

    /**
     * Register top-level and sub-menu pages.
     */
    public function register_menus(): void {
        // Top-level menu
        add_menu_page(
            __( 'Inventory', 'hugo-inventory' ),
            __( 'Inventory', 'hugo-inventory' ),
            'manage_options',
            'hugo-inventory',
            [ $this, 'render_dashboard_page' ],
            'dashicons-clipboard',
            30
        );

        add_submenu_page(
            'hugo-inventory',
            __( 'Dashboard', 'hugo-inventory' ),
            __( 'Dashboard', 'hugo-inventory' ),
            'manage_options',
            'hugo-inventory',
            [ $this, 'render_dashboard_page' ]
        );

        add_submenu_page(
            'hugo-inventory',
            __( 'Organizations', 'hugo-inventory' ),
            __( 'Organizations', 'hugo-inventory' ),
            'manage_options',
            'hugo-inventory-organizations',
            [ $this, 'render_organizations_page' ]
        );

        add_submenu_page(
            'hugo-inventory',
            __( 'Categories', 'hugo-inventory' ),
            __( 'Categories', 'hugo-inventory' ),
            'manage_options',
            'hugo-inventory-categories',
            [ $this, 'render_categories_page' ]
        );

        add_submenu_page(
            'hugo-inventory',
            __( 'Locations', 'hugo-inventory' ),
            __( 'Locations', 'hugo-inventory' ),
            'manage_options',
            'hugo-inventory-locations',
            [ $this, 'render_locations_page' ]
        );

        add_submenu_page(
            'hugo-inventory',
            __( 'Assets', 'hugo-inventory' ),
            __( 'Assets', 'hugo-inventory' ),
            'manage_options',
            'hugo-inventory-assets',
            [ $this, 'render_assets_page' ]
        );

        add_submenu_page(
            'hugo-inventory',
            __( 'Settings', 'hugo-inventory' ),
            __( 'Settings', 'hugo-inventory' ),
            'manage_options',
            'hugo-inventory-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Enqueue admin CSS/JS on plugin pages only.
     */
    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'hugo-inventory' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'hugo-inventory-admin',
            HUGO_INV_PLUGIN_URL . 'assets/css/admin.css',
            [],
            HUGO_INV_VERSION
        );

        wp_enqueue_script(
            'hugo-inventory-admin',
            HUGO_INV_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            HUGO_INV_VERSION,
            true
        );

        wp_localize_script( 'hugo-inventory-admin', 'hugoInventory', [
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'adminUrl'         => admin_url(),
            'restUrl'          => rest_url( 'hugo-inventory/v1/' ),
            'nonce'            => wp_create_nonce( 'wp_rest' ),
            'printNonce'       => wp_create_nonce( 'hugo_inv_print_labels' ),
            'scannerThreshold' => get_option( 'hugo_inventory_settings', [] )['scanner_threshold'] ?? 50,
        ] );
    }

    /**
     * Dashboard page — all-in-one inventory command center.
     */
    public function render_dashboard_page(): void {
        $org_id = isset( $_GET['organization_id'] ) ? absint( $_GET['organization_id'] ) : null;

        // Fetch data server-side for initial render.
        $by_status   = \Hugo_Inventory\Models\Asset::count_by_status( $org_id );
        $total       = array_sum( $by_status );
        $org_options = \Hugo_Inventory\Models\Organization::dropdown_options();
        $cat_options = \Hugo_Inventory\Models\Category::dropdown_options();
        $loc_options = \Hugo_Inventory\Models\Location::dropdown_options();
        $status_opts = \Hugo_Inventory\Models\Asset::status_options();

        // All assets for the table.
        $list_args = [
            'orderby'  => 'created_at',
            'order'    => 'DESC',
            'per_page' => 200,
            'page'     => 1,
        ];
        if ( $org_id ) {
            $list_args['organization_id'] = $org_id;
        }
        $all_assets = \Hugo_Inventory\Models\Asset::list( $list_args );

        // Warranty alerts (next 30 days).
        global $wpdb;
        $t_assets = $wpdb->prefix . 'inventory_assets';
        $t_orgs   = $wpdb->prefix . 'inventory_organizations';
        $warranty_where  = 'WHERE a.warranty_expiration IS NOT NULL AND a.warranty_expiration <> ""'
            . ' AND a.warranty_expiration <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)'
            . ' AND a.warranty_expiration >= CURDATE()'
            . ' AND a.status NOT IN ("retired","lost")';
        $warranty_params = [];
        if ( $org_id ) {
            $warranty_where   .= ' AND a.organization_id = %d';
            $warranty_params[] = $org_id;
        }
        $warranty_sql = "SELECT a.id, a.asset_tag, a.name, a.warranty_expiration, o.name AS organization_name
                         FROM {$t_assets} a LEFT JOIN {$t_orgs} o ON a.organization_id = o.id
                         {$warranty_where} ORDER BY a.warranty_expiration ASC LIMIT 10";
        if ( $warranty_params ) {
            $warranty_sql = $wpdb->prepare( $warranty_sql, ...$warranty_params );
        }
        $warranty_alerts = $wpdb->get_results( $warranty_sql );

        $status_colors = [
            'available'   => '#46b450',
            'checked_out' => '#0073aa',
            'in_repair'   => '#ffb900',
            'retired'     => '#826eb4',
            'lost'        => '#dc3232',
        ];

        $edit_base   = admin_url( 'admin.php?page=hugo-inventory-assets&action=edit&id=' );
        $add_url     = admin_url( 'admin.php?page=hugo-inventory-assets&action=add' );
        $print_nonce = wp_create_nonce( 'hugo_inv_print_labels' );

        // WP users for assignment dropdown.
        $wp_users = get_users( [ 'fields' => [ 'ID', 'display_name' ], 'orderby' => 'display_name' ] );

        echo '<div class="wrap hugo-inv-dashboard-wrap">';
        echo '<h1>' . esc_html__( 'Inventory Dashboard', 'hugo-inventory' ) . '</h1>';

        // ── Top bar: Org filter + Actions ──
        ?>
        <div class="hugo-inv-topbar">
            <form method="get" class="hugo-inv-topbar-filter">
                <input type="hidden" name="page" value="hugo-inventory">
                <label for="organization_id"><strong><?php esc_html_e( 'Organization:', 'hugo-inventory' ); ?></strong></label>
                <select name="organization_id" id="organization_id" onchange="this.form.submit()">
                    <option value=""><?php esc_html_e( 'All', 'hugo-inventory' ); ?></option>
                    <?php foreach ( $org_options as $oid => $oname ) : ?>
                        <option value="<?php echo esc_attr( $oid ); ?>" <?php selected( $org_id, $oid ); ?>><?php echo esc_html( $oname ); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <div class="hugo-inv-topbar-actions">
                <a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary"><span class="dashicons dashicons-plus-alt2" style="vertical-align:text-top;margin-right:2px;"></span> <?php esc_html_e( 'Add Asset', 'hugo-inventory' ); ?></a>
                <button type="button" class="button" id="hugo-inv-scan-btn"><span class="dashicons dashicons-camera" style="vertical-align:text-top;margin-right:2px;"></span> <?php esc_html_e( 'Scan', 'hugo-inventory' ); ?></button>
                <button type="button" class="button" id="hugo-inv-print-selected" disabled><span class="dashicons dashicons-printer" style="vertical-align:text-top;margin-right:2px;"></span> <?php esc_html_e( 'Print Selected', 'hugo-inventory' ); ?></button>
            </div>
        </div>
        <?php

        // ── Scan panel (hidden by default, toggled by Scan button) ──
        ?>
        <div id="hugo-inv-scan-panel" class="postbox" style="display:none;margin-bottom:16px;">
            <div class="inside" style="padding:16px;">
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                    <div id="hugo-inv-scan-status" style="display:flex;align-items:center;gap:8px;">
                        <span class="hugo-inv-scan-dot"></span>
                        <strong><?php esc_html_e( 'Ready to Scan', 'hugo-inventory' ); ?></strong>
                        <span style="color:#666;font-size:13px;"><?php esc_html_e( '— Point scanner at barcode or type below', 'hugo-inventory' ); ?></span>
                    </div>
                    <div style="flex:1;min-width:200px;">
                        <input type="text" id="hugo-inv-manual-scan" class="regular-text hugo-inv-scan-field" placeholder="<?php esc_attr_e( 'Type or paste barcode/asset tag…', 'hugo-inventory' ); ?>" autocomplete="off">
                    </div>
                    <button type="button" class="button button-primary" id="hugo-inv-manual-scan-go"><?php esc_html_e( 'Look Up', 'hugo-inventory' ); ?></button>
                </div>
                <div id="hugo-inv-scan-result-panel" style="margin-top:12px;display:none;"></div>
            </div>
        </div>
        <?php

        // ── Status Cards ──
        echo '<div class="hugo-inv-dashboard-cards">';
        echo '<div class="hugo-inv-stat-card" style="border-left-color:#23282d;">';
        echo '<div class="hugo-inv-stat-number">' . esc_html( number_format_i18n( $total ) ) . '</div>';
        echo '<div class="hugo-inv-stat-label">' . esc_html__( 'Total', 'hugo-inventory' ) . '</div>';
        echo '</div>';
        foreach ( $by_status as $status => $count ) {
            $color = $status_colors[ $status ] ?? '#666';
            $label = ucwords( str_replace( '_', ' ', $status ) );
            echo '<div class="hugo-inv-stat-card" style="border-left-color:' . esc_attr( $color ) . ';">';
            echo '<div class="hugo-inv-stat-number">' . esc_html( number_format_i18n( $count ) ) . '</div>';
            echo '<div class="hugo-inv-stat-label">' . esc_html( $label ) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        // ── Main content: Asset table + sidebar ──
        ?>
        <div class="hugo-inv-main-layout">
            <!-- Asset table -->
            <div class="hugo-inv-main-content">
                <div class="postbox">
                    <div class="hugo-inv-table-header">
                        <h2 class="hndle" style="margin:0;"><?php esc_html_e( 'All Assets', 'hugo-inventory' ); ?>
                            <span style="font-weight:normal;color:#666;font-size:13px;margin-left:8px;">(<?php echo esc_html( number_format_i18n( $all_assets['total'] ?? 0 ) ); ?>)</span>
                        </h2>
                        <div class="hugo-inv-table-filters">
                            <input type="text" id="hugo-inv-table-search" placeholder="<?php esc_attr_e( 'Filter…', 'hugo-inventory' ); ?>" class="hugo-inv-filter-input">
                            <select id="hugo-inv-status-filter" class="hugo-inv-filter-select">
                                <option value=""><?php esc_html_e( 'All Statuses', 'hugo-inventory' ); ?></option>
                                <?php foreach ( $status_opts as $sk => $sl ) : ?>
                                    <option value="<?php echo esc_attr( $sk ); ?>"><?php echo esc_html( $sl ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="inside" style="padding:0;overflow-x:auto;">
                        <table class="widefat striped hugo-inv-asset-table" id="hugo-inv-asset-table">
                            <thead>
                                <tr>
                                    <th class="check-column"><input type="checkbox" id="hugo-inv-select-all"></th>
                                    <th><?php esc_html_e( 'Asset Tag', 'hugo-inventory' ); ?></th>
                                    <th><?php esc_html_e( 'Name', 'hugo-inventory' ); ?></th>
                                    <th><?php esc_html_e( 'Organization', 'hugo-inventory' ); ?></th>
                                    <th><?php esc_html_e( 'Location', 'hugo-inventory' ); ?></th>
                                    <th><?php esc_html_e( 'Assigned To', 'hugo-inventory' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'hugo-inventory' ); ?></th>
                                    <th><?php esc_html_e( 'Actions', 'hugo-inventory' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ( ! empty( $all_assets['items'] ) ) : ?>
                                <?php foreach ( $all_assets['items'] as $item ) :
                                    $sc         = $status_colors[ $item->status ] ?? '#666';
                                    $status_lbl = ucwords( str_replace( '_', ' ', $item->status ) );
                                    $edit_url   = $edit_base . $item->id;
                                    $p_url      = admin_url( 'admin-ajax.php?action=hugo_inv_print_labels&ids=' . $item->id . '&_wpnonce=' . $print_nonce );
                                ?>
                                <tr data-id="<?php echo esc_attr( $item->id ); ?>"
                                    data-status="<?php echo esc_attr( $item->status ); ?>"
                                    data-search="<?php echo esc_attr( strtolower( $item->asset_tag . ' ' . $item->name . ' ' . ( $item->serial_number ?? '' ) . ' ' . ( $item->organization_name ?? '' ) . ' ' . ( $item->location_name ?? '' ) ) ); ?>">
                                    <td><input type="checkbox" class="hugo-inv-row-cb" value="<?php echo esc_attr( $item->id ); ?>"></td>
                                    <td><a href="<?php echo esc_url( $edit_url ); ?>"><code><?php echo esc_html( $item->asset_tag ); ?></code></a></td>
                                    <td><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $item->name ); ?></a></td>
                                    <td><?php echo esc_html( $item->organization_name ?? '—' ); ?></td>
                                    <td><?php echo esc_html( $item->location_name ?? '—' ); ?></td>
                                    <td><?php echo esc_html( $item->assigned_user_display ?? '—' ); ?></td>
                                    <td><span class="hugo-inv-status hugo-inv-status-<?php echo esc_attr( $item->status ); ?>"><?php echo esc_html( $status_lbl ); ?></span></td>
                                    <td class="hugo-inv-actions-cell">
                                        <a href="<?php echo esc_url( $edit_url ); ?>" title="<?php esc_attr_e( 'Edit', 'hugo-inventory' ); ?>"><span class="dashicons dashicons-edit"></span></a>
                                        <a href="<?php echo esc_url( $p_url ); ?>" target="_blank" title="<?php esc_attr_e( 'Print Label', 'hugo-inventory' ); ?>"><span class="dashicons dashicons-printer"></span></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr><td colspan="8" style="text-align:center;padding:24px;"><?php esc_html_e( 'No assets found. Add your first asset!', 'hugo-inventory' ); ?></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="hugo-inv-sidebar">
                <?php if ( ! empty( $warranty_alerts ) ) : ?>
                <div class="postbox hugo-inv-sidebar-box">
                    <h2 class="hndle"><span class="dashicons dashicons-warning" style="color:#ffb900;"></span> <?php esc_html_e( 'Warranty Alerts', 'hugo-inventory' ); ?></h2>
                    <div class="inside" style="padding:0;">
                        <ul class="hugo-inv-alert-list">
                        <?php foreach ( $warranty_alerts as $wa ) :
                            $days_left = max( 0, (int) ( ( strtotime( $wa->warranty_expiration ) - time() ) / DAY_IN_SECONDS ) );
                            $urgency   = $days_left <= 7 ? '#dc3232' : ( $days_left <= 14 ? '#ffb900' : '#666' );
                        ?>
                            <li>
                                <a href="<?php echo esc_url( $edit_base . $wa->id ); ?>">
                                    <strong><?php echo esc_html( $wa->name ); ?></strong>
                                    <code><?php echo esc_html( $wa->asset_tag ); ?></code>
                                </a>
                                <span style="color:<?php echo esc_attr( $urgency ); ?>;font-size:12px;">
                                    <?php echo esc_html( sprintf( __( '%d days left', 'hugo-inventory' ), $days_left ) ); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>

                <div class="postbox hugo-inv-sidebar-box">
                    <h2 class="hndle"><?php esc_html_e( 'Quick Links', 'hugo-inventory' ); ?></h2>
                    <div class="inside">
                        <ul class="hugo-inv-quick-links">
                            <li><a href="<?php echo esc_url( $add_url ); ?>"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add New Asset', 'hugo-inventory' ); ?></a></li>
                            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hugo-inventory-organizations' ) ); ?>"><span class="dashicons dashicons-building"></span> <?php esc_html_e( 'Manage Organizations', 'hugo-inventory' ); ?></a></li>
                            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hugo-inventory-categories' ) ); ?>"><span class="dashicons dashicons-category"></span> <?php esc_html_e( 'Manage Categories', 'hugo-inventory' ); ?></a></li>
                            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hugo-inventory-locations' ) ); ?>"><span class="dashicons dashicons-location"></span> <?php esc_html_e( 'Manage Locations', 'hugo-inventory' ); ?></a></li>
                            <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hugo-inventory-settings' ) ); ?>"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Settings', 'hugo-inventory' ); ?></a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
        echo '</div>'; // end wrap
    }

    // ── Categories ─────────────────────────────────────────────────────────

    /**
     * Categories page — handles list, add, edit, and delete views.
     */
    public function render_categories_page(): void {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        // Handle delete action.
        if ( 'delete' === $action && isset( $_GET['id'] ) ) {
            $id = absint( $_GET['id'] );
            check_admin_referer( 'hugo_inv_delete_cat_' . $id );
            $result = \Hugo_Inventory\Models\Category::delete( $id );
            $redirect_args = [ 'page' => 'hugo-inventory-categories' ];
            if ( is_wp_error( $result ) ) {
                $redirect_args['error'] = urlencode( $result->get_error_message() );
            } else {
                $redirect_args['deleted'] = '1';
            }
            wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
            exit;
        }

        switch ( $action ) {
            case 'add':
            case 'edit':
                $this->render_category_form();
                break;
            default:
                $this->render_categories_list();
                break;
        }
    }

    /**
     * Render the categories WP_List_Table.
     */
    private function render_categories_list(): void {
        $table = new Categories_List_Table();
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__( 'Categories', 'hugo-inventory' ) . '</h1>';
        echo ' <a href="' . esc_url( admin_url( 'admin.php?page=hugo-inventory-categories&action=add' ) ) . '" class="page-title-action">';
        echo esc_html__( 'Add New', 'hugo-inventory' ) . '</a>';
        echo '<hr class="wp-header-end">';

        if ( isset( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Category deleted.', 'hugo-inventory' ) . '</p></div>';
        }
        if ( isset( $_GET['error'] ) ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( urldecode( $_GET['error'] ) ) . '</p></div>';
        }

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="hugo-inventory-categories">';
        $table->search_box( __( 'Search Categories', 'hugo-inventory' ), 'cat-search' );
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    /**
     * Render add/edit category form.
     */
    private function render_category_form(): void {
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $cat    = $id ? \Hugo_Inventory\Models\Category::find( $id ) : null;
        $is_edit = (bool) $cat;

        // Handle form submission.
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['hugo_inv_cat_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['hugo_inv_cat_nonce'], 'hugo_inv_save_cat' ) ) {
                wp_die( __( 'Security check failed.', 'hugo-inventory' ) );
            }

            $data = [
                'name'      => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
                'parent_id' => ! empty( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : null,
            ];

            if ( $is_edit ) {
                \Hugo_Inventory\Models\Category::update( $id, $data );
                $redirect = add_query_arg( [ 'page' => 'hugo-inventory-categories', 'updated' => '1' ], admin_url( 'admin.php' ) );
            } else {
                \Hugo_Inventory\Models\Category::create( $data );
                $redirect = add_query_arg( [ 'page' => 'hugo-inventory-categories', 'created' => '1' ], admin_url( 'admin.php' ) );
            }

            wp_safe_redirect( $redirect );
            exit;
        }

        $title = $is_edit
            ? __( 'Edit Category', 'hugo-inventory' )
            : __( 'Add Category', 'hugo-inventory' );

        // Get parent dropdown options (exclude self and descendants for edit).
        $parent_options = \Hugo_Inventory\Models\Category::dropdown_options();
        if ( $is_edit ) {
            unset( $parent_options[ $id ] );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $title ) . '</h1>';

        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Category updated.', 'hugo-inventory' ) . '</p></div>';
        }
        if ( isset( $_GET['created'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Category created.', 'hugo-inventory' ) . '</p></div>';
        }

        $form_url = admin_url( 'admin.php?page=hugo-inventory-categories&action=' . ( $is_edit ? 'edit&id=' . $id : 'add' ) );
        ?>
        <form method="post" action="<?php echo esc_url( $form_url ); ?>">
            <?php wp_nonce_field( 'hugo_inv_save_cat', 'hugo_inv_cat_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="name"><?php esc_html_e( 'Name', 'hugo-inventory' ); ?> <span class="required">*</span></label></th>
                    <td><input type="text" id="name" name="name" class="regular-text" required value="<?php echo esc_attr( $cat->name ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="parent_id"><?php esc_html_e( 'Parent Category', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <select id="parent_id" name="parent_id">
                            <option value=""><?php esc_html_e( '— None (top level) —', 'hugo-inventory' ); ?></option>
                            <?php foreach ( $parent_options as $opt_id => $opt_name ) : ?>
                                <option value="<?php echo esc_attr( $opt_id ); ?>" <?php selected( $cat->parent_id ?? '', $opt_id ); ?>>
                                    <?php echo esc_html( $opt_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( $is_edit ? __( 'Update Category', 'hugo-inventory' ) : __( 'Add Category', 'hugo-inventory' ) ); ?>
        </form>
        <?php
        echo '</div>';
    }

    // ── Locations ─────────────────────────────────────────────────────────

    /**
     * Locations page — handles list, add, edit, and delete views.
     */
    public function render_locations_page(): void {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        // Handle delete action.
        if ( 'delete' === $action && isset( $_GET['id'] ) ) {
            $id = absint( $_GET['id'] );
            check_admin_referer( 'hugo_inv_delete_loc_' . $id );
            $result = \Hugo_Inventory\Models\Location::delete( $id );
            $redirect_args = [ 'page' => 'hugo-inventory-locations' ];
            if ( is_wp_error( $result ) ) {
                $redirect_args['error'] = urlencode( $result->get_error_message() );
            } else {
                $redirect_args['deleted'] = '1';
            }
            wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
            exit;
        }

        switch ( $action ) {
            case 'add':
            case 'edit':
                $this->render_location_form();
                break;
            default:
                $this->render_locations_list();
                break;
        }
    }

    /**
     * Render the locations WP_List_Table.
     */
    private function render_locations_list(): void {
        $table = new Locations_List_Table();
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__( 'Locations', 'hugo-inventory' ) . '</h1>';
        echo ' <a href="' . esc_url( admin_url( 'admin.php?page=hugo-inventory-locations&action=add' ) ) . '" class="page-title-action">';
        echo esc_html__( 'Add New', 'hugo-inventory' ) . '</a>';
        echo '<hr class="wp-header-end">';

        if ( isset( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Location deleted.', 'hugo-inventory' ) . '</p></div>';
        }
        if ( isset( $_GET['error'] ) ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( urldecode( $_GET['error'] ) ) . '</p></div>';
        }

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="hugo-inventory-locations">';
        $table->search_box( __( 'Search Locations', 'hugo-inventory' ), 'loc-search' );
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    /**
     * Render add/edit location form.
     */
    private function render_location_form(): void {
        $id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $loc     = $id ? \Hugo_Inventory\Models\Location::find( $id ) : null;
        $is_edit = (bool) $loc;

        // Handle form submission.
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['hugo_inv_loc_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['hugo_inv_loc_nonce'], 'hugo_inv_save_loc' ) ) {
                wp_die( __( 'Security check failed.', 'hugo-inventory' ) );
            }

            $data = [
                'name'            => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
                'organization_id' => ! empty( $_POST['organization_id'] ) ? absint( $_POST['organization_id'] ) : null,
                'parent_id'       => ! empty( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : null,
                'address'         => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ),
            ];

            if ( $is_edit ) {
                \Hugo_Inventory\Models\Location::update( $id, $data );
                $redirect = add_query_arg( [ 'page' => 'hugo-inventory-locations', 'updated' => '1' ], admin_url( 'admin.php' ) );
            } else {
                \Hugo_Inventory\Models\Location::create( $data );
                $redirect = add_query_arg( [ 'page' => 'hugo-inventory-locations', 'created' => '1' ], admin_url( 'admin.php' ) );
            }

            wp_safe_redirect( $redirect );
            exit;
        }

        $title = $is_edit
            ? __( 'Edit Location', 'hugo-inventory' )
            : __( 'Add Location', 'hugo-inventory' );

        $org_options    = \Hugo_Inventory\Models\Organization::dropdown_options();
        $parent_options = \Hugo_Inventory\Models\Location::dropdown_options();
        if ( $is_edit ) {
            unset( $parent_options[ $id ] );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $title ) . '</h1>';

        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Location updated.', 'hugo-inventory' ) . '</p></div>';
        }
        if ( isset( $_GET['created'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Location created.', 'hugo-inventory' ) . '</p></div>';
        }

        $form_url = admin_url( 'admin.php?page=hugo-inventory-locations&action=' . ( $is_edit ? 'edit&id=' . $id : 'add' ) );
        ?>
        <form method="post" action="<?php echo esc_url( $form_url ); ?>">
            <?php wp_nonce_field( 'hugo_inv_save_loc', 'hugo_inv_loc_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="name"><?php esc_html_e( 'Name', 'hugo-inventory' ); ?> <span class="required">*</span></label></th>
                    <td><input type="text" id="name" name="name" class="regular-text" required value="<?php echo esc_attr( $loc->name ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="organization_id"><?php esc_html_e( 'Organization', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <select id="organization_id" name="organization_id">
                            <option value=""><?php esc_html_e( '— Global (not org-scoped) —', 'hugo-inventory' ); ?></option>
                            <?php foreach ( $org_options as $opt_id => $opt_name ) : ?>
                                <option value="<?php echo esc_attr( $opt_id ); ?>" <?php selected( $loc->organization_id ?? '', $opt_id ); ?>>
                                    <?php echo esc_html( $opt_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Optionally scope this location to a specific organization.', 'hugo-inventory' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="parent_id"><?php esc_html_e( 'Parent Location', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <select id="parent_id" name="parent_id">
                            <option value=""><?php esc_html_e( '— None (top level) —', 'hugo-inventory' ); ?></option>
                            <?php foreach ( $parent_options as $opt_id => $opt_name ) : ?>
                                <option value="<?php echo esc_attr( $opt_id ); ?>" <?php selected( $loc->parent_id ?? '', $opt_id ); ?>>
                                    <?php echo esc_html( $opt_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="address"><?php esc_html_e( 'Address', 'hugo-inventory' ); ?></label></th>
                    <td><textarea id="address" name="address" rows="3" class="large-text"><?php echo esc_textarea( $loc->address ?? '' ); ?></textarea></td>
                </tr>
            </table>
            <?php submit_button( $is_edit ? __( 'Update Location', 'hugo-inventory' ) : __( 'Add Location', 'hugo-inventory' ) ); ?>
        </form>
        <?php
        echo '</div>';
    }

    // ── Organizations ─────────────────────────────────────────────────────

    /**
     * Organizations page — handles list, add, and edit views.
     */
    public function render_organizations_page(): void {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        switch ( $action ) {
            case 'add':
            case 'edit':
                $this->render_organization_form();
                break;
            default:
                $this->render_organizations_list();
                break;
        }
    }

    /**
     * Render the organizations WP_List_Table.
     */
    private function render_organizations_list(): void {
        $table = new Organizations_List_Table();
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__( 'Organizations', 'hugo-inventory' ) . '</h1>';
        echo ' <a href="' . esc_url( admin_url( 'admin.php?page=hugo-inventory-organizations&action=add' ) ) . '" class="page-title-action">';
        echo esc_html__( 'Add New', 'hugo-inventory' ) . '</a>';
        echo '<hr class="wp-header-end">';

        $table->search_box( __( 'Search Organizations', 'hugo-inventory' ), 'org-search' );
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="hugo-inventory-organizations">';
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    /**
     * Render add/edit organization form.
     */
    private function render_organization_form(): void {
        $id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $org  = $id ? \Hugo_Inventory\Models\Organization::find( $id ) : null;
        $is_edit = (bool) $org;

        // Handle form submission.
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['hugo_inv_org_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['hugo_inv_org_nonce'], 'hugo_inv_save_org' ) ) {
                wp_die( __( 'Security check failed.', 'hugo-inventory' ) );
            }

            $data = [
                'name'          => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
                'contact_name'  => sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) ),
                'contact_email' => sanitize_email( wp_unslash( $_POST['contact_email'] ?? '' ) ),
                'notes'         => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
                'is_active'     => isset( $_POST['is_active'] ) ? 1 : 0,
            ];

            if ( $is_edit ) {
                \Hugo_Inventory\Models\Organization::update( $id, $data );
                $redirect = add_query_arg( [ 'page' => 'hugo-inventory-organizations', 'updated' => '1' ], admin_url( 'admin.php' ) );
            } else {
                $new_id = \Hugo_Inventory\Models\Organization::create( $data );
                $redirect = add_query_arg( [ 'page' => 'hugo-inventory-organizations', 'created' => '1' ], admin_url( 'admin.php' ) );
            }

            wp_safe_redirect( $redirect );
            exit;
        }

        $title = $is_edit
            ? __( 'Edit Organization', 'hugo-inventory' )
            : __( 'Add Organization', 'hugo-inventory' );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $title ) . '</h1>';

        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Organization updated.', 'hugo-inventory' ) . '</p></div>';
        }
        if ( isset( $_GET['created'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Organization created.', 'hugo-inventory' ) . '</p></div>';
        }

        $form_url = admin_url( 'admin.php?page=hugo-inventory-organizations&action=' . ( $is_edit ? 'edit&id=' . $id : 'add' ) );
        ?>
        <form method="post" action="<?php echo esc_url( $form_url ); ?>">
            <?php wp_nonce_field( 'hugo_inv_save_org', 'hugo_inv_org_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="name"><?php esc_html_e( 'Name', 'hugo-inventory' ); ?> <span class="required">*</span></label></th>
                    <td><input type="text" id="name" name="name" class="regular-text" required value="<?php echo esc_attr( $org->name ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="contact_name"><?php esc_html_e( 'Contact Name', 'hugo-inventory' ); ?></label></th>
                    <td><input type="text" id="contact_name" name="contact_name" class="regular-text" value="<?php echo esc_attr( $org->contact_name ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="contact_email"><?php esc_html_e( 'Contact Email', 'hugo-inventory' ); ?></label></th>
                    <td><input type="email" id="contact_email" name="contact_email" class="regular-text" value="<?php echo esc_attr( $org->contact_email ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="notes"><?php esc_html_e( 'Notes', 'hugo-inventory' ); ?></label></th>
                    <td><textarea id="notes" name="notes" rows="5" class="large-text"><?php echo esc_textarea( $org->notes ?? '' ); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Active', 'hugo-inventory' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_active" value="1" <?php checked( $org->is_active ?? 1, 1 ); ?>>
                            <?php esc_html_e( 'Organization is active', 'hugo-inventory' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button( $is_edit ? __( 'Update Organization', 'hugo-inventory' ) : __( 'Add Organization', 'hugo-inventory' ) ); ?>
        </form>
        <?php
        echo '</div>';
    }

    // ── Assets ─────────────────────────────────────────────────────────────

    /**
     * Assets page — handles list, add, edit, and archive views.
     */
    public function render_assets_page(): void {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        // Handle archive (soft-delete) action.
        if ( 'archive' === $action && isset( $_GET['id'] ) ) {
            $id = absint( $_GET['id'] );
            check_admin_referer( 'hugo_inv_archive_asset_' . $id );
            \Hugo_Inventory\Models\Asset::archive( $id );
            wp_safe_redirect( add_query_arg( [ 'page' => 'hugo-inventory-assets', 'archived' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        switch ( $action ) {
            case 'add':
            case 'edit':
                $this->render_asset_form();
                break;
            default:
                $this->render_assets_list();
                break;
        }
    }

    /**
     * Render the assets WP_List_Table.
     */
    private function render_assets_list(): void {
        $table = new Assets_List_Table();

        // Handle bulk actions.
        $bulk_action = $table->current_action();
        if ( $bulk_action && isset( $_GET['asset'] ) ) {
            check_admin_referer( 'bulk-assets' );
            $ids = array_map( 'absint', (array) $_GET['asset'] );

            if ( 'archive' === $bulk_action ) {
                foreach ( $ids as $id ) {
                    \Hugo_Inventory\Models\Asset::archive( $id );
                }
            } elseif ( 'print_labels' === $bulk_action ) {
                // Redirect to the label printer page in a new tab via JS.
                $print_url = wp_nonce_url(
                    admin_url( 'admin-ajax.php?action=hugo_inv_print_labels&ids=' . implode( ',', $ids ) ),
                    'hugo_inv_print_labels'
                );
                wp_safe_redirect( add_query_arg( [
                    'page'      => 'hugo-inventory-assets',
                    'print_url' => urlencode( $print_url ),
                ], admin_url( 'admin.php' ) ) );
                exit;
            } elseif ( str_starts_with( $bulk_action, 'set_status_' ) ) {
                $new_status = substr( $bulk_action, 11 );
                $valid = array_keys( \Hugo_Inventory\Models\Asset::status_options() );
                if ( in_array( $new_status, $valid, true ) ) {
                    foreach ( $ids as $id ) {
                        \Hugo_Inventory\Models\Asset::update( $id, [ 'status' => $new_status ] );
                    }
                }
            }

            wp_safe_redirect( add_query_arg( [ 'page' => 'hugo-inventory-assets', 'bulk' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__( 'Assets', 'hugo-inventory' ) . '</h1>';
        echo ' <a href="' . esc_url( admin_url( 'admin.php?page=hugo-inventory-assets&action=add' ) ) . '" class="page-title-action">';
        echo esc_html__( 'Add New', 'hugo-inventory' ) . '</a>';
        echo '<hr class="wp-header-end">';

        if ( isset( $_GET['archived'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Asset archived.', 'hugo-inventory' ) . '</p></div>';
        }
        if ( isset( $_GET['bulk'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Bulk action applied.', 'hugo-inventory' ) . '</p></div>';
        }
        if ( isset( $_GET['created'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Asset created.', 'hugo-inventory' ) . '</p></div>';
        }
        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Asset updated.', 'hugo-inventory' ) . '</p></div>';
        }

        // Open print labels popup if redirected from bulk action.
        if ( isset( $_GET['print_url'] ) ) {
            $print_url = esc_url( urldecode( $_GET['print_url'] ) );
            echo '<script>window.open(' . wp_json_encode( $print_url ) . ', "_blank");</script>';
        }

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="hugo-inventory-assets">';
        $table->search_box( __( 'Search Assets', 'hugo-inventory' ), 'asset-search' );
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    /**
     * Render add/edit asset form.
     */
    private function render_asset_form(): void {
        $id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $asset   = $id ? \Hugo_Inventory\Models\Asset::find( $id ) : null;
        $is_edit = (bool) $asset;

        // Handle form submission.
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['hugo_inv_asset_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['hugo_inv_asset_nonce'], 'hugo_inv_save_asset' ) ) {
                wp_die( __( 'Security check failed.', 'hugo-inventory' ) );
            }

            $data = [
                'name'                => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
                'organization_id'     => absint( $_POST['organization_id'] ?? 0 ),
                'description'         => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
                'serial_number'       => sanitize_text_field( wp_unslash( $_POST['serial_number'] ?? '' ) ),
                'barcode_value'       => sanitize_text_field( wp_unslash( $_POST['barcode_value'] ?? '' ) ),
                'asset_tag'          => sanitize_text_field( wp_unslash( $_POST['asset_tag'] ?? '' ) ),
                'category_id'        => ! empty( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : null,
                'location_id'        => ! empty( $_POST['location_id'] ) ? absint( $_POST['location_id'] ) : null,
                'assigned_user_id'   => ! empty( $_POST['assigned_user_id'] ) ? absint( $_POST['assigned_user_id'] ) : null,
                'status'             => sanitize_key( $_POST['status'] ?? 'available' ),
                'purchase_date'      => sanitize_text_field( $_POST['purchase_date'] ?? '' ),
                'purchase_cost'      => ! empty( $_POST['purchase_cost'] ) ? floatval( $_POST['purchase_cost'] ) : null,
                'warranty_expiration' => sanitize_text_field( $_POST['warranty_expiration'] ?? '' ),
            ];

            // Remember last-used org for convenience.
            update_user_meta( get_current_user_id(), 'hugo_inv_last_org', $data['organization_id'] );

            if ( $is_edit ) {
                $result = \Hugo_Inventory\Models\Asset::update( $id, $data );
                if ( is_wp_error( $result ) ) {
                    $redirect = add_query_arg( [ 'page' => 'hugo-inventory-assets', 'action' => 'edit', 'id' => $id, 'error' => urlencode( $result->get_error_message() ) ], admin_url( 'admin.php' ) );
                } else {
                    $redirect = add_query_arg( [ 'page' => 'hugo-inventory-assets', 'updated' => '1' ], admin_url( 'admin.php' ) );
                }
            } else {
                $result = \Hugo_Inventory\Models\Asset::create( $data );
                if ( is_wp_error( $result ) ) {
                    $redirect = add_query_arg( [ 'page' => 'hugo-inventory-assets', 'action' => 'add', 'error' => urlencode( $result->get_error_message() ) ], admin_url( 'admin.php' ) );
                } else {
                    $redirect = add_query_arg( [ 'page' => 'hugo-inventory-assets', 'created' => '1' ], admin_url( 'admin.php' ) );
                }
            }

            wp_safe_redirect( $redirect );
            exit;
        }

        $title = $is_edit
            ? __( 'Edit Asset', 'hugo-inventory' )
            : __( 'Add Asset', 'hugo-inventory' );

        // Dropdown options.
        $org_options    = \Hugo_Inventory\Models\Organization::dropdown_options();
        $cat_options    = \Hugo_Inventory\Models\Category::dropdown_options();
        $loc_options    = \Hugo_Inventory\Models\Location::dropdown_options();
        $status_options = \Hugo_Inventory\Models\Asset::status_options();

        // Default org from last-used.
        $default_org = get_user_meta( get_current_user_id(), 'hugo_inv_last_org', true );

        // Pre-fill barcode from query string (e.g. from lookup redirect).
        $prefill_barcode = isset( $_GET['barcode'] ) ? sanitize_text_field( wp_unslash( $_GET['barcode'] ) ) : '';

        // WP users for assignment dropdown.
        $wp_users = get_users( [ 'fields' => [ 'ID', 'display_name' ], 'orderby' => 'display_name' ] );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $title ) . '</h1>';

        if ( isset( $_GET['error'] ) ) {
            $error_msg = urldecode( $_GET['error'] );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error_msg ) . '</p></div>';
            echo '<script>console.error("Hugo Inventory asset error:", ' . wp_json_encode( $error_msg ) . ');</script>';
        }

        $form_url = admin_url( 'admin.php?page=hugo-inventory-assets&action=' . ( $is_edit ? 'edit&id=' . $id : 'add' ) );
        ?>
        <form method="post" action="<?php echo esc_url( $form_url ); ?>" onkeydown="if(event.key==='Enter'&&event.target.tagName!=='TEXTAREA'&&event.target.type!=='submit'){event.preventDefault();return false;}">
            <?php wp_nonce_field( 'hugo_inv_save_asset', 'hugo_inv_asset_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="name"><?php esc_html_e( 'Name', 'hugo-inventory' ); ?> <span class="required">*</span></label></th>
                    <td><input type="text" id="name" name="name" class="regular-text" required value="<?php echo esc_attr( $asset->name ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="organization_id"><?php esc_html_e( 'Organization', 'hugo-inventory' ); ?> <span class="required">*</span></label></th>
                    <td>
                        <select id="organization_id" name="organization_id" required>
                            <option value=""><?php esc_html_e( '— Select —', 'hugo-inventory' ); ?></option>
                            <?php
                            $selected_org = $asset->organization_id ?? $default_org;
                            foreach ( $org_options as $opt_id => $opt_name ) : ?>
                                <option value="<?php echo esc_attr( $opt_id ); ?>" <?php selected( $selected_org, $opt_id ); ?>>
                                    <?php echo esc_html( $opt_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="description"><?php esc_html_e( 'Description', 'hugo-inventory' ); ?></label></th>
                    <td><textarea id="description" name="description" rows="4" class="large-text"><?php echo esc_textarea( $asset->description ?? '' ); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="serial_number"><?php esc_html_e( 'Serial Number', 'hugo-inventory' ); ?></label></th>
                    <td><input type="text" id="serial_number" name="serial_number" class="regular-text" value="<?php echo esc_attr( $asset->serial_number ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="barcode_value"><?php esc_html_e( 'Barcode', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <input type="text" id="barcode_value" name="barcode_value" class="regular-text" value="<?php echo esc_attr( $asset->barcode_value ?? $prefill_barcode ); ?>">
                        <p class="description"><?php esc_html_e( 'Leave blank to auto-generate from asset tag.', 'hugo-inventory' ); ?></p>
                    </td>
                </tr>
                <?php if ( $is_edit && ! empty( $asset->asset_tag ) ) : ?>
                <tr>
                    <th scope="row"><label for="asset_tag"><?php esc_html_e( 'Asset Tag', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <input type="text" id="asset_tag" name="asset_tag" class="regular-text" value="<?php echo esc_attr( $asset->asset_tag ); ?>">
                        <?php
                        $print_url = wp_nonce_url(
                            admin_url( 'admin-ajax.php?action=hugo_inv_print_labels&ids=' . $id ),
                            'hugo_inv_print_labels'
                        );
                        ?>
                        <a href="<?php echo esc_url( $print_url ); ?>" target="_blank" class="button button-small" style="margin-left:8px;">
                            <?php esc_html_e( 'Print Label', 'hugo-inventory' ); ?>
                        </a>
                    </td>
                </tr>
                <?php else : ?>
                <tr>
                    <th scope="row"><label for="asset_tag"><?php esc_html_e( 'Asset Tag', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <input type="text" id="asset_tag" name="asset_tag" class="regular-text" value="">
                        <p class="description"><?php esc_html_e( 'Leave blank to auto-generate.', 'hugo-inventory' ); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if ( $is_edit && ! empty( $asset->asset_tag ) ) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'QR Code', 'hugo-inventory' ); ?></th>
                    <td><?php echo \Hugo_Inventory\Barcode::qr_svg( \Hugo_Inventory\Barcode::build_qr_payload( $asset ), 3 ); // phpcs:ignore ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><label for="category_id"><?php esc_html_e( 'Category', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <select id="category_id" name="category_id">
                            <option value=""><?php esc_html_e( '— None —', 'hugo-inventory' ); ?></option>
                            <?php foreach ( $cat_options as $opt_id => $opt_name ) : ?>
                                <option value="<?php echo esc_attr( $opt_id ); ?>" <?php selected( $asset->category_id ?? '', $opt_id ); ?>>
                                    <?php echo esc_html( $opt_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="location_id"><?php esc_html_e( 'Location', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <select id="location_id" name="location_id">
                            <option value=""><?php esc_html_e( '— None —', 'hugo-inventory' ); ?></option>
                            <?php foreach ( $loc_options as $opt_id => $opt_name ) : ?>
                                <option value="<?php echo esc_attr( $opt_id ); ?>" <?php selected( $asset->location_id ?? '', $opt_id ); ?>>
                                    <?php echo esc_html( $opt_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="assigned_user_id"><?php esc_html_e( 'Assigned User', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <select id="assigned_user_id" name="assigned_user_id">
                            <option value=""><?php esc_html_e( '— Unassigned —', 'hugo-inventory' ); ?></option>
                            <?php foreach ( $wp_users as $u ) : ?>
                                <option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $asset->assigned_user_id ?? '', $u->ID ); ?>>
                                    <?php echo esc_html( $u->display_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="status"><?php esc_html_e( 'Status', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <select id="status" name="status">
                            <?php foreach ( $status_options as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $asset->status ?? 'available', $val ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="purchase_date"><?php esc_html_e( 'Purchase Date', 'hugo-inventory' ); ?></label></th>
                    <td><input type="date" id="purchase_date" name="purchase_date" value="<?php echo esc_attr( $asset->purchase_date ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="purchase_cost"><?php esc_html_e( 'Purchase Cost', 'hugo-inventory' ); ?></label></th>
                    <td><input type="number" id="purchase_cost" name="purchase_cost" step="0.01" min="0" value="<?php echo esc_attr( $asset->purchase_cost ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="warranty_expiration"><?php esc_html_e( 'Warranty Expiration', 'hugo-inventory' ); ?></label></th>
                    <td><input type="date" id="warranty_expiration" name="warranty_expiration" value="<?php echo esc_attr( $asset->warranty_expiration ?? '' ); ?>"></td>
                </tr>
            </table>
            <?php submit_button( $is_edit ? __( 'Update Asset', 'hugo-inventory' ) : __( 'Add Asset', 'hugo-inventory' ) ); ?>
        </form>
        <?php
        echo '</div>';
    }

    // ── Settings ──────────────────────────────────────────────────────────

    /**
     * Settings page — plugin-wide configuration.
     */
    public function render_settings_page(): void {
        // Handle form submission.
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['hugo_inv_settings_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['hugo_inv_settings_nonce'], 'hugo_inv_save_settings' ) ) {
                wp_die( __( 'Security check failed.', 'hugo-inventory' ) );
            }

            $new = [
                'asset_tag_prefix'     => sanitize_text_field( wp_unslash( $_POST['asset_tag_prefix'] ?? 'HUGO' ) ),
                'asset_tag_digits'     => absint( $_POST['asset_tag_digits'] ?? 6 ),
                'default_organization' => ! empty( $_POST['default_organization'] ) ? absint( $_POST['default_organization'] ) : '',
                'scanner_threshold'    => absint( $_POST['scanner_threshold'] ?? 50 ),
                'qr_payload_format'    => sanitize_key( $_POST['qr_payload_format'] ?? 'tag_only' ),
                'label_cols'           => absint( $_POST['label_cols'] ?? 3 ),
                'label_width_mm'       => absint( $_POST['label_width_mm'] ?? 63 ),
                'label_height_mm'      => absint( $_POST['label_height_mm'] ?? 30 ),
                'label_code_type'      => sanitize_key( $_POST['label_code_type'] ?? 'qr' ),
                'items_per_page'       => absint( $_POST['items_per_page'] ?? 50 ),
            ];

            // Clamp digits 1-10.
            $new['asset_tag_digits'] = max( 1, min( 10, $new['asset_tag_digits'] ) );
            // Clamp scanner threshold 10-500.
            $new['scanner_threshold'] = max( 10, min( 500, $new['scanner_threshold'] ) );
            // Clamp items per page 10-200.
            $new['items_per_page'] = max( 10, min( 200, $new['items_per_page'] ) );
            // Clamp label cols 1-6.
            $new['label_cols'] = max( 1, min( 6, $new['label_cols'] ) );

            update_option( 'hugo_inventory_settings', $new );

            wp_safe_redirect( add_query_arg( [
                'page'    => 'hugo-inventory-settings',
                'updated' => '1',
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $s = wp_parse_args( get_option( 'hugo_inventory_settings', [] ), [
            'asset_tag_prefix'     => 'HUGO',
            'asset_tag_digits'     => 6,
            'default_organization' => '',
            'scanner_threshold'    => 50,
            'qr_payload_format'    => 'tag_only',
            'label_cols'           => 3,
            'label_width_mm'       => 63,
            'label_height_mm'      => 30,
            'label_code_type'      => 'qr',
            'items_per_page'       => 50,
        ] );

        $org_options = \Hugo_Inventory\Models\Organization::dropdown_options();

        // Current counter value.
        $counter = (int) get_option( 'hugo_inventory_asset_tag_counter', 0 );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Inventory Settings', 'hugo-inventory' ) . '</h1>';

        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'hugo-inventory' ) . '</p></div>';
        }

        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=hugo-inventory-settings' ) ); ?>">
            <?php wp_nonce_field( 'hugo_inv_save_settings', 'hugo_inv_settings_nonce' ); ?>

            <h2><?php esc_html_e( 'Asset Tag', 'hugo-inventory' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="asset_tag_prefix"><?php esc_html_e( 'Tag Prefix', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <input type="text" id="asset_tag_prefix" name="asset_tag_prefix" class="regular-text" value="<?php echo esc_attr( $s['asset_tag_prefix'] ); ?>" maxlength="10">
                        <p class="description"><?php esc_html_e( 'Prefix for auto-generated asset tags (e.g., HUGO → HUGO-000042).', 'hugo-inventory' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="asset_tag_digits"><?php esc_html_e( 'Number of Digits', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <input type="number" id="asset_tag_digits" name="asset_tag_digits" value="<?php echo esc_attr( $s['asset_tag_digits'] ); ?>" min="1" max="10" class="small-text">
                        <p class="description"><?php echo esc_html( sprintf( __( 'Current counter: %d. Next tag: %s-%s', 'hugo-inventory' ), $counter, $s['asset_tag_prefix'], str_pad( $counter + 1, $s['asset_tag_digits'], '0', STR_PAD_LEFT ) ) ); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Defaults', 'hugo-inventory' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="default_organization"><?php esc_html_e( 'Default Organization', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <select id="default_organization" name="default_organization">
                            <option value=""><?php esc_html_e( '— None —', 'hugo-inventory' ); ?></option>
                            <?php foreach ( $org_options as $oid => $oname ) : ?>
                                <option value="<?php echo esc_attr( $oid ); ?>" <?php selected( $s['default_organization'], $oid ); ?>>
                                    <?php echo esc_html( $oname ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Pre-selected organization when adding a new asset.', 'hugo-inventory' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="items_per_page"><?php esc_html_e( 'Items Per Page', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <input type="number" id="items_per_page" name="items_per_page" value="<?php echo esc_attr( $s['items_per_page'] ); ?>" min="10" max="200" class="small-text">
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Barcode Scanner', 'hugo-inventory' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="scanner_threshold"><?php esc_html_e( 'Scanner Sensitivity (ms)', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <input type="number" id="scanner_threshold" name="scanner_threshold" value="<?php echo esc_attr( $s['scanner_threshold'] ); ?>" min="10" max="500" class="small-text">
                        <p class="description"><?php esc_html_e( 'Maximum milliseconds between keystrokes to detect scanner input. Lower = more strict. Default: 50.', 'hugo-inventory' ); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'QR Code & Labels', 'hugo-inventory' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="qr_payload_format"><?php esc_html_e( 'QR Code Payload', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <select id="qr_payload_format" name="qr_payload_format">
                            <option value="tag_only" <?php selected( $s['qr_payload_format'], 'tag_only' ); ?>>
                                <?php esc_html_e( 'Asset Tag Only (e.g., HUGO-000042)', 'hugo-inventory' ); ?>
                            </option>
                            <option value="full_url" <?php selected( $s['qr_payload_format'], 'full_url' ); ?>>
                                <?php esc_html_e( 'Full URL (scannable by any phone camera)', 'hugo-inventory' ); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="label_code_type"><?php esc_html_e( 'Label Code Type', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <select id="label_code_type" name="label_code_type">
                            <option value="qr" <?php selected( $s['label_code_type'], 'qr' ); ?>><?php esc_html_e( 'QR Code', 'hugo-inventory' ); ?></option>
                            <option value="barcode" <?php selected( $s['label_code_type'], 'barcode' ); ?>><?php esc_html_e( 'Barcode (Code 128)', 'hugo-inventory' ); ?></option>
                            <option value="both" <?php selected( $s['label_code_type'], 'both' ); ?>><?php esc_html_e( 'Both', 'hugo-inventory' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="label_cols"><?php esc_html_e( 'Labels Per Row', 'hugo-inventory' ); ?></label></th>
                    <td>
                        <input type="number" id="label_cols" name="label_cols" value="<?php echo esc_attr( $s['label_cols'] ); ?>" min="1" max="6" class="small-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Label Dimensions', 'hugo-inventory' ); ?></th>
                    <td>
                        <label>
                            <?php esc_html_e( 'Width:', 'hugo-inventory' ); ?>
                            <input type="number" name="label_width_mm" value="<?php echo esc_attr( $s['label_width_mm'] ); ?>" min="20" max="200" class="small-text"> mm
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <?php esc_html_e( 'Height:', 'hugo-inventory' ); ?>
                            <input type="number" name="label_height_mm" value="<?php echo esc_attr( $s['label_height_mm'] ); ?>" min="15" max="150" class="small-text"> mm
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save Settings', 'hugo-inventory' ) ); ?>
        </form>
        <?php
        echo '</div>';
    }
}
