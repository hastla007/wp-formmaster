=== Form Captain ===
Contributors: captainsuite
Tags: forms, contact form, form builder, submissions, gdpr
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build forms visually and manage submissions securely inside your own WordPress – no external service, no submission limits.

== Description ==

Form Captain is a WordPress-native form builder and submission manager. Forms are built in a visual editor, embedded with a block or shortcode and every submission is stored in your own database – reliably, even when email delivery or an integration fails.

**Free – no limits, no account, no license check**

* Unlimited forms, fields and submissions
* Visual editor with keyboard support and desktop/mobile preview
* Field types: single line text, paragraph, email, phone, number, dropdown, radio buttons, checkboxes, date, heading/HTML note, hidden value, consent
* Templates: contact, quote request, support request
* Simple conditional logic: show or hide a field based on another field
* Entries with status (new, read, archived, spam, trash), search, filters, notes and CSV export with formula-injection protection
* Admin notification and optional confirmation email, sent through a durable queue with retries and visible delivery status
* Spam protection without external services: honeypot, minimum fill-in time, rate limit and blocklist
* Privacy tools: WordPress personal data exporter and eraser, retention (90 days, 12 months or manual), consent records, data stays on your site
* Gutenberg block and shortcode, server-rendered and accessible markup, works without JavaScript and with page caching
* Compatible with Yoast SEO (no changes to titles, meta, canonical, sitemaps or schema)
* Form import/export as JSON
* Versioned public developer API and connector registry for custom integrations

**Form Captain Pro** is a separate add-on (not required) that adds multi-step forms, file uploads, advanced conditions and routing, webhooks, PDF generation and WooCommerce buyer questionnaires. Everything you build with the free plugin keeps working without Pro.

== Installation ==

1. Install and activate Form Captain from the Plugins screen.
2. Open **Form Captain → Dashboard** and follow the setup checklist.
3. Create a form from a template, check the recipient address and publish it.
4. Add the **Form Captain** block (or the shortcode `[form_captain id="…"]`) to a page.
5. Send a test submission and check **Form Captain → Entries**.

For reliable email delivery we recommend an SMTP plugin of your choice. Form Captain does not store SMTP credentials.

== Frequently Asked Questions ==

= Does Form Captain send my data to third parties? =

No. Submissions are stored in your WordPress database. Data only leaves your site if you explicitly enable an integration or a third-party service.

= What happens if email delivery fails? =

The submission is saved first. Emails are processed afterwards by a queue with automatic retries; failures are visible on the entry and in the system status.

= My site uses page caching. Does the form still work? =

Yes. The form fetches a fresh, short-lived submission token when needed, so cached pages do not break submissions.

= My hosting disables WP-Cron. =

Use a system cron job or run `wp form-captain jobs run`. As a fallback, opening a Form Captain admin page processes due jobs.

= How long are submissions kept? =

12 months by default. You can choose 90 days, 12 months or manual deletion under **Settings → Privacy & storage**. Deleting the plugin keeps your data unless you explicitly opt in to deleting it.

= Does it work with WooCommerce? =

Yes. The free plugin does not interfere with cart, checkout, orders or HPOS.

== Support ==

Support for the free plugin is provided in the WordPress.org support forum. Customers of Form Captain Pro receive support by email ticket with response times according to their plan.

== Privacy ==

Form Captain stores the data your visitors enter into your forms in your WordPress database. It does not store IP addresses; for rate limiting only a short-lived, salted hash is kept. It integrates with the WordPress personal data export and erasure tools and suggests text for your privacy policy. No data is sent to the plugin authors.

== Screenshots ==

1. Dashboard with setup checklist
2. Form editor with field palette, canvas and field settings
3. Entries list with status filters
4. Entry detail with delivery status and notes
5. Settings: privacy and storage
6. Accessible form on the front end

== Changelog ==

= 1.0.0 =
* First release: visual form editor, 12 field types, templates, simple conditional logic, entries management, CSV export, queued email notifications, spam protection, privacy tools, block and shortcode, Yoast SEO compatibility, public developer API v1 and connector registry.

== Upgrade Notice ==

= 1.0.0 =
First release.
