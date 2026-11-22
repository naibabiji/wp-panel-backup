<?php

namespace WPPanelBackup;

use RuntimeException;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FILE_BLOCK_BYTES = 16 * 1024 * 1024;
const ZIP_PAYLOAD_BYTES = 64 * 1024 * 1024;

function validate_export_source() {
	if ( ! class_exists( ZipArchive::class ) || PHP_INT_SIZE < 8 ) {
		throw new RuntimeException( '备份需要 PHP Zip 扩展及 64 位的 PHP 文件偏移支持。' );
	}
	$source = inspect_source();
	require_database_visibility();
	$source['tables'] = export_table_list( $source );

	if ( ! is_file( $source['root'] . '/wp-config.php' ) ) {
		throw new RuntimeException( '未找到根目录 wp-config.php，本版无法携带供迁移参考的配置。' );
	}
	$drop_ins = array( 'db.php', 'object-cache.php' );
	if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
		$drop_ins[] = 'advanced-cache.php';
	}
	foreach ( $drop_ins as $drop_in ) {
		if ( file_exists( WP_CONTENT_DIR . '/' . $drop_in ) ) {
			throw new RuntimeException( '发现本版无法迁移的配置依赖：' . $drop_in );
		}
	}
	foreach ( array( 'WP_REDIS_PASSWORD', 'CUSTOM_USER_TABLE', 'CUSTOM_USER_META_TABLE' ) as $constant ) {
		if ( defined( $constant ) && constant( $constant ) ) {
			throw new RuntimeException( '发现本版无法迁移的站点运行配置：' . $constant );
		}
	}
	$content = $source['root'] . '/wp-content';
	if ( rtrim( WP_CONTENT_DIR, '/' ) !== $content || rtrim( WP_PLUGIN_DIR, '/' ) !== $content . '/plugins'
		|| rtrim( WPMU_PLUGIN_DIR, '/' ) !== $content . '/mu-plugins'
		|| ( defined( 'UPLOADS' ) && 'wp-content/uploads' !== trim( UPLOADS, '/' ) ) ) {
		throw new RuntimeException( '发现依赖自定义 WordPress 内容路径常量的布局，当前版本无法迁移。' );
	}
	$upload_path = get_option( 'upload_path' );
	if ( is_string( $upload_path ) && preg_match( '~^(?:/|[a-z]:[/\\\\])~i', $upload_path ) ) {
		throw new RuntimeException( '媒体上传配置依赖源站的绝对路径，当前版本无法迁移。' );
	}
	return $source;
}

function create_export( $workspace, $options, $mode ) {
	$source = validate_export_source();
	$id = bin2hex( random_bytes( 16 ) );
	$directory = $workspace . '/' . $id;
	if ( ! mkdir( $directory, 0700 ) ) {
		throw new RuntimeException( '无法在私有目录创建备份任务。' );
	}

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
		'options'       => $options,
		'updated_at'    => time(),
	);
	write_record( $directory . '/job.json', $job );
	return $job;
}

function plan_export_files( $directory, &$job ) {
	$stream = open_output( $directory . '/queue.ndjson', 0 );
	try {
		foreach ( source_entries( $job['source']['root'], '', true ) as $entry ) {
			write_bytes( $stream, encode_record( $entry ) );
		}
		finish_output( $stream );
	} finally {
		fclose( $stream );
	}
	$job['phase'] = 'database';
}

function read_queue_entry( $stream, &$job ) {
	$line = fgets( $stream );
	if ( false === $line ) {
		if ( ! feof( $stream ) ) {
			throw new RuntimeException( '无法读取备份文件列表。' );
		}
		return null;
	}
	$entry = json_decode( $line, true );
	if ( ! is_array( $entry ) ) {
		throw new RuntimeException( '备份文件列表损坏。' );
	}
	$position = ftell( $stream );
	if ( false === $position ) {
		throw new RuntimeException( '无法记录备份文件列表的位置。' );
	}
	$job['queue_offset'] = $position;
	return $entry;
}

function open_source_file( $path, $entry ) {
	clearstatcache( true, $path );
	$stat = lstat( $path );
	if ( false === $stat || realpath( $path ) !== $path || 0100000 !== ( $stat['mode'] & 0170000 ) || 1 !== $stat['nlink'] ) {
		throw new RuntimeException( '备份文件已消失或变成链接/特殊文件：' . $entry['path'] );
	}
	$stream = fopen( $path, 'rb' );
	if ( false === $stream ) {
		throw new RuntimeException( '无法打开备份文件：' . $entry['path'] );
	}
	$opened = fstat( $stream );
	if ( false === $opened || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']
		|| $opened['size'] !== $entry['size'] || $opened['mtime'] !== $entry['mtime'] || $opened['ctime'] !== $entry['ctime'] ) {
		fclose( $stream );
		throw new RuntimeException( '文件在备份期间发生变化，请重新创建备份：' . $entry['path'] );
	}
	return $stream;
}

function pack_export_step( $directory, &$job ) {
	$queue = fopen( $directory . '/queue.ndjson', 'rb' );
	if ( false === $queue ) {
		throw new RuntimeException( '无法打开备份文件列表。' );
	}
	if ( 0 !== fseek( $queue, $job['queue_offset'] ) ) {
		fclose( $queue );
		throw new RuntimeException( '无法定位备份文件列表。' );
	}
	$index = open_output( $directory . '/files.ndjson', $job['index_offset'] );
	$ordinal = count( $job['packages'] ) + 1;
	$name = sprintf( 'part-%05d.zip', $ordinal );
	$zip_path = $directory . '/building.zip';
	$zip = new ZipArchive();
	$chunks = array();
	$zip_open = false;
	$bytes = 0;
	$finished = false;
	try {
		for ( $processed = 0; $processed < 256 && $bytes < ZIP_PAYLOAD_BYTES; ++$processed ) {
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
			$length = min( FILE_BLOCK_BYTES, ZIP_PAYLOAD_BYTES - $bytes, $file['size'] - $file['offset'] );
			$stream = open_source_file( $source_path, $file );
			try {
				if ( $length > 0 ) {
					if ( ! $zip_open ) {
						if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
							throw new RuntimeException( '无法创建 ZIP 分包。' );
						}
						$zip_open = true;
					}
					$member = sprintf( 'data/%05d', count( $chunks ) + 1 );
					$chunk = $directory . '/chunk-' . count( $chunks ) . '.raw';
					$chunks[] = $chunk;
					$part = open_output( $chunk, 0 );
					try {
						if ( 0 !== fseek( $stream, $file['offset'] ) || $length !== stream_copy_to_stream( $stream, $part, $length ) ) {
							throw new RuntimeException( '无法完整读取文件块：' . $file['path'] );
						}
						finish_output( $part );
					} finally {
						fclose( $part );
					}
					if ( ! $zip->addFile( $chunk, $member ) ) {
						throw new RuntimeException( '无法写入 ZIP 分包。' );
					}
					$digest = hash_file( 'sha256', $chunk );
					if ( false === $digest ) {
						throw new RuntimeException( '无法校验文件块。' );
					}
					write_bytes( $index, encode_record( array(
						'kind' => 'block', 'target' => $file['kind'], 'path' => $file['path'],
						'package' => $name, 'member' => $member, 'offset' => $file['offset'], 'length' => $length, 'sha256' => $digest,
					) ) );
					$file['offset'] += $length;
					$bytes += $length;
				}
				if ( $file['offset'] === $file['size'] ) {
					if ( ! rewind( $stream ) ) {
						throw new RuntimeException( '无法重新读取文件以生成完整校验：' . $file['path'] );
					}
					$hash = hash_init( 'sha256' );
					if ( $file['size'] !== hash_update_stream( $hash, $stream ) ) {
						throw new RuntimeException( '无法计算完整文件校验：' . $file['path'] );
					}
					$check = open_source_file( $source_path, $file );
					fclose( $check );
					write_bytes( $index, encode_record( array(
						'kind' => $file['kind'], 'path' => $file['path'], 'size' => $file['size'], 'sha256' => hash_final( $hash ),
					) ) );
					if ( 'sql' === $file['kind'] ) {
						$job['sql_packed'] = true;
					}
					$job['file'] = null;
				}
			} finally {
				fclose( $stream );
				unset( $file );
			}
		}

		if ( $zip_open ) {
			$zip_open = false;
			if ( ! $zip->close() ) {
				throw new RuntimeException( '无法完成 ZIP 分包写入。' );
			}
			$package = artifact_metadata( $zip_path, $name );
			if ( ! rename( $zip_path, $directory . '/' . $name ) ) {
				throw new RuntimeException( '无法保存 ZIP 分包。' );
			}
			$job['packages'][] = $package;
		}
		$job['index_offset'] = finish_output( $index );
		if ( $finished ) {
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
		if ( $zip_open ) {
			$zip->unchangeAll();
			$zip->close();
		}
		foreach ( $chunks as $chunk ) {
			if ( is_file( $chunk ) ) {
				unlink( $chunk );
			}
		}
		fclose( $queue );
		fclose( $index );
	}
}

function artifact_metadata( $path, $name ) {
	clearstatcache( true, $path );
	$size = filesize( $path );
	$hash = hash_file( 'sha256', $path );
	if ( false === $size || false === $hash ) {
		throw new RuntimeException( '无法校验备份材料：' . $name );
	}
	return array( 'name' => $name, 'size' => $size, 'sha256' => $hash );
}

function advance_export( $directory, $job ) {
	if ( ! in_array( $job['phase'], array( 'scan', 'database', 'package' ), true ) ) {
		return $job;
	}
	$before = $job;
	try {
		if ( 'scan' === $job['phase'] ) {
			plan_export_files( $directory, $job );
			open_maintenance( dirname( $directory ), $job + array( 'mode' => 'normal' ) );
		} elseif ( 'database' === $job['phase'] ) {
			export_database_step( $directory, $job );
		} else {
			pack_export_step( $directory, $job );
		}
		$job['updated_at'] = time();
		write_record( $directory . '/job.json', $job );
	} catch ( \Throwable $error ) {
		$job = $before;
		$job['phase'] = 'failed';
		$job['error'] = $error->getMessage();
		write_record( $directory . '/job.json', $job );
	}
	if ( 'complete' === $job['phase'] ) {
		unlink( $directory . '/queue.ndjson' );
		unlink( $directory . '/database.sql' );
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
			throw new RuntimeException( '备份目录与任务编号不一致。' );
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
		if ( ( ! $entry->isLink() && ! $entry->isFile() ) || ! unlink( $entry->getPathname() ) ) {
			throw new RuntimeException( '无法清除备份材料：' . $id );
		}
	}
	if ( ! unlink( $directory . '/job.json' ) || ! rmdir( $directory ) ) {
		throw new RuntimeException( '无法清除备份任务：' . $id );
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
