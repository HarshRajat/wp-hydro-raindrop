<?php

declare( strict_types=1 );

/**
 * Hydro Raindrop MFA user profile form extension.
 *
 * @package    Hydro_Raindrop
 * @author     Alwin Drenth <adrenth@gmail.com>, Ronald Drenth <ronalddrenth@gmail.com>
 */

$hydro_id                          = (string) $user->hydro_id;
$hydro_mfa_enabled                 = (bool) $user->hydro_mfa_enabled;
$hydro_raindrop_confirmed          = (bool) $user->hydro_raindrop_confirmed;
$has_valid_raindrop_client_options = Hydro_Raindrop::has_valid_raindrop_client_options();
$custom_mfa_page                   = (int) get_option( 'hydro_raindrop_custom_mfa_page' );
$custom_hydro_raindrop_mfa_uri     = get_permalink( $custom_mfa_page );

?>
<h2>Hydro Raindrop MFA</h2>
<?php if ( ! $has_valid_raindrop_client_options ) : ?>
	<p class="error-message">
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			The Hydro Raindrop MFA plugin is not properly configured, please review the
			<a href="<?php echo self_admin_url( 'options-general.php?page=' . $this->plugin_name ); ?>-options">
				Hydro Raindrop MFA Settings
			</a>
			and try again.
		<?php else : ?>
			The Hydro Raindrop MFA plugin is not properly configured.
		<?php endif; ?>
	</p>
<?php else : ?>

	<?php if ( ! $hydro_mfa_enabled ) : ?>
		<p class="error-message">Your account does not have Hydro Raindrop MFA enabled.</p>
	<?php endif ?>

	<?php if ( $hydro_mfa_enabled && ! $hydro_raindrop_confirmed ) : ?>
		<p class="error-message">
			Your account does have Hydro Raindrop MFA enabled, but it is unconfirmed.
			<?php if ( $custom_mfa_page > 0 && get_post_status( $custom_mfa_page ) === 'publish' ) : ?>
			<a href="<?php echo esc_attr( $custom_hydro_raindrop_mfa_uri . '?hydro-raindrop-verify=1' ); ?>">Click here</a> to confirm.
			<?php else : ?>
			<a href="<?php echo esc_attr( self_admin_url( 'profile.php?hydro-raindrop-verify=1' ) ); ?>">Click here</a> to confirm.
			<?php endif; ?>
		</p>
	<?php endif ?>

	<?php if ( $hydro_mfa_enabled && $hydro_raindrop_confirmed ) : ?>
		<p class="hydro-success">
			Your account has Hydro Raindrop MFA enabled and confirmed.
		</p>
	<?php endif ?>

	<table class="form-table hydro">
		<?php if ( $hydro_mfa_enabled ) : ?>
			<tr>
				<th scope="row">Unregister</th>
				<td>
					<button type="submit" class="button" name="disable_hydro_mfa" id="disable_hydro_mfa_button">
						Unregister Raindrop MFA
					</button>
				</td>
			</tr>
		<?php endif ?>
		<tr>
			<th scope="row">
				<label for="hydro_id">HydroID</label>
			</th>
			<td>
				<?php if ( $hydro_mfa_enabled ) : ?>
					<input type="text"
							id="hydro_id"
							maxlength="7"
							class="code"
							value="<?php echo esc_attr( $hydro_id ); ?>"
							disabled
							autocomplete="off"/>
				<?php else : ?>
					<input type="text"
							name="hydro_id"
							id="hydro_id"
							size="7"
							minlength="3"
							maxlength="32"
							class="code"
							value="<?php esc_attr( $_POST['hydro_id'] ?? '' ); ?>"
							autocomplete="off"/>

					<p class="description">Enter your HydroID, visible in the Hydro mobile app.</p>
				<?php endif ?>
			</td>
		</tr>
	</table>

<?php endif ?>
