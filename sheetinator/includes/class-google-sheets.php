<?php
/**
 * Sheetinator Google Sheets API Wrapper
 *
 * Handles all interactions with Google Sheets API including:
 * - Creating spreadsheets
 * - Adding/updating sheets
 * - Appending rows
 * - Managing headers
 *
 * @package Sheetinator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sheetinator_Google_Sheets {

    /**
     * Google Sheets API base URL
     */
    const SHEETS_API = 'https://sheets.googleapis.com/v4/spreadsheets';
    const DRIVE_API  = 'https://www.googleapis.com/drive/v3/files';

    /**
     * Option key for storing spreadsheet mappings
     */
    const MAPPING_OPTION = 'sheetinator_sheet_mappings';

    /**
     * @var Sheetinator_Google_Auth Authentication handler
     */
    private $auth;

    /**
     * @var array Cached sheet mappings
     */
    private $mappings = null;

    /**
     * Constructor
     *
     * @param Sheetinator_Google_Auth $auth Authentication handler
     */
    public function __construct( Sheetinator_Google_Auth $auth ) {
        $this->auth = $auth;
    }

    /**
     * Get sheet mappings (form_id => spreadsheet_id)
     *
     * @return array
     */
    public function get_mappings() {
        if ( is_null( $this->mappings ) ) {
            $this->mappings = get_option( self::MAPPING_OPTION, array() );
        }
        return $this->mappings;
    }

    /**
     * Save sheet mapping for a form
     *
     * @param int    $form_id        Forminator form ID
     * @param string $spreadsheet_id Google Spreadsheet ID
     * @param string $spreadsheet_url Google Spreadsheet URL
     * @return bool
     */
    public function save_mapping( $form_id, $spreadsheet_id, $spreadsheet_url = '' ) {
        $mappings = $this->get_mappings();

        $mappings[ $form_id ] = array(
            'spreadsheet_id'  => $spreadsheet_id,
            'spreadsheet_url' => $spreadsheet_url,
            'created_at'      => current_time( 'mysql' ),
        );

        $this->mappings = $mappings;
        return update_option( self::MAPPING_OPTION, $mappings );
    }

    /**
     * Get spreadsheet ID for a form
     *
     * @param int $form_id Forminator form ID
     * @return string|null Spreadsheet ID or null if not mapped
     */
    public function get_spreadsheet_id( $form_id ) {
        $mappings = $this->get_mappings();
        return $mappings[ $form_id ]['spreadsheet_id'] ?? null;
    }

    /**
     * Get spreadsheet URL for a form
     *
     * @param int $form_id Forminator form ID
     * @return string|null Spreadsheet URL or null if not mapped
     */
    public function get_spreadsheet_url( $form_id ) {
        $mappings = $this->get_mappings();
        return $mappings[ $form_id ]['spreadsheet_url'] ?? null;
    }

    /**
     * Check if form has a spreadsheet mapping
     *
     * @param int $form_id Forminator form ID
     * @return bool
     */
    public function has_mapping( $form_id ) {
        $mappings = $this->get_mappings();
        return isset( $mappings[ $form_id ] );
    }

    /**
     * Remove mapping for a form
     *
     * @param int $form_id Forminator form ID
     * @return bool
     */
    public function remove_mapping( $form_id ) {
        $mappings = $this->get_mappings();

        if ( isset( $mappings[ $form_id ] ) ) {
            unset( $mappings[ $form_id ] );
            $this->mappings = $mappings;
            return update_option( self::MAPPING_OPTION, $mappings );
        }

        return true;
    }

    /**
     * Create a new Google Spreadsheet for a form
     *
     * @param string $title   Spreadsheet title
     * @param array  $headers Column headers
     * @return array|WP_Error Spreadsheet data or error
     */
    public function create_spreadsheet( $title, $headers ) {
        $access_token = $this->auth->get_access_token();

        if ( ! $access_token ) {
            return new WP_Error( 'not_authenticated', __( 'Not authenticated with Google.', 'sheetinator' ) );
        }

        // Sanitize title for Google Sheets (max 100 chars, no special chars that cause issues)
        $title = $this->sanitize_sheet_title( $title );

        // Prepare the request body
        $body = array(
            'properties' => array(
                'title' => $title,
            ),
            'sheets' => array(
                array(
                    'properties' => array(
                        'title'   => 'Submissions',
                        'sheetId' => 0,
                    ),
                ),
            ),
        );

        $response = wp_remote_post( self::SHEETS_API, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code !== 200 ) {
            $error_message = $body['error']['message'] ?? __( 'Failed to create spreadsheet.', 'sheetinator' );
            return new WP_Error( 'api_error', $error_message );
        }

        $spreadsheet_id = $body['spreadsheetId'];

        // Add headers to the first row
        $header_result = $this->set_headers( $spreadsheet_id, $headers );

        if ( is_wp_error( $header_result ) ) {
            return $header_result;
        }

        return array(
            'spreadsheet_id'  => $spreadsheet_id,
            'spreadsheet_url' => $body['spreadsheetUrl'],
            'title'           => $title,
        );
    }

    /**
     * Set headers (first row) in a spreadsheet
     *
     * @param string $spreadsheet_id Spreadsheet ID
     * @param array  $headers        Column headers
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function set_headers( $spreadsheet_id, $headers ) {
        $access_token = $this->auth->get_access_token();

        if ( ! $access_token ) {
            return new WP_Error( 'not_authenticated', __( 'Not authenticated with Google.', 'sheetinator' ) );
        }

        // Debug: Log what we're sending to Google
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[Sheetinator] set_headers called with %d headers', count( $headers ) ) );
            error_log( '[Sheetinator] Headers to set: ' . wp_json_encode( array_slice( $headers, 0, 10 ) ) . '...' );
        }

        $range = 'Submissions!A1:' . $this->column_letter( count( $headers ) ) . '1';
        $url   = self::SHEETS_API . '/' . $spreadsheet_id . '/values/' . rawurlencode( $range ) . '?valueInputOption=RAW';

        // Debug: Log the API call
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Sheetinator] API Range: ' . $range );
        }

        $response = wp_remote_request( $url, array(
            'method'  => 'PUT',
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'values' => array( $headers ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[Sheetinator] set_headers WP_Error: ' . $response->get_error_message() );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        // Always log the response for debugging
        error_log( '[Sheetinator] set_headers response code: ' . $status_code );
        error_log( '[Sheetinator] set_headers response body: ' . $response_body );

        if ( $status_code !== 200 ) {
            $body          = json_decode( $response_body, true );
            $error_message = $body['error']['message'] ?? __( 'Failed to set headers.', 'sheetinator' );
            return new WP_Error( 'api_error', $error_message );
        }

        // Format header row (bold, freeze)
        $this->format_header_row( $spreadsheet_id, count( $headers ) );

        return true;
    }

    /**
     * Format the header row (bold text, frozen row)
     *
     * @param string $spreadsheet_id Spreadsheet ID
     * @param int    $column_count   Number of columns
     * @return bool|WP_Error
     */
    private function format_header_row( $spreadsheet_id, $column_count ) {
        $access_token = $this->auth->get_access_token();

        if ( ! $access_token ) {
            return new WP_Error( 'not_authenticated', __( 'Not authenticated with Google.', 'sheetinator' ) );
        }

        $url = self::SHEETS_API . '/' . $spreadsheet_id . ':batchUpdate';

        $requests = array(
            // Freeze the first row
            array(
                'updateSheetProperties' => array(
                    'properties' => array(
                        'sheetId'          => 0,
                        'gridProperties'   => array(
                            'frozenRowCount' => 1,
                        ),
                    ),
                    'fields' => 'gridProperties.frozenRowCount',
                ),
            ),
            // Make header row bold
            array(
                'repeatCell' => array(
                    'range' => array(
                        'sheetId'          => 0,
                        'startRowIndex'    => 0,
                        'endRowIndex'      => 1,
                        'startColumnIndex' => 0,
                        'endColumnIndex'   => $column_count,
                    ),
                    'cell' => array(
                        'userEnteredFormat' => array(
                            'textFormat' => array(
                                'bold' => true,
                            ),
                            'backgroundColor' => array(
                                'red'   => 0.9,
                                'green' => 0.9,
                                'blue'  => 0.9,
                            ),
                        ),
                    ),
                    'fields' => 'userEnteredFormat(textFormat,backgroundColor)',
                ),
            ),
        );

        $response = wp_remote_post( $url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array( 'requests' => $requests ) ),
        ) );

        return ! is_wp_error( $response );
    }

    /**
     * Append a row of data to a spreadsheet
     *
     * @param string $spreadsheet_id Spreadsheet ID
     * @param array  $values         Row values
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function append_row( $spreadsheet_id, $values ) {
        return $this->append_rows( $spreadsheet_id, array( $values ) );
    }

    /**
     * Append multiple rows of data to a spreadsheet (batch operation)
     *
     * @param string $spreadsheet_id Spreadsheet ID
     * @param array  $rows           Array of row arrays
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function append_rows( $spreadsheet_id, $rows ) {
        $access_token = $this->auth->get_access_token();

        if ( ! $access_token ) {
            return new WP_Error( 'not_authenticated', __( 'Not authenticated with Google.', 'sheetinator' ) );
        }

        if ( empty( $rows ) ) {
            return true;
        }

        $range = 'Submissions!A:A';
        $url   = self::SHEETS_API . '/' . $spreadsheet_id . '/values/' . rawurlencode( $range ) . ':append';
        $url  .= '?' . http_build_query( array(
            'valueInputOption' => 'USER_ENTERED',
            'insertDataOption' => 'INSERT_ROWS',
        ) );

        $response = wp_remote_post( $url, array(
            'timeout' => 60, // Longer timeout for batch operations
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'values' => $rows,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 200 ) {
            $body          = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = $body['error']['message'] ?? __( 'Failed to append row.', 'sheetinator' );
            return new WP_Error( 'api_error', $error_message );
        }

        return true;
    }

    /**
     * Get current headers from a spreadsheet
     *
     * @param string $spreadsheet_id Spreadsheet ID
     * @return array|WP_Error Headers array or error
     */
    public function get_headers( $spreadsheet_id ) {
        $access_token = $this->auth->get_access_token();

        if ( ! $access_token ) {
            return new WP_Error( 'not_authenticated', __( 'Not authenticated with Google.', 'sheetinator' ) );
        }

        $range = 'Submissions!1:1';
        $url   = self::SHEETS_API . '/' . $spreadsheet_id . '/values/' . rawurlencode( $range );

        $response = wp_remote_get( $url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code !== 200 ) {
            $error_message = $body['error']['message'] ?? __( 'Failed to get headers.', 'sheetinator' );
            return new WP_Error( 'api_error', $error_message );
        }

        return $body['values'][0] ?? array();
    }

    /**
     * Update headers if form fields have changed
     *
     * @param string $spreadsheet_id Spreadsheet ID
     * @param array  $new_headers    New headers to add
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function update_headers( $spreadsheet_id, $new_headers ) {
        $current_headers = $this->get_headers( $spreadsheet_id );

        if ( is_wp_error( $current_headers ) ) {
            return $current_headers;
        }

        // Find headers that don't exist yet
        $headers_to_add = array_diff( $new_headers, $current_headers );

        if ( empty( $headers_to_add ) ) {
            return true; // No new headers needed
        }

        // Merge headers (existing + new)
        $merged_headers = array_merge( $current_headers, array_values( $headers_to_add ) );

        // Update the header row
        return $this->set_headers( $spreadsheet_id, $merged_headers );
    }

    /**
     * Verify a spreadsheet still exists and is accessible
     *
     * @param string $spreadsheet_id Spreadsheet ID
     * @return bool
     */
    public function verify_spreadsheet( $spreadsheet_id ) {
        $access_token = $this->auth->get_access_token();

        if ( ! $access_token ) {
            return false;
        }

        $url = self::SHEETS_API . '/' . $spreadsheet_id . '?fields=spreadsheetId';

        $response = wp_remote_get( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        return wp_remote_retrieve_response_code( $response ) === 200;
    }

    /**
     * Convert column number to letter (1 = A, 26 = Z, 27 = AA, etc.)
     *
     * @param int $num Column number (1-based)
     * @return string Column letter
     */
    private function column_letter( $num ) {
        $letter = '';
        while ( $num > 0 ) {
            $num--;
            $letter = chr( 65 + ( $num % 26 ) ) . $letter;
            $num    = intval( $num / 26 );
        }
        return $letter;
    }

    /**
     * Sanitize sheet title for Google Sheets
     *
     * @param string $title Original title
     * @return string Sanitized title
     */
    private function sanitize_sheet_title( $title ) {
        // Remove characters that cause issues
        $title = preg_replace( '/[\\\\\/\?\*\[\]]/', '', $title );

        // Limit length
        if ( strlen( $title ) > 100 ) {
            $title = substr( $title, 0, 97 ) . '...';
        }

        // Ensure not empty
        if ( empty( trim( $title ) ) ) {
            $title = 'Forminator Form';
        }

        return $title;
    }
}
