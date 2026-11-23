=== SMTPfast ===
Contributors: smtpfast
Tags: email, smtp, wp mail, transactional email, email api
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send every WordPress email through the SMTPfast email API. Password resets, WooCommerce orders and contact forms arrive in the inbox.

== Description ==

WordPress sends email with the PHP mail() function by default. Many hosts block it, and mail that does go out often lands in spam because it is not signed for your domain.

SMTPfast sends every email that WordPress, your theme or your plugins send with `wp_mail()` through the [SMTPfast](https://smtpfa.st) email API, from your own verified domain with SPF, DKIM and DMARC in place. It uses the HTTPS API, not SMTP, so it works on hosts that block SMTP ports.

**What it does**

* Sends all `wp_mail()` email through the SMTPfast API: To, Cc, Bcc, Reply-To, HTML or plain text, multipart bodies, attachments, embedded images and custom headers.
* Works with WooCommerce, Contact Form 7, WPForms, Gravity Forms, password resets, new user emails and any other plugin that uses `wp_mail()`.
* Shows the SPF, DKIM and DMARC status of your SMTPfast sending domains.
* Sends a test email from the settings page.
* Keeps a log of the last 100 emails with their live delivery status from SMTPfast. The log stores no addresses, subjects or message content.
* Never drops an email silently. When the API refuses an email or cannot be reached, `wp_mail()` returns false, the standard `wp_mail_failed` action fires and an admin notice shows the error. You can turn on a fallback to the default WordPress mailer.
* When a plugin sets a From address on a domain you have not verified, such as a contact form that sends "from" the visitor, the plugin sends from your configured address and keeps the original sender as Reply-To.
* Lets you keep the API key out of the database with a constant in `wp-config.php`.

**Developer hooks**

* `smtpfast_enabled` (filter): return false to send one email the default WordPress way.
* `smtpfast_email_payload` (filter): change the API request body, for example to add tags.
* `smtpfast_http_timeout` (filter): API timeout in seconds, 15 by default.
* Per email headers: `X-SMTPfast-Category: transactional|marketing`, `X-SMTPfast-Track-Opens: true|false` and `X-SMTPfast-Track-Clicks: true|false`. The plugin turns them into API fields and does not send them as headers.

== External services ==

This plugin connects to the SMTPfast API at `https://smtpfa.st/api/v1` to send your site's email. It is the whole purpose of the plugin and nothing is sent until you add an API key.

* **When an email is sent** with `wp_mail()`: the sender, recipients (To, Cc, Bcc, Reply-To), subject, message body, attachments and custom headers of that email go to `POST /v1/emails`, together with your API key.
* **On the plugin settings page**: the plugin asks `GET /v1/me` for the key's scopes and plan, `GET /v1/domains` for the status of your sending domains, and `GET /v1/emails` for the delivery status of recent emails. These requests send only your API key.

The plugin sends no usage data or analytics of its own. SMTPfast is provided by SMTPfast: [Terms of Service](https://smtpfa.st/terms), [Privacy Policy](https://smtpfa.st/privacy).

== Installation ==

1. Install and activate the plugin from Plugins > Add New, or upload the `smtpfast` folder to `/wp-content/plugins/`.
2. Create a free account at [smtpfa.st](https://smtpfa.st), add your domain and add the DNS records it shows.
3. Create an API key with the `email:send` scope. Add `domain:read` and `email:read` to see domain and delivery status in WordPress.
4. Go to Settings > SMTPfast, paste the key, set the From email to an address on your verified domain and save.
5. Send a test email from the same page.

To keep the key out of the database, add this line to `wp-config.php` instead of saving it on the settings page:

`define( 'SMTPFAST_API_KEY', 'your-api-key' );`

== Frequently Asked Questions ==

= Do I need an SMTPfast account? =

Yes. The plugin sends email through your SMTPfast account. There is a free plan.

= Does it use SMTP? =

No. It uses the SMTPfast HTTPS API, so it works on hosts that block outgoing SMTP ports. The name is the name of the service.

= What happens when SMTPfast cannot send an email? =

`wp_mail()` returns false, the `wp_mail_failed` action fires with the error, the email appears as failed in the log, and an admin notice shows the error until you dismiss it or the next email is sent.

If you turned on the fallback, WordPress sends the email with its default mailer instead, but only when SMTPfast surely did not accept it: the API refused it, or the connection never opened. After a timeout or a server error SMTPfast may already have queued the email, so the plugin reports the failure instead of risking a duplicate.

= Why does my contact form email come from my own address? =

SMTPfast sends only from domains you have verified. When a plugin sets the From address to another domain, the plugin uses your configured From email and puts the original address in Reply-To, so you can still answer with one click. Turn on Force From to always use your configured address and name.

= What happens to an email with only Bcc recipients? =

The SMTPfast API needs a To address. The plugin sends one copy to each Bcc address in a single batch request (up to 100), so nobody else is added and no Bcc address is shown to the others. An email with only Cc recipients is sent with them as To.

= Which emails are transactional? =

By default every email is sent as transactional: password resets, receipts, notifications. Transactional email still reaches people who unsubscribed from your marketing. If the site sends newsletters through `wp_mail()`, set the category to Marketing, or add the `X-SMTPfast-Category: marketing` header to those emails. Marketing email gets an unsubscribe link.

= Does it work with plugins that change PHPMailer? =

The plugin sends email through the `pre_wp_mail` filter, so WordPress does not build a PHPMailer object for emails that go through SMTPfast, and `phpmailer_init` does not run for them. Changes a plugin makes to PHPMailer in `phpmailer_init` (such as an `AltBody`) do not apply. The standard `wp_mail` filters (`wp_mail`, `wp_mail_from`, `wp_mail_from_name`, `wp_mail_content_type`, `wp_mail_charset`, `wp_mail_embed_args`) and the `wp_mail_succeeded` and `wp_mail_failed` actions work as usual.

SMTPfast accepts up to 50 To addresses, 10 attachments and 25 MB of attachments per email, and no executable or temporary file types such as .exe, .js or .tmp. Such an email fails at once with a clear error.

= Does the plugin store my emails? =

No. The local log keeps the time, the SMTPfast email id, the result, the number of recipients and any error message, with email addresses removed. Subjects and delivery status are read live from the SMTPfast API when you open the log.

= What is removed when I delete the plugin? =

All plugin settings, the saved API key and the log. A key in `wp-config.php` stays there; remove that line yourself.

== Screenshots ==

1. Settings: connection status, sender and tracking options and the test email.
2. Domains: SPF, DKIM and DMARC status of your sending domains.
3. Email log: the last 100 emails with their live delivery status.

== Changelog ==

= 1.0.1 =
* The send-failure notice now shows only on the Dashboard, the Plugins screen and the SMTPfast settings page.

= 1.0.0 =
* First release: sends all `wp_mail()` email through the SMTPfast API, with domain status, a test email, a send log, failure notices and an optional fallback.

== Upgrade Notice ==

= 1.0.0 =
First release.
