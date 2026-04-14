<?php

namespace Hugo_Inventory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Frontend shortcodes for use in Oxygen Builder (or any editor).
 *
 * [hugo_inv_lookup]   — Search / scan bar with AJAX asset lookup.
 * [hugo_inv_assets]   — Filterable asset table.
 * [hugo_inv_checkout] — Checkout / check-in form for logged-in users.
 * [hugo_inv_stats]    — Status summary cards.
 * [hugo_inv_my_assets] — Assets assigned to the current user.
 */
class Shortcodes {

    public function __construct() {
        add_shortcode( 'hugo_inv_lookup',   [ $this, 'render_lookup' ] );
        add_shortcode( 'hugo_inv_assets',   [ $this, 'render_assets' ] );
        add_shortcode( 'hugo_inv_checkout', [ $this, 'render_checkout' ] );
        add_shortcode( 'hugo_inv_stats',    [ $this, 'render_stats' ] );
        add_shortcode( 'hugo_inv_my_assets', [ $this, 'render_my_assets' ] );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX handlers for checkout / check-in (logged-in users).
        add_action( 'wp_ajax_hugo_inv_fe_checkout', [ $this, 'ajax_checkout' ] );
        add_action( 'wp_ajax_hugo_inv_fe_checkin',  [ $this, 'ajax_checkin' ] );
    }

    /**
     * Enqueue frontend CSS/JS only when a shortcode is present.
     */
    public function enqueue_assets(): void {
        global $post;

        if ( ! $post ) {
            return;
        }

        // Check rendered content for our shortcodes (also works with Oxygen).
        $check = $post->post_content ?? '';
        $has_shortcode = has_shortcode( $check, 'hugo_inv_lookup' )
            || has_shortcode( $check, 'hugo_inv_assets' )
            || has_shortcode( $check, 'hugo_inv_checkout' )
            || has_shortcode( $check, 'hugo_inv_stats' )
            || has_shortcode( $check, 'hugo_inv_my_assets' );

        // Oxygen stores content in ct_builder_shortcodes meta.
        if ( ! $has_shortcode ) {
            $oxy = get_post_meta( $post->ID, 'ct_builder_shortcodes', true );
            if ( $oxy && (
                str_contains( $oxy, 'hugo_inv_lookup' )
                || str_contains( $oxy, 'hugo_inv_assets' )
                || str_contains( $oxy, 'hugo_inv_checkout' )
                || str_contains( $oxy, 'hugo_inv_stats' )
                || str_contains( $oxy, 'hugo_inv_my_assets' )
            ) ) {
                $has_shortcode = true;
            }
        }

        if ( ! $has_shortcode ) {
            return;
        }

        wp_enqueue_style(
            'hugo-inventory-frontend',
            HUGO_INV_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            HUGO_INV_VERSION
        );

        wp_enqueue_script(
            'hugo-inventory-frontend',
            HUGO_INV_PLUGIN_URL . 'assets/js/frontend.js',
            [ 'jquery' ],
            HUGO_INV_VERSION,
            true
        );

        wp_localize_script( 'hugo-inventory-frontend', 'hugoInvFE', [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'restUrl'  => rest_url( 'hugo-inventory/v1/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'feNonce'  => wp_create_nonce( 'hugo_inv_frontend' ),
            'loggedIn' => is_user_logged_in(),
            'i18n'     => [
                'searching'  => __( 'Searching…', 'hugo-inventory' ),
                'notFound'   => __( 'Not found', 'hugo-inventory' ),
                'error'      => __( 'Something went wrong.', 'hugo-inventory' ),
                'noAssets'   => __( 'No assets found.', 'hugo-inventory' ),
                'loginReq'   => __( 'You must be logged in to use this feature.', 'hugo-inventory' ),
                'checkoutOk' => __( 'Asset checked out successfully!', 'hugo-inventory' ),
                'checkinOk'  => __( 'Asset checked in successfully!', 'hugo-inventory' ),
            ],
        ] );
    }

    // ── Shortcode: Lookup ──────────────────────────────────────────────

    public function render_lookup( $atts ): string {
        $atts = shortcode_atts( [
            'placeholder' => __( 'Scan or type barcode / asset tag / serial…', 'hugo-inventory' ),
        ], $atts, 'hugo_inv_lookup' );

        ob_start();
        ?>
        <div class="hugo-inv-fe hugo-inv-fe-lookup">
            <div class="hugo-inv-fe-lookup-bar">
                <input type="text" class="hugo-inv-fe-input hugo-inv-fe-lookup-input"
                       placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" autocomplete="off">
                <button type="button" class="hugo-inv-fe-btn hugo-inv-fe-lookup-btn"><?php esc_html_e( 'Look Up', 'hugo-inventory' ); ?></button>
            </div>
            <div class="hugo-inv-fe-lookup-result" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Shortcode: Assets Table ────────────────────────────────────────

    public function render_assets( $atts ): string {
        $atts = shortcode_atts( [
            'organization_id' => '',
            'status'          => '',
            'category_id'     => '',
            'per_page'        => 50,
            'show_filters'    => 'yes',
        ], $atts, 'hugo_inv_assets' );

        $list_args = [
            'per_page' => absint( $atts['per_page'] ) ?: 50,
            'page'     => 1,
        ];
        if ( $atts['organization_id'] ) {
            $list_args['organization_id'] = absint( $atts['organization_id'] );
        }
        if ( $atts['status'] ) {
            $list_args['status'] = sanitize_key( $atts['status'] );
        }
        if ( $atts['category_id'] ) {
            $list_args['category_id'] = absint( $atts['category_id'] );
        }

        $result       = Models\Asset::list( $list_args );
        $items        = $result['items'];
        $status_opts  = Models\Asset::status_options();
        $show_filters = ( $atts['show_filters'] === 'yes' );

        $status_colors = [
            'available'   => '#46b450',
            'checked_out' => '#0073aa',
            'in_repair'   => '#ffb900',
            'retired'     => '#826eb4',
            'lost'        => '#dc3232',
        ];

        ob_start();
        ?>
        <div class="hugo-inv-fe hugo-inv-fe-assets">
            <?php if ( $show_filters ) : ?>
            <div class="hugo-inv-fe-filters">
                <input type="text" class="hugo-inv-fe-input hugo-inv-fe-assets-search" placeholder="<?php esc_attr_e( 'Search assets…', 'hugo-inventory' ); ?>">
                <select class="hugo-inv-fe-select hugo-inv-fe-assets-status">
                    <option value=""><?php esc_html_e( 'All Statuses', 'hugo-inventory' ); ?></option>
                    <?php foreach ( $status_opts as $sk => $sl ) : ?>
                        <option value="<?php echo esc_attr( $sk ); ?>"><?php echo esc_html( $sl ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="hugo-inv-fe-table-wrap">
                <table class="hugo-inv-fe-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Asset Tag', 'hugo-inventory' ); ?></th>
                            <th><?php esc_html_e( 'Name', 'hugo-inventory' ); ?></th>
                            <th><?php esc_html_e( 'Organization', 'hugo-inventory' ); ?></th>
                            <th><?php esc_html_e( 'Location', 'hugo-inventory' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'hugo-inventory' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( $items ) : ?>
                        <?php foreach ( $items as $item ) :
                            $sc = $status_colors[ $item->status ] ?? '#666';
                        ?>
                        <tr data-status="<?php echo esc_attr( $item->status ); ?>"
                            data-search="<?php echo esc_attr( strtolower( $item->asset_tag . ' ' . $item->name . ' ' . ( $item->organization_name ?? '' ) . ' ' . ( $item->location_name ?? '' ) . ' ' . ( $item->serial_number ?? '' ) ) ); ?>">
                            <td><code><?php echo esc_html( $item->asset_tag ); ?></code></td>
                            <td><?php echo esc_html( $item->name ); ?></td>
                            <td><?php echo esc_html( $item->organization_name ?? '—' ); ?></td>
                            <td><?php echo esc_html( $item->location_name ?? '—' ); ?></td>
                            <td><span class="hugo-inv-fe-status" style="background:<?php echo esc_attr( $sc ); ?>;<?php echo $item->status === 'in_repair' ? 'color:#23282d;' : ''; ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $item->status ) ) ); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="5" class="hugo-inv-fe-empty"><?php esc_html_e( 'No assets found.', 'hugo-inventory' ); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Shortcode: Checkout / Check-in ─────────────────────────────────

    public function render_checkout( $atts ): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="hugo-inv-fe hugo-inv-fe-notice">' . esc_html__( 'Please log in to check out or return assets.', 'hugo-inventory' ) . '</div>';
        }

        ob_start();
        ?>
        <div class="hugo-inv-fe hugo-inv-fe-checkout">
            <div class="hugo-inv-fe-checkout-tabs">
                <button type="button" class="hugo-inv-fe-tab active" data-tab="checkout"><?php esc_html_e( 'Check Out', 'hugo-inventory' ); ?></button>
                <button type="button" class="hugo-inv-fe-tab" data-tab="checkin"><?php esc_html_e( 'Check In', 'hugo-inventory' ); ?></button>
            </div>

            <!-- Checkout form -->
            <div class="hugo-inv-fe-tab-content active" id="hugo-inv-fe-tab-checkout">
                <form class="hugo-inv-fe-form" id="hugo-inv-fe-checkout-form">
                    <?php wp_nonce_field( 'hugo_inv_frontend', '_hugo_inv_fe_nonce' ); ?>
                    <div class="hugo-inv-fe-field">
                        <label><?php esc_html_e( 'Asset (scan or type)', 'hugo-inventory' ); ?></label>
                        <input type="text" name="asset_lookup" class="hugo-inv-fe-input hugo-inv-fe-scan-field" placeholder="<?php esc_attr_e( 'Barcode / asset tag / serial…', 'hugo-inventory' ); ?>" required autocomplete="off">
                        <input type="hidden" name="asset_id" value="">
                        <div class="hugo-inv-fe-asset-preview" style="display:none;"></div>
                    </div>
                    <div class="hugo-inv-fe-field">
                        <label><?php esc_html_e( 'Expected Return Date', 'hugo-inventory' ); ?></label>
                        <input type="date" name="expected_return_date" class="hugo-inv-fe-input">
                    </div>
                    <div class="hugo-inv-fe-field">
                        <label><?php esc_html_e( 'Notes', 'hugo-inventory' ); ?></label>
                        <textarea name="checkout_notes" class="hugo-inv-fe-input" rows="3"></textarea>
                    </div>
                    <button type="submit" class="hugo-inv-fe-btn hugo-inv-fe-btn-primary"><?php esc_html_e( 'Check Out Asset', 'hugo-inventory' ); ?></button>
                    <div class="hugo-inv-fe-message" style="display:none;"></div>
                </form>
            </div>

            <!-- Check-in form -->
            <div class="hugo-inv-fe-tab-content" id="hugo-inv-fe-tab-checkin">
                <form class="hugo-inv-fe-form" id="hugo-inv-fe-checkin-form">
                    <?php wp_nonce_field( 'hugo_inv_frontend', '_hugo_inv_fe_nonce2' ); ?>
                    <div class="hugo-inv-fe-field">
                        <label><?php esc_html_e( 'Asset (scan or type)', 'hugo-inventory' ); ?></label>
                        <input type="text" name="asset_lookup" class="hugo-inv-fe-input hugo-inv-fe-scan-field" placeholder="<?php esc_attr_e( 'Barcode / asset tag / serial…', 'hugo-inventory' ); ?>" required autocomplete="off">
                        <input type="hidden" name="asset_id" value="">
                        <div class="hugo-inv-fe-asset-preview" style="display:none;"></div>
                    </div>
                    <div class="hugo-inv-fe-field">
                        <label><?php esc_html_e( 'Notes', 'hugo-inventory' ); ?></label>
                        <textarea name="checkin_notes" class="hugo-inv-fe-input" rows="3"></textarea>
                    </div>
                    <button type="submit" class="hugo-inv-fe-btn hugo-inv-fe-btn-primary"><?php esc_html_e( 'Check In Asset', 'hugo-inventory' ); ?></button>
                    <div class="hugo-inv-fe-message" style="display:none;"></div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Shortcode: Stats ───────────────────────────────────────────────

    public function render_stats( $atts ): string {
        $atts = shortcode_atts( [
            'organization_id' => '',
        ], $atts, 'hugo_inv_stats' );

        $org_id    = $atts['organization_id'] ? absint( $atts['organization_id'] ) : null;
        $by_status = Models\Asset::count_by_status( $org_id );
        $total     = array_sum( $by_status );

        $colors = [
            'available'   => '#46b450',
            'checked_out' => '#0073aa',
            'in_repair'   => '#ffb900',
            'retired'     => '#826eb4',
            'lost'        => '#dc3232',
        ];

        ob_start();
        ?>
        <div class="hugo-inv-fe hugo-inv-fe-stats">
            <div class="hugo-inv-fe-stat-card" style="border-left-color:#23282d;">
                <div class="hugo-inv-fe-stat-number"><?php echo esc_html( number_format_i18n( $total ) ); ?></div>
                <div class="hugo-inv-fe-stat-label"><?php esc_html_e( 'Total Assets', 'hugo-inventory' ); ?></div>
            </div>
            <?php foreach ( $by_status as $status => $count ) :
                $color = $colors[ $status ] ?? '#666';
            ?>
            <div class="hugo-inv-fe-stat-card" style="border-left-color:<?php echo esc_attr( $color ); ?>;">
                <div class="hugo-inv-fe-stat-number"><?php echo esc_html( number_format_i18n( $count ) ); ?></div>
                <div class="hugo-inv-fe-stat-label"><?php echo esc_html( ucwords( str_replace( '_', ' ', $status ) ) ); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Shortcode: My Assets ───────────────────────────────────────────

    public function render_my_assets( $atts ): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="hugo-inv-fe hugo-inv-fe-notice">' . esc_html__( 'Please log in to view your assets.', 'hugo-inventory' ) . '</div>';
        }

        $user_id = get_current_user_id();
        $items   = Models\Checkout::checked_out_to_user( $user_id );

        ob_start();
        ?>
        <div class="hugo-inv-fe hugo-inv-fe-my-assets">
            <h3 class="hugo-inv-fe-heading"><?php esc_html_e( 'My Checked-Out Assets', 'hugo-inventory' ); ?></h3>
            <?php if ( $items ) : ?>
            <div class="hugo-inv-fe-table-wrap">
                <table class="hugo-inv-fe-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Asset Tag', 'hugo-inventory' ); ?></th>
                            <th><?php esc_html_e( 'Name', 'hugo-inventory' ); ?></th>
                            <th><?php esc_html_e( 'Organization', 'hugo-inventory' ); ?></th>
                            <th><?php esc_html_e( 'Checked Out', 'hugo-inventory' ); ?></th>
                            <th><?php esc_html_e( 'Expected Return', 'hugo-inventory' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $items as $item ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $item->asset_tag ); ?></code></td>
                            <td><?php echo esc_html( $item->name ); ?></td>
                            <td><?php echo esc_html( $item->organization_name ?? '—' ); ?></td>
                            <td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->checkout_date ) ) ); ?></td>
                            <td><?php echo $item->expected_return_date ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->expected_return_date ) ) ) : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else : ?>
            <p class="hugo-inv-fe-empty-text"><?php esc_html_e( 'You have no assets checked out.', 'hugo-inventory' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── AJAX: Checkout ─────────────────────────────────────────────────

    public function ajax_checkout(): void {
        check_ajax_referer( 'hugo_inv_frontend', '_hugo_inv_fe_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'hugo-inventory' ) ], 403 );
        }

        $result = Models\Checkout::checkout( [
            'asset_id'             => absint( $_POST['asset_id'] ?? 0 ),
            'checked_out_to'       => get_current_user_id(),
            'checked_out_by'       => get_current_user_id(),
            'expected_return_date' => sanitize_text_field( $_POST['expected_return_date'] ?? '' ),
            'checkout_notes'       => sanitize_textarea_field( $_POST['checkout_notes'] ?? '' ),
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => __( 'Asset checked out successfully!', 'hugo-inventory' ), 'checkout_id' => $result ] );
    }

    public function ajax_checkin(): void {
        check_ajax_referer( 'hugo_inv_frontend', '_hugo_inv_fe_nonce2' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'hugo-inventory' ) ], 403 );
        }

        $result = Models\Checkout::checkin( [
            'asset_id'      => absint( $_POST['asset_id'] ?? 0 ),
            'checkin_by'    => get_current_user_id(),
            'checkin_notes' => sanitize_textarea_field( $_POST['checkin_notes'] ?? '' ),
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => __( 'Asset checked in successfully!', 'hugo-inventory' ) ] );
    }
}
