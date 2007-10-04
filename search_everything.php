<?php
/*
Plugin Name: Search Everything
Plugin URI: http://dancameron.org/wordpress/
Description: Adds search functionality with little setup. Including options to search pages, excerpts, attachments, drafts, comments, tags and custom fields (metadata). Also offers the ability to exclude specific pages and posts. Does not search password-protected content. 
Version: 4.1
Author: Dan Cameron
Author URI: http://dancameron.org/
*/

/*
This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, version 2.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
*/
define('SE_ABSPATH', ABSPATH . 'wp-content/plugins/' . dirname(plugin_basename(__FILE__)).'/');

$SE = new SearchEverything();
//add filters based upon option settings

Class SearchEverything {

	var $login = false;
	var $options;
	
	function SearchEverything(){

		$this->options = get_option('SE4_options');

		if (is_admin()) {
			include ( SE_ABSPATH  . '/SE-Admin.php' );
			$SEAdmin = new SearchEverythingAdmin();
		}
		
		//add filters based upon option settings
		if ("true" == $this->options['SE4_use_page_search']) {
			add_filter('posts_where', array(&$this, 'SE4_search_pages'));
			$this->SE4_log("searching pages");
		}
	
		if ("true" == $this->options['SE4_use_excerpt_search']) {
			add_filter('posts_where', array(&$this, 'SE4_search_excerpt'));
			$this->SE4_log("searching excerpts");
		}

		if ("true" == $this->options['SE4_use_comment_search']) {
			add_filter('posts_where', array(&$this, 'SE4_search_comments'));
			add_filter('posts_join', array(&$this, 'SE4_comments_join'));
			$this->SE4_log("searching comments");
		}
	
		if ("true" == $this->options['SE4_use_draft_search']) {
			add_filter('posts_where', array(&$this, 'SE4_search_draft_posts'));
			$this->SE4_log("searching drafts");
		}
	
		if ("true" == $this->options['SE4_use_attachment_search']) {
			add_filter('posts_where', array(&$this, 'SE4_search_attachments'));
			$this->SE4_log("searching attachments");
		}

		if ("true" == $this->options['SE4_use_metadata_search']) {
			add_filter('posts_where', array(&$this, 'SE4_search_metadata'));
			add_filter('posts_join', array(&$this, 'SE4_search_metadata_join'));
			$this->SE4_log("searching metadata");
		}

		if ("true" == $this->options['SE4_exclude_posts']) {
			add_filter('posts_where', array(&$this, 'SE4_exclude_posts'));
			$this->SE4_log("searching excluding posts");
		}

		// - Depracated in 2.3
		if ("true" == $this->options['SE4_exclude_categories']) {
			add_filter('posts_where', array(&$this, 'SE4_exclude_categories'));
			add_filter('posts_join', array(&$this, 'SE4_exclude_categories_join'));
			$this->SE4_log("searching excluding categories");
		}

		// - Depracated
		if ("true" == $this->options['SE4_use_tag_search']) { 
			add_filter('posts_where', array(&$this, 'SE4_search_tag')); 
			add_filter('posts_join', array(&$this, 'SE4_search_tag_join')); 
			$this->SE4_log("searching tag");
		}

		//Duplicate fix provided by Tiago.Pocinho
		add_filter('posts_request', array(&$this, 'SE4_distinct'));
	}

	function SE4_log($msg) {

		if ($this->logging) {
			$fp = fopen("logfile.log","a+");
			$date = date("Y-m-d H:i:s ");
			$source = "search_everything_2 plugin: ";
			fwrite($fp, "\n\n".$date."\n".$source."\n".$msg);
			fclose($fp);
		}
		return true;
	}

	//Duplicate fix provided by Tiago.Pocinho
	function SE4_distinct($query){
		  global $wp_query;
		  if (!empty($wp_query->query_vars['s'])) {
		    if (strstr($where, 'DISTINCT')) {}
		    else {
		      $query = str_replace('SELECT', 'SELECT DISTINCT', $query);
		    }
		  }
		  return $query;
		}

	function SE4_exclude_posts($where) {
		global $wp_query;
		if (!empty($wp_query->query_vars['s'])) {
			$excl_list = implode(',', explode(',', trim($this->options['SE4_exclude_posts_list'])));
			$where = str_replace('"', '\'', $where);
			$where = 'AND ('.substr($where, strpos($where, 'AND')+3).' )';
			$where .= ' AND (ID NOT IN ( '.$excl_list.' ))';
		}
	
		$this->SE4_log("ex posts where: ".$where);
		return $where;
	}
	
	//exlude some categories from search - Depracated in 2.3
	function SE4_exclude_categories($where) {
		global $wp_query;
		if (!empty($wp_query->query_vars['s'])) {
			$excl_list = implode(',', explode(',', trim($this->options['SE4_exclude_categories_list'])));
			$where = str_replace('"', '\'', $where);
			$where = 'AND ('.substr($where, strpos($where, 'AND')+3).' )';
			$where .= ' AND (c.category_id NOT IN ( '.$excl_list.' ))';
		}
	
		$this->SE4_log("ex cats where: ".$where);
		return $where;
	}
	
	//join for excluding categories - Depracated in 2.3
	function SE4_exclude_categories_join($join) {
		global $wp_query, $wpdb;
	
		if (!empty($wp_query->query_vars['s'])) {
	
			$join .= "LEFT JOIN $wpdb->post2cat AS c ON $wpdb->posts.ID = c.post_id";
		}
		$this->SE4_log("category join: ".$join);
		return $join;
	}
	
	//search pages (except password protected pages provided by loops)
	function SE4_search_pages($where) {
		global $wp_query;
		if (!empty($wp_query->query_vars['s'])) {
			
			$where = str_replace('"', '\'', $where);
			if ('true' == $this->options['SE4_approved_pages_only']) { 
				$where = str_replace('post_type = \'post\' AND ', 'post_password = \'\' AND ', $where);
			}
			else { // < v 2.1
				$where = str_replace('post_type = \'post\' AND ', '', $where);
			}
		}
	
		$this->SE4_log("pages where: ".$where);
		return $where;
	}
	
	//search excerpts provided by Dennis Turner
	function SE4_search_excerpt($where) {
		global $wp_query;
		if (!empty($wp_query->query_vars['s'])) {
			$where = str_replace('"', '\'', $where);
			$where = str_replace(' OR (post_content LIKE \'%' .
			$wp_query->query_vars['s'] . '%\'', ' OR (post_content LIKE \'%' .
			$wp_query->query_vars['s'] . '%\') OR (post_excerpt LIKE \'%' .
			$wp_query->query_vars['s'] . '%\'', $where);
	   	}
	
		$this->SE4_log("excerpts where: ".$where);
		return $where;
	}
	
	//search drafts
	function SE4_search_draft_posts($where) {
		global $wp_query;
		if (!empty($wp_query->query_vars['s'])) {
			$where = str_replace('"', '\'', $where);
			$where = str_replace(' AND (post_status = \'publish\'', ' AND (post_status = \'publish\' or post_status = \'draft\'', $where);
		}
	
		$this->SE4_log("drafts where: ".$where);
		return $where;
	}
	
	//search attachments
	function SE4_search_attachments($where) {
		global $wp_query;
		if (!empty($wp_query->query_vars['s'])) {
			$where = str_replace('"', '\'', $where);
			$where = str_replace(' AND (post_status = \'publish\'', ' AND (post_status = \'publish\' or post_status = \'attachment\'', $where);
			$where = str_replace('AND post_status != \'attachment\'','',$where);
		}
	
		$this->SE4_log("attachments where: ".$where);
		return $where;
	}
	
	//search comments
	function SE4_search_comments($where) {
	global $wp_query, $wpdb;
		if (!empty($wp_query->query_vars['s'])) {
			$where .= " OR (comment_content LIKE '%" . $wpdb->escape($wp_query->query_vars['s']) . "%') ";
		}
	
		$this->SE4_log("comments where: ".$where);
	
		return $where;
	}
	
	//join for searching comments
	function SE4_comments_join($join) {
		global $wp_query, $wpdb;
	
		if (!empty($wp_query->query_vars['s'])) {
	
			if ('true' == $this->options['SE4_approved_comments_only']) {
				$comment_approved = " AND comment_approved =  '1'";
	  		} else {
				$comment_approved = '';
	    	}
	
			$join .= "LEFT JOIN $wpdb->comments ON ( comment_post_ID = ID " . $comment_approved . ") ";
		}
		$this->SE4_log("comments join: ".$join);
		return $join;
	}
	
	//search metadata
	function SE4_search_metadata($where) {
		global $wp_query, $wpdb;
		if (!empty($wp_query->query_vars['s'])) {
			$where .= " OR meta_value LIKE '%" . $wpdb->escape($wp_query->query_vars['s']) . "%' ";
		}
	
		$this->SE4_log("metadata where: ".$where);
	
		return $where;
	}

	//join for searching metadata
	function SE4_search_metadata_join($join) {
		global $wp_query, $wpdb;
	
		if (!empty($wp_query->query_vars['s'])) {
	
			$join .= "LEFT JOIN $wpdb->postmeta ON $wpdb->posts.ID = $wpdb->postmeta.post_id ";
		}
		$this->SE4_log("metadata join: ".$join);
		return $join;
	}
}

?>