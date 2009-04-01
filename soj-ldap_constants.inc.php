<?php
	// CAS constants - DEFINE THESE AS YOU NEED
	DEFINE('CAS_URI', '');
	DEFINE('CAS_LOGIN', '');
	DEFINE('CAS_LOGOUT', '');
	DEFINE('CAS_APP_CODE', '');
	DEFINE('CAS_AUTHENTIC', 'yes');
	DEFINE('CAS_NOT_AUTHENTIC', 'no');
	DEFINE('CAS_DEFAULT_PASSWORD', ''); // Used in auto-account creation, make it something difficult

	// LDAP constants
	DEFINE('LDAP_HOST', ''); // IP address
	DEFINE('LDAP_PORT', NULL);
	DEFINE('LDAP_BASEDN', '');
	DEFINE('TECH_STAFF_GROUP', '');	// This group will always have admin privileges in WordPress
?>