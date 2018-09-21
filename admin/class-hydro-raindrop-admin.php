<?php

declare( strict_types=1 );

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://github.com/adrenth/wp-hydro-raindrop
 * @since      1.0.0
 *
 * @package    Hydro_Raindrop
 * @subpackage Hydro_Raindrop/admin
 */

use Adrenth\Raindrop\Exception\RefreshTokenFailed;

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Hydro_Raindrop
 * @subpackage Hydro_Raindrop/admin
 * @author     Alwin Drenth <adrenth@gmail.com>, Ronald Drenth <ronalddrenth@gmail.com>
 */
class Hydro_Raindrop_Admin {

	const OPTION_GROUP_SYSTEM_REQUIREMENTS = 'hydro_raindrop_system_requirements';
	const OPTION_GROUP_API_SETTINGS        = 'hydro_raindrop_api_settings';
	const OPTION_GROUP_CUSTOMIZATION       = 'hydro_raindrop_customization';

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
	 * @param      string $plugin_name The name of this plugin.
	 * @param      string $version     The version of this plugin.
	 */
	public function __construct( string $plugin_name, string $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;

	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		wp_enqueue_style(
			$this->plugin_name,
			plugin_dir_url( __FILE__ ) . 'css/hydro-raindrop-admin.css',
			[],
			$this->version
		);

	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function admin_init() {

		register_setting( self::OPTION_GROUP_SYSTEM_REQUIREMENTS, Hydro_Raindrop_Helper::OPTION_ENABLED );

		register_setting( self::OPTION_GROUP_API_SETTINGS, Hydro_Raindrop_Helper::OPTION_APPLICATION_ID );
		register_setting( self::OPTION_GROUP_API_SETTINGS, Hydro_Raindrop_Helper::OPTION_CLIENT_ID );
		register_setting( self::OPTION_GROUP_API_SETTINGS, Hydro_Raindrop_Helper::OPTION_CLIENT_SECRET );
		register_setting( self::OPTION_GROUP_API_SETTINGS, Hydro_Raindrop_Helper::OPTION_ENVIRONMENT );

		register_setting( self::OPTION_GROUP_CUSTOMIZATION, Hydro_Raindrop_Helper::OPTION_CUSTOM_MFA_PAGE );
		register_setting( self::OPTION_GROUP_CUSTOMIZATION, Hydro_Raindrop_Helper::OPTION_CUSTOM_HYDRO_ID_PAGE );
		register_setting( self::OPTION_GROUP_CUSTOMIZATION, Hydro_Raindrop_Helper::OPTION_MFA_METHOD );

	}

	/**
	 * Add options page.
	 *
	 * @return void
	 */
	public function admin_menu() {

		add_menu_page(
			'Hydro Raindrop: General',
			'Hydro Raindrop',
			'manage_options',
			$this->plugin_name,
			[
				$this,
				'settings_page',
			],
			plugins_url( 'images/icon.svg', __FILE__ )
		);

		add_submenu_page(
			$this->plugin_name,
			'Hydro Raindrop: Settings',
			'Settings',
			'manage_options',
			$this->plugin_name,
			[
				$this,
				'settings_page',
			]
		);

		add_submenu_page(
			$this->plugin_name,
			'Hydro Raindrop: FAQ',
			'FAQ',
			'manage_options',
			$this->plugin_name . '-faq',
			[
				$this,
				'faq_page',
			]
		);

	}

	/**
	 * Before updating an option.
	 *
	 * @param mixed  $value     New value.
	 * @param string $option    Option which has been updated.
	 * @param mixed  $old_value Old value.
	 *
	 * @return mixed
	 */
	public function pre_update_option( $value, string $option, $old_value ) {

		switch ( $option ) {
			case Hydro_Raindrop_Helper::OPTION_ENABLED:
				// User wants to enable or disabled Hydro Raindrop MFA.
				if ( ( 0 === (int) $old_value && 1 === (int) $value )
						|| ( 1 === (int) $old_value && 0 === (int) $value )
				) {
					// @codingStandardsIgnoreStart
					$user_login = $_POST['user_login'] ?? null;
					$password = $_POST['password'] ?? null;
					// @codingStandardsIgnoreEnd

					$user = wp_authenticate( $user_login, $password );

					if ( is_wp_error( $user ) ) {
						add_settings_error(
							$option,
							'invalid_credentials',
							$user->get_error_message()
						);

						return (int) ( new Hydro_Raindrop_Helper() )->is_hydro_raindrop_enabled();
					}
				}
				break;
		}

		return $value;
	}

	/**
	 * Hydro Raindrop environment options have been changed.
	 *
	 * @param mixed $option Option which has been updated.
	 *
	 * @return void
	 */
	public function update_option( $option ) {

		switch ( $option ) {

			case Hydro_Raindrop_Helper::OPTION_APPLICATION_ID:
			case Hydro_Raindrop_Helper::OPTION_CLIENT_ID:
			case Hydro_Raindrop_Helper::OPTION_CLIENT_SECRET:
			case Hydro_Raindrop_Helper::OPTION_ENVIRONMENT:
				$token_storage = new Hydro_Raindrop_TransientTokenStorage();
				$token_storage->unsetAccessToken();

				delete_option( Hydro_Raindrop_Helper::OPTION_ACCESS_TOKEN_SUCCESS );

				delete_metadata( 'user', 0, Hydro_Raindrop_Helper::USER_META_HYDRO_ID, '', true );
				delete_metadata( 'user', 0, Hydro_Raindrop_Helper::USER_META_HYDRO_MFA_ENABLED, '', true );
				delete_metadata( 'user', 0, Hydro_Raindrop_Helper::USER_META_HYDRO_RAINDROP_CONFIRMED, '', true );

				break;
		}

	}

	/**
	 * Display the settings page.
	 *
	 * @return void
	 */
	public function settings_page() {

		include __DIR__ . '/../admin/partials/hydro-raindrop-settings.php';

	}

	/**
	 * Display the FAQ page.
	 *
	 * @return void
	 */
	public function faq_page() {

		// TODO: Render external URL.

	}

	/**
	 * @return bool
	 */
	public function options_are_valid() : bool {

		$token_success = (string) get_option( Hydro_Raindrop_Helper::OPTION_ACCESS_TOKEN_SUCCESS, '' );

		if ( empty( $token_success ) && Hydro_Raindrop::has_valid_raindrop_client_options() ) {
			try {
				$client = Hydro_Raindrop::get_raindrop_client();
				$client->getAccessToken();

				update_option( Hydro_Raindrop_Helper::OPTION_ACCESS_TOKEN_SUCCESS, 1 );

				return true;
			} catch ( RefreshTokenFailed $e ) {
				return false;
			}
		}

		return true;

	}

	/**
	 * Display the activation notice.
	 *
	 * @return void
	 */
	public function activation_notice() {

		if ( get_option( Hydro_Raindrop_Helper::OPTION_ACTIVATION_NOTICE ) ) {
			return;
		}

		$option_page_url = admin_url( 'options-general.php?page=' . $this->plugin_name );

		$message = sprintf(
			__( 'Succesfully activated the WP Hydro Raindrop plugin, to configure the plugin go to the Hydro Raindrop MFA <a style="color: #fff; font-weight: bold;" href="%1$s">settings page</a>.', $this->plugin_name ),
			esc_url( $option_page_url )
		);

		printf(
			'<div class="notice is-dismissible" style="background-color: #5591f3; color: #fff; border-left: none;">
				<p>%1$s</p>
			</div>',
			$message
		);

		add_option( Hydro_Raindrop_Helper::OPTION_ACTIVATION_NOTICE, '1' );

	}

	/**
	 * Add action links to plugins table.
	 *
	 * @param array $links Default links.
	 * @return array
	 */
	public function add_action_links( array $links = [] ) : array {

		$option_page_url = admin_url( 'options-general.php?page=' . $this->plugin_name );

		$add_links = [
			'<a href="' . $option_page_url . '">' . __( 'Settings', 'wp-hydro-raindrop' ) . '</a>',
		];

		return array_merge( $links, $add_links );
	}

	/**
	 * @return array
	 */
	public function get_post_options() : array {

		$args = array(
			'post_type'      => 'page',
			'posts_per_page' => -1,
			'order'          => 'ASC',
			'orderby'        => 'menu_order',
		);

		$parent = new WP_Query( $args );

		$posts = [];

		while ( $parent->have_posts() ) {
			$parent->the_post();

			$post_id = get_the_ID();

			if ( $post_id ) {
				/**
				 * The variable $posts is used in the template file.
				 *
				 * @noinspection OnlyWritesOnParameterInspection
				 */
				$posts[ (int) $post_id ] = get_the_title() . ' - ' . get_the_permalink();
			}
		}

		return $posts;

	}

}
