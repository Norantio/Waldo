<?php

namespace Hugo_Inventory\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Microsoft Entra ID (Azure AD) client — searches users via Microsoft Graph API.
 *
 * Uses the OAuth2 client credentials flow with an App Registration.
 * Required API permission: User.Read.All (Application).
 */
class Entra_Client {

    private string $tenant_id;
    private string $client_id;
    private string $client_secret;

    private const TOKEN_TRANSIENT = 'hugo_inv_entra_token';
    private const GRAPH_BASE      = 'https://graph.microsoft.com/v1.0';

    public function __construct( string $tenant_id, string $client_id, string $client_secret ) {
        $this->tenant_id     = $tenant_id;
        $this->client_id     = $client_id;
        $this->client_secret = $client_secret;
    }

    /**
     * Create an instance from plugin settings, or null if not configured.
     */
    public static function from_settings(): ?self {
        $settings = get_option( 'hugo_inventory_settings', [] );

        if ( empty( $settings['entra_enabled'] ) ) {
            return null;
        }

        $tenant = $settings['entra_tenant_id'] ?? '';
        $client = $settings['entra_client_id'] ?? '';
        $secret = $settings['entra_client_secret_enc'] ?? '';

        if ( ! $tenant || ! $client || ! $secret ) {
            return null;
        }

        $decrypted = self::decrypt_secret( $secret );
        if ( ! $decrypted ) {
            return null;
        }

        return new self( $tenant, $client, $decrypted );
    }

    /**
     * Search Entra users by display name or email.
     *
     * @param string $query Search term (min 2 chars).
     * @param int    $limit Max results.
     * @return array Array of user objects: [ { id, displayName, mail, userPrincipalName } ]
     */
    public function search_users( string $query, int $limit = 10 ): array {
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return [];
        }

        // Sanitize: strip double-quotes (used as $search delimiters).
        $safe_query = str_replace( '"', '', $query );

        // Use $search for tokenized matching (matches anywhere in the name).
        // Requires ConsistencyLevel: eventual header.
        $url = self::GRAPH_BASE . '/users?' . http_build_query( [
            '$search' => '"displayName:' . $safe_query . '" OR "mail:' . $safe_query . '" OR "userPrincipalName:' . $safe_query . '"',
            '$select' => 'id,displayName,mail,userPrincipalName,jobTitle,department',
            '$top'    => $limit,
            '$orderby' => 'displayName',
            '$count'  => 'true',
        ] );

        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization'    => 'Bearer ' . $token,
                'Content-Type'     => 'application/json',
                'ConsistencyLevel' => 'eventual',
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            // Clear cached token on auth failure — it may have expired.
            if ( $code === 401 ) {
                delete_transient( self::TOKEN_TRANSIENT );
            }
            return [];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return $body['value'] ?? [];
    }

    /**
     * Get a single Entra user by Object ID.
     *
     * @return array|null User object or null.
     */
    public function get_user( string $object_id ): ?array {
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return null;
        }

        // Validate UUID format to prevent injection.
        if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $object_id ) ) {
            return null;
        }

        $url = self::GRAPH_BASE . '/users/' . $object_id . '?' . http_build_query( [
            '$select' => 'id,displayName,mail,userPrincipalName,jobTitle,department',
        ] );

        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    /**
     * Test the connection — try to fetch the /me endpoint (app context).
     *
     * @return true|\WP_Error
     */
    public function test_connection() {
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        // Try a minimal Graph call.
        $url = self::GRAPH_BASE . '/users?' . http_build_query( [
            '$select' => 'id',
            '$top'    => 1,
        ] );

        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $msg  = $body['error']['message'] ?? "HTTP {$code}";
            return new \WP_Error( 'graph_error', $msg );
        }

        return true;
    }

    // ── OAuth2 Token ─────────────────────────────────────────────────────

    /**
     * Get an OAuth2 access token, cached in a transient.
     *
     * @return string|\WP_Error Token string or error.
     */
    private function get_access_token() {
        $cached = get_transient( self::TOKEN_TRANSIENT );
        if ( $cached ) {
            return $cached;
        }

        $url = "https://login.microsoftonline.com/{$this->tenant_id}/oauth2/v2.0/token";

        $response = wp_remote_post( $url, [
            'body' => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'scope'         => 'https://graph.microsoft.com/.default',
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'http_error', $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $body['error_description'] ?? $body['error'] ?? "HTTP {$code}";
            return new \WP_Error( 'token_error', $msg );
        }

        $token = $body['access_token'] ?? null;

        if ( ! $token ) {
            return new \WP_Error( 'no_token', __( 'Token response did not contain an access_token.', 'hugo-inventory' ) );
        }

        // Cache for slightly less than the token's lifetime (default ~3600s).
        $expires_in = ( $body['expires_in'] ?? 3600 ) - 60;
        set_transient( self::TOKEN_TRANSIENT, $token, max( 60, $expires_in ) );

        return $token;
    }

    // ── Encryption Helpers ───────────────────────────────────────────────

    /**
     * Encrypt a client secret for storage.
     */
    public static function encrypt_secret( string $plaintext ): string {
        $key = wp_salt( 'auth' );
        $iv  = substr( wp_salt( 'secure_auth' ), 0, 16 );
        $encrypted = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $encrypted );
    }

    /**
     * Decrypt a stored client secret.
     */
    public static function decrypt_secret( string $ciphertext ): ?string {
        $key  = wp_salt( 'auth' );
        $iv   = substr( wp_salt( 'secure_auth' ), 0, 16 );
        $data = base64_decode( $ciphertext, true );
        if ( $data === false ) {
            return null;
        }
        $decrypted = openssl_decrypt( $data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return $decrypted !== false ? $decrypted : null;
    }
}
