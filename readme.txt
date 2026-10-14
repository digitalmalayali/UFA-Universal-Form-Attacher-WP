=== Forms to Google Sheets (Webhook) ===
Contributors: oxygendigitalshop
Tags: google sheets, contact form 7, wpforms, elementor, webhook
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later

Send form submissions from any popular form plugin to Google Sheets through a Google Apps Script webhook. No Google Cloud project or API keys needed.

== Description ==

Supported form plugins:

* Contact Form 7 (old entries via Flamingo or CFDB7)
* WPForms (old entries with WPForms Pro)
* Gravity Forms
* Elementor Pro Forms
* Fluent Forms
* Ninja Forms
* Formidable Forms
* Forminator
* Everest Forms
* JetFormBuilder
* MetForm
* Divi Contact Form
* Bricks Builder Form ("Custom" action)
* Caldera Forms
* Any plain HTML form (add data-ftgs-form="name" to the <form> tag)

Features:

* Every submission is saved in WordPress first, so no lead is lost.
* Background sending: visitors never wait for Google.
* Automatic retries (1 min, 5 min, 15 min, 1 h, 6 h), then an email alert.
* Resend one, many or all failed submissions.
* Sync existing entries from the form plugin's database or from the local store.
* Duplicate protection: every row carries an Entry ID; the script skips IDs already in the sheet.
* "All form plugins" connections, or pick specific forms per plugin.
* Conditional sending (==, !=, contains, >, <, is empty…), column renaming, column order, exclusions.
* Optional Timestamp, Form, Form plugin, Page URL, IP, User agent, WP user columns.
* Contact Form 7 uploads are kept and linked in the sheet.
* Submissions screen with search, filters and CSV export.
* Export / import connections between sites.
* GDPR: integrates with Tools > Export / Erase Personal Data; data retention setting.
* Spam protection for HTML forms: same-site check, minimum fill time, rate limit, optional honeypot.
* Formula-injection protection; leading-zero and +country-code phone numbers preserved.

== Installation ==

1. Upload the plugin zip in Plugins > Add New > Upload, then activate.
2. Go to Forms → Sheets > Add Connection.
3. Copy the generated Apps Script into your Google Sheet (Extensions > Apps Script) and save.
4. Deploy > New deployment > Web app (Execute as: Me, Who has access: Anyone). Copy the /exec URL.
5. Paste the URL into the connection, save, and click "Send test row".
6. Optional: use "Sync existing entries" to import older entries.

Upgrading from 1.x: your connections are kept. Copy the new script from each connection and deploy a new version of the Apps Script to enable batches and duplicate protection.

== Developer hooks ==

* `ftgs_fields` (filter) – normalized fields before storing: `( $fields, $source, $form_id )`.
* `ftgs_row` (filter) – final row for one connection: `( $row, $connection, $submission )`.
* `ftgs_payload` (filter) – JSON payload sent to the webhook: `( $payload, $connection )`.
* `ftgs_captured`, `ftgs_delivered`, `ftgs_delivery_failed` (actions).
* `ftgs_integration_classes` (filter) – register your own FTGS_Integration subclass.
* `ftgs_sync_batch_size` (filter, default 50), `ftgs_http_timeout` (filter, default 30).
* `ftgs_html_selector`, `ftgs_allowed_hosts`, `ftgs_ip_headers` (filters).
* JavaScript: `window.FTGSsend(formElement)` sends an AJAX-driven HTML form manually.

== Changelog ==

= 2.0.0 =
* New: local submission store, background sending, retry queue, resend, email alerts.
* New: Sync existing entries with duplicate protection (Apps Script v2).
* New: Forminator, Everest Forms, JetFormBuilder, MetForm, Divi and Bricks integrations.
* New: "All form plugins" connections, conditions, column order, CF7 file links, Elementor form picker.
* New: submissions screen, CSV export, connection export/import, GDPR tools, retention.
* Improved: clear error messages for access (403), secret and outdated script problems.

= 1.0.0 =
* Initial release.
