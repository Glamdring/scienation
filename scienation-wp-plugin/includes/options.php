<?php
if( is_admin() ) {
	new ScienationSettingsPage();
}

class ScienationSettingsPage {
	
	public function __construct() {
		add_action( 'admin_menu', array(&$this, 'create_menu'));
		add_action( 'admin_init', array(&$this, 'register_settings') );
	}
	
	public function create_menu() {
		// This page will be under "Settings"
        add_menu_page(
            'Scienation Setting', 
            'Scienation Setting', 
            'manage_options', 
            'scienation-setting-admin', 
            array( $this, 'create_admin_page' )
        );
	}

	public function register_settings() {
		register_setting( 'scienation-settings-group', 'orcid' );
		register_setting( 'scienation-settings-group', 'enabled_by_default' );
		register_setting( 'scienation-settings-group', 'register_at_scienation' );
	}

	public function create_admin_page() {
	?>
	<div class="wrap">
	<h1>Scienation options</h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'scienation-settings-group' ); ?>
		<?php do_settings_sections( 'scienation-settings-group' ); ?>
		<table class="form-table">
			<tr valign="top">
			<th scope="row" style="width: 250px;">Your ORCID</th>
			<td><input type="text" name="orcid" value="<?php echo esc_attr( get_option('orcid') ); ?>" /></td>
			</tr>
			 
			<tr valign="top">
			<th scope="row">Enabled on new posts by default</th>
			<td><input type="checkbox" name="enabled_by_default" value="true" <?php checked(get_option('enabled_by_default', true) == true ); ?>" /></td>
			</tr>
			
			<tr valign="top">
			<th scope="row">Automatically register at <a href="http://scienation.com">scienation.com</a> (get your site listed)</th>
			<td><input type="checkbox" name="register_at_scienation" value="true" <?php checked(get_option('register_at_scienation', true) == true ); ?>" /></td>
			</tr>
		</table>
		
		<?php submit_button(); ?>

	</form>
	</div>
<?php 
	}
}
?>