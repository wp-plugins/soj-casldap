<?php
/*
Plugin Name: SoJ CAS/LDAP Login
Plugin URI: http://journalism.indiana.edu/apps/mediawiki-1.10.1/index.php/Wp_cas_ldap
Description: Augments WordPress's log-in system. The user is authenticated against a CAS, and their authorization level is determined via LDAP. Valid log-ins that do not yet have WordPress accounts can optionally have WordPress accounts created for them.
Author: Jeff Johnson
Version: 1.0
Author URI:
*/

if(!function_exists('wp_insert_user'))
	require_once(ABSPATH.WPINC.'/registration-functions.php');

require_once 'soj-ldap_config.inc.php';

/**
 * When a new user is added to WordPress, add the user to the Login databse
 */
/*
function add_user_to_login_db($uid)
{
	// Get the user
	$user = new WP_User($uid);

	// Do something with user information
}
add_action('user_register','add_user_to_login_db');
*/

/**
 * Stolen from role-manager plugin
 */
function get_cap_list($roles = true, $kill_levels = true) {
	global $wp_roles;
	
	// Get Role List
	foreach($wp_roles->role_objects as $key => $role) {
		foreach($role->capabilities as $cap => $grant) {
			$capnames[$cap] = $cap;
		}
	}    
	
	if ($caplist = get_settings('caplist')) {
		$capnames = array_unique(array_merge($caplist, $capnames));
	}
	
	$capnames = apply_filters('capabilities_list', $capnames);
	if(!is_array($capnames)) $capnames = array();
	$capnames = array_unique($capnames);
	sort($capnames);

	//Filter out the level_x caps, they're obsolete
	if($kill_levels) {
		$capnames = array_diff($capnames, array('level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5',
			'level_6', 'level_7', 'level_8', 'level_9', 'level_10'));
	}
	
	//Filter out roles if required
	if (!$roles) {
		foreach ($wp_roles->get_names() as $role) {
			$key = array_search($role, $capnames);
			if ($key !== false && $key !== null) { //array_search() returns null if not found in 4.1
				unset($capnames[$key]);
			}
		}
	}
	
	return $capnames;
}

/**
 * Force the existence of an admin account for tech staff: If the current or
 * passed user is a member of the tech staff LDAP group, force their role to
 * be 'Administrator,' and make sure that the 'Administrator' role has all
 * capabilities.
 */
function soj_admin_user($user_login='')
{
	global $wpdb;
	global $soj_admin_config;
	global $wp_roles;

	// Get the user you want to make into an admin; if no username is passed, default to the current user
	if(strcmp($user_login,'')==0)
		$user = wp_get_current_user();
	else
		$user = new WP_User($user_login);

	// Proceed only if this user is in the appropriate group on the LDAP server
	$groups = get_group_membership($user->user_login);
	if(!isset($groups[$soj_admin_config['soj_admin_group']])) return;

	$admin_role = sanitize_title($soj_admin_config['soj_admin_role']);

	// Make sure an 'Administrator' role exists
	$capability_list = get_cap_list();
	if( !isset($wp_roles->role_names[$admin_role]) )
	{
		$caps = array();
		foreach($capability_list as $key=>$value)
			$caps[$value] = 1;
		$role = $wp_roles->add_role($admin_role, stripslashes($soj_admin_config['soj_admin_role']), $caps);
	}
	else
	{
		$role = $wp_roles->get_role($admin_role);
	}

	// Make sure $soj_admin_config['soj_admin_role'] has all capabilities
	foreach($capability_list as $key=>$value)
	{
		if( !$role->has_cap($value) ) $role->add_cap($value);
	}

	// Make sure the tech staff user is assigned to $soj_admin_config['soj_admin_role']
	$user->set_role($admin_role);
}

/**
 * Authenticate username and password against LDAP server
 */
function soj_ldap_auth()
{
	global $soj_admin_config;
	global $wp_roles;
	
	// Determine where we are and make sure to remove the casticket from the URI
	if($tmp=parse_url(get_option('siteurl')))
	{
		$root = $tmp['scheme'].'://'.$tmp['host'];
	}
	else
	{
		$root = '';
	}
	$uri = $root.preg_replace('|casticket=[^&]*|','',$_SERVER['REQUEST_URI']);

	/**
	 * Do CAS authentication.
	 */
	$cas_authentication = cas_authenticate($uri);
	$user_login = $cas_authentication['username'];
	$user_pass = CAS_DEFAULT_PASSWORD;

	//// For testing
	//$user_login = 'sojstudent';
	//$user_pass = CAS_DEFAULT_PASSWORD;

	/**
	 * Verify whether $username exists on Brady.
	 */
	if(!get_ldap_user($user_login))
	{
		// LDAP authentication failed
		header('Content-type: text/plain');
		die('LDAP authentication failed.');
	}

	// If we're here LDAP authentication succeeded, so check to see if
	// the user is registered in WordPress. If not, create a default account for
	// them and log in under it.
	$id = username_exists($user_login);
	if($id==NULL)
	{
		if(intval($soj_admin_config['soj_user_autocreate'])==1)
		{
			$default_role = get_option('default_role');
	
			// Pull the user's full name and e-mail address from the database and use them
			$user_first_name = '';
			$user_last_name = '';
			$user_email = '';
			
			// Create WordPress account for $user_login
			$user_info = array();
			$user_info['user_login']= $user_login;
			$user_info['user_email']= $user_email;
			$user_info['user_pass']= $user_pass;
			$user_info['first_name']= $user_first_name;
			$user_info['last_name']= $user_last_name;
			$user_info['nickname']= '';
			$user_info['rich_editing'] = 'false';
			wp_insert_user($user_info);
	
			// Get admin group name and plug-in options
			$soj_admin_group = $soj_admin_config['soj_admin_group'];
			$soj_admin_config = get_option('soj-ldap');
	
			// Get the user's group memberships
			$groups = get_group_membership($user_login);
	
			// If the user is a member of the admin group, set the role to the admin role
			if( isset($groups[$soj_admin_group]) )
			{
				soj_admin_user($user_login);
			}
			// Otherwise, use either any roles that have been specified in the
			// plug-in admin, or the WP defaults
			elseif( is_array($soj_admin_config['soj_roles']) )
			{
				foreach($groups as $key=>$value)
				{
					$tmp = $soj_admin_config['soj_roles'][$key];
	
					// If this group has a default role assigned and it's a valid role, use it
					if( isset($tmp) && isset($wp_roles->role_names[$tmp]) && strcmp($default_role,$tmp)!=0 )
					{
						$role = sanitize_title($tmp);
						if( isset($wp_roles->role_names[$role]) )
						{
							$user_id = username_exists($user_login);
							$user = new WP_User($user_id);
							$user->set_role($role);
						}
						break;
					}
					// Otherwise, the WP default will be used.
				}
			}
		}
		else
		{
			// The user has no current account, and autocreates are not enabled
			header('Content-type: text/plain');
			die('You need to have an account set up first.');
		}
	}

	// Set user credentials
	return array(
		'user_login' => $user_login,
		'user_password' => $user_pass
	);
}

/**
 * Log the user out of CAS
 */
function soj_cas_logout()
{
	header('Location: '.CAS_LOGOUT);
	die();
}

/**
 * Log the user into CAS
 */
function soj_cas_login_action($l, $p)
{
	$user_auth = soj_ldap_auth();
	$l = $user_auth['user_login'];
	$p = $user_auth['user_password'];
}

/**
 * Generate admin panel for plugin
 */
function soj_ldap_options_subpanel() {

	global $soj_admin_config;
	global $wp_roles;
	global $table_prefix;

	$groups = get_groups();
	$roles = $wp_roles->get_names();

  if (isset($_POST['info_update'])) {
    	// Build config array
    	$soj_admin_config['soj_force_ldap'] = $_POST['soj_force_ldap'];
    	$soj_admin_config['soj_user_autocreate'] = $_POST['soj_user_autocreate'];
    	$soj_admin_config['soj_lock_password'] = $_POST['soj_lock_password'];
    	
    	// $default_roles_array = array( gid => role )
    	$default_roles_array = array();
    	foreach($groups as $key=>$value)
	    	$default_roles_array[intval($key)] = $_POST['g'.$key];
    	$soj_admin_config['soj_roles'] = $default_roles_array;

		// Perform update
		update_option('soj-ldap',$soj_admin_config);
		echo '<div class="updated"><p><strong>Update successful.</strong></p></div>';
	}
	?>
    
	<div class="wrap">
		<?php if(isset($message)) { ?>
		<div id="message" class="updated fade">
			<p><?php _e($message); ?></p>
		</div>
		<?php } ?>

		<h2>User Authorization Configuration</h2>
	  <form method="post" action="">

		<?php
			if ( function_exists('wp_nonce_field') )
				wp_nonce_field('plugin-name-action_' . $your_object);

			// Get updated options
			$soj_admin_group = $soj_admin_config['soj_admin_group'];
			$soj_admin_config = get_option('soj-ldap');
		?>

		<div class="submit" style="float:right;">
		  <input type="submit" name="info_update" value="<?php _e('Update options', 'soj-ldap'); ?> &#187;" />
		</div>

		 <fieldset class="options">
		 	<legend>Authorization</legend>
		 	<table cellpadding="3" cellspacing="0">
		 	<tbody>
		 	<tr>
		 		<td>
		 			<input id="soj_lock_password" type="hidden" name="soj_lock_password" value="<?php echo $soj_admin_config['soj_lock_password']; ?>" />
		 			<label>Lock password:</label>
		 		</td>
		 		<td><input type="checkbox" onchange="window.document.getElementById('soj_lock_password').value=this.checked?'1':'0';" <?php echo intval($soj_admin_config['soj_lock_password'])==1 ? 'checked="checked" ' : ''; ?> disabled="disabled" /></td>
		 	</tr>
		 	<tr>
		 		<td>
		 			<input id="soj_user_autocreate" type="hidden" name="soj_user_autocreate" value="<?php echo $soj_admin_config['soj_user_autocreate']; ?>" />
		 			<label>Auto-create new users:</label>
		 		</td>
		 		<td><input type="checkbox" onchange="window.document.getElementById('soj_user_autocreate').value=this.checked?'1':'0';" <?php echo intval($soj_admin_config['soj_user_autocreate'])==1 ? 'checked="checked" ' : ''; ?>/></td>
		 	</tr>
		 	<tr>
		 		<td>
		 			<input id="soj_force_ldap" type="hidden" name="soj_force_ldap" value="<?php echo $soj_admin_config['soj_force_ldap']; ?>" />
		 			<label>Force LDAP Authorization:</label>
		 		</td>
		 		<td><input type="checkbox" onchange="window.document.getElementById('soj_force_ldap').value=this.checked?'1':'0';" <?php echo intval($soj_admin_config['soj_force_ldap'])==1 ? 'checked="checked" ' : ''; ?>/></td>
		 	</tr>
		 	</tbody>
		 	</table>
		 </fieldset>

		<div style="clear:both;"></div>

		<div style="background-color:#f9fcfe;color:#333;border:1px solid #83b4d8;padding:10px 40px;text-align:left;margin:20px 0;"><strong style="line-height:2em;">PLEASE NOTE:</strong><br />
			<ul>
			<li>If a person belongs to more than one group, then the group they
			belong to that is highest on this list will determine what their
			role is, unless it's the same as the default role assigned in <strong>Options &gt; General</strong>!</li>
			<li>These roles only act as defaults&#8212;once an individual account exists, the role can be
			changed as usual and at any time in the <strong>Users &gt; Authors &amp; Users</strong>.</li>
			</ul>
		</div>

		<fieldset class="options">
		 	<legend>Default Roles</legend>
		 	<table cellpadding="3" cellspacing="0">
		 	<tbody>
 			<?php
 				foreach($groups as $key=>$value)
 				{
 					if($soj_admin_group==intval($key)) continue;
 					echo '<tr><td><label>'.$value.'</label></td><td>';
 					echo '<select name="g'.$key.'">';

 					if( isset($soj_admin_config['soj_roles'][$key]) && isset($wp_roles->role_names[$soj_admin_config['soj_roles'][$key]]) )
 						wp_dropdown_roles( $soj_admin_config['soj_roles'][$key] );
 					else
 						wp_dropdown_roles( get_option('default_role') );
					
					
 					echo '</select></td></tr>';
		 		}
 			?>
		 	</tbody>
		 	</table>

		 </fieldset>
	  </form>
	 </div>
	 <?php
}

/**
 * Add admin panel for plugin
 */
function soj_ldap_options_panel() {
    if (function_exists('add_options_page')) {
		add_options_page('SoJ-Login', 'SoJ-Login', 'manage_options', __FILE__, 'soj_ldap_options_subpanel');
    }
 }

/**
 * Prevent users from changing the WordPress password
 */
function soj_disable_password_change()
{
	echo '
<script type="text/javascript">
//<![CDATA[
	var items = window.document.getElementById("your-profile").getElementsByTagName("input");
	for(var i=items.length-1,name; i>=0; i--)
	{
		name = items[i].getAttribute("name");
		if(name=="pass1" || name=="pass2")
			items[i].disabled = true;
	}
//]]>
</script>
		';
}

add_action('wp_authenticate','soj_cas_login_action',10,2);
add_action('show_user_profile', 'soj_disable_password_change');
add_action('admin_head', 'soj_admin_user');
add_action('admin_menu', 'soj_ldap_options_panel');
add_action('wp_logout', 'soj_cas_logout');
?>