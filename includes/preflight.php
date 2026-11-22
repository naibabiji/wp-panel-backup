<?php

namespace WPPanelBackup;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are plain text. Escape at HTML output so JSON responses and saved job status retain the original text.

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
		throw new RuntimeException( __( '当前是 WordPress 多站点，暂不支持自动备份。', 'wp-panel-backup' ) );
	}

	$home    = source_address( get_option( 'home' ) );
	$siteurl = source_address( get_option( 'siteurl' ) );
	if ( $home !== $siteurl ) {
		throw new RuntimeException( __( '站点地址与 WordPress 地址不同，请使用手动迁移。', 'wp-panel-backup' ) );
	}

	// Read stored values directly. get_option() can return constants via core filters.
	$options = str_replace( '`', '``', $wpdb->options );
	$stored = database_rows( "SELECT option_name, option_value FROM `{$options}` WHERE option_name IN ('home', 'siteurl')" );
	$addresses = array_column( $stored, 'option_value', 'option_name' );
	if ( $home !== source_address( $addresses['home'] ?? '' ) || $siteurl !== source_address( $addresses['siteurl'] ?? '' ) ) {
		throw new RuntimeException( __( '实际生效的站点地址与数据库记录不同，请使用手动迁移。', 'wp-panel-backup' ) );
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
		throw new RuntimeException( __( '站点地址必须使用域名根路径和标准 HTTP/HTTPS 端口，且不能使用国际化域名。', 'wp-panel-backup' ) );
	}

	return $scheme . '://' . $host;
}

function source_root() {
	$root = realpath( ABSPATH );
	if ( false === $root || ! is_readable( $root ) || ! is_file( $root . '/wp-load.php' ) ) {
		throw new RuntimeException( __( '无法读取 WordPress 安装根目录。', 'wp-panel-backup' ) );
	}

	if ( 'cli' !== PHP_SAPI ) {
		// The web server provides this local path. Preserve its bytes for the canonical path comparison.
		$document_root = realpath( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $document_root !== $root ) {
			throw new RuntimeException( __( 'WordPress 安装根目录与服务器网站根目录不同，请使用手动迁移。', 'wp-panel-backup' ) );
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
			/* translators: Relative file or directory path. */
			throw new RuntimeException( sprintf( __( '发现网站根目录之外或无法读取的内容目录：%s', 'wp-panel-backup' ), $location ) );
		}
	}

	return $root;
}

/**
 * Traverse the included tree without following links or retaining every file in memory.
 */
function source_entries( $root, $excluded, $relative = '' ) {
	$directory = rtrim( $root . '/' . $relative, '/' );
	if ( realpath( $directory ) !== $directory ) {
		/* translators: Relative file or directory path. */
		throw new RuntimeException( sprintf( __( '扫描目录已消失或变成链接：%s', 'wp-panel-backup' ), $relative ) );
	}
	foreach ( new DirectoryIterator( $directory ) as $entry ) {
		if ( $entry->isDot() ) {
			continue;
		}
		$name = $entry->getFilename();
		$path = $relative . $name;
		if ( '.' === $name[0] ) {
			yield array( 'kind' => 'excluded', 'path' => $path, 'reason' => 'hidden' );
			continue;
		}

		$stat = lstat( $entry->getPathname() );
		if ( false === $stat ) {
			/* translators: Relative file or directory path. */
			throw new RuntimeException( sprintf( __( '无法读取文件信息：%s', 'wp-panel-backup' ), $path ) );
		}

		$type = $stat['mode'] & 0170000;
		if ( in_array( $type, array( 0100000, 0120000 ), true ) && 'cache-drop-in' === ( $excluded[ $path ]['reason'] ?? '' ) ) {
			yield array( 'kind' => 'excluded', 'path' => $path ) + $excluded[ $path ];
			continue;
		}
		if ( 0040000 !== $type && ( 0100000 !== $type || 1 !== $stat['nlink'] ) ) {
			/* translators: Relative file or directory path. */
			throw new RuntimeException( sprintf( __( '备份范围内存在软链接、硬链接或特殊文件：%s', 'wp-panel-backup' ), $path ) );
		}
		if ( ! $entry->isReadable() ) {
			/* translators: Relative file or directory path. */
			throw new RuntimeException( sprintf( __( '无法读取备份路径：%s', 'wp-panel-backup' ), $path ) );
		}

		$is_directory = 0040000 === $type;
		if ( $is_directory && isset( $excluded[ $path ] ) ) {
			yield array( 'kind' => 'excluded', 'path' => $path ) + $excluded[ $path ];
			continue;
		}
		if ( $is_directory ) {
			yield array( 'path' => $path, 'kind' => 'directory' );
			yield from source_entries( $root, $excluded, $path . '/' );
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
		// Calls supply fixed metadata queries and identifiers quoted by sql_name().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( __( '无法读取数据库选项或对象清单，请检查当前数据库账号的读取权限。', 'wp-panel-backup' ) );
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
			/* translators: 1: Database object name, 2: Database object type. */
			throw new RuntimeException( sprintf( __( '发现不支持的数据库对象：%1$s（%2$s）', 'wp-panel-backup' ), $name, $type ) );
		}
		$tables[] = $name;
	}

	$queries = array(
		'SHOW TRIGGERS' => __( '触发器', 'wp-panel-backup' ),
		'SHOW EVENTS' => __( '计划事件', 'wp-panel-backup' ),
		'SHOW PROCEDURE STATUS WHERE Db = DATABASE()' => __( '存储过程', 'wp-panel-backup' ),
		'SHOW FUNCTION STATUS WHERE Db = DATABASE()' => __( '存储函数', 'wp-panel-backup' ),
	);
	foreach ( $queries as $query => $type ) {
		if ( database_rows( $query ) ) {
			/* translators: Database object type. */
			throw new RuntimeException( sprintf( __( '当前数据库存在首版不支持的对象：%s。', 'wp-panel-backup' ), $type ) );
		}
	}

	return $tables;
}
