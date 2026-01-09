<?php
/**
 * Sheetinator Google OAuth 2.0 Authentication Handler
 *
 * Handles OAuth 2.0 flow with Google APIs including:
 * - Authorization URL generation
 * - Token exchange
 * - Token refresh
 * - Token storage in wp_options
 *
 * @package Sheetinator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sheetinator_Google_Auth {

    /**
     * Google OAuth endpoints
     */
    const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Required OAuth scopes for Google Sheets
     */
    const SCOPES = array(
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive.file',
    );

    /**
     * Option key for storing credentials
     */
    const CREDENTIALS_OPTION = 'sheetinator_google_credentials';
    const TOKEN_OPTION       = 'sheetinator_google_token';

    /**
     * @var array|null Cached credentials
     */
    private $credentials = null;

    /**
     * @var array|null Cached token
     */
    private $token = null;

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
    }

    /**
     * Check if Google credentials (Client ID & Secret) are configured
     *
     * @return bool
     */
    public function has_credentials() {
        $credentials = $this->get_credentials();
        return ! empty( $credentials['client_id'] ) && ! empty( $credentials['client_secret'] );
    }

    /**
     * Check if we have a valid access token
     *
     * @return bool
     */
    public function is_authenticated() {
        $token = $this->get_token();
        if ( empty( $token['access_token'] ) ) {
            return false;
        }

        // Check if token is expired and try to refresh
        if ( $this->is_token_expired() ) {
            return $this->refresh_token();
        }

        return true;
    }

    /**
     * Get stored credentials
     *
     * @return array
     */
    public function get_credentials() {
        if ( is_null( $this->credentials ) ) {
            $this->credentials = get_option( self::CREDENTIALS_OPTION, array() );
        }
        return $this->credentials;
    }

    /**
     * Save credentials
     *
     * @param string $client_id     Google OAuth Client ID
     * @param string $client_secret Google OAuth Client Secret
     * @return bool
     */
    public function save_credentials( $client_id, $client_secret ) {
        $credentials = array(
            'client_id'     => sanitize_text_field( $client_id ),
            'client_secret' => sanitize_text_field( $client_secret ),
        );

        $this->credentials = $credentials;
        return update_option( self::CREDENTIALS_OPTION, $credentials );
    }

    /**
     * Get stored token
     *
     * @return array
     */
    public function get_token() {
        if ( is_null( $this->token ) ) {
            $this->token = get_option( self::TOKEN_OPTION, array() );
        }
        return $this->token;
    }

    /**
     * Get current access token (refreshing if needed)
     *
     * @return string|false
     */
    public function get_access_token() {
        if ( ! $this->is_authenticated() ) {
            return false;
        }
        $token = $this->get_token();
        return $token['access_token'] ?? false;
    }

    /**
     * Save token
     *
     * @param array $token Token data from Google
     * @return bool
     */
    public function save_token( $token ) {
        // Add expiration timestamp if we have expires_in
        if ( isset( $token['expires_in'] ) && ! isset( $token['expires_at'] ) ) {
            $token['expires_at'] = time() + $token['expires_in'];
        }

        $this->token = $token;
        return update_option( self::TOKEN_OPTION, $token );
    }

    /**
     * Check if current token is expired
     *
     * @return bool
     */
    private function is_token_expired() {
        $token = $this->get_token();

        if ( empty( $token['expires_at'] ) ) {
            return true;
        }

        // Consider token expired 5 minutes before actual expiry
        return time() >= ( $token['expires_at'] - 300 );
    }

    /**
     * Generate OAuth authorization URL
     *
     * @return string|false Authorization URL or false if credentials not set
     */
    public function get_auth_url() {
        $credentials = $this->get_credentials();

        if ( empty( $credentials['client_id'] ) ) {
            return false;
        }

        $state = wp_create_nonce( 'sheetinator_oauth' );
        set_transient( 'sheetinator_oauth_state', $state, HOUR_IN_SECONDS );

        $params = array(
            'client_id'     => $credentials['client_id'],
            'redirect_uri'  => $this->get_redirect_uri(),
            'response_type' => 'code',
            'scope'         => implode( ' ', self::SCOPES ),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        );

        return self::AUTH_URL . '?' . http_build_query( $params );
    }

    /**
     * Get OAuth redirect URI
     *
     * @return string
     */
    public function get_redirect_uri() {
        return admin_url( 'admin.php?page=sheetinator' );
    }

    /**
     * Handle OAuth callback from Google
     */
    public function handle_oauth_callback() {
        // Check if this is an OAuth callback
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'sheetinator' ) {
            return;
        }

        if ( ! isset( $_GET['code'] ) ) {
            return;
        }

        // Verify nonce/state
        $state       = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $saved_state = get_transient( 'sheetinator_oauth_state' );

        if ( empty( $state ) || $state !== $saved_state ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'OAuth state mismatch. Please try authenticating again.', 'sheetinator' ) . '</p></div>';
            });
            return;
        }

        delete_transient( 'sheetinator_oauth_state' );

        // Exchange code for tokens
        $code   = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        $result = $this->exchange_code( $code );

        if ( is_wp_error( $result ) ) {
            set_transient( 'sheetinator_auth_error', $result->get_error_message(), 60 );
        } else {
            set_transient( 'sheetinator_auth_success', true, 60 );
        }

        // Redirect to clean URL
        wp_safe_redirect( admin_url( 'admin.php?page=sheetinator' ) );
        exit;
    }

    /**
     * Exchange authorization code for tokens
     *
     * @param string $code Authorization code from Google
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function exchange_code( $code ) {
        $credentials = $this->get_credentials();

        $response = wp_remote_post( self::TOKEN_URL, array(
            'timeout' => 30,
            'body'    => array(
                'code'          => $code,
                'client_id'     => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'redirect_uri'  => $this->get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error(
                'oauth_error',
                sprintf( '%s: %s', $body['error'], $body['error_description'] ?? 'Unknown error' )
            );
        }

        if ( empty( $body['access_token'] ) ) {
            return new WP_Error( 'oauth_error', __( 'No access token received from Google.', 'sheetinator' ) );
        }

        $this->save_token( $body );
        return true;
    }

    /**
     * Refresh the access token using refresh token
     *
     * @return bool True on success, false on failure
     */
    public function refresh_token() {
        $token       = $this->get_token();
        $credentials = $this->get_credentials();

        if ( empty( $token['refresh_token'] ) ) {
            return false;
        }

        $response = wp_remote_post( self::TOKEN_URL, array(
            'timeout' => 30,
            'body'    => array(
                'refresh_token' => $token['refresh_token'],
                'client_id'     => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'grant_type'    => 'refresh_token',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'Token refresh failed: ' . $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            $this->log_error( 'Token refresh error: ' . $body['error'] );
            return false;
        }

        if ( empty( $body['access_token'] ) ) {
            return false;
        }

        // Preserve refresh token if not returned
        if ( empty( $body['refresh_token'] ) ) {
            $body['refresh_token'] = $token['refresh_token'];
        }

        $this->save_token( $body );
        return true;
    }

    /**
     * Disconnect Google account (revoke and delete tokens)
     *
     * @return bool
     */
    public function disconnect() {
        $token = $this->get_token();

        // Try to revoke token with Google
        if ( ! empty( $token['access_token'] ) ) {
            wp_remote_post( 'https://oauth2.googleapis.com/revoke', array(
                'timeout' => 10,
                'body'    => array( 'token' => $token['access_token'] ),
            ) );
        }

        // Clear stored token
        $this->token = null;
        delete_option( self::TOKEN_OPTION );

        return true;
    }

    /**
     * Log error for debugging
     *
     * @param string $message Error message
     */
    private function log_error( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Sheetinator] ' . $message );
        }
    }
}
