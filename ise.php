<?php

/*
Plugin Name: WP-ISE Lite
Plugin URI: http://www.wp-ise.com
Description: Improved Search Engine for WordPress - Lite Edition. There are also Pro and Ultimate editions, <a href="http://www.wp-ise.com/compare-wdp/">visit our dedicated page to compare editions</a>.
Version: 1.0.1
Author: WP-ISE
Author URI: http://www.wp-ise.com
License: GPL2
*/

function ise_requirements(){

	global $wp_version;
	$plugin = plugin_basename( __FILE__ );
	$plugin_data = get_plugin_data( __FILE__, false );

	if ( version_compare($wp_version, "3.3", "<" ) ) {
		if( is_plugin_active($plugin) ) {
			deactivate_plugins( $plugin );
			wp_die( "'".$plugin_data['Name']."' requires WordPress 3.3 or higher, and has been deactivated! Please upgrade WordPress and try again.<br /><br />Back to <a href='".admin_url()."'>WordPress admin</a>." );
		}
	}
	if (!extension_loaded('pdo_mysql')) {
		if( is_plugin_active($plugin) ) {
			deactivate_plugins( $plugin );
			wp_die( "'".$plugin_data['Name']."' requires PDO_MYSQL, and has been deactivated! Please install PDO driver and try again.<br /><br />Back to <a href='".admin_url()."'>WordPress admin</a>." );
		}
	}
	if (version_compare(PHP_VERSION, '5.0.0', '<=')) {
		if( is_plugin_active($plugin) ) {
			deactivate_plugins( $plugin );
			wp_die( "'".$plugin_data['Name']."' requires at least PHP version 5.0.0 , and has been deactivated!<br /><br />Back to <a href='".admin_url()."'>WordPress admin</a>." );
		}
	}

}

add_action( 'admin_init', 'ise_requirements' );

class sql {

	public $db;

	public static function query($query, $bind) {
		$db = new db();
		if(!empty($bind['multi'])) {
			unset($bind['multi']);
			foreach ($bind as $key => $value) {
				if(is_array($value)) continue;
				foreach ($bind as $k => &$v) {
					if(is_array($v)) {
						$v[$key] = $value;
					}
				}
				unset($bind[$key]);
			}
			$x = array();
			foreach ($bind as $values) {
				$x[] = $db->query($query, $values);
			}
			return $x;
		}
		else {
			return $db->query($query, $bind);
		}
	}

	public static function select($query, $bind, $type) {
		$db = new db();
		return $db->select($query, $bind, $type);
	}

	public static function insert($query, $bind) {
		$db = new db();
		$db->query($query, $bind);
		return $db->lastInsertId();
	}

}

class db extends PDO {

	public function __construct() {
		if(false !== strpos(DB_HOST, ':')) {
			list($host, $port) = explode(':', DB_HOST);

		}
		else {
			$port = 3306;
			$host = DB_HOST;
		}
		$socket = (is_numeric($port)) ? false : true;
		$dns = (false === $socket) ? 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . DB_NAME : 'mysql:dbname=' . DB_NAME . ';unix_socket=' . $port;
		parent::__construct($dns, DB_USER, DB_PASSWORD);
		if(defined(DB_CHARSET)) $this->query("SET NAMES '" . DB_CHARSET . "'");
	}

	public function query($query, $bind = array()) {
		if(!is_array($bind)) {
			$bind = array($bind);
		}
		$stmt = $this->prepare($query);
		$stmt->execute($bind);
		return $stmt;
	}

	public function select($query, $bind, $type) {
		$stmt = $this->query($query, $bind);
		$result = array();
		switch ($type) {
			case 'one' :
				$result = $stmt->fetchColumn(0);
				break;
			case 'pairs' :
				$result = array();
				while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
					$result[$row[0]] = $row[1];
				}
				break;
		}
		return $result;
	}

}

function my_own_search(&$wp_query) {

	if($wp_query->is_search) {
		$r = do_the_search($wp_query->query_vars);

		$firstVal = ($r['page'] - 1) * $r['per_page'];
		$results = array_slice($r['results'], $firstVal, $r['per_page']);
		$wp_query->found_posts = count($r['results']);

			$matching_ids = array();
			if(empty($results)) $results[] = -1;

			$wp_query->query_vars['my_search_terms'] = $wp_query->query_vars['s'];
			if($results) unset($wp_query->query_vars['s']);
			if(isset($wp_query->query_vars['paged'])) {
				$wp_query->query_vars['my_paged'] = $wp_query->query_vars['paged'];
				if($results) unset($wp_query->query_vars['paged']);
			}
			$wp_query->query_vars['post__in'] = $results;
			$wp_query->query_vars['my_num_matches'] = count($r['results']);

	}
}

add_action('parse_query', 'my_own_search', 10, 1);
add_filter('the_posts', 'search_filter_posts_order', 10, 2);

function do_the_search($arguments) {
	global $wpdb;
	$defaults = array('search_using' => 'any', 'paged' => 1, 'posts_per_page' => 0, 'showposts' => 0);
	$arguments = wp_parse_args($arguments, $defaults);

	$search = (false === strpos($arguments['s'], '+')) ? $arguments['s'] : implode(' ', explode('+', $arguments['s']));

	$page = isset($arguments['paged']) && (intval($arguments['paged']) > 0) ? intval($arguments['paged']) : 1;

	$per_page = max(array($arguments['posts_per_page'], $arguments['showposts']));
	if($per_page < 1) {
		$per_page = get_option('posts_per_page');
	}

	$Qry = "SELECT id, post_title AS title FROM " . $wpdb->prefix . "posts WHERE post_title LIKE '%" . $search . "%' AND post_type = 'post'";
	$strictResult = sql::select($Qry, array(), 'pairs');
	$weight = array();
	$eSearch = explode(' ', strtolower($search));
	$multi = count($eSearch);
	if($multi > 1) {
		$args = array();
		foreach ($eSearch as $term) {
			$args[] = " post_title LIKE '%" . $term . "%'";
		}
		$Qry = "SELECT id, post_title AS title FROM " . $wpdb->prefix . "posts WHERE " . implode(' AND', $args) . " AND post_type = 'post'";
		$fuzzyResult = sql::select($Qry, array(), 'pairs');
		if(empty($fuzzyResult)) {
			$Qry = "SELECT id, post_title AS title FROM " . $wpdb->prefix . "posts WHERE (" . implode(' OR', $args) . ") AND post_type = 'post'";
			$dummyResult = sql::select($Qry, array(), 'pairs');
		}
	}
	if(!empty($strictResult)) {
		foreach ($strictResult as $id => &$row) {
			$weight[$id] = 0;
			if(strtolower($row) == strtolower($search)) {
				$weight[$id] += 500 + ($multi * 10);
			}
			else {
				$weight[$id] += 75 + ($multi * 10);
			}
			unset($fuzzyResult[$id]);
			unset($dummyResult[$id]);
		}
	}
	if(!empty($fuzzyResult)) {
		foreach ($fuzzyResult as $id => &$row) {
			$weight[$id] = 50 + $multi * 10;
			unset($dummyResult[$id]);
		}
	}
	elseif(!empty($dummyResult)) {
		foreach ($dummyResult as $id => &$row) {
			$m = 0;
			foreach ($eSearch as $term) {
				if(false !== stripos($row->title, $term)) $m += 10;
			}
			$weight[$id] = $m * 10;
		}
	}
	unset($strictResult);
	unset($fuzzyResult);
	unset($dummyResult);

	$Qry = "SELECT id, post_excerpt AS excerpt FROM " . $wpdb->prefix . "posts WHERE post_excerpt LIKE :search AND post_type = 'post' AND post_status = 'publish'";
	$stmt = sql::query($Qry, array(':search' => '%' . $search . '%'));
	while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
		$content = strip_tags(str_replace(array('[', ']'), array('<', '>'), $row->excerpt));
		$content = strip_tags($content);
		preg_match_all("#" . preg_quote($search) . "#i", $content, $matches);
		$m = (!empty($matches[0])) ? count($matches[0]) : 0;
		$weight[$row->id] += 25 + $m * $multi;
	}

	$Qry = "SELECT id, post_content AS content FROM " . $wpdb->prefix . "posts WHERE post_content LIKE :search AND post_type = 'post' AND post_status = 'publish'";
	$stmt = sql::query($Qry, array(':search' => '%' . $search . '%'));
	while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
		$content = strip_tags(str_replace(array('[', ']'), array('<', '>'), $row->content));
		$content = strip_tags($content);
		preg_match_all("#" . preg_quote($search) . "#i", $content, $matches);
		$m = (!empty($matches[0])) ? count($matches[0]) : 0;
		$weight[$row->id] += 5 + $m * $multi;
	}

	if($multi > 1) {
		$args = array();
		foreach ($eSearch as $term) {
			$args[] = " post_excerpt LIKE '%" . $term . "%'";
		}
		$Qry = "SELECT id, post_excerpt AS excerpt FROM " . $wpdb->prefix . "posts WHERE " . implode(' AND', $args) . " AND post_type = 'post' AND post_status = 'publish'";
		$stmt = sql::query($Qry, array());
		while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
			$content = strip_tags(str_replace(array('[', ']'), array('<', '>'), $row->excerpt));
			$content = strip_tags($content);
			preg_match_all("#" . preg_quote($search) . "#i", $content, $matches);
			$m = (!empty($matches[0])) ? count($matches[0]) : 0;
			$weight[$row->id] += $m * $multi * 1.25;
		}
		if(empty($fuzzyResult)) {
			$Qry = "SELECT id, post_excerpt AS excerpt FROM " . $wpdb->prefix . "posts WHERE (" . implode(' OR', $args) . ") AND post_type = 'post' AND post_status = 'publish'";
			$stmt = sql::query($Qry, array());
			while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
				$content = strip_tags(str_replace(array('[', ']'), array('<', '>'), $row->excerpt));
				$content = strip_tags($content);
				preg_match_all("#" . preg_quote($search) . "#i", $content, $matches);
				$m = (!empty($matches[0])) ? count($matches[0]) : 0;
				$weight[$row->id] += $m * $multi * .75;
			}
		}

		$args = array();
		foreach ($eSearch as $term) {
			$args[] = " post_content LIKE '%" . $term . "%'";
		}
		$Qry = "SELECT id, post_content AS content FROM " . $wpdb->prefix . "posts WHERE " . implode(' AND', $args) . " AND post_type = 'post' AND post_status = 'publish'";
		$stmt = sql::query($Qry, array());
		while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
			$content = strip_tags(str_replace(array('[', ']'), array('<', '>'), $row->content));
			$content = strip_tags($content);
			preg_match_all("#" . preg_quote($search) . "#i", $content, $matches);
			$m = (!empty($matches[0])) ? count($matches[0]) : 0;
			$weight[$row->id] += $m * $multi;
		}
		if(empty($fuzzyResult)) {
			$Qry = "SELECT id, post_content AS content FROM " . $wpdb->prefix . "posts WHERE (" . implode(' OR', $args) . ") AND post_type = 'post' AND post_status = 'publish'";
			$stmt = sql::query($Qry, array());
			while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
				$content = strip_tags(str_replace(array('[', ']'), array('<', '>'), $row->content));
				$content = strip_tags($content);
				preg_match_all("#" . preg_quote($search) . "#i", $content, $matches);
				$m = (!empty($matches[0])) ? count($matches[0]) : 0;
				$weight[$row->id] += $m * $multi * .5;
			}
		}

	}
	arsort($weight);

	$i = 0;
	foreach ($weight as $k => $w) {
		if(empty($w)) {
			unset($weight[$k]);
		}
		else {
			$weight[$k] = $i++;
		}
	}

	$weight = array_flip($weight);
	return array('results' => $weight, 'per_page' => $per_page, 'page' => $page);
}

function my_filter_mode($found_posts, &$wp_query = null) {
	if(!is_null($wp_query)) {
		if(isset($wp_query->query_vars['my_num_matches'])) {
			$found_posts = intval($wp_query->query_vars['my_num_matches']);
		}
		if(isset($wp_query->query_vars['my_search_terms'])) {
			$wp_query->query_vars['s'] = $wp_query->query_vars['my_search_terms'];
		}
		if(isset($wp_query->query_vars['my_paged'])) {
			$wp_query->query_vars['paged'] = $wp_query->query_vars['my_paged'];
		}
	}

	return $found_posts;
}

function search_filter_posts_order($posts, &$wp_query = null) {
	if(!is_null($wp_query) && isset($wp_query->query_vars['post__in']) && isset($wp_query->query_vars['my_num_matches'])) {
		$id_order = $wp_query->query_vars['post__in'];
		$reordered_posts = array();
		foreach ($id_order as $elem) {
			foreach ($posts as $post) {
				if($post->ID == $elem) {
					$reordered_posts[] = $post;
					break;
				}
			}
		}
		return $reordered_posts;
	}
	return $posts;
}

add_filter('found_posts', 'my_filter_mode', 10, 2);