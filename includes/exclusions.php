<?php

namespace WPPanelBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CACHE_DROPIN_PATHS = array( 'wp-content/object-cache.php', 'wp-content/advanced-cache.php' );

/**
 * Return only directories that exist and can be attributed to a known plugin.
 * All keys are exact paths relative to the source root.
 */
function detected_directory_exclusions( $root ) {
	if ( false === $root ) {
		return array();
	}
	$rules = array(
		'backups' => array(
			'UpdraftPlus'             => array( 'updraftplus/updraftplus.php', array( 'updraft' ) ),
			'Duplicator'              => array( array( 'duplicator/duplicator.php', 'duplicator-pro/duplicator-pro.php' ), array( 'duplicator-backups', 'backups-dup-lite', 'backups-dup-pro' ) ),
			'All-in-One WP Migration' => array( 'all-in-one-wp-migration/all-in-one-wp-migration.php', array( 'ai1wm-backups' ) ),
			'WPvivid'                 => array( 'wpvivid-backuprestore/wpvivid-backuprestore.php', array( 'wpvividbackups' ) ),
		),
		'cache' => array(
			'WP Super Cache'   => array( 'wp-super-cache/wp-cache.php', array( 'cache/supercache' ) ),
			'W3 Total Cache'   => array( 'w3-total-cache/w3-total-cache.php', array( 'cache/page_enhanced', 'cache/minify', 'cache/db', 'cache/object' ) ),
			'WP Fastest Cache' => array( 'wp-fastest-cache/wpFastestCache.php', array( 'cache/all', 'cache/wpfc-minified', 'cache/wpfc-mobile-cache', 'cache/wpfc-widget-cache' ) ),
			'WP Rocket'        => array( 'wp-rocket/wp-rocket.php', array( 'cache/wp-rocket', 'cache/min', 'cache/busting', 'cache/critical-css', 'cache/used-css', 'cache/background-css' ) ),
			'LiteSpeed Cache' => array( 'litespeed-cache/litespeed-cache.php', array( 'litespeed' ) ),
			'WP-Optimize'    => array( 'wp-optimize/wp-optimize.php', array( 'cache/wpo-cache', 'cache/wpo-minify' ) ),
			'Cache Enabler'   => array( 'cache-enabler/cache-enabler.php', array( 'cache/cache-enabler' ) ),
			'Autoptimize'     => array( 'autoptimize/autoptimize.php', array( 'cache/autoptimize' ) ),
			'Breeze'          => array( 'breeze/breeze.php', array( 'cache/breeze', 'cache/breeze-minification', 'cache/breeze-extra' ) ),
		),
	);
	$matches = array();
	foreach ( $rules as $category => $plugins ) {
		foreach ( $plugins as $plugin => list( $entry_points, $directories ) ) {
			foreach ( (array) $entry_points as $entry_point ) {
				if ( ! is_file( $root . '/wp-content/plugins/' . $entry_point ) ) {
					continue;
				}
				foreach ( $directories as $directory ) {
					add_directory_exclusion( $matches, $root, $root . '/wp-content/' . $directory, $category, $plugin );
				}
				break;
			}
		}
	}

	// UpdraftPlus and WPvivid store relative paths under WP_CONTENT_DIR.
	$content = $root . '/wp-content/';
	$updraft = get_option( 'updraft_dir', '' );
	$wpvivid = get_option( 'wpvivid_local_setting', array() );
	$configured = array(
		'UpdraftPlus' => array(
			array( $content . 'updraft', backup_storage_path( $content, $updraft ) ),
			'/^backup_[0-9]{4}(?:-[0-9]{2}){2}-[0-9]{4}_.+_[a-f0-9]{12}-(?:db|[a-z-]+)[0-9]*\.(?:zip|gz(?:\.crypt)?)$/D',
		),
		'All-in-One WP Migration' => array(
			array( $content . 'ai1wm-backups', backup_storage_path( $content, get_option( 'ai1wm_backups_path', '' ) ) ),
			'/^.+\.wpress$/iD',
		),
		'WPvivid' => array(
			array( $content . 'wpvividbackups', backup_storage_path( $content, is_array( $wpvivid ) ? ( $wpvivid['path'] ?? '' ) : '' ) ),
			'/^.*wpvivid-[a-f0-9]+_.+\.zip$/iD',
		),
	);
	foreach ( $configured as $plugin => list( $paths, $archive_pattern ) ) {
		foreach ( array_unique( $paths ) as $path ) {
			if ( is_backup_only_directory( $root, $path, $archive_pattern ) ) {
				add_directory_exclusion( $matches, $root, $path, 'backups', $plugin );
			}
		}
	}

	$backwpup_id = get_site_option( 'backwpup_cfg_hash', '' );
	$uploads = wp_get_upload_dir();
	if ( is_string( $backwpup_id ) && preg_match( '/^[A-Za-z0-9]{6,12}$/D', $backwpup_id ) ) {
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$path = rtrim( $uploads['basedir'], '/' ) . '/backwpup/' . $backwpup_id . '/';
			foreach ( array( 'backups', 'temp' ) as $directory ) {
				add_directory_exclusion( $matches, $root, $path . $directory, 'backups', 'BackWPup' );
			}
		}
	}
	if ( ! empty( $uploads['basedir'] ) ) {
		foreach ( $matches as $path => $match ) {
			if ( 0 === strpos( rtrim( $uploads['basedir'], '/' ) . '/', $root . '/' . $path . '/' ) ) {
				unset( $matches[ $path ] );
			}
		}
	}

	ksort( $matches );
	return $matches;
}

function backup_storage_path( $content, $path ) {
	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}
	$path = wp_normalize_path( $path );
	return rtrim( '/' === $path[0] ? $path : $content . $path, '/' );
}

function add_directory_exclusion( &$matches, $root, $path, $category, $plugin ) {
	if ( 0 !== strpos( $path, $root . '/wp-content/' ) || ! is_dir( $path ) || realpath( $path ) !== $path ) {
		return;
	}
	$relative = substr( $path, strlen( $root ) + 1 );
	$matches[ $relative ] = array( 'reason' => $category, 'plugin' => $plugin );
}

/**
 * An explicit third-party path may still be a shared folder. Preserve it when
 * its top-level contents do not all look like this plugin's backup artifacts.
 */
function is_backup_only_directory( $root, $path, $archive_pattern ) {
	if ( 0 !== strpos( $path, $root . '/wp-content/' ) || ! is_dir( $path ) || realpath( $path ) !== $path
		|| in_array( $path, array( $root . '/wp-content/uploads', $root . '/wp-content/cache' ), true )
		|| preg_match( '~^' . preg_quote( $root . '/wp-content/', '~' ) . '(?:plugins|mu-plugins|themes)(?:/|$)~D', $path ) ) {
		return false;
	}
	$found_archive = false;
	try {
		foreach ( new \DirectoryIterator( $path ) as $entry ) {
			if ( $entry->isDot() ) {
				continue;
			}
			if ( $entry->isLink() || ! $entry->isFile() ) {
				return false;
			}
			$name = $entry->getFilename();
			if ( preg_match( $archive_pattern, $name ) ) {
				$found_archive = true;
			} elseif ( ! in_array( $name, array( '.htaccess', '.donotbackup', 'index.html', 'index.php', 'web.config', 'robots.txt' ), true )
				&& ! preg_match( '/^log\.[a-f0-9]{12}\.txt$/D', $name ) ) {
				return false;
			}
		}
	} catch ( \UnexpectedValueException $error ) {
		return false;
	}
	return $found_archive;
}
