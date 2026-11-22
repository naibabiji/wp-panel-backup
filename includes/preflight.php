<?php

namespace WPPanelBackup;

use DirectoryIterator;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check the already confirmed source constraints. No backup or maintenance is started here.
 */
function inspect_source() {
	global $wpdb;

	if ( is_multisite() ) {
		throw new RuntimeException( '当前是 WordPress 多站点，暂不支持自动备份。' );
	}

	$home    = source_address( get_option( 'home' ) );
	$siteurl = source_address( get_option( 'siteurl' ) );
	if ( $home !== $siteurl ) {
		throw new RuntimeException( '站点地址与 WordPress 地址不同，请使用手动迁移。' );
	}

	// Read stored values directly. get_option() can return constants via core filters.
	$options = str_replace( '`', '``', $wpdb->options );
	$stored = database_rows( "SELECT option_name, option_value FROM `{$options}` WHERE option_name IN ('home', 'siteurl')" );
	$addresses = array_column( $stored, 'option_value', 'option_name' );
	if ( $home !== source_address( $addresses['home'] ?? '' ) || $siteurl !== source_address( $addresses['siteurl'] ?? '' ) ) {
		throw new RuntimeException( '实际生效的站点地址与数据库记录不同，请使用手动迁移。' );
	}

	$root = source_root();
	$tables = inspect_database();

	return array(
		'home'     => $home,
		'siteurl'  => $siteurl,
		'prefix'   => $wpdb->prefix,
		'root'     => $root,
		'tables'   => $tables,
	);
}

function source_address( $address ) {
	$parts = is_string( $address ) ? wp_parse_url( $address ) : false;
	$scheme = strtolower( $parts['scheme'] ?? '' );
	$host   = strtolower( $parts['host'] ?? '' );
	$port   = 'https' === $scheme ? 443 : 80;

	if ( ! in_array( $scheme, array( 'http', 'https' ), true )
		|| ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host )
		|| preg_match( '/(?:^|\.)xn--/i', $host )
		|| strlen( $host ) > 253
		|| ( $parts['port'] ?? $port ) !== $port
		|| ! in_array( $parts['path'] ?? '', array( '', '/' ), true )
		|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
		throw new RuntimeException( '站点地址必须使用域名根路径和标准 HTTP/HTTPS 端口，且不能使用国际化域名。' );
	}

	return $scheme . '://' . $host;
}

function source_root() {
	$root = realpath( ABSPATH );
	if ( false === $root || ! is_readable( $root ) || ! is_file( $root . '/wp-load.php' ) ) {
		throw new RuntimeException( '无法读取 WordPress 安装根目录。' );
	}

	if ( 'cli' !== PHP_SAPI ) {
		$document_root = realpath( $_SERVER['DOCUMENT_ROOT'] ?? '' );
		if ( $document_root !== $root ) {
			throw new RuntimeException( 'WordPress 安装根目录与服务器网站根目录不同，请使用手动迁移。' );
		}
	}

	$uploads = wp_get_upload_dir();
	$locations = array( WP_CONTENT_DIR, WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, $uploads['basedir'] );
	foreach ( $locations as $location ) {
		// Resolve the nearest existing ancestor so a fresh site without uploads can pass.
		$path = rtrim( $location, '/\\' );
		while ( ! file_exists( $path ) && ! is_link( $path ) && dirname( $path ) !== $path ) {
			$path = dirname( $path );
		}
		$resolved = realpath( $path );
		if ( false === $resolved || ( $resolved !== $root && 0 !== strpos( $resolved, $root . DIRECTORY_SEPARATOR ) ) ) {
			throw new RuntimeException( '发现网站根目录之外或无法读取的内容目录：' . $location );
		}
	}

	return $root;
}

/**
 * Traverse the included tree without following links or retaining every file in memory.
 */
function source_entries( $root, $relative = '', $report_excluded = false, $workspace_path = '' ) {
	$directory = rtrim( $root . '/' . $relative, '/' );
	if ( realpath( $directory ) !== $directory ) {
		throw new RuntimeException( '扫描目录已消失或变成链接：' . $relative );
	}
	foreach ( new DirectoryIterator( $directory ) as $entry ) {
		if ( $entry->isDot() ) {
			continue;
		}
		$name = $entry->getFilename();
		$path = $relative . $name;
		if ( '.' === $name[0] || $path === $workspace_path ) {
			if ( $report_excluded ) {
				yield array( 'kind' => 'excluded', 'path' => $path, 'reason' => $path === $workspace_path ? 'workspace' : 'hidden' );
			}
			continue;
		}

		$stat = lstat( $entry->getPathname() );
		if ( false === $stat ) {
			throw new RuntimeException( '无法读取文件信息：' . $path );
		}

		$type = $stat['mode'] & 0170000;
		if ( 0040000 !== $type && ( 0100000 !== $type || 1 !== $stat['nlink'] ) ) {
			throw new RuntimeException( '备份范围内存在软链接、硬链接或特殊文件：' . $path );
		}
		if ( ! $entry->isReadable() ) {
			throw new RuntimeException( '无法读取备份路径：' . $path );
		}

		$is_directory = 0040000 === $type;
		if ( $is_directory ) {
			yield array( 'path' => $path, 'kind' => 'directory' );
			yield from source_entries( $root, $path . '/', $report_excluded, $workspace_path );
		} else {
			yield array( 'path' => $path, 'kind' => 'file', 'size' => $stat['size'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'] );
		}
	}
}

/**
 * Database metadata queries must fail visibly when the account cannot execute them.
 */
function database_rows( $sql ) {
	global $wpdb;

	$previous = $wpdb->suppress_errors();
	try {
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( '无法读取数据库选项或对象清单，请检查当前数据库账号的读取权限。' );
		}
		return $rows;
	} finally {
		$wpdb->suppress_errors( $previous );
	}
}

function inspect_database() {
	$tables = array();
	foreach ( database_rows( 'SHOW FULL TABLES' ) as $row ) {
		list( $name, $type ) = array_values( $row );
		if ( 'BASE TABLE' !== $type ) {
			throw new RuntimeException( '发现不支持的数据库对象：' . $name . '（' . $type . '）' );
		}
		$tables[] = $name;
	}

	$queries = array(
		'触发器'   => 'SHOW TRIGGERS',
		'计划事件' => 'SHOW EVENTS',
		'存储过程' => 'SHOW PROCEDURE STATUS WHERE Db = DATABASE()',
		'存储函数' => 'SHOW FUNCTION STATUS WHERE Db = DATABASE()',
	);
	foreach ( $queries as $type => $query ) {
		if ( database_rows( $query ) ) {
			throw new RuntimeException( '当前数据库存在首版不支持的对象：' . $type . '。' );
		}
	}

	return $tables;
}
