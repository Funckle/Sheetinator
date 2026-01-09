<?php
/**
 * Sheetinator Uninstall Script
 *
 * Cleans up all plugin data when the plugin is uninstalled.
 * This file is called by WordPress when the plugin is deleted
 * through the admin interface.
 *
 * @package Sheetinator
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Remove all plugin options from the database
 */
function sheetinator_uninstall() {
    // Delete main options
    delete_option( 'sheetinator_settings' );
    delete_option( 'sheetinator_google_credentials' );
    delete_option( 'sheetinator_google_token' );
    delete_option( 'sheetinator_sheet_mappings' );
    delete_option( 'sheetinator_sync_log' );

    // Delete transients
    delete_transient( 'sheetinator_oauth_state' );
    delete_transient( 'sheetinator_auth_error' );
    delete_transient( 'sheetinator_auth_success' );
    delete_transient( 'sheetinator_notice' );
    delete_transient( 'sheetinator_sync_errors' );
    delete_transient( 'sheetinator_sync_results' );

    // Clean up any orphaned transients (with expiration)
    global $wpdb;

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '%sheetinator%'
        )
    );
}

// Run uninstall
sheetinator_uninstall();
