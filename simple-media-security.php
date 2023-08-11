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
	private static int $chunk_threshold = 512000; // 500KB
	private WP_Fusion $wp_fusion;
	private $fusion_access;
	private $fusion_admin;

	public function __construct() {
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
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( $post->post_type != 'attachment' ) {
			return;
		}

		$noindex_value = isset( $_POST['noindex_checkbox'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_noindex', $noindex_value );
	}

	public function init() {
		if ( ! class_exists( 'WP_Fusion' ) ) {
			return;
		}

		$wp_fusion = new WP_Fusion(); // Assume WP_Fusion class is properly defined and instantiated

		$this->wp_fusion     = $wp_fusion;
		$this->fusion_access = $wp_fusion->instance()->access;
		$this->fusion_admin  = $wp_fusion->instance()->admin_interfaces;
		if ( is_admin() ) {
			add_action( 'edit_attachment', array( $this->fusion_admin, 'save_meta_box_data' ) );
			add_action( 'add_meta_boxes', array( $this, 'add_noindex_metabox' ), 10, 2 );
			add_action( 'edit_attachment', array( $this, 'save_noindex_metabox_data' ), 10, 1 );
		}

		add_filter( 'wpf_meta_box_post_types', array( $this, 'add_attachment' ) );
		add_action( 'template_redirect', array( $this, 'custom_media_redirect' ), 30 );
	}

	public function add_attachment( $post_types ) {
		$post_types['attachment'] = 'attachment';

		return $post_types;
	}

	public function custom_media_redirect() {
		if ( ! $this->is_valid_redirect() ) {
			return;
		}

		$post = $GLOBALS['post'];
		if ( ! $this->can_user_access( $post ) ) {
			return;
		}

		$file_path = get_attached_file( get_the_ID() );
		if ( ! $this->file_exists( $file_path ) ) {
			$this->return_404();

			return;
		}

		$mime_type    = get_post_mime_type( $post );
		$shouldStream = $this->should_stream( $file_path, $mime_type );
		$this->set_headers( $file_path, $mime_type, $post );

		$file_contents = $this->get_file_contents( $file_path, $shouldStream );
		if ( $file_contents === false ) {
			$this->return_404();

			return;
		}

		$rangeInfo = $this->get_range_info( filesize( $file_path ) );
		if ( $rangeInfo ) {
			$this->output_range_content( $file_path, $rangeInfo, $shouldStream );
		} else {
			$this->output_entire_content( $shouldStream, $file_contents, $file_path );
		}
	}

	private function is_valid_redirect() {
		return ! is_admin() && is_attachment();
	}

	private function can_user_access( $post ) {
		$has_access = $this->fusion_access->user_can_access( $post->ID );
		if ( ! $has_access ) {
			wp_die( __( 'You do not have permission to view this file.' ) );

			return false;
		}

		return true;
	}

	private function file_exists( $file_path ) {
		return file_exists( $file_path ) && is_readable( $file_path );
	}

	private function return_404() {
		status_header( 404 );
		nocache_headers();
		include( get_query_template( '404' ) );
		exit;
	}

	private function should_stream( $file_path, $mime_type ) {
		return strpos( $mime_type, 'video/' ) !== false || filesize( $file_path ) > self::$chunk_threshold;
	}

	private function set_headers( $file_path, $mime_type, $post ) {
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'Content-Disposition: inline; filename="' . basename( get_the_title( $post->ID ) ) . '.mp3"' );
//		header('Cache-Control: must-revalidate');
//		header('Pragma: public');
	}

	private function get_file_contents( $file_path, $shouldStream ) {
		return $shouldStream ? null : fopen( $file_path, 'rb' );
	}

	private function get_range_info( $file_size ) {
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? $_SERVER['HTTP_RANGE'] : null;
		if ( $range ) {
			$from = $to = 0;
			list( , $range ) = explode( '=', $range, 2 );
			if ( strpos( $range, ',' ) !== false ) {
				header( 'HTTP/1.1 416 Requested Range Not Satisfiable' );
				exit;
			}
			if ( $range == '-' ) {
				$from = max( 0, $file_size - intval( substr( $range, 1 ) ) );
			} else {
				$range = explode( '-', $range );
				$from  = $range[0];
				$to    = ( isset( $range[1] ) && is_numeric( $range[1] ) ) ? $range[1] : $file_size - 1;
			}
			$from = (int) max( $from, 0 );
			$to   = (int) min( $to, $file_size - 1 );

			return compact( 'from', 'to', 'file_size' );
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
			$file_contents = fopen( $file_path, 'rb' );
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

}

$media_security = new Simple_Media_Security();
add_action( 'init', array( $media_security, 'init' ) );
