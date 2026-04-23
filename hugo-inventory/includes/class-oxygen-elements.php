<?php

namespace Hugo_Inventory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Oxygen Classic custom elements for Hugo Inventory.
 *
 * Registers two sidebar-configurable Oxygen Builder elements:
 *   – Hugo Inv: Add Asset  (button + modal)
 *   – Hugo Inv: Assets Table
 *
 * Additional elements can follow the same pattern at the bottom of this file.
 */
class OxygenElements {

    public function __construct() {
        // OxyEl classes are registered on init with priority 2.
        add_action( 'init', [ $this, 'register' ], 2 );
    }

    public function register(): void {
        if ( ! class_exists( 'OxyEl' ) ) {
            return;
        }
        new Hugo_Inv_Oxygen_Add_Asset();
        new Hugo_Inv_Oxygen_Assets_Table();
    }
}

// ── Element: Add Asset button + modal ─────────────────────────────────────────

class Hugo_Inv_Oxygen_Add_Asset extends \OxyEl {

    public function init(): void {}

    public function afterInit(): void {
        $this->removeApplyParamsButton();
    }

    public function name(): string {
        return 'Hugo Inv: Add Asset';
    }

    public function slug(): string {
        return 'hugo-inv-add-asset';
    }

    public function icon(): string {
        return CT_FW_URI . '/toolbar/UI/oxygen-icons/add-icons/heading.svg';
    }

    public function controls(): void {

        // — Button Label ——————————————————————————————————————————————
        $ctrl = $this->addOptionControl( [
            'type'    => 'textfield',
            'name'    => 'Button Label',
            'slug'    => 'label',
        ] );
        $ctrl->setValue( 'Add Asset' );
        $ctrl->rebuildElementOnChange();

        // — Appearance section ————————————————————————————————————————
        $section = $this->addControlSection( 'appearance', 'Appearance', 'assets/icon.svg', $this );

        $ctrl = $section->addOptionControl( [
            'type'    => 'colorpicker',
            'name'    => 'Button Color',
            'slug'    => 'bg_color',
        ] );
        $ctrl->setValue( '#0073aa' );
        $ctrl->rebuildElementOnChange();

        $ctrl = $section->addOptionControl( [
            'type'    => 'colorpicker',
            'name'    => 'Button Text Color',
            'slug'    => 'text_color',
        ] );
        $ctrl->setValue( '#ffffff' );
        $ctrl->rebuildElementOnChange();

        $ctrl = $section->addOptionControl( [
            'type'    => 'slider-measurebox',
            'name'    => 'Font Size',
            'slug'    => 'font_size',
        ] );
        $ctrl->setUnits( 'px', 'px' );
        $ctrl->rebuildElementOnChange();

        $ctrl = $section->addOptionControl( [
            'type'    => 'slider-measurebox',
            'name'    => 'Border Radius',
            'slug'    => 'radius',
        ] );
        $ctrl->setUnits( 'px', 'px' );
        $ctrl->rebuildElementOnChange();

        $ctrl = $section->addOptionControl( [
            'type'    => 'textfield',
            'name'    => 'Modal Max Width',
            'slug'    => 'modal_width',
        ] );
        $ctrl->setValue( '640px' );
        $ctrl->rebuildElementOnChange();
    }

    public function render( $options, $defaults, $content ): void {
        $atts = [
            'label'       => $options['label']       ?? 'Add Asset',
            'bg_color'    => $options['bg_color']    ?? '',
            'text_color'  => $options['text_color']  ?? '',
            'font_size'   => $options['font_size']   ?? '',
            'radius'      => $options['radius']      ?? '',
            'modal_width' => $options['modal_width'] ?? '',
        ];

        // Strip the unit suffix Oxygen appends to measurebox values when empty.
        foreach ( [ 'font_size', 'radius' ] as $k ) {
            if ( isset( $atts[ $k ] ) && rtrim( $atts[ $k ], 'px emt%' ) === '' ) {
                $atts[ $k ] = '';
            }
        }

        echo ( new Shortcodes() )->render_add_asset( $atts );
    }
}

// ── Element: Assets Table ──────────────────────────────────────────────────────

class Hugo_Inv_Oxygen_Assets_Table extends \OxyEl {

    public function init(): void {}

    public function afterInit(): void {
        $this->removeApplyParamsButton();
    }

    public function name(): string {
        return 'Hugo Inv: Assets Table';
    }

    public function slug(): string {
        return 'hugo-inv-assets-table';
    }

    public function icon(): string {
        return CT_FW_URI . '/toolbar/UI/oxygen-icons/add-icons/heading.svg';
    }

    public function controls(): void {

        // — Filters section ———————————————————————————————————————————
        $filters = $this->addControlSection( 'filters', 'Filters', 'assets/icon.svg', $this );

        $ctrl = $filters->addOptionControl( [
            'type'    => 'textfield',
            'name'    => 'Organization ID',
            'slug'    => 'organization_id',
        ] );
        $ctrl->rebuildElementOnChange();

        $ctrl = $filters->addOptionControl( [
            'type'    => 'dropdown',
            'name'    => 'Status',
            'slug'    => 'status',
        ] );
        $ctrl->setValue( '' );
        $ctrl->setParam( 'items', [
            ''            => 'All',
            'available'   => 'Available',
            'checked_out' => 'Checked Out',
            'in_repair'   => 'In Repair',
            'retired'     => 'Retired',
            'lost'        => 'Lost',
        ] );
        $ctrl->rebuildElementOnChange();

        $ctrl = $filters->addOptionControl( [
            'type'    => 'textfield',
            'name'    => 'Category ID',
            'slug'    => 'category_id',
        ] );
        $ctrl->rebuildElementOnChange();

        $ctrl = $filters->addOptionControl( [
            'type'    => 'textfield',
            'name'    => 'Per Page',
            'slug'    => 'per_page',
        ] );
        $ctrl->setValue( '50' );
        $ctrl->rebuildElementOnChange();

        $ctrl = $filters->addOptionControl( [
            'type'    => 'dropdown',
            'name'    => 'Show Filters Bar',
            'slug'    => 'show_filters',
        ] );
        $ctrl->setValue( 'yes' );
        $ctrl->setParam( 'items', [
            'yes' => 'Yes',
            'no'  => 'No',
        ] );
        $ctrl->rebuildElementOnChange();

        // — Appearance section ————————————————————————————————————————
        $appearance = $this->addControlSection( 'appearance', 'Appearance', 'assets/icon.svg', $this );

        $ctrl = $appearance->addOptionControl( [
            'type'    => 'slider-measurebox',
            'name'    => 'Font Size',
            'slug'    => 'font_size',
        ] );
        $ctrl->setUnits( 'px', 'px' );
        $ctrl->rebuildElementOnChange();
    }

    public function render( $options, $defaults, $content ): void {
        $atts = [
            'organization_id' => $options['organization_id'] ?? '',
            'status'          => $options['status']          ?? '',
            'category_id'     => $options['category_id']     ?? '',
            'per_page'        => $options['per_page']        ?? '50',
            'show_filters'    => $options['show_filters']    ?? 'yes',
            'font_size'       => $options['font_size']       ?? '',
        ];

        // Strip empty measurebox unit remnants.
        if ( isset( $atts['font_size'] ) && rtrim( $atts['font_size'], 'px emt%' ) === '' ) {
            $atts['font_size'] = '';
        }

        echo ( new Shortcodes() )->render_assets( $atts );
    }
}
