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
            HUGO_INV_URL . 'assets/css/admin.css',
            [],
            HUGO_INV_VERSION
        );

        wp_enqueue_script(
            'hugo-inventory-admin',
            HUGO_INV_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            HUGO_INV_VERSION,
            true
        );

        wp_localize_script( 'hugo-inventory-admin', 'hugoInventory', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'restUrl' => rest_url( 'hugo-inventory/v1/' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    /**
     * Dashboard page placeholder.
     */
    public function render_dashboard_page(): void {
        echo '<div class="wrap"><h1>' . esc_html__( 'Inventory Dashboard', 'hugo-inventory' ) . '</h1>';
        echo '<p>' . esc_html__( 'Dashboard coming soon.', 'hugo-inventory' ) . '</p></div>';
    }

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
}
