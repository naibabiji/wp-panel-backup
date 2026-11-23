<?php

namespace WPPanelBackup;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are plain text. Escape at HTML output so JSON responses and saved job status retain the original text.

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BUNDLE_ARTIFACT = 'bundle.tar';
const DOWNLOAD_CHUNK_BYTES = 1048576;
const TAR_BLOCK_BYTES = 512;
const TAR_MAX_MEMBER_BYTES = 8589934591;

function download_export() {
	if ( ! current_user_can( 'manage_options' ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
		wp_die( esc_html__( '你没有下载备份的权限。', 'wp-panel-backup' ), '', array( 'response' => 403 ) );
	}

	$id = sanitize_key( wp_unslash( $_POST['job'] ?? '' ) );
	check_admin_referer( 'wp-panel-backup-download-' . $id, '_wp_panel_backup_download_nonce' );

	// Admin callbacks can open an unbounded buffer that retains every download chunk.
	while ( ob_get_level() > 0 ) {
		if ( ! @ob_end_clean() ) {
			wp_die( esc_html__( '无法关闭下载请求的 PHP 输出缓冲，请检查站点后台的缓冲设置。', 'wp-panel-backup' ), '', array( 'response' => 500 ) );
		}
	}

	// Preserve the requested name for an exact match against the backup's artifact allowlist.
	$name = wp_unslash( $_POST['artifact'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$bundle = BUNDLE_ARTIFACT === $name;
	try {
		$items = with_workspace_lock( static function ( $root ) use ( $id, $name, $bundle ) {
			return prepare_download( export_directory( $root, $id ), $name, $bundle );
		} );
	} catch ( \Throwable $error ) {
		wp_die( esc_html( $error->getMessage() ), esc_html__( '下载未能开始', 'wp-panel-backup' ), array( 'response' => 404, 'back_link' => true ) );
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
				// TAR headers and padding are binary data.
				echo tar_header( $item['name'], $item['size'], $item['mtime'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			// Keep each write bounded even when PHP-FPM has output buffering enabled.
			$remaining = $item['size'];
			while ( $remaining > 0 ) {
				$chunk = @fread( $item['stream'], min( DOWNLOAD_CHUNK_BYTES, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded chunk from the open backup stream.
				if ( false === $chunk || '' === $chunk ) {
					$complete = false;
					break 2;
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Send the original binary bytes.
				$remaining -= strlen( $chunk );
			}
			if ( $bundle ) {
				echo str_repeat( "\0", tar_padding( $item['size'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		if ( $bundle && $complete ) {
			echo str_repeat( "\0", 2 * TAR_BLOCK_BYTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	} finally {
		foreach ( $items as $item ) {
			fclose( $item['stream'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native stream and any held lock.
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
		throw new RuntimeException( __( '这份备份材料不存在或已到期。', 'wp-panel-backup' ) );
	}

	$artifacts = array( 'manifest.json' => null, 'files.ndjson' => null );
	foreach ( $job['packages'] as $package ) {
		if ( ! preg_match( '/^part-[0-9]{5,}\.zip$/D', $package['name'] ) ) {
			throw new RuntimeException( __( '备份材料清单损坏。', 'wp-panel-backup' ) );
		}
		$artifacts[ $package['name'] ] = $package['size'];
	}
	if ( ! $bundle ) {
		if ( ! is_string( $name ) || ! array_key_exists( $name, $artifacts ) ) {
			throw new RuntimeException( __( '这份备份材料不存在。', 'wp-panel-backup' ) );
		}
		$artifacts = array( $name => $artifacts[ $name ] );
	}
	prepare_workspace( dirname( $directory ) );
	prepare_directory_indexes( $directory );

	$items = array();
	try {
		foreach ( $artifacts as $artifact => $expected_size ) {
			$path = $directory . '/' . $artifact;
			$stream = is_file( $path ) && ! is_link( $path ) ? @fopen( $path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream supports locking, seeking, and bounded reads.
			if ( false === $stream ) {
				/* translators: Name of the backup file. */
				throw new RuntimeException( sprintf( __( '无法打开备份材料：%s', 'wp-panel-backup' ), $artifact ) );
			}
			$items[] = array( 'name' => $artifact, 'stream' => $stream );
			$stat = fstat( $stream );
			if ( false === $stat || ( $expected_size !== null && $expected_size !== $stat['size'] ) ) {
				/* translators: Name of the backup file. */
				throw new RuntimeException( sprintf( __( '备份材料长度与记录不符：%s', 'wp-panel-backup' ), $artifact ) );
			}
			if ( $bundle && $stat['size'] > TAR_MAX_MEMBER_BYTES ) {
				/* translators: Name of the backup file. */
				throw new RuntimeException( sprintf( __( '此备份材料超过单文件封装上限，请使用逐份下载：%s', 'wp-panel-backup' ), $artifact ) );
			}
			$items[ count( $items ) - 1 ] += array( 'size' => $stat['size'], 'mtime' => $stat['mtime'] );
		}

		$job['downloads'][ $name ] = time();
		write_record( $job_path, $job );
		return $items;
	} catch ( \Throwable $error ) {
		foreach ( $items as $item ) {
			fclose( $item['stream'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native stream and any held lock.
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
