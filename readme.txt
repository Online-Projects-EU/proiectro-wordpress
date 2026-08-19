=== Proiect.ro ===
Contributors: ogherghinis
Tags: crm, leads, contact form 7, wpforms, gravity forms
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send leads from your WordPress forms to your Proiect.ro workspace reliably, with a local outbox that retries until every lead is delivered.

== Description ==

[Proiect.ro](https://proiect.ro) is a business platform for service companies: leads, proposals, projects, work, payments and support in one place. This plugin turns your website into a lead source for it.

* **Every submission becomes a lead** in the lead list you choose — name, email, phone, company, message; anything else the form collected is kept in the lead's notes.
* **Nothing gets lost.** Submissions are stored on your site first and sent from an outbox with automatic retries. If Proiect.ro or your hosting has a bad moment, the lead waits and goes out later; you can see and retry every item under Settings > Proiect.ro > Outbox.
* **A shortcode form** — `[proiectro_lead_form]` — for sites without a form plugin, and a PHP hook (`do_action( 'proiectro_capture_lead', $fields, $source )`) for developers and form-plugin bridges.
* **Least privilege by design.** The plugin needs an API key with only the *create* permission and an expiry date. The connection test tells you when the key you pasted can do more than that.

= External services =

This plugin sends data to Proiect.ro (https://proiect.ro), or to the self-hosted Proiect.ro instance you configure under Settings > Proiect.ro. Every form submission you route through it — the fields listed above — is sent to your Proiect.ro workspace's API together with the API key you configured. Nothing is sent until you have entered a workspace and key, and no data about your site or visitors is sent to anyone else. Proiect.ro's terms and privacy policy: https://proiect.ro/legal/terms/ and https://proiect.ro/legal/privacy/.

== Installation ==

1. Install and activate the plugin.
2. In your Proiect.ro workspace, go to Settings > API Keys and create a key with only the *create* permission (and an expiry date).
3. In WordPress, go to Settings > Proiect.ro, enter your workspace path and the key, and click *Test connection*.
4. Add `[proiectro_lead_form]` to a page, or connect your form plugin.

== Frequently Asked Questions ==

= Which form plugins are supported? =

Contact Form 7, WPForms and Gravity Forms: when one of them is active, its forms are listed under Settings > Proiect.ro > Forms — tick the ones whose submissions should become leads (none are on by default). Name, email, phone, company and message fields are recognised by type or label; everything else the form collected is kept in the lead's notes. The shortcode form works without any form plugin, and any other form can be connected with `do_action( 'proiectro_capture_lead', $fields, 'my-form' )`.

= What happens if Proiect.ro is unreachable? =

The submission stays in the outbox on your site and is retried automatically (every few minutes at first, then less often, for about two days). You can retry or delete items by hand under Settings > Proiect.ro > Outbox.

= Which permissions does the API key need? =

Only *create*. The plugin never reads, updates or deletes anything in your workspace. If your key can also read, the connection test says so — replace it with a create-only key.

== Changelog ==

= 0.1.0 =
* First release: settings, connection test, outbox with retries, shortcode form, `proiectro_capture_lead` hook, Contact Form 7 / WPForms / Gravity Forms bridges.
