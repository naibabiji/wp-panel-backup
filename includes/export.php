<?php

namespace WPPanelBackup;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are plain text. Escape at HTML output so JSON responses and saved job status retain the original text.

use RuntimeException;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FILE_BLOCK_BYTES = 16 * 1024 * 1024;
const LEGACY_ZIP_PAYLOAD_BYTES = 64 * 1024 * 1024;
const DEFAULT_ZIP_PAYLOAD_MIB = 512;
const ZIP_PAYLOAD_OPTIONS = array( 128, 512, 1024 );
const ZIP_MAX_MEMBERS = 60000;
const PACK_REQUEST_BYTES = 64 * 1024 * 1024;

function validate_export_source() {
	if ( ! class_exists( ZipArchive::class ) || PHP_INT_SIZE < 8 ) {
		throw new RuntimeException( __( '备份需要 PHP Zip 扩展及 64 位的 PHP 文件偏移支持。', 'wp-panel-backup' ) );
	}
	$source = inspect_source();
	require_database_visibility();
	validate_export_table_engines( $source['tables'] );

	if ( ! is_file( $source['root'] . '/wp-config.php' ) ) {
		throw new RuntimeException( __( '未找到根目录 wp-config.php，本版无法携带供迁移参考的配置。', 'wp-panel-backup' ) );
	}
	if ( file_exists( WP_CONTENT_DIR . '/db.php' ) ) {
		throw new RuntimeException( __( '发现本版无法迁移的配置依赖：db.php', 'wp-panel-backup' ) );
	}
	foreach ( array( 'CUSTOM_USER_TABLE', 'CUSTOM_USER_META_TABLE' ) as $constant ) {
		if ( defined( $constant ) && constant( $constant ) ) {
			/* translators: Name of the unsupported WordPress configuration constant. */
			throw new RuntimeException( sprintf( __( '发现本版无法迁移的站点运行配置：%s', 'wp-panel-backup' ), $constant ) );
		}
	}
	$content = $source['root'] . '/wp-content';
	if ( rtrim( WP_CONTENT_DIR, '/' ) !== $content || rtrim( WP_PLUGIN_DIR, '/' ) !== $content . '/plugins'
		|| rtrim( WPMU_PLUGIN_DIR, '/' ) !== $content . '/mu-plugins'
		|| ( defined( 'UPLOADS' ) && 'wp-content/uploads' !== trim( UPLOADS, '/' ) ) ) {
		throw new RuntimeException( __( '发现依赖自定义 WordPress 内容路径常量的布局，当前版本无法迁移。', 'wp-panel-backup' ) );
	}
	$upload_path = get_option( 'upload_path' );
	if ( is_string( $upload_path ) && preg_match( '~^(?:/|[a-z]:[/\\\\])~i', $upload_path ) ) {
		throw new RuntimeException( __( '媒体上传配置依赖源站的绝对路径，当前版本无法迁移。', 'wp-panel-backup' ) );
	}
	return $source;
}

function create_export( $workspace, $options, $mode, $zip_payload_mib ) {
	if ( ! in_array( $zip_payload_mib, ZIP_PAYLOAD_OPTIONS, true ) ) {
		throw new RuntimeException( __( '请选择支持的 ZIP 分包大小。', 'wp-panel-backup' ) );
	}
	$source = validate_export_source();
	prepare_workspace( $workspace );
	$id = bin2hex( random_bytes( 16 ) );
	$directory = $workspace . '/' . $id;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the private directory with mode 0700 in a single operation.
	if ( ! mkdir( $directory, 0700 ) ) {
		throw new RuntimeException( __( '无法创建备份任务目录。', 'wp-panel-backup' ) );
	}
	prepare_directory_indexes( $directory );
	$job = array(
		'id'            => $id,
		'mode'          => $mode,
		'phase'         => 'scan',
		'source'        => array_diff_key( $source, array( 'tables' => true ) ),
		'tables'        => $source['tables'],
		'table_rows'    => array_fill( 0, count( $source['tables'] ), 0 ),
		'table_index'   => 0,
		'cursor'        => null,
		'sql_offset'    => 0,
		'queue_offset'  => 0,
		'index_offset'  => 0,
		'file'          => null,
		'sql_packed'    => false,
		'packages'      => array(),
		'zip_payload_bytes' => $zip_payload_mib * 1024 * 1024,
		'options'       => $options,
		'directory_exclusions' => array_filter( detected_directory_exclusions( $source['root'] ), static function ( $entry ) use ( $options ) {
			return ! empty( $options[ $entry['reason'] ] );
		} ),
		'updated_at'    => time(),
	);
	write_record( $directory . '/job.json', $job );
	return $job;
}

function plan_export_files( $directory, &$job ) {
	$root = $job['source']['root'];
	$workspace_path = substr( dirname( $directory ), strlen( $root ) + 1 );
	$excluded = array( $workspace_path => array( 'reason' => 'workspace' ) ) + ( $job['directory_exclusions'] ?? array() );
	$excluded += array_fill_keys( CACHE_DROPIN_PATHS, array( 'reason' => 'cache-drop-in' ) );
	$stream = open_output( $directory . '/queue.ndjson', 0 );
	try {
		foreach ( source_entries( $root, $excluded ) as $entry ) {
			write_bytes( $stream, encode_record( $entry ) );
		}
		finish_output( $stream );
	} finally {
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native stream and any held lock.
	}
	$job['phase'] = 'database';
}

function read_queue_entry( $stream, &$job ) {
	$line = fgets( $stream );
	if ( false === $line ) {
		if ( ! feof( $stream ) ) {
			throw new RuntimeException( __( '无法读取备份文件列表。', 'wp-panel-backup' ) );
		}
		return null;
	}
	$entry = json_decode( $line, true );
	if ( ! is_array( $entry ) ) {
		throw new RuntimeException( __( '备份文件列表损坏。', 'wp-panel-backup' ) );
	}
	$position = ftell( $stream );
	if ( false === $position ) {
		throw new RuntimeException( __( '无法记录备份文件列表的位置。', 'wp-panel-backup' ) );
	}
	$job['queue_offset'] = $position;
	return $entry;
}

function open_source_file( $path, $entry ) {
	clearstatcache( true, $path );
	$stat = lstat( $path );
	if ( false === $stat || realpath( $path ) !== $path || 0100000 !== ( $stat['mode'] & 0170000 ) || 1 !== $stat['nlink'] ) {
		/* translators: Relative file or directory path. */
		throw new RuntimeException( sprintf( __( '备份文件已消失或变成链接/特殊文件：%s', 'wp-panel-backup' ), $entry['path'] ) );
	}
	$stream = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream supports locking, seeking, and bounded reads.
	if ( false === $stream ) {
		/* translators: Relative file or directory path. */
		throw new RuntimeException( sprintf( __( '无法打开备份文件：%s', 'wp-panel-backup' ), $entry['path'] ) );
	}
	$opened = fstat( $stream );
	if ( false === $opened || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']
		|| $opened['size'] !== $entry['size'] || $opened['mtime'] !== $entry['mtime'] || $opened['ctime'] !== $entry['ctime'] ) {
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native stream and any held lock.
		/* translators: Relative file or directory path. */
		throw new RuntimeException( sprintf( __( '文件在备份期间发生变化，请重新创建备份：%s', 'wp-panel-backup' ), $entry['path'] ) );
	}
	return $stream;
}

function pack_export_step( $directory, &$job ) {
	$queue = fopen( $directory . '/queue.ndjson', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream supports locking, seeking, and bounded reads.
	if ( false === $queue ) {
		throw new RuntimeException( __( '无法打开备份文件列表。', 'wp-panel-backup' ) );
	}
	if ( 0 !== fseek( $queue, $job['queue_offset'] ) ) {
		fclose( $queue ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native stream and any held lock.
		throw new RuntimeException( __( '无法定位备份文件列表。', 'wp-panel-backup' ) );
	}
	$index = open_output( $directory . '/files.ndjson', $job['index_offset'] );
	$ordinal = count( $job['packages'] ) + 1;
	$name = sprintf( 'part-%05d.zip', $ordinal );
	$payload_limit = $job['zip_payload_bytes'] ?? LEGACY_ZIP_PAYLOAD_BYTES;
	$bytes = $job['pending_zip_bytes'] ?? 0;
	$members = $job['pending_zip_members'] ?? 0;
	$request_bytes = 0;
	$published_members = 0;
	$finished = ! empty( $job['archive_scan_complete'] );
	try {
		for ( $processed = 0; ! $finished && $processed < 256 && $request_bytes < PACK_REQUEST_BYTES && $bytes < $payload_limit && $members < ZIP_MAX_MEMBERS; ++$processed ) {
			if ( null === $job['file'] ) {
				if ( ! $job['sql_packed'] ) {
					$stat = stat( $directory . '/database.sql' );
					$entry = array( 'kind' => 'sql', 'path' => 'database.sql', 'size' => $stat['size'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'] );
				} else {
					$entry = read_queue_entry( $queue, $job );
					if ( null === $entry ) {
						$finished = true;
						break;
					}
				}
				if ( 'file' !== $entry['kind'] && 'sql' !== $entry['kind'] ) {
					write_bytes( $index, encode_record( $entry ) );
					continue;
				}
				$job['file'] = $entry + array( 'offset' => 0 );
			}

			$file = &$job['file'];
			$source_path = 'sql' === $file['kind'] ? $directory . '/database.sql' : $job['source']['root'] . '/' . $file['path'];
			$length = min( FILE_BLOCK_BYTES, PACK_REQUEST_BYTES - $request_bytes, $payload_limit - $bytes, $file['size'] - $file['offset'] );
			$stream = open_source_file( $source_path, $file );
			try {
				if ( $length > 0 ) {
					++$members;
					$member = sprintf( 'data/%05d', $members );
					$chunk = staged_chunk_path( $directory, $ordinal, $members );
					$part = open_output( $chunk, 0 );
					try {
						if ( 0 !== fseek( $stream, $file['offset'] ) ) {
							/* translators: Relative file or directory path. */
							throw new RuntimeException( sprintf( __( '无法完整读取文件块：%s', 'wp-panel-backup' ), $file['path'] ) );
						}
						if ( $length !== @stream_copy_to_stream( $stream, $part, $length ) ) {
							/* translators: Relative file or directory path. */
							throw new RuntimeException( storage_error( sprintf( __( '无法完整复制文件块：%s。', 'wp-panel-backup' ), $file['path'] ), $directory ) );
						}
						finish_output( $part );
					} finally {
						fclose( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native stream and any held lock.
					}
					$digest = hash_file( 'sha256', $chunk );
					if ( false === $digest ) {
						throw new RuntimeException( __( '无法校验文件块。', 'wp-panel-backup' ) );
					}
					write_bytes( $index, encode_record( array(
						'kind' => 'block', 'target' => $file['kind'], 'path' => $file['path'],
						'package' => $name, 'member' => $member, 'offset' => $file['offset'], 'length' => $length, 'sha256' => $digest,
					) ) );
					$file['offset'] += $length;
					$bytes += $length;
					$request_bytes += $length;
					if ( 'file' === $file['kind'] && isset( $job['file_processed_bytes'] ) ) {
						$job['file_processed_bytes'] += $length;
					}
				}
				if ( $file['offset'] === $file['size'] ) {
					if ( ! rewind( $stream ) ) {
						/* translators: Relative file or directory path. */
						throw new RuntimeException( sprintf( __( '无法重新读取文件以生成完整校验：%s', 'wp-panel-backup' ), $file['path'] ) );
					}
					$hash = hash_init( 'sha256' );
					if ( $file['size'] !== hash_update_stream( $hash, $stream ) ) {
						/* translators: Relative file or directory path. */
						throw new RuntimeException( sprintf( __( '无法计算完整文件校验：%s', 'wp-panel-backup' ), $file['path'] ) );
					}
					$check = open_source_file( $source_path, $file );
					fclose( $check ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native stream and any held lock.
					write_bytes( $index, encode_record( array(
						'kind' => $file['kind'], 'path' => $file['path'], 'size' => $file['size'], 'sha256' => hash_final( $hash ),
					) ) );
					if ( 'sql' === $file['kind'] ) {
						$job['sql_packed'] = true;
					}
					$job['file'] = null;
				}
			} finally {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native stream and any held lock.
				unset( $file );
			}
		}

		$job['index_offset'] = finish_output( $index );
		// Close a ready ZIP separately from the requests that copied its raw data.
		if ( 0 === $request_bytes && $members && ( $finished || $bytes === $payload_limit || $members === ZIP_MAX_MEMBERS ) ) {
			$job['packages'][] = publish_staged_zip( $directory, $name, $ordinal, $members );
			$published_members = $members;
			$bytes = 0;
			$members = 0;
		}
		$job['pending_zip_bytes'] = $bytes;
		$job['pending_zip_members'] = $members;
		$job['archive_scan_complete'] = $finished;
		if ( $finished && ! $members ) {
			$job['phase'] = 'complete';
			$job['completed_at'] = time();
			write_record( $directory . '/manifest.json', array(
				'magic'          => 'wp-panel-backup-development',
				'format_version' => 1,
				'id'             => $job['id'],
				'complete'       => true,
				'mode'           => $job['mode'] ?? 'normal',
				'completed_at'   => gmdate( 'c', $job['completed_at'] ),
				'source'         => array_diff_key( $job['source'], array( 'root' => true ) ),
				'tables'         => array_map( static function ( $name, $rows ) {
					return array( 'name' => $name, 'rows' => $rows );
				}, $job['tables'], $job['table_rows'] ),
				'exclusions'     => $job['options'] + array( 'hidden' => true ),
				'index'          => artifact_metadata( $directory . '/files.ndjson', 'files.ndjson' ),
				'packages'       => $job['packages'],
			) );
		}
	} finally {
		fclose( $queue ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native stream and any held lock.
		fclose( $index ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native stream and any held lock.
	}
	return $published_members;
}

function staged_chunk_path( $directory, $ordinal, $member ) {
	return sprintf( '%s/chunk-%05d-%05d.raw', $directory, $ordinal, $member );
}

function publish_staged_zip( $directory, $name, $ordinal, $members ) {
	$path = $directory . '/building.zip';
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( storage_error( __( '无法创建 ZIP 分包。', 'wp-panel-backup' ), $directory ) );
	}
	for ( $member = 1; $member <= $members; ++$member ) {
		$chunk = staged_chunk_path( $directory, $ordinal, $member );
		if ( ! is_file( $chunk ) || ! @$zip->addFile( $chunk, sprintf( 'data/%05d', $member ) ) ) {
			$zip->unchangeAll();
			@$zip->close();
			throw new RuntimeException( __( '备份文件块缺失或无法加入 ZIP 分包。', 'wp-panel-backup' ) );
		}
	}
	if ( ! @$zip->close() ) {
		throw new RuntimeException( storage_error( __( '无法完成 ZIP 分包写入。', 'wp-panel-backup' ), $directory ) );
	}
	$package = artifact_metadata( $path, $name );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Commit the local file atomically.
	if ( ! @rename( $path, $directory . '/' . $name ) ) {
		throw new RuntimeException( storage_error( __( '无法保存 ZIP 分包。', 'wp-panel-backup' ), $directory ) );
	}
	return $package;
}

function artifact_metadata( $path, $name ) {
	clearstatcache( true, $path );
	$size = filesize( $path );
	$hash = hash_file( 'sha256', $path );
	if ( false === $size || false === $hash ) {
		/* translators: Name of the backup file. */
		throw new RuntimeException( sprintf( __( '无法校验备份材料：%s', 'wp-panel-backup' ), $name ) );
	}
	return array( 'name' => $name, 'size' => $size, 'sha256' => $hash );
}

function advance_export( $directory, $job ) {
	if ( ! in_array( $job['phase'], array( 'scan', 'database', 'package' ), true ) ) {
		return $job;
	}
	$before = $job;
	$published_members = 0;
	try {
		if ( 'scan' === $job['phase'] ) {
			plan_export_files( $directory, $job );
			open_maintenance( dirname( $directory ), $job + array( 'mode' => 'normal' ) );
		} elseif ( 'database' === $job['phase'] ) {
			export_database_step( $directory, $job );
		} else {
			$published_members = pack_export_step( $directory, $job );
		}
		$job['updated_at'] = time();
		write_record( $directory . '/job.json', $job );
		// The previous checkpoint still needs these chunks until the new state is saved.
		for ( $member = 1; $member <= $published_members; ++$member ) {
			@unlink( staged_chunk_path( $directory, count( $job['packages'] ), $member ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only a known local workspace path.
		}
	} catch ( \Throwable $error ) {
		$job = $before;
		$job['phase'] = 'failed';
		$job['error'] = $error->getMessage();
		write_record( $directory . '/job.json', $job );
	}
	if ( 'complete' === $job['phase'] ) {
		unlink( $directory . '/queue.ndjson' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only a known local workspace path.
		unlink( $directory . '/database.sql' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only a known local workspace path.
		foreach ( glob( $directory . '/chunk-*.raw' ) ?: array() as $chunk ) {
			@unlink( $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only a known local workspace path.
		}
	}
	return $job;
}

function list_exports( $root ) {
	$jobs = array();
	foreach ( new \DirectoryIterator( $root ) as $entry ) {
		if ( $entry->isLink() || ! $entry->isDir() || ! preg_match( '/^[0-9a-f]{32}$/D', $entry->getFilename() )
			|| ! is_file( $entry->getPathname() . '/job.json' ) ) {
			continue;
		}
		$job = read_record( $entry->getPathname() . '/job.json' );
		if ( $entry->getFilename() !== $job['id'] ) {
			throw new RuntimeException( __( '备份目录与任务编号不一致。', 'wp-panel-backup' ) );
		}
		$jobs[] = $job;
	}
	usort( $jobs, static function ( $first, $second ) {
		return $second['updated_at'] <=> $first['updated_at'];
	} );
	return $jobs;
}

function export_is_running( $job ) {
	return in_array( $job['phase'], array( 'scan', 'database', 'package' ), true );
}

function export_expired( $job ) {
	return time() >= ( $job['completed_at'] ?? $job['updated_at'] ) + 7 * DAY_IN_SECONDS;
}

function delete_export( $root, $id ) {
	$directory = $root . '/' . $id;
	foreach ( new \DirectoryIterator( $directory ) as $entry ) {
		if ( $entry->isDot() || 'job.json' === $entry->getFilename() ) {
			continue;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only a known local workspace path.
		if ( ( ! $entry->isLink() && ! $entry->isFile() ) || ! unlink( $entry->getPathname() ) ) {
			/* translators: Backup job ID. */
			throw new RuntimeException( sprintf( __( '无法清除备份材料：%s', 'wp-panel-backup' ), $id ) );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only a known local workspace path. Remove the empty local workspace subdirectory.
	if ( ! unlink( $directory . '/job.json' ) || ! rmdir( $directory ) ) {
		/* translators: Backup job ID. */
		throw new RuntimeException( sprintf( __( '无法清除备份任务：%s', 'wp-panel-backup' ), $id ) );
	}
}

function clean_expired_exports( $root ) {
	foreach ( list_exports( $root ) as $job ) {
		if ( export_expired( $job ) ) {
			delete_export( $root, $job['id'] );
		}
	}
}

function rotate_exports( $root, $completed_id ) {
	$kept = 1;
	foreach ( list_exports( $root ) as $job ) {
		if ( 'complete' !== $job['phase'] || $job['id'] === $completed_id ) {
			continue;
		}
		if ( $kept < 2 ) {
			++$kept;
		} else {
			delete_export( $root, $job['id'] );
		}
	}
}
