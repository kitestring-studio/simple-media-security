<?php
/**
	Plugin Name: Simple Media Security
	Plugin URI: https://github.com/kitestring-studio/simple-media-security
	Description: Allows media files to be protected by WP Fusion tags
	Version: 0.1.1
	Requires at least: 6.2
	Requires PHP: 7.4
	Author: Kitestring Studio
	Author URI: https://kitestring.studio
	License: GPLv2 or later
	Text Domain: simple-download-controls
	Network: false
	GitHub Plugin URI: https://github.com/kitestring-studio/simple-media-security
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

		add_action( 'wp_audio_shortcode_override', array( $this, 'maybe_set_media_override' ), 10, 4 );

		add_filter( 'wpf_meta_box_post_types', array( $this, 'add_attachment' ) );
		add_action( 'template_redirect', array( $this, 'custom_media_redirect' ), 30 );

		add_shortcode( 'download', array( $this, 'download_shortcode' ) );
	}

	public function modify_attachment_url( $url, $post_id ) {
		$post = get_post( $post_id );
		if ( $post->post_type !== 'attachment' ) {
			return $url;
		}

		// remove because this should only run for the [audio] shortcode
		remove_filter( 'wp_get_attachment_url', array( $this, 'modify_attachment_url' ), 10 );

		return get_permalink( $post );
	}

	/**
	 * if this is a protected media file, set a hook to use in the future
	 *
	 * @param $html
	 * @param $attr
	 * @param $content
	 * @param $instance
	 *
	 * @return string
	 */
	public function maybe_set_media_override( $html, $attr, $content, $instance ): string {
		if ( ! isset( $attr['mp3'] ) ) {
			return "";
		}

		$mp3_url = $attr['mp3'];
		// get $post from $mp3_url
		$post_id = url_to_postid( $mp3_url );

		// @TODO I need a method to check weather this file should even be protected. don't mess with normal files.
		// eg if $this->should_protect_media_file( $post_id )
		$is_protected = true;

		if ( $is_protected ) {
			add_filter( 'wp_get_attachment_url', array( $this, 'modify_attachment_url' ), 10, 2 );
		}

		return "";
	}

	public function add_attachment( $post_types ) {
		$post_types['attachment'] = 'attachment';

		return $post_types;
	}

	public function download_shortcode( $atts ) {
		// Extracting the slug attribute
		$atts = shortcode_atts( array( 'slug' => '' ), $atts, 'download' );
		$slug = $atts['slug'];

		// Querying for the attachment by slug
		$args        = array(
			'name'        => $slug,
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => 1
		);
		$attachments = get_posts( $args );

		if ( $attachments ) {
			$attachment = $attachments[0];
			$url        = get_permalink( $attachment );
			$extension  = $this->get_attachment_extension( $attachment );

			$title = get_the_title( $attachment );

			// Detecting the file type and selecting an icon
			$icon = '';
			if ( $extension === 'pdf' ) {
				$icon = '<i class="fas fa-file-pdf"></i>'; // Font Awesome PDF icon
			} elseif ( in_array( $extension, array( 'mp3', 'wav', 'ogg' ) ) ) {
				$icon = '<i class="fas fa-file-audio"></i>'; // Font Awesome audio icon
			}

			return "<a href='{$url}' download>{$icon} Download $title</a>";
		}

		return 'File not found.';
	}

	/**
	 * @param $attachment
	 *
	 * @return array
	 */
	private function get_attachment_extension( $attachment ): string {
		$file_url = wp_get_attachment_url( $attachment->ID );

		return wp_check_filetype( $file_url )['ext'];
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
		$ext          = $this->get_attachment_extension( $post );
		$this->set_headers( $file_path, $mime_type, $post, $ext );

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

	private function set_headers( $file_path, $mime_type, $post, $extension ) {
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . filesize( $file_path ) );

		// @TODO set content-disposition to attachment/inline conditionally? only streaming audio should be inline?
		header( 'Content-Disposition: attachment; filename="' . basename( get_the_title( $post->ID ) ) . '.' . $extension . '"' );
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
