<?php

namespace WPPanelBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function register_backup_page() {
	$page = add_management_page( 'WP PANEL Backup', 'WP PANEL Backup', 'manage_options', 'wp-panel-backup', __NAMESPACE__ . '\\render_backup_page' );
	if ( $page ) {
		add_action( 'load-' . $page, __NAMESPACE__ . '\\authorize_preflight_request' );
	}
}

function backup_plugin_action_links( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		$links = array( 'settings' => '<a href="' . esc_url( backup_page_url() ) . '">设置</a>' ) + $links;
	}
	return $links;
}

function authorize_preflight_request() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '你没有访问备份页面的权限。', '', array( 'response' => 403 ) );
	}
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		check_admin_referer( 'wp-panel-backup-preflight', '_wp_panel_backup_nonce' );
	}
}

function render_backup_page() {
	$source = null;
	$count  = 0;
	$error  = '';
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		try {
			$source = inspect_source();
			$storage = workspace();
			$excluded = null === $storage ? '' : substr( $storage, strlen( $source['root'] ) + 1 );
			foreach ( source_entries( $source['root'], '', false, $excluded ) as $entry ) {
				if ( 'file' === $entry['kind'] ) {
					++$count;
				}
			}
		} catch ( \RuntimeException $exception ) {
			$error = $exception->getMessage();
		}
	}
	?>
	<div class="wrap">
		<h1>WP PANEL Backup</h1>
		<p>检查站点地址、文件布局和数据库对象。扫描时跳过隐藏文件和目录。</p>
		<?php if ( '' !== $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php elseif ( null !== $source ) : ?>
			<div class="notice notice-success"><p><?php
				echo esc_html( sprintf( '%s 的基础检查通过，共扫描 %d 个文件，列出 %d 张可见数据表。', $source['home'], $count, count( $source['tables'] ) ) );
			?></p></div>
		<?php endif; ?>
		<p>首次开始备份时会自动创建工作目录，并检查匿名访客无法直接读取备份材料。</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=wp-panel-backup' ) ); ?>">
			<?php wp_nonce_field( 'wp-panel-backup-preflight', '_wp_panel_backup_nonce' ); ?>
			<?php submit_button( '运行基础预检' ); ?>
		</form>
		<?php render_export_controls(); ?>
	</div>
	<?php
}

function backup_page_url() {
	return admin_url( 'tools.php?page=wp-panel-backup' );
}

function control_export() {
	if ( ! current_user_can( 'manage_options' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_die( '你没有操作备份任务的权限。', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce' );
	$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
	$id = sanitize_key( wp_unslash( $_POST['job'] ?? '' ) );
	$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'normal' ) );
	$options = array( 'cache' => isset( $_POST['exclude_cache'] ), 'backups' => isset( $_POST['exclude_backups'] ) );
	try {
		$job = with_workspace_lock( static function ( $root ) use ( $operation, $id, $mode, $options ) {
			return apply_export_operation( $root, $operation, $id, $mode, $options );
		}, 'start' === $operation );
	} catch ( \Throwable $error ) {
		wp_die( esc_html( $error->getMessage() ), '备份任务未能运行', array( 'response' => 400, 'back_link' => true ) );
	}
	$args = $job ? array( 'job' => $job['id'] ) : array();
	if ( $job && export_is_running( $job ) ) {
		$args['run'] = '1';
	}
	wp_safe_redirect( add_query_arg( $args, backup_page_url() ), 303 );
	exit;
}

function apply_export_operation( $root, $operation, $id, $mode, $options ) {
	$maintenance = maintenance_record( $root );
	if ( in_array( $operation, array( 'release_temporary', 'cancel_move' ), true ) ) {
		$release_mode = 'cancel_move' === $operation ? 'move' : 'temporary';
		if ( $maintenance && $release_mode === $maintenance['mode'] ) {
			foreach ( list_exports( $root ) as $candidate ) {
				if ( ( $candidate['mode'] ?? 'normal' ) === $release_mode && export_is_running( $candidate ) ) {
					cancel_export( $root, $candidate );
				}
			}
			release_maintenance( $root, $release_mode );
		}
		return null;
	}
	clean_expired_exports( $root );
	if ( 'cleanup' === $operation ) {
		return null;
	}
	if ( 'start' === $operation ) {
		if ( ! in_array( $mode, array( 'normal', 'temporary', 'move' ), true ) ) {
			throw new \RuntimeException( '无效的备份方式。' );
		}
		if ( $maintenance && ( 'move' !== $maintenance['mode'] || 'move' !== $mode ) ) {
			throw new \RuntimeException( '当前存在本插件的维护状态，请先处理该状态。' );
		}
		foreach ( list_exports( $root ) as $job ) {
			if ( export_is_running( $job ) ) {
				throw new \RuntimeException( '已有待完成的备份任务，请先继续或取消。' );
			}
		}
		return create_export( $root, $options, $mode );
	}

	$directory = export_directory( $root, $id );
	$job = read_record( $directory . '/job.json' );
	if ( 'delete' === $operation && ! export_is_running( $job ) ) {
		delete_export( $root, $id );
		return null;
	}
	if ( 'cancel' === $operation ) {
		return export_is_running( $job ) ? cancel_export( $root, $job ) : $job;
	}
	if ( ! in_array( $operation, array( 'advance', 'resume' ), true ) ) {
		throw new \RuntimeException( '无效的备份操作。' );
	}
	$was_running = export_is_running( $job );
	if ( $was_running && ( 'resume' === $operation || time() - ( $job['protection_checked_at'] ?? 0 ) >= MINUTE_IN_SECONDS ) ) {
		verify_workspace_protection( $root, $directory, source_address( get_option( 'home' ) ) );
		$job['protection_checked_at'] = time();
	}
	$job = advance_export( $directory, $job );
	if ( $was_running && 'complete' === $job['phase'] ) {
		release_maintenance( $root, 'temporary', $job['id'] );
		rotate_exports( $root, $job['id'] );
	}
	return $job;
}

function export_directory( $root, $id ) {
	$directory = $root . '/' . $id;
	if ( ! preg_match( '/^[a-f0-9]{32}$/D', $id ) || is_link( $directory ) || ! is_dir( $directory ) ) {
		throw new \RuntimeException( '备份任务不存在或已到期。' );
	}
	return $directory;
}

function cancel_export( $root, $job ) {
	$job['phase'] = 'cancelled';
	write_record( $root . '/' . $job['id'] . '/job.json', $job );
	return $job;
}

function render_export_controls() {
	try {
		$root = workspace();
		$jobs = null === $root ? array() : list_exports( $root );
		$maintenance = null === $root ? null : maintenance_record( $root );
	} catch ( \RuntimeException $error ) {
		echo '<div class="notice notice-warning"><p>' . esc_html( $error->getMessage() ) . '</p></div>';
		return;
	}
	$active = null;
	$expired = false;
	$phases = array( 'scan' => '待扫描文件', 'database' => '导出数据库', 'package' => '生成 ZIP 分包', 'complete' => '备份生成完成', 'failed' => '备份失败', 'cancelled' => '备份已取消' );
	$modes = array( 'normal' => '正常备份', 'temporary' => '临时维护备份', 'move' => '搬家备份' );
	if ( $maintenance ) {
		if ( 'move' === $maintenance['mode'] ) {
			echo '<div class="notice notice-warning"><p>搬家维护中。取消备份后，原站仍会保持维护。</p></div>';
			render_control_button( 'cancel_move', '取消搬家并恢复原站' );
		} else {
			echo '<div class="notice notice-warning"><p>临时维护中。备份成功后将自动恢复原站。</p></div>';
			render_control_button( 'release_temporary', '结束临时维护并恢复原站' );
		}
	}
	foreach ( $jobs as $job ) {
		if ( export_expired( $job ) ) {
			$expired = true;
			continue;
		}
		if ( export_is_running( $job ) ) {
			$active = $job;
		}
		?>
		<p><?php echo esc_html( sprintf( '%s · %s · %s · %d 个 ZIP 包', $job['id'], $modes[ $job['mode'] ?? 'normal' ], $phases[ $job['phase'] ], count( $job['packages'] ) ) ); ?></p>
		<?php if ( 'database' === $job['phase'] ) : ?>
			<p><?php echo esc_html( sprintf( '数据库已导出 %d/%d 张表。', $job['table_index'], count( $job['tables'] ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( 'failed' === $job['phase'] ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $job['error'] ); ?></p></div>
		<?php endif; ?>
		<?php
		if ( 'complete' === $job['phase'] ) {
			render_export_downloads( $job );
		}
		if ( ! export_is_running( $job ) ) {
			render_control_button( 'delete', '删除这套备份材料', $job['id'] );
		}
	}
	if ( $expired ) {
		render_control_button( 'cleanup', '清理已过期材料' );
	}
	if ( $active ) {
		$run = ( $_GET['job'] ?? '' ) === $active['id'] && '1' === ( $_GET['run'] ?? '' );
		wp_enqueue_script( 'wp-panel-backup-advance', plugins_url( 'assets/advance.js', dirname( __DIR__ ) . '/wp-panel-backup.php' ), array(), '0.0.0-dev', true );
		?>
		<form id="wp-panel-backup-advance" data-running="<?php echo $run ? '1' : '0'; ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce' ); ?>
			<input type="hidden" name="action" value="wp_panel_backup_control">
			<input type="hidden" name="operation" value="<?php echo $run ? 'advance' : 'resume'; ?>">
			<input type="hidden" name="job" value="<?php echo esc_attr( $active['id'] ); ?>">
			<?php submit_button( '继续备份' ); ?>
		</form>
		<p>保持此页打开以推进备份。关闭后可返回继续。</p>
		<?php
		render_control_button( 'cancel', '取消备份', $active['id'] );
		return;
	}
	if ( $maintenance && 'temporary' === $maintenance['mode'] ) {
		return;
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce' ); ?>
		<input type="hidden" name="action" value="wp_panel_backup_control">
		<input type="hidden" name="operation" value="start">
		<fieldset>
			<legend>备份方式</legend>
			<?php if ( ! $maintenance ) : ?>
				<p><label><input type="radio" name="mode" value="normal" checked> 正常备份</label>（网站继续运行，文件和数据库可能来自不同时间点）</p>
				<p><label><input type="radio" name="mode" value="temporary"> 临时维护备份</label>（成功后自动开放原站）</p>
			<?php endif; ?>
			<p><label><input type="radio" name="mode" value="move" <?php checked( (bool) $maintenance ); ?>> 搬家备份</label>（原站持续维护，直到管理员明确取消搬家）</p>
		</fieldset>
		<p><label><input type="checkbox" name="exclude_cache" checked> 排除已识别的缓存目录</label>（当前无匹配）</p>
		<p><label><input type="checkbox" name="exclude_backups" checked> 排除已识别的备份目录</label>（当前无匹配）</p>
		<?php submit_button( '开始备份' ); ?>
	</form>
	<?php
}

function render_control_button( $operation, $label, $id = '' ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wp_panel_backup_control">
		<input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>">
		<input type="hidden" name="job" value="<?php echo esc_attr( $id ); ?>">
		<?php wp_nonce_field( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce' ); ?>
		<?php submit_button( $label, 'secondary', 'submit', false ); ?>
	</form>
	<?php
}

function render_export_downloads( $job ) {
	$names = array_merge( array( 'manifest.json', 'files.ndjson' ), array_column( $job['packages'], 'name' ) );
	$expiry = wp_date( 'Y-m-d H:i T', $job['completed_at'] + 7 * DAY_IN_SECONDS );
	?>
	<p><?php echo esc_html( sprintf( '请下载本套的全部 %d 份材料。到期时间 %s。刷新此页可查看已发起的下载。', count( $names ), $expiry ) ); ?></p>
	<ul>
		<?php foreach ( $names as $name ) : ?>
			<li>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wp_panel_backup_download">
					<input type="hidden" name="job" value="<?php echo esc_attr( $job['id'] ); ?>">
					<input type="hidden" name="artifact" value="<?php echo esc_attr( $name ); ?>">
					<?php wp_nonce_field( 'wp-panel-backup-download-' . $job['id'], '_wp_panel_backup_download_nonce' ); ?>
					<?php submit_button( '下载 ' . $name, 'secondary small', 'submit', false ); ?>
					<?php if ( isset( $job['downloads'][ $name ] ) ) : ?><span>已发起下载</span><?php endif; ?>
				</form>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}

function download_export() {
	if ( ! current_user_can( 'manage_options' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_die( '你没有下载备份的权限。', '', array( 'response' => 403 ) );
	}
	$id = sanitize_key( wp_unslash( $_POST['job'] ?? '' ) );
	$name = wp_unslash( $_POST['artifact'] ?? '' );
	check_admin_referer( 'wp-panel-backup-download-' . $id, '_wp_panel_backup_download_nonce' );
	try {
		list( $stream, $size ) = with_workspace_lock( static function ( $root ) use ( $id, $name ) {
			$directory = export_directory( $root, $id );
			$job_path = $directory . '/job.json';
			$job = read_record( $job_path );
			$names = array_merge( array( 'manifest.json', 'files.ndjson' ), array_column( $job['packages'], 'name' ) );
			if ( 'complete' !== $job['phase'] || export_expired( $job ) || ! in_array( $name, $names, true ) ) {
				throw new \RuntimeException( '这份备份材料不存在或已到期。' );
			}
			$path = $directory . '/' . $name;
			$stream = is_file( $path ) && ! is_link( $path ) ? fopen( $path, 'rb' ) : false;
			if ( false === $stream ) {
				throw new \RuntimeException( '无法打开备份材料。' );
			}
			try {
				$stat = fstat( $stream );
				if ( false === $stat ) {
					throw new \RuntimeException( '无法读取备份材料的大小。' );
				}
				$job['downloads'][ $name ] = time();
				write_record( $job_path, $job );
				return array( $stream, $stat['size'] );
			} catch ( \Throwable $error ) {
				fclose( $stream );
				throw $error;
			}
		} );
	} catch ( \Throwable $error ) {
		wp_die( esc_html( $error->getMessage() ), '下载未能开始', array( 'response' => 404, 'back_link' => true ) );
	}
	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Disposition: attachment; filename="' . $name . '"' );
	header( 'Content-Length: ' . $size );
	fpassthru( $stream );
	fclose( $stream );
	exit;
}
