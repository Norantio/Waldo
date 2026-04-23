<?php

namespace Hugo_Inventory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Frontend shortcodes for use in Oxygen Builder (or any editor).
 *
 * [hugo_inv_lookup]     — Search / scan bar with AJAX asset lookup.
 * [hugo_inv_assets]     — Filterable asset table.
 * [hugo_inv_add_asset]  — Add Asset button + modal (standalone).
 * [hugo_inv_checkout]   — Checkout / check-in form for logged-in users.
 * [hugo_inv_stats]      — Status summary cards.
 * [hugo_inv_my_assets]  — Assets assigned to the current user.
 */
class Shortcodes {

    /** Ensures the Add Asset modal HTML is only emitted once per page. */
    private static bool $add_modal_rendered = false;

    public function __construct() {
        add_shortcode( 'hugo_inv_lookup',     [ $this, 'render_lookup' ] );
        add_shortcode( 'hugo_inv_assets',     [ $this, 'render_assets' ] );
        add_shortcode( 'hugo_inv_add_asset',  [ $this, 'render_add_asset' ] );
        add_shortcode( 'hugo_inv_checkout',   [ $this, 'render_checkout' ] );
        add_shortcode( 'hugo_inv_stats',     [ $this, 'render_stats' ] );
        add_shortcode( 'hugo_inv_my_assets', [ $this, 'render_my_assets' ] );
        add_shortcode( 'hugo_inv_dashboard', [ $this, 'render_dashboard' ] );

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
            || has_shortcode( $check, 'hugo_inv_add_asset' )
            || has_shortcode( $check, 'hugo_inv_checkout' )
            || has_shortcode( $check, 'hugo_inv_stats' )
            || has_shortcode( $check, 'hugo_inv_my_assets' )
            || has_shortcode( $check, 'hugo_inv_dashboard' );

        // Oxygen stores content in ct_builder_shortcodes meta.
        if ( ! $has_shortcode ) {
            $oxy = get_post_meta( $post->ID, 'ct_builder_shortcodes', true );
            if ( $oxy && (
                str_contains( $oxy, 'hugo_inv_lookup' )
                || str_contains( $oxy, 'hugo_inv_assets' )
                || str_contains( $oxy, 'hugo_inv_add_asset' )
                || str_contains( $oxy, 'hugo_inv_checkout' )
                || str_contains( $oxy, 'hugo_inv_stats' )
                || str_contains( $oxy, 'hugo_inv_my_assets' )
                || str_contains( $oxy, 'hugo_inv_dashboard' )
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
            'font_size'       => '',   // Base font size for the table, e.g. 14px.
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
        $total        = $result['total'];
        $status_opts  = Models\Asset::status_options();
        $show_filters = ( $atts['show_filters'] === 'yes' );

        $fz         = $atts['font_size'] ? preg_replace( '/[^a-zA-Z0-9.%]/', '', $atts['font_size'] ) : '';
        $style_attr = $fz ? ' style="--hugo-inv-font-size:' . esc_attr( $fz ) . ';--hugo-inv-btn-font-size:' . esc_attr( $fz ) . '"' : '';

        $status_colors = [
            'available'   => '#46b450',
            'checked_out' => '#0073aa',
            'in_repair'   => '#ffb900',
            'retired'     => '#826eb4',
            'lost'        => '#dc3232',
        ];

        ob_start();
        ?>
        <div class="hugo-inv-fe hugo-inv-fe-assets"<?php echo $style_attr; ?>>

            <!-- Toolbar: count -->
            <div class="hugo-inv-fe-assets-toolbar">
                <span class="hugo-inv-fe-assets-count">
                    <span class="hugo-inv-fe-assets-count-visible"><?php echo esc_html( number_format_i18n( count( $items ) ) ); ?></span><?php if ( $total > count( $items ) ) : ?><span class="hugo-inv-fe-assets-count-sep"> <?php esc_html_e( 'of', 'hugo-inventory' ); ?> <?php echo esc_html( number_format_i18n( $total ) ); ?></span><?php endif; ?> <?php esc_html_e( 'assets', 'hugo-inventory' ); ?>
                </span>
            </div>

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
                <table class="hugo-inv-fe-table hugo-inv-fe-assets-table">
                    <thead>
                        <tr>
                            <th class="hugo-inv-fe-sortable" data-col="asset_tag"><?php esc_html_e( 'Asset Tag', 'hugo-inventory' ); ?><span class="hugo-inv-fe-sort-icon" aria-hidden="true"></span></th>
                            <th class="hugo-inv-fe-sortable" data-col="name"><?php esc_html_e( 'Name', 'hugo-inventory' ); ?><span class="hugo-inv-fe-sort-icon" aria-hidden="true"></span></th>
                            <th class="hugo-inv-fe-sortable" data-col="organization"><?php esc_html_e( 'Organization', 'hugo-inventory' ); ?><span class="hugo-inv-fe-sort-icon" aria-hidden="true"></span></th>
                            <th class="hugo-inv-fe-sortable" data-col="location"><?php esc_html_e( 'Location', 'hugo-inventory' ); ?><span class="hugo-inv-fe-sort-icon" aria-hidden="true"></span></th>
                            <th class="hugo-inv-fe-sortable" data-col="status"><?php esc_html_e( 'Status', 'hugo-inventory' ); ?><span class="hugo-inv-fe-sort-icon" aria-hidden="true"></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( $items ) : ?>
                        <?php foreach ( $items as $item ) :
                            $sc = $status_colors[ $item->status ] ?? '#666';
                        ?>
                        <tr data-status="<?php echo esc_attr( $item->status ); ?>"
                            data-search="<?php echo esc_attr( strtolower( $item->asset_tag . ' ' . $item->name . ' ' . ( $item->organization_name ?? '' ) . ' ' . ( $item->location_name ?? '' ) . ' ' . ( $item->serial_number ?? '' ) ) ); ?>"
                            data-asset_tag="<?php echo esc_attr( strtolower( $item->asset_tag ) ); ?>"
                            data-name="<?php echo esc_attr( strtolower( $item->name ) ); ?>"
                            data-organization="<?php echo esc_attr( strtolower( $item->organization_name ?? '' ) ); ?>"
                            data-location="<?php echo esc_attr( strtolower( $item->location_name ?? '' ) ); ?>"
                            data-status-val="<?php echo esc_attr( $item->status ); ?>">
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

    // ── Shortcode: Add Asset button + modal ────────────────────────────

    public function render_add_asset( $atts ): string {
        $atts = shortcode_atts( [
            'label'       => __( 'Add Asset', 'hugo-inventory' ),
            'bg_color'    => '',   // Primary button / accent color. Any CSS color value.
            'text_color'  => '',   // Button text color.
            'font_size'   => '',   // Base font size, e.g. 16px or 1.1rem.
            'radius'      => '',   // Button corner radius, e.g. 4px or 0.
            'modal_width' => '',   // Modal max-width, e.g. 800px or 90vw.
        ], $atts, 'hugo_inv_add_asset' );

        if ( ! current_user_can( 'manage_options' ) ) {
            return '';
        }

        // Build CSS custom-property overrides from shortcode attributes.
        $css_san = static fn( string $v ): string => preg_replace( '/[^a-zA-Z0-9#()%.,\/ -]/', '', $v );
        $vars = [];
        if ( $atts['bg_color'] )    $vars[] = '--hugo-inv-primary:'         . $css_san( $atts['bg_color'] );
        if ( $atts['text_color'] )  $vars[] = '--hugo-inv-primary-text:'    . $css_san( $atts['text_color'] );
        if ( $atts['font_size'] )   $vars[] = '--hugo-inv-font-size:'       . $css_san( $atts['font_size'] )
                                            . ';--hugo-inv-btn-font-size:'   . $css_san( $atts['font_size'] );
        if ( $atts['radius'] )      $vars[] = '--hugo-inv-btn-radius:'      . $css_san( $atts['radius'] );
        if ( $atts['modal_width'] ) $vars[] = '--hugo-inv-modal-max-width:' . $css_san( $atts['modal_width'] );
        $style_attr = $vars ? ' style="' . esc_attr( implode( ';', $vars ) ) . '"' : '';

        ob_start();
        ?>
        <div class="hugo-inv-fe hugo-inv-fe-add-asset-trigger"<?php echo $style_attr; ?>>
            <button type="button" class="hugo-inv-fe-btn hugo-inv-fe-btn-primary hugo-inv-fe-add-btn hugo-inv-fe-open-add-modal">
                <span class="hugo-inv-fe-add-icon" aria-hidden="true">+</span>
                <?php echo esc_html( $atts['label'] ); ?>
            </button>
        <?php if ( ! self::$add_modal_rendered ) :
            self::$add_modal_rendered = true; ?>
        <div id="hugo-inv-add-asset-modal" class="hugo-inv-fe-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="hugo-inv-add-asset-title">
            <div class="hugo-inv-fe-modal">
                <div class="hugo-inv-fe-modal-header">
                    <h2 id="hugo-inv-add-asset-title" class="hugo-inv-fe-modal-title"><?php esc_html_e( 'Add New Asset', 'hugo-inventory' ); ?></h2>
                    <button type="button" class="hugo-inv-fe-modal-close" aria-label="<?php esc_attr_e( 'Close', 'hugo-inventory' ); ?>">&times;</button>
                </div>
                <div class="hugo-inv-fe-modal-body">
                    <form id="hugo-inv-add-asset-form" novalidate>
                        <div class="hugo-inv-fe-modal-grid">
                            <div class="hugo-inv-fe-field hugo-inv-fe-field-full">
                                <label for="hugo-inv-aa-name"><?php esc_html_e( 'Asset Name', 'hugo-inventory' ); ?> <span class="hugo-inv-fe-required" aria-hidden="true">*</span></label>
                                <input type="text" id="hugo-inv-aa-name" name="name" class="hugo-inv-fe-input" required autocomplete="off">
                            </div>
                            <div class="hugo-inv-fe-field">
                                <label for="hugo-inv-aa-org"><?php esc_html_e( 'Organization', 'hugo-inventory' ); ?> <span class="hugo-inv-fe-required" aria-hidden="true">*</span></label>
                                <select id="hugo-inv-aa-org" name="organization_id" class="hugo-inv-fe-select hugo-inv-fe-select-full" required>
                                    <option value=""><?php esc_html_e( 'Loading…', 'hugo-inventory' ); ?></option>
                                </select>
                            </div>
                            <div class="hugo-inv-fe-field">
                                <label for="hugo-inv-aa-status"><?php esc_html_e( 'Status', 'hugo-inventory' ); ?></label>
                                <select id="hugo-inv-aa-status" name="status" class="hugo-inv-fe-select hugo-inv-fe-select-full">
                                    <option value="available"><?php esc_html_e( 'Available', 'hugo-inventory' ); ?></option>
                                    <option value="checked_out"><?php esc_html_e( 'Checked Out', 'hugo-inventory' ); ?></option>
                                    <option value="in_repair"><?php esc_html_e( 'In Repair', 'hugo-inventory' ); ?></option>
                                    <option value="retired"><?php esc_html_e( 'Retired', 'hugo-inventory' ); ?></option>
                                    <option value="lost"><?php esc_html_e( 'Lost', 'hugo-inventory' ); ?></option>
                                </select>
                            </div>
                            <div class="hugo-inv-fe-field">
                                <label for="hugo-inv-aa-tag"><?php esc_html_e( 'Asset Tag', 'hugo-inventory' ); ?> <small><?php esc_html_e( '(auto if blank)', 'hugo-inventory' ); ?></small></label>
                                <input type="text" id="hugo-inv-aa-tag" name="asset_tag" class="hugo-inv-fe-input" placeholder="e.g. HUGO-0042">
                            </div>
                            <div class="hugo-inv-fe-field">
                                <label for="hugo-inv-aa-serial"><?php esc_html_e( 'Serial Number', 'hugo-inventory' ); ?></label>
                                <input type="text" id="hugo-inv-aa-serial" name="serial_number" class="hugo-inv-fe-input">
                            </div>
                            <div class="hugo-inv-fe-field">
                                <label for="hugo-inv-aa-cat"><?php esc_html_e( 'Category', 'hugo-inventory' ); ?></label>
                                <select id="hugo-inv-aa-cat" name="category_id" class="hugo-inv-fe-select hugo-inv-fe-select-full">
                                    <option value=""><?php esc_html_e( '— None —', 'hugo-inventory' ); ?></option>
                                </select>
                            </div>
                            <div class="hugo-inv-fe-field">
                                <label for="hugo-inv-aa-loc"><?php esc_html_e( 'Location', 'hugo-inventory' ); ?></label>
                                <select id="hugo-inv-aa-loc" name="location_id" class="hugo-inv-fe-select hugo-inv-fe-select-full">
                                    <option value=""><?php esc_html_e( '— None —', 'hugo-inventory' ); ?></option>
                                </select>
                            </div>
                            <div class="hugo-inv-fe-field">
                                <label for="hugo-inv-aa-pdate"><?php esc_html_e( 'Purchase Date', 'hugo-inventory' ); ?></label>
                                <input type="date" id="hugo-inv-aa-pdate" name="purchase_date" class="hugo-inv-fe-input">
                            </div>
                            <div class="hugo-inv-fe-field">
                                <label for="hugo-inv-aa-pcost"><?php esc_html_e( 'Purchase Cost ($)', 'hugo-inventory' ); ?></label>
                                <input type="number" id="hugo-inv-aa-pcost" name="purchase_cost" class="hugo-inv-fe-input" min="0" step="0.01" placeholder="0.00">
                            </div>
                            <div class="hugo-inv-fe-field">
                                <label for="hugo-inv-aa-warranty"><?php esc_html_e( 'Warranty Expiration', 'hugo-inventory' ); ?></label>
                                <input type="date" id="hugo-inv-aa-warranty" name="warranty_expiration" class="hugo-inv-fe-input">
                            </div>
                            <div class="hugo-inv-fe-field hugo-inv-fe-field-full">
                                <label for="hugo-inv-aa-desc"><?php esc_html_e( 'Description', 'hugo-inventory' ); ?></label>
                                <textarea id="hugo-inv-aa-desc" name="description" class="hugo-inv-fe-input" rows="3"></textarea>
                            </div>
                        </div><!-- /.hugo-inv-fe-modal-grid -->
                        <div class="hugo-inv-fe-modal-footer">
                            <div class="hugo-inv-fe-message" style="display:none;"></div>
                            <div class="hugo-inv-fe-modal-actions">
                                <button type="button" class="hugo-inv-fe-btn hugo-inv-fe-modal-cancel"><?php esc_html_e( 'Cancel', 'hugo-inventory' ); ?></button>
                                <button type="submit" class="hugo-inv-fe-btn hugo-inv-fe-btn-primary hugo-inv-fe-modal-submit"><?php esc_html_e( 'Add Asset', 'hugo-inventory' ); ?></button>
                            </div>
                        </div>
                    </form>
                </div><!-- /.hugo-inv-fe-modal-body -->
            </div><!-- /.hugo-inv-fe-modal -->
        </div><!-- /#hugo-inv-add-asset-modal -->
        <?php endif; ?>
        </div><!-- /.hugo-inv-fe-add-asset-trigger -->
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

    // ── Shortcode: Dashboard ───────────────────────────────────────────

    /**
     * Renders a full inventory dashboard with stat cards, activity feed,
     * overdue returns, org breakdown, and alerts.
     *
     * Attributes:
     *   organization_id  — optional int, filter all sections to one org
     *   show_stats        — yes|no (default yes)
     *   show_activity     — yes|no (default yes)
     *   show_overdue      — yes|no (default yes)
     *   show_by_org       — yes|no (default yes)
     *   show_alerts       — yes|no (default yes)
     *   activity_limit    — int 1–50 (default 10)
     *   overdue_limit     — int 1–100 (default 10)
     *   alert_days        — int 1–365, warranty window in days (default 30)
     */
    public function render_dashboard( $atts ): string {
        $atts = shortcode_atts( [
            'organization_id' => '',
            'show_stats'      => 'yes',
            'show_activity'   => 'yes',
            'show_overdue'    => 'yes',
            'show_by_org'     => 'yes',
            'show_alerts'     => 'yes',
            'activity_limit'  => 10,
            'overdue_limit'   => 10,
            'alert_days'      => 30,
        ], $atts, 'hugo_inv_dashboard' );

        if ( ! is_user_logged_in() ) {
            return '<div class="hugo-inv-fe hugo-inv-fe-notice">' . esc_html__( 'Please log in to view the dashboard.', 'hugo-inventory' ) . '</div>';
        }

        $org_id         = $atts['organization_id'] ? absint( $atts['organization_id'] ) : null;
        $show_stats     = ( $atts['show_stats']    !== 'no' );
        $show_activity  = ( $atts['show_activity'] !== 'no' );
        $show_overdue   = ( $atts['show_overdue']  !== 'no' );
        $show_by_org    = ( $atts['show_by_org']   !== 'no' );
        $show_alerts    = ( $atts['show_alerts']   !== 'no' );
        $activity_limit = max( 1, min( 50,  absint( $atts['activity_limit'] ) ?: 10 ) );
        $overdue_limit  = max( 1, min( 100, absint( $atts['overdue_limit'] )  ?: 10 ) );
        $alert_days     = max( 1, min( 365, absint( $atts['alert_days'] )     ?: 30 ) );

        // Fetch data for each enabled section.
        $by_status  = $show_stats    ? Models\Asset::count_by_status( $org_id )                        : [];
        $activity   = $show_activity ? Models\Checkout::recent_activity( $activity_limit, $org_id )    : [];
        $overdue    = $show_overdue  ? Models\Checkout::overdue( $overdue_limit, $org_id )             : [];
        $by_org     = $show_by_org   ? Models\Asset::count_by_organization( 15 )                       : [];
        $alerts     = $show_alerts   ? $this->get_dashboard_alerts( $org_id, $alert_days )             : [];

        $status_colors = [
            'available'   => '#46b450',
            'checked_out' => '#0073aa',
            'in_repair'   => '#ffb900',
            'retired'     => '#826eb4',
            'lost'        => '#dc3232',
        ];

        $date_format = get_option( 'date_format' );

        ob_start();
        ?>
        <div class="hugo-inv-fe hugo-inv-fe-dashboard">

            <?php if ( $show_stats ) : ?>
            <div class="hugo-inv-fe-dash-section">
                <div class="hugo-inv-fe-stats">
                    <?php $total = array_sum( $by_status ); ?>
                    <div class="hugo-inv-fe-stat-card" style="border-left-color:#23282d;">
                        <div class="hugo-inv-fe-stat-number"><?php echo esc_html( number_format_i18n( $total ) ); ?></div>
                        <div class="hugo-inv-fe-stat-label"><?php esc_html_e( 'Total Assets', 'hugo-inventory' ); ?></div>
                    </div>
                    <?php foreach ( $by_status as $status => $count ) :
                        $color = $status_colors[ $status ] ?? '#666';
                    ?>
                    <div class="hugo-inv-fe-stat-card" style="border-left-color:<?php echo esc_attr( $color ); ?>;">
                        <div class="hugo-inv-fe-stat-number"><?php echo esc_html( number_format_i18n( $count ) ); ?></div>
                        <div class="hugo-inv-fe-stat-label"><?php echo esc_html( ucwords( str_replace( '_', ' ', $status ) ) ); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $show_activity || $show_overdue ) : ?>
            <div class="hugo-inv-fe-dash-grid">

                <?php if ( $show_activity ) : ?>
                <div class="hugo-inv-fe-dash-panel">
                    <div class="hugo-inv-fe-dash-panel-header">
                        <span class="hugo-inv-fe-dash-panel-title"><?php esc_html_e( 'Recent Activity', 'hugo-inventory' ); ?></span>
                    </div>
                    <?php if ( $activity ) : ?>
                    <ul class="hugo-inv-fe-activity-list">
                        <?php foreach ( $activity as $event ) :
                            $is_out      = ( $event->event === 'checkout' );
                            $dot_mod     = $is_out ? 'checkout' : 'checkin';
                            $action_text = $is_out
                                ? __( 'checked out', 'hugo-inventory' )
                                : __( 'returned', 'hugo-inventory' );
                            $ts = strtotime( $event->event_date );
                        ?>
                        <li class="hugo-inv-fe-activity-item">
                            <span class="hugo-inv-fe-activity-dot hugo-inv-fe-activity-dot--<?php echo esc_attr( $dot_mod ); ?>"></span>
                            <div class="hugo-inv-fe-activity-body">
                                <span class="hugo-inv-fe-activity-text">
                                    <strong><?php echo esc_html( $event->user_name ?? __( 'Unknown', 'hugo-inventory' ) ); ?></strong>
                                    <?php echo esc_html( $action_text ); ?>
                                    <code><?php echo esc_html( $event->asset_tag ); ?></code>
                                    &mdash; <?php echo esc_html( $event->asset_name ); ?>
                                </span>
                                <span class="hugo-inv-fe-activity-time">
                                    <?php echo esc_html( human_time_diff( $ts, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'hugo-inventory' ) ); ?>
                                </span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else : ?>
                    <p class="hugo-inv-fe-dash-empty"><?php esc_html_e( 'No recent activity.', 'hugo-inventory' ); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ( $show_overdue ) : ?>
                <div class="hugo-inv-fe-dash-panel hugo-inv-fe-dash-panel--warn">
                    <div class="hugo-inv-fe-dash-panel-header">
                        <span class="hugo-inv-fe-dash-panel-title"><?php esc_html_e( 'Overdue Returns', 'hugo-inventory' ); ?></span>
                        <?php if ( $overdue ) : ?>
                        <span class="hugo-inv-fe-dash-badge hugo-inv-fe-dash-badge--warn"><?php echo esc_html( count( $overdue ) ); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ( $overdue ) : ?>
                    <div class="hugo-inv-fe-table-wrap">
                        <table class="hugo-inv-fe-table hugo-inv-fe-table--compact">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Asset', 'hugo-inventory' ); ?></th>
                                    <th><?php esc_html_e( 'Checked Out To', 'hugo-inventory' ); ?></th>
                                    <th><?php esc_html_e( 'Days Over', 'hugo-inventory' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $overdue as $row ) : ?>
                                <tr>
                                    <td>
                                        <div><strong><?php echo esc_html( $row->asset_name ); ?></strong></div>
                                        <code><?php echo esc_html( $row->asset_tag ); ?></code>
                                    </td>
                                    <td><?php echo esc_html( $row->user_name ?? '—' ); ?></td>
                                    <td><span class="hugo-inv-fe-overdue-days"><?php echo esc_html( $row->days_overdue ); ?>d</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else : ?>
                    <p class="hugo-inv-fe-dash-empty hugo-inv-fe-dash-empty--good"><?php esc_html_e( 'No overdue returns. ', 'hugo-inventory' ); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
            <?php endif; ?>

            <?php if ( $show_by_org || $show_alerts ) : ?>
            <div class="hugo-inv-fe-dash-grid">

                <?php if ( $show_by_org ) : ?>
                <div class="hugo-inv-fe-dash-panel">
                    <div class="hugo-inv-fe-dash-panel-header">
                        <span class="hugo-inv-fe-dash-panel-title"><?php esc_html_e( 'Assets by Organization', 'hugo-inventory' ); ?></span>
                    </div>
                    <?php if ( $by_org ) :
                        $max_cnt = max( array_column( (array) $by_org, 'cnt' ) );
                    ?>
                    <div class="hugo-inv-fe-org-bars">
                        <?php foreach ( $by_org as $org_row ) :
                            $pct = $max_cnt > 0 ? round( ( $org_row->cnt / $max_cnt ) * 100 ) : 0;
                        ?>
                        <div class="hugo-inv-fe-org-bar-row">
                            <span class="hugo-inv-fe-org-bar-label" title="<?php echo esc_attr( $org_row->name ); ?>"><?php echo esc_html( $org_row->name ); ?></span>
                            <div class="hugo-inv-fe-org-bar-track">
                                <div class="hugo-inv-fe-org-bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%;"></div>
                            </div>
                            <span class="hugo-inv-fe-org-bar-count"><?php echo esc_html( number_format_i18n( (int) $org_row->cnt ) ); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else : ?>
                    <p class="hugo-inv-fe-dash-empty"><?php esc_html_e( 'No organization data.', 'hugo-inventory' ); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ( $show_alerts ) :
                    $alert_total = count( $alerts['at_risk'] ?? [] ) + count( $alerts['warranty'] ?? [] );
                ?>
                <div class="hugo-inv-fe-dash-panel hugo-inv-fe-dash-panel--alert">
                    <div class="hugo-inv-fe-dash-panel-header">
                        <span class="hugo-inv-fe-dash-panel-title"><?php esc_html_e( 'Alerts', 'hugo-inventory' ); ?></span>
                        <?php if ( $alert_total ) : ?>
                        <span class="hugo-inv-fe-dash-badge hugo-inv-fe-dash-badge--alert"><?php echo esc_html( $alert_total ); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ( ! empty( $alerts['at_risk'] ) ) : ?>
                    <div class="hugo-inv-fe-alert-group">
                        <div class="hugo-inv-fe-alert-group-label"><?php esc_html_e( 'At Risk', 'hugo-inventory' ); ?></div>
                        <ul class="hugo-inv-fe-alert-list">
                            <?php foreach ( $alerts['at_risk'] as $ar ) :
                                $sc = $status_colors[ $ar->status ] ?? '#666';
                            ?>
                            <li class="hugo-inv-fe-alert-item">
                                <span class="hugo-inv-fe-status" style="background:<?php echo esc_attr( $sc ); ?>;<?php echo $ar->status === 'in_repair' ? 'color:#23282d;' : ''; ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $ar->status ) ) ); ?></span>
                                <span class="hugo-inv-fe-alert-name" title="<?php echo esc_attr( $ar->name ); ?>"><?php echo esc_html( $ar->name ); ?></span>
                                <code class="hugo-inv-fe-alert-tag"><?php echo esc_html( $ar->asset_tag ); ?></code>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $alerts['warranty'] ) ) : ?>
                    <div class="hugo-inv-fe-alert-group">
                        <div class="hugo-inv-fe-alert-group-label"><?php esc_html_e( 'Warranty Expiring', 'hugo-inventory' ); ?></div>
                        <ul class="hugo-inv-fe-alert-list">
                            <?php foreach ( $alerts['warranty'] as $wa ) : ?>
                            <li class="hugo-inv-fe-alert-item">
                                <span class="hugo-inv-fe-alert-date"><?php echo esc_html( wp_date( $date_format, strtotime( $wa->warranty_expiration ) ) ); ?></span>
                                <span class="hugo-inv-fe-alert-name" title="<?php echo esc_attr( $wa->name ); ?>"><?php echo esc_html( $wa->name ); ?></span>
                                <code class="hugo-inv-fe-alert-tag"><?php echo esc_html( $wa->asset_tag ); ?></code>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <?php if ( ! $alert_total ) : ?>
                    <p class="hugo-inv-fe-dash-empty hugo-inv-fe-dash-empty--good"><?php esc_html_e( 'No alerts.', 'hugo-inventory' ); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Query at-risk assets (lost/in_repair) and warranty-expiring assets for the Alerts panel.
     *
     * @return array{ at_risk: object[], warranty: object[] }
     */
    private function get_dashboard_alerts( ?int $org_id, int $alert_days ): array {
        global $wpdb;
        $t     = $wpdb->prefix . 'inventory_assets';
        $t_org = $wpdb->prefix . 'inventory_organizations';

        // At-risk: lost or in_repair.
        $ar_where  = "a.status IN ('lost','in_repair')";
        $ar_params = [];
        if ( $org_id !== null ) {
            $ar_where   .= ' AND a.organization_id = %d';
            $ar_params[] = $org_id;
        }
        $ar_params[] = 25;

        $at_risk = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
            "SELECT a.id, a.asset_tag, a.name, a.status, o.name AS organization_name
             FROM {$t} a
             LEFT JOIN {$t_org} o ON a.organization_id = o.id
             WHERE {$ar_where}
             ORDER BY a.status, a.name
             LIMIT %d",
            ...$ar_params
        ) ); // phpcs:ignore WordPress.DB.PreparedSQL

        // Warranty expiring within $alert_days.
        $w_where  = 'a.warranty_expiration IS NOT NULL AND a.warranty_expiration <> ""'
                  . ' AND a.warranty_expiration <= DATE_ADD(CURDATE(), INTERVAL %d DAY)'
                  . ' AND a.warranty_expiration >= CURDATE()'
                  . " AND a.status NOT IN ('retired','lost')";
        $w_params = [ $alert_days ];
        if ( $org_id !== null ) {
            $w_where   .= ' AND a.organization_id = %d';
            $w_params[] = $org_id;
        }
        $w_params[] = 20;

        $warranty = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
            "SELECT a.id, a.asset_tag, a.name, a.warranty_expiration, o.name AS organization_name
             FROM {$t} a
             LEFT JOIN {$t_org} o ON a.organization_id = o.id
             WHERE {$w_where}
             ORDER BY a.warranty_expiration ASC
             LIMIT %d",
            ...$w_params
        ) ); // phpcs:ignore WordPress.DB.PreparedSQL

        return [
            'at_risk'  => $at_risk ?: [],
            'warranty' => $warranty ?: [],
        ];
    }
}
