=== Plugin Name ===
Contributors: sojweb
Tags: cas, ldap
Requires at least: 2.0
Tested up to: 2.7.1
Stable tag: 1.0

This plugin allows users to be authenticated via CAS, and logins to be controlled via LDAP.

== Description ==

**THIS IS FOR DEVELOPMENT PURPOSES, NOT FOR USE ON PRODUCTION SITES**

This plugin is meant to be a reference; I've talked to several people who have similar setups, and thought it would beneficial to share some code.

This plugin allows users to be authenticated via CAS, and logins to be controlled via LDAP. Default user roles can be assigned based on LDAP group membership, and accounts can be auto-created for users that exist in LDAP, but not yet in WordPress.

It assumes that, for CAS authentication, the user is redirected to an outside address, then back to the referring address; that's how it works where I'm at :-). You can also specify a group in LDAP that will always have admin privileges; useful for tech staff.

Before the plugin is used, the constants in `soj-ldap_constants.inc.php` must be filled out. The CAS default password is the one the user is registered with in WordPress; so all WP users will have this password. Be aware of that. There is obviously more that could be done there, and I'm happy to work with others who have ideas.

I've adapted this from an in-house plugin I made, so there might be leftover code bits or things that look strange... feel free to harass me about that, I did this in a bit of a rush.

I've only ever used this plugin on one environment, so it might not work out-of-the-box for you. I'm interested in making this plugin more robust, however, so please let me know of any issues.

Contact me: jj56@indiana.edu

== Installation ==

1. Edit `soj-ldap_constants.inc.php` to contain the appropriate values.
1. Upload the folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==

Nothing here yet.

== Screenshots ==

Nothing here yet.