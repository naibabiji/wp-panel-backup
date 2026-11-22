<?php

namespace WPPanelBackup;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WORKSPACE_OPTION = 'wp_panel_backup_workspace';
const WORKSPACE_PREFIX = 'wp-panel-backup-storage-';

function workspace( $create = false ) {
	$identity = site_identity();
	$record = get_option( WORKSPACE_OPTION );
	if ( ! is_array( $record ) || ( $record['site'] ?? '' ) !== $identity ) {
		if ( ! $create ) {
			return null;
		}
		$new_record = array( 'site' => $identity, 'suffix' => bin2hex( random_bytes( 16 ) ) );
		if ( false === $record ) {
			add_option( WORKSPACE_OPTION, $new_record, '', false );
		} else {
			update_option( WORKSPACE_OPTION, $new_record, false );
		}
		$record = get_option( WORKSPACE_OPTION );
	}
	if ( ! is_array( $record ) || ( $record['site'] ?? '' ) !== $identity
		|| ! is_string( $record['suffix'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $record['suffix'] ) ) {
		throw new RuntimeException( '无法读取本机工作目录配置。' );
	}

	$content = rtrim( WP_CONTENT_DIR, '/' );
	if ( realpath( $content ) !== $content || ! is_dir( $content ) ) {
		throw new RuntimeException( '无法使用当前 WordPress 内容目录。' );
	}
	$path = $content . '/' . WORKSPACE_PREFIX . $record['suffix'];
	if ( $create && ! file_exists( $path ) && ! is_link( $path ) && ! @mkdir( $path, 0700 ) && ! is_dir( $path ) ) {
		throw new RuntimeException( 'WordPress 无法在 wp-content 创建备份工作目录，请检查 PHP 写权限。' );
	}
	if ( realpath( $path ) !== $path || ! is_dir( $path ) || 0700 !== ( fileperms( $path ) & 0777 )
		|| ! is_readable( $path ) || ! is_writable( $path ) || ! is_executable( $path )
		|| ( function_exists( 'posix_geteuid' ) && fileowner( $path ) !== posix_geteuid() ) ) {
		throw new RuntimeException( '备份工作目录不可用，请检查目录归属、0700 权限及链接设置。' );
	}
	if ( is_file( $path . '/site.json' ) && array( 'site' => $identity ) !== read_record( $path . '/site.json' ) ) {
		throw new RuntimeException( '工作目录已绑定到另一份 WordPress 安装。' );
	}
	return $path;
}

function site_identity() {
	$root = realpath( ABSPATH );
	$stat = false === $root ? false : stat( $root );
	if ( false === $stat ) {
		throw new RuntimeException( '无法识别当前 WordPress 安装位置。' );
	}
	return hash( 'sha256', $root . "\0" . $stat['dev'] . "\0" . $stat['ino'] );
}

function with_workspace_lock( $callback, $create = false ) {
	$root = workspace( $create );
	if ( null === $root ) {
		throw new RuntimeException( '尚未创建备份工作目录，请先开始备份。' );
	}
	$lock = fopen( $root . '/lock', 'c+b' );
	if ( false === $lock ) {
		throw new RuntimeException( '无法打开备份锁。' );
	}
	try {
		if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			throw new RuntimeException( '另一个备份请求正在运行，请稍后重试。' );
		}
		if ( $root !== workspace() ) {
			throw new RuntimeException( '备份工作目录已更改，请重新打开备份页面。' );
		}
		return $callback( $root );
	} finally {
		fclose( $lock );
	}
}

function prepare_workspace( $root ) {
	ensure_workspace_file( $root . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" );
	ensure_workspace_file( $root . '/index.html', "<!doctype html><meta charset=\"utf-8\"><title></title>\n" );


	if ( ! is_file( $root . '/site.json' ) ) {
		write_record( $root . '/site.json', array( 'site' => site_identity() ) );
	}
}

function ensure_workspace_file( $path, $content ) {
	if ( is_file( $path ) && ! is_link( $path ) ) {
		return;
	}
	$stream = @fopen( $path, 'xb' );
	if ( false === $stream ) {
		throw new RuntimeException( storage_error( '无法创建备份目录文件 ' . basename( $path ) . '。', dirname( $path ) ) );
	}
	try {
		write_bytes( $stream, $content );
	} finally {
		fclose( $stream );
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
	if ( strlen( $value ) !== @fwrite( $stream, $value ) ) {
		throw new RuntimeException( storage_error( '备份写入失败。', dirname( stream_get_meta_data( $stream )['uri'] ) ) );
	}
}

function storage_error( $message, $directory ) {
	$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $directory ) : false;
	if ( false !== $free ) {
		return $message . sprintf( '文件系统显示剩余 %s，请检查主机账号配额及目录写权限。', size_format( $free, 1 ) );
	}
	return $message . '请检查磁盘空间、主机账号配额及目录写权限。';
}

function write_record( $path, $value ) {
	$json = encode_record( $value );
	if ( strlen( $json ) !== @file_put_contents( $path . '.tmp', $json ) || ! @rename( $path . '.tmp', $path ) ) {
		throw new RuntimeException( storage_error( '无法保存备份状态。', dirname( $path ) ) );
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
	$stream = @fopen( $path, 'c+b' );
	if ( false === $stream ) {
		throw new RuntimeException( storage_error( '无法创建备份数据文件。', dirname( $path ) ) );
	}
	if ( fstat( $stream )['size'] < $offset || ! ftruncate( $stream, $offset ) || 0 !== fseek( $stream, $offset ) ) {
		fclose( $stream );
		throw new RuntimeException( '备份数据文件长度不匹配。' );
	}
	return $stream;
}

function finish_output( $stream ) {
	$offset = ftell( $stream );
	if ( false === $offset || ! @fflush( $stream ) ) {
		throw new RuntimeException( storage_error( '无法保存备份数据文件。', dirname( stream_get_meta_data( $stream )['uri'] ) ) );
	}
	return $offset;
}
