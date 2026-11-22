<?php

namespace WPPanelBackup;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function workspace() {
	$path = defined( 'WP_PANEL_BACKUP_PRIVATE_DIR' ) && is_string( WP_PANEL_BACKUP_PRIVATE_DIR ) ? rtrim( WP_PANEL_BACKUP_PRIVATE_DIR, '/' ) : '';
	if ( '' === $path || ! defined( 'WP_PANEL_BACKUP_PRIVATE_DIR_VERIFIED' ) || true !== WP_PANEL_BACKUP_PRIVATE_DIR_VERIFIED ) {
		throw new RuntimeException( '请先由主机管理员配置并核对 WP PANEL Backup 的私有工作目录。' );
	}
	$real = realpath( $path );
	$root = realpath( ABSPATH );
	$web_root = empty( $_SERVER['DOCUMENT_ROOT'] ) ? $root : realpath( $_SERVER['DOCUMENT_ROOT'] );
	if ( $real !== $path || ! is_dir( $path ) || false === $root || false === $web_root
		|| paths_overlap( $path, $root ) || paths_overlap( $path, $web_root )
		|| 0700 !== ( fileperms( $path ) & 0777 ) || ! is_readable( $path ) || ! is_writable( $path ) || ! is_executable( $path )
		|| ( function_exists( 'posix_geteuid' ) && fileowner( $path ) !== posix_geteuid() ) ) {
		throw new RuntimeException( '私有目录必须是站点根目录之外的独占真实路径，由 PHP 运行用户持有，且权限为 0700。' );
	}
	if ( is_file( $path . '/site.json' ) && site_identity() !== read_record( $path . '/site.json' ) ) {
		throw new RuntimeException( '私有目录已绑定到另一份 WordPress 安装。' );
	}
	return $path;
}

function site_identity() {
	$root = realpath( ABSPATH );
	$stat = stat( $root );
	return array( 'root' => $root, 'device' => $stat['dev'], 'inode' => $stat['ino'] );
}

function paths_overlap( $first, $second ) {
	$first = rtrim( $first, '/' ) . '/';
	$second = rtrim( $second, '/' ) . '/';
	return 0 === strpos( $first, $second ) || 0 === strpos( $second, $first );
}

function with_workspace_lock( $callback ) {
	$root = workspace();
	$lock = fopen( $root . '/lock', 'c+b' );
	if ( false === $lock ) {
		throw new RuntimeException( '无法打开备份锁。' );
	}
	try {
		if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			throw new RuntimeException( '另一个备份请求正在运行，请稍后重试。' );
		}
		$root = workspace();
		if ( ! is_file( $root . '/site.json' ) ) {
			write_record( $root . '/site.json', site_identity() );
		}
		return $callback( $root );
	} finally {
		fclose( $lock );
	}
}

function encode_record( $value ) {
	$json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		throw new RuntimeException( '无法编码备份记录。' );
	}
	return $json . "\n";
}

function write_bytes( $stream, $value ) {
	if ( strlen( $value ) !== fwrite( $stream, $value ) ) {
		throw new RuntimeException( '备份写入失败，请检查私有目录的可用空间。' );
	}
}

function write_record( $path, $value ) {
	$json = encode_record( $value );
	if ( strlen( $json ) !== file_put_contents( $path . '.tmp', $json ) || ! rename( $path . '.tmp', $path ) ) {
		throw new RuntimeException( '无法保存备份状态，请检查私有目录的可用空间。' );
	}
}

function read_record( $path ) {
	$json = is_file( $path ) ? json_decode( file_get_contents( $path ), true ) : null;
	if ( ! is_array( $json ) ) {
		throw new RuntimeException( '无法读取备份记录。' );
	}
	return $json;
}

function open_output( $path, $offset ) {
	$stream = fopen( $path, 'c+b' );
	if ( false === $stream ) {
		throw new RuntimeException( '无法创建备份数据文件。' );
	}
	if ( fstat( $stream )['size'] < $offset || ! ftruncate( $stream, $offset ) || 0 !== fseek( $stream, $offset ) ) {
		fclose( $stream );
		throw new RuntimeException( '备份数据文件长度不匹配。' );
	}
	return $stream;
}

function finish_output( $stream ) {
	$offset = ftell( $stream );
	if ( false === $offset || ! fflush( $stream ) ) {
		throw new RuntimeException( '无法保存备份数据文件。' );
	}
	return $offset;
}
