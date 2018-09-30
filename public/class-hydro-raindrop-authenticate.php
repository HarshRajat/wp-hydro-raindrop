<?php

declare( strict_types=1 );

use Adrenth\Raindrop\Exception\RegisterUserFailed;
use Adrenth\Raindrop\Exception\UnregisterUserFailed;
use Adrenth\Raindrop\Exception\UserAlreadyMappedToApplication;
use Adrenth\Raindrop\Exception\VerifySignatureFailed;

/** @noinspection AutoloadingIssuesInspection */

/**
 * Class Hydro_Raindrop_Authenticate
 */
final class Hydro_Raindrop_Authenticate {

	const MESSAGE_TRANSIENT_ID = 'HydroRaindropMessage_%s';

	/**
	 * The ID of this plugin.
	 *
	 * @var     string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @var     string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Helper.
	 *
	 * @var     Hydro_Raindrop_Helper $helper
	 */
	private $helper;

	/**
	 * Cookie helper.
	 *
	 * @var     Hydro_Raindrop_Cookie $cookie
	 */
	private $cookie;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param      string $plugin_name The name of the plugin.
	 * @param      string $version     The version of this plugin.
	 */
	public function __construct( string $plugin_name, string $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->helper      = new Hydro_Raindrop_Helper();
		$this->cookie      = new Hydro_Raindrop_Cookie( $plugin_name, $version );

	}

	/**
	 * The authenticate filter hook is used to perform additional validation/authentication any time a user logs in to
	 * WordPress.
	 *
	 * @param null|WP_User|WP_Error $user NULL indicates no process has authenticated the user yet. A WP_Error object
	 *                                    indicates another process has failed the authentication.
	 *                                    A WP_User object indicates another process has authenticated the user.
	 *
	 * @return null|WP_User|WP_Error
	 * @throws Exception
	 */
	public function authenticate( $user = null ) {

		if ( ! $user
				|| $user instanceof WP_Error
				|| ! ( $user instanceof WP_User )
				|| ! is_ssl()
		) {
			return $user;
		}

		// @codingStandardsIgnoreLine
		$account_blocked = (bool) get_user_meta(
			$user->ID,
			Hydro_Raindrop_Helper::USER_META_ACCOUNT_BLOCKED,
			true
		);

		/*
		 * User account was blocked because of too many failed MFA attempts.
		 */
		if ( $account_blocked ) {
			return new WP_Error(
				'hydro_raindrop_account_blocked',
				__( 'Your account has been blocked.', 'wp-hydro-raindrop' )
			);
		}

		/*
		 * Set up of Hydro Raindrop MFA is required.
		 */
		if ( $this->user_requires_setup_mfa( $user ) ) {
			$this->log( 'User authenticates and requires Hydro Raindrop MFA Setup.' );
			$this->cookie->set( $user->ID );
			$this->start_mfa_setup( $user );
		}

		/*
		 * Hydro Raindrop MFA is required to proceed.
		 */
		if ( $this->user_requires_mfa( $user ) ) {
			$this->log( 'User authenticates and requires Hydro Raindrop MFA.' );
			$this->delete_transient_data( $user );
			$this->cookie->set( $user->ID );
			$this->start_mfa( $user );
		}

		return $user;

	}

	/**
	 * Verify request.
	 *
	 * @return void
	 * @throws Exception When MFA could not be started.
	 */
	public function verify() {

		$this->verify_post_request();

		/*
		 * Perform first time verification.
		 */
		if ( is_user_logged_in() && $this->is_request_verify() ) {

			$user = wp_get_current_user();

			$this->log( 'Start first time verification.' );
			$this->delete_transient_data( $user );
			$this->cookie->set( $user->ID );
			$this->start_mfa( $user );

		}

		/*
		 * Allow administrator to view the MFA page.
		 */
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();

			if ( user_can( $user, 'administrator' )
					&& $this->helper->is_mfa_page_enabled()
					&& $this->helper->get_current_url() === $this->helper->get_mfa_page_url()
			) {
				return;
			}
		}

		$cookie_is_valid = $this->cookie->validate();

		/*
		 * Protect MFA page.
		 */
		if ( ! $cookie_is_valid
			&& $this->helper->is_mfa_page_enabled()
			&& $this->helper->get_current_url() === $this->helper->get_mfa_page_url() ) {
			// @codingStandardsIgnoreLine
			wp_redirect( home_url() );
			exit;
		}

		/*
		 * Protect Setup page.
		 */
		if ( ! $cookie_is_valid
			&& $this->helper->is_setup_page_enabled()
			&& $this->helper->get_current_url() === $this->helper->get_setup_page_url() ) {
			// @codingStandardsIgnoreLine
			wp_redirect( home_url() );
			exit;
		}

		if ( ! $cookie_is_valid ) {
			return;
		}

		$user = $this->get_current_mfa_user();

		/*
		 * Redirect to MFA page if not already.
		 */
		if ( $this->helper->is_mfa_page_enabled()
				&& $this->user_requires_mfa( $user )
				&& strpos( $this->helper->get_current_url(), $this->helper->get_mfa_page_url() ) === false
		) {
			$this->log( 'User not on Hydro Raindrop MFA page. Redirecting...' );

			// @codingStandardsIgnoreLine
			wp_redirect( $this->helper->get_mfa_page_url() );
			exit;
		}

		/*
		 * User requires Hydro Raindrop MFA setup.
		 */
		if ( $this->user_requires_setup_mfa( $user ) ) {

			$this->log( 'User requires setup Hydro Raindrop MFA.' );

			if ( $this->helper->is_setup_page_enabled()
					&& $this->helper->get_current_url() !== $this->helper->get_setup_page_url()
			) {
				$this->log( 'User not on Hydro Raindrop Setup page. Redirecting...' );

				// @codingStandardsIgnoreLine
				wp_redirect( $this->helper->get_setup_page_url() );
				exit;
			}

			$this->start_mfa_setup( $user );

		}

		/*
		 * Render MFA or Setup page if not on login page.
		 */
		if ( ! $this->helper->is_mfa_page_enabled()
				&& strpos( $this->helper->get_current_url(), 'wp-login.php' ) !== false
		) {
			$this->log( 'User not on Hydro Raindrop MFA page. Render MFA page.' );

			$user = $this->get_current_mfa_user();

			if ( $this->user_requires_setup_mfa( $user ) ) {
				$this->start_mfa_setup( $user );
			}

			$this->start_mfa( $user );
		}

	}

	/**
	 * Get's the current MFA user from cookie.
	 *
	 * @return WP_User|null
	 */
	public function get_current_mfa_user() {

		$user_id = $this->cookie->validate();

		if ( ! $user_id ) {
			return null;
		}

		$user = get_user_by( 'ID', $user_id );

		if ( ! ( $user instanceof WP_User ) ) {
			return null;
		}

		return $user;

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
			set_transient( $transient_id, $message, Hydro_Raindrop_Cookie::MFA_TIME_OUT );
		}

		return (int) $message;

	}

	/**
	 * Verify POST request for Hydro Raindrop specifics.
	 *
	 * @return void
	 */
	private function verify_post_request() {
		// @codingStandardsIgnoreLine
		$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';

		if ( ! $is_post || ! is_ssl() ) {
			return;
		}

		// @codingStandardsIgnoreLine
		$nonce = $_POST['_wpnonce'] ?? null;

		$user = $this->get_current_mfa_user();

		// @codingStandardsIgnoreStart
		/*
		 * VERIFY NONCE FOR THE HYDRO RAINDROP MFA/SETUP PAGE
		 */
		if ( ( isset( $_POST['hydro_raindrop_mfa'] ) && ! wp_verify_nonce( $nonce, 'hydro_raindrop_mfa' ) )
		     || ( isset ( $_POST['hydro_raindrop_setup'] ) && ! wp_verify_nonce( $nonce, 'hydro_raindrop_setup' ) )
		) {
			$this->log( 'Nonce verification failed.' );

			$this->cookie->unset();

			// Delete all transient data which is used during the MFA process.
			if ( $user ) {
				$this->delete_transient_data( $user );
			}

			wp_redirect( home_url() );
			exit;
		}

		// @codingStandardsIgnoreEnd

		/*
		 * Handle Hydro Raindrop Setup.
		 */
		// @codingStandardsIgnoreStart
		if ( isset( $_POST['hydro_raindrop_setup'] ) && $user ) {
			$hydro_id = sanitize_text_field( (string) ( $_POST[ 'hydro_id' ] ?? '' ) );
			$flash    = new Hydro_Raindrop_Flash( $user->user_login );
			$client   = Hydro_Raindrop::get_raindrop_client();
			$length   = strlen( $hydro_id );

			if ( $length < 3 || $length > 32 ) {
				$flash->error( esc_html__( 'Please provide a valid HydroID.', 'wp-hydro-raindrop' ) );
				return;
			}

			$redirect_url = $this->helper->get_current_url( true ) . '?hydro-raindrop-verify=1';

			if ( $this->helper->is_mfa_page_enabled() ) {
				$redirect_url = $this->helper->get_mfa_page_url() . '?hydro-raindrop-verify=1';
			}

			try {

				$client->registerUser( sanitize_text_field( $hydro_id ) );

				$flash->info( 'Your HydroID has been successfully set-up. Enter security code in the Hydro app.' );

			} catch ( UserAlreadyMappedToApplication $e) {

				/*
				 * User is already mapped to this application.
				 *
				 * Edge case: A user tries to re-register with HydroID. If the user meta has been deleted, the
				 *            user can re-use his HydroID but needs to verify it again.
				 */

				$this->log( 'User is already mapped to this application: ' . $e->getMessage() );

				try {
					$client->unregisterUser( $hydro_id );

					$flash->warning( 'Your HydroID was already mapped to this site. Mapping is removed. Please re-enter your HydroID to proceed.' );

					$redirect_url = $this->helper->get_current_url();

					if ( $this->helper->is_setup_page_enabled() ) {
						$redirect_url = $this->helper->get_setup_page_url();
					}
				} catch ( UnregisterUserFailed $e ) {
					$this->log( 'Unregistering user failed: ' . $e->getMessage() );
				}

			} catch ( RegisterUserFailed $e ) {

				$flash->error( $e->getMessage() );

				delete_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_HYDRO_ID );
				delete_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_MFA_ENABLED );
				delete_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_MFA_CONFIRMED );
				delete_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_MFA_FAILED_ATTEMPTS );

				return;

			}

			update_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_HYDRO_ID, $hydro_id );
			update_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_MFA_ENABLED, 1 );
			update_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_MFA_CONFIRMED, 0 );
			update_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_MFA_FAILED_ATTEMPTS, 0 );

			wp_redirect( $redirect_url );
			exit;

			// @codingStandardsIgnoreEnd
		}

		/*
		 * Verify Hydro Raindrop MFA message
		 */
		// @codingStandardsIgnoreLine
		if ( isset( $_POST['hydro_raindrop_mfa'] ) && $user ) {
			if ( $this->verify_signature_login( $user ) ) {
				$this->log( 'MFA success.' );

				$this->cookie->unset();

				// Delete all transient data which is used during the MFA process.
				$this->delete_transient_data( $user );

				if ( ! is_user_logged_in() ) {
					$this->set_auth_cookie( $user );
				}

				/*
				 * Disable Hydro Raindrop MFA.
				 */
				if ( $this->is_action_disable() ) {
					$client = Hydro_Raindrop::get_raindrop_client();

					// @codingStandardsIgnoreStart
					$hydro_id = get_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_HYDRO_ID, true );

					try {
						$client->unregisterUser( $hydro_id );

						delete_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_HYDRO_ID );
						delete_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_MFA_ENABLED );
						delete_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_MFA_CONFIRMED );
						delete_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_MFA_FAILED_ATTEMPTS );

					} catch ( UnregisterUserFailed $e ) {
						$this->log( 'Could not unregister user: ' . $e->getMessage() );
					}
					// @codingStandardsIgnoreEnd
				}

				// Redirect the user to it's intended location.
				$this->redirect( $user );
			} else {

				$flash = new Hydro_Raindrop_Flash( $user->user_login );
				$flash->error( esc_html__( 'Authentication failed.', 'wp-hydro-raindrop' ) );

				$this->delete_transient_data( $user );

				$meta_key = Hydro_Raindrop_Helper::USER_META_MFA_FAILED_ATTEMPTS;

				// @codingStandardsIgnoreLine
				$failed_attempts = (int) get_user_meta( $user->ID, $meta_key, true );

				// @codingStandardsIgnoreLine
				update_user_meta( $user->ID, $meta_key, ++ $failed_attempts );

				$this->log( 'MFA failed, attempts: ' . $failed_attempts );

				/*
				 * Block user account if maximum MFA attempts has been reached.
				 */
				$maximum_attempts = (int) get_option( Hydro_Raindrop_Helper::OPTION_MFA_MAXIMUM_ATTEMPTS );

				if ( $maximum_attempts > 0 && $failed_attempts > $maximum_attempts ) {
					// @codingStandardsIgnoreStart
					update_user_meta( $user->ID, $meta_key, 0 );
					update_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_ACCOUNT_BLOCKED, true );

					$flash->error( esc_html__( 'Your account has been blocked.', 'wp-hydro-raindrop' ) );

					$this->cookie->unset();

					wp_logout();

					wp_redirect( wp_login_url() );
					exit;
					// @codingStandardsIgnoreEnd
				}

				return;
			}
		}

		/*
		 * Skip Hydro Raindrop Setup
		 */
		// @codingStandardsIgnoreLine
		if ( isset( $_POST['hydro_raindrop_setup_skip'] )
				&& wp_verify_nonce( $nonce, 'hydro_raindrop_setup' )
		) {
			$method = (string) get_option( Hydro_Raindrop_Helper::OPTION_MFA_METHOD );

			$user = $this->get_current_mfa_user();

			if ( $user
					&& ( Hydro_Raindrop_Helper::MFA_METHOD_PROMPTED === $method
						|| Hydro_Raindrop_Helper::MFA_METHOD_OPTIONAL === $method
					)
			) {
				$this->cookie->unset();
				$this->delete_transient_data( $user );
				$this->set_auth_cookie( $user );
				$this->redirect( $user );
			}
		}

		/*
		 * Allow user to cancel the MFA. Which results in a logout.
		 */
		// @codingStandardsIgnoreLine
		if ( isset( $_POST['hydro_raindrop_mfa_cancel'] )
				&& wp_verify_nonce( $nonce, 'hydro_raindrop_mfa' )
		) {
			$this->log( 'User cancels MFA.' );

			$this->cookie->unset();

			// Delete all transient data which is used during the MFA process.
			if ( $user ) {
				$this->delete_transient_data( $user );
			}

			// @codingStandardsIgnoreLine
			wp_redirect( home_url() );
			exit;
		}

	}

	/**
	 * Start Hydro Raindrop Multi Factor Authentication.
	 *
	 * @param WP_User $user Authenticated user.
	 *
	 * @throws Exception If message could not be generated.
	 */
	private function start_mfa( WP_User $user ) {

		$this->log( 'Start MFA.' );

		$error = null;

		/*
		 * Redirect to the Custom MFA page (if applicable).
		 */
		if ( $this->helper->is_mfa_page_enabled() ) {

			$this->log( 'MFA page is enabled.' );

			if ( strpos( $this->helper->get_current_url(), $this->helper->get_mfa_page_url() ) !== 0 ) {
				// @codingStandardsIgnoreLine
				wp_redirect( $this->helper->get_mfa_page_url() );
				exit;
			}

			return;
		}

		require __DIR__ . '/partials/hydro-raindrop-public-mfa.php';
		exit;

	}

	/**
	 * Start Hydro Raindrop Setup.
	 *
	 * @param WP_User $user Authenticated user.
	 * @return void
	 */
	private function start_mfa_setup( WP_User $user ) {

		$this->log( 'Start MFA Setup.' );

		if ( $this->helper->is_setup_page_enabled() ) {

			$this->log( 'Setup page is enabled.' );

			if ( strpos( $this->helper->get_current_url(), $this->helper->get_setup_page_url() ) !== 0 ) {
				// @codingStandardsIgnoreLine
				wp_redirect( $this->helper->get_setup_page_url() );
				exit;
			}

			return;
		}

		require __DIR__ . '/partials/hydro-raindrop-public-setup.php';
		exit;

	}

	/**
	 * Set the WP Auth Cookie.
	 *
	 * @param WP_User $user     Authenticated user.
	 * @param bool    $remember Whether to remember the user.
	 * @return void
	 */
	private function set_auth_cookie( WP_User $user, bool $remember = false ) {

		wp_set_auth_cookie( $user->ID, $remember ); // TODO: Remember login parameter.

	}

	/**
	 * Redirects user after successful login and MFA.
	 *
	 * @param WP_User $user Current logged in user.
	 *
	 * @return void
	 */
	private function redirect( WP_User $user ) {

		if ( $this->is_request_verify() && $this->helper->is_setup_page_enabled() ) {

			// @codingStandardsIgnoreLine
			wp_redirect( $this->helper->get_setup_page_url() );
			exit;

		}

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

		if ( ( empty( $redirect_to ) || 'wp-admin/' === $redirect_to || $redirect_to === admin_url() ) ) {
			/*
			 * If the user doesn't belong to a blog, send them to user admin.
			 * If the user can't edit posts, send them to their profile.
			 */
			if ( is_multisite() && ! get_active_blog_for_user( $user->ID ) && ! is_super_admin( $user->ID ) ) {
				$redirect_to = user_admin_url();
			} elseif ( is_multisite() && ! $user->has_cap( 'read' ) ) {
				$redirect_to = get_dashboard_url( $user->ID );
			} elseif ( ! $user->has_cap( 'edit_posts' ) ) {
				$redirect_to = $user->has_cap( 'read' ) ? admin_url( 'profile.php' ) : $this->helper->get_home_url();
			}

			// @codingStandardsIgnoreLine
			wp_redirect( $redirect_to );
			exit;
		}

		wp_safe_redirect( $redirect_to );
		exit;

	}

	/**
	 * Get the users' HydroID.
	 *
	 * @param WP_User $user Current logged in user.
	 *
	 * @return string
	 */
	private function get_user_hydro_id( WP_User $user ) : string {

		// @codingStandardsIgnoreLine
		return (string) get_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_HYDRO_ID, true );

	}

	/**
	 * Whether this is a request for verification.
	 *
	 * @return bool
	 */
	private function is_request_verify() : bool {

		// @codingStandardsIgnoreLine
		return (int) ( $_GET['hydro-raindrop-verify'] ?? 0 ) === 1;

	}

	/**
	 * Whether to disable Hydro Raindrop MFA.
	 *
	 * @return bool
	 */
	private function is_action_disable() : bool {

		// @codingStandardsIgnoreLine
		return ( $_GET['hydro-raindrop-action'] ?? '') === 'disable';

	}

	/**
	 * Whether to enable Hydro Raindrop MFA.
	 *
	 * @return bool
	 */
	private function is_action_enable() : bool {

		// @codingStandardsIgnoreLine
		return ( $_GET['hydro-raindrop-action'] ?? '') === 'enable';

	}

	/**
	 * Checks whether given user requires Hydro Raindrop MFA.
	 *
	 * @param WP_User $user An authenticated user.
	 *
	 * @return bool
	 */
	private function user_requires_mfa( WP_User $user ) : bool {

		$enabled = (bool) get_option( Hydro_Raindrop_Helper::OPTION_ENABLED );

		if ( ! $enabled ) {
			return false;
		}

		// @codingStandardsIgnoreStart

		$hydro_id                 = $this->get_user_hydro_id( $user );
		$hydro_mfa_enabled        = (bool) get_user_meta(
			$user->ID,
			Hydro_Raindrop_Helper::USER_META_MFA_ENABLED,
			true
		);
		$hydro_raindrop_confirmed = (bool) get_user_meta(
			$user->ID,
			Hydro_Raindrop_Helper::USER_META_MFA_CONFIRMED,
			true
		);

		// @codingStandardsIgnoreEnd

		return ! empty( $hydro_id )
			&& $hydro_mfa_enabled
			&& ( $hydro_raindrop_confirmed || $this->is_request_verify() );

	}

	/**
	 * Checks whether given User requires to set up Hydro Raindrop MFA.
	 *
	 * @param WP_User $user An authenticated user.
	 *
	 * @return bool
	 */
	private function user_requires_setup_mfa( WP_User $user ) : bool {

		$enabled = (bool) get_option( Hydro_Raindrop_Helper::OPTION_ENABLED );

		if ( ! $enabled ) {
			return false;
		}

		/*
		 * User wants to enable Hydro Raindrop MFA from user profile.
		 */
		if ( $this->is_action_enable() ) {
			return true;
		}

		$method = get_option( Hydro_Raindrop_Helper::OPTION_MFA_METHOD );

		switch ( $method ) {
			case Hydro_Raindrop_Helper::MFA_METHOD_OPTIONAL:
				return false;
			case Hydro_Raindrop_Helper::MFA_METHOD_PROMPTED:
			case Hydro_Raindrop_Helper::MFA_METHOD_ENFORCED:
				return ! $this->user_requires_mfa( $user );
		}

		return false;

	}

	/**
	 * Delete any transient data for current user.
	 *
	 * @param WP_User $user User.
	 *
	 * @return void
	 */
	private function delete_transient_data( WP_User $user ) {

		$transient_id = sprintf( self::MESSAGE_TRANSIENT_ID, $user->ID );

		delete_transient( $transient_id );

		$this->log( 'Deleted transient data.' );

	}

	/**
	 * Perform Hydro Raindrop signature verification.
	 *
	 * @param WP_User $user The user to verify the signature login for.
	 *
	 * @return bool
	 */
	private function verify_signature_login( WP_User $user ) : bool {

		$client = Hydro_Raindrop::get_raindrop_client();

		try {
			$hydro_id     = $this->get_user_hydro_id( $user );
			$transient_id = sprintf( self::MESSAGE_TRANSIENT_ID, $user->ID );
			$message      = (int) get_transient( $transient_id );

			$client->verifySignature( $hydro_id, $message );

			$this->delete_transient_data( $user );

			if ( $this->is_request_verify() ) {
				// @codingStandardsIgnoreLine
				update_user_meta( $user->ID, Hydro_Raindrop_Helper::USER_META_MFA_CONFIRMED, 1 );
			}

			return true;
		} catch ( VerifySignatureFailed $e ) {
			$this->log( $e->getMessage() );
			return false;
		}

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

}
