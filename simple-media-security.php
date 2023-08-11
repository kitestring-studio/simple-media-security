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
	private static int $chunk_threshold;

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
//		self::$chunk_threshold = 512000; // 500KB
		self::$chunk_threshold = 8192;

		// return if post_type is not media or attachment
		if ( ! is_attachment() || ! class_exists( 'WP_Fusion' ) ) {
			return;
		}

		global $post;

		$fusion = WP_Fusion::instance()->access;
		if ( ! is_user_logged_in() || ! $fusion->user_can_access( $post->ID ) ) {
			return;
		}

		$mime_type = get_post_mime_type( $post );
		if ( strpos( $mime_type, 'image' ) === false && strpos( $mime_type, 'audio' ) === false && $mime_type !== 'application/pdf' ) {
			return;
		}

		$file_path = get_attached_file( get_the_ID() );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			self::return_404();

			return;
		}

		$shouldStream = ( strpos( $mime_type, 'video' ) !== false || strpos( $mime_type, 'audio' ) !== false ) && filesize( $file_path ) > self::$chunk_threshold;

		if ( $shouldStream ) {
			$file_contents = fopen( $file_path, 'rb' );
		} else {
			$file_contents = true; // Set this to true to indicate that the file should be read normally
		}

		if ( $file_contents === false ) {
			self::return_404();

			return;
		}

		header( "Content-Type: {$mime_type}" );
		header( 'Content-Disposition: inline; filename="' . basename( $file_path ) . '"' );

		$noindex = get_post_meta( $post->ID, '_noindex', true );
		if ( $noindex == 'yes' ) {
			header( "X-Robots-Tag: noindex", true );
		}

		$rangeHeader = isset( $_SERVER['HTTP_RANGE'] ) ? $_SERVER['HTTP_RANGE'] : '';
		$rangeInfo   = self::get_range_info( $rangeHeader, filesize( $file_path ) );

		if ( $rangeInfo ) {
			self::output_range_content( $file_path, $rangeInfo, $shouldStream );
		} else {
			self::output_entire_content( $shouldStream, $file_contents, $file_path );
		}
	}

	/**
	 * @return void
	 */
	protected static function return_404(): void {
		// Fallback to a designated 404 image if the file can't be read
		$file_path_404 = '/path/to/your/404/image/on/filesystem.jpg'; // Update this path
		if ( file_exists( $file_path_404 ) ) {
			$file_contents_404 = file_get_contents( $file_path_404 );
			header( 'Content-Type: image/jpeg' ); // or whatever MIME type your 404 image is
			echo $file_contents_404;
			exit;
		}
	}

	public static function get_range_info( $httpRange, $file_size ) {
		$range = '';
		if ( $httpRange ) {
			list( $param, $range ) = explode( '=', $httpRange );
			if ( strtolower( trim( $param ) ) != 'bytes' ) {
				return null;
			}
		}

		if ( $range ) {
			list( $from, $to ) = explode( '-', $range );
			$from = intval( $from );
			$to   = $to ? intval( $to ) : $file_size - 1;

			if ( $to < $from || $from < 0 || $to >= $file_size ) {
				return null;
			}

			return array( 'from' => $from, 'to' => $to );
		}

		return null;
	}

	public static function output_range_content( $file_path, $rangeInfo, $shouldStream ) {
		$file_size = filesize( $file_path );
		$from      = $rangeInfo['from'];
		$to        = $rangeInfo['to'];

		header( 'HTTP/1.1 206 Partial Content' );
		header( "Content-Range: bytes $from-$to/$file_size" );
		header( 'Content-Length: ' . ( $to - $from + 1 ) );

		if ( $shouldStream ) {
			$file_contents = fopen( $file_path, 'rb' );
			fseek( $file_contents, $from );
			while ( ! feof( $file_contents ) && ( $p = ftell( $file_contents ) ) <= $to ) {
				echo fread( $file_contents, min( 8192, $to - $p + 1 ) );
				flush();
			}
			fclose( $file_contents );
		} else {
			$fp = fopen( $file_path, 'rb' );
			fseek( $fp, $from );
			while ( ! feof( $fp ) && ( $p = ftell( $fp ) ) <= $to ) {
				echo fread( $fp, min( 8192, $to - $p + 1 ) );
				flush();
			}
			fclose( $fp );
		}
	}

	/**
	 * @param bool $shouldStream
	 * @param  $file_contents
	 * @param string $file_path
	 *
	 * @return void
	 */
	protected static function output_entire_content( bool $shouldStream, $file_contents, string $file_path ): void {
		if ( $shouldStream ) {
			while ( ! feof( $file_contents ) ) {
				echo fread( $file_contents, 8192 );
				flush();
			}
			fclose( $file_contents );
		} else {
			header( 'Content-Length: ' . filesize( $file_path ) );
			readfile( $file_path ); // efffectively the same as echo $file_contents;
		}
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
