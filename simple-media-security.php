<?php
/*
Plugin Name: Simple Media Security
Plugin URI: https://github.com/kitestring/simple-media-security
Description: Allows media files to be protected by WP Fusion tags
Version: 0.1.0
Requires at least: 6.2
Requires PHP: 7.4
Author: Kitestring Studio
Author URI: https://kitestring.studio
License: GPLv2 or later
Text Domain: simple-download-controls
Network: false
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * a class that adds a hook to this filter to add 'attachment' to the array
 * apply_filters( 'wpf_meta_box_post_types', $post_types )
 */
class Simple_Media_Security {
	protected function __construct() {}

	public static function init() {
		add_filter( 'wpf_meta_box_post_types', array( __CLASS__, 'add_attachment' ) );
		add_action( 'template_redirect', array( __CLASS__, 'custom_media_redirect' ), 30 );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_noindex_metabox' ), 10, 2 );
		add_action( 'edit_attachment', array( __CLASS__, 'save_noindex_metabox_data' ), 10, 1 );
	}

	public static function add_attachment( $post_types ) {
		$post_types['attachment'] = 'attachment';

		return $post_types;
	}

	public static function custom_media_redirect() {
		// return if post_type is not media or attachment
		if ( ! is_attachment() || ! class_exists('WP_Fusion') ) {
			return;
		}

		global $post;
		// Only proceed if the user is logged in
		$fusion = WP_Fusion::instance()->access;

		if ( is_user_logged_in() && $fusion->user_can_access( $post->ID ) ) {
			$mime_type = get_post_mime_type();

			// Check if the attachment is an image, audio, or PDF
			if ( strpos( $mime_type, 'image' ) !== false || strpos( $mime_type, 'audio' ) !== false || $mime_type === 'application/pdf' ) {
				$file_path = get_attached_file( get_the_ID() );

				// If the file exists, serve its content
				if ( $file_path && file_exists( $file_path ) ) {
					$file_contents = file_get_contents( $file_path );

					$noindex = get_post_meta($post->ID, '_noindex', true);

					// Add noindex header if needed
					if ($noindex == 'yes') {
						header("X-Robots-Tag: noindex", true);
					}

					if ( $file_contents !== false ) {
						header( "Content-Type: {$mime_type}" );
						echo $file_contents;
						exit;
					}
				} else {
					// Fallback to a designated 404 image if the file can't be read
					$file_path_404 = '/path/to/your/404/image/on/filesystem.jpg'; // Update this path
					if ( file_exists( $file_path_404 ) ) {
						$file_contents_404 = file_get_contents( $file_path_404 );
						header( 'Content-Type: image/jpeg' ); // or whatever MIME type your 404 image is
						echo $file_contents_404;
						exit;
					}
				}
			}
		}
	}

	public static function add_noindex_metabox( $post_type, $post) {
		if ( $post_type !== 'attachment' ) {
			return;
		}

		add_meta_box(
			'noindex_metabox', // ID of the metabox
			'No Index Option', // Title
			array(__CLASS__, 'noindex_metabox_callback'), // Callback function
			'attachment', // Post type (media attachment)
			'side' // Context (side panel)
		);
	}

	public static function noindex_metabox_callback($post) {
		$noindex = get_post_meta($post->ID, '_noindex', true);
		echo '<input type="checkbox" id="noindex_checkbox" name="noindex_checkbox" value="yes" ' . checked($noindex, 'yes', false) . ' />';
		echo '<label for="noindex_checkbox">Do not index this media</label>';
	}

	static function save_noindex_metabox_data($post_id ) {

		$post = get_post( $post_id );
		// Verify post type is attachment
		if ($post->post_type != 'attachment') {
			return;
		}

		// Save the checkbox state
		$noindex_value = isset($_POST['noindex_checkbox']) ? 'yes' : 'no';
	//	wp_update_attachment_metadata($post_id, '_noindex', $noindex_value);
		update_post_meta($post_id, '_noindex', $noindex_value);
	}

}

add_action('init', 'Simple_Media_Security::init' );
