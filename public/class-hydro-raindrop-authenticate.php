<?php

declare( strict_types=1 );

use Adrenth\Raindrop\Exception\VerifySignatureFailed;

/** @noinspection AutoloadingIssuesInspection */

/**
 * Class Hydro_Raindrop_Authenticate
 */
final class Hydro_Raindrop_Authenticate {

	const MESSAGE_TRANSIENT_ID = 'HydroRaindropMessage_%s';

	const COOKIE_NAME = 'HydroRaindropMfa';

	const TIME_OUT = 90;

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 *
	 * @param      string $plugin_name The name of the plugin.
	 * @param      string $version     The version of this plugin.
	 */
	public function __construct( string $plugin_name, string $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;

	}

	/**
	 * Generates the salt which will be used for hashing and encrypting.
	 *
	 * @return string
	 */
	private function get_salt() : string {

		if ( defined( 'AUTH_SALT' ) ) {
			return AUTH_SALT;
		}

		$salt = get_option( 'hydro_raindrop_salt', '' );

		if ( empty( $salt ) ) {
			$salt = wp_generate_password( 64, true, true );
			update_option( 'hydro_raindrop_salt', $salt );
		}

		return $salt;
	}

	/**
	 * Log message.
	 *
	 * @param string $message The message to log.
	 *
	 * @return void
	 */
	private function log( string $message ) {

		if ( WP_DEBUG && WP_DEBUG_LOG ) {
			// @codingStandardsIgnoreLine
			error_log( $this->plugin_name . ' (' . $this->version . '): ' . $message );
		}

	}

	/**
	 * Verify request.
	 *
	 * @return void
	 * @throws Exception When MFA could not be started.
	 */
	public function verify() {

		if ( ! $this->is_hydro_raindrop_mfa_enabled() ) {
			return;
		}

		// @codingStandardsIgnoreLine
		$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';

		// @codingStandardsIgnoreLine
		$retrieved_nonce = $_POST['_wpnonce'] ?? null;

		// @codingStandardsIgnoreLine
		if ( isset( $_POST['hydro_raindrop'] )
				&& $is_post
				&& is_ssl()
				&& ! wp_verify_nonce( $retrieved_nonce, 'hydro_raindrop_mfa' )
		) {
			$this->log( 'Nonce verification failed. Logging out.' );
			wp_logout();
			return;
		}

		// Allow user to cancel the MFA. Which results in a logout.
		// @codingStandardsIgnoreLine
		if ( isset( $_POST['cancel_hydro_raindrop'] )
				&& $is_post
				&& is_ssl()
				&& wp_verify_nonce( $retrieved_nonce, 'hydro_raindrop_mfa' )
		) {
			// Unset the MFA cookie.
			$this->unset_cookie();

			// Delete all transient data which is used during the MFA process.
			$this->delete_transient_data();

			// When this is not the first time verification; the "Cancel button" will logout the user.
			if ( ! $this->is_first_time_verify() ) {
				wp_logout();
			}

			// @codingStandardsIgnoreLine
			wp_redirect( home_url() );
			exit;
		}

		// @codingStandardsIgnoreLine
		if ( isset( $_POST['hydro_raindrop'] )
				&& $is_post
				&& is_ssl()
				&& is_user_logged_in()
				&& $this->verify_signature_login()
		) {
			$user = wp_get_current_user();

			// Set the MFA cookie for current user.
			$this->set_cookie( $user );

			// Delete all transient data which is used during the MFA process.
			$this->delete_transient_data();

			// Redirect the user to it's intended location.
			$this->redirect( $user );
		}

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();

			$user_requires_mfa = $this->user_requires_mfa( $user );

			// MFA is enabled, but the current request is not SSL.
			if ( $user_requires_mfa && ! is_ssl() ) {
				$this->log( 'Non-SSL detected.' );
				die( 'Non-SSL WordPress sites are not supported to perform Hydro Raindrop MFA.' );
			}

			// MFA is enabled, but there's not cookie present. Start the MFA flow.
			if ( $user_requires_mfa && ! $this->verify_cookie( $user ) ) {
				$this->log( 'Cookie not valid or not set.' );
				$this->unset_cookie();
				$this->start_mfa( $user );
			}
		}
	}

	/**
	 * Whether the Hydro Raindrop MFA is enabled.
	 *
	 * @return bool
	 */
	public function is_hydro_raindrop_mfa_enabled() : bool {

		return true;

	}

	/**
	 * Start Hydro Raindrop Multi Factor Authentication.
	 *
	 * @param WP_User $user Logged in user.
	 *
	 * @throws Exception If message could not be generated.
	 */
	public function start_mfa( WP_User $user ) {

		$this->log( 'Start MFA.' );

		$error = null;

		/*
		 * The authentication failed. Delete transient data to make sure a new message will be generated.
		 */
		// @codingStandardsIgnoreLine
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_REQUEST['hydro_raindrop'] ) ) {
			$this->log( 'Authentication failed.' );

			$error = 'Authentication failed.';

			$this->delete_transient_data();
		}

		/*
		 * Redirect to the Custom MFA page (if applicable).
		 */

		$custom_mfa_page = (int) get_option( 'hydro_raindrop_custom_mfa_page' );

		if ( $custom_mfa_page > 0 ) {
			$current_uri                   = home_url( add_query_arg( null, null ) );
			$custom_hydro_raindrop_mfa_uri = get_permalink( $custom_mfa_page );

			if ( $custom_hydro_raindrop_mfa_uri !== $current_uri ) {
				// @codingStandardsIgnoreLine
				wp_redirect( $custom_hydro_raindrop_mfa_uri );
				exit;
			}

			return;
		}

		/*
		 * Display the default (non customizable) MFA page.
		 */

		$message = self::get_message( $user );
		$logo    = plugin_dir_url( __FILE__ ) . 'images/logo.svg';
		$image   = plugin_dir_url( __FILE__ ) . 'images/security-code.png';

		require __DIR__ . '/partials/hydro-raindrop-public-mfa.php';
		exit;

	}

	/**
	 * Get the Raindrop MFA message.
	 *
	 * @param WP_User $user Current logged in user.
	 *
	 * @return int
	 * @throws Exception When message could not be generated.
	 */
	public static function get_message( WP_User $user ) : int {

		$client = Hydro_Raindrop::get_raindrop_client();

		$transient_id = sprintf( self::MESSAGE_TRANSIENT_ID, $user->ID );

		$message = get_transient( $transient_id );

		if ( ! $message ) {
			$message = $client->generateMessage();
			set_transient( $transient_id, $message, self::TIME_OUT );
		}

		return $message;

	}

	/**
	 * Redirects user after successful login and MFA.
	 *
	 * @param WP_User $user Current logged in user.
	 *
	 * @return void
	 */
	private function redirect( WP_User $user ) {

		// @codingStandardsIgnoreLine
		if ( isset( $_REQUEST['redirect_to'] ) ) {
			// @codingStandardsIgnoreLine
			$redirect_to = $_REQUEST['redirect_to'];
		} else {
			$redirect_to = admin_url();
		}

		// @codingStandardsIgnoreLine
		$requested_redirect_to = $_REQUEST['redirect_to'] ?? '';

		/**
		 * Filters the login redirect URL.
		 *
		 * @since 3.0.0
		 *
		 * @param string           $redirect_to           The redirect destination URL.
		 * @param string           $requested_redirect_to The requested redirect destination URL passed as a parameter.
		 * @param WP_User|WP_Error $user                  WP_User object if login was successful, WP_Error object otherwise.
		 */
		$redirect_to = apply_filters( 'login_redirect', $redirect_to, $requested_redirect_to, $user );

		if ( ( empty( $redirect_to ) || $redirect_to === 'wp-admin/' || $redirect_to === admin_url() ) ) {
			/*
			 * If the user doesn't belong to a blog, send them to user admin.
			 * If the user can't edit posts, send them to their profile.
			 */
			if ( is_multisite() && ! get_active_blog_for_user( $user->ID ) && ! is_super_admin( $user->ID ) ) {
				$redirect_to = user_admin_url();
			} elseif ( is_multisite() && ! $user->has_cap( 'read' ) ) {
				$redirect_to = get_dashboard_url( $user->ID );
			} elseif ( ! $user->has_cap( 'edit_posts' ) ) {
				$redirect_to = $user->has_cap( 'read' ) ? admin_url( 'profile.php' ) : home_url();
			}

			// @codingStandardsIgnoreLine
			wp_redirect( $redirect_to );
			exit();
		}

		wp_safe_redirect( $redirect_to );
		exit;

	}

	/**
	 * Set cookie for current user.
	 *
	 * @param WP_User $user Current logged in user.
	 *
	 * @throws \Hashids\HashidsException When hashing fails.
	 */
	private function set_cookie( WP_User $user ) {

		$this->log( 'Setting SSL cookie.' );

		$cookie = $this->get_cookie_value( $user, self::COOKIE_NAME );

		// @codingStandardsIgnoreLine
		$result = setcookie( self::COOKIE_NAME, $cookie, 0, COOKIEPATH, (string) COOKIE_DOMAIN, true, true );

		if ( ! $result ) {
			$this->log( 'Could not set cookie.' );
		}

		if ( COOKIEPATH !== SITECOOKIEPATH ) {
			// @codingStandardsIgnoreLine
			$result = setcookie( self::COOKIE_NAME, $cookie, 0, SITECOOKIEPATH, (string) COOKIE_DOMAIN, true, true );

			if ( ! $result ) {
				$this->log( 'Could not set cookie.' );
			}
		}

	}

	/**
	 * Get HTTP cookie value.
	 *
	 * @param WP_User $user        Currently logged in user.
	 * @param string  $cookie_name Cookie name.
	 *
	 * @return string
	 * @throws \Hashids\HashidsException When user ID could not be hashed.
	 */
	private function get_cookie_value( WP_User $user, string $cookie_name ) : string {

		$salt = $this->get_salt();

		// @codingStandardsIgnoreLine
		$hydro_id  = $this->get_user_hydro_id( $user );
		$user_hash = ( new \Hashids\Hashids( $salt, 64 ) )->encode( $user->ID );
		$expire    = strtotime( '+24 hours' );
		$value     = base64_encode( sprintf( '%s|%s|%s|%s', $cookie_name, $user_hash, $hydro_id, $expire ) );
		$signature = $this->hash_mac( $value );

		return $value . '|' . $signature;
	}

	/**
	 * Get the users' Hydro ID.
	 *
	 * @param WP_User $user Current logged in user.
	 *
	 * @return string
	 */
	private function get_user_hydro_id( WP_User $user ) : string {

		// @codingStandardsIgnoreLine
		return (string) get_user_meta( $user->ID, 'hydro_id', true );

	}

	/**
	 * Unset the Hydro Raindrop MFA cookie
	 *
	 * @return void
	 */
	public function unset_cookie() {

		$this->log( 'Unsetting cookies (force).' );

		// @codingStandardsIgnoreLine
		setcookie( self::COOKIE_NAME, '', strtotime( '-1 day' ), (string) COOKIEPATH, (string) COOKIE_DOMAIN );

		// @codingStandardsIgnoreLine
		setcookie( self::COOKIE_NAME, '', strtotime( '-1 day' ), (string) SITECOOKIEPATH, (string) COOKIE_DOMAIN );

	}

	/**
	 * Verify Hydro Raindrop MFA cookie.
	 *
	 * @param WP_User $user Verify cookie for current user.
	 *
	 * @return bool
	 * @throws \Hashids\HashidsException When hashing fails.
	 */
	private function verify_cookie( WP_User $user ) : bool {

		// @codingStandardsIgnoreLine
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$this->log( 'Cookie is not set.' );

			return false;
		}

		// @codingStandardsIgnoreLine
		$cookie_list = explode( '|', $_COOKIE[ self::COOKIE_NAME ] );

		if ( count( $cookie_list ) !== 2 ) {
			$this->log( 'Cookie contents are not valid (2).' );

			return false;
		}

		// @codingStandardsIgnoreLine
		list ( $b64_value, $cookie_signature ) = $cookie_list;

		$signature = $this->hash_mac( $b64_value );

		if ( $this->hash_mac( $signature ) !== $this->hash_mac( $cookie_signature ) ) {
			$this->log( 'Cookie signature invalid.' );

			return false;
		}

		// @codingStandardsIgnoreLine
		$cookie_content = explode( '|', base64_decode( $b64_value ) );

		if ( count( $cookie_content ) !== 4 ) {
			$this->log( 'Cookie contents are not valid (4).' );

			return false;
		}

		list ( $name, $user_id, $hydro_id, $expire ) = $cookie_content;

		$user_hash = ( new \Hashids\Hashids( $this->get_salt(), 64 ) )->decode( $user_id );

		$is_valid = self::COOKIE_NAME === $name
					|| $user->ID === $user_hash[0]
					|| $hydro_id === $this->get_user_hydro_id( $user );

		if ( ! $is_valid ) {
			$this->log( 'Cookie data invalid.' );
			return false;
		}

		// Cookie expired.
		if ( (int) $expire < time() ) {
			$this->log( 'Cookie is expired.' );
			return false;
		}

		return true;

	}

	/**
	 * Perform Hash Mac on data and return hash.
	 *
	 * @param string $data Data to hash.
	 *
	 * @return string
	 */
	private function hash_mac( string $data ) : string {

		return hash_hmac( 'sha1', $data, $this->get_salt() );

	}

	/**
	 * Checks whether current user requires Hydro Raindro MFA.
	 *
	 * @param WP_User $user Currently logged in user.
	 *
	 * @return bool
	 */
	private function user_requires_mfa( WP_User $user ) : bool {

		// @codingStandardsIgnoreLine
		$hydro_id = $this->get_user_hydro_id( $user );

		// @codingStandardsIgnoreLine
		$hydro_mfa_enabled = (bool) get_user_meta( $user->ID, 'hydro_mfa_enabled', true );

		// @codingStandardsIgnoreLine
		$hydro_raindrop_confirmed = (bool) get_user_meta( $user->ID, 'hydro_raindrop_confirmed', true );

		return ! empty( $hydro_id )
			&& $hydro_mfa_enabled
			&& ( $hydro_raindrop_confirmed || $this->is_first_time_verify() );

	}

	/**
	 * Whether this is the first time verification.
	 *
	 * @return bool
	 */
	private function is_first_time_verify() : bool {

		// @codingStandardsIgnoreLine
		return (int) ($_GET['hydro-raindrop-verify'] ?? 0) === 1;

	}

	/**
	 * Delete any transient data for current user.
	 *
	 * @return void
	 */
	private function delete_transient_data() {

		$user = wp_get_current_user();

		if ( ! $user instanceof WP_User ) {
			$this->log( 'No current user; skipping deletion of transient data.' );

			return;
		}

		$transient_id = sprintf( self::MESSAGE_TRANSIENT_ID, $user->ID );

		delete_transient( $transient_id );

		$this->log( 'Deleted transient data.' );

	}

	/**
	 * Perform Hydro Raindrop signature verification.
	 *
	 * @return bool
	 */
	private function verify_signature_login() : bool {

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$client = Hydro_Raindrop::get_raindrop_client();

		try {
			$user = wp_get_current_user();

			$hydro_id     = $this->get_user_hydro_id( $user );
			$transient_id = sprintf( self::MESSAGE_TRANSIENT_ID, $user->ID );
			$message      = (int) get_transient( $transient_id );

			$client->verifySignature( $hydro_id, $message );

			$this->delete_transient_data();

			if ( $this->is_first_time_verify() ) {
				// @codingStandardsIgnoreLine
				update_user_meta( $user->ID, 'hydro_raindrop_confirmed', 1 );
			}

			return true;
		} catch ( VerifySignatureFailed $e ) {
			return false;
		}

	}

}
