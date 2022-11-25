<?php
/*
Plugin Name: Better Core Video Embeds
Description: A plugin which enhances the core video embeds for Youtube and Vimeo videos by not loading unnecessary scripts until they are needed.
Requires at least: 6.0
Requires PHP: 7.0
Version: 1.0
Author: Highrise Digital
Author URI: https://highrise.digital/
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: better-core-video-embeds
*/

// define variable for path to this plugin file.
define( 'HD_BCVE_LOCATION', dirname( __FILE__ ) );
define( 'HD_BCVE_LOCATION_URL', plugins_url( '', __FILE__ ) );

/**
 * Function to run on plugins load.
 */
function hd_bcve_plugins_loaded() {

	$locale = apply_filters( 'plugin_locale', get_locale(), 'better-core-video-embeds' );
	load_textdomain( 'better-core-video-embeds', WP_LANG_DIR . '/better-core-video-embeds/better-core-video-embeds-' . $locale . '.mo' );
	load_plugin_textdomain( 'better-core-video-embeds', false, plugin_basename( dirname( __FILE__ ) ) . '/languages/' );

}

add_action( 'plugins_loaded', 'hd_bcve_plugins_loaded' );

/**
 * Enqueus the frontend JS for the plugin.
 */
function hd_bcve_enqueue_scripts() {

	// only if the page has a core embed block present.
	if ( has_block( 'core/embed' ) ) {

		// enqueue the front end script to invoking the video embed on image click.
		wp_enqueue_script(
			'better-core-video-embeds-js',
			HD_BCVE_LOCATION_URL . '/assets/js/better-core-video-embeds.js',
			false,
			false,
			true
		);

	}
}

add_action( 'wp_enqueue_scripts', 'hd_bcve_enqueue_scripts' );

/**
 * Register a stylesheet for this block.
 */
function hd_bcve_register_block_style() {

	// register the style for this block.
	wp_register_style(
		'better-core-video-embeds-styles',
		HD_BCVE_LOCATION_URL . '/assets/css/better-core-video-embeds.css'
	);

}

add_action( 'wp_head', 'hd_bcve_register_block_style' );

/**
 * Filters the code embed block output for improved performance on Youtube videos.
 *
 * @param string   $block_content The block content.
 * @param array    $block         The full block, including name and attributes.
 * @param WP_Block $instance      The block instance.
 *
 * @return string  $block_content The block content.
 */
function hd_bcve_render_core_embed_block( $block_content, $block, $instance ) {

	// if the provider slug name is empty or not youtube.
	if ( empty( $block['attrs']['providerNameSlug'] ) ) {
		return $block_content;
	}

	// if for some reason there is no embed URL.
	if ( empty( $block['attrs']['url'] ) ) {
		return $block_content;
	}

	// create a default video id, url and thumbnail url.
	$video_id = '';
	$thumbnail_url = '';

	// grab the video id.
	$video_url = $block['attrs']['url'];
	$parsed_video_url = parse_url( $video_url );

	// switch based on the host.
	switch( $parsed_video_url['host'] ) {

		// for standard youtube URLs
		case 'www.youtube.com':

			// parse the query part of the URL into its arguments.
			parse_str( $parsed_video_url['query'], $video_url_query_args );

			// if we cannot find a youtube video id.
			if ( empty( $video_url_query_args['v'] ) ) {
				return $block_content;
			}

			// set the video id to the v query arg.
			$video_id = $video_url_query_args['v'];

			// get the youtube thumbnail url.
			$thumbnail_url = hd_bcve_get_youtube_video_thumbnail_url( $video_id );

			// break out the switch.
			break;

		// for youtube short urls.
		case 'youtu.be':

			// if we have a path.
			if ( empty( $parsed_video_url['path'] ) ) {
				return $block_content;
			}

			// remove the preceeding slash.
			$video_id = str_replace( '/', '', $parsed_video_url['path'] );

			// get the youtube thumbnail url.
			$thumbnail_url = hd_bcve_get_youtube_video_thumbnail_url( $video_id );

			// break out the switch.
			break;

		// for vimeo urls.
		case 'vimeo.com';
		
			// if we have a path.
			if ( empty( $parsed_video_url['path'] ) ) {
				return $block_content;
			}

			// remove the preceeding slash.
			$video_id = str_replace( '/', '', $parsed_video_url['path'] );

			// get the vimeo thumbnail url for this video.
			$thumbnail_url = hd_bcve_get_vimeo_video_thumbnail_url( $video_id );

			// break out the switch.
			break;

	}

	// if we don't have a video id.
	if ( '' === $video_id ) {
		return $block_content;
	}

	// if we don't have a video thumbnail url.
	if ( '' === $thumbnail_url ) {
		return $block_content;
	}

	// create an array of classes to add to the placeholder image figure.
	$figure_classes = [
		'wp-block-image',
		'hd-bcve-wrapper',
	];

	// if we have classNames on the embed block.
	if ( ! empty( $block['attrs']['className'] ) ) {

		// explode the className string into array.
		$class_names = explode( ' ', $block['attrs']['className'] );

		// merge the class names into the figures classes array.
		$figure_classes = array_merge( $figure_classes, $class_names );

	}

	// if the embed block has an alignment.
	if ( ! empty( $block['attrs']['align'] ) ) {

		// add the alignment class to the figure classes.
		$figure_classes[] = 'align' . $block['attrs']['align'];

	}

	// buffer the output as we need to return not echo.
	ob_start();

	?>

	<figure class="<?php echo esc_attr( implode( ' ', apply_filters( 'hd_bcve_wrapper_classes', $figure_classes, $block ) ) ); ?>" data-id="<?php echo esc_attr( $video_id ); ?>">
		<?php wp_print_styles( 'better-core-video-embeds-styles' ); // output the "block" styles for the thubmnail. ?>
		<div class="play-button"></div>
		<img loading="lazy" class="hd-bcve-thumbnail" src="<?php echo esc_url( $thumbnail_url ); ?>" />
	</figure>

	<template id="hd-bcve-embed-html-<?php echo esc_attr( $video_id ); ?>">
		<?php echo $block['innerHTML']; ?>
	</template>

	<?php

	// return the new block markup.
	return ob_get_clean();

}

add_filter( 'render_block_core/embed', 'hd_bcve_render_core_embed_block', 10, 3 );

/**
 * Return the youtube video thumbnail url.
 *
 * @param string  $video_id The ID of the video.
 * @return string $url      The URL of the thumbnail or an empty string if no URL found.
 */
function hd_bcve_get_youtube_video_thumbnail_url( $video_id = '' ) {

	// if we have no video id.
	if ( '' === $video_id ) {
		return '';
	}

	// get the URL from the transient.
	$image_url = get_transient( 'hd_bcve_' . $video_id );

	// if we don't have a transient.
	if ( false === $image_url ) {

		// set the normal image url.
		$image_url = 'https://img.youtube.com/vi/' . esc_attr( $video_id ) . '/mqdefault.jpg';

		// check if there is a max res image available.
		$max_res_img = wp_remote_get(
			'https://img.youtube.com/vi/' . esc_attr( $video_id ) . '/maxresdefault.jpg'
		);

		// if the request to the hi res image doesn't errors and returns a http 200 response code.
		if ( ( ! is_wp_error( $max_res_img ) ) && ( 200 === wp_remote_retrieve_response_code( $max_res_img ) ) ) {

			// set the name as max res.
			$image_url = 'https://img.youtube.com/vi/' . esc_attr( $video_id ) . '/maxresdefault.jpg';

		}

		// set the transient, storing the image url.
		set_transient( 'hd_bcve_' . $video_id, $image_url, DAY_IN_SECONDS );

	}

	// return the thumbnail url.
	return apply_filters( 'hd_bcve_youtube_video_thumbnail_url', $image_url, $video_id );

}

/**
 * Return the vimeo video thumbnail url.
 *
 * @param string  $video_id The ID of the video.
 * @return string $url      The URL of the thumbnail or an empty string if no URL found.
 */
function hd_bcve_get_vimeo_video_thumbnail_url( $video_id = '' ) {

	// if we have no video id.
	if ( '' === $video_id ) {
		return '';
	}

	// get the URL from the transient.
	$image_url = get_transient( 'hd_bcve_' . $video_id );

	// if we don't have a transient.
	if ( false === $image_url ) {

		// get the video details from the api.
		$video_details = wp_remote_get(
			'https://vimeo.com/api/v2/video/' . esc_attr( $video_id ) . '.json'
		);

		// if the request to the hi res image errors or returns anything other than a http 200 response code.
		if ( ( is_wp_error( $video_details )) && ( 200 !== wp_remote_retrieve_response_code( $video_details ) ) ) {
			return '';
		}

		// grab the body of the response.
		$video_details = json_decode(
			wp_remote_retrieve_body(
				$video_details
			)
		);

		// get the image url from the json.
		$image_url = $video_details[0]->thumbnail_large;

		// set the transient, storing the image url.
		set_transient( 'hd_bcve_' . $video_id, $image_url, DAY_IN_SECONDS );

	}
	
	// return the url.
	return apply_filters( 'hd_bcve_vimeo_video_thumbnail_url', $image_url, $video_id );

}