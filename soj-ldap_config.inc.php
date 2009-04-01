<?php
	require_once 'soj-ldap_constants.inc.php';

	/**
	 * LDAP Member object
	 */
	class LDAPUser
	{
		private $data = NULL;

		function __construct($member_array)
		{
			$this->data = $member_array;
		}

		function get_user_name()
		{
			if(isset($this->data[0]['cn'][0]))
				return $this->data[0]['cn'][0];
			else
				return FALSE;
		}
	}

	/**
	 * Function to get and return member attributes
	 *
	 * @param {string} $uid The user's login name
	 * @return An object containing the member information and accessors
	 * @type {object}
	 */
	function get_ldap_user($uid)
	{
		$ds = ldap_connect(LDAP_HOST,LDAP_PORT);

		//Can't connect to LDAP.
		if(!$ds)
		{
			$error = 'Error in contacting the LDAP server.';
		}
		else
		{		
			// Make sure the protocol is set to version 3
			if(!ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3))
			{
				$error = 'Failed to set protocol version to 3.';
			}
			else
			{
				//Connection made -- bind anonymously and get dn for username.
				$bind = @ldap_bind($ds);
				
				//Check to make sure we're bound.
				if(!$bind)
				{
					$error = 'Anonymous bind to LDAP failed.';
				}
				else
				{
					$search = ldap_search($ds, LDAP_BASEDN, "(uid=$uid)");
					$info = ldap_get_entries($ds, $search);
	
					ldap_close($ds);
					return new LDAPUser($info);
				}
				ldap_close($ds);
			}
		}
		return FALSE;
	}

	/**
	 * Return an array containing all the groups, where the array key is the
	 * group number and the array value is an array containing cn and
	 * apple-group-realname. If the function fails, an empty array is returned.
	 */
	function get_groups()
	{
		$ds = ldap_connect(LDAP_HOST,LDAP_PORT);
	
		//Can't connect to LDAP.
		if(!$ds)
		{
			$error = 'Error in contacting the LDAP server.';
		}
		else
		{
			// Make sure the protocol is set to version 3
			if(!ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3))
			{
				$error = 'Failed to set protocol version to 3.';
			}
			else
			{
				//Connection made -- bind anonymously and get dn for username.
				$bind = @ldap_bind($ds);
				
				//Check to make sure we're bound.
				if(!$bind)
				{
					$error = 'Anonymous bind to LDAP failed.';
				}
				else
				{			
					$search = ldap_search($ds, LDAP_BASEDN, "(objectClass=apple-group)");
					$info = ldap_get_entries($ds, $search);
	
					$groups = array();
					for($i=0; $i<$info['count']; $i++)
						$groups[intval($info[$i]['gidnumber'][0])] = $info[$i]['cn'][0];
	
					ldap_close($ds);
					ksort($groups);
					return $groups;
				}
				ldap_close($ds);
			}
		}
		return array();
	}

	/**
	 * Return an array containing all the groups $uid is a member of, where the
	 * array key is the group number and the array value is the group name. If
	 * the function fails, an empty array is returned.
	 *
	 * @param {string} $uid The users login name.
	 */
	function get_group_membership($uid='')
	{
		if(empty($uid)) return array();
		$uid .= '';

		$ds = ldap_connect(LDAP_HOST,LDAP_PORT);

		//Can't connect to LDAP.
		if(!$ds)
		{
			$error = 'Error in contacting the LDAP server.';
		}
		else
		{		
			// Make sure the protocol is set to version 3
			if(!ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3))
			{
				$error = 'Failed to set protocol version to 3.';
			}
			else
			{
				//Connection made -- bind anonymously and get dn for username.
				$bind = @ldap_bind($ds);
				
				//Check to make sure we're bound.
				if(!$bind)
				{
					$error = 'Anonymous bind to LDAP failed.';
				}
				else
				{
					// Add non-primary groups
					$search = ldap_search($ds, LDAP_BASEDN, "(&(memberuid=$uid)(objectClass=apple-group))");
					$info = ldap_get_entries($ds, $search);
					$groups = array();
					for($i=0; $i<$info['count']; $i++)
						$groups[intval($info[$i]['gidnumber'][0])] = $info[$i]['cn'][0];
	
					// Add primary groups
					$group_names = get_groups();
					$search = ldap_search($ds, LDAP_BASEDN, "(&(objectClass=inetOrgPerson)(uid=$uid))");
					$info = ldap_get_entries($ds, $search);
					if(isset($info[0]) && isset($info[0]['gidnumber']) && isset($info[0]['gidnumber'][0]))
						$groups[$info[0]['gidnumber'][0]] = $group_names[$info[0]['gidnumber'][0]];
	
					ldap_close($ds);
					ksort($groups);
					return $groups;
				}
				ldap_close($ds);
			}
		}
		return array();
	}

	/**
	 * Do a CAS authentication, return:
	 *
		array(
			'username' => string,
			'access' => CAS_AUTHENTIC | !CAS_AUTHENTIC,
			'authentic' => AUTHENTICATED | NOT_AUTHENTICATED
		);
	 *
	 * @param {string} uri The address you direct to upon authentication
	 */
	function cas_authenticate($uri=FALSE)
	{
		// Make sure a redirect URI was passed
		if($uri===FALSE)
		{
			header('Content-type: text/plain');
			die('No CAS URI passed.');
		}
		
		// If they haven't previously authenticated, make sure they do
		$casticket = isset($_GET['casticket']) ? $_GET['casticket'] : FALSE;
		if($casticket===FALSE)
		{
			header('Location: '.CAS_LOGIN.'?cassvc='.CAS_APP_CODE.'&casurl='.$uri);
			die();
		}

		// Get the CAS response
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, CAS_URI.'?cassvc='.CAS_APP_CODE.'&casticket='.urlencode($_GET['casticket']).'&casurl='.urlencode($uri));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		$result = curl_exec($ch);
		curl_close($ch);

		$pieces = explode("\n",$result);
	
		/**
		 * CAS sends a response on 2 lines:
		 * First line contains "yes" or "no."
		 * If "yes," second line contains username (otherwise, it is empty).
		 */
		$access = trim($pieces[0]);
		$username = trim($pieces[1]);
		
		// Regiser user as CAS verified
		$authentic = strcmp($access,CAS_AUTHENTIC)==0 ? AUTHENTICATED : NOT_AUTHENTICATED;

		return array(
			'username' => $username,
			'access' => $access,
			'authentic' => $authentic
		);
	}

	/**
	 * Default settings for SoJ-LDAP plugin
	 */
	// Admin account details
	$default_soj_admin_config['soj_force_ldap'] = '1';
	$default_soj_admin_config['soj_user_autocreate'] = '1';
   	$default_soj_admin_config['soj_lock_password'] = '1';

	// Make sure at least the default options are present (this does not affect existing values)
	add_option('soj-ldap',$default_soj_admin_config);
	add_option('soj-ldap-pass',$default_soj_admin_config['soj_admin_pass']);

	/**
	 * Get settings from WordPress DB
	 */
	$soj_admin_config = get_option('soj-ldap');
	$soj_admin_config['soj_admin_pass'] = get_option('soj-ldap-pass');

	/**
	 * Set Admin account
	 */
	$soj_admin_config['soj_admin_group'] = TECH_STAFF_GROUP; // technicalstaff on Brady
	$soj_admin_config['soj_admin_role'] = 'Administrator';
?>