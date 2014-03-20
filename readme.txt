=== PMPro Infusionsoft ===
Contributors: strangerstudios
Tags: pmpro, paid memberships pro, infusionsoft, email marketing
Requires at least: 3.4
Tested up to: 3.8.1
Stable tag: 1.1

Sync your WordPress users and members with Infusionsoft groups and tags.

If Paid Memberships Pro is installed you can sync users by membership level, otherwise all users can be synced to one or more groups.

== Description ==

Sync your WordPress users and members with Infusionsoft groups and tags.

If Paid Memberships Pro is installed you can sync users by membership level, otherwise all users can be synced to one or more groups.


== Installation ==

1. Upload the `pmpro-infusionsoft` directory to the `/wp-content/plugins/` directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. The settings page is at Settings --> PMPro Infusionsoft in the WP dashboard.

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. https://github.com/strangerstudios/pmpro-infusionsoft/issues

= I need help installing, configuring, or customizing the plugin. =

Please visit our premium support site at http://www.paidmembershipspro.com for more documentation and our support forums.

== Changelog ==
= 1.1 =
* Fixed issue with updating contact info if the user changed their email address. (Thanks, Matt Cherry)

= 1.0 =
* Released to the WordPress repository.

= .6 =
* Now getting tags from Infusionsoft and showing them in a multiselect box on the options page.
* When a user changes levels or cancels, the plugin will now remove tags based on the level they are changing from.

= .5 =
* Updates contact information in Infusionsoft if it is updated on the WP side. (Thanks, Matt Cherry.)

= .4.1 =
* Fixed dup check that was keeping contacts from being added.
* Now updating user info if someone checks out again. At checkout a contact with no name/etc is added, then when pmpro_after_checkout fires, the contact is updated with the full user data.

= .4 =
* Added extra call to update contact at checkout to give PMPro time to update user meta.
* Added pmpro_infusionsoft_addcon_fields filter to update additional fields when updating contacts.
* Fixed bug in preg_replace for duplicate check.

= .3 =
* Including the xmlrpc.inc file from Infusionsoft for older PHP versions.
* The Infusionsoft username/id is now a settings field vs being hard coded into the plugin files.
* Removed the krumo require.

= .2 =
* Added readme. This version is actually working and pushing users to Infusionsoft.