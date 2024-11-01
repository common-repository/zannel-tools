=== Zannel Tools ===
Tags: zannel, update, integration, post, digest, notify, integrate, archive, widget
Contributors: alexkingorg
Requires at least: 2.5
Tested up to: 2.7.1
Stable tag: 1.0

A complete integration between your WordPress blog and Zannel. Bring your Zannel Updates into your blog and pass your blog posts back to Zannel.

== Details ==

Zannel Tools integrates with Zannel by giving you the following functionality:

* Archive your Zannel updates (downloaded every 30 minutes)
* Create a blog post from each of your updates
* Create a daily or weekly digest post of your updates
* Create an update on Zannel whenever you post in your blog, with a link back to the blog post
* Post an update from your sidebar
* Post an update from the WP Admin screens
* Pass your updates along to another service (via API hook)


== Installation ==

1. Download the plugin archive and expand it (you've likely already done this).
2. Put the 'zannel-tools.php' file into your wp-content/plugins/ directory.
3. Go to the Plugins page in your WordPress Administration area and click 'Activate' for Zannel Tools.
4. Go to the Zannel Tools Options page (Settings > Zannel Tools) to set your Zannel account information and preferences.


== Configuration ==

There are a number of configuration options for Zannel Tools. You can find these in Settings > Zannel Tools.

== Showing Your Updates ==

= Widget Friendly =

If you are using widgets, you can drag Zannel Tools to your sidebar to display your latest updates.


= Template Tags =

If you are not using widgest, you can use a template tag to add your latest updates to your sidebar.

`<?php cfzt_sidebar_zupdates(); ?>`


If you just want your latest update, use this template tag.

`<?php cfzt_latest_zupdate(); ?>`


== Hooks/API ==

Zannel Tools contains a hook that can be used to pass along your Zannel update data to another service (for example, some folks have wanted to be able to update their Facebook status). To use this hook, create a plugin and add an action to:

`cfzt_add_zupdate`

Your plugin function will receive an `cfzt_zupdate` object as the first parameter.

Example psuedo-code:

`function my_status_update($update) { // do something here }`
`add_action('cfzt_add_zupdate', 'my_status_update')`


== Known Issues ==

* Only one Zannel account is supported (not one account per author).
* Updates are not deleted from the update table in your WordPress database when they are deleted from Zannel. To delete from your WordPress database, use a database admin tool like phpMyAdmin.
* The relative date function isn't fully localized.


== Frequently Asked Questions ==

= What happens if I have both my updates posting to my blog as posts and my posts sent to Zannel? Will it cause the world to end in a spinning fireball of death? = 

Actually, Zannel Tools has taken this into account and you can safely enable both creating posts from your updates and updates from your posts without duplicating them in either place.

= Does Zannel Tools use a URL shortening service? =

No, Zannel Tools sends your long URL to Zannel and Zannel chooses to shorten it or not.

= Is there any way to change the prefix of my blog posts that get updated? =

Yes there is.  The change is made in the code of the plugin, just look for and modify the following line: 
$this->zupdate_prefix = 'New blog post';

The reason this is done this way, and not as an easily changeable option from the admin screen, is so that the plugin correctly ignores the updates that originated from previous blog posts when creating the digest posts, displaying the latest update, and displaying sidebar updates.
