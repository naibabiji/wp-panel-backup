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

function verify_workspace_protection( $root, $directory, $home ) {
	$rule = $root . '/.htaccess';
	if ( ! file_exists( $rule ) ) {
		$stream = @fopen( $rule, 'xb' );
		if ( false === $stream ) {
			throw new RuntimeException( '无法创建备份目录的访问保护规则。' );
		}
		try {
			write_bytes( $stream, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" );
		} finally {
			fclose( $stream );
		}
	}

	$token = bin2hex( random_bytes( 16 ) );
	$probe = 'wp-panel-backup-probe-' . $token;
	$base_url = $home . '/wp-content/';
	$root_url = $base_url . basename( $root ) . '/';
	$job_url = $root_url . basename( $directory ) . '/';
	$written = array();
	try {
		create_workspace_probe( WP_CONTENT_DIR . '/' . $probe . '.txt', $token, $written );
		$response = request_workspace_probe( $base_url . $probe . '.txt' );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || $token !== wp_remote_retrieve_body( $response ) ) {
			throw new RuntimeException( '无法确认匿名自检请求到达本站。请检查站点地址、回环访问和代理配置。' );
		}

		// Check directory listings as well as the file types served by the web server.
		foreach ( array( $root => $root_url, $directory => $job_url ) as $path => $url ) {
			require_workspace_probe_denied( $url, $token );
			foreach ( array( '', '.json', '.tmp', '.sql', '.zip', '.ndjson', '.raw' ) as $extension ) {
				$name = $probe . $extension;
				create_workspace_probe( $path . '/' . $name, $token, $written );
				require_workspace_probe_denied( $url . $name, $token );
			}
		}
	} finally {
		foreach ( $written as $path ) {
			unlink( $path );
		}
	}
	if ( ! is_file( $root . '/site.json' ) ) {
		write_record( $root . '/site.json', array( 'site' => site_identity() ) );
	}
}

function create_workspace_probe( $path, $token, &$written ) {
	$stream = @fopen( $path, 'xb' );
	if ( false === $stream ) {
		throw new RuntimeException( '无法写入工作目录探测文件，请检查 wp-content 和工作目录写权限。' );
	}
	$written[] = $path;
	try {
		write_bytes( $stream, $token );
	} finally {
		fclose( $stream );
	}
}

function request_workspace_probe( $url ) {
	// Use the verified WordPress home origin, which may use private LAN addresses.
	$response = wp_remote_get( $url, array(
		'timeout'             => 3,
		'redirection'         => 0,
		'limit_response_size' => 4096,
		'headers'             => array( 'Cache-Control' => 'no-cache, no-store' ),
	) );
	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( '匿名自检无法连接本站。请检查站点域名、TLS 或 PHP 发起的回环请求。' );
	}
	return $response;
}

function require_workspace_probe_denied( $url, $token ) {
	$response = request_workspace_probe( $url );
	$status = wp_remote_retrieve_response_code( $response );
	if ( ! in_array( $status, array( 403, 404 ), true ) || false !== strpos( wp_remote_retrieve_body( $response ), $token ) ) {
		throw new RuntimeException( sprintf( '无法确认备份目录禁止匿名直读（HTTP %d）。请让主机商为 wp-content 下的 WP PANEL Backup 工作目录及子目录设置禁访规则，然后重试。', $status ) );
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
