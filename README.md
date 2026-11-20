# SMTPfast for WordPress

A WordPress plugin that sends every `wp_mail()` email through the [SMTPfast](https://smtpfa.st) email API.

- Sends To, Cc, Bcc, Reply-To, HTML, plain text, multipart bodies, attachments, WordPress 6.9 embeds and custom headers through `POST /v1/emails`. An email with only Bcc recipients goes through `POST /v1/emails/batch`, one copy per address.
- Settings > SMTPfast: API key (or the `SMTPFAST_API_KEY` constant), From email and name, Force From, category, open and click tracking, and an optional fallback to the default mailer.
- Domains tab with SPF, DKIM and DMARC status from `GET /v1/domains`.
- Test email button and a log of the last 100 sends, with live delivery status from `GET /v1/emails`. The log stores no addresses, subjects or message content, and error messages are stored with addresses removed.
- On failure `wp_mail()` returns false, `wp_mail_failed` fires and an admin notice shows the error until it is dismissed or the next email is sent. The optional fallback runs only when SMTPfast surely did not accept the email (a 4xx, or a connection that never opened), so it cannot cause duplicates.

The wordpress.org listing text is in [readme.txt](readme.txt).

## How it works

The plugin hooks the `pre_wp_mail` filter (WordPress 5.7+). It reads the `wp_mail()` arguments the same way core does (header parsing, the `wp_mail_from`, `wp_mail_from_name`, `wp_mail_content_type` and `wp_mail_charset` filters), builds one API request, and returns true or false. WordPress never builds a PHPMailer object for an email that SMTPfast handles, so `phpmailer_init` does not run for it.

Every send has an `Idempotency-Key`. A network error, 429 or 5xx is retried once with the same key, so a retry cannot send twice. Requests never follow redirects, so the key and the email cannot leak to another host.

Attachment and embed paths with a URL scheme (`phar://`, `ftp://` and so on) are refused before any file function sees them, as PHPMailer does.

When a plugin sets a From address on a domain that is not verified in SMTPfast (a contact form that sends "from" the visitor, for example), the plugin sends from the configured From email and puts the original address in Reply-To. The list of verified domains is stored when the settings page reads `GET /v1/domains` and is cleared when the key changes. With a send-only key there is no list, so the plugin sends as asked and, if SMTPfast answers 403 for a From on another domain, sends once more from the configured From.

### Why not the PHP SDK

The plugin calls the API with `wp_safe_remote_request()` instead of requiring or vendoring [smtpfast-php](https://github.com/smtpfast/smtpfast-php):

- The SDK needs PHP 8.2 and ext-curl. WordPress sites still run PHP 7.4 to 8.1, and the plugin supports 7.4+.
- The WordPress HTTP API respects the site's proxy settings and the `pre_http_request` filter (which the tests use), and the review team prefers it over raw cURL.
- The plugin uses four endpoints. A vendored SDK would add a Composer autoloader, a namespace prefixing step and an MIT library inside a GPL plugin for very little code.

## Development

All dev tools run through Composer. The plugin itself has no runtime dependencies.

```bash
composer install
vendor/bin/phpcs            # WordPress Coding Standards + PHPCompatibility (PHP 7.4+)
WP_DB_HOST=127.0.0.1 WP_DB_USER=root WP_DB_PASSWORD=root WP_DB_NAME=wordpress_test vendor/bin/phpunit
bin/build-zip.sh            # build/smtpfast.zip with only the runtime files (see .distignore)
```

PHPUnit runs the WordPress core test suite (`wp-phpunit/wp-phpunit` with `johnpbloch/wordpress-core`), so it needs an empty MySQL or MariaDB database. The HTTP layer is mocked with `pre_http_request`; no test talks to the real API.

Plugin Check: install the [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin on a test site, copy `build/smtpfast` into `wp-content/plugins`, then run `wp plugin check smtpfast`.

CI (`.github/workflows/ci.yml`) runs PHPCS, PHPUnit on PHP 7.4 with WordPress 6.4 and on PHP 8.2 and 8.4 with the latest WordPress, and Plugin Check on the built folder.

## Release

1. Bump `Version` in `smtpfast.php`, `SMTPFAST_VERSION`, `Stable tag` in `readme.txt` and add a changelog entry. `bin/build-zip.sh` refuses to build when the versions differ.
2. Tag and push: `git tag v1.0.1 && git push origin v1.0.1`. The Release workflow builds `smtpfast.zip` and attaches it to a GitHub release.

## Submit to wordpress.org (first time)

1. Use a wordpress.org account that belongs to SMTPfast, with an email address on the smtpfa.st domain. The review team checks that a plugin named after a brand is submitted by the brand owner. Turn on two-factor authentication.
2. Put that account's username in `Contributors:` in `readme.txt` (it says `smtpfast` now) and rebuild the zip.
3. Upload `smtpfast.zip` at https://wordpress.org/plugins/developers/add/. Check that the slug it shows is `smtpfast` before you confirm. The upload runs Plugin Check again.
4. Answer the review email. The reviewers write from plugins@wordpress.org; reply to that thread with any fixes.
5. After approval you get SVN access to `https://plugins.svn.wordpress.org/smtpfast/`. Set an SVN password in your wordpress.org profile (Account & Security), then:

```bash
svn checkout https://plugins.svn.wordpress.org/smtpfast smtpfast-svn
cd smtpfast-svn
rsync -a --delete ../smtpfast-wordpress/build/smtpfast/ trunk/
cp ../smtpfast-wordpress/.wordpress-org/* assets/
svn add --force trunk assets
svn cp trunk tags/1.0.0
svn commit -m "Release 1.0.0" --username <wordpress.org username>
```

The `assets/` folder holds `screenshot-1.png` to `screenshot-3.png` (listed in the readme), and optionally `banner-772x250.png`, `banner-1544x500.png`, `icon-128x128.png` and `icon-256x256.png`.

### Automating SVN releases later

This repo has no SVN deploy job or secrets on purpose. To automate releases, add `SVN_USERNAME` and `SVN_PASSWORD` as repository secrets and a step with [10up/action-wordpress-plugin-deploy](https://github.com/10up/action-wordpress-plugin-deploy) to the Release workflow (`SLUG: smtpfast`, `BUILD_DIR: build/smtpfast`, `ASSETS_DIR: .wordpress-org`).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
