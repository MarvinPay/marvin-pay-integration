<?php
/**
 * Central plugin settings. Currency is NEVER stored — it is always derived
 * from the selected country (currency and country travel together).
 */

defined( 'ABSPATH' ) || exit;

class MarvinPay_Settings {

	const OPTION = 'marvinpay_settings';
	const MASK   = '__marvinpay_keep__';

	const COUNTRY_NAMES = array(
		'CM' => 'Cameroon',
		'GA' => 'Gabon',
		'CI' => "Côte d'Ivoire",
		'SN' => 'Senegal',
		'BJ' => 'Benin',
		'TG' => 'Togo',
		'ML' => 'Mali',
	);

	public static function defaults() {
		return array(
			'mode'                => 'live',
			'live_api_key'        => '',
			'test_api_key'        => '',
			'live_base_url'       => 'https://app.marvincorporate.co/api',
			'test_base_url'       => 'https://api.marvincorporate.co/api',
			'country'             => 'CM',
			'webhook_secret'      => '',
			'success_page'        => 0,
			'failure_page'        => 0,
			'delete_on_uninstall' => 0,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function mode() {
		return 'test' === self::get( 'mode' ) ? 'test' : 'live';
	}

	public static function api_key() {
		return (string) self::get( self::mode() . '_api_key' );
	}

	public static function base_url() {
		return (string) self::get( self::mode() . '_base_url' );
	}

	public static function country() {
		return strtoupper( (string) self::get( 'country' ) );
	}

	public static function currency() {
		return (string) MarvinPay_Rules::currency_for_country( self::country() );
	}

	public static function is_configured() {
		return '' !== self::api_key()
			&& '' !== self::base_url()
			&& null !== MarvinPay_Rules::currency_for_country( self::country() );
	}

	/**
	 * Live provider list, cached 12h per mode+country. Failures fall back to
	 * the static list and are negative-cached for 5 minutes so a downed API
	 * can never block every front-end page view (this runs from
	 * wp_enqueue_scripts site-wide).
	 */
	public static function payment_methods() {
		$country = self::country();
		$key     = 'marvinpay_methods_' . self::mode() . '_' . $country;
		$cached  = get_transient( $key );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}
		$fallback = MarvinPay_Rules::FALLBACK_METHODS;
		$fallback = isset( $fallback[ $country ] ) ? $fallback[ $country ] : array();
		if ( false !== get_transient( $key . '_down' ) ) {
			return $fallback;
		}
		try {
			$client  = new MarvinPay_Client( self::api_key(), self::base_url(), 10 );
			$methods = $client->get_payment_methods( $country );
			$methods = array_values( array_filter( array_map( 'strval', $methods ) ) );
			if ( ! empty( $methods ) ) {
				set_transient( $key, $methods, 12 * HOUR_IN_SECONDS );
				return $methods;
			}
		} catch ( MarvinPay_API_Exception $e ) {
			// fall through to the negative-cached fallback below
		}
		set_transient( $key . '_down', 1, 5 * 60 );
		return $fallback;
	}

	// ── admin wiring ────────────────────────────────────────────────────

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'wp_ajax_marvinpay_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
	}

	public static function add_menu() {
		add_options_page( 'Marvin Pay', 'Marvin Pay', 'manage_options', 'marvinpay', array( __CLASS__, 'render_page' ) );
	}

	public static function register() {
		register_setting( 'marvinpay', self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	/** Merge onto existing settings; masked secret fields keep their stored value. */
	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = self::all();

		$out['mode'] = ( isset( $in['mode'] ) && 'test' === $in['mode'] ) ? 'test' : 'live';

		foreach ( array( 'live_api_key', 'test_api_key', 'webhook_secret' ) as $secret_field ) {
			if ( isset( $in[ $secret_field ] ) && self::MASK !== $in[ $secret_field ] ) {
				$out[ $secret_field ] = sanitize_text_field( $in[ $secret_field ] );
			}
		}

		foreach ( array( 'live_base_url', 'test_base_url' ) as $url_field ) {
			if ( isset( $in[ $url_field ] ) ) {
				$out[ $url_field ] = rtrim( esc_url_raw( trim( (string) $in[ $url_field ] ) ), '/' );
			}
		}

		if ( isset( $in['country'] ) ) {
			$country = strtoupper( sanitize_text_field( $in['country'] ) );
			if ( null !== MarvinPay_Rules::currency_for_country( $country ) ) {
				$out['country'] = $country;
			}
		}

		$out['success_page']        = isset( $in['success_page'] ) ? absint( $in['success_page'] ) : 0;
		$out['failure_page']        = isset( $in['failure_page'] ) ? absint( $in['failure_page'] ) : 0;
		$out['delete_on_uninstall'] = empty( $in['delete_on_uninstall'] ) ? 0 : 1;

		// A country or mode change invalidates the cached provider list.
		delete_transient( 'marvinpay_methods_live_' . $out['country'] );
		delete_transient( 'marvinpay_methods_test_' . $out['country'] );

		return $out;
	}

	private static function mask_display( $value ) {
		return '' === (string) $value ? '' : self::MASK;
	}

	public static function ajax_test_connection() {
		check_ajax_referer( 'marvinpay_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'marvinpay' ) ), 403 );
		}
		if ( ! self::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Fill in the API key, base URL and country first, then save.', 'marvinpay' ) ) );
		}
		try {
			$methods = MarvinPay_Plugin::client()->get_payment_methods( self::country() );
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: 1: country code, 2: provider list */
					__( 'Connected. Providers for %1$s: %2$s', 'marvinpay' ),
					self::country(),
					implode( ', ', array_map( 'strval', $methods ) )
				),
			) );
		} catch ( MarvinPay_API_Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s           = self::all();
		$webhook_url = rest_url( 'marvinpay/v1/webhook' );
		$admin_nonce = wp_create_nonce( 'marvinpay_admin' );
		?>
		<div class="wrap">
			<h1>Marvin Pay</h1>

			<?php if ( 'test' === $s['mode'] && '' === $s['test_base_url'] ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Test mode is selected but no test base URL is set. The test environment URL is provided by Marvin Pay support — payments are disabled until it is configured.', 'marvinpay' ); ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'marvinpay' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mp-mode"><?php esc_html_e( 'Mode', 'marvinpay' ); ?></label></th>
						<td>
							<select id="mp-mode" name="<?php echo esc_attr( self::OPTION ); ?>[mode]">
								<option value="live" <?php selected( $s['mode'], 'live' ); ?>><?php esc_html_e( 'Live', 'marvinpay' ); ?></option>
								<option value="test" <?php selected( $s['mode'], 'test' ); ?>><?php esc_html_e( 'Test', 'marvinpay' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mp-live-key"><?php esc_html_e( 'Live API key', 'marvinpay' ); ?></label></th>
						<td><input type="password" id="mp-live-key" class="regular-text" autocomplete="off"
							name="<?php echo esc_attr( self::OPTION ); ?>[live_api_key]"
							value="<?php echo esc_attr( self::mask_display( $s['live_api_key'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'The stored key is kept unless you type a new one.', 'marvinpay' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="mp-test-key"><?php esc_html_e( 'Test API key', 'marvinpay' ); ?></label></th>
						<td><input type="password" id="mp-test-key" class="regular-text" autocomplete="off"
							name="<?php echo esc_attr( self::OPTION ); ?>[test_api_key]"
							value="<?php echo esc_attr( self::mask_display( $s['test_api_key'] ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mp-live-url"><?php esc_html_e( 'Live base URL', 'marvinpay' ); ?></label></th>
						<td><input type="url" id="mp-live-url" class="regular-text"
							name="<?php echo esc_attr( self::OPTION ); ?>[live_base_url]"
							value="<?php echo esc_attr( $s['live_base_url'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mp-test-url"><?php esc_html_e( 'Test base URL', 'marvinpay' ); ?></label></th>
						<td><input type="url" id="mp-test-url" class="regular-text" placeholder="<?php esc_attr_e( 'Provided by Marvin Pay support', 'marvinpay' ); ?>"
							name="<?php echo esc_attr( self::OPTION ); ?>[test_base_url]"
							value="<?php echo esc_attr( $s['test_base_url'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mp-country"><?php esc_html_e( 'Country', 'marvinpay' ); ?></label></th>
						<td>
							<select id="mp-country" name="<?php echo esc_attr( self::OPTION ); ?>[country]">
								<?php foreach ( MarvinPay_Rules::LIVE_COUNTRIES as $cc ) : ?>
									<option value="<?php echo esc_attr( $cc ); ?>" <?php selected( $s['country'], $cc ); ?>>
										<?php echo esc_html( self::COUNTRY_NAMES[ $cc ] . ' (' . $cc . ' — ' . MarvinPay_Rules::currency_for_country( $cc ) . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The currency follows the country automatically — they always travel together.', 'marvinpay' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mp-secret"><?php esc_html_e( 'Webhook secret', 'marvinpay' ); ?></label></th>
						<td><input type="password" id="mp-secret" class="regular-text" autocomplete="off"
							name="<?php echo esc_attr( self::OPTION ); ?>[webhook_secret]"
							value="<?php echo esc_attr( self::mask_display( $s['webhook_secret'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional. Set the same secret on your Marvin Pay account to receive signed webhooks.', 'marvinpay' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'marvinpay' ); ?></th>
						<td><code><?php echo esc_html( $webhook_url ); ?></code>
							<p class="description"><?php esc_html_e( 'Paste this URL into your Marvin Pay merchant portal as the account webhook URL (must be HTTPS).', 'marvinpay' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="mp-success-page"><?php esc_html_e( 'Success page', 'marvinpay' ); ?></label></th>
						<td><?php wp_dropdown_pages( array( 'name' => self::OPTION . '[success_page]', 'id' => 'mp-success-page', 'selected' => (int) $s['success_page'], 'show_option_none' => __( '— stay in the payment window —', 'marvinpay' ), 'option_none_value' => '0' ) ); ?>
							<p class="description"><?php esc_html_e( 'Optional page to redirect to after a successful widget payment (add the Payment Status widget to it).', 'marvinpay' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="mp-failure-page"><?php esc_html_e( 'Failure page', 'marvinpay' ); ?></label></th>
						<td><?php wp_dropdown_pages( array( 'name' => self::OPTION . '[failure_page]', 'id' => 'mp-failure-page', 'selected' => (int) $s['failure_page'], 'show_option_none' => __( '— stay in the payment window —', 'marvinpay' ), 'option_none_value' => '0' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On uninstall', 'marvinpay' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'], 1 ); ?> />
							<?php esc_html_e( 'Delete the transactions table and all settings when the plugin is uninstalled.', 'marvinpay' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Connection test', 'marvinpay' ); ?></h2>
			<p>
				<button type="button" class="button" id="mp-test-connection"><?php esc_html_e( 'Test connection', 'marvinpay' ); ?></button>
				<span id="mp-test-result"></span>
			</p>
			<script>
			( function () {
				var btn = document.getElementById( 'mp-test-connection' );
				var out = document.getElementById( 'mp-test-result' );
				btn.addEventListener( 'click', function () {
					out.textContent = '…';
					var body = new URLSearchParams();
					body.append( 'action', 'marvinpay_test_connection' );
					body.append( 'nonce', <?php echo wp_json_encode( $admin_nonce ); ?> );
					fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: body, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( j ) { out.textContent = ( j.data && j.data.message ) ? j.data.message : ( j.success ? 'OK' : 'Failed' ); } )
						.catch( function () { out.textContent = 'Request failed.'; } );
				} );
			} )();
			</script>
		</div>
		<?php
	}
}
