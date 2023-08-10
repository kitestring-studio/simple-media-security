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
	protected function __construct() {
	}

	public static function init() {
		if ( is_admin() ) {
			$fusion_admin = WP_Fusion::instance()->admin_interfaces;
			add_action( 'edit_attachment', array( $fusion_admin, 'save_meta_box_data' ) );

			add_action( 'add_meta_boxes', array( __CLASS__, 'add_noindex_metabox' ), 10, 2 );
			add_action( 'edit_attachment', array( __CLASS__, 'save_noindex_metabox_data' ), 10, 1 );
		}

		add_filter( 'wpf_meta_box_post_types', array( __CLASS__, 'add_attachment' ) );
		add_action( 'template_redirect', array( __CLASS__, 'custom_media_redirect' ), 30 );

	}

	public static function add_attachment( $post_types ) {
		$post_types['attachment'] = 'attachment';

		return $post_types;
	}

	public static function custom_media_redirect() {
		define( 'SOME_THRESHOLD_SIZE', 1000000 );

		// return if post_type is not media or attachment
		if ( ! is_attachment() || ! class_exists( 'WP_Fusion' ) ) {
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

				$shouldStream = ( strpos( $mime_type, 'video' ) !== false || strpos( $mime_type, 'audio' ) !== false ) && filesize( $file_path ) > SOME_THRESHOLD_SIZE;

				// If the file exists, serve its content
				if ( $file_path && file_exists( $file_path ) ) {
//					$file_contents = file_get_contents( $file_path );
					$file_contents = $shouldStream ? fopen( $file_path, 'rb' ) : file_get_contents( $file_path );

					$noindex = get_post_meta( $post->ID, '_noindex', true );

					// Add noindex header if needed
					if ( $noindex == 'yes' ) {
						header( "X-Robots-Tag: noindex", true );
					}

					if ( $file_contents !== false ) {
						header( "Content-Type: {$mime_type}" );
//						echo $file_contents;
//						readfile( $file_path );
//						exit;

						if ( $shouldStream ) {
							while ( ! feof( $file_contents ) ) {
								echo fread( $file_contents, 8192 );
								flush();
							}
							fclose( $file_contents );
						} else {
							header( 'Content-Length: ' . filesize( $file_path ) );
							readfile( $file_path );
//							echo $file_contents;
						}

						self::serve_file( $file_path, $mime_type );

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

	private static function serve_file( $file_path, $mime_type ) {
		$size  = filesize( $file_path );
		$start = 0;
		$end   = $size - 1;

		if ( isset( $_SERVER['HTTP_RANGE'] ) ) {
			list( $spec, $range ) = explode( '=', $_SERVER['HTTP_RANGE'], 2 );
			if ( $spec != 'bytes' ) {
				header( 'HTTP/1.1 400 Bad Request' );
				exit;
			}
			list( $start, $range_end ) = explode( '-', $range, 2 );
			$start = (int) $start;
			$end   = ( isset( $range_end ) && is_numeric( $range_end ) ) ? (int) $range_end : $end;
		}

		$length = $end - $start + 1;

// Open the file
		$fp = fopen( $file_path, 'rb' );

// Set the headers
		header( 'HTTP/1.1 206 Partial Content' );
		header( "Content-Type: {$mime_type}" );
		header( "Accept-Ranges: bytes" );
		header( "Content-Range: bytes $start-$end/$size" );
		header( "Content-Length: $length" );

// Seek to the start of the range
		fseek( $fp, $start );

// Output the file
		$buffer = 1024 * 8; // Use an 8KB buffer
		while ( ! feof( $fp ) && ( $p = ftell( $fp ) ) <= $end ) {
			if ( $p + $buffer > $end ) {
				$buffer = $end - $p + 1;
			}
			set_time_limit( 0 ); // Reset time limit for big files
			echo fread( $fp, $buffer );
			flush(); // Flush the output buffer
		}

		fclose( $fp );
		exit;
	}

	public static function add_noindex_metabox( $post_type, $post ) {
		if ( $post_type !== 'attachment' ) {
			return;
		}

		add_meta_box(
			'noindex_metabox', // ID of the metabox
			'No Index Option', // Title
			array( __CLASS__, 'noindex_metabox_callback' ), // Callback function
			'attachment', // Post type (media attachment)
			'side' // Context (side panel)
		);
	}

	public static function noindex_metabox_callback( $post ) {
		$noindex = get_post_meta( $post->ID, '_noindex', true );
		echo '<input type="checkbox" id="noindex_checkbox" name="noindex_checkbox" value="yes" ' . checked( $noindex, 'yes', false ) . ' />';
		echo '<label for="noindex_checkbox">Do not index this media</label>';
	}

	static function save_noindex_metabox_data( $post_id ) {

		if ( ! current_user_can( 'edit_post', $post_id )
//		     || ! wp_verify_nonce($_POST['your_nonce_field'], 'your_nonce_action')
		) {
			return;
		}


		$post = get_post( $post_id );
		// Verify post type is attachment
		if ( $post->post_type != 'attachment' ) {
			return;
		}

		// Save the checkbox state
		$noindex_value = isset( $_POST['noindex_checkbox'] ) ? 'yes' : 'no';
		//	wp_update_attachment_metadata($post_id, '_noindex', $noindex_value);
		update_post_meta( $post_id, '_noindex', $noindex_value );
	}

}

add_action( 'init', 'Simple_Media_Security::init' );
