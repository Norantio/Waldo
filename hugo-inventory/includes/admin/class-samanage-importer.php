<?php

namespace Hugo_Inventory\Admin;

use Hugo_Inventory\Models\Asset;
use Hugo_Inventory\Models\Category;
use Hugo_Inventory\Models\Location;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles importing assets from a Samanage hardware export CSV.
 */
class Samanage_Importer {

    /**
     * Map Samanage status values to plugin statuses.
     */
    private const STATUS_MAP = [
        'operational' => 'available',
        'spare'       => 'available',
        'in repair'   => 'in_repair',
        'broken'      => 'retired',
        'disposed'    => 'retired',
        'missing'     => 'lost',
    ];

    /** @var int Organization ID to import into. */
    private int $organization_id;

    /** @var array<string, int> Cache of category name → ID. */
    private array $category_cache = [];

    /** @var array<string, int> Cache of location/site name → ID. */
    private array $location_cache = [];

    /** @var array Import results. */
    private array $results = [
        'created'  => 0,
        'skipped'  => 0,
        'errors'   => [],
    ];

    public function __construct( int $organization_id ) {
        $this->organization_id = $organization_id;
    }

    /**
     * Import assets from a CSV file path.
     *
     * @param string $file_path Absolute path to the uploaded CSV.
     * @return array{ created: int, skipped: int, errors: array }
     */
    public function import( string $file_path ): array {
        $handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( ! $handle ) {
            $this->results['errors'][] = __( 'Could not open CSV file.', 'hugo-inventory' );
            return $this->results;
        }

        // Read header row.
        $headers = fgetcsv( $handle );
        if ( ! $headers ) {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            $this->results['errors'][] = __( 'CSV file is empty or has no header row.', 'hugo-inventory' );
            return $this->results;
        }

        // Normalize headers (trim BOM, whitespace).
        $headers = array_map( function ( $h ) {
            return trim( preg_replace( '/^\x{FEFF}/u', '', $h ) );
        }, $headers );

        $row_number = 1; // 1 = header, data starts at 2.
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $row_number++;

            // Skip empty rows.
            if ( count( $row ) < 2 ) {
                continue;
            }

            // Combine headers + values.
            if ( count( $row ) !== count( $headers ) ) {
                // Pad or trim to match headers.
                $row = array_pad( $row, count( $headers ), '' );
                $row = array_slice( $row, 0, count( $headers ) );
            }

            $record = array_combine( $headers, $row );
            if ( ! $record ) {
                continue;
            }

            $this->import_row( $record, $row_number );
        }

        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

        return $this->results;
    }

    /**
     * Import a single CSV row.
     */
    private function import_row( array $row, int $row_number ): void {
        $name = trim( $row['Name'] ?? '' );
        if ( empty( $name ) ) {
            $this->results['skipped']++;
            return;
        }

        // Check for duplicate by serial number.
        $serial = trim( $row['SSN'] ?? '' );
        if ( ! empty( $serial ) && $this->asset_exists_by_serial( $serial ) ) {
            $this->results['skipped']++;
            return;
        }

        // Build asset data array.
        $data = [
            'name'            => $name,
            'organization_id' => $this->organization_id,
            'serial_number'   => $serial,
            'status'          => $this->map_status( $row['Status'] ?? '' ),
        ];

        // Category.
        $category_name = trim( $row['Category'] ?? '' );
        if ( ! empty( $category_name ) ) {
            $data['category_id'] = $this->resolve_category( $category_name );
        }

        // Location from Site.
        $site_name = trim( $row['Site'] ?? '' );
        if ( ! empty( $site_name ) ) {
            $data['location_id'] = $this->resolve_location( $site_name );
        }

        // Build description from hardware details.
        $data['description'] = $this->build_description( $row );

        // Warranty expiration.
        $warranty_end = trim( $row['Warranty End Date'] ?? '' );
        if ( ! empty( $warranty_end ) ) {
            $parsed = $this->parse_date( $warranty_end );
            if ( $parsed ) {
                $data['warranty_expiration'] = $parsed;
            }
        }

        // MAC address → asset tag if no better tag.
        $samanage_asset_id = trim( $row['Asset ID'] ?? '' );
        if ( ! empty( $samanage_asset_id ) ) {
            // Store Samanage's Asset ID as a reference in meta later.
        }

        // Attempt to create the asset.
        $result = Asset::create( $data );

        if ( is_wp_error( $result ) ) {
            $this->results['errors'][] = sprintf(
                /* translators: 1: row number, 2: asset name, 3: error message */
                __( 'Row %1$d (%2$s): %3$s', 'hugo-inventory' ),
                $row_number,
                $name,
                $result->get_error_message()
            );
            return;
        }

        $asset_id = (int) $result;

        // Store Samanage-specific fields as asset meta.
        $this->save_meta( $asset_id, $row );

        $this->results['created']++;
    }

    /**
     * Map a Samanage status string to a plugin status.
     */
    private function map_status( string $samanage_status ): string {
        $key = strtolower( trim( $samanage_status ) );
        return self::STATUS_MAP[ $key ] ?? 'available';
    }

    /**
     * Resolve a category name to an ID, creating it if necessary.
     */
    private function resolve_category( string $name ): int {
        if ( isset( $this->category_cache[ $name ] ) ) {
            return $this->category_cache[ $name ];
        }

        // Try to find by slug first.
        $slug     = sanitize_title( $name );
        $existing = Category::find_by_slug( $slug );

        if ( $existing ) {
            $this->category_cache[ $name ] = (int) $existing->id;
            return (int) $existing->id;
        }

        // Create new category.
        $id = Category::create( [ 'name' => $name ] );
        if ( $id ) {
            $this->category_cache[ $name ] = $id;
            return $id;
        }

        return 0;
    }

    /**
     * Resolve a site/location name to an ID, creating it if necessary.
     */
    private function resolve_location( string $name ): int {
        if ( isset( $this->location_cache[ $name ] ) ) {
            return $this->location_cache[ $name ];
        }

        $slug     = sanitize_title( $name );
        $existing = Location::find_by_slug( $slug );

        if ( $existing ) {
            $this->location_cache[ $name ] = (int) $existing->id;
            return (int) $existing->id;
        }

        // Create new location scoped to the import org.
        $id = Location::create( [
            'name'            => $name,
            'organization_id' => $this->organization_id,
        ] );
        if ( $id ) {
            $this->location_cache[ $name ] = $id;
            return $id;
        }

        return 0;
    }

    /**
     * Build a rich description string from hardware details.
     */
    private function build_description( array $row ): string {
        $parts = [];

        $manufacturer = trim( $row['Manufacturer'] ?? '' );
        $model        = trim( $row['Model'] ?? '' );
        if ( $manufacturer || $model ) {
            $parts[] = trim( $manufacturer . ' ' . $model );
        }

        $os      = trim( $row['OS'] ?? '' );
        $os_ver  = trim( $row['OS Version'] ?? '' );
        if ( $os ) {
            $parts[] = $os . ( $os_ver ? ' ' . $os_ver : '' );
        }

        $cpu = trim( $row['CPU'] ?? '' );
        if ( $cpu ) {
            $parts[] = 'CPU: ' . $cpu;
        }

        $memory = trim( $row['Memory'] ?? '' );
        if ( $memory && is_numeric( $memory ) ) {
            $gb      = round( (int) $memory / 1024 );
            $parts[] = 'RAM: ' . $gb . ' GB';
        }

        $ip = trim( $row['IP Address'] ?? '' );
        if ( $ip ) {
            $parts[] = 'IP: ' . $ip;
        }

        $mac = trim( $row['Mac Address'] ?? '' );
        if ( $mac ) {
            $parts[] = 'MAC: ' . $mac;
        }

        return implode( "\n", $parts );
    }

    /**
     * Save Samanage-specific fields as asset meta.
     */
    private function save_meta( int $asset_id, array $row ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'inventory_asset_meta';

        $meta_fields = [
            'samanage_id'       => trim( $row['id'] ?? '' ),
            'samanage_asset_id' => trim( $row['Asset ID'] ?? '' ),
            'ip_address'        => trim( $row['IP Address'] ?? '' ),
            'mac_address'       => trim( $row['Mac Address'] ?? '' ),
            'manufacturer'      => trim( $row['Manufacturer'] ?? '' ),
            'model'             => trim( $row['Model'] ?? '' ),
            'os'                => trim( $row['OS'] ?? '' ),
            'os_version'        => trim( $row['OS Version'] ?? '' ),
            'cpu'               => trim( $row['CPU'] ?? '' ),
            'memory_mb'         => trim( $row['Memory'] ?? '' ),
            'owner_name'        => trim( $row['Owner Name'] ?? '' ),
            'owner_email'       => trim( $row['Owner Email'] ?? '' ),
            'samanage_user'     => trim( $row['User'] ?? '' ),
            'samanage_site'     => trim( $row['Site'] ?? '' ),
            'samanage_department' => trim( $row['Department'] ?? '' ),
        ];

        foreach ( $meta_fields as $key => $value ) {
            if ( $value === '' ) {
                continue;
            }
            $wpdb->insert(
                $table,
                [
                    'asset_id'   => $asset_id,
                    'meta_key'   => sanitize_key( $key ),
                    'meta_value' => sanitize_text_field( $value ),
                ],
                [ '%d', '%s', '%s' ]
            );
        }
    }

    /**
     * Parse a Samanage date string (e.g. "2028-05-04 19:00:00 -0500") to Y-m-d.
     */
    private function parse_date( string $date_str ): ?string {
        $timestamp = strtotime( $date_str );
        if ( $timestamp === false ) {
            return null;
        }
        return gmdate( 'Y-m-d', $timestamp );
    }

    /**
     * Check if an asset with this serial number already exists.
     */
    private function asset_exists_by_serial( string $serial ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'inventory_assets';
        $count = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE serial_number = %s", $serial ) // phpcs:ignore WordPress.DB.PreparedSQL
        );
        return $count > 0;
    }
}
