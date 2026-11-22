<?php

namespace WPPanelBackup;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BUNDLE_ARTIFACT = 'bundle.tar';
const TAR_BLOCK_BYTES = 512;
const TAR_MAX_MEMBER_BYTES = 8589934591;

function download_export() {
	if ( ! current_user_can( 'manage_options' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_die( '你没有下载备份的权限。', '', array( 'response' => 403 ) );
	}

	$id = sanitize_key( wp_unslash( $_POST['job'] ?? '' ) );
	$name = wp_unslash( $_POST['artifact'] ?? '' );
	check_admin_referer( 'wp-panel-backup-download-' . $id, '_wp_panel_backup_download_nonce' );

	$bundle = BUNDLE_ARTIFACT === $name;
	try {
		$items = with_workspace_lock( static function ( $root ) use ( $id, $name, $bundle ) {
			return prepare_download( export_directory( $root, $id ), $name, $bundle );
		} );
	} catch ( \Throwable $error ) {
		wp_die( esc_html( $error->getMessage() ), '下载未能开始', array( 'response' => 404, 'back_link' => true ) );
	}

	$size = $items[0]['size'];
	if ( $bundle ) {
		$size = 2 * TAR_BLOCK_BYTES;
		foreach ( $items as $item ) {
			$size += TAR_BLOCK_BYTES + $item['size'] + tar_padding( $item['size'] );
		}
	}

	$filename = $bundle ? 'wp-panel-backup-' . $id . '.tar' : $name;
	nocache_headers();
	header( 'Content-Type: ' . ( $bundle ? 'application/x-tar' : 'application/octet-stream' ) );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . $size );

	try {
		$complete = true;
		foreach ( $items as $item ) {
			if ( $bundle ) {
				echo tar_header( $item['name'], $item['size'], $item['mtime'] );
			}
			if ( $item['size'] !== fpassthru( $item['stream'] ) ) {
				$complete = false;
				break;
			}
			if ( $bundle ) {
				echo str_repeat( "\0", tar_padding( $item['size'] ) );
			}
		}
		if ( $bundle && $complete ) {
			echo str_repeat( "\0", 2 * TAR_BLOCK_BYTES );
		}
	} finally {
		foreach ( $items as $item ) {
			fclose( $item['stream'] );
		}
	}
	exit;
}

/**
 * Open all requested files while cleanup is excluded. Streaming uses the
 * open handles and does not hold the workspace lock during the download.
 */
function prepare_download( $directory, $name, $bundle ) {
	$job_path = $directory . '/job.json';
	$job = read_record( $job_path );
	if ( 'complete' !== $job['phase'] || export_expired( $job ) ) {
		throw new RuntimeException( '这份备份材料不存在或已到期。' );
	}

	$artifacts = array( 'manifest.json' => null, 'files.ndjson' => null );
	foreach ( $job['packages'] as $package ) {
		if ( ! preg_match( '/^part-[0-9]{5,}\.zip$/D', $package['name'] ) ) {
			throw new RuntimeException( '备份材料清单损坏。' );
		}
		$artifacts[ $package['name'] ] = $package['size'];
	}
	if ( ! $bundle ) {
		if ( ! is_string( $name ) || ! array_key_exists( $name, $artifacts ) ) {
			throw new RuntimeException( '这份备份材料不存在。' );
		}
		$artifacts = array( $name => $artifacts[ $name ] );
	}

	$items = array();
	try {
		foreach ( $artifacts as $artifact => $expected_size ) {
			$path = $directory . '/' . $artifact;
			$stream = is_file( $path ) && ! is_link( $path ) ? @fopen( $path, 'rb' ) : false;
			if ( false === $stream ) {
				throw new RuntimeException( '无法打开备份材料：' . $artifact );
			}
			$items[] = array( 'name' => $artifact, 'stream' => $stream );
			$stat = fstat( $stream );
			if ( false === $stat || ( $expected_size !== null && $expected_size !== $stat['size'] ) ) {
				throw new RuntimeException( '备份材料长度与记录不符：' . $artifact );
			}
			if ( $bundle && $stat['size'] > TAR_MAX_MEMBER_BYTES ) {
				throw new RuntimeException( '此备份材料超过单文件封装上限，请使用逐份下载：' . $artifact );
			}
			$items[ count( $items ) - 1 ] += array( 'size' => $stat['size'], 'mtime' => $stat['mtime'] );
		}

		$job['downloads'][ $name ] = time();
		write_record( $job_path, $job );
		return $items;
	} catch ( \Throwable $error ) {
		foreach ( $items as $item ) {
			fclose( $item['stream'] );
		}
		throw $error;
	}
}

function tar_padding( $size ) {
	return ( TAR_BLOCK_BYTES - $size % TAR_BLOCK_BYTES ) % TAR_BLOCK_BYTES;
}

/**
 * USTAR header for a regular file. The checksum is calculated with the
 * checksum field filled with ASCII spaces, as specified by the TAR format.
 */
function tar_header( $name, $size, $mtime ) {
	$header = pack(
		'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
		$name, "0000644\0", "0000000\0", "0000000\0",
		sprintf( '%011o', $size ) . "\0",
		sprintf( '%011o', max( 0, min( $mtime, TAR_MAX_MEMBER_BYTES ) ) ) . "\0",
		str_repeat( ' ', 8 ), '0', '', "ustar\0", '00', '', '',
		"0000000\0", "0000000\0", '', ''
	);
	$checksum = array_sum( unpack( 'C*', $header ) );
	return substr_replace( $header, sprintf( '%06o', $checksum ) . "\0 ", 148, 8 );
}
