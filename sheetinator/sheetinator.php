<?php
/**
 * Plugin Name: Sheetinator
 * Plugin URI: https://github.com/Funckle/Sheetinator
 * Description: Automatically sync all Forminator form submissions to Google Sheets. One-click setup, zero configuration per form.
 * Version: 1.0.0
 * Author: Funckle
 * Author URI: https://funckle.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sheetinator
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'SHEETINATOR_VERSION', '1.0.0' );
define( 'SHEETINATOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHEETINATOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SHEETINATOR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main Sheetinator class - singleton pattern for plugin initialization
 */
final class Sheetinator {

    /**
     * @var Sheetinator Single instance
     */
    private static $instance = null;

    /**
     * @var Sheetinator_Google_Auth Google authentication handler
     */
    public $google_auth;

    /**
     * @var Sheetinator_Google_Sheets Google Sheets API wrapper
     */
    public $google_sheets;

    /**
     * @var Sheetinator_Form_Discovery Form discovery handler
     */
    public $form_discovery;

    /**
     * @var Sheetinator_Sync_Handler Sync handler
     */
    public $sync_handler;

    /**
     * @var Sheetinator_Admin Admin interface
     */
    public $admin;

    /**
     * Get single instance
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - private to enforce singleton
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required class files
     */
    private function load_dependencies() {
        require_once SHEETINATOR_PLUGIN_DIR . 'includes/class-google-auth.php';
        require_once SHEETINATOR_PLUGIN_DIR . 'includes/class-google-sheets.php';
        require_once SHEETINATOR_PLUGIN_DIR . 'includes/class-form-discovery.php';
        require_once SHEETINATOR_PLUGIN_DIR . 'includes/class-sync-handler.php';
        require_once SHEETINATOR_PLUGIN_DIR . 'includes/class-admin.php';
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    /**
     * Initialize plugin components after all plugins loaded
     */
    public function init() {
        // Check if Forminator is active
        if ( ! $this->is_forminator_active() ) {
            add_action( 'admin_notices', array( $this, 'forminator_missing_notice' ) );
            return;
        }

        // Initialize components
        $this->google_auth    = new Sheetinator_Google_Auth();
        $this->google_sheets  = new Sheetinator_Google_Sheets( $this->google_auth );
        $this->form_discovery = new Sheetinator_Form_Discovery();
        $this->sync_handler   = new Sheetinator_Sync_Handler( $this->google_sheets, $this->form_discovery );
        $this->admin          = new Sheetinator_Admin( $this );

        // Hook into Forminator form submissions (after entry is saved)
        // This hook passes ($form_id, $entry_id) and fires after entry is fully saved
        // Using this hook ensures all form data is available for option label mapping
        add_action( 'forminator_custom_form_after_save_entry', array( $this->sync_handler, 'handle_saved_entry' ), 10, 2 );
    }

    /**
     * Check if Forminator plugin is active
     */
    private function is_forminator_active() {
        return class_exists( 'Forminator' ) || defined( 'FORMINATOR_VERSION' );
    }

    /**
     * Show admin notice if Forminator is not active
     */
    public function forminator_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'Sheetinator requires Forminator plugin to be installed and activated.', 'sheetinator' ); ?></strong>
            </p>
        </div>
        <?php
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $defaults = array(
            'sync_status' => array(),
            'last_sync'   => null,
        );

        if ( ! get_option( 'sheetinator_settings' ) ) {
            add_option( 'sheetinator_settings', $defaults );
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception( 'Cannot unserialize singleton' );
    }
}

/**
 * Returns the main instance of Sheetinator
 *
 * @return Sheetinator
 */
function sheetinator() {
    return Sheetinator::instance();
}

// Initialize plugin
sheetinator();
