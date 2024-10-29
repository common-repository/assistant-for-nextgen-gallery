<?php
/*
Plugin Name: Assistant for NextGEN Gallery
Version: 1.0.9
Description: Image Uploader, Optimizer (resize, auto rotate, watermarks), add / delete NextGEN galleries. All from your desktop system (Windows or MACOS).
Author: 48hmorris
Plugin URI: https://nextgenassistant.com
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

function nextgenassistant_trim_name( $name ) {
	// Remove path information and dots around the filename, to prevent uploading
	// into different directories or replacing hidden system files.
	// Also remove control characters and spaces (\x00..\x20) around the filename:
	$name = trim( basename( stripslashes( $name ) ), ".\x00..\x20" );
	// Use a timestamp for empty filenames:
	if ( ! $name ) {
		$name = str_replace( '.', '-', microtime( true ) );
	}
	return $name;
}

define( 'NEXTGEN_ASSISTANT_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'NEXTGEN_ASSISTANT_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'NEXTGEN_ASSISTANT_PLUGIN_CHUNKS_DIR', NEXTGEN_ASSISTANT_PLUGIN_DIR . 'chunks' );
define( 'NEXTGEN_ASSISTANT_PLUGIN_CHUNKS_LOCK', NEXTGEN_ASSISTANT_PLUGIN_CHUNKS_DIR . '/' . '.nextgenassistant_lock' );
define( 'NEXTGEN_ASSISTANT_PLUGIN_VERSION', '1.0.0' );
define( 'NEXTGEN_ASSISTANT_NAMESPACE', 'nextgenassistant/v' . NEXTGEN_ASSISTANT_PLUGIN_VERSION );
define( 'NEXTGEN_ASSISTANT_BASE_UPLOAD', 'upload' );
define( 'NEXTGEN_ASSISTANT_BASE_CONTROL', 'control' );



add_action( 'plugins_loaded', 'nextgen_assistant_init' );

function nextgenassistant_jwt_auth_token_before_dispatch( $data ) {
	$data['success'] = 'true';
	return $data;
}

function nextgen_assistant_init() {
	if ( ! is_dir( NEXTGEN_ASSISTANT_PLUGIN_CHUNKS_DIR ) ) {
		mkdir( NEXTGEN_ASSISTANT_PLUGIN_CHUNKS_DIR, 0777, true );
	}
	if ( ! file_exists( ( NEXTGEN_ASSISTANT_PLUGIN_CHUNKS_LOCK ) ) ) {
		$lock_file = fopen( NEXTGEN_ASSISTANT_PLUGIN_CHUNKS_LOCK, 'wb+' );
		fclose( $lock_file );
	}
	add_filter( 'jwt_auth_token_before_dispatch', 'nextgenassistant_jwt_auth_token_before_dispatch' );
	add_action( 'wp_enqueue_scripts', 'nextgen_assistant_enqueue_scripts' );
}

function nextgen_assistant_enqueue_scripts() {
	// from: http://v2.wp-api.org/guide/authentication/
	wp_enqueue_script( 'wp-api' );
	wp_localize_script(
		'wp-api',
		'WP_API_Settings',
		array(
			'root'        => esc_url_raw( rest_url() ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'title'       => 'Media Title',
			'description' => 'Media Description',
			'alt_text'    => 'Media Alt Text',
			'caption'     => 'Media Caption'
		)
	);
}

/**
 * register custom endpoint, see http://v2.wp-api.org/extending/adding/
 */
add_action( 'rest_api_init', function () {
	register_rest_route( NEXTGEN_ASSISTANT_NAMESPACE, '/' . NEXTGEN_ASSISTANT_BASE_UPLOAD , array(
		'methods' => 'POST',
		'callback' => 'nextgenassistant_upload',
		'permission_callback' => function () {
			// return current_user_can( 'upload_files' );
			return true;
		}
	) );
	register_rest_route( NEXTGEN_ASSISTANT_NAMESPACE, '/' . NEXTGEN_ASSISTANT_BASE_CONTROL , array(
		'methods' => 'POST',
		'callback' => 'nextgenassistant_control',
		'permission_callback' => function () {
			// return current_user_can( 'upload_files' );
			return true;
		}
	) );
	// cors headers
	remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
	add_filter( 'rest_pre_serve_request', function( $value ) {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE' );
		header( 'Access-Control-Allow-Credentials: true' );

		return $value;
	} );

} );

function nextgenassistant_iterable( $var ) {
	return ! empty( $var ) && ( is_array( $var ) || is_object( $var ) );
}

function nextgenassistant_get_nextgen_gallery_list() {
	$gallerylist = nggdb::find_all_galleries();
	if ( is_array( $gallerylist ) ) {
		return $gallerylist;
	} else {
		$nogalleries = array();
		return $nogalleries;
	}
}

function nextgenassistant_get_image_sizes() {
	// Make thumbnails and other intermediate sizes.
	$_wp_additional_image_sizes = wp_get_additional_image_sizes();

	$sizes = array();
	foreach ( get_intermediate_image_sizes() as $s ) {
		$sizes[ $s ] = array( 'width' => '', 'height' => '', 'crop' => false );
		if ( isset( $_wp_additional_image_sizes[ $s ]['width'] ) ) {
			// For theme-added sizes
			$sizes[ $s ]['width'] = intval( $_wp_additional_image_sizes[ $s ]['width'] );
			$sizes[ $s ]['isWP'] = false;
		} else {
			// For default sizes set in options
			$sizes[ $s ]['width'] = get_option( "{$s}_size_w" );
			$sizes[ $s ]['isWP'] = true;
		}

		if ( isset( $_wp_additional_image_sizes[ $s ]['height'] ) ) {
			// For theme-added sizes
			$sizes[ $s ]['height'] = intval( $_wp_additional_image_sizes[ $s ]['height'] );
			$sizes[ $s ]['isWP'] = false;
		} else {
			// For default sizes set in options
			$sizes[ $s ]['height'] = get_option( "{$s}_size_h" );
			$sizes[ $s ]['isWP'] = true;
		}

		if ( isset( $_wp_additional_image_sizes[ $s ]['crop'] ) ) {
			// For theme-added sizes
			$sizes[ $s ]['crop'] = $_wp_additional_image_sizes[ $s ]['crop'];
			$sizes[ $s ]['isWP'] = false;
		} else {
			// For default sizes set in options
			$sizes[ $s ]['crop'] = get_option( "{$s}_crop" );
			$sizes[ $s ]['isWP'] = true;
		}
	}
	return $sizes;
}

function nextgenassistant_remove_session( $session_id ) {
	$src_folder = NEXTGEN_ASSISTANT_PLUGIN_CHUNKS_DIR . '/' . $session_id;
	if ( file_exists( $src_folder ) ) {
		if ( $handle = opendir( $src_folder ) ) {
			while ( false !== ( $file = readdir( $handle ) ) ) {
				if ( $file != "." && $file != ".." ) {
					unlink( $src_folder . '/' . $file );
				}
			}
			closedir( $handle );
		}
		rmdir ( $src_folder );
	}
}

function nextgenassistant_dirtree( $dir, $path_remove ) {
	$array_items = array();
	if ( $handle = opendir( $dir ) ) {
		while ( false !== ( $file = readdir( $handle ) ) ) {
			if ( $file != "." && $file != ".." ) {
				if ( is_dir( $dir . "/" . $file ) ) {
					$array_items = array_merge( $array_items, nextgenassistant_dirtree( $dir . "/" . $file , $path_remove ) );
					$file_path = $dir . "/" . $file;
					$file_path_clean = preg_replace( "/\/\//si", "/", $file_path );
					$file_path_content_dir = str_replace( $path_remove, "", $file_path_clean );
					$content_dir = str_replace( $path_remove, "", $dir );
					if ( strlen( $content_dir ) == 0 ) {
						$content_dir = '/';
					}
					// parent
					$entry['parent'] = $content_dir;
					// text
					$entry['text'] = $file;
					$entry['id'] = $file_path_content_dir;
					$array_items[] = $entry;
				}
			}
		}
		closedir( $handle );
	}
	return $array_items;
}

function nextgenassistant_chunks_complete( $req_info ) {
	$uuid = $req_info['uuid'];
	$req_uuid = $req_info['reqUuid'];
	if ( ! $uuid ) {
		$data = array();
		$data['success'] = 'false';
		$data['uuid'] = 'notFound';
		$data['reqUuid'] = $req_uuid;
		$data['code'] = 'ngga_chuckscomplete_no_uuid';
		$data['line'] = __LINE__;
		$data['message'] = 'No uuid in request.';
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}
	$session_path = $req_info['sessionId'];
	if ( ! $session_path ) {
		$data = array();
		$data['success'] = 'false';
		$data['uuid'] = $uuid;
		$data['reqUuid'] = $req_uuid;
		$data['code'] = 'ngga_chuckscomplete_no_session_id';
		$data['line'] = __LINE__;
		$data['message'] = 'No session id in request.';
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}
	$src_folder = NEXTGEN_ASSISTANT_PLUGIN_CHUNKS_DIR . '/' . $session_path;
	$src_file = $src_folder . '/' . $uuid;

	if ( ! file_exists( $src_file ) ) {
		$data = array();
		$data['success'] = 'false';
		$data['uuid'] = $uuid;
		$data['reqUuid'] = $req_uuid;
		$data['code'] = 'ngga_chuckscomplete_missing_chunkfile';
		$data['line'] = __LINE__;
		$data['message'] = 'The uploaded chuck file is missing.';
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}
	if ( is_dir( $src_file ) ) {
		$data = array();
		$data['success'] = 'false';
		$data['uuid'] = $uuid;
		$data['reqUuid'] = $req_uuid;
		$data['code'] = 'ngga_chuckscomplete_source_is_dir';
		$data['line'] = __LINE__;
		$data['message'] = 'The chunk file is a directory.';
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}

	$invalid_path = nextgenassistant_validate_request_paths( $req_info );

	if ( $invalid_path ) {
		return $invalid_path;
	}

	$dest_file = $req_info['destinationFileName'];
	$dest_file = nextgenassistant_trim_name( $dest_file );
	$site_dir = get_home_path();
	$site_url = site_url();
	$dest_file = $site_dir . $req_info['destinationPath'] . '/' . $dest_file;
	if ( isset( $req_info['isBackup'] ) ) {
		$dest_file = $dest_file . '_backup';
	}

	$dest_file = nextgenassistant_move_file( $req_info, $src_file, $dest_file, false );
	if ( nextgenassistant_is_rest_ressponse( $dest_file ) ) {
		return $dest_file;
	}
	if ( isset( $req_info['createBackup'] ) ) {
		$backup_file = nextgenassistant_create_backup( $req_info, $dest_file, false );
		if ( nextgenassistant_is_rest_ressponse( $backup_file ) ) {
			return $backup_file;
		}
	}

	$bname = basename( $dest_file );
	if ( isset( $req_info['original'] ) ) {
		$gid = ( int ) $req_info['gid'];
		if ( $gid != -1 ) {
			$add_gallery_error = nextgenassistant_add_gallery_image( $req_info, $gid, $bname );
			if ( $add_gallery_error ) {
				return $add_gallery_error;
			}
		}
	}

// Wrap the data in a response object
	$data = array();
	$data['success'] = 'true';
	$data['uuid'] = $uuid;
	$data['reqUuid'] = $req_uuid;
	$data['name'] = $bname;
	$data['url'] = $site_url . '/' . $req_info['destinationPath'] . '/' . $data['name'];
	$data['file'] = $dest_file;

	$response = rest_ensure_response( $data );
	$response->set_status( 201 );
	return $response;
}

function nextgenassistant_return_bytes( $val ) {
	$val = trim( $val );
	$last = strtolower( $val[strlen( $val )-1] );
	switch( $last ) {
		// The 'G' modifier is available since PHP 5.1.0
		case 'g':
			$val *= 1000;
		case 'm':
			$val *= 1000;
		case 'k':
			$val *= 1000;
	}

	return $val;
}

function nextgenassistant_control( WP_REST_Request $request ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-includes/option.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-includes/link-template.php';
	$data = array();
	$auth_header = $_SERVER['HTTP_AUTHORIZATION'];
	if ( empty( $auth_header ) ) {
		$data['success'] = 'false';
		$data['message'] = 'Missing authorization header.';
		$data['code'] = 'ngga_missing_auth_header';
		$data['line'] = __LINE__;
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}

	$send_control = $_POST['sendControl'];
	if ( ! empty( $send_control ) ) {
		$current_date = ( date ( "Y-m-d" ) );
		if ( $send_control == 'getConfig' ) {
			if (  is_plugin_active( 'nextgen-gallery/nggallery.php' ) ) {
				$data['nextgen_active'] = 'true';
				$gallery_list = nextgenassistant_get_nextgen_gallery_list();
			} else {
				$data['nextgen_active'] = 'false';
				$gallery_list = array();
			}
			if ( isset( $_POST['removeSessionsList'] ) ) {
				$post_data = $_POST['removeSessionsList'];
				$temp_data = str_replace( "\\", "", $post_data );
				$remove_session_list = json_decode( $temp_data, true );
				foreach( $remove_session_list as $session_id ) {
					nextgenassistant_remove_session( $session_id );
				}
			}
//			$mysizes = nextgenassistant_get_image_sizes();
//			$data['sizes'] = json_encode( $mysizes );
			$site_dir = get_home_path();
			$site_url = site_url();

			$data['nextgen_gallery_list'] = json_encode( $gallery_list );
			$upload_path = wp_upload_dir();
			$upload_path = str_replace( WP_CONTENT_DIR, "", $upload_path );
			$data['upload_dir'] = $upload_path;
			$data['site_dir'] = $site_dir;
			$data['site_url'] = $site_url;
			$data['content_dir'] = WP_CONTENT_DIR;
			$data['php_version'] = phpversion();
			$data['upload_max_filesize'] = nextgenassistant_return_bytes( ini_get( 'upload_max_filesize' ) );
			$data['post_max_size'] = nextgenassistant_return_bytes( ini_get( 'post_max_size' ) );
			$data['max_file_uploads'] = nextgenassistant_return_bytes( ini_get( 'max_file_uploads' ) );
			$data['memory_limit'] = nextgenassistant_return_bytes( ini_get( 'memory_limit' ) );
			$data['server_date'] = $current_date;
		} elseif ( $send_control == 'getDirs' ) {
			$site_dir = get_home_path();
			$path_remove = rtrim( $site_dir, '/' );
			$array_items = nextgenassistant_dirtree( $path_remove, $path_remove );
			array_multisort( array_map( 'count', $array_items ), $array_items );
			$data['site_dirs'] = ( $array_items );
			$data['server_date'] = $current_date;
		} elseif ( $send_control == 'addNextGENGallery' ) {
			$nextgen_not_active = nextgenassistant_check_nextgen_active();
			if ( $nextgen_not_active ) {
				$response = rest_ensure_response( $nextgen_not_active );
				$response->set_status( 200 );
				return $response;
			}
			include_once ( NGGALLERY_ABSPATH . "lib/ngg-db.php" );
			require_once ( NGGALLERY_ABSPATH . '/admin/functions.php' );
			global $ngg;
			$data['nextgen_active'] = 'true';
			if ( ! current_user_can( 'NextGEN Manage gallery' ) ) {
				$gallery_list = nextgenassistant_get_nextgen_gallery_list();
				$data['success'] = 'false';
				$data['message'] = 'You are not allowed to add galleries.';
				$data['code'] = 'ngga_manage_nextgen_gallery';
				$data['line'] = __LINE__;
				$data['nextgen_gallery_list'] = json_encode( $gallery_list );
				$response                     = rest_ensure_response( $data );
				$response->set_status( 200 );
				return $response;
			}

			$gallery_title = $_POST['galleryTitle'];
			if ( empty( $gallery_title ) ) {
				$gallery_list = nextgenassistant_get_nextgen_gallery_list();
				$data['success'] = 'false';
				$data['message'] = 'No Gallery Title in request.';
				$data['code'] = 'ngga_manage_nextgen_gallery';
				$data['line'] = __LINE__;
				$data['nextgen_gallery_list'] = json_encode( $gallery_list );
				$response = rest_ensure_response( $data );
				$response->set_status( 200 );
				return $response;
			}
			$defaultpath = $ngg->options["gallerypath"];
			$new_gid = nggAdmin::create_gallery( $gallery_title, $defaultpath, false );
			if ( false == $new_gid ) {
				$gallery_list = nextgenassistant_get_nextgen_gallery_list();
				$data['success'] = 'false';
				$data['nextgen_gallery_list'] = json_encode( $gallery_list );
				$data['message'] = 'Unable to create NextGEN Gallery.';
				$data['code'] = 'ngga_manage_nextgen_gallery';
				$data['line'] = __LINE__;
				$response = rest_ensure_response( $data );
				$response->set_status( 200 );
				return $response;
			} else {
				$gallery_list = nextgenassistant_get_nextgen_gallery_list();
				$data['success'] = 'true';
				$data['gid'] = $new_gid;
				$data['server_date'] = $current_date;
				$data['nextgen_gallery_list'] = json_encode( $gallery_list );
				$response = rest_ensure_response( $data );
				$response->set_status( 200 );
				return $response;
			}
		} elseif ( $send_control == 'deleteNextGENGallery' ) {
			$nextgen_not_active = nextgenassistant_check_nextgen_active();
			if ( $nextgen_not_active ) {
				$response = rest_ensure_response( $nextgen_not_active );
				$response->set_status( 200 );
				return $response;
			}
			include_once ( NGGALLERY_ABSPATH . "lib/ngg-db.php" );
			require_once ( NGGALLERY_ABSPATH . '/admin/functions.php' );
			$data['nextgen_active'] = 'true';
			$gid = $_POST['gid'];
			if ( empty( $gid ) ) {
				$gallery_list = nextgenassistant_get_nextgen_gallery_list();
				$data['success'] = 'false';
				$data['message'] = 'No Gallery ID in request.';
				$data['code'] = 'ngga_remove_nextgen_gallery';
				$data['line'] = __LINE__;
				$data['nextgen_gallery_list'] = json_encode( $gallery_list );
				$response = rest_ensure_response( $data );
				$response->set_status( 200 );
				return $response;
			}
			$gallery_found = nextgenassistant_find_gallery( $gid );
			if ( ! $gallery_found ) {
				$gallery_list = nextgenassistant_get_nextgen_gallery_list();
				$data['success'] = 'false';
				$data['message'] = 'Requested NextGEN Gallery not found.';
				$data['code'] = 'ngga_manage_nextgen_gallery';
				$data['line'] = __LINE__;
				$data['nextgen_gallery_list'] = json_encode( $gallery_list );
				$response                     = rest_ensure_response( $data );
				$response->set_status( 200 );

				return $response;
			}

			if ( ! current_user_can( 'NextGEN Manage gallery' ) ) {
				$gallery_list = nextgenassistant_get_nextgen_gallery_list();
				$data['success'] = 'false';
				$data['message'] = 'You are not allowed to remove galleries.';
				$data['code'] = 'ngga_manage_nextgen_gallery';
				$data['line'] = __LINE__;
				$data['nextgen_gallery_list'] = json_encode( $gallery_list );
				$response                     = rest_ensure_response( $data );
				$response->set_status( 200 );

				return $response;
			}

			$mapper = C_Gallery_Mapper::get_instance();
			$gallery = $mapper->find( $gid );
			if ( ! nggAdmin::can_manage_this_gallery( $gallery->author ) ) {
				$gallery_list = nextgenassistant_get_nextgen_gallery_list();
				$data['success'] = 'false';
				$data['message'] = 'You are not allowed to delete this gallery.';
				$data['code'] = 'ngga_manage_nextgen_gallery';
				$data['line'] = __LINE__;
				$data['nextgen_gallery_list'] = json_encode( $gallery_list );
				$response = rest_ensure_response( $data );
				$response->set_status( 200 );
				return $response;
			}
			if ( $gallery->path == '../' || FALSE !== strpos( $gallery->path, '/../' ) ) {
				$gallery_list = nextgenassistant_get_nextgen_gallery_list();
				$data['success'] = 'false';
				$data['message'] = 'One or more "../" in Gallery paths could be unsafe and NextGen Gallery will not delete gallery';
				$data['code'] = 'ngga_manage_nextgen_gallery';
				$data['line'] = __LINE__;
				$data['nextgen_gallery_list'] = json_encode( $gallery_list );
				$response = rest_ensure_response( $data );
				$response->set_status( 200 );
				return $response;
			}

			$deleted = false;
			if ( $mapper->destroy( $gid, TRUE ) ) {
				$deleted = TRUE;
			}
			if ( false == $deleted ) {
				$gallery_list = nextgenassistant_get_nextgen_gallery_list();
				$data['success'] = 'false';
				$data['nextgen_gallery_list'] = json_encode( $gallery_list );
				$data['message'] = 'Unable to remove NextGEN Gallery.';
				$data['code'] = 'ngga_manage_nextgen_gallery';
				$data['line'] = __LINE__;
				$response = rest_ensure_response( $data );
				$response->set_status( 200 );
				return $response;
			} else {
				$gallery_list = nextgenassistant_get_nextgen_gallery_list();
				$data['success'] = 'true';
				$data['server_date'] = $current_date;
				$data['nextgen_gallery_list'] = json_encode( $gallery_list );
				$response = rest_ensure_response( $data );
				$response->set_status( 200 );
				return $response;
			}

		} elseif ( $send_control == 'removeSessions' ) {
			if ( isset( $_POST['removeSessionsList'] ) ) {
				$post_data = $_POST['removeSessionsList'];
				$temp_data = str_replace( "\\", "", $post_data );
				$remove_session_list = json_decode( $temp_data, true );
				foreach( $remove_session_list as $session_id ) {
					nextgenassistant_remove_session( $session_id );
				}
			}

		} else {
			$data['success'] = 'false';
			$data['message'] = 'Invalid control request.';
			$data['code'] = 'ngga_invalid_control_request';
			$data['line'] = __LINE__;
			$response = rest_ensure_response( $data );
			$response->set_status( 200 );
			return $response;
		}

		$data['success'] = 'true';
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}
	$data['success'] = 'false';
	$data['message'] = 'No control code request.';
	$data['code'] = 'ngga_no_control_request';
	$data['line'] = __LINE__;
	$response = rest_ensure_response( $data );
	$response->set_status( 200 );
	return ( $response );
}

function nextgenassistant_check_nextgen_active() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-includes/option.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-includes/link-template.php';

	if ( ! is_plugin_active( 'nextgen-gallery/nggallery.php' ) ) {
		$data                         = array();
		$data['code']                 = 'ngga_nextgen_not_active';
		$data['success']              = 'false';
		$data['nextgen_active']       = 'false';
		$gallery_list                 = array();
		$data['nextgen_gallery_list'] = json_encode( $gallery_list );
		$data['message']              = 'NextGEN plugin is not active.';

		return $data;
	}
	return false;
}


// nextgan must be active
function nextgenassistant_find_gallery( $gid ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-includes/option.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-includes/link-template.php';

	include_once ( NGGALLERY_ABSPATH . "lib/ngg-db.php" );
	require_once ( NGGALLERY_ABSPATH . '/admin/functions.php' );
	$gallery_list = nextgenassistant_get_nextgen_gallery_list();

	$gallery_found = false;
	$gallery = null;
	foreach( $gallery_list as $gallery ) {
		foreach( $gallery as $name => $value ) {
			if ( $name == 'gid' ){
				if ( $value == $gid ) {
					$gallery_found = true;
					break;
				}
			}
		}
	}
	return $gallery_found;
}

function nextgenassistant_check_nextgen_config( $nextgen_gid ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-includes/option.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-includes/link-template.php';

	if (  is_plugin_active( 'nextgen-gallery/nggallery.php' ) ) {
		$data['nextgen_active'] = 'true';
		$gallery_list = nextgenassistant_get_nextgen_gallery_list();
	} else {
		$data['nextgen_active'] = 'false';
		$gallery_list = array();
	}

	$gallery_found = nextgenassistant_find_gallery( $nextgen_gid );

	if ( ! $gallery_found ) {
		$data['success'] = 'false';
		$data['message'] = 'The requested gallery has been removed.';
		$data['code'] = 'ngga_manage_nextgen_gallery';
		$data['line'] = __LINE__;
		$data['nextgen_active'] = 'true';
		$data['nextgen_gallery_list'] = json_encode( $gallery_list );
		return $data;
	}

	if ( ! current_user_can( 'NextGEN Upload images' ) ) {
		$data['success'] = 'false';
		$data['message'] = 'You are not allowed to upload images.';
		$data['code'] = 'ngga_manage_nextgen_gallery';
		$data['line'] = __LINE__;
		$data['nextgen_active'] = 'true';
		$data['nextgen_gallery_list'] = json_encode( $gallery_list );
		return $data;
	}

	return false;
}

function nextgenassistant_nextgen_error( $req_info, $data ) {
	$data['success'] = 'false';
	$data['uuid'] = $req_info['uuid'];
	$data['reqUuid'] = $req_info['reqUuid'];
	$response = rest_ensure_response( $data );
	$response->set_status( 200 );
	return $response;
}

function nextgenassistant_validate_request_paths( $req_info ) {

	$data = array();
	$data['success'] = 'false';
	$data['code'] = 'ngga_validate_request_paths';

	$dest_path = $req_info['destinationPath'];

	if ( empty( $dest_path ) ) {
		$data['success'] = 'false';
		$data['dest'] = 'no path';
		$data['line'] = __LINE__;
		$data['code'] = 'nextgenassistant_no_dest_path';
		$data['uuid'] = $req_info['uuid'];
		$data['reqUuid'] = $req_info['reqUuid'];
		$data['message'] = 'No destination path.';
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}

	if ( preg_match( '/\.\./', $dest_path ) ) {
		$data['dest'] = $dest_path;
		$data['uuid'] = $req_info['uuid'];
		$data['reqUuid'] = $req_info['reqUuid'];
		$data['line'] = __LINE__;
		$data['code'] = 'ngga_path_has_dots';
		$data['message'] = 'Previous directory paths (..) are not permitted in Path.';
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}
	$site_dir = get_home_path();

	$path = $site_dir . $dest_path;
	if ( ! is_dir( $path ) ) {
		$data['dest'] = $path;
		$data['line'] = __LINE__;
		$data['code'] = 'ngga_dest_path_does_not_exist';
		$data['uuid'] = $req_info['uuid'];
		$data['reqUuid'] = $req_info['reqUuid'];
		$data['message'] = 'Destination path doesn\'t exist.';
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}

	$dest_file_name = $req_info['destinationFileName'];
	$dest_file_name = nextgenassistant_trim_name( $dest_file_name );

	if ( preg_match( '/\.\./', $dest_file_name ) ) {
		$data['dest'] = $dest_file_name;
		$data['line'] = __LINE__;
		$data['code'] = 'ngga_filename_has_dots';
		$data['uuid'] = $req_info['uuid'];
		$data['reqUuid'] = $req_info['reqUuid'];
		$data['message'] = 'Previous directory paths (..) are not permitted in filename .\'';
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}
	return false;
}

function nextgenassistant_validate_request( $file, $req_info ) {
	$upload_error_strings = array( false,
		__( "The uploaded file exceeds the <code>upload_max_filesize</code> directive in <code>php.ini</code>." ),
		__( "The uploaded file exceeds the <em>MAX_FILE_SIZE</em> directive that was specified in the HTML form." ),
		__( "The uploaded file was only partially uploaded." ),
		__( "No file was uploaded." ),
		__( "Missing a temporary folder." ),
		__( "Failed to write file to disk." ) );

	$data = array();
	$data['success'] = 'false';
	$data['code'] = 'ngga_validate_request';


	if ( empty( $file ) ) {
		$data['line'] = __LINE__;
		$data['code'] = 'ngga_no_input_file';
		$data['message'] = 'No input file.';
		$data['uuid'] = $req_info['uuid'];
		$data['reqUuid'] = $req_info['reqUuid'];
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}
	// Verify hash, if given
	if ( ! empty ( $headers['content_md5'] ) ) {
		$content_md5 = array_shift( $headers['content_md5'] );
		$expected = trim( $content_md5 );
		$actual = md5_file( $file['file']['tmp_name'] );
		if ( $expected !== $actual ) {
			$data['line'] = __LINE__;
			$data['code'] = 'ngga_content_hash_error';
			$data['message'] = 'Content hash did not match expected.';
			$data['uuid'] = $req_info['uuid'];
			$data['reqUuid'] = $req_info['reqUuid'];
			$response = rest_ensure_response( $data );
			$response->set_status( 200 );
			return $response;
		}
	}
	$invalid_path = nextgenassistant_validate_request_paths( $req_info );
	if ( $invalid_path ) {
		return $invalid_path;
	}

	// All tests are on by default. Most can be turned off by $override[{test_name}] = false;
	$test_size = true;

	// If you override this, you must provide $ext and $type!!!!


	// A non-empty file will pass this test.
	if ( $test_size && ! ( $file['size'] > 0 ) ) {
		$data['line'] = __LINE__;
		$data['code'] = 'ngga_file_is_empty';
		$data['message'] = 'File is empty. Please upload something more substantial. This error could also be caused by uploads being disabled in your php.ini.';
		$data['uuid'] = $req_info['uuid'];
		$data['reqUuid'] = $req_info['reqUuid'];
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}

	// A successful upload will pass this test. It makes no sense to override this one.
	if ( $file['error'] > 0 ) {
		$data['line'] = __LINE__;
		$data['code'] = 'ngga_file_upload_error';
		$data['message'] = $upload_error_strings[ $file['error'] ];
		$data['uuid'] = $req_info['uuid'];
		$data['reqUuid'] = $req_info['reqUuid'];
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}


	// A properly uploaded file will pass this test. There should be no reason to override this one.
	if ( ! @ is_uploaded_file( $file['tmp_name'] ) ) {
		$data['line'] = __LINE__;
		$data['code'] = 'ngga_file_failed_upload_test';
		$data['message'] = 'Specified file failed upload test.';
		$data['uuid'] = $req_info['uuid'];
		$data['reqUuid'] = $req_info['reqUuid'];
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}

	return false;
}

function nextgenassistant_add_gallery_image( $req_info, $gid, $picture ) {
	include_once ( NGGALLERY_ABSPATH . "lib/ngg-db.php" );
	require_once ( NGGALLERY_ABSPATH . '/admin/functions.php' );
	$nggdb = new nggdb();
	// strip off the extension of the filename
	$path_parts = M_I18n::mb_pathinfo( $picture );
	$alttext = ( ! isset( $path_parts['filename'] ) ) ? substr( $path_parts['basename'], 0, strpos( $path_parts['basename'], '.' ) ) : $path_parts['filename'];
	// save it to the database
	// $pic_id = nggdb::add_image( $gid, $picture, '', $alttext );
	$pic_id = $nggdb->add_image( $gid, $picture, '', $alttext );

	if ( $pic_id ) {
		nggAdmin::import_MetaData( $pic_id );
		return false;
	} else {
		$data = array();
		$data['success'] = 'false';
		$data['uuid'] = $req_info['uuid'];
		$data['reqUuid'] = $req_info['reqUuid'];
		$data['code'] = 'ngga_add_gallery_image';
		$data['line'] = __LINE__;
		$data['message'] = 'Unable to add image to NextGEN gallery database.';
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}
}

/**
 * nextgenassistant_upload hadles the upload,
 * code from https://github.com/WP-API/WP-API/blob/47491996f08a3f51883dbae6f6fd0c94ade90c9f/lib/endpoints/class-wp-rest-attachments-controller.php#L56
 *
 * @param WP_REST_Request $request The request object has the  multipart file parameters and the given header from the request.
 * @return WP_HTTP_Response   The response has the name, file type and url of the uploaded file
 */
function nextgenassistant_upload( WP_REST_Request $request ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-includes/option.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-includes/link-template.php';

	$req_results = array();
	$data = array();
	$index = 0;
	$nextgen_gid = -1;

	if ( isset( $_POST['gid'] ) ) {
		$nextgen_gid = ( int ) $_POST['gid'];
	}
	$nextgen_error = false;
	$auth_header = $_SERVER['HTTP_AUTHORIZATION'];
	if ( empty( $auth_header ) ) {
		$data['success'] = 'false';
		$data['message'] = 'Missing authorization header.';
		$data['code'] = 'ngga_missing_auth_header';
		$data['line'] = __LINE__;
		$nextgen_error = $data;
	}

	if ( ! $nextgen_error ) {
		$nextgen_error = nextgenassistant_check_nextgen_active();
	}
	if ( ! $nextgen_error ) {
		$nextgen_error = nextgenassistant_check_nextgen_config( $nextgen_gid );
	}
	if ( isset( $_POST['uploadsInfo'] ) ) {
		$files   = $request->get_file_params();
		$post_data = $_POST['uploadsInfo'];
		$temp_data = str_replace( "\\", "", $post_data );
		$req_info_list = json_decode( $temp_data, true );
		foreach( $files as $file ) {
			$req_uuid = $file['name'];
			$req_info = $req_info_list[ $req_uuid] ;
			if ( $nextgen_error ) {
				$results = nextgenassistant_nextgen_error( $req_info, $nextgen_error );
			} else {
				$results = nextgenassistant_process_upload( $file, $req_info );
			}
			$req_results[ $index ] = $results;
			$index++;
		}
	}
	if ( isset( $_POST['chunksCompleteInfo'] ) ) {
		$post_data = $_POST['chunksCompleteInfo'];
		$temp_data = str_replace( "\\", "", $post_data );
		$req_info_list = json_decode( $temp_data, true );
		foreach( $req_info_list as $req_info ) {
			if ( $nextgen_error ) {
				$results = nextgenassistant_nextgen_error( $req_info, $nextgen_error );
			} else {
				$results = nextgenassistant_chunks_complete( $req_info );
			}
			$req_results[ $index ] = $results;
			$index++;
		}
	}

	$data['upload_results'] = ( $req_results );
	$data['server_date'] = ( date ( "Y-m-d" ) );

	$response = rest_ensure_response( $data );
	$response->set_status( 201 );
	return $response;
}

function nextgenassistant_process_upload( $file, $req_info ) {

	$data = array();

	$invalid_request = nextgenassistant_validate_request( $file, $req_info );

	if ( $invalid_request ) {
		return $invalid_request;
	}

	$src_path = $file['tmp_name'];
	$req_uuid = $file['name'];
	$uuid = $req_info['uuid'];

	// Save a chunk
	$has_chunks = isset( $req_info['nggaHasChunks'] );
	if ( $has_chunks ) {
		$session_path = $req_info['sessionId'];
		if ( ! $session_path ) {
			$data = array();
			$data['success'] = 'false';
			$data['uuid'] = $uuid;
			$data['reqUuid'] = $req_uuid;
			$data['code'] = 'ngga_chuckscomplete_no_session_id';
			$data['line'] = __LINE__;
			$data['message'] = 'No session id in request.';
			$response = rest_ensure_response( $data );
			$response->set_status( 200 );
			return $response;
		}
		$lock_file = fopen( NEXTGEN_ASSISTANT_PLUGIN_CHUNKS_LOCK, 'rb' );
		flock( $lock_file, LOCK_EX );
		$dest_folder = NEXTGEN_ASSISTANT_PLUGIN_CHUNKS_DIR . '/' . $session_path;
		if ( ! is_dir( $dest_folder ) ) {
			if ( ! @ mkdir( $dest_folder, 0777, true ) ) {
				$data['uuid']       = $uuid;
				$data['reqUuid']    = $req_uuid;
				$data['success']    = 'false';
				$data['code']       = 'ngga_upload_chunk';
				$data['line']       = __LINE__;
				$data['message']    = 'Create chunk session directory failed, path: ' . $dest_folder;
				$response           = rest_ensure_response( $data );
				$response->set_status( 200 );
				fclose( $lock_file );
				return $response;
			}
		}
		$part_offset = ( int )$req_info['nggaPartbyteoffset'];
		$part_size = ( int )$req_info['nggaChunksize'];
		$dest_path = $dest_folder . '/' . $uuid;
		if ( file_exists( $dest_path ) ) {
			$dest = fopen( $dest_path, "rb+" );
			if ( ! $dest ) {
				$data['uuid'] = $uuid;
				$data['reqUuid'] = $req_uuid;
				$data['success'] = 'false';
				$data['dest'] = $dest_path;
				$data['partOffest'] = $part_offset;
				$data['partSize'] = $part_size;
				$data['code'] = 'ngga_upload_chunk';
				$data['line'] = __LINE__;
				$data['message'] = 'Destination file missing.';
				$response = rest_ensure_response( $data );
				$response->set_status( 200 );
				fclose( $lock_file );
				return $response;
			}

		} else {
			$dest = fopen( $dest_path, "wb+" );
			if ( ! $dest ) {
				$data['uuid'] = $uuid;
				$data['reqUuid'] = $req_uuid;
				$data['success'] = 'false';
				$data['dest'] = $dest_path;
				$data['partOffest'] = $part_offset;
				$data['partSize'] = $part_size;
				$data['code'] = 'ngga_upload_chunk';
				$data['line'] = __LINE__;
				$data['message'] = 'Can\'t open chunk destination file.';
				$response = rest_ensure_response( $data );
				$response->set_status( 200 );
				fclose( $lock_file );
				return $response;
			}
		}
		flock( $dest, LOCK_EX );
		fclose( $lock_file );
		if ( fseek( $dest, $part_offset, SEEK_SET ) == -1 ) {
			$data['uuid'] = $uuid;
			$data['reqUuid'] = $req_uuid;
			$data['success'] = 'false';
			$data['dest'] = $dest_path;
			$data['partOffest'] = $part_offset;
			$data['partSize'] = $part_size;
			$data['code'] = 'ngga_upload_chunk';
			$data['line'] = __LINE__;
			$data['message'] = 'Can\'t set position of chunk file.';
			$response = rest_ensure_response( $data );
			$response->set_status( 200 );
			fclose( $dest );
			return $response;
		}

		$src = fopen( $src_path, "rb" );
		if ( ! $src ) {
			fclose( $dest );
			$data['uuid'] = $uuid;
			$data['reqUuid'] = $req_uuid;
			$data['success'] = 'false';
			$data['dest'] = $dest_path;
			$data['partOffest'] = $part_offset;
			$data['partSize'] = $part_size;
			$data['code'] = 'ngga_upload_chunk';
			$data['line'] = __LINE__;
			$data['message'] = 'Can\'t open chunk source file.';
			$response = rest_ensure_response( $data );
			$response->set_status( 200 );
			return $response;
		}
		while ( ! feof( $src ) ) {
			$rbuf = fread( $src, $part_size );
			fwrite( $dest, $rbuf );
		}
		fclose( $src );
		fclose( $dest );

		$data = array();
		$data['success'] = 'true';
		$data['uuid'] = $uuid;
		$data['reqUuid'] = $req_uuid;
		$data['dest'] = $dest_path;
		$data['partOffest'] = $part_offset;
		$data['partSize'] = $part_size;
		$response = rest_ensure_response( $data );
		$response->set_status( 201 );
		return $response;
	}

	$src_file = $file['tmp_name'];

	$dest_file = $req_info['destinationFileName'];
	$dest_file = nextgenassistant_trim_name( $dest_file );
	$site_dir = get_home_path();
	$site_url = site_url();
	$dest_file = $site_dir . $req_info['destinationPath'] . '/' . $dest_file;
	if ( isset( $req_info['isBackup'] ) ) {
		$dest_file = $dest_file . '_backup';
	}
	$dest_file = nextgenassistant_move_file( $req_info, $src_file, $dest_file, false );

	if ( nextgenassistant_is_rest_ressponse( $dest_file ) ) {
		return $dest_file;
	}

	if ( isset( $req_info['createBackup'] ) ) {
		$backup_file = nextgenassistant_create_backup( $req_info, $dest_file, false );
		if ( nextgenassistant_is_rest_ressponse( $backup_file ) ) {
			return $backup_file;
		}
	}

	$bname = basename( $dest_file );
	if ( isset( $req_info['original'] ) ) {
		$gid = ( int ) $req_info['gid'];
		if ( $gid != -1 ) {
			$add_gallery_error = nextgenassistant_add_gallery_image( $req_info, $gid, $bname );
			if ( $add_gallery_error ) {
				return $add_gallery_error;
			}
		}
	}

	$data = array();
	$data['success'] = 'true';
	$data['uuid'] = $req_info['uuid'];
	$data['reqUuid'] = $req_info['reqUuid'];
	$data['name'] = $bname;
	$data['url'] = $site_url . '/' . $req_info['destinationPath'] . '/' . $data['name'];
	$data['file'] = $dest_file;


	$response = rest_ensure_response( $data );
	$response->set_status( 201 );
	return $response;
}

function nextgenassistant_move_file( $req_info, $src_file, $dest_file, $over_write ) {
	if ( ! $over_write && file_exists( $dest_file ) ) {
		$dir = dirname( $dest_file );
		$dest_file = $dir . '/' . wp_unique_filename( $dir, basename( $dest_file ) );
	}
	if ( ! @ rename( $src_file, $dest_file ) ) {
		$data = array();
		$data['success'] = 'false';
		$data['src'] = $src_file;
		$data['dest'] = $dest_file;
		$data['code'] = 'ngga_upload_rename_failed';
		$data['line'] = __LINE__;
		$data['uuid'] = $req_info['uuid'];
		$data['reqUuid'] = $req_info['reqUuid'];
		$data['message'] = 'The uploaded file could not be moved. Please check the folder and file permissions.';
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}
	$perms = 0000666;
	@ chmod( $dest_file, $perms );

	return $dest_file;
}

function nextgenassistant_create_backup( $req_info, $src_file, $over_write ) {
	$site_dir  = get_home_path();
	$backup_file = $src_file . '_backup';
	if ( ! $over_write && file_exists( $backup_file ) ) {
		$dir = dirname( $src_file );
		$src_file = $dir . '/' . wp_unique_filename( $dir, basename( $src_file ) );
		$backup_file = $site_dir . $req_info['destinationPath'] . '/' . $src_file. '_backup';
	}

	if ( ! @ copy( $src_file, $backup_file ) ) {
		$data = array();
		$data['success'] = 'false';
		$data['uuid'] = $req_info['uuid'];
		$data['reqUuid'] = $req_info['reqUuid'];
		$data['code'] = 'ngga_copy_backup_failed';
		$data['line'] = __LINE__;
		$data['message'] = 'Copy to backup failed.';
		$response = rest_ensure_response( $data );
		$response->set_status( 200 );
		return $response;
	}
	$perms = 0000666;
	@ chmod( $backup_file, $perms );

	return $backup_file;
}


function nextgenassistant_is_rest_ressponse( $thing ) {
	return ( $thing instanceof WP_REST_Response );
}
?>
