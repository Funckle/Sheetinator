# Sheetinator

**Automatically sync all Forminator form submissions to Google Sheets.**

Sheetinator is a WordPress plugin that creates a seamless bridge between [Forminator](https://wpmudev.com/project/forminator/) forms and Google Sheets. One-click setup, zero configuration per form.

## Features

- **Auto-Discovery**: Automatically detects all Forminator forms
- **One-Click Sync**: Creates Google Sheets for all forms with a single click
- **Real-Time Sync**: Form submissions are instantly appended to their corresponding sheet
- **Smart Headers**: Column headers are automatically generated from form field labels
- **Standard Columns**: Each submission includes Entry ID, Date, Time, and User IP
- **Compound Field Support**: Handles name fields, address fields, and other complex field types
- **Error Handling**: Graceful failures with admin notifications
- **Activity Log**: Track recent sync activity and errors

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- [Forminator](https://wordpress.org/plugins/forminator/) plugin (free version works)
- Google account with access to Google Sheets

## Installation

1. Download the plugin and upload the `sheetinator` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Go to **Sheetinator** in the admin menu to configure

## Setup Guide

### Step 1: Create Google API Credentials

1. Go to the [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Navigate to **APIs & Services** > **Library**
4. Enable both:
   - Google Sheets API
   - Google Drive API
5. Go to **APIs & Services** > **Credentials**
6. Click **Create Credentials** > **OAuth 2.0 Client ID**
7. Select **Web application** as the application type
8. Add your redirect URI (shown in the Sheetinator settings page)
9. Copy the **Client ID** and **Client Secret**

### Step 2: Configure the Plugin

1. Go to **Sheetinator** in your WordPress admin
2. Paste your **Client ID** and **Client Secret**
3. Click **Save Credentials**

### Step 3: Connect to Google

1. Click the **Connect with Google** button
2. Authorize the app to access Google Sheets
3. You'll be redirected back to WordPress

### Step 4: Sync Your Forms

1. Click **Sync All Forms**
2. A Google Sheet will be created for each Forminator form
3. New submissions will automatically sync in real-time

## Architecture

```
sheetinator/
├── sheetinator.php              # Main plugin file (bootstrap)
├── uninstall.php                # Cleanup on uninstall
├── includes/
│   ├── class-google-auth.php    # OAuth 2.0 authentication
│   ├── class-google-sheets.php  # Google Sheets API wrapper
│   ├── class-form-discovery.php # Forminator form discovery
│   ├── class-sync-handler.php   # Form submission sync handler
│   └── class-admin.php          # Admin settings page
└── assets/
    ├── css/
    │   └── admin.css            # Admin interface styles
    └── js/
        └── admin.js             # Admin interface scripts
```

### Class Overview

| Class | Purpose |
|-------|---------|
| `Sheetinator` | Main plugin class, singleton pattern, initializes all components |
| `Sheetinator_Google_Auth` | Handles OAuth 2.0 flow, token storage, and refresh |
| `Sheetinator_Google_Sheets` | Wrapper for Google Sheets API (create, append, headers) |
| `Sheetinator_Form_Discovery` | Discovers Forminator forms and extracts field structure |
| `Sheetinator_Sync_Handler` | Handles form submissions and syncs to sheets |
| `Sheetinator_Admin` | Admin settings page and UI |

### Data Storage

All plugin data is stored in `wp_options`:

| Option Key | Purpose |
|------------|---------|
| `sheetinator_settings` | General plugin settings |
| `sheetinator_google_credentials` | OAuth Client ID and Secret |
| `sheetinator_google_token` | Access and refresh tokens |
| `sheetinator_sheet_mappings` | Form ID to Spreadsheet ID mappings |
| `sheetinator_sync_log` | Recent sync activity log |

### Hooks

The plugin hooks into Forminator's submission process:

```php
// Hook into form submission
add_action( 'forminator_custom_form_submit_before_set_fields',
    array( $sync_handler, 'handle_submission' ), 10, 3 );
```

### Extensibility

Filters and actions for future features:

```php
// Filter: Modify headers before creating spreadsheet
apply_filters( 'sheetinator_form_headers', $headers, $form_id );

// Filter: Modify row data before appending
apply_filters( 'sheetinator_row_data', $row_data, $form_id, $entry );

// Action: After successful sync
do_action( 'sheetinator_after_sync', $form_id, $entry_id, $spreadsheet_id );

// Action: After sync error
do_action( 'sheetinator_sync_error', $form_id, $error_message );
```

## Edge Cases Handled

- **Duplicate Sheet Names**: Form ID is appended to ensure uniqueness
- **Form Field Changes**: New fields are appended as new columns
- **Deleted Spreadsheets**: Detected and recreated on next sync
- **Token Expiration**: Automatic token refresh
- **Compound Fields**: Name, address, time fields are split into components
- **Array Values**: Checkboxes and multi-selects are comma-separated
- **Nested Data**: Complex structures are stored as JSON

## Troubleshooting

### "Not authenticated with Google"
- Your token may have expired. Go to Sheetinator settings and reconnect.

### Form submissions not syncing
- Check the Activity Log for errors
- Verify the spreadsheet still exists in Google Drive
- Try clicking "Resync" to create a new spreadsheet

### Missing columns in spreadsheet
- Click "Resync" to recreate the spreadsheet with current form fields
- Note: This creates a new spreadsheet; old data remains in the previous one

## Security

- OAuth tokens are stored encrypted in the WordPress database
- All API requests use HTTPS
- Admin nonces protect all form actions
- Credentials are never exposed in the frontend

## License

GPL v2 or later. See [LICENSE](LICENSE) for details.

## Credits

Built with the KISS principle - Keep It Simple, Stupid.

- Uses WordPress HTTP API for all requests (no external libraries)
- Minimal database usage (just `wp_options`)
- Clean, documented code following WordPress coding standards
