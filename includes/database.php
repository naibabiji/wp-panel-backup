<?php

namespace WPPanelBackup;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are plain text. Escape at HTML output so JSON responses and saved job status retain the original text.

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sql_name( $name ) {
	return '`' . str_replace( '`', '``', $name ) . '`';
}

/**
 * An empty SHOW result does not prove absence when metadata is hidden by grants.
 * Require direct database-wide grants so ordinary WordPress site accounts can be checked.
 */
function require_database_visibility() {
	$database = array_values( database_rows( 'SELECT DATABASE()' )[0] )[0];
	$scopes = array( '*.*', sql_name( $database ) . '.*', sql_name( addcslashes( $database, '_%' ) ) . '.*' );
	$required = array( 'SELECT', 'SHOW VIEW', 'TRIGGER', 'EVENT', 'EXECUTE' );
	foreach ( database_rows( 'SHOW GRANTS' ) as $grant ) {
		$statement = array_values( $grant )[0];
		if ( ! preg_match( '/^GRANT (.+?) ON (.+?) TO /i', $statement, $parts ) || ! in_array( $parts[2], $scopes, true ) ) {
			continue;
		}
		if ( 'ALL PRIVILEGES' === $parts[1] ) {
			return;
		}
		$required = array_diff( $required, explode( ', ', $parts[1] ) );
	}
	if ( $required ) {
		throw new RuntimeException( __( '当前数据库账号的直接授权不足以核对全库对象，请由主机管理员检查数据库级读取及对象可见权限。', 'wp-panel-backup' ) );
	}
}

function export_table_list( $source ) {
	$statuses = array_column( database_rows( 'SHOW TABLE STATUS' ), 'Engine', 'Name' );
	foreach ( $source['tables'] as $table ) {
		if ( 0 !== strcasecmp( 'InnoDB', $statuses[ $table ] ?? '' ) ) {
			/* translators: Database table name. */
			throw new RuntimeException( sprintf( __( '发现非 InnoDB 表，当前版本无法备份：%s', 'wp-panel-backup' ), $table ) );
		}
	}
	return $source['tables'];
}

function table_definition( $name ) {
	$table = sql_name( $name );
	$create = array_values( database_rows( 'SHOW CREATE TABLE ' . $table )[0] )[1];
	$columns = array();
	foreach ( database_rows( 'SHOW FULL COLUMNS FROM ' . $table ) as $column ) {
		if ( preg_match( '/\b(?:VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', $column['Extra'] ) ) {
			continue;
		}
		$type = strtolower( preg_replace( '/[^a-z].*$/i', '', $column['Type'] ) );
		$columns[ $column['Field'] ] = array(
			'type'      => $type,
			'collation' => $column['Collation'],
		);
	}
	$primary = array();
	foreach ( database_rows( "SHOW INDEX FROM {$table} WHERE Key_name = 'PRIMARY'" ) as $key ) {
		$primary[ (int) $key['Seq_in_index'] ] = $key['Column_name'];
	}
	ksort( $primary );
	return array( $create, $columns, array_values( $primary ) );
}

function sql_value_expression( $name, $column ) {
	$id = sql_name( $name );
	$type = $column['type'];
	if ( 'bit' === $type ) {
		return 'CAST(' . $id . ' AS UNSIGNED)';
	}
	if ( in_array( $type, array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'year' ), true ) ) {
		return $id;
	}
	if ( in_array( $type, array( 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection' ), true ) ) {
		return "IF({$id} IS NULL, NULL, CONCAT(ST_SRID({$id}), ':', HEX(ST_AsWKB({$id}))))";
	}
	return 'HEX(' . $id . ')';
}

function sql_literal( $value, $column, $for_key = false ) {
	if ( null === $value ) {
		return 'NULL';
	}
	$type = $column['type'];
	if ( in_array( $type, array( 'bit', 'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'year' ), true ) ) {
		if ( ! preg_match( '/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:e[+-]?[0-9]+)?$/iD', $value ) ) {
			throw new RuntimeException( __( '读到无法导出的数据库数值。', 'wp-panel-backup' ) );
		}
		return $value;
	}
	if ( in_array( $type, array( 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection' ), true ) ) {
		if ( ! preg_match( '/^([0-9]+):([0-9A-F]+)$/D', $value, $parts ) ) {
			throw new RuntimeException( __( '读到无法导出的空间数据。', 'wp-panel-backup' ) );
		}
		return "ST_GeomFromWKB(X'{$parts[2]}', {$parts[1]})";
	}
	$length = strlen( $value );
	if ( $length % 2 || strspn( $value, '0123456789ABCDEF' ) !== $length ) {
		throw new RuntimeException( __( '读到无法导出的数据库字节。', 'wp-panel-backup' ) );
	}
	$binary = "X'{$value}'";
	if ( 'json' === $type ) {
		return "CONVERT({$binary} USING utf8mb4)";
	}
	if ( $for_key && $column['collation'] ) {
		$collation = $column['collation'];
		$charset = explode( '_', $collation, 2 )[0];
		return 'CONVERT(' . $binary . ' USING ' . sql_name( $charset ) . ') COLLATE ' . sql_name( $collation );
	}
	return $binary;
}

function export_database_step( $directory, &$job ) {
	global $wpdb;

	$stream = open_output( $directory . '/database.sql', $job['sql_offset'] );
	$table = $job['tables'][ $job['table_index'] ];
	try {
		list( $create, $columns, $primary ) = table_definition( $table );
		$names = array_keys( $columns );
		$quoted = array_map( __NAMESPACE__ . '\\sql_name', $names );
		$keys = array_map( __NAMESPACE__ . '\\sql_name', $primary );
		$key_columns = array();
		foreach ( $primary as $index => $key ) {
			if ( in_array( $columns[ $key ]['type'], array( 'enum', 'set' ), true ) ) {
				$keys[ $index ] = 'CAST(' . $keys[ $index ] . ' AS UNSIGNED)';
				$key_columns[ $key ] = $keys[ $index ];
			}
		}
		if ( ! $job['sql_offset'] ) {
			write_bytes( $stream, "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\nSET FOREIGN_KEY_CHECKS=0;\n" );
		}
		if ( null === $job['cursor'] ) {
			write_bytes( $stream, $create . ";\n" );
		}

		$select = array();
		foreach ( $columns as $name => $column ) {
			$select[] = sql_value_expression( $name, $column );
		}
		$select = array_merge( $select, array_values( $key_columns ) );
		$query = 'SELECT ' . ( $select ? implode( ', ', $select ) : '1' ) . ' FROM ' . sql_name( $table );
		if ( $primary ) {
			if ( null !== $job['cursor'] ) {
				$after = array();
				$equal = '';
				foreach ( $primary as $index => $key ) {
					$column = isset( $key_columns[ $key ] ) ? array( 'type' => 'bigint' ) : $columns[ $key ];
					$value = sql_literal( $job['cursor'][ $index ], $column, true );
					$after[] = '(' . $equal . $keys[ $index ] . ' > ' . $value . ')';
					$equal .= $keys[ $index ] . ' = ' . $value . ' AND ';
				}
				$query .= ' WHERE ' . implode( ' OR ', $after );
			}
			$query .= ' ORDER BY ' . implode( ', ', $keys ) . ' LIMIT 512';
		}

		$session = array_values( database_rows( 'SELECT @@session.time_zone' )[0] );
		$connection = $wpdb->dbh;
		if ( ! $connection instanceof \mysqli ) {
			throw new RuntimeException( __( '当前 WordPress 数据库连接不支持流式读取。', 'wp-panel-backup' ) );
		}
		$result = false;
		try {
			if ( ! $connection->query( "SET SESSION time_zone = '+00:00'" ) ) {
				throw new RuntimeException( __( '无法设置数据库导出时区。', 'wp-panel-backup' ) );
			}
			$result = $connection->query( $query, MYSQLI_USE_RESULT );
			if ( ! $result ) {
				throw new RuntimeException( __( '无法读取数据行。', 'wp-panel-backup' ) );
			}
			$row_count = 0;
			$written = 0;
			$last = null;
			$started = microtime( true );
			$insert = 'INSERT INTO ' . sql_name( $table ) . ' (' . implode( ', ', $quoted ) . ') VALUES (';
			while ( is_array( $row = $result->fetch_row() ) ) {
				$literals = array();
				$values = array();
				foreach ( $names as $index => $name ) {
					$values[ $name ] = $row[ $index ];
					$literals[] = sql_literal( $row[ $index ], $columns[ $name ] );
				}
				$line = $insert . implode( ', ', $literals ) . ");\n";
				write_bytes( $stream, $line );
				++$row_count;
				$written += strlen( $line );
				$last = $values;
				foreach ( array_keys( $key_columns ) as $index => $key ) {
					$last[ $key ] = $row[ count( $names ) + $index ];
				}
				if ( ! $primary && microtime( true ) - $started > 15 ) {
					throw new RuntimeException( __( '无主键表无法在一次短请求内导完。', 'wp-panel-backup' ) );
				}
				if ( $primary && $written >= 4 * 1024 * 1024 ) {
					break;
				}
			}
			if ( $connection->errno || ( isset( $row ) && false === $row ) ) {
				throw new RuntimeException( __( '数据库数据流读取中断。', 'wp-panel-backup' ) );
			}
		} finally {
			if ( $result instanceof \mysqli_result ) {
				$result->free();
			}
			$connection->query( $wpdb->prepare( 'SET SESSION time_zone = %s', $session[0] ) );
		}

		$job['table_rows'][ $job['table_index'] ] += $row_count;
		if ( $primary && ( 512 === $row_count || $written >= 4 * 1024 * 1024 ) ) {
			$job['cursor'] = array();
			foreach ( $primary as $key ) {
				$job['cursor'][] = $last[ $key ];
			}
		} else {
			$job['cursor'] = null;
			++$job['table_index'];
		}
		if ( $job['table_index'] === count( $job['tables'] ) ) {
			write_bytes( $stream, "SET FOREIGN_KEY_CHECKS=1;\n" );
			$job['phase'] = 'package';
		}
		$job['sql_offset'] = finish_output( $stream );
	} catch ( \Throwable $error ) {
		$message = $error instanceof \mysqli_sql_exception ? __( '数据库查询或读取失败。', 'wp-panel-backup' ) : $error->getMessage();
		/* translators: 1: Database table name, 2: Export error message. */
		throw new RuntimeException( sprintf( __( '导出表 %1$s 失败：%2$s', 'wp-panel-backup' ), $table, $message ), 0, $error );
	} finally {
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native SQL output stream.
	}
}
