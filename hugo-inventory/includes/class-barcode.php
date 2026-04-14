<?php

namespace Hugo_Inventory;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\Output\QRGdImagePNG;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGenerator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Barcode & QR code generation utility.
 *
 * Wraps chillerlan/php-qrcode and picqer/php-barcode-generator.
 * All methods are static — no state, no side-effects.
 */
class Barcode {

    /**
     * Generate a QR code as SVG markup.
     *
     * @param string $data    The data to encode (asset tag or URL).
     * @param int    $scale   Module scale factor (pixels per module).
     * @return string SVG markup.
     */
    public static function qr_svg( string $data, int $scale = 5 ): string {
        $options = new QROptions( [
            'outputInterface'      => QRMarkupSVG::class,
            'scale'                => $scale,
            'quietzoneSize'        => 2,
            'svgUseFillAttributes' => true,
            'outputBase64'         => false,
        ] );

        $svg = ( new QRCode( $options ) )->render( $data );

        // Strip XML declaration — it breaks inline HTML embedding.
        $svg = preg_replace( '/<\?xml[^?]*\?>\s*/i', '', $svg );

        // The library outputs SVG without width/height attributes (only viewBox).
        // Add explicit dimensions so the SVG renders at a visible size inline.
        $size = 25 * $scale; // viewBox units * scale
        $svg  = preg_replace(
            '/<svg\b/',
            '<svg width="' . $size . '" height="' . $size . '"',
            $svg,
            1
        );

        return $svg;
    }

    /**
     * Generate a QR code as a base64-encoded PNG data URI.
     *
     * @param string $data  The data to encode.
     * @param int    $scale Module scale factor.
     * @return string data:image/png;base64,... string.
     */
    public static function qr_png( string $data, int $scale = 5 ): string {
        $options = new QROptions( [
            'outputInterface' => QRGdImagePNG::class,
            'scale'           => $scale,
            'quietzoneSize'   => 2,
            'outputBase64'    => true,
        ] );

        return ( new QRCode( $options ) )->render( $data );
    }

    /**
     * Generate a Code 128 barcode as SVG markup.
     *
     * @param string $data        The data to encode (asset tag).
     * @param float  $widthFactor Width of narrowest bar in user units.
     * @param float  $height      Barcode height in user units.
     * @return string SVG markup.
     */
    public static function barcode_svg( string $data, float $widthFactor = 2.0, float $height = 40.0 ): string {
        $generator = new BarcodeGeneratorSVG();
        $svg       = $generator->getBarcode( $data, BarcodeGenerator::TYPE_CODE_128, $widthFactor, $height );

        // Strip XML declaration and DOCTYPE — they break inline HTML embedding.
        $svg = preg_replace( '/<\?xml[^?]*\?>\s*/i', '', $svg );
        $svg = preg_replace( '/<!DOCTYPE[^>]*>\s*/i', '', $svg );

        return $svg;
    }

    /**
     * Generate a Code 128 barcode as a base64-encoded PNG data URI.
     *
     * @param string $data        The data to encode.
     * @param float  $widthFactor Width of narrowest bar.
     * @param float  $height      Barcode height.
     * @return string data:image/png;base64,... string.
     */
    public static function barcode_png( string $data, float $widthFactor = 2.0, float $height = 40.0 ): string {
        $generator = new BarcodeGeneratorPNG();
        $png_data  = $generator->getBarcode( $data, BarcodeGenerator::TYPE_CODE_128, $widthFactor, (int) $height );
        return 'data:image/png;base64,' . base64_encode( $png_data );
    }

    /**
     * Build the QR code payload for a given asset.
     *
     * Respects the plugin setting: 'tag_only' returns just the asset tag,
     * 'full_url' returns a link to the asset detail page.
     *
     * @param object $asset Asset record (needs ->asset_tag and ->id).
     * @return string The payload to encode in the QR code.
     */
    public static function build_qr_payload( object $asset ): string {
        $settings = get_option( 'hugo_inventory_settings', [] );
        $format   = $settings['qr_payload_format'] ?? 'tag_only';

        if ( 'full_url' === $format ) {
            return admin_url( 'admin.php?page=hugo-inventory-assets&action=edit&id=' . (int) $asset->id );
        }

        return $asset->asset_tag;
    }

    /**
     * Generate a complete label HTML block for a single asset.
     *
     * @param object $asset       Asset record (needs asset_tag, name, and optionally organization_name).
     * @param string $code_type   'qr', 'barcode', or 'both'.
     * @return string HTML for one label.
     */
    public static function render_label( object $asset, string $code_type = 'qr' ): string {
        $payload = self::build_qr_payload( $asset );
        $tag     = esc_html( $asset->asset_tag );
        $name    = esc_html( $asset->name ?? '' );
        $org     = esc_html( $asset->organization_name ?? '' );

        $code_html = '';

        if ( 'qr' === $code_type || 'both' === $code_type ) {
            $code_html .= '<div class="hugo-inv-label-qr">' . self::qr_svg( $payload, 3 ) . '</div>';
        }

        if ( 'barcode' === $code_type || 'both' === $code_type ) {
            $code_html .= '<div class="hugo-inv-label-barcode">' . self::barcode_svg( $asset->asset_tag, 1.5, 30 ) . '</div>';
        }

        return '<div class="hugo-inv-label">'
            . $code_html
            . '<div class="hugo-inv-label-text">'
            . '<span class="hugo-inv-label-tag">' . $tag . '</span>'
            . ( $name ? '<span class="hugo-inv-label-name">' . $name . '</span>' : '' )
            . ( $org ? '<span class="hugo-inv-label-org">' . $org . '</span>' : '' )
            . '</div>'
            . '</div>';
    }
}
