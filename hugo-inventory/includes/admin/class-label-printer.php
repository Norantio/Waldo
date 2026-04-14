<?php

namespace Hugo_Inventory\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Label printer — generates print-optimized HTML pages for asset labels.
 *
 * Handles both single-asset and bulk label printing.
 * Output is a standalone HTML page with CSS @media print rules,
 * designed for the browser's native Print dialog.
 */
class Label_Printer {

    /**
     * Register the AJAX handler for label printing.
     */
    public static function register(): void {
        add_action( 'wp_ajax_hugo_inv_print_labels', [ self::class, 'handle' ] );
    }

    /**
     * Handle the print labels request.
     *
     * Expects: $_GET['ids'] — comma-separated asset IDs.
     * Optional: $_GET['code_type'] — 'qr', 'barcode', or 'both'. Default: 'qr'.
     * Optional: $_GET['cols'] — labels per row. Default: 3.
     */
    public static function handle(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'hugo-inventory' ) );
        }

        check_admin_referer( 'hugo_inv_print_labels' );

        $ids = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
        if ( empty( $ids ) ) {
            wp_die( __( 'No assets selected.', 'hugo-inventory' ) );
        }

        $asset_ids  = array_map( 'absint', explode( ',', $ids ) );
        $settings   = get_option( 'hugo_inventory_settings', [] );
        $code_type  = isset( $_GET['code_type'] ) ? sanitize_key( $_GET['code_type'] ) : ( $settings['label_code_type'] ?? 'qr' );
        $cols       = isset( $_GET['cols'] ) ? absint( $_GET['cols'] ) : ( $settings['label_cols'] ?? 3 );

        if ( ! in_array( $code_type, [ 'qr', 'barcode', 'both' ], true ) ) {
            $code_type = 'qr';
        }
        if ( $cols < 1 || $cols > 6 ) {
            $cols = 3;
        }

        // Load assets with hydrated data.
        $assets = [];
        foreach ( $asset_ids as $aid ) {
            $a = \Hugo_Inventory\Models\Asset::find( $aid, true );
            if ( $a ) {
                $assets[] = $a;
            }
        }

        if ( empty( $assets ) ) {
            wp_die( __( 'No valid assets found.', 'hugo-inventory' ) );
        }

        // Settings for label dimensions.
        $settings     = get_option( 'hugo_inventory_settings', [] );
        $label_width  = $settings['label_width_mm'] ?? 63;
        $label_height = $settings['label_height_mm'] ?? 30;

        self::render_print_page( $assets, $code_type, $cols, $label_width, $label_height );
        exit;
    }

    /**
     * Output a standalone print-optimized HTML page.
     */
    private static function render_print_page( array $assets, string $code_type, int $cols, int $width_mm, int $height_mm ): void {
        $col_width = round( 100 / $cols, 2 );
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php esc_html_e( 'Hugo Inventory — Print Labels', 'hugo-inventory' ); ?></title>
<style>
    /* Reset */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
        font-size: 10pt;
        color: #000;
        background: #fff;
    }

    /* Screen-only controls bar */
    .controls {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        background: #23282d;
        color: #fff;
        padding: 12px 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        z-index: 999;
        font-size: 13px;
    }
    .controls button {
        background: #0073aa;
        color: #fff;
        border: none;
        padding: 8px 20px;
        border-radius: 3px;
        cursor: pointer;
        font-size: 13px;
    }
    .controls button:hover { background: #005a87; }
    .controls .info { opacity: 0.7; }

    /* Label grid */
    .label-grid {
        display: flex;
        flex-wrap: wrap;
        padding: 50px 10px 10px 10px; /* top padding for controls bar on screen */
    }

    .hugo-inv-label {
        width: <?php echo esc_attr( $col_width ); ?>%;
        min-height: <?php echo esc_attr( $height_mm ); ?>mm;
        padding: 4mm;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border: 1px dashed #ccc;
        page-break-inside: avoid;
    }

    .hugo-inv-label-qr svg,
    .hugo-inv-label-barcode svg {
        max-width: 100%;
        height: auto;
    }

    .hugo-inv-label-qr {
        margin-bottom: 2mm;
    }
    .hugo-inv-label-barcode {
        margin-bottom: 1mm;
    }

    .hugo-inv-label-text {
        text-align: center;
        line-height: 1.3;
    }

    .hugo-inv-label-tag {
        display: block;
        font-family: "Courier New", Courier, monospace;
        font-weight: bold;
        font-size: 9pt;
    }

    .hugo-inv-label-name {
        display: block;
        font-size: 7pt;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .hugo-inv-label-org {
        display: block;
        font-size: 6pt;
        color: #555;
    }

    /* Print styles */
    @media print {
        .controls { display: none !important; }

        .label-grid {
            padding: 0;
        }

        .hugo-inv-label {
            border: none;
            width: <?php echo esc_attr( $col_width ); ?>%;
        }

        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
</head>
<body>

<div class="controls">
    <button onclick="window.print()"><?php esc_html_e( 'Print Labels', 'hugo-inventory' ); ?></button>

    <label for="code-type-select" style="margin-left:8px;"><?php esc_html_e( 'Label Type:', 'hugo-inventory' ); ?></label>
    <select id="code-type-select" onchange="switchCodeType(this.value)" style="padding:4px 8px;border-radius:3px;border:1px solid #555;background:#fff;color:#000;font-size:13px;">
        <option value="qr"<?php selected( $code_type, 'qr' ); ?>><?php esc_html_e( 'QR Code', 'hugo-inventory' ); ?></option>
        <option value="barcode"<?php selected( $code_type, 'barcode' ); ?>><?php esc_html_e( 'Barcode', 'hugo-inventory' ); ?></option>
        <option value="both"<?php selected( $code_type, 'both' ); ?>><?php esc_html_e( 'Both', 'hugo-inventory' ); ?></option>
    </select>

    <label for="cols-select" style="margin-left:8px;"><?php esc_html_e( 'Per Row:', 'hugo-inventory' ); ?></label>
    <select id="cols-select" onchange="switchCodeType(document.getElementById('code-type-select').value)" style="padding:4px 8px;border-radius:3px;border:1px solid #555;background:#fff;color:#000;font-size:13px;">
        <?php for ( $c = 1; $c <= 6; $c++ ) : ?>
            <option value="<?php echo $c; ?>"<?php selected( $cols, $c ); ?>><?php echo $c; ?></option>
        <?php endfor; ?>
    </select>

    <span class="info"><?php echo esc_html( sprintf( __( '%d label(s)', 'hugo-inventory' ), count( $assets ) ) ); ?></span>
    <button onclick="window.close()" style="background:#666;"><?php esc_html_e( 'Close', 'hugo-inventory' ); ?></button>
</div>

<div class="label-grid">
<?php foreach ( $assets as $asset ) : ?>
    <?php echo \Hugo_Inventory\Barcode::render_label( $asset, $code_type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SVG output ?>
<?php endforeach; ?>
</div>

<script>
function switchCodeType(codeType) {
    var url = new URL(window.location.href);
    url.searchParams.set('code_type', codeType);
    url.searchParams.set('cols', document.getElementById('cols-select').value);
    window.location.href = url.toString();
}
</script>
</body>
</html>
        <?php
    }
}
