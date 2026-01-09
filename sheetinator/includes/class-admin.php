<?php
/**
 * Sheetinator Admin Interface
 *
 * Handles the admin settings page including:
 * - Google OAuth credentials setup
 * - Authentication status
 * - Form sync management
 * - Status display
 *
 * @package Sheetinator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sheetinator_Admin {

    /**
     * @var Sheetinator Main plugin instance
     */
    private $plugin;

    /**
     * Constructor
     *
     * @param Sheetinator $plugin Main plugin instance
     */
    public function __construct( Sheetinator $plugin ) {
        $this->plugin = $plugin;

        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
        add_action( 'admin_notices', array( $this, 'show_notices' ) );
    }

    /**
     * Add admin menu item
     */
    public function add_menu() {
        add_menu_page(
            __( 'Sheetinator', 'sheetinator' ),
            __( 'Sheetinator', 'sheetinator' ),
            'manage_options',
            'sheetinator',
            array( $this, 'render_page' ),
            'dashicons-media-spreadsheet',
            30
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_sheetinator' ) {
            return;
        }

        wp_enqueue_style(
            'sheetinator-admin',
            SHEETINATOR_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            SHEETINATOR_VERSION
        );

        wp_enqueue_script(
            'sheetinator-admin',
            SHEETINATOR_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            SHEETINATOR_VERSION,
            true
        );

        wp_localize_script( 'sheetinator-admin', 'sheetinatorAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'sheetinator_admin' ),
            'strings' => array(
                'syncing'      => __( 'Syncing...', 'sheetinator' ),
                'syncComplete' => __( 'Sync complete!', 'sheetinator' ),
                'syncError'    => __( 'Sync failed. Please try again.', 'sheetinator' ),
                'confirmResync' => __( 'This will create a new spreadsheet for this form. Continue?', 'sheetinator' ),
            ),
        ) );
    }

    /**
     * Handle admin actions (form submissions)
     */
    public function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Save credentials
        if ( isset( $_POST['sheetinator_save_credentials'] ) ) {
            check_admin_referer( 'sheetinator_credentials' );

            $client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
            $client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';

            $this->plugin->google_auth->save_credentials( $client_id, $client_secret );

            set_transient( 'sheetinator_notice', array(
                'type'    => 'success',
                'message' => __( 'Credentials saved successfully.', 'sheetinator' ),
            ), 60 );

            wp_safe_redirect( admin_url( 'admin.php?page=sheetinator' ) );
            exit;
        }

        // Disconnect
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'disconnect' ) {
            check_admin_referer( 'sheetinator_disconnect' );

            $this->plugin->google_auth->disconnect();

            set_transient( 'sheetinator_notice', array(
                'type'    => 'success',
                'message' => __( 'Disconnected from Google successfully.', 'sheetinator' ),
            ), 60 );

            wp_safe_redirect( admin_url( 'admin.php?page=sheetinator' ) );
            exit;
        }

        // Sync all forms
        if ( isset( $_POST['sheetinator_sync_all'] ) ) {
            check_admin_referer( 'sheetinator_sync' );

            $results = $this->plugin->sync_handler->sync_all_forms();

            $message = sprintf(
                __( 'Sync complete. Created: %d, Skipped: %d, Errors: %d', 'sheetinator' ),
                count( $results['success'] ),
                count( $results['skipped'] ),
                count( $results['errors'] )
            );

            set_transient( 'sheetinator_notice', array(
                'type'    => empty( $results['errors'] ) ? 'success' : 'warning',
                'message' => $message,
            ), 60 );

            set_transient( 'sheetinator_sync_results', $results, 300 );

            wp_safe_redirect( admin_url( 'admin.php?page=sheetinator' ) );
            exit;
        }

        // Resync single form
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'resync' && isset( $_GET['form_id'] ) ) {
            check_admin_referer( 'sheetinator_resync' );

            $form_id = absint( $_GET['form_id'] );

            // Debug: Get headers before resync to show in notice
            $headers = $this->plugin->form_discovery->build_headers( $form_id );
            $header_count = count( $headers );

            // Store headers in transient for debugging
            set_transient( 'sheetinator_debug_headers', $headers, 300 );

            $result  = $this->plugin->sync_handler->resync_form( $form_id );

            if ( is_wp_error( $result ) ) {
                set_transient( 'sheetinator_notice', array(
                    'type'    => 'error',
                    'message' => sprintf( __( 'Failed to resync form: %s', 'sheetinator' ), $result->get_error_message() ),
                ), 60 );
            } else {
                // Get the new spreadsheet URL
                $spreadsheet_url = $result['spreadsheet_url'] ?? '';
                set_transient( 'sheetinator_notice', array(
                    'type'    => 'success',
                    'message' => sprintf(
                        __( 'Form resynced with %d columns. First headers: [%s]. Spreadsheet: %s', 'sheetinator' ),
                        $header_count,
                        implode( ', ', array_slice( $headers, 0, 6 ) ),
                        $spreadsheet_url
                    ),
                ), 60 );
            }

            wp_safe_redirect( admin_url( 'admin.php?page=sheetinator' ) );
            exit;
        }

        // Import existing entries
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'import' && isset( $_GET['form_id'] ) ) {
            check_admin_referer( 'sheetinator_import' );

            $form_id = absint( $_GET['form_id'] );

            $result = $this->plugin->sync_handler->import_existing_entries( $form_id );

            // Build debug info from result
            $debug_info = '';
            if ( ! empty( $result['debug'] ) ) {
                $debug = $result['debug'];

                $parts = array();

                if ( isset( $debug['sample_entry'] ) ) {
                    $se = $debug['sample_entry'];
                    $parts[] = sprintf(
                        'Entry keys: [%s]',
                        implode( ', ', array_slice( $se['meta_keys_found'], 0, 5 ) ) ?: 'none'
                    );
                    $parts[] = sprintf(
                        'Expected IDs: [%s]',
                        implode( ', ', array_slice( $se['expected_field_ids'], 0, 5 ) )
                    );
                    $parts[] = sprintf(
                        'has_get_meta: %s',
                        $se['has_get_meta'] ? 'yes' : 'no'
                    );
                    if ( ! empty( $se['sample_values'] ) ) {
                        $sample_vals = array();
                        foreach ( $se['sample_values'] as $k => $v ) {
                            $sample_vals[] = $k . '=' . ( is_array( $v ) ? 'array' : substr( (string) $v, 0, 20 ) );
                        }
                        $parts[] = 'Sample: ' . implode( ', ', $sample_vals );
                    }
                }

                if ( isset( $debug['sample_row'] ) ) {
                    $row_preview = array_map( function( $v ) {
                        return is_string( $v ) ? substr( $v, 0, 15 ) : '?';
                    }, $debug['sample_row'] );
                    $parts[] = sprintf( 'Row preview: [%s]', implode( ' | ', $row_preview ) );
                }

                if ( ! empty( $parts ) ) {
                    $debug_info = ' [DEBUG: ' . implode( ' | ', $parts ) . ']';
                }
            }

            if ( ! empty( $result['errors'] ) && $result['imported'] === 0 ) {
                set_transient( 'sheetinator_notice', array(
                    'type'    => 'error',
                    'message' => sprintf(
                        __( 'Import failed: %s', 'sheetinator' ),
                        implode( ', ', array_slice( $result['errors'], 0, 3 ) )
                    ) . $debug_info,
                ), 60 );
            } else {
                $message = sprintf(
                    __( 'Import complete! %d of %d entries imported.', 'sheetinator' ),
                    $result['imported'],
                    $result['total']
                );

                if ( $result['failed'] > 0 ) {
                    $message .= sprintf( __( ' (%d failed)', 'sheetinator' ), $result['failed'] );
                }

                $message .= $debug_info;

                set_transient( 'sheetinator_notice', array(
                    'type'    => $result['failed'] > 0 ? 'warning' : 'success',
                    'message' => $message,
                ), 60 );
            }

            wp_safe_redirect( admin_url( 'admin.php?page=sheetinator' ) );
            exit;
        }
    }

    /**
     * Show admin notices
     */
    public function show_notices() {
        // Check for auth success/error
        if ( get_transient( 'sheetinator_auth_success' ) ) {
            delete_transient( 'sheetinator_auth_success' );
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 esc_html__( 'Successfully connected to Google!', 'sheetinator' ) .
                 '</p></div>';
        }

        $auth_error = get_transient( 'sheetinator_auth_error' );
        if ( $auth_error ) {
            delete_transient( 'sheetinator_auth_error' );
            echo '<div class="notice notice-error is-dismissible"><p>' .
                 esc_html( sprintf( __( 'Google authentication failed: %s', 'sheetinator' ), $auth_error ) ) .
                 '</p></div>';
        }

        // Check for general notices
        $notice = get_transient( 'sheetinator_notice' );
        if ( $notice ) {
            delete_transient( 'sheetinator_notice' );
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr( $notice['type'] ),
                esc_html( $notice['message'] )
            );
        }

        // Show sync errors
        $sync_errors = $this->plugin->sync_handler->get_sync_errors();
        if ( ! empty( $sync_errors ) ) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>' .
                 esc_html__( 'Sheetinator Sync Errors:', 'sheetinator' ) . '</strong></p><ul>';
            foreach ( $sync_errors as $error ) {
                printf(
                    '<li>%s: %s</li>',
                    esc_html( $error['title'] ),
                    esc_html( $error['message'] )
                );
            }
            echo '</ul></div>';
        }
    }

    /**
     * Render admin page
     */
    public function render_page() {
        $google_auth   = $this->plugin->google_auth;
        $google_sheets = $this->plugin->google_sheets;
        $discovery     = $this->plugin->form_discovery;

        $has_credentials  = $google_auth->has_credentials();
        $is_authenticated = $has_credentials && $google_auth->is_authenticated();
        $credentials      = $google_auth->get_credentials();

        // Get sync results if available
        $sync_results = get_transient( 'sheetinator_sync_results' );
        delete_transient( 'sheetinator_sync_results' );
        ?>
        <div class="wrap sheetinator-wrap">
            <h1>
                <span class="dashicons dashicons-media-spreadsheet"></span>
                <?php esc_html_e( 'Sheetinator', 'sheetinator' ); ?>
            </h1>

            <p class="sheetinator-description">
                <?php esc_html_e( 'Automatically sync all Forminator form submissions to Google Sheets.', 'sheetinator' ); ?>
            </p>

            <!-- Step 1: Google API Credentials -->
            <div class="sheetinator-card">
                <h2>
                    <span class="step-number">1</span>
                    <?php esc_html_e( 'Google API Credentials', 'sheetinator' ); ?>
                    <?php if ( $has_credentials ) : ?>
                        <span class="status-badge status-success"><?php esc_html_e( 'Configured', 'sheetinator' ); ?></span>
                    <?php endif; ?>
                </h2>

                <?php if ( ! $has_credentials ) : ?>
                    <div class="setup-instructions">
                        <p><?php esc_html_e( 'To get started, you need to create Google API credentials:', 'sheetinator' ); ?></p>
                        <ol>
                            <li><?php printf(
                                /* translators: %s: Google Cloud Console URL */
                                esc_html__( 'Go to the %s', 'sheetinator' ),
                                '<a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>'
                            ); ?></li>
                            <li><?php esc_html_e( 'Create a new project (or select existing)', 'sheetinator' ); ?></li>
                            <li><?php esc_html_e( 'Enable the Google Sheets API and Google Drive API', 'sheetinator' ); ?></li>
                            <li><?php esc_html_e( 'Go to Credentials → Create Credentials → OAuth 2.0 Client ID', 'sheetinator' ); ?></li>
                            <li><?php esc_html_e( 'Set application type to "Web application"', 'sheetinator' ); ?></li>
                            <li><?php printf(
                                /* translators: %s: Redirect URI */
                                esc_html__( 'Add this redirect URI: %s', 'sheetinator' ),
                                '<code>' . esc_html( $google_auth->get_redirect_uri() ) . '</code>'
                            ); ?></li>
                            <li><?php esc_html_e( 'Copy the Client ID and Client Secret below', 'sheetinator' ); ?></li>
                        </ol>
                    </div>
                <?php endif; ?>

                <form method="post" class="credentials-form">
                    <?php wp_nonce_field( 'sheetinator_credentials' ); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="client_id"><?php esc_html_e( 'Client ID', 'sheetinator' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       id="client_id"
                                       name="client_id"
                                       value="<?php echo esc_attr( $credentials['client_id'] ?? '' ); ?>"
                                       class="regular-text"
                                       placeholder="xxxx.apps.googleusercontent.com">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="client_secret"><?php esc_html_e( 'Client Secret', 'sheetinator' ); ?></label>
                            </th>
                            <td>
                                <input type="password"
                                       id="client_secret"
                                       name="client_secret"
                                       value="<?php echo esc_attr( $credentials['client_secret'] ?? '' ); ?>"
                                       class="regular-text"
                                       autocomplete="new-password">
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" name="sheetinator_save_credentials" class="button button-primary">
                            <?php esc_html_e( 'Save Credentials', 'sheetinator' ); ?>
                        </button>
                    </p>
                </form>
            </div>

            <!-- Step 2: Connect to Google -->
            <div class="sheetinator-card <?php echo ! $has_credentials ? 'disabled' : ''; ?>">
                <h2>
                    <span class="step-number">2</span>
                    <?php esc_html_e( 'Connect to Google', 'sheetinator' ); ?>
                    <?php if ( $is_authenticated ) : ?>
                        <span class="status-badge status-success"><?php esc_html_e( 'Connected', 'sheetinator' ); ?></span>
                    <?php endif; ?>
                </h2>

                <?php if ( ! $has_credentials ) : ?>
                    <p class="description"><?php esc_html_e( 'Please configure your Google API credentials first.', 'sheetinator' ); ?></p>
                <?php elseif ( $is_authenticated ) : ?>
                    <p class="description">
                        <?php esc_html_e( 'You are connected to Google and ready to sync forms.', 'sheetinator' ); ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url( wp_nonce_url(
                            admin_url( 'admin.php?page=sheetinator&action=disconnect' ),
                            'sheetinator_disconnect'
                        ) ); ?>" class="button button-secondary">
                            <?php esc_html_e( 'Disconnect', 'sheetinator' ); ?>
                        </a>
                    </p>
                <?php else : ?>
                    <p class="description">
                        <?php esc_html_e( 'Click the button below to authorize Sheetinator to access Google Sheets on your behalf.', 'sheetinator' ); ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url( $google_auth->get_auth_url() ); ?>" class="button button-primary button-hero">
                            <span class="dashicons dashicons-google"></span>
                            <?php esc_html_e( 'Connect with Google', 'sheetinator' ); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Step 3: Sync Forms -->
            <div class="sheetinator-card <?php echo ! $is_authenticated ? 'disabled' : ''; ?>">
                <h2>
                    <span class="step-number">3</span>
                    <?php esc_html_e( 'Sync Forms', 'sheetinator' ); ?>
                </h2>

                <?php if ( ! $is_authenticated ) : ?>
                    <p class="description"><?php esc_html_e( 'Please connect to Google first.', 'sheetinator' ); ?></p>
                <?php elseif ( ! $discovery->has_forms() ) : ?>
                    <p class="description"><?php esc_html_e( 'No Forminator forms found. Create some forms first!', 'sheetinator' ); ?></p>
                <?php else : ?>
                    <form method="post">
                        <?php wp_nonce_field( 'sheetinator_sync' ); ?>
                        <p>
                            <button type="submit" name="sheetinator_sync_all" class="button button-primary button-hero">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e( 'Sync All Forms', 'sheetinator' ); ?>
                            </button>
                        </p>
                        <p class="description">
                            <?php esc_html_e( 'This will create a Google Sheet for each Forminator form that doesn\'t have one yet.', 'sheetinator' ); ?>
                        </p>
                    </form>

                    <?php if ( $sync_results ) : ?>
                        <div class="sync-results">
                            <h3><?php esc_html_e( 'Sync Results', 'sheetinator' ); ?></h3>

                            <?php if ( ! empty( $sync_results['success'] ) ) : ?>
                                <div class="result-section result-success">
                                    <h4><?php esc_html_e( 'Created', 'sheetinator' ); ?></h4>
                                    <ul>
                                        <?php foreach ( $sync_results['success'] as $item ) : ?>
                                            <li>
                                                <?php echo esc_html( $item['title'] ); ?> -
                                                <a href="<?php echo esc_url( $item['spreadsheet_url'] ); ?>" target="_blank">
                                                    <?php esc_html_e( 'Open Sheet', 'sheetinator' ); ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <?php if ( ! empty( $sync_results['errors'] ) ) : ?>
                                <div class="result-section result-error">
                                    <h4><?php esc_html_e( 'Errors', 'sheetinator' ); ?></h4>
                                    <ul>
                                        <?php foreach ( $sync_results['errors'] as $item ) : ?>
                                            <li><?php echo esc_html( $item['title'] . ': ' . $item['error'] ); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Forms Table -->
                    <h3><?php esc_html_e( 'Form Status', 'sheetinator' ); ?></h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Form', 'sheetinator' ); ?></th>
                                <th><?php esc_html_e( 'Fields', 'sheetinator' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'sheetinator' ); ?></th>
                                <th><?php esc_html_e( 'Spreadsheet', 'sheetinator' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'sheetinator' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $forms_summary = $discovery->get_forms_summary( $google_sheets );
                            foreach ( $forms_summary as $form ) :
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $form['title'] ); ?></strong>
                                        <br>
                                        <small class="form-id">#<?php echo esc_html( $form['id'] ); ?></small>
                                    </td>
                                    <td><?php echo esc_html( $form['field_count'] ); ?></td>
                                    <td>
                                        <?php if ( $form['synced'] ) : ?>
                                            <span class="status-badge status-success">
                                                <?php esc_html_e( 'Synced', 'sheetinator' ); ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="status-badge status-pending">
                                                <?php esc_html_e( 'Not Synced', 'sheetinator' ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( $form['spreadsheet_url'] ) : ?>
                                            <a href="<?php echo esc_url( $form['spreadsheet_url'] ); ?>" target="_blank" class="button button-small">
                                                <span class="dashicons dashicons-external"></span>
                                                <?php esc_html_e( 'Open', 'sheetinator' ); ?>
                                            </a>
                                        <?php else : ?>
                                            <span class="description">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( $form['synced'] ) : ?>
                                            <a href="<?php echo esc_url( wp_nonce_url(
                                                admin_url( 'admin.php?page=sheetinator&action=import&form_id=' . $form['id'] ),
                                                'sheetinator_import'
                                            ) ); ?>"
                                               class="button button-small button-primary"
                                               title="<?php esc_attr_e( 'Import all existing Forminator entries to Google Sheets', 'sheetinator' ); ?>">
                                                <?php esc_html_e( 'Import', 'sheetinator' ); ?>
                                            </a>
                                            <a href="<?php echo esc_url( wp_nonce_url(
                                                admin_url( 'admin.php?page=sheetinator&action=resync&form_id=' . $form['id'] ),
                                                'sheetinator_resync'
                                            ) ); ?>"
                                               class="button button-small"
                                               onclick="return confirm('<?php echo esc_js( __( 'This will create a new spreadsheet for this form. Continue?', 'sheetinator' ) ); ?>')">
                                                <?php esc_html_e( 'Resync', 'sheetinator' ); ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Recent Activity -->
            <?php
            $log = $this->plugin->sync_handler->get_log( 10 );
            if ( ! empty( $log ) ) :
            ?>
                <div class="sheetinator-card">
                    <h2><?php esc_html_e( 'Recent Activity', 'sheetinator' ); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Time', 'sheetinator' ); ?></th>
                                <th><?php esc_html_e( 'Form', 'sheetinator' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'sheetinator' ); ?></th>
                                <th><?php esc_html_e( 'Details', 'sheetinator' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $log as $entry ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $entry['time'] ); ?></td>
                                    <td>
                                        <?php
                                        $form_title = $discovery->get_form_title( $entry['form_id'] );
                                        echo esc_html( $form_title );
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ( $entry['type'] === 'success' ) : ?>
                                            <span class="status-badge status-success">
                                                <?php esc_html_e( 'Success', 'sheetinator' ); ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="status-badge status-error">
                                                <?php esc_html_e( 'Error', 'sheetinator' ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ( $entry['type'] === 'success' ) {
                                            printf(
                                                /* translators: %s: Entry ID */
                                                esc_html__( 'Entry #%s synced', 'sheetinator' ),
                                                esc_html( $entry['entry_id'] )
                                            );
                                        } else {
                                            echo esc_html( $entry['message'] ?? '' );
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
