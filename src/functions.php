<?php
/**
 * @package koko-analytics
 * @license GPL-3.0+
 * @author Danny van Kooten
 */
namespace KokoAnalytics;

use WP_Query;

function maybe_collect_request() {
	// since we call this function (early) on every AJAX request, detect our specific request here
	// this allows us to short-circuit a bunch of unrelated AJAX stuff and gain a lot of performance
	if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'koko_analytics_collect' || ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
		return;
	}

	collect_request();
}

function collect_request() {
	if ( ! isset( $_GET['e'] ) ) {
		$data = array(
			'p',                // type indicator
			(int) $_GET['p'],   // post ID
			(int) $_GET['nv'],  // new visitor?
			(int) $_GET['up'],  // unique pageview?
			$_GET['r'] ?? '',   // referrer URL
		);
	} else {
		$data = array(
			'e',            // type indicator
			$_GET['e'],     // event name
			$_GET['p1'],    // event param 1
			$_GET['p2'],    // event param 2
			$_GET['v'],    // event value
		);
	}

	$success = isset( $_GET['test'] ) ? test_collect_in_file() : collect_in_file( $data );

	// set OK headers & prevent caching
	if ( ! $success ) {
		header( $_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error' );
	} else {
		header( $_SERVER['SERVER_PROTOCOL'] . ' 200 OK' );
	}
	header( 'Content-Type: image/gif' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
	header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
	header_remove( 'Last-Modified' );

	// indicate that we are not tracking user specifically, see https://www.w3.org/TR/tracking-dnt/
	header( 'Tk: N' );

	// set cookie server-side if requested (eg for AMP requests)
	if ( isset( $_GET['p'] ) && isset( $_GET['sc'] ) && (int) $_GET['sc'] === 1 ) {
		$posts_viewed = isset( $_COOKIE['_koko_analytics_pages_viewed'] ) ? explode( ',', $_COOKIE['_koko_analytics_pages_viewed'] ) : array( '' );
		if ( (int) $_GET['nv'] ) {
			$posts_viewed[] = (int) $_GET['p'];
		}
		$cookie = join( ',', $posts_viewed );
		setcookie( '_koko_analytics_pages_viewed', $cookie, time() + 6 * HOUR_IN_SECONDS, '/' );
	}

	// 1px transparent GIF, needs to be an actual image to make sure browser fires the onload event
	echo base64_decode( 'R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==' );
	exit;
}

function get_buffer_filename() {
	if ( defined( 'KOKO_ANALYTICS_BUFFER_FILE' ) ) {
		return KOKO_ANALYTICS_BUFFER_FILE;
	}

	$uploads = wp_upload_dir( null, false );
	return rtrim( $uploads['basedir'], '/' ) . '/pageviews.php';
}

function collect_in_file( array $data ) {
	$filename = get_buffer_filename();

	// if file does not yet exist, add PHP header to prevent direct file access
	if ( ! file_exists( $filename ) ) {
		$content = '<?php exit; ?>' . PHP_EOL;
	} else {
		$content = '';
	}

	// append data to file
	$line     = join( ',', $data ) . PHP_EOL;
	$content .= $line;
	return file_put_contents( $filename, $content, FILE_APPEND );
}

function test_collect_in_file() {
	$filename = get_buffer_filename();
	if ( file_exists( $filename ) ) {
		return is_writable( $filename );
	}

	$dir = dirname( $filename );
	return is_writable( $dir );
}

function get_settings() {
	$default_settings = array(
		'use_cookie' => 1,
		'exclude_user_roles' => array(),
		'prune_data_after_months' => 5 * 12, // 5 years
		'default_view' => 'last_28_days',
	);
	$settings         = (array) get_option( 'koko_analytics_settings', array() );
	$settings         = array_merge( $default_settings, $settings );
	return $settings;
}

function get_most_viewed_posts( array $args ) {
	global $wpdb;
	$default_args = array(
		'number'    => 5,
		'post_type' => 'post',
		'show_date' => false,
		'days'    => 30,
	);

	$args              = array_merge( $default_args, $args );
	$args['post_type'] = is_array( $args['post_type'] ) ? $args['post_type'] : explode( ',', $args['post_type'] );
	$args['post_type'] = array_map( 'trim', $args['post_type'] );
	$start_date        = gmdate( 'Y-m-d', strtotime( "-{$args['days']} days" ) );
	$end_date          = gmdate( 'Y-m-d', strtotime( 'tomorrow midnight' ) );
	$post_types        = join(',', array_map( function( $v ) {
		return "'" . esc_sql( $v ) . "'";
	}, $args['post_type'] ) );
	$sql               = $wpdb->prepare( "SELECT p.id, SUM(visitors) As visitors, SUM(pageviews) AS pageviews FROM {$wpdb->prefix}koko_analytics_post_stats s JOIN {$wpdb->posts} p ON s.id = p.id WHERE p.id > 0 AND s.date >= %s AND s.date <= %s AND p.post_type IN ($post_types) AND p.post_status = 'publish' GROUP BY s.id ORDER BY pageviews DESC LIMIT 0, %d", array( $start_date, $end_date, $args['number'] ) );
	$results           = $wpdb->get_results( $sql );
	if ( empty( $results ) ) {
		return array();
	}

	$ids = wp_list_pluck( $results, 'id' );
	$r   = new WP_Query(
		array(
			'posts_per_page'      => -1,
			'post__in'            => $ids,
			'orderby'             => 'post__in',
			'post_type'           => $args['post_type'],
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		)
	);
	return $r->posts;
}

function admin_bar_menu( $wp_admin_bar ) {
	// only show on frontend
	if ( is_admin() ) {
		return;
	}

	// only show for users who can access statistics page
	if ( ! current_user_can( 'view_koko_analytics' ) ) {
		return;
	}

	$wp_admin_bar->add_node(
		array(
			'parent' => 'site-name',
			'id' => 'koko-analytics',
			'title' => esc_html__( 'Analytics', 'koko-analytics' ),
			'href' => admin_url( '/index.php?page=koko-analytics' ),
		)
	);
}

function widgets_init() {
	require KOKO_ANALYTICS_PLUGIN_DIR . '/src/class-widget-most-viewed-posts.php';
	register_widget( 'KokoAnalytics\Widget_Most_Viewed_Posts' );
}

function get_realtime_pageview_count( $since = null ) {
	$since  = $since !== null ? $since : strtotime( '-5 minutes' );
	$counts = (array) get_option( 'koko_analytics_realtime_pageview_count', array() );
	$sum    = 0;
	foreach ( $counts as $timestamp => $pageviews ) {
		if ( $timestamp > $since ) {
			$sum += $pageviews;
		}
	}
	return $sum;
}

function using_custom_endpoint() {
	if ( defined( 'KOKO_ANALYTICS_CUSTOM_ENDPOINT' ) ) {
		return KOKO_ANALYTICS_CUSTOM_ENDPOINT;
	}

	return get_option( 'koko_analytics_use_custom_endpoint', false );
}

function install_and_test_custom_endpoint() {
	$endpoint_installer = new Endpoint_Installer();
	$endpoint_installer->run();
}
