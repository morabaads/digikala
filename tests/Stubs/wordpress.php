<?php
/**
 * Minimal in-memory stand-ins for the WordPress functions used by the
 * infrastructure classes under test. Not a WordPress emulator.
 *
 * @package DKBridge
 */

// phpcs:disable

$GLOBALS['dkb_test_options']    = array();
$GLOBALS['dkb_test_transients'] = array();

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );
}

function dkb_test_reset() {
	$GLOBALS['dkb_test_options']    = array();
	$GLOBALS['dkb_test_transients'] = array();
}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['dkb_test_options'] ) ? $GLOBALS['dkb_test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['dkb_test_options'][ $key ] = $value;
	return true;
}
function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $key, $GLOBALS['dkb_test_options'] ) ) {
		return false;
	}
	$GLOBALS['dkb_test_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['dkb_test_options'][ $key ] );
	return true;
}
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['dkb_test_transients'] ) ? $GLOBALS['dkb_test_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['dkb_test_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['dkb_test_transients'][ $key ] );
	return true;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function do_action( $hook ) {}
function __( $text, $domain = 'default' ) {
	return $text;
}
function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options );
}
function current_time( $type, $gmt = false ) {
	return gmdate( 'Y-m-d H:i:s' );
}
function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_textarea_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_key( $s ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
function wp_upload_dir() {
	$dir = sys_get_temp_dir() . '/dkb-uploads';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	return array( 'basedir' => $dir, 'baseurl' => 'http://example.test/uploads' );
}
function trailingslashit( $s ) {
	return rtrim( $s, '/\\' ) . '/';
}
function wp_delete_file( $f ) {
	if ( file_exists( $f ) ) {
		unlink( $f );
	}
}
function wp_list_pluck( $list, $field ) {
	return array_map(
		function ( $r ) use ( $field ) {
			return is_array( $r ) ? ( isset( $r[ $field ] ) ? $r[ $field ] : null ) : ( isset( $r->$field ) ? $r->$field : null );
		},
		(array) $list
	);
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $s, $d = 'default' ) {
	return esc_html( $s );
}
function esc_url( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
