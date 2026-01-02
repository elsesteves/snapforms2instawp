# SnapForms2InstaWP

InstaWP integration for the SnapForms WordPress plugin that creates temporary demo WordPress sites on demand from SnapForms submissions.

- Core entry: `snapforms2instawp.php`
- API client: `includes/InstaWPClient.php`
- Config (external): `wp-content/snapforms/addons/instawp/config.json`

## Overview
This plugin listens to SnapForms submission events and powers a simple two-step flow:

1) A verification email is sent to the requester with a link to confirm creation.
2) On confirmation, a temporary WordPress site is created in InstaWP, demo plugins are installed (and optionally activated), and a confirmation email with access details is sent.

Multiple SnapForms forms are supported, each with its own InstaWP and messaging settings defined in an external JSON config file.

## Key Features
- Per-form configuration in a single external JSON file
- InstaWP site creation with instance settings and optional snapshot
- Demo plugin installation (ZIP URLs) and optional activation via WP-CLI command
- Customizable verification/confirmation pages and emails with placeholders
- External config location (safer across plugin updates)
- Graceful error handling with WordPress notices and `WP_Error`

## Requirements
- WordPress
- SnapForms plugin
- InstaWP API key (Bearer token)

## How It Works
- On each SnapForms submission (`snapforms.submission.new`), the plugin sends a verification email containing a link.
- The user visits the verification page and clicks the CTA to create the demo.
- The plugin calls InstaWP APIs to create the site, waits briefly, installs the configured plugin ZIPs, optionally activates them, and emails the site credentials.
- The submission is marked approved once the site is provisioned.

## Installation
1. Copy this plugin folder into `wp-content/plugins/` and activate it from WordPress admin.
2. Create the external config directory and file:
   - Directory: `wp-content/snapforms/addons/instawp/`
   - File: `config.json`
3. Start from the provided sample and customize:
   - Sample: `wp-content/snapforms/addons/instawp/config_sample.json`
   - Copy it to `config.json` and replace values as needed (form IDs, API token, plugins, messages, etc.).

## Configuration Location
The configuration file lives outside the plugin directory to avoid being overwritten by updates.
- Path: `wp-content/snapforms/addons/instawp/config.json`

The plugin loads and indexes entries by `id_form`.

## Config Schema
Top-level object with a `forms` array. Each entry defines one SnapForms form integration. All keys are strings unless noted.

- `id_form` (string, required): SnapForms internal form ID this config applies to.
- `api_token` (string, required): InstaWP API token (Bearer).
- `title` (string, optional): Title shown on the verification/confirmation pages.
- `user_entry_point` (string, optional): Path appended to the site URL for the CTA (e.g., `/wp-admin/admin.php?page=snapforms`).
- `instance_prefix` (string, optional): Prefix used to generate a unique site name (e.g., `snapforms-demo`).
- `instance_settings` (object, required): Site creation parameters.
  - `php_version` (string): e.g., `8.2`.
  - `expiry_hours` (number): Lifespan for the temporary site.
  - `plan_id` (number): InstaWP plan (≥ 2 typically allows command execution and removes promo pages).
  - `snapshot_slug` (string, optional): If set, site is created from this snapshot.
  - `is_reserved` (bool, optional): Indicates whether the site is reserved
  - `is_shared` (bool, optional)
- `plugins` (array, optional): Plugins to install on the new site.
  - Each item:
    - `slug` (string): Plugin folder slug (used for activation).
    - `url` (string): Direct ZIP download URL.
- `activation` (object, optional): Controls plugin activation via WP-CLI.
  - `enabled` (boolean): Enable activation step.
  - `command_id` (number, optional): InstaWP command ID to use (if pre-created).
  - `argument_name` (string, optional): Command argument placeholder name (e.g., `plugin_slug`).
  - `plan_id` (number, optional): If provided and > 1, enables the activation flow (use when your plan supports commands).
- `delays` (object, optional): Timing controls in seconds.
  - `post_website_creation` (number): Wait after site creation before installing content (default: 30).
  - `pre_plugin_activation` (number): Wait before activating plugins (default: 15).
- `pages` (object, optional): HTML content shown on verification and confirmation pages.
  - `verification` (object): Verification page HTML content
    - `message` (string): HTML with placeholders.
    - `button.label` (string)
    - `button.post_click_label` (string)
  - `confirmation` (object): Confirmation page HTML content
    - `message` (string): HTML with placeholders.
    - `button.label` (string)
- `emails` (object, optional): Verification and confirmation email templates.
  - `verification` (object): Verification email template.
    - `subject` (string)
    - `email_address` (string)
    - `body` (string): HTML with placeholders.
  - `confirmation` (object): Confirmation email template.
    - `subject` (string)
    - `email_address` (string)
    - `body` (string): HTML with placeholders.

### Example Config
```json
{
  "forms": [
    {
      "id_form": "4",
      "api_token": "INSTA_WP_API_TOKEN",
      "title": "SnapForms Demo",
      "user_entry_point": "/wp-admin/admin.php?page=snapforms",
      "instance_prefix": "snapforms-demo",
      "instance_settings": {
        "php_version": "8.2",
        "expiry_hours": 24,
        "plan_id": 2
      },
      "plugins": [
        { "slug": "snapforms_demo", "url": "https://example.com/path/to/snapforms_demo.zip" }
      ],
      "activation": {
        "enabled": true
      },
      "delays": {
        "post_website_creation": 30,
        "pre_plugin_activation": 15
      },
      "pages": {
        "verification": {
          "message": "<p>Greetings, {recipient:name},</p><p>Click below to create your temporary demo site.</p>",
          "button": {
            "label": "Create Demo",
            "post_click_label": "Creating Demo... Please wait."
          }
        },
        "confirmation": {
          "message": "<p><b>Demo Site Created!</b></p><p>URL: <a href='{site:url}' target='_blank'>{site:url}</a></p><p>Username: {site:username}</p><p>Password: {site:password}</p>",
          "button": { "label": "Open Demo" }
        }
      },
      "emails": {
        "verification": {
          "subject": "SnapForms Demo Request",
          "email_address": "sales@example.com",
          "body": "Greetings, {recipient:name},<br><br>Please confirm your demo site request:<br><a href='{vrfy_link}'>{vrfy_link}</a>"
        },
        "confirmation": {
          "subject": "Your SnapForms Demo Site is Ready",
          "email_address": "sales@example.com",
          "body": "Greetings, {recipient:name},<br><br>Your demo site is ready!<br>URL: {site:url}<br>Username: {site:username}<br>Password: {site:password}<br><br>Access the demo here: <a href='{entry_link}'>{entry_link}</a>"
        }
      }
    }
  ]
}
```

## Placeholders
You can use these placeholders inside page messages and email bodies:
- `{recipient:name}`: Name of the submission recipient
- `{vrfy_link}`: Verification link sent in the first email
- `{site:url}`: InstaWP website URL
- `{site:username}` / `{site:password}`: WP admin credentials for the demo site
- `{entry_link}`: Generated website URL plus `user_entry_point` if provided

## Multiple Forms
Add one entry per SnapForms form to the `forms` array. The plugin indexes these entries internally by `id_form` for quick lookups.

## Error Handling
- API request failures and validation issues surface as WordPress notices and `WP_Error` objects.
- Errors may be logged via `error_log` when applicable.

## Uninstallation
Removing the plugin does not delete the external config directory. To fully remove configuration, delete `wp-content/snapforms/addons/instawp/config.json` manually.

## Developers
- Hook consumed: `snapforms.submission.new` (verification email)
- Frontend actions: `snapf2instawp_demo_request`, `snapf2instawp_demo_confirm`
- Core client: `includes/InstaWPClient.php`
  - Creates sites (`createDemo`), installs content (`installPlugins`), and optionally activates plugins (`activatePlugins`).

## Changelog
### 1.0.0
- Initial public release: external config, multi-form support, optional activation, customizable pages and emails.

## Credits
- Author: Eduardo Esteves
- Project page: https://edluis97.github.io/
