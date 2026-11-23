<?php
/**
 * Settings page, actions and notices.
 *
 * @package SMTPfast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Settings > SMTPfast screen.
 */
final class SMTPfast_Admin {

	const PAGE             = 'smtpfast';
	const CAPABILITY       = 'manage_options';
	const DOMAINS_CACHE    = 'smtpfast_domains';
	const ACCOUNT_CACHE    = 'smtpfast_account';
	const CACHE_SECONDS    = 600;
	const REQUIRED_SCOPE   = 'email:send';
	const OPTIONAL_SCOPES  = array( 'domain:read', 'email:read' );
	const NOTICE_TRANSIENT = 'smtpfast_notice_';

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'failure_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SMTPFAST_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );

		foreach ( array( 'save_settings', 'delete_key', 'send_test', 'refresh_domains', 'clear_log', 'dismiss_failure' ) as $action ) {
			add_action( 'admin_post_smtpfast_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Adds Settings > SMTPfast.
	 *
	 * @return void
	 */
	public static function add_page() {
		add_options_page(
			__( 'SMTPfast', 'smtpfast' ),
			__( 'SMTPfast', 'smtpfast' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Loads the stylesheet on the settings page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'smtpfast-admin', plugins_url( 'assets/admin.css', SMTPFAST_PLUGIN_FILE ), array(), SMTPFAST_VERSION );
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'smtpfast' ) )
		);
		return $links;
	}

	/**
	 * URL of a tab of the settings page.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	public static function page_url( $tab = 'general' ) {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Shows the last send failure on the Dashboard, the Plugins screen and the
	 * SMTPfast settings page, until it is dismissed or the next email is sent.
	 *
	 * @return void
	 */
	public static function failure_notice() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, self::notice_screens(), true ) ) {
			return;
		}
		$failure = SMTPfast_Log::last_failure();
		if ( null === $failure ) {
			return;
		}
		$count = isset( $failure['count'] ) ? (int) $failure['count'] : 1;
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'SMTPfast could not send an email.', 'smtpfast' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of failed emails, 2: date and time of the last failure. */
						_n( '%1$d failure, the last at %2$s.', '%1$d failures, the last at %2$s.', $count, 'smtpfast' ),
						$count,
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $failure['time'] )
					)
				);
				?>
				<?php
				if ( ! empty( $failure['fell_back'] ) ) {
					esc_html_e( 'WordPress then sent it with its default mailer.', 'smtpfast' );
				} else {
					esc_html_e( 'The email was not sent.', 'smtpfast' );
				}
				echo ' ';
				esc_html_e( 'This notice goes away when the next email is sent.', 'smtpfast' );
				?>
			</p>
			<p>
				<?php
				/* translators: %s: error message from the SMTPfast API. */
				echo esc_html( sprintf( __( 'Last error: %s', 'smtpfast' ), (string) $failure['message'] ) );
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smtpfast-inline-form">
				<input type="hidden" name="action" value="smtpfast_dismiss_failure" />
				<?php wp_nonce_field( 'smtpfast_dismiss_failure' ); ?>
				<a class="button" href="<?php echo esc_url( self::page_url( 'log' ) ); ?>"><?php esc_html_e( 'View the email log', 'smtpfast' ); ?></a>
				<?php submit_button( __( 'Dismiss', 'smtpfast' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * The admin screens where the failure notice may show.
	 *
	 * @return string[]
	 */
	public static function notice_screens() {
		return array( 'dashboard', 'plugins', 'settings_page_' . self::PAGE );
	}

	/**
	 * On the Plugins screen, says when no API key is set yet.
	 *
	 * @return void
	 */
	public static function setup_notice() {
		if ( ! current_user_can( self::CAPABILITY ) || SMTPfast_Settings::has_api_key() ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p>
				<?php esc_html_e( 'SMTPfast is active but has no API key, so WordPress still sends email the default way.', 'smtpfast' ); ?>
				<a href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Add your API key', 'smtpfast' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$tabs = array(
			'general' => __( 'Settings', 'smtpfast' ),
			'domains' => __( 'Domains', 'smtpfast' ),
			'log'     => __( 'Email log', 'smtpfast' ),
		);
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which tab to show.
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		?>
		<div class="wrap smtpfast-wrap">
			<h1><?php esc_html_e( 'SMTPfast', 'smtpfast' ); ?></h1>
			<?php self::render_flash_notice(); ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'SMTPfast sections', 'smtpfast' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::page_url( $slug ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'domains' === $tab ) {
				self::render_domains_tab();
			} elseif ( 'log' === $tab ) {
				self::render_log_tab();
			} else {
				self::render_general_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * The Settings tab: connection, sender options and the test email.
	 *
	 * @return void
	 */
	private static function render_general_tab() {
		$settings = SMTPfast_Settings::get();
		$has_key  = SMTPfast_Settings::has_api_key();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="smtpfast_save_settings" />
			<?php wp_nonce_field( 'smtpfast_save_settings' ); ?>

			<h2><?php esc_html_e( 'Connection', 'smtpfast' ); ?></h2>
			<?php self::render_account_status(); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="smtpfast-api-key"><?php esc_html_e( 'API key', 'smtpfast' ); ?></label></th>
					<td>
						<?php if ( SMTPfast_Settings::key_from_constant() ) : ?>
							<p>
								<?php
								printf(
									/* translators: %s: the SMTPFAST_API_KEY constant. */
									esc_html__( 'The API key is set with the %s constant in wp-config.php.', 'smtpfast' ),
									'<code>SMTPFAST_API_KEY</code>'
								);
								?>
							</p>
						<?php else : ?>
							<input type="password" id="smtpfast-api-key" name="smtpfast_api_key" class="regular-text" autocomplete="off" spellcheck="false" value=""
								placeholder="<?php echo esc_attr( $has_key ? SMTPfast_Settings::mask_api_key( SMTPfast_Settings::get_api_key() ) : '' ); ?>" />
							<p class="description">
								<?php
								if ( $has_key ) {
									esc_html_e( 'A key is saved. Leave the field empty to keep it, or paste a new key to replace it.', 'smtpfast' );
								} else {
									printf(
										/* translators: 1: link to the SMTPfast dashboard, 2: the SMTPFAST_API_KEY constant. */
										esc_html__( 'Create a key with the email:send scope in the %1$s. Add domain:read and email:read to see domain status and delivery status here. You can also define %2$s in wp-config.php.', 'smtpfast' ),
										'<a href="https://smtpfa.st/dashboard" target="_blank" rel="noopener noreferrer">' . esc_html__( 'SMTPfast dashboard', 'smtpfast' ) . '</a>',
										'<code>SMTPFAST_API_KEY</code>'
									);
								}
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Sender', 'smtpfast' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="smtpfast-from-email"><?php esc_html_e( 'From email', 'smtpfast' ); ?></label></th>
					<td>
						<input type="email" id="smtpfast-from-email" name="smtpfast[from_email]" class="regular-text" value="<?php echo esc_attr( $settings['from_email'] ); ?>" />
						<p class="description"><?php esc_html_e( 'An address on a verified SMTPfast domain. Used when an email has no From address, and when its From domain is not verified.', 'smtpfast' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="smtpfast-from-name"><?php esc_html_e( 'From name', 'smtpfast' ); ?></label></th>
					<td><input type="text" id="smtpfast-from-name" name="smtpfast[from_name]" class="regular-text" value="<?php echo esc_attr( $settings['from_name'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Force From', 'smtpfast' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="smtpfast[force_from]" value="1" <?php checked( $settings['force_from'] ); ?> />
							<?php esc_html_e( 'Always use the From email and name above, even when a plugin sets its own.', 'smtpfast' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="smtpfast-category"><?php esc_html_e( 'Email category', 'smtpfast' ); ?></label></th>
					<td>
						<select id="smtpfast-category" name="smtpfast[category]">
							<option value="transactional" <?php selected( $settings['category'], 'transactional' ); ?>><?php esc_html_e( 'Transactional', 'smtpfast' ); ?></option>
							<option value="marketing" <?php selected( $settings['category'], 'marketing' ); ?>><?php esc_html_e( 'Marketing', 'smtpfast' ); ?></option>
						</select>
						<p class="description">
							<?php
							printf(
								/* translators: %s: the X-SMTPfast-Category header. */
								esc_html__( 'Password resets, receipts and notifications are transactional. Marketing email gets an unsubscribe link. One email can override this with the %s header.', 'smtpfast' ),
								'<code>X-SMTPfast-Category</code>'
							);
							?>
						</p>
					</td>
				</tr>
				<?php
				self::render_tracking_row( 'track_opens', __( 'Open tracking', 'smtpfast' ), $settings['track_opens'] );
				self::render_tracking_row( 'track_clicks', __( 'Click tracking', 'smtpfast' ), $settings['track_clicks'] );
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fallback', 'smtpfast' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="smtpfast[fallback]" value="1" <?php checked( $settings['fallback'] ); ?> />
							<?php esc_html_e( 'When the SMTPfast API fails, send the email with the default WordPress mailer instead.', 'smtpfast' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Off by default. Without it, a failed email shows an admin notice and fires the wp_mail_failed action.', 'smtpfast' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'smtpfast' ) ); ?>
		</form>

		<?php if ( '' !== (string) get_option( SMTPfast_Settings::KEY_OPTION, '' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smtpfast-inline-form">
				<?php if ( SMTPfast_Settings::key_from_constant() ) : ?>
					<span><?php esc_html_e( 'An older key is also saved in the database. It is not used while the constant is set.', 'smtpfast' ); ?></span>
				<?php endif; ?>
				<input type="hidden" name="action" value="smtpfast_delete_key" />
				<?php wp_nonce_field( 'smtpfast_delete_key' ); ?>
				<?php submit_button( __( 'Remove the saved API key', 'smtpfast' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Send a test email', 'smtpfast' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="smtpfast_send_test" />
			<?php wp_nonce_field( 'smtpfast_send_test' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="smtpfast-test-to"><?php esc_html_e( 'Send to', 'smtpfast' ); ?></label></th>
					<td>
						<input type="email" id="smtpfast-test-to" name="smtpfast_test_to" class="regular-text" required value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
						<p class="description"><?php esc_html_e( 'The test goes through wp_mail(), the same path as every other email on this site.', 'smtpfast' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Send test email', 'smtpfast' ), 'secondary', 'submit', true, $has_key ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>
		<?php
	}

	/**
	 * One tracking select row.
	 *
	 * @param string $field Setting name.
	 * @param string $label Row label.
	 * @param string $value Current value.
	 * @return void
	 */
	private static function render_tracking_row( $field, $label, $value ) {
		$id = 'smtpfast-' . str_replace( '_', '-', $field );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $id ); ?>" name="smtpfast[<?php echo esc_attr( $field ); ?>]">
					<option value="account" <?php selected( $value, 'account' ); ?>><?php esc_html_e( 'Use my SMTPfast account setting', 'smtpfast' ); ?></option>
					<option value="on" <?php selected( $value, 'on' ); ?>><?php esc_html_e( 'On', 'smtpfast' ); ?></option>
					<option value="off" <?php selected( $value, 'off' ); ?>><?php esc_html_e( 'Off', 'smtpfast' ); ?></option>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * The connection status line from GET /v1/me.
	 *
	 * @return void
	 */
	private static function render_account_status() {
		if ( ! SMTPfast_Settings::has_api_key() ) {
			echo '<p class="smtpfast-status smtpfast-status-off">' . esc_html__( 'Not connected. WordPress sends email the default way until you add an API key.', 'smtpfast' ) . '</p>';
			return;
		}
		$account = self::get_account();
		if ( is_wp_error( $account ) ) {
			echo '<p class="smtpfast-status smtpfast-status-bad">' . esc_html(
				/* translators: %s: error message. */
				sprintf( __( 'The API key does not work: %s', 'smtpfast' ), $account->get_error_message() )
			) . '</p>';
			return;
		}
		$scopes = isset( $account['scopes'] ) && is_array( $account['scopes'] ) ? $account['scopes'] : array();
		$tier   = isset( $account['account']['tier'] ) ? (string) $account['account']['tier'] : '';

		if ( ! in_array( self::REQUIRED_SCOPE, $scopes, true ) ) {
			echo '<p class="smtpfast-status smtpfast-status-bad">' . esc_html__( 'Connected, but this key cannot send email. Create a key with the email:send scope.', 'smtpfast' ) . '</p>';
			return;
		}
		echo '<p class="smtpfast-status smtpfast-status-ok">' . esc_html(
			'' !== $tier
				/* translators: %s: plan name. */
				? sprintf( __( 'Connected. All WordPress email goes through SMTPfast (%s plan).', 'smtpfast' ), ucfirst( $tier ) )
				: __( 'Connected. All WordPress email goes through SMTPfast.', 'smtpfast' )
		) . '</p>';

		$missing = array_diff( self::OPTIONAL_SCOPES, $scopes );
		if ( ! empty( $missing ) ) {
			echo '<p class="description">' . esc_html(
				/* translators: %s: list of API key scopes. */
				sprintf( __( 'This key has no %s scope, so some status on this page is hidden.', 'smtpfast' ), implode( ', ', $missing ) )
			) . '</p>';
		}
	}

	/**
	 * The Domains tab.
	 *
	 * @return void
	 */
	private static function render_domains_tab() {
		if ( ! SMTPfast_Settings::has_api_key() ) {
			echo '<p>' . esc_html__( 'Add an API key on the Settings tab first.', 'smtpfast' ) . '</p>';
			return;
		}
		$domains = self::get_domains();
		?>
		<p><?php esc_html_e( 'SMTPfast sends only from verified domains. Fix DNS records and verify domains in the SMTPfast dashboard.', 'smtpfast' ); ?></p>
		<?php if ( is_wp_error( $domains ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $domains->get_error_message() ); ?></p></div>
		<?php elseif ( empty( $domains ) ) : ?>
			<p><?php esc_html_e( 'This account has no sending domains yet.', 'smtpfast' ); ?> <a href="https://smtpfa.st/dashboard" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Add one in the SMTPfast dashboard', 'smtpfast' ); ?></a></p>
		<?php else : ?>
			<table class="widefat striped smtpfast-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Domain', 'smtpfast' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'smtpfast' ); ?></th>
						<th scope="col"><?php esc_html_e( 'SPF', 'smtpfast' ); ?></th>
						<th scope="col"><?php esc_html_e( 'DKIM', 'smtpfast' ); ?></th>
						<th scope="col"><?php esc_html_e( 'DMARC', 'smtpfast' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $domains as $domain ) : ?>
						<tr>
							<td><strong><?php echo esc_html( isset( $domain['domain'] ) ? (string) $domain['domain'] : '' ); ?></strong></td>
							<?php foreach ( array( 'status', 'spf_status', 'dkim_status', 'dmarc_status' ) as $field ) : ?>
								<td><?php self::render_badge( isset( $domain[ $field ] ) ? (string) $domain[ $field ] : '' ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php self::render_from_check(); ?>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="smtpfast_refresh_domains" />
			<?php wp_nonce_field( 'smtpfast_refresh_domains' ); ?>
			<?php submit_button( __( 'Check again', 'smtpfast' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Says whether the configured From address is on a verified domain.
	 *
	 * @return void
	 */
	private static function render_from_check() {
		$from = (string) SMTPfast_Settings::get( 'from_email' );
		if ( '' === $from ) {
			echo '<p class="description">' . esc_html__( 'Set a From email on the Settings tab so emails from plugins that use an unverified From domain can still be sent.', 'smtpfast' ) . '</p>';
			return;
		}
		$verified = SMTPfast_Settings::is_verified_sender( $from );
		if ( true === $verified ) {
			/* translators: %s: email address. */
			echo '<p class="smtpfast-status smtpfast-status-ok">' . esc_html( sprintf( __( 'Your From email %s is on a verified domain.', 'smtpfast' ), $from ) ) . '</p>';
		} elseif ( false === $verified ) {
			/* translators: %s: email address. */
			echo '<p class="smtpfast-status smtpfast-status-bad">' . esc_html( sprintf( __( 'Your From email %s is not on a verified domain, so SMTPfast will refuse emails from it.', 'smtpfast' ), $from ) ) . '</p>';
		}
	}

	/**
	 * A status badge.
	 *
	 * @param string $status Status from the API.
	 * @return void
	 */
	private static function render_badge( $status ) {
		$status = strtolower( $status );
		$class  = 'smtpfast-badge-wait';
		if ( in_array( $status, array( 'verified', 'valid', 'pass', 'ok', 'delivered', 'sent', 'opened', 'clicked' ), true ) ) {
			$class = 'smtpfast-badge-ok';
		} elseif ( in_array( $status, array( 'failed', 'invalid', 'missing', 'bounced', 'complained', 'canceled' ), true ) ) {
			$class = 'smtpfast-badge-bad';
		}
		printf(
			'<span class="smtpfast-badge %s">%s</span>',
			esc_attr( $class ),
			esc_html( '' !== $status ? $status : __( 'unknown', 'smtpfast' ) )
		);
	}

	/**
	 * The Email log tab: local entries, with the live status from GET /v1/emails.
	 *
	 * @return void
	 */
	private static function render_log_tab() {
		$entries = SMTPfast_Log::all();
		$remote  = array();
		$note    = '';

		if ( ! empty( $entries ) && SMTPfast_Settings::has_api_key() ) {
			$api    = new SMTPfast_API( SMTPfast_Settings::get_api_key() );
			$emails = $api->list_emails( 100 );
			if ( is_wp_error( $emails ) ) {
				/* translators: %s: error message. */
				$note = sprintf( __( 'Live delivery status is not available: %s', 'smtpfast' ), $emails->get_error_message() );
			} else {
				foreach ( $emails as $email ) {
					if ( isset( $email['id'] ) ) {
						$remote[ (string) $email['id'] ] = $email;
					}
				}
			}
		}
		?>
		<p><?php esc_html_e( 'The last 100 emails this site sent through SMTPfast. The log keeps no addresses or message content; subjects and delivery status come live from the SMTPfast API.', 'smtpfast' ); ?></p>
		<?php if ( '' !== $note ) : ?>
			<p class="description"><?php echo esc_html( $note ); ?></p>
		<?php endif; ?>
		<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'No emails yet.', 'smtpfast' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped smtpfast-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'smtpfast' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'smtpfast' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Delivery', 'smtpfast' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Recipients', 'smtpfast' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Subject', 'smtpfast' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email id or error', 'smtpfast' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					$id     = isset( $entry['id'] ) ? (string) $entry['id'] : '';
					$email  = '' !== $id && isset( $remote[ $id ] ) ? $remote[ $id ] : null;
					$result = isset( $entry['status'] ) ? (string) $entry['status'] : '';
					?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?></td>
						<td><?php self::render_badge( 'sent' === $result ? 'sent' : ( 'fallback' === $result ? 'fallback' : 'failed' ) ); ?></td>
						<td>
							<?php
							if ( $email && isset( $email['status'] ) ) {
								self::render_badge( (string) $email['status'] );
							} else {
								echo '-';
							}
							?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) $entry['recipients'] ) ); ?></td>
						<td><?php echo $email && isset( $email['subject'] ) ? esc_html( (string) $email['subject'] ) : '-'; ?></td>
						<td>
							<?php if ( '' !== $id ) : ?>
								<code><?php echo esc_html( $id ); ?></code>
							<?php else : ?>
								<?php echo esc_html( (string) $entry['error'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="smtpfast_clear_log" />
			<?php wp_nonce_field( 'smtpfast_clear_log' ); ?>
			<?php submit_button( __( 'Clear the log', 'smtpfast' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Saves the settings and, when given, a new API key.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		self::check_capability();
		check_admin_referer( 'smtpfast_save_settings' );

		$input = isset( $_POST['smtpfast'] ) && is_array( $_POST['smtpfast'] ) ? map_deep( wp_unslash( $_POST['smtpfast'] ), 'sanitize_text_field' ) : array();
		update_option( SMTPfast_Settings::OPTION, SMTPfast_Settings::sanitize( $input ) );

		$message = __( 'Settings saved.', 'smtpfast' );
		$type    = 'success';

		$raw_key = isset( $_POST['smtpfast_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['smtpfast_api_key'] ) ) ) : '';
		if ( '' !== $raw_key && ! SMTPfast_Settings::key_from_constant() ) {
			$key = SMTPfast_Settings::sanitize_api_key( $raw_key );
			if ( '' === $key ) {
				$message = __( 'Settings saved, but the API key was not: it does not look like an SMTPfast key.', 'smtpfast' );
				$type    = 'error';
			} else {
				update_option( SMTPfast_Settings::KEY_OPTION, $key, false );
				delete_option( SMTPfast_Settings::DOMAINS_OPTION );
				self::clear_cache();
				$account = self::get_account();
				if ( is_wp_error( $account ) ) {
					/* translators: %s: error message. */
					$message = sprintf( __( 'Settings saved, but SMTPfast refused the API key: %s', 'smtpfast' ), $account->get_error_message() );
					$type    = 'error';
				} else {
					$message = __( 'Settings saved. The API key works.', 'smtpfast' );
					self::get_domains();
				}
			}
		}

		self::redirect( 'general', $type, $message );
	}

	/**
	 * Removes the saved API key.
	 *
	 * @return void
	 */
	public static function handle_delete_key() {
		self::check_capability();
		check_admin_referer( 'smtpfast_delete_key' );
		delete_option( SMTPfast_Settings::KEY_OPTION );
		if ( SMTPfast_Settings::key_from_constant() ) {
			self::redirect( 'general', 'success', __( 'The saved API key was removed. The key from wp-config.php is still used.', 'smtpfast' ) );
		}
		delete_option( SMTPfast_Settings::DOMAINS_OPTION );
		self::clear_cache();
		self::redirect( 'general', 'success', __( 'The API key was removed. WordPress sends email the default way again.', 'smtpfast' ) );
	}

	/**
	 * Sends a test email through wp_mail().
	 *
	 * @return void
	 */
	public static function handle_send_test() {
		self::check_capability();
		check_admin_referer( 'smtpfast_send_test' );

		$to = isset( $_POST['smtpfast_test_to'] ) ? sanitize_email( wp_unslash( $_POST['smtpfast_test_to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			self::redirect( 'general', 'error', __( 'Enter a valid email address for the test.', 'smtpfast' ) );
		}
		if ( ! SMTPfast_Settings::has_api_key() ) {
			self::redirect( 'general', 'error', __( 'Add an API key first.', 'smtpfast' ) );
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name. */
		$subject = sprintf( __( 'SMTPfast test email from %s', 'smtpfast' ), $site );
		$body    = '<p>' . esc_html__( 'This test email was sent by the SMTPfast plugin through the SMTPfast API.', 'smtpfast' ) . '</p>'
			. '<p>' . esc_html(
				/* translators: %s: site URL. */
				sprintf( __( 'Site: %s', 'smtpfast' ), home_url( '/' ) )
			) . '</p>';

		$sent   = wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		$result = SMTPfast_Mailer::last_result();

		if ( $sent && is_array( $result ) && isset( $result['id'] ) ) {
			/* translators: 1: email address, 2: SMTPfast email id. */
			self::redirect( 'general', 'success', sprintf( __( 'Test email sent to %1$s. SMTPfast email id: %2$s', 'smtpfast' ), $to, (string) $result['id'] ) );
		}
		if ( is_wp_error( $result ) ) {
			$message = $sent
				/* translators: %s: error message. */
				? sprintf( __( 'SMTPfast failed (%s), so the fallback sent the test with the default WordPress mailer.', 'smtpfast' ), $result->get_error_message() )
				/* translators: %s: error message. */
				: sprintf( __( 'The test email failed: %s', 'smtpfast' ), $result->get_error_message() );
			self::redirect( 'general', 'error', $message );
		}
		self::redirect( 'general', $sent ? 'warning' : 'error', $sent ? __( 'WordPress sent the test, but not through SMTPfast. Another plugin may handle email on this site.', 'smtpfast' ) : __( 'The test email failed.', 'smtpfast' ) );
	}

	/**
	 * Clears the domain cache so the Domains tab checks again.
	 *
	 * @return void
	 */
	public static function handle_refresh_domains() {
		self::check_capability();
		check_admin_referer( 'smtpfast_refresh_domains' );
		self::clear_cache();
		self::redirect( 'domains', 'success', __( 'Domain status refreshed.', 'smtpfast' ) );
	}

	/**
	 * Clears the local log.
	 *
	 * @return void
	 */
	public static function handle_clear_log() {
		self::check_capability();
		check_admin_referer( 'smtpfast_clear_log' );
		SMTPfast_Log::clear();
		self::redirect( 'log', 'success', __( 'The log was cleared.', 'smtpfast' ) );
	}

	/**
	 * Dismisses the failure notice.
	 *
	 * @return void
	 */
	public static function handle_dismiss_failure() {
		self::check_capability();
		check_admin_referer( 'smtpfast_dismiss_failure' );
		SMTPfast_Log::clear_failure();
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * The account from GET /v1/me, cached for ten minutes.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function get_account() {
		$cached = get_transient( self::ACCOUNT_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$api     = new SMTPfast_API( SMTPfast_Settings::get_api_key() );
		$account = $api->get_me();
		if ( ! is_wp_error( $account ) ) {
			set_transient( self::ACCOUNT_CACHE, $account, self::CACHE_SECONDS );
		}
		return $account;
	}

	/**
	 * The domains from GET /v1/domains, cached for ten minutes. Also stores the verified domain names.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function get_domains() {
		$cached = get_transient( self::DOMAINS_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$api     = new SMTPfast_API( SMTPfast_Settings::get_api_key() );
		$domains = $api->list_domains();
		if ( ! is_wp_error( $domains ) ) {
			SMTPfast_Settings::store_verified_domains( $domains );
			set_transient( self::DOMAINS_CACHE, $domains, self::CACHE_SECONDS );
		}
		return $domains;
	}

	/**
	 * Clears the cached API answers.
	 *
	 * @return void
	 */
	private static function clear_cache() {
		delete_transient( self::ACCOUNT_CACHE );
		delete_transient( self::DOMAINS_CACHE );
	}

	/**
	 * Stops unless the current user can manage options.
	 *
	 * @return void
	 */
	private static function check_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change SMTPfast settings.', 'smtpfast' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Stores a one-time notice for the current user and goes back to the settings page.
	 *
	 * @param string $tab     Tab to open.
	 * @param string $type    success, warning or error.
	 * @param string $message Message.
	 * @return void
	 */
	private static function redirect( $tab, $type, $message ) {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( self::page_url( $tab ) );
		exit;
	}

	/**
	 * Shows and removes the one-time notice.
	 *
	 * @return void
	 */
	private static function render_flash_notice() {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $notice['type'], array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'info';
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( (string) $notice['message'] )
		);
	}
}
