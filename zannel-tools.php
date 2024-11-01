<?php
/*
Plugin Name: Zannel Tools
Plugin URI: http://zannel.com
Description: A complete integration between your WordPress blog and <a href="http://zannel.com">Zannel</a>. Bring your Zannel Updates into your blog and pass your blog posts back to Zannel.
Version: 1.0
Author: Crowd Favorite
Author URI: http://crowdfavorite.com

Template Tags:
Latest update: <?php cfzt_latest_zupdate(); ?>
List of updates: <?php cfzt_sidebar_zupdates(); ?>
*/

// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
//
// This is an add-on for WordPress
// http://wordpress.org/
//
// Based on Twitter Tools by Crowd Favorite, Ltd.
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. 
// **********************************************************************

load_plugin_textdomain('zannel-tools');

if (!defined('PLUGINDIR')) {
	define('PLUGINDIR','wp-content/plugins');
}

if (is_file(trailingslashit(ABSPATH.PLUGINDIR).'zannel-tools.php')) {
	define('CFZT_FILE', trailingslashit(ABSPATH.PLUGINDIR).'zannel-tools.php');
}
else if (is_file(trailingslashit(ABSPATH.PLUGINDIR).'zannel-tools/zannel-tools.php')) {
	define('CFZT_FILE', trailingslashit(ABSPATH.PLUGINDIR).'zannel-tools/zannel-tools.php');
}

if (!function_exists('is_admin_page')) {
	function is_admin_page() {
		if (function_exists('is_admin')) {
			return is_admin();
		}
		if (function_exists('check_admin_referer')) {
			return true;
		}
		else {
			return false;
		}
	}
}

if (!function_exists('wp_prototype_before_jquery')) {
	function wp_prototype_before_jquery( $js_array ) {
		if ( false === $jquery = array_search( 'jquery', $js_array ) )
			return $js_array;
	
		if ( false === $prototype = array_search( 'prototype', $js_array ) )
			return $js_array;
	
		if ( $prototype < $jquery )
			return $js_array;
	
		unset($js_array[$prototype]);
	
		array_splice( $js_array, $jquery, 0, 'prototype' );
	
		return $js_array;
	}
	
	add_filter( 'print_scripts_array', 'wp_prototype_before_jquery' );
}

define('CFZT_API_POST_UPDATE', 'http://app.zannel.com/api/update.json');
define('CFZT_API_GET_UPDATES', 'http://app.zannel.com/api/user/###USERNAME###/updates.json');
define('CFZT_API_COMMENT_URL', 'http://app.zannel.com/api/update/###ZUPDATEHASH###/comments.json');
define('CFZT_PROFILE_URL', 'http://zannel.com/###USERNAME###');
define('CFZT_TEST_LOGIN_URL', 'http://app.zannel.com/api/user/###USERNAME###.json');

function cfzt_install() {
	global $wpdb;

	$cfzt_install = new zannel_tools;
	$wpdb->cfzt = $wpdb->prefix.'cfzt_zannel';
	$charset_collate = '';
	if ( version_compare(mysql_get_server_info(), '4.1.0', '>=') ) {
		if (!empty($wpdb->charset)) {
			$charset_collate .= " DEFAULT CHARACTER SET $wpdb->charset";
		}
		if (!empty($wpdb->collate)) {
			$charset_collate .= " COLLATE $wpdb->collate";
		}
	}
	$result = $wpdb->query("
		CREATE TABLE `$wpdb->cfzt` (
		`id` INT( 11 ) NOT NULL AUTO_INCREMENT PRIMARY KEY ,
		`zupdate_hash` VARCHAR( 255 ) NOT NULL ,
		`zupdate_type` VARCHAR( 255 ) NOT NULL ,
		`zupdate_tags` VARCHAR( 255 ) NOT NULL ,
		`zupdate_timestamp` VARCHAR( 255 ) NOT NULL ,
		`zupdate_description` VARCHAR( 255 ) NOT NULL ,
		`zupdate_user` VARCHAR( 255 ) NOT NULL ,
		`zupdate_media` VARCHAR( 500 ) ,
		`zupdate_embed_code` VARCHAR ( 500 ) ,		
		`zupdate_url` VARCHAR( 255 ) NOT NULL ,
		`modified` DATETIME NOT NULL ,
		INDEX ( `zupdate_hash` )
		) $charset_collate
	");
	foreach ($cfzt_install->options as $option) {
		add_option('cfzt_'.$option, $cfzt_install->$option);
	}
	add_option('cfzt_zupdate_hash', '');
}
register_activation_hook(CFZT_FILE, 'cfzt_install');

class zannel_tools {
	function zannel_tools() {
		$this->options = array(
			'zannel_username'
			, 'zannel_password'
			, 'create_blog_posts'
			, 'default_post_title'
			, 'create_digest'
			, 'create_digest_weekly'
			, 'digest_daily_time'
			, 'digest_weekly_time'
			, 'digest_weekly_day'
			, 'digest_title'
			, 'digest_title_weekly'
			, 'blog_post_author'
			, 'blog_post_category'
			, 'blog_post_tags'
			, 'notify_zannel'
			, 'sidebar_zupdate_count'
			, 'zupdate_from_sidebar'
			, 'give_cfzt_credit'
			, 'exclude_reply_zupdates'
			, 'last_zupdate_download'
			, 'doing_zupdate_download'
			, 'doing_digest_post'
			, 'install_date'
			, 'js_lib'
			, 'digest_zupdate_order'
			, 'notify_zannel_default'
		);
		$this->zannel_username = '';
		$this->zannel_password = '';
		$this->create_blog_posts = '0';
		$this->default_post_title = 'New Update from Zannel!';
		$this->create_digest = '0';
		$this->create_digest_weekly = '0';
		$this->digest_daily_time = null;
		$this->digest_weekly_time = null;
		$this->digest_weekly_day = null;
		$this->digest_title = __("Zannel Updates for %s", 'zannel-tools');
		$this->digest_title_weekly = __("Zannel Weekly Updates for %s", 'zannel-tools');
		$this->blog_post_author = '1';
		$this->blog_post_category = '1';
		$this->blog_post_tags = '';
		$this->notify_zannel = '0';
		$this->notify_zannel_default = '0';
		$this->sidebar_zupdate_count = '3';
		$this->zupdate_from_sidebar = '1';
		$this->give_cfzt_credit = '1';
		$this->exclude_reply_zupdates = '0';
		$this->install_date = '';
		$this->js_lib = 'jquery';
		$this->digest_zupdate_order = 'ASC';
		// not included in options
		$this->update_hash = '';
		$this->zupdate_prefix = 'New blog post';
		$this->zupdate_format = $this->zupdate_prefix.': %s %s';
		$this->last_digest_post = '';
		$this->last_zupdate_download = '';
		$this->doing_zupdate_download = '0';
		$this->doing_digest_post = '0';
		$this->version = '1.0';
	}
	
	function get_settings() {
		foreach ($this->options as $option) {
			$this->$option = get_option('cfzt_'.$option);
		}
	}
	
	// puts post fields into object propps
	function populate_settings() {
		foreach ($this->options as $option) {
			if (isset($_POST['cfzt_'.$option])) {
				$this->$option = stripslashes($_POST['cfzt_'.$option]);
			}
		}
	}
	
	// puts object props into wp option storage
	function update_settings() {
		if (current_user_can('manage_options')) {
			$this->sidebar_zupdate_count = intval($this->sidebar_zupdate_count);
			if ($this->sidebar_zupdate_count == 0) {
				$this->sidebar_zupdate_count = '3';
			}
			foreach ($this->options as $option) {
				update_option('cfzt_'.$option, $this->$option);
			}
			if (empty($this->install_date)) {
				update_option('cfzt_install_date', current_time('mysql'));
			}
			$this->initiate_digests();
		}
	}
	
	// figure out when the next weekly and daily digests will be
	function initiate_digests() {
		$next = ($this->create_digest) ? $this->calculate_next_daily_digest() : null;
		$this->next_daily_digest = $next;
		update_option('cfzt_next_daily_digest', $next);
		
		$next = ($this->create_digest_weekly) ? $this->calculate_next_weekly_digest() : null;
		$this->next_weekly_digest = $next;
		update_option('cfzt_next_weekly_digest', $next);
	}
	
	function calculate_next_daily_digest() {
		$optionDate = strtotime($this->digest_daily_time);
		$hour_offset = date("G", $optionDate);
		$minute_offset = date("i", $optionDate);
		$next = mktime($hour_offset, $minute_offset, 0);
		
		// may have to move to next day
		$now = time();
		while ($next < $now) {
			$next += 60 * 60 * 24;
		}
		return $next;
	}
	
	function calculate_next_weekly_digest() {
		$optionDate = strtotime($this->digest_weekly_time);
		$hour_offset = date("G", $optionDate);
		$minute_offset = date("i", $optionDate);
		
		$current_day_of_month = date("j");
		$current_day_of_week = date("w");
		$current_month = date("n");

		// if this week's day is less than today, go for next week
		$nextDay = $current_day_of_month - $current_day_of_week + $this->digest_weekly_day;
		if ($this->digest_weekly_day <= $current_day_of_week) {
			$nextDay += 7;
		}

		$next = mktime($hour_offset, $minute_offset, 0, $current_month, $nextDay);

		return $next;
	}
	
	function ping_digests() {
		// still busy
		if (get_option('cfzt_doing_digest_post') == '1') {
			return;
		}
		// check all the digest schedules
		if ($this->create_digest == 1) {
			$this->ping_digest('cfzt_next_daily_digest', 'cfzt_last_digest_post', $this->digest_title, (60 * 60 * 24 * 1));
		}
		if ($this->create_digest_weekly == 1) {
			$this->ping_digest('cfzt_next_weekly_digest', 'cfzt_last_digest_post_weekly', $this->digest_title_weekly, (60 * 60 * 24 * 7));
		}
		return;
	}
	
	function ping_digest($nextDateField, $lastDateField, $title, $defaultDuration) {
		$next = get_option($nextDateField);
		
		if ($next) {		
			$next = $this->validateDate($next);
			$rightNow = time();
			if ($rightNow >= $next) {
				$start = get_option($lastDateField);
				$start = $this->validateDate($start, ($rightNow - $defaultDuration));
				if ($this->do_digest_post($start, $next, $title)) {
					update_option($lastDateField, $rightNow);
					update_option($nextDateField, ($next + $defaultDuration));
				} else {
					update_option($lastDateField, null);
				}
			}
		}
	}

	function validateDate($in, $default = 0) {
		if (!is_numeric($in)) {
			// try to convert what they gave us into a date
			$out = strtotime($in);
			// if that doesn't work, return the default
			if (!is_numeric($out)) {
				return $default;
			}
			return $out;	
		}
		return $in;
	}

	function do_digest_post($start, $end, $title) {
		if (!$start || !$end) return false;
		// flag us as busy
		update_option('cfzt_doing_digest_post', '1');
		remove_action('publish_post', 'cfzt_notify_zannel', 99);
		remove_action('publish_post', 'cfzt_store_post_options', 1, 2);
		remove_action('save_post', 'cfzt_store_post_options', 1, 2);
		// see if there's any updates in the time range
		global $wpdb;
		
		$startGMT = gmdate("Y-m-d H:i:s", $start);
		$endGMT = gmdate("Y-m-d H:i:s", $end);
		
		// build sql
		$conditions = array();
		$conditions[] = "zupdate_timestamp >= '{$startGMT}'";
		$conditions[] = "zupdate_timestamp <= '{$endGMT}'";
		$conditions[] = "zupdate_description NOT LIKE '$this->zupdate_prefix%'";
		if ($this->exclude_reply_zupdates) {
			$conditions[] = "zupdate_description NOT LIKE '@%'";
		}
		$where = implode(' AND ', $conditions);
		
		$sql = "
			SELECT * FROM {$wpdb->cfzt}
			WHERE {$where}
			GROUP BY id
			ORDER BY zupdate_timestamp {$this->digest_zupdate_order}
		";

		$zupdates = $wpdb->get_results($sql);

		if (count($zupdates) > 0) {
		
			$zupdates_to_post = array();
			foreach ($zupdates as $data) {
				$zupdate = new cfzt_zupdate;
				$zupdate->description = $data->description;
				$zupdate->cfzt_reply_zupdate = $data->cfzt_reply_zupdate;
				if (!$zupdate->zupdate_is_post_notification() || ($zupdate->zupdate_is_reply() && $this->exclude_reply_zupdates)) {
					$zupdates_to_post[] = $data;
				}
			}

			if (count($zupdates_to_post) > 0) {
				$content = '<ul class="cfzt_zupdate_digest">'."\n";
				foreach ($zupdates_to_post as $zupdate) {
					$content .= '	<li>'.cfzt_zupdate_display($zupdate, 'absolute').'</li>'."\n";
				}
				$content .= '</ul>'."\n";
				if ($this->give_cfzt_credit == '1') {
					$content .= '<p class="cfzt_credit">Powered by <a href="http://zannel.com">Zannel Tools</a>.</p>';
				}

				$post_data = array(
					'post_content' => $wpdb->escape($content),
					'post_title' => $wpdb->escape(sprintf($title, date('Y-m-d'))),
					'post_date' => date('Y-m-d H:i:s', $end),
					'post_category' => array($this->blog_post_category),
					'post_status' => 'publish',
					'post_author' => $wpdb->escape($this->blog_post_author)
				);

				$post_id = wp_insert_post($post_data);

				add_post_meta($post_id, 'cfzt_zupdated', '1', true);
				wp_set_post_tags($post_id, $this->blog_post_tags);
			}

		}
		add_action('publish_post', 'cfzt_notify_zannel', 99);
		add_action('publish_post', 'cfzt_store_post_options', 1, 2);
		add_action('save_post', 'cfzt_store_post_options', 1, 2);
		update_option('cfzt_doing_digest_post', '0');
		return true;
	}
	
	function zupdate_download_interval() {
		return 1800;
	}
	
	function do_zupdate($zupdate = '') {
		if (empty($this->zannel_username) 
			|| empty($this->zannel_password) 
			|| empty($zupdate)
			|| empty($zupdate->zupdate_description)
		) {
			return;
		}
		require_once(ABSPATH.WPINC.'/class-snoopy.php');
		$snoop = new Snoopy;
		$snoop->agent = 'Zannel Tools http://zannel.com';
		$snoop->rawheaders = array(
			'X-Zannel-Client' => 'Zannel Tools'
			, 'X-Zannel-Client-Version' => $this->version
			, 'X-Zannel-Client-URL' => 'http://zannel.com'
		);
		$snoop->set_submit_multipart();
		$data_arguments = array(
				'authuser' => $this->zannel_username,
				'authpass' => $this->zannel_password,
				'description' => $zupdate->zupdate_description
			);
		if ($zupdate->media) {
			$snoop->submit(
				CFZT_API_POST_UPDATE
				, $data_arguments
				, $zupdate->media
			);			
		}
		else {
			$snoop->submit(
				CFZT_API_POST_UPDATE
				, $data_arguments
			);
		}
		if (strpos($snoop->response_code, '200')) {
			update_option('cfzt_last_zupdate_download', strtotime('-28 minutes'));
			return true;
		}
		return false;
	}
	
	function do_blog_post_zupdate($post_id = 0) {
		if ($this->notify_zannel == '0'
			|| $post_id == 0
			|| get_post_meta($post_id, 'cfzt_zupdated', true) == '1'
			|| get_post_meta($post_id, 'cfzt_notify_zannel', true) == 'no'
		) {
			return;
		}
		$post = get_post($post_id);
		// check for an edited post before TT was installed
		if ($post->post_date <= $this->install_date) {
			return;
		}
		// check for private posts
		if ($post->post_status != 'publish') {
			return;
		}
		$zupdate = new cfzt_zupdate;
		$url = apply_filters('zupdate_blog_post_url', get_permalink($post_id));
		$zupdate->zupdate_description = sprintf(__($this->zupdate_format, 'zannel-tools'), $post->post_title, $url);
		$zupdate->media = $this->get_post_media($post_id);
		
		$this->do_zupdate($zupdate);
		add_post_meta($post_id, 'cfzt_zupdated', '1', true);
	}
	function get_post_media($post_id) {
		global $wpdb;
		$sql = '
			SELECT ID
			FROM '.$wpdb->posts.'
			WHERE post_type="attachment"
			AND post_parent='.$post_id.'
		';
		$attachment_ids = $wpdb->get_results($sql);
		if (!$attachment_ids) {
			return false;
		}
		$path_to_uploads = wp_upload_dir();
		$path_to_media = trailingslashit($path_to_uploads['basedir']);
		$biggest_file = '';
		$biggest_file_size = '';
		foreach ($attachment_ids as $attachment) {
			$media = get_post_meta($attachment->ID,'_wp_attached_file',true);
			if (!empty($media)) {
				if (version_compare(get_bloginfo('version'),'2.6.5','>')) {
					// 2.7 gives us a relative path, while 2.6.5 and 2.5.1 give us a full path
					$media = $path_to_media.$media;
				}
				$file_size = filesize($media);
				if ($file_size >= $biggest_file_size) {
					$biggest_file = $media;
					$biggest_file_size = $file_size;
				}
			}					
		}
		return $biggest_file;
	}
	function do_zupdate_post($zupdate) {
		global $wpdb;
		remove_action('publish_post', 'cfzt_notify_zannel', 99);
		remove_action('publish_post', 'cfzt_store_post_options', 1, 2);
		remove_action('save_post', 'cfzt_store_post_options', 1, 2);
		// if post type is video, then grab embed code
		if ($zupdate->type == "VIDEO") {
			$post_content = $zupdate->description.'<p>'.$zupdate->embed_code.'</p>';
		}
		else if ($zupdate->type == "IMAGE") {
			$media = unserialize($zupdate->media);
			$post_content = $zupdate->description.'<p><img src="'.$media->large.'" /></p>';
		}
		else {
			$post_content = $zupdate->description;
		}
		if (empty($zupdate->description)) {
			// we need a post title, so if it's empty, use the default one.
			$post_title = get_option('cfzt_default_post_title');
		}
		if ($this->exclude_reply_zupdates && ereg('^@',$zupdate->description)) {
			// don't create post if is reply and exclude replies is set
			return;
		}
		else {
			$post_title = $zupdate->description;
		}
		$data = array(
			'post_content' => $wpdb->escape(cfzt_make_clickable($post_content))
			, 'post_title' => $wpdb->escape(trim_add_elipsis($post_title, 30))
			, 'post_date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($zupdate->timestamp)))
			, 'post_category' => array($this->blog_post_category)
			, 'post_status' => 'publish'
			, 'post_author' => $wpdb->escape($this->blog_post_author)
		);
		$post_id = wp_insert_post($data);
		update_post_meta($post_id, 'cfzt_notify_zannel', 'no', true);
		add_post_meta($post_id, 'cfzt_zupdate_id', $zupdate->hashcode, true);
		wp_set_post_tags($post_id, $this->blog_post_tags);
		add_action('publish_post', 'cfzt_notify_zannel', 99);
		add_action('publish_post', 'cfzt_store_post_options', 1, 2);
		add_action('save_post', 'cfzt_store_post_options', 1, 2);
	}
}

class cfzt_zupdate {
	function cfzt_zupdate(
		$hashcode = '', 
		$type = '', 
		$tags = '', 
		$timestamp = '', 
		$description = '', 
		$user = '', 
		$media = '', 
		$embed_code = '', 
		$url = ''
	){
		$this->id = '';
		$this->hashcode = $hashcode;
		$this->type = $type;
		$this->tags = $tags;
		$this->timestamp = $timestamp;
		$this->description = $description;
		$this->user = $user;
		$this->media = $media;
		$this->embed_code = $embed_code;
		$this->url = $url;
		$this->modified = '';
	}
	
	function cfztdate_to_time($date) {
		$parts = explode(' ', $date);
		$date = strtotime($parts[1].' '.$parts[2].', '.$parts[5].' '.$parts[3]);
		return $date;
	}
	
	function zupdate_post_exists() {
		global $wpdb;
		$test = $wpdb->get_results("
			SELECT *
			FROM $wpdb->postmeta
			WHERE meta_key = 'cfzt_zupdate_id'
			AND meta_value = '".$wpdb->escape($this->hashcode)."'
		");
		if (count($test) > 0) {
			return true;
		}
		return false;
	}
	
	function zupdate_is_post_notification() {
		global $cfzt;
		if (substr($this->description, 0, strlen($cfzt->zupdate_prefix)) == $cfzt->zupdate_prefix) {
			return true;
		}
		return false;
	}
	
	function zupdate_is_reply() {
		return !empty($this->cfzt_reply_zupdate);
	}
	
	function add() {
		global $wpdb, $cfzt;
		$wpdb->query("
			INSERT
			INTO $wpdb->cfzt
			( id
			, zupdate_hash
			, zupdate_type
			, zupdate_tags
			, zupdate_timestamp
			, zupdate_description
			, zupdate_user
			, zupdate_media
			, zupdate_embed_code
			, zupdate_url
			, modified
			)
			VALUES
			( '".$wpdb->escape($this->id)."'
			, '".$wpdb->escape($this->hashcode)."'
			, '".$wpdb->escape($this->type)."'
			, '".$wpdb->escape($this->tags)."'
			, '".date('Y-m-d H:i:s', strtotime($this->timestamp))."'
			, '".$wpdb->escape($this->description)."'
			, '".$wpdb->escape($this->user)."'
			, '".$wpdb->escape($this->media)."'
			, '".$wpdb->escape($this->embed_code)."'
			, '".$wpdb->escape($this->url)."'
			, NOW()
			)
		");
		do_action('cfzt_add_zupdate', $this);

		if ($cfzt->create_blog_posts == '1' && !$this->zupdate_post_exists() && !$this->zupdate_is_post_notification()) {
			$cfzt->do_zupdate_post($this);
		}
	}
}

function cfzt_api_status_show_url($id) {
	return str_replace('###ID###', $id, CFZT_API_STATUS_SHOW);
}

function cfzt_profile_url($username) {
	return str_replace('###USERNAME###', $username, CFZT_PROFILE_URL);
}

function cfzt_profile_link($username, $prefix = '', $suffix = '') {
	return $prefix.'<a href="'.cfzt_profile_url($username).'">'.$username.'</a>'.$suffix;
}

function cfzt_hashtag_url($hashtag) {
	$hashtag = urlencode('#'.$hashtag);
	return str_replace('###HASHTAG###', $hashtag, CFZT_HASHTAG_URL);
}

function cfzt_hashtag_link($hashtag, $prefix = '', $suffix = '') {
	return $prefix.'<a href="'.cfzt_hashtag_url($hashtag).'">'.htmlspecialchars($hashtag).'</a>'.$suffix;
}

function cfzt_status_url($username, $status) {
	return str_replace(
		array(
			'###USERNAME###'
			, '###STATUS###'
		)
		, array(
			$username
			, $status
		)
		, CFZT_STATUS_URL
	);
}

function cfzt_login_test($username, $password) {
	require_once(ABSPATH.WPINC.'/class-snoopy.php');
	$snoop = new Snoopy;
	$snoop->agent = 'Zannel Tools http://zannel.com';
	$snoop->user = $username;
	$snoop->pass = $password;
	$arguments = array(
		'zannel_login_test' => 'test'
	);
	$snoop->submit(str_replace('###USERNAME###',$username,CFZT_TEST_LOGIN_URL),$arguments);
	if (strpos($snoop->response_code, '200')) {
		return __("Login succeeded, you're good to go.", 'zannel-tools');
	} else {
		return print(__('Sorry, login failed. Please check your username and password', 'zannel-tools'));
	}
}


function cfzt_ping_digests() {
	global $cfzt;
	$cfzt->ping_digests();
}

function cfzt_update_zupdates() {
	// let the last update run for 10 minutes
	if (time() - intval(get_option('cfzt_doing_zupdate_download')) < 600) {
		return;
	}
	// wait 10 min between downloads
	if (time() - intval(get_option('cfzt_last_zupdate_download')) < 600) {
		return;
	}
	update_option('cfzt_doing_zupdate_download', time());
	global $wpdb, $cfzt;
	if (empty($cfzt->zannel_username) || empty($cfzt->zannel_password)) {
		update_option('cfzt_doing_zupdate_download', '0');
		return;
	}
	require_once(ABSPATH.WPINC.'/class-snoopy.php');
	$snoop = new Snoopy;
	$snoop->agent = 'Zannel Tools http://zannel.com';
	$snoop->user = $cfzt->zannel_username;
	$snoop->pass = $cfzt->zannel_password;
	$url = str_replace('###USERNAME###',$cfzt->zannel_username,CFZT_API_GET_UPDATES);
	$snoop->fetch($url);
	if (!strpos($snoop->response_code, '200')) {
		update_option('cfzt_doing_zupdate_download', '0');
		return;
	}
	$data = $snoop->results;
	// hash results to see if they're any different than the last update, if so, return
	$hash = md5($data);
	if ($hash == get_option('cfzt_zupdate_hash')) {
		update_option('cfzt_last_zupdate_download', time());
		update_option('cfzt_doing_zupdate_download', '0');
		return;
	}
	$json = new Services_JSON();
	$zupdates = $json->decode($data);
	$zupdates = $zupdates->updates;

	if (is_array($zupdates) && count($zupdates) > 0) {
		$zupdate_ids = array();
		foreach ($zupdates as $zupdate) {
			$zupdate_ids[] = $wpdb->escape($zupdate->hashcode);
		}
		$existing_ids = $wpdb->get_col("
			SELECT zupdate_hash
			FROM $wpdb->cfzt
			WHERE zupdate_hash
			IN ('".implode("', '", $zupdate_ids)."')
		");
		$new_zupdates = array();
		foreach ($zupdates as $zupdate_data) {
			if (!empty($zupdate_data->media)) {
				$zupdate_data->media = serialize($zupdate_data->media);
			}
			else {
				$zupdate_data->media = "";
			}
			if (!$existing_ids || !in_array($zupdate_data->hashcode, $existing_ids)) {
				$zupdate = new cfzt_zupdate(
					$zupdate_data->hashcode
					, $zupdate_data->type
					, $zupdate_data->tags
					, $zupdate_data->timestamp
					, $zupdate_data->description
					, $zupdate_data->user->name
					, $zupdate_data->media
					, $zupdate_data->embedCode
					, $zupdate_data->zannelurl
				);
				// make sure we haven't downloaded someone else's updates - happens sometimes due to Zannel hiccups
				if (strtolower($zupdate_data->user->name) == strtolower($cfzt->zannel_username)) {
					$new_zupdates[] = $zupdate;
				}
			}
		}
		foreach ($new_zupdates as $zupdate) {
			$zupdate->add();
		}
	}
	cfzt_reset_zupdate_checking($hash, time());
}

function cfzt_reset_zupdate_checking($hash = '', $time = 0) {
	if (!current_user_can('manage_options')) {
		return;
	}
	update_option('cfzt_zupdate_hash', $hash);
	update_option('cfzt_last_zupdate_download', $time);
	update_option('cfzt_doing_zupdate_download', '0');
}

function cfzt_notify_zannel($post_id) {
	global $cfzt;
	$cfzt->do_blog_post_zupdate($post_id);
}
add_action('publish_post', 'cfzt_notify_zannel', 99);

function cfzt_sidebar_zupdates() {
	global $wpdb, $cfzt;
	if ($cfzt->exclude_reply_zupdates) {
		$where = "AND zupdate_description NOT LIKE '@%' ";
	}
	else {
		$where = '';
	}
	$zupdates = $wpdb->get_results("
		SELECT *
		FROM $wpdb->cfzt
		WHERE zupdate_description NOT LIKE '$cfzt->zupdate_prefix%'
		$where
		GROUP BY id
		ORDER BY zupdate_timestamp DESC
		LIMIT $cfzt->sidebar_zupdate_count
	");
	$output = '<div class="cfzt_zupdates">'."\n"
		.'	<ul>'."\n";
	if (count($zupdates) > 0) {
		foreach ($zupdates as $zupdate) {
			$output .= '		<li>'.cfzt_zupdate_display($zupdate).'</li>'."\n";
		}
	}
	else {
		$output .= '		<li>'.__('No Updates available at the moment.', 'zannel-tools').'</li>'."\n";
	}
	if (!empty($cfzt->zannel_username)) {
  		$output .= '		<li class="cfzt_more_updates"><a href="'.cfzt_profile_url($cfzt->zannel_username).'">More Updates...</a></li>'."\n";
	}
	$output .= '</ul>';
	if ($cfzt->zupdate_from_sidebar == '1' && !empty($cfzt->zannel_username) && !empty($cfzt->zannel_password)) {
  		$output .= cfzt_zupdate_form('input', 'onsubmit="cfztPostZupdate(); return false;"');
		  $output .= '	<p id="cfzt_zupdate_posted_msg">'.__('Posting Update...', 'zannel-tools').'</p>';
	}
	if ($cfzt->give_cfzt_credit == '1') {
		$output .= '<p class="cfzt_credit">Powered by <a href="http://zannel.com">Zannel Tools</a>.</p>';
	}
	$output .= '</div>';
	print($output);
}

function cfzt_latest_zupdate() {
	global $wpdb, $cfzt;
	$zupdates = $wpdb->get_results("
		SELECT *
		FROM $wpdb->cfzt
		WHERE zupdate_description NOT LIKE '$cfzt->zupdate_prefix%'
		GROUP BY id
		ORDER BY zupdate_timestamp DESC
		LIMIT 1
	");
	if (count($zupdates) == 1) {
		foreach ($zupdates as $zupdate) {
			$output = cfzt_zupdate_display($zupdate);
		}
	}
	else {
		$output = __('No Updates available at the moment.', 'zannel-tools');
	}
	print($output);
}

function cfzt_zupdate_display($zupdate, $time = 'relative') {
	global $cfzt;
	$text = cfzt_make_clickable(wp_specialchars($zupdate->zupdate_description)).' ';
	switch ($time) {
		case 'relative':
			$time_display = cfzt_relativeTime($zupdate->zupdate_timestamp, 3);
			break;
		case 'absolute':
			$time_display = '#';
			break;
	}
	switch (strtolower($zupdate->zupdate_type)) {
		case 'text':
			$output = $text.' <a href="'.$zupdate->zupdate_url.'">'.$time_display.'</a>';
			break;
		case 'image':
			$media = unserialize($zupdate->zupdate_media);
			$output = '<a href="'.$zupdate->zupdate_url.'"><span class="zannel_thumb"><img src="'.$media->medium.'"/></span></a> '.$text.'<a href="'.$zupdate->zupdate_url.'">'.$time_display.'</a>';
			break;
		case 'video':
			$media = unserialize($zupdate->zupdate_media);
			$output = '<a href="'.$zupdate->zupdate_url.'"><span class="zannel_thumb"><img src="'.$media->medium.'"/></span></a> '.$text.'<a href="'.$zupdate->zupdate_url.'">'.$time_display.'</a>';
			break;
	}
	return $output;
}

function cfzt_make_clickable($zupdate) {
	$zupdate .= ' ';
	$zupdate = preg_replace_callback(
			'/@([a-zA-Z0-9_]{1,15})([) ])/'
			, create_function(
				'$matches'
				, 'return cfzt_profile_link($matches[1], \'@\', $matches[2]);'
			)
			, $zupdate
	);
	$zupdate = preg_replace_callback(
		'/\#([a-zA-Z0-9_]{1,15}) /'
		, create_function(
			'$matches'
			, 'return cfzt_hashtag_link($matches[1], \'#\', \' \');'
		)
		, $zupdate
	);
	
	if (function_exists('make_chunky')) {
		return make_chunky($zupdate);
	}
	else {
		return make_clickable($zupdate);
	}
}

function cfzt_zupdate_form($type = 'input', $extra = '') {
	$output = '';
	if (current_user_can('publish_posts')) {
		$output .= '
<form action="'.get_bloginfo('wpurl').'/index.php" method="post" id="cfzt_zupdate_form" '.$extra.'>
	<fieldset>
		';
		switch ($type) {
			case 'input':
				$output .= '
		<p><input type="text" size="20" maxlength="255" id="cfzt_zupdate_text" name="cfzt_zupdate_text" onkeyup="cfztCharCount();" /></p>
		<input type="hidden" name="cfzt_action" value="cfzt_post_zupdate_sidebar" />
		<script type="text/javascript">
		//<![CDATA[
		function cfztCharCount() {
			var count = document.getElementById("cfzt_zupdate_text").value.length;
			if (count > 0) {
				document.getElementById("cfzt_char_count").innerHTML = 255 - count;
			}
			else {
				document.getElementById("cfzt_char_count").innerHTML = "";
			}
		}
		setTimeout("cfztCharCount();", 500);
		document.getElementById("cfzt_zupdate_form").setAttribute("autocomplete", "off");
		//]]>
		</script>
				';
				break;
			case 'textarea':
				$output .= '
		<p><textarea type="text" cols="60" rows="5" maxlength="255" id="cfzt_zupdate_text" name="cfzt_zupdate_text" onkeyup="cfztCharCount();"></textarea></p>
		<input type="hidden" name="cfzt_action" value="cfzt_post_zupdate_admin" />
		<script type="text/javascript">
		//<![CDATA[
		function cfztCharCount() {
			var count = document.getElementById("cfzt_zupdate_text").value.length;
			if (count > 0) {
				document.getElementById("cfzt_char_count").innerHTML = (255 - count) + "'.__(' characters remaining', 'zannel-tools').'";
			}
			else {
				document.getElementById("cfzt_char_count").innerHTML = "";
			}
		}
		setTimeout("cfztCharCount();", 500);
		document.getElementById("cfzt_zupdate_form").setAttribute("autocomplete", "off");
		//]]>
		</script>
				';
				break;
		}
		$output .= '
		<p>
			<input type="submit" id="cfzt_zupdate_submit" name="cfzt_zupdate_submit" value="'.__('Post Update!', 'zannel-tools').'" />
			<span id="cfzt_char_count"></span>
		</p>
		<div class="clear"></div>
	</fieldset>
</form>
		';
	}
	return $output;
}

function cfzt_widget_init() {
	if (!function_exists('register_sidebar_widget')) {
		return;
	}
	function cfzt_widget($args) {
		extract($args);
		$options = get_option('cfzt_widget');
		$title = $options['title'];
		if (empty($title)) {
		}
		echo $before_widget . $before_title . $title . $after_title;
		cfzt_sidebar_zupdates();
		echo $after_widget;
	}
	register_sidebar_widget(array(__('Zannel Tools', 'zannel-tools'), 'widgets'), 'cfzt_widget');
	
	function cfzt_widget_control() {
		$options = get_option('cfzt_widget');
		if (!is_array($options)) {
			$options = array(
				'title' => __("What I'm Doing...", 'zannel-tools')
			);
		}
		if (isset($_POST['cfzt_action']) && $_POST['cfzt_action'] == 'cfzt_update_widget_options') {
			$options['title'] = strip_tags(stripslashes($_POST['cfzt_widget_title']));
			update_option('cfzt_widget', $options);
			// reset checking so that sidebar isn't blank if this is the first time activating
			cfzt_reset_zupdate_checking();
			cfzt_update_zupdates();
		}

		// Be sure you format your options to be valid HTML attributes.
		$title = htmlspecialchars($options['title'], ENT_QUOTES);
		
		// Here is our little form segment. Notice that we don't need a
		// complete form. This will be embedded into the existing form.
		print('
			<p style="text-align:right;"><label for="cfzt_widget_title">' . __('Title:') . ' <input style="width: 200px;" id="cfzt_widget_title" name="cfzt_widget_title" type="text" value="'.$title.'" /></label></p>
			<p>'.__('Find additional Zannel Tools options on the <a href="options-general.php?page=zannel-tools.php">Zannel Tools Options page</a>.', 'zannel-tools').'
			<input type="hidden" id="cfzt_action" name="cfzt_action" value="cfzt_update_widget_options" />
		');
	}
	register_widget_control(array(__('Zannel Tools', 'zannel-tools'), 'widgets'), 'cfzt_widget_control', 300, 100);

}
add_action('widgets_init', 'cfzt_widget_init');

function cfzt_init() {
	global $wpdb, $cfzt;
	$cfzt = new zannel_tools;

	$wpdb->cfzt = $wpdb->prefix.'cfzt_zannel';

	$cfzt->get_settings();
	if (($cfzt->last_zupdate_download + $cfzt->zupdate_download_interval()) < time()) {
		add_action('shutdown', 'cfzt_update_zupdates');
		add_action('shutdown', 'cfzt_ping_digests');
	}
	if (is_admin() || $cfzt->zupdate_from_sidebar) {
		switch ($cfzt->js_lib) {
			case 'jquery':
				wp_enqueue_script('jquery');
				break;
			case 'prototype':
				wp_enqueue_script('prototype');
				break;
		}
	}
}
add_action('init', 'cfzt_init');

function cfzt_head() {
	global $cfzt;
	if ($cfzt->zupdate_from_sidebar) {
		print('
			<link rel="stylesheet" type="text/css" href="'.get_bloginfo('wpurl').'/index.php?cfzt_action=cfzt_css" />
			<script type="text/javascript" src="'.get_bloginfo('wpurl').'/index.php?cfzt_action=cfzt_js"></script>
		');
	}
}
add_action('wp_head', 'cfzt_head');

function cfzt_head_admin() {
	print('
		<link rel="stylesheet" type="text/css" href="'.get_bloginfo('wpurl').'/index.php?cfzt_action=cfzt_css_admin" />
		<script type="text/javascript" src="'.get_bloginfo('wpurl').'/index.php?cfzt_action=cfzt_js_admin"></script>
	');
}
add_action('admin_head', 'cfzt_head_admin');

function cfzt_request_handler() {
	global $wpdb, $cfzt;
	if (!empty($_GET['cfzt_action'])) {
		switch($_GET['cfzt_action']) {
			case 'cfzt_update_zupdates':
				cfzt_update_zupdates();
				wp_redirect(get_bloginfo('wpurl').'/wp-admin/options-general.php?page=zannel-tools.php&zupdates-updated=true');
				die();
				break;
			case 'cfzt_reset_zupdate_checking':
				cfzt_reset_zupdate_checking();
				wp_redirect(get_bloginfo('wpurl').'/wp-admin/options-general.php?page=zannel-tools.php&zupdate-checking-reset=true');
				die();
				break;
			case 'cfzt_js':
				remove_action('shutdown', 'cfzt_ping_digests');
				header("Content-type: text/javascript");
				switch ($cfzt->js_lib) {
					case 'jquery':
?>
function cfztPostZupdate() {
	var zupdate_field = jQuery('#cfzt_zupdate_text');
	var zupdate_text = zupdate_field.val();
	if (zupdate_text == '') {
		return;
	}
	var zupdate_msg = jQuery("#cfzt_zupdate_posted_msg");
	jQuery.post(
		"<?php bloginfo('wpurl'); ?>/index.php"
		, {
			cfzt_action: "cfzt_post_zupdate_sidebar"
			, cfzt_zupdate_text: zupdate_text
		}
		, function(data) {
			zupdate_msg.html(data);
			cfztSetReset();
		}
	);
	zupdate_field.val('').focus();
	jQuery('#cfzt_char_count').html('');
	jQuery("#cfzt_zupdate_posted_msg").show();
}
function cfztSetReset() {
	setTimeout('cfztReset();', 2000);
}
function cfztReset() {
	jQuery('#cfzt_zupdate_posted_msg').hide();
}
<?php
						break;
					case 'prototype':
?>
function cfztPostZupdate() {
	var zupdate_field = $('cfzt_zupdate_text');
	var zupdate_text = zupdate_field.value;
	if (zupdate_text == '') {
		return;
	}
	var zupdate_msg = $("cfzt_zupdate_posted_msg");
	var cfztAjax = new Ajax.Updater(
		zupdate_msg,
		"<?php bloginfo('wpurl'); ?>/index.php",
		{
			method: "post",
			parameters: "cfzt_action=cfzt_post_zupdate_sidebar&cfzt_zupdate_text=" + zupdate_text,
			onComplete: cfztSetReset
		}
	);
	zupdate_field.value = '';
	zupdate_field.focus();
	$('cfzt_char_count').innerHTML = '';
	zupdate_msg.style.display = 'block';
}
function cfztSetReset() {
	setTimeout('cfztReset();', 2000);
}
function cfztReset() {
	$('cfzt_zupdate_posted_msg').style.display = 'none';
}
<?php
						break;
				}
				die();
				break;
			case 'cfzt_css':
				remove_action('shutdown', 'cfzt_ping_digests');
				header("Content-Type: text/css");
?>
#cfzt_zupdate_form {
	margin: 0;
	padding: 5px 0;
}
#cfzt_zupdate_form fieldset {
	border: 0;
}
#cfzt_zupdate_form fieldset #cfzt_zupdate_submit {
	float: right;
	margin-right: 10px;
}
#cfzt_zupdate_form fieldset #cfzt_char_count {
	color: #666;
}
#cfzt_zupdate_posted_msg {
	background: #ffc;
	display: none;
	margin: 0 0 5px 0;
	padding: 5px;
}
#cfzt_zupdate_form div.clear {
	clear: both;
	float: none;
}
<?php
				die();
				break;
			case 'cfzt_js_admin':
				remove_action('shutdown', 'cfzt_ping_digests');			
				header("Content-Type: text/javascript");
				switch ($cfzt->js_lib) {
					case 'jquery':
?>
function cfztTestLogin() {
	var result = jQuery('#cfzt_login_test_result');
	result.show().addClass('cfzt_login_result_wait').html('<?php _e('Testing...', 'zannel-tools'); ?>');
	jQuery.post(
		"<?php bloginfo('wpurl'); ?>/index.php"
		, {
			cfzt_action: "cfzt_login_test"
			, cfzt_zannel_username: jQuery('#cfzt_zannel_username').val()
			, cfzt_zannel_password: jQuery('#cfzt_zannel_password').val()
		}
		, function(data) {
			result.html(data).removeClass('cfzt_login_result_wait');
			setTimeout('cfztTestLoginResult();', 5000);
		}
	);
};

function cfztTestLoginResult() {
	jQuery('#cfzt_login_test_result').fadeOut('slow');
};

(function($){

	jQuery.fn.timepicker = function(){
	
		var hrs = new Array();
		for(var h = 1; h <= 12; hrs.push(h++));

		var mins = new Array();
		for(var m = 0; m < 60; mins.push(m++));

		var ap = new Array('am', 'pm');

		function pad(n) {
			n = n.toString();
			return n.length == 1 ? '0' + n : n;
		}
	
		this.each(function() {
			var v = $(this).val();
			if (!v) v = new Date();

			var d = new Date(v);
			var h = d.getHours();
			var m = d.getMinutes();
			var p = (h >= 12) ? "pm" : "am";
			h = (h > 12) ? h - 12 : h;

			var output = '';

			output += '<select id="h_' + this.id + '" class="timepicker">';				
			for (var hr in hrs){
				output += '<option value="' + pad(hrs[hr]) + '"';
				if(parseInt(hrs[hr],10) == h || (parseInt(hrs[hr],10) == 12 && h == 0)) {
					output += ' selected';
				} 
				output += '>' + pad(hrs[hr]) + '</option>';
			}
			output += '</select>';
	
			output += '<select id="m_' + this.id + '" class="timepicker">';				
			for (var mn in mins){
				output += '<option value="' + pad(mins[mn]) + '"';
				if(parseInt(mins[mn],10) == m) output += ' selected';
				output += '>' + pad(mins[mn]) + '</option>';
			}
			output += '</select>';				
	
			output += '<select id="p_' + this.id + '" class="timepicker">';				
			for(var pp in ap){
				output += '<option value="' + ap[pp] + '"';
				if(ap[pp] == p) output += ' selected';
				output += '>' + ap[pp] + '</option>';
			}
			output += '</select>';
			
			$(this).after(output);
			
			var field = this;
			$(this).siblings('select.timepicker').change(function() {
				var h = parseInt(jQuery('#h_'+field.id).val(),10);
				var m = parseInt($('#m_' + field.id).val(),10);
				var p = $('#p_' + field.id).val();
	
				if (p == "am") {
					if (h == 12) {
						h = 0;
					}
				} else if (p == "pm") {
					if (h < 12) {
						h += 12;
					}
				}
				
				var d = new Date();
				d.setHours(h);
				d.setMinutes(m);
				
				$(field).val(d.toUTCString());
			}).change();

		});

		return this;
	};
	
	jQuery.fn.daypicker = function() {
		
		var days = new Array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
		
		this.each(function() {
			var v = $(this).val();
			if (!v) v = 0;
			v = parseInt(v,10);
			
			var output = "";
			output += '<select id="d_' + this.id + '" class="daypicker">';				
			for (var i = 0; i < days.length; i++) {
				output += '<option value="' + i + '"';
				if (v == i) output += ' selected';
				output += '>' + days[i] + '</option>';
			}
			output += '</select>';
			
			$(this).after(output);
			
			var field = this;
			$(this).siblings('select.daypicker').change(function() {
				$(field).val( $(this).val() );
			}).change();
		
		});
		
	};
	
	jQuery.fn.forceToggleClass = function(classNames, bOn) {
		return this.each(function() {
			jQuery(this)[ bOn ? "addClass" : "removeClass" ](classNames);
		});
	};
	
})(jQuery);

jQuery(function() {

	// add in the time and day selects
	jQuery('form#cfzt_zanneltools input.time').timepicker();
	jQuery('form#cfzt_zanneltools input.day').daypicker();
	
	// togglers
	jQuery('.time_toggle .toggler').change(function() {
		var theSelect = jQuery(this);
		theSelect.parent('.time_toggle').forceToggleClass('active', theSelect.val() === "1");
	}).change();
	
});
<?php
						break;
					case 'prototype':
?>
function cfztTestLogin() {
	var username = $('cfzt_zannel_username').value;
	var password = $('cfzt_zannel_password').value;
	var result = $('cfzt_login_test_result');
	result.className = 'cfzt_login_result_wait';
	result.innerHTML = '<?php _e('Testing...', 'zannel-tools'); ?>';
	var cfztAjax = new Ajax.Updater(
		result,
		"<?php bloginfo('wpurl'); ?>/index.php",
		{
			method: "post",
			parameters: "cfzt_action=cfzt_login_test&cfzt_zannel_username=" + username + "&cfzt_zannel_password=" + password,
			onComplete: cfztTestLoginResult
		}
	);
}
function cfztTestLoginResult() {
	$('cfzt_login_test_result').className = 'cfzt_login_result';
	Fat.fade_element('cfzt_login_test_result');
}
<?php
						break;
				}
				die();
				break;
			case 'cfzt_css_admin':
				remove_action('shutdown', 'cfzt_ping_digests');
				header("Content-Type: text/css");
?>
#cfzt_zupdate_form {
	margin: 0;
	padding: 5px 0;
}
#cfzt_zupdate_form fieldset {
	border: 0;
}
#cfzt_zupdate_form fieldset textarea {
	width: 95%;
}
#cfzt_zupdate_form fieldset #cfzt_zupdate_submit {
	float: right;
	margin-right: 50px;
}
#cfzt_zupdate_form fieldset #cfzt_char_count {
	color: #666;
}
#ak_readme {
	height: 300px;
	width: 95%;
}
#cfzt_zanneltools .options {
	overflow: hidden;
	border: none;
}
#cfzt_zanneltools .option {
	overflow: hidden;
	border-bottom: dashed 1px #ccc;
	padding-bottom: 9px;
	padding-top: 9px;
}
#cfzt_zanneltools .option label {
	display: block;
	float: left;
	width: 200px;
	margin-right: 24px;
	text-align: right;
}
#cfzt_zanneltools .option span {
	display: block;
	float: left;
	margin-left: 230px;
	margin-top: 6px;
	clear: left;
}
#cfzt_zanneltools select,
#cfzt_zanneltools input {
	float: left;
	display: block;
	margin-right: 6px;
}
#cfzt_zanneltools p.submit {
	overflow: hidden;
}
#cfzt_zanneltools .option span {
	color: #666;
	display: block;
}
#cfzt_zanneltools #cfzt_login_test_result {
	display: inline;
	padding: 3px;
}
#cfzt_zanneltools fieldset.options .option span.cfzt_login_result_wait {
	background: #ffc;
}
#cfzt_zanneltools fieldset.options .option span.cfzt_login_result {
	background: #CFEBF7;
	color: #000;
}
#cfzt_zanneltools .timepicker,
#cfzt_zanneltools .daypicker {
	display: none;
}
#cfzt_zanneltools .active .timepicker,
#cfzt_zanneltools .active .daypicker {
	display: block
}
fieldset.experimental {
	border: 2px solid #900;
	padding: 10px;
}
fieldset.experimental legend {
	color: #900;
	font-weight: bold;
}
<?php
				die();
				break;
		}
	}
	if (!empty($_POST['cfzt_action'])) {
		switch($_POST['cfzt_action']) {
			case 'cfzt_update_settings':
				$cfzt->populate_settings();
				$cfzt->update_settings();
				wp_redirect(get_bloginfo('wpurl').'/wp-admin/options-general.php?page=zannel-tools.php&updated=true');
				die();
				break;
			case 'cfzt_post_zupdate_sidebar':
				if (!empty($_POST['cfzt_zupdate_text']) && current_user_can('publish_posts')) {
					$zupdate = new cfzt_zupdate();
					$zupdate->zupdate_description = stripslashes($_POST['cfzt_zupdate_text']);
					if ($cfzt->do_zupdate($zupdate)) {
						die(__('Update posted.', 'zannel-tools'));
					}
					else {
						die(__('Update post failed.', 'zannel-tools'));
					}
				}
				break;
			case 'cfzt_post_zupdate_admin':
				if (!empty($_POST['cfzt_zupdate_text']) && current_user_can('publish_posts')) {
					$zupdate = new cfzt_zupdate();
					$zupdate->zupdate_description = stripslashes($_POST['cfzt_zupdate_text']);
					if ($cfzt->do_zupdate($zupdate)) {
						wp_redirect(get_bloginfo('wpurl').'/wp-admin/post-new.php?page=zannel-tools.php&zupdate-posted=true');
					}
					else {
						wp_die(__('Oops, your Update was not posted. Please check your username and password and that Zannel is up and running happily.', 'zannel-tools'));
					}
					die();
				}
				break;
			case 'cfzt_login_test':
				$test = @cfzt_login_test(
					@stripslashes($_POST['cfzt_zannel_username'])
					, @stripslashes($_POST['cfzt_zannel_password'])
				);
				die(__($test, 'zannel-tools'));
				break;
		}
	}
}
add_action('init', 'cfzt_request_handler', 10);

function cfzt_admin_zupdate_form() {
	global $cfzt;
	if ( $_GET['zupdate-posted'] ) {
		print('
			<div id="message" class="updated fade">
				<p>'.__('Update posted.', 'zannel-tools').'</p>
			</div>
		');
	}
	print('
		<div class="wrap" id="cfzt_write_zupdate">
	');
	if (empty($cfzt->zannel_username) || empty($cfzt->zannel_password)) {
		print('
<p>Please enter your <a href="http://zannel.com">Zannel</a> account information in your <a href="options-general.php?page=zannel-tools.php">Zannel Tools Options</a>.</p>		
		');
	}
	else {
		print('
			<h2>'.__('Write Update', 'zannel-tools').'</h2>
			<p>This will create a new \'Update\' in <a href="http://zannel.com">Zannel</a> using the account information in your <a href="options-general.php?page=zannel-tools.php">Zannel Tools Options</a>.</p>
			'.cfzt_zupdate_form('textarea').'
		');
	}
	print('
		</div>
	');
}

function cfzt_options_form() {
	global $wpdb, $cfzt;

	$categories = get_categories('hide_empty=0');
	$cat_options = '';
	foreach ($categories as $category) {
		if ($category->term_id == $cfzt->blog_post_category) {
			$selected = 'selected="selected"';
		}
		else {
			$selected = '';
		}
		$cat_options .= "\n\t<option value='$category->term_id' $selected>$category->name</option>";
	}

	$authors = get_users_of_blog();
	$author_options = '';
	foreach ($authors as $user) {
		$usero = new WP_User($user->user_id);
		$author = $usero->data;
		// Only list users who are allowed to publish
		if (! $usero->has_cap('publish_posts')) {
			continue;
		}
		if ($author->ID == $cfzt->blog_post_author) {
			$selected = 'selected="selected"';
		}
		else {
			$selected = '';
		}
		$author_options .= "\n\t<option value='$author->ID' $selected>$author->user_nicename</option>";
	}
	
	$js_libs = array(
		'jquery' => 'jQuery'
		, 'prototype' => 'Prototype'
	);
	$js_lib_options = '';
	foreach ($js_libs as $js_lib => $js_lib_display) {
		if ($js_lib == $cfzt->js_lib) {
			$selected = 'selected="selected"';
		}
		else {
			$selected = '';
		}
		$js_lib_options .= "\n\t<option value='$js_lib' $selected>$js_lib_display</option>";
	}
	$digest_zupdate_orders = array(
		'ASC' => 'Oldest first (Chronological order)'
		, 'DESC' => 'Newest first (Reverse-chronological order)'
	);
	$digest_zupdate_order_options = '';
	foreach ($digest_zupdate_orders as $digest_zupdate_order => $digest_zupdate_order_display) {
		if ($digest_zupdate_order == $cfzt->digest_zupdate_order) {
			$selected = 'selected="selected"';
		}
		else {
			$selected = '';
		}
		$digest_zupdate_order_options .= "\n\t<option value='$digest_zupdate_order' $selected>$digest_zupdate_order_display</option>";
	}	
	$yes_no = array(
		'create_blog_posts'
		, 'create_digest'
		, 'create_digest_weekly'
		, 'notify_zannel'
		, 'notify_zannel_default'
		, 'zupdate_from_sidebar'
		, 'give_cfzt_credit'
		, 'exclude_reply_zupdates'
	);
	foreach ($yes_no as $key) {
		$var = $key.'_options';
		if ($cfzt->$key == '0') {
			$$var = '
				<option value="0" selected="selected">'.__('No', 'zannel-tools').'</option>
				<option value="1">'.__('Yes', 'zannel-tools').'</option>
			';
		}
		else {
			$$var = '
				<option value="0">'.__('No', 'zannel-tools').'</option>
				<option value="1" selected="selected">'.__('Yes', 'zannel-tools').'</option>
			';
		}
	}
	if ( $_GET['zupdates-updated'] ) {
		print('
			<div id="message" class="updated fade">
				<p>'.__('Zannel Updates updated.', 'zannel-tools').'</p>
			</div>
		');
	}
	if ( $_GET['zupdate-checking-reset'] ) {
		print('
			<div id="message" class="updated fade">
				<p>'.__('Update checking has been reset.', 'zannel-tools').'</p>
			</div>
		');
	}
	print('
			<div class="wrap" id="cfzt_options_page">
				<h2>'.__('Zannel Tools Options', 'zannel-tools').'</h2>
				<form id="cfzt_zanneltools" name="cfzt_zanneltools" action="'.get_bloginfo('wpurl').'/wp-admin/options-general.php" method="post">
					<input type="hidden" name="cfzt_action" value="cfzt_update_settings" />
					<fieldset class="options">
						<div class="option">
							<label for="cfzt_zannel_username">'.__('Zannel Username', 'zannel-tools').'/'.__('Password', 'zannel-tools').'</label>
							<input type="text" size="25" name="cfzt_zannel_username" id="cfzt_zannel_username" value="'.$cfzt->zannel_username.'" autocomplete="off" />
							<input type="password" size="25" name="cfzt_zannel_password" id="cfzt_zannel_password" value="'.$cfzt->zannel_password.'" autocomplete="off" />
							<input type="button" name="cfzt_login_test" id="cfzt_login_test" value="'.__('Test Login Info', 'zannel-tools').'" onclick="cfztTestLogin(); return false;" />
							<span id="cfzt_login_test_result"></span>
						</div>
						<div class="option">
							<label for="cfzt_notify_zannel">'.__('Enable option to create a Zannel Update when you post in your blog?', 'zannel-tools').'</label>
							<select name="cfzt_notify_zannel" id="cfzt_notify_zannel">'.$notify_zannel_options.'</select>
						</div>
						<div class="option">
							<label for="cfzt_notify_zannel_default">'.__('Set this on by default?', 'zannel-tools').'</label>
							<select name="cfzt_notify_zannel_default" id="cfzt_notify_zannel_default">'.$notify_zannel_default_options.'</select>
						</div>
						<div class="option">
							<label for="cfzt_create_blog_posts">'.__('Create a blog post from each of your Zannel Updates?', 'zannel-tools').'</label>
							<select name="cfzt_create_blog_posts" id="cfzt_create_blog_posts">'.$create_blog_posts_options.'</select>
						</div>	
						<div class="option">
							<label for="cfzt_default_post_title">'.__('If Zannel Update is just a pic or video, what should the title of the post be?', 'zannel-tools').'</label>
							<input type="text" name="cfzt_default_post_title" id="cfzt_default_post_title" value="'.get_option('cfzt_default_post_title').'" />
						</div>
						<div class="option time_toggle">
							<label>'.__('Create a daily digest blog post from your Zannel Updates?', 'zannel-tools').'</label>
							<select name="cfzt_create_digest" class="toggler">'.$create_digest_options.'</select>
							<input type="hidden" class="time" id="cfzt_digest_daily_time" name="cfzt_digest_daily_time" value="'.$cfzt->digest_daily_time.'" />
						</div>
						<div class="option">
							<label for="cfzt_digest_title">'.__('Title for daily digest posts:', 'zannel-tools').'</label>
							<input type="text" size="30" name="cfzt_digest_title" id="cfzt_digest_title" value="'.$cfzt->digest_title.'" />
							<span>'.__('Include %s where you want the date. Example: Updates on %s', 'zannel-tools').'</span>
						</div>
						<div class="option time_toggle">
							<label>'.__('Create a weekly digest blog post from your Zannel Updates?', 'zannel-tools').'</label>
							<select name="cfzt_create_digest_weekly" class="toggler">'.$create_digest_weekly_options.'</select>
							<input type="hidden" class="time" name="cfzt_digest_weekly_time" id="cfzt_digest_weekly_time" value="'.$cfzt->digest_weekly_time.'" />
							<input type="hidden" class="day" name="cfzt_digest_weekly_day" value="'.$cfzt->digest_weekly_day.'" />
						</div>
						<div class="option">
							<label for="cfzt_digest_title_weekly">'.__('Title for weekly digest posts:', 'zannel-tools').'</label>
							<input type="text" size="30" name="cfzt_digest_title_weekly" id="cfzt_digest_title_weekly" value="'.$cfzt->digest_title_weekly.'" />
							<span>'.__('Include %s where you want the date. Example: Updates on %s', 'zannel-tools').'</span>
						</div>
						<div class="option">
							<label for="cfzt_digest_zupdate_order">'.__('Order of Zannel Updates in digest?', 'zannel-tools').'</label>
							<select name="cfzt_digest_zupdate_order" id="cfzt_digest_zupdate_order">'.$digest_zupdate_order_options.'</select>
						</div>
						<div class="option">
							<label for="cfzt_blog_post_category">'.__('Category for Zannel Update posts:', 'zannel-tools').'</label>
							<select name="cfzt_blog_post_category" id="cfzt_blog_post_category">'.$cat_options.'</select>
						</div>
						<div class="option">
							<label for="cfzt_blog_post_tags">'.__('Tag(s) for your Zannel Update posts:', 'zannel-tools').'</label>
							<input name="cfzt_blog_post_tags" id="cfzt_blog_post_tags" value="'.$cfzt->blog_post_tags.'">
							<span>'._('Separate multiple tags with commas. Example: updates, zannel').'</span>
						</div>
						<div class="option">
							<label for="cfzt_blog_post_author">'.__('Author for Zannel Update posts:', 'zannel-tools').'</label>
							<select name="cfzt_blog_post_author" id="cfzt_blog_post_author">'.$author_options.'</select>
						</div>
						<div class="option">
							<label for="cfzt_exclude_reply_zupdates">'.__('Exclude @reply updates in your sidebar, digests and created blog posts?', 'zannel-tools').'</label>
							<select name="cfzt_exclude_reply_zupdates" id="cfzt_exclude_reply_zupdates">'.$exclude_reply_zupdates_options.'</select>
						</div>
						<div class="option">
							<label for="cfzt_sidebar_zupdate_count">'.__('Zannel Updates to show in sidebar:', 'zannel-tools').'</label>
							<input type="text" size="3" name="cfzt_sidebar_zupdate_count" id="cfzt_sidebar_zupdate_count" value="'.$cfzt->sidebar_zupdate_count.'" />
							<span>'.__('Numbers only please.', 'zannel-tools').'</span>
						</div>
						<div class="option">
							<label for="cfzt_zupdate_from_sidebar">'.__('Create Zannel Updates from your sidebar?', 'zannel-tools').'</label>
							<select name="cfzt_zupdate_from_sidebar" id="cfzt_zupdate_from_sidebar">'.$zupdate_from_sidebar_options.'</select>
						</div>
						<div class="option">
							<label for="cfzt_js_lib">'.__('JS Library to use?', 'zannel-tools').'</label>
							<select name="cfzt_js_lib" id="cfzt_js_lib">'.$js_lib_options.'</select>
						</div>
						<div class="option">
							<label for="cfzt_give_cfzt_credit">'.__('Give Zannel Tools credit?', 'zannel-tools').'</label>
							<select name="cfzt_give_cfzt_credit" id="cfzt_give_cfzt_credit">'.$give_cfzt_credit_options.'</select>
						</div>
					</fieldset>
					<p class="submit">
						<input type="submit" name="submit" value="'.__('Update Zannel Tools Options', 'zannel-tools').'" />
					</p>
				</form>
				<h2>'.__('Update Zannel Updates', 'zannel-tools').'</h2>
				<form name="cfzt_zanneltools_updatezupdates" action="'.get_bloginfo('wpurl').'/wp-admin/options-general.php" method="get">
					<p>'.__('Use this button to manually refresh your Zannel Updates.', 'zannel-tools').'</p>
					<p class="submit">
						<input type="submit" name="submit-button" value="'.__('Refresh Updates', 'zannel-tools').'" />
						<input type="submit" name="reset-button" value="'.__('Reset Update Checking', 'zannel-tools').'" onclick="document.getElementById(\'cfzt_action_2\').value = \'cfzt_reset_zupdate_checking\';" />
						<input type="hidden" name="cfzt_action" id="cfzt_action_2" value="cfzt_update_zupdates" />
					</p>
				</form>
			</div>
	');
}

function cfzt_post_options() {
	global $cfzt, $post;
	if ($cfzt->notify_zannel) {
		echo '<div class="postbox">
			<h3>Zannel Tools</h3>
			<div class="inside">
			<p>Notify Zannel about this post?
			';
		if (get_post_meta($post->ID, 'cfzt_notify_zannel', true) == 'no' || !$cfzt->notify_zannel_default) {
			$yes = '';
			$no = 'checked="checked"';
		}
		else {
			$yes = 'checked="checked"';
			$no = '';
		}
		echo '
		<input type="radio" name="cfzt_notify_zannel" id="cfzt_notify_zannel_yes" value="yes" '.$yes.' /> <label for="cfzt_notify_zannel_yes">Yes</label>&nbsp;&nbsp;
		<input type="radio" name="cfzt_notify_zannel" id="cfzt_notify_zannel_no" value="no" '.$no.' /> <label for="cfzt_notify_zannel_no">No</label>
		';			
		echo '</p>
			</div><!--.inside-->
			</div><!--.postbox-->
		';
	}
}
add_action('edit_form_advanced', 'cfzt_post_options');

function cfzt_store_post_options($post_id, $post = false) {
	global $cfzt;
	if (!$post || $post->post_type == 'revision') {
		return;
	}
	if ((!empty($_POST['cfzt_notify_zannel']) && $_POST['cfzt_notify_zannel'] == "yes") || (empty($_POST['cfzt_notify_zannel']) && $cfzt->notify_zannel_default)) {
		$notify = 'yes';
	}
	else {
		$notify = 'no';
	}
	if (!update_post_meta($post_id, 'cfzt_notify_zannel', $notify)) {
		add_post_meta($post_id, 'cfzt_notify_zannel', $notify);
	}
}
add_action('draft_post', 'cfzt_store_post_options', 1, 2);
add_action('publish_post', 'cfzt_store_post_options', 1, 2);
add_action('save_post', 'cfzt_store_post_options', 1, 2);

function cfzt_menu_items() {
	if (current_user_can('manage_options')) {
		add_options_page(
			__('Zannel Tools Options', 'zannel-tools')
			, __('Zannel Tools', 'zannel-tools')
			, 10
			, basename(__FILE__)
			, 'cfzt_options_form'
		);
	}
	if (current_user_can('publish_posts')) {
		add_submenu_page(
			'post-new.php'
			, __('New Update', 'zannel-tools')
			, __('Zannel Update', 'zannel-tools')
			, 10
			, basename(__FILE__)
			, 'cfzt_admin_zupdate_form'
		);
	}
}
add_action('admin_menu', 'cfzt_menu_items');

function cfzt_plugin_action_links($links, $file) {
	$plugin_file = basename(__FILE__);
	if ($file == $plugin_file) {
		$settings_link = '<a href="options-general.php?page='.$plugin_file.'">'.__('Settings', 'zannel-tools').'</a>';
		array_unshift($links, $settings_link);
	}
	return $links;
}
add_filter('plugin_action_links', 'cfzt_plugin_action_links', 10, 2);

if (!function_exists('trim_add_elipsis')) {
	function trim_add_elipsis($string, $limit = 100) {
		if (strlen($string) > $limit) {
			$string = substr($string, 0, $limit)."...";
		}
		return $string;
	}
}

if (!function_exists('ak_gmmktime')) {
	function ak_gmmktime() {
		return gmmktime() - get_option('gmt_offset') * 3600;
	}
}

/**

based on: http://www.gyford.com/phil/writing/2006/12/02/quick_twitter.php

	 * Returns a relative date, eg "4 hrs ago".
	 *
	 * Assumes the passed-in can be parsed by strtotime.
	 * Precision could be one of:
	 * 	1	5 hours, 3 minutes, 2 seconds ago (not yet implemented).
	 * 	2	5 hours, 3 minutes
	 * 	3	5 hours
	 *
	 * This is all a little overkill, but copied from other places I've used it.
	 * Also superfluous, now I've noticed that the Zannel API includes something
	 * similar, but this version is more accurate and less verbose.
	 *
	 * @access private.
	 * @param string date In a format parseable by strtotime().
	 * @param integer precision
	 * @return string
	 */
function cfzt_relativeTime ($date, $precision=2)
{

	$now = time();

	/*$time = gmmktime(
		substr($date, 11, 2)
		, substr($date, 14, 2)
		, substr($date, 17, 2)
		, substr($date, 5, 2)
		, substr($date, 8, 2)
		, substr($date, 0, 4)
	);

	$time = strtotime(date('Y-m-d H:i:s', $time));*/
	$time = strtotime($date);

	$diff 	=  $now - $time;

	$months	=  floor($diff/2419200);
	$diff 	-= $months * 2419200;
	$weeks 	=  floor($diff/604800);
	$diff	-= $weeks*604800;
	$days 	=  floor($diff/86400);
	$diff 	-= $days * 86400;
	$hours 	=  floor($diff/3600);
	$diff 	-= $hours * 3600;
	$minutes = floor($diff/60);
	$diff 	-= $minutes * 60;
	$seconds = $diff;

	if ($months > 0) {
		return date('Y-m-d', $time);
	} else {
		$relative_date = '';
		if ($weeks > 0) {
			// Weeks and days
			$relative_date .= ($relative_date?', ':'').$weeks.' week'.($weeks>1?'s':'');
			if ($precision <= 2) {
				$relative_date .= $days>0?($relative_date?', ':'').$days.' day'.($days>1?'s':''):'';
				if ($precision == 1) {
					$relative_date .= $hours>0?($relative_date?', ':'').$hours.' hr'.($hours>1?'s':''):'';
				}
			}
		} elseif ($days > 0) {
			// days and hours
			$relative_date .= ($relative_date?', ':'').$days.' day'.($days>1?'s':'');
			if ($precision <= 2) {
				$relative_date .= $hours>0?($relative_date?', ':'').$hours.' hr'.($hours>1?'s':''):'';
				if ($precision == 1) {
					$relative_date .= $minutes>0?($relative_date?', ':'').$minutes.' min'.($minutes>1?'s':''):'';
				}
			}
		} elseif ($hours > 0) {
			// hours and minutes
			$relative_date .= ($relative_date?', ':'').$hours.' hr'.($hours>1?'s':'');
			if ($precision <= 2) {
				$relative_date .= $minutes>0?($relative_date?', ':'').$minutes.' min'.($minutes>1?'s':''):'';
				if ($precision == 1) {
					$relative_date .= $seconds>0?($relative_date?', ':'').$seconds.' sec'.($seconds>1?'s':''):'';
				}
			}
		} elseif ($minutes > 0) {
			// minutes only
			$relative_date .= ($relative_date?', ':'').$minutes.' min'.($minutes>1?'s':'');
			if ($precision == 1) {
				$relative_date .= $seconds>0?($relative_date?', ':'').$seconds.' sec'.($seconds>1?'s':''):'';
			}
		} else {
			// seconds only
			$relative_date .= ($relative_date?', ':'').$seconds.' sec'.($seconds>1?'s':'');
		}
	}

	// Return relative date and add proper verbiage
	return sprintf(__('%s ago', 'zannel-tools'), $relative_date);
}
if (!class_exists('Services_JSON')) {

// PEAR JSON class

/**
* Converts to and from JSON format.
*
* JSON (JavaScript Object Notation) is a lightweight data-interchange
* format. It is easy for humans to read and write. It is easy for machines
* to parse and generate. It is based on a subset of the JavaScript
* Programming Language, Standard ECMA-262 3rd Edition - December 1999.
* This feature can also be found in  Python. JSON is a text format that is
* completely language independent but uses conventions that are familiar
* to programmers of the C-family of languages, including C, C++, C#, Java,
* JavaScript, Perl, TCL, and many others. These properties make JSON an
* ideal data-interchange language.
*
* This package provides a simple encoder and decoder for JSON notation. It
* is intended for use with client-side Javascript applications that make
* use of HTTPRequest to perform server communication functions - data can
* be encoded into JSON notation for use in a client-side javascript, or
* decoded from incoming Javascript requests. JSON format is native to
* Javascript, and can be directly eval()'ed with no further parsing
* overhead
*
* All strings should be in ASCII or UTF-8 format!
*
* LICENSE: Redistribution and use in source and binary forms, with or
* without modification, are permitted provided that the following
* conditions are met: Redistributions of source code must retain the
* above copyright notice, this list of conditions and the following
* disclaimer. Redistributions in binary form must reproduce the above
* copyright notice, this list of conditions and the following disclaimer
* in the documentation and/or other materials provided with the
* distribution.
*
* THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED
* WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
* MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN
* NO EVENT SHALL CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
* INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
* BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS
* OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
* ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR
* TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE
* USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
* DAMAGE.
*
* @category
* @package     Services_JSON
* @author      Michal Migurski <mike-json@teczno.com>
* @author      Matt Knapp <mdknapp[at]gmail[dot]com>
* @author      Brett Stimmerman <brettstimmerman[at]gmail[dot]com>
* @copyright   2005 Michal Migurski
* @version     CVS: $Id: JSON.php,v 1.31 2006/06/28 05:54:17 migurski Exp $
* @license     http://www.opensource.org/licenses/bsd-license.php
* @link        http://pear.php.net/pepr/pepr-proposal-show.php?id=198
*/

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_SLICE',   1);

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_IN_STR',  2);

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_IN_ARR',  3);

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_IN_OBJ',  4);

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_IN_CMT', 5);

/**
* Behavior switch for Services_JSON::decode()
*/
define('SERVICES_JSON_LOOSE_TYPE', 16);

/**
* Behavior switch for Services_JSON::decode()
*/
define('SERVICES_JSON_SUPPRESS_ERRORS', 32);

/**
* Converts to and from JSON format.
*
* Brief example of use:
*
* <code>
* // create a new instance of Services_JSON
* $json = new Services_JSON();
*
* // convert a complexe value to JSON notation, and send it to the browser
* $value = array('foo', 'bar', array(1, 2, 'baz'), array(3, array(4)));
* $output = $json->encode($value);
*
* print($output);
* // prints: ["foo","bar",[1,2,"baz"],[3,[4]]]
*
* // accept incoming POST data, assumed to be in JSON notation
* $input = file_get_contents('php://input', 1000000);
* $value = $json->decode($input);
* </code>
*/
class Services_JSON
{
   /**
    * constructs a new JSON instance
    *
    * @param    int     $use    object behavior flags; combine with boolean-OR
    *
    *                           possible values:
    *                           - SERVICES_JSON_LOOSE_TYPE:  loose typing.
    *                                   "{...}" syntax creates associative arrays
    *                                   instead of objects in decode().
    *                           - SERVICES_JSON_SUPPRESS_ERRORS:  error suppression.
    *                                   Values which can't be encoded (e.g. resources)
    *                                   appear as NULL instead of throwing errors.
    *                                   By default, a deeply-nested resource will
    *                                   bubble up with an error, so all return values
    *                                   from encode() should be checked with isError()
    */
    function Services_JSON($use = 0)
    {
        $this->use = $use;
    }

   /**
    * convert a string from one UTF-16 char to one UTF-8 char
    *
    * Normally should be handled by mb_convert_encoding, but
    * provides a slower PHP-only method for installations
    * that lack the multibye string extension.
    *
    * @param    string  $utf16  UTF-16 character
    * @return   string  UTF-8 character
    * @access   private
    */
    function utf162utf8($utf16)
    {
        // oh please oh please oh please oh please oh please
        if(function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($utf16, 'UTF-8', 'UTF-16');
        }

        $bytes = (ord($utf16{0}) << 8) | ord($utf16{1});

        switch(true) {
            case ((0x7F & $bytes) == $bytes):
                // this case should never be reached, because we are in ASCII range
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0x7F & $bytes);

            case (0x07FF & $bytes) == $bytes:
                // return a 2-byte UTF-8 character
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0xC0 | (($bytes >> 6) & 0x1F))
                     . chr(0x80 | ($bytes & 0x3F));

            case (0xFFFF & $bytes) == $bytes:
                // return a 3-byte UTF-8 character
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0xE0 | (($bytes >> 12) & 0x0F))
                     . chr(0x80 | (($bytes >> 6) & 0x3F))
                     . chr(0x80 | ($bytes & 0x3F));
        }

        // ignoring UTF-32 for now, sorry
        return '';
    }

   /**
    * convert a string from one UTF-8 char to one UTF-16 char
    *
    * Normally should be handled by mb_convert_encoding, but
    * provides a slower PHP-only method for installations
    * that lack the multibye string extension.
    *
    * @param    string  $utf8   UTF-8 character
    * @return   string  UTF-16 character
    * @access   private
    */
    function utf82utf16($utf8)
    {
        // oh please oh please oh please oh please oh please
        if(function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($utf8, 'UTF-16', 'UTF-8');
        }

        switch(strlen($utf8)) {
            case 1:
                // this case should never be reached, because we are in ASCII range
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return $utf8;

            case 2:
                // return a UTF-16 character from a 2-byte UTF-8 char
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0x07 & (ord($utf8{0}) >> 2))
                     . chr((0xC0 & (ord($utf8{0}) << 6))
                         | (0x3F & ord($utf8{1})));

            case 3:
                // return a UTF-16 character from a 3-byte UTF-8 char
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr((0xF0 & (ord($utf8{0}) << 4))
                         | (0x0F & (ord($utf8{1}) >> 2)))
                     . chr((0xC0 & (ord($utf8{1}) << 6))
                         | (0x7F & ord($utf8{2})));
        }

        // ignoring UTF-32 for now, sorry
        return '';
    }

   /**
    * encodes an arbitrary variable into JSON format
    *
    * @param    mixed   $var    any number, boolean, string, array, or object to be encoded.
    *                           see argument 1 to Services_JSON() above for array-parsing behavior.
    *                           if var is a strng, note that encode() always expects it
    *                           to be in ASCII or UTF-8 format!
    *
    * @return   mixed   JSON string representation of input var or an error if a problem occurs
    * @access   public
    */
    function encode($var)
    {
        switch (gettype($var)) {
            case 'boolean':
                return $var ? 'true' : 'false';

            case 'NULL':
                return 'null';

            case 'integer':
                return (int) $var;

            case 'double':
            case 'float':
                return (float) $var;

            case 'string':
                // STRINGS ARE EXPECTED TO BE IN ASCII OR UTF-8 FORMAT
                $ascii = '';
                $strlen_var = strlen($var);

               /*
                * Iterate over every character in the string,
                * escaping with a slash or encoding to UTF-8 where necessary
                */
                for ($c = 0; $c < $strlen_var; ++$c) {

                    $ord_var_c = ord($var{$c});

                    switch (true) {
                        case $ord_var_c == 0x08:
                            $ascii .= '\b';
                            break;
                        case $ord_var_c == 0x09:
                            $ascii .= '\t';
                            break;
                        case $ord_var_c == 0x0A:
                            $ascii .= '\n';
                            break;
                        case $ord_var_c == 0x0C:
                            $ascii .= '\f';
                            break;
                        case $ord_var_c == 0x0D:
                            $ascii .= '\r';
                            break;

                        case $ord_var_c == 0x22:
                        case $ord_var_c == 0x2F:
                        case $ord_var_c == 0x5C:
                            // double quote, slash, slosh
                            $ascii .= '\\'.$var{$c};
                            break;

                        case (($ord_var_c >= 0x20) && ($ord_var_c <= 0x7F)):
                            // characters U-00000000 - U-0000007F (same as ASCII)
                            $ascii .= $var{$c};
                            break;

                        case (($ord_var_c & 0xE0) == 0xC0):
                            // characters U-00000080 - U-000007FF, mask 110XXXXX
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c, ord($var{$c + 1}));
                            $c += 1;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;

                        case (($ord_var_c & 0xF0) == 0xE0):
                            // characters U-00000800 - U-0000FFFF, mask 1110XXXX
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c,
                                         ord($var{$c + 1}),
                                         ord($var{$c + 2}));
                            $c += 2;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;

                        case (($ord_var_c & 0xF8) == 0xF0):
                            // characters U-00010000 - U-001FFFFF, mask 11110XXX
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c,
                                         ord($var{$c + 1}),
                                         ord($var{$c + 2}),
                                         ord($var{$c + 3}));
                            $c += 3;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;

                        case (($ord_var_c & 0xFC) == 0xF8):
                            // characters U-00200000 - U-03FFFFFF, mask 111110XX
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c,
                                         ord($var{$c + 1}),
                                         ord($var{$c + 2}),
                                         ord($var{$c + 3}),
                                         ord($var{$c + 4}));
                            $c += 4;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;

                        case (($ord_var_c & 0xFE) == 0xFC):
                            // characters U-04000000 - U-7FFFFFFF, mask 1111110X
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c,
                                         ord($var{$c + 1}),
                                         ord($var{$c + 2}),
                                         ord($var{$c + 3}),
                                         ord($var{$c + 4}),
                                         ord($var{$c + 5}));
                            $c += 5;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;
                    }
                }

                return '"'.$ascii.'"';

            case 'array':
               /*
                * As per JSON spec if any array key is not an integer
                * we must treat the the whole array as an object. We
                * also try to catch a sparsely populated associative
                * array with numeric keys here because some JS engines
                * will create an array with empty indexes up to
                * max_index which can cause memory issues and because
                * the keys, which may be relevant, will be remapped
                * otherwise.
                *
                * As per the ECMA and JSON specification an object may
                * have any string as a property. Unfortunately due to
                * a hole in the ECMA specification if the key is a
                * ECMA reserved word or starts with a digit the
                * parameter is only accessible using ECMAScript's
                * bracket notation.
                */

                // treat as a JSON object
                if (is_array($var) && count($var) && (array_keys($var) !== range(0, sizeof($var) - 1))) {
                    $properties = array_map(array($this, 'name_value'),
                                            array_keys($var),
                                            array_values($var));

                    foreach($properties as $property) {
                        if(Services_JSON::isError($property)) {
                            return $property;
                        }
                    }

                    return '{' . join(',', $properties) . '}';
                }

                // treat it like a regular array
                $elements = array_map(array($this, 'encode'), $var);

                foreach($elements as $element) {
                    if(Services_JSON::isError($element)) {
                        return $element;
                    }
                }

                return '[' . join(',', $elements) . ']';

            case 'object':
                $vars = get_object_vars($var);

                $properties = array_map(array($this, 'name_value'),
                                        array_keys($vars),
                                        array_values($vars));

                foreach($properties as $property) {
                    if(Services_JSON::isError($property)) {
                        return $property;
                    }
                }

                return '{' . join(',', $properties) . '}';

            default:
                return ($this->use & SERVICES_JSON_SUPPRESS_ERRORS)
                    ? 'null'
                    : new Services_JSON_Error(gettype($var)." can not be encoded as JSON string");
        }
    }

   /**
    * array-walking function for use in generating JSON-formatted name-value pairs
    *
    * @param    string  $name   name of key to use
    * @param    mixed   $value  reference to an array element to be encoded
    *
    * @return   string  JSON-formatted name-value pair, like '"name":value'
    * @access   private
    */
    function name_value($name, $value)
    {
        $encoded_value = $this->encode($value);

        if(Services_JSON::isError($encoded_value)) {
            return $encoded_value;
        }

        return $this->encode(strval($name)) . ':' . $encoded_value;
    }

   /**
    * reduce a string by removing leading and trailing comments and whitespace
    *
    * @param    $str    string      string value to strip of comments and whitespace
    *
    * @return   string  string value stripped of comments and whitespace
    * @access   private
    */
    function reduce_string($str)
    {
        $str = preg_replace(array(

                // eliminate single line comments in '// ...' form
                '#^\s*//(.+)$#m',

                // eliminate multi-line comments in '/* ... */' form, at start of string
                '#^\s*/\*(.+)\*/#Us',

                // eliminate multi-line comments in '/* ... */' form, at end of string
                '#/\*(.+)\*/\s*$#Us'

            ), '', $str);

        // eliminate extraneous space
        return trim($str);
    }

   /**
    * decodes a JSON string into appropriate variable
    *
    * @param    string  $str    JSON-formatted string
    *
    * @return   mixed   number, boolean, string, array, or object
    *                   corresponding to given JSON input string.
    *                   See argument 1 to Services_JSON() above for object-output behavior.
    *                   Note that decode() always returns strings
    *                   in ASCII or UTF-8 format!
    * @access   public
    */
    function decode($str)
    {
        $str = $this->reduce_string($str);

        switch (strtolower($str)) {
            case 'true':
                return true;

            case 'false':
                return false;

            case 'null':
                return null;

            default:
                $m = array();

                if (is_numeric($str)) {
                    // Lookie-loo, it's a number

                    // This would work on its own, but I'm trying to be
                    // good about returning integers where appropriate:
                    // return (float)$str;

                    // Return float or int, as appropriate
                    return ((float)$str == (integer)$str)
                        ? (integer)$str
                        : (float)$str;

                } elseif (preg_match('/^("|\').*(\1)$/s', $str, $m) && $m[1] == $m[2]) {
                    // STRINGS RETURNED IN UTF-8 FORMAT
                    $delim = substr($str, 0, 1);
                    $chrs = substr($str, 1, -1);
                    $utf8 = '';
                    $strlen_chrs = strlen($chrs);

                    for ($c = 0; $c < $strlen_chrs; ++$c) {

                        $substr_chrs_c_2 = substr($chrs, $c, 2);
                        $ord_chrs_c = ord($chrs{$c});

                        switch (true) {
                            case $substr_chrs_c_2 == '\b':
                                $utf8 .= chr(0x08);
                                ++$c;
                                break;
                            case $substr_chrs_c_2 == '\t':
                                $utf8 .= chr(0x09);
                                ++$c;
                                break;
                            case $substr_chrs_c_2 == '\n':
                                $utf8 .= chr(0x0A);
                                ++$c;
                                break;
                            case $substr_chrs_c_2 == '\f':
                                $utf8 .= chr(0x0C);
                                ++$c;
                                break;
                            case $substr_chrs_c_2 == '\r':
                                $utf8 .= chr(0x0D);
                                ++$c;
                                break;

                            case $substr_chrs_c_2 == '\\"':
                            case $substr_chrs_c_2 == '\\\'':
                            case $substr_chrs_c_2 == '\\\\':
                            case $substr_chrs_c_2 == '\\/':
                                if (($delim == '"' && $substr_chrs_c_2 != '\\\'') ||
                                   ($delim == "'" && $substr_chrs_c_2 != '\\"')) {
                                    $utf8 .= $chrs{++$c};
                                }
                                break;

                            case preg_match('/\\\u[0-9A-F]{4}/i', substr($chrs, $c, 6)):
                                // single, escaped unicode character
                                $utf16 = chr(hexdec(substr($chrs, ($c + 2), 2)))
                                       . chr(hexdec(substr($chrs, ($c + 4), 2)));
                                $utf8 .= $this->utf162utf8($utf16);
                                $c += 5;
                                break;

                            case ($ord_chrs_c >= 0x20) && ($ord_chrs_c <= 0x7F):
                                $utf8 .= $chrs{$c};
                                break;

                            case ($ord_chrs_c & 0xE0) == 0xC0:
                                // characters U-00000080 - U-000007FF, mask 110XXXXX
                                //see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 2);
                                ++$c;
                                break;

                            case ($ord_chrs_c & 0xF0) == 0xE0:
                                // characters U-00000800 - U-0000FFFF, mask 1110XXXX
                                // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 3);
                                $c += 2;
                                break;

                            case ($ord_chrs_c & 0xF8) == 0xF0:
                                // characters U-00010000 - U-001FFFFF, mask 11110XXX
                                // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 4);
                                $c += 3;
                                break;

                            case ($ord_chrs_c & 0xFC) == 0xF8:
                                // characters U-00200000 - U-03FFFFFF, mask 111110XX
                                // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 5);
                                $c += 4;
                                break;

                            case ($ord_chrs_c & 0xFE) == 0xFC:
                                // characters U-04000000 - U-7FFFFFFF, mask 1111110X
                                // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 6);
                                $c += 5;
                                break;

                        }

                    }

                    return $utf8;

                } elseif (preg_match('/^\[.*\]$/s', $str) || preg_match('/^\{.*\}$/s', $str)) {
                    // array, or object notation

                    if ($str{0} == '[') {
                        $stk = array(SERVICES_JSON_IN_ARR);
                        $arr = array();
                    } else {
                        if ($this->use & SERVICES_JSON_LOOSE_TYPE) {
                            $stk = array(SERVICES_JSON_IN_OBJ);
                            $obj = array();
                        } else {
                            $stk = array(SERVICES_JSON_IN_OBJ);
                            $obj = new stdClass();
                        }
                    }

                    array_push($stk, array('what'  => SERVICES_JSON_SLICE,
                                           'where' => 0,
                                           'delim' => false));

                    $chrs = substr($str, 1, -1);
                    $chrs = $this->reduce_string($chrs);

                    if ($chrs == '') {
                        if (reset($stk) == SERVICES_JSON_IN_ARR) {
                            return $arr;

                        } else {
                            return $obj;

                        }
                    }

                    //print("\nparsing {$chrs}\n");

                    $strlen_chrs = strlen($chrs);

                    for ($c = 0; $c <= $strlen_chrs; ++$c) {

                        $top = end($stk);
                        $substr_chrs_c_2 = substr($chrs, $c, 2);

                        if (($c == $strlen_chrs) || (($chrs{$c} == ',') && ($top['what'] == SERVICES_JSON_SLICE))) {
                            // found a comma that is not inside a string, array, etc.,
                            // OR we've reached the end of the character list
                            $slice = substr($chrs, $top['where'], ($c - $top['where']));
                            array_push($stk, array('what' => SERVICES_JSON_SLICE, 'where' => ($c + 1), 'delim' => false));
                            //print("Found split at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                            if (reset($stk) == SERVICES_JSON_IN_ARR) {
                                // we are in an array, so just push an element onto the stack
                                array_push($arr, $this->decode($slice));

                            } elseif (reset($stk) == SERVICES_JSON_IN_OBJ) {
                                // we are in an object, so figure
                                // out the property name and set an
                                // element in an associative array,
                                // for now
                                $parts = array();
                                
                                if (preg_match('/^\s*(["\'].*[^\\\]["\'])\s*:\s*(\S.*),?$/Uis', $slice, $parts)) {
                                    // "name":value pair
                                    $key = $this->decode($parts[1]);
                                    $val = $this->decode($parts[2]);

                                    if ($this->use & SERVICES_JSON_LOOSE_TYPE) {
                                        $obj[$key] = $val;
                                    } else {
                                        $obj->$key = $val;
                                    }
                                } elseif (preg_match('/^\s*(\w+)\s*:\s*(\S.*),?$/Uis', $slice, $parts)) {
                                    // name:value pair, where name is unquoted
                                    $key = $parts[1];
                                    $val = $this->decode($parts[2]);

                                    if ($this->use & SERVICES_JSON_LOOSE_TYPE) {
                                        $obj[$key] = $val;
                                    } else {
                                        $obj->$key = $val;
                                    }
                                }

                            }

                        } elseif ((($chrs{$c} == '"') || ($chrs{$c} == "'")) && ($top['what'] != SERVICES_JSON_IN_STR)) {
                            // found a quote, and we are not inside a string
                            array_push($stk, array('what' => SERVICES_JSON_IN_STR, 'where' => $c, 'delim' => $chrs{$c}));
                            //print("Found start of string at {$c}\n");

                        } elseif (($chrs{$c} == $top['delim']) &&
                                 ($top['what'] == SERVICES_JSON_IN_STR) &&
                                 ((strlen(substr($chrs, 0, $c)) - strlen(rtrim(substr($chrs, 0, $c), '\\'))) % 2 != 1)) {
                            // found a quote, we're in a string, and it's not escaped
                            // we know that it's not escaped becase there is _not_ an
                            // odd number of backslashes at the end of the string so far
                            array_pop($stk);
                            //print("Found end of string at {$c}: ".substr($chrs, $top['where'], (1 + 1 + $c - $top['where']))."\n");

                        } elseif (($chrs{$c} == '[') &&
                                 in_array($top['what'], array(SERVICES_JSON_SLICE, SERVICES_JSON_IN_ARR, SERVICES_JSON_IN_OBJ))) {
                            // found a left-bracket, and we are in an array, object, or slice
                            array_push($stk, array('what' => SERVICES_JSON_IN_ARR, 'where' => $c, 'delim' => false));
                            //print("Found start of array at {$c}\n");

                        } elseif (($chrs{$c} == ']') && ($top['what'] == SERVICES_JSON_IN_ARR)) {
                            // found a right-bracket, and we're in an array
                            array_pop($stk);
                            //print("Found end of array at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                        } elseif (($chrs{$c} == '{') &&
                                 in_array($top['what'], array(SERVICES_JSON_SLICE, SERVICES_JSON_IN_ARR, SERVICES_JSON_IN_OBJ))) {
                            // found a left-brace, and we are in an array, object, or slice
                            array_push($stk, array('what' => SERVICES_JSON_IN_OBJ, 'where' => $c, 'delim' => false));
                            //print("Found start of object at {$c}\n");

                        } elseif (($chrs{$c} == '}') && ($top['what'] == SERVICES_JSON_IN_OBJ)) {
                            // found a right-brace, and we're in an object
                            array_pop($stk);
                            //print("Found end of object at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                        } elseif (($substr_chrs_c_2 == '/*') &&
                                 in_array($top['what'], array(SERVICES_JSON_SLICE, SERVICES_JSON_IN_ARR, SERVICES_JSON_IN_OBJ))) {
                            // found a comment start, and we are in an array, object, or slice
                            array_push($stk, array('what' => SERVICES_JSON_IN_CMT, 'where' => $c, 'delim' => false));
                            $c++;
                            //print("Found start of comment at {$c}\n");

                        } elseif (($substr_chrs_c_2 == '*/') && ($top['what'] == SERVICES_JSON_IN_CMT)) {
                            // found a comment end, and we're in one now
                            array_pop($stk);
                            $c++;

                            for ($i = $top['where']; $i <= $c; ++$i)
                                $chrs = substr_replace($chrs, ' ', $i, 1);

                            //print("Found end of comment at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                        }

                    }

                    if (reset($stk) == SERVICES_JSON_IN_ARR) {
                        return $arr;

                    } elseif (reset($stk) == SERVICES_JSON_IN_OBJ) {
                        return $obj;

                    }

                }
        }
    }

    /**
     * @todo Ultimately, this should just call PEAR::isError()
     */
    function isError($data, $code = null)
    {
        if (class_exists('pear')) {
            return PEAR::isError($data, $code);
        } elseif (is_object($data) && (get_class($data) == 'services_json_error' ||
                                 is_subclass_of($data, 'services_json_error'))) {
            return true;
        }

        return false;
    }
}

if (class_exists('PEAR_Error')) {

    class Services_JSON_Error extends PEAR_Error
    {
        function Services_JSON_Error($message = 'unknown error', $code = null,
                                     $mode = null, $options = null, $userinfo = null)
        {
            parent::PEAR_Error($message, $code, $mode, $options, $userinfo);
        }
    }

} else {

    /**
     * @todo Ultimately, this class shall be descended from PEAR_Error
     */
    class Services_JSON_Error
    {
        function Services_JSON_Error($message = 'unknown error', $code = null,
                                     $mode = null, $options = null, $userinfo = null)
        {

        }
    }

}

}

?>