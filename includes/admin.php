<?php

namespace WPPanelBackup;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are plain text. Escape at HTML output so JSON responses and saved job status retain the original text.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function register_backup_page() {
	add_management_page( 'WP PANEL Backup', 'WP PANEL Backup', 'manage_options', 'wp-panel-backup', __NAMESPACE__ . '\\render_backup_page' );
}

function enqueue_backup_assets( $hook ) {
	if ( 'tools_page_wp-panel-backup' !== $hook ) {
		return;
	}
	$plugin = dirname( __DIR__ ) . '/wp-panel-backup.php';
	wp_enqueue_style( 'wp-panel-backup-admin', plugins_url( 'assets/admin.css', $plugin ), array(), (string) filemtime( dirname( $plugin ) . '/assets/admin.css' ) );
	wp_enqueue_script( 'wp-panel-backup-advance', plugins_url( 'assets/advance.js', $plugin ), array( 'wp-i18n' ), (string) filemtime( dirname( $plugin ) . '/assets/advance.js' ), true );
	wp_set_script_translations( 'wp-panel-backup-advance', 'wp-panel-backup' );
}

function backup_plugin_action_links( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		$links = array( 'settings' => '<a href="' . esc_url( backup_page_url() ) . '">' . esc_html__( '备份', 'wp-panel-backup' ) . '</a>' ) + $links;
	}
	return $links;
}

function backup_list_view() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The view parameter only selects a page to display.
	return 'backups' === sanitize_key( wp_unslash( $_GET['view'] ?? '' ) );
}

function render_backup_page() {
	$list = backup_list_view();
	?>
	<div class="wrap wp-panel-backup">
		<header class="wppb-header">
			<div class="wppb-header-content">
				<div>
					<h1>WP PANEL Backup</h1>
					<p><?php esc_html_e( '备份 WordPress 站点文件和数据库，供 WP PANEL 恢复网站时使用。', 'wp-panel-backup' ); ?></p>
				</div>
				<a class="wppb-site-link" href="https://wp-panel.org/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'WP PANEL 官网', 'wp-panel-backup' ); ?><span class="screen-reader-text"><?php esc_html_e( '（在新标签页打开）', 'wp-panel-backup' ); ?></span></a>
			</div>
			<nav class="wppb-tabs" aria-label="<?php esc_attr_e( '备份管理', 'wp-panel-backup' ); ?>">
				<a href="<?php echo esc_url( backup_page_url() ); ?>" <?php if ( ! $list ) : ?>aria-current="page"<?php endif; ?>><?php esc_html_e( '创建备份', 'wp-panel-backup' ); ?></a>
				<a href="<?php echo esc_url( backup_page_url( true ) ); ?>" <?php if ( $list ) : ?>aria-current="page"<?php endif; ?>><?php esc_html_e( '备份列表', 'wp-panel-backup' ); ?></a>
			</nav>
		</header>
		<?php render_export_controls( $list ); ?>
	</div>
	<?php
}

function backup_page_url( $list = false ) {
	$url = admin_url( 'tools.php?page=wp-panel-backup' );
	return $list ? add_query_arg( 'view', 'backups', $url ) : $url;
}

function advance_export_ajax() {
	if ( ! current_user_can( 'manage_options' ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) )
		|| ! check_ajax_referer( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( '无法验证备份请求。请重新打开备份页面后继续。', 'wp-panel-backup' ) ), 403 );
	}
	$id = sanitize_key( wp_unslash( $_POST['job'] ?? '' ) );
	try {
		$progress = with_workspace_lock( static function ( $root ) use ( $id ) {
			$job = apply_export_operation( $root, 'advance', $id, 'normal', array(), DEFAULT_ZIP_PAYLOAD_MIB );
			$progress = export_progress( $job );
			$progress['maintenance'] = null !== maintenance_record( $root );
			if ( 'complete' === $job['phase'] ) {
				$progress['listUrl'] = add_query_arg( 'job', $job['id'], backup_page_url( true ) );
			}
			return $progress;
		} );
	} catch ( \Throwable $error ) {
		wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
	}
	wp_send_json_success( $progress );
}

function export_progress( $job ) {
	$labels = array(
		'scan' => __( '检查备份文件', 'wp-panel-backup' ), 'database' => __( '导出数据库', 'wp-panel-backup' ), 'package' => __( '生成 ZIP 分包', 'wp-panel-backup' ),
		'complete' => __( '备份完成', 'wp-panel-backup' ), 'failed' => __( '备份失败', 'wp-panel-backup' ), 'cancelled' => __( '备份已取消', 'wp-panel-backup' ),
	);
	$sql_bytes = $job['sql_offset'];
	$file_bytes = $job['file_processed_bytes'] ?? 0;
	// Older tasks may have read source files before the byte counter was added.
	$file_size = ! isset( $job['file_processed_bytes'] ) && ! empty( $job['queue_offset'] )
		? __( '未记录', 'wp-panel-backup' )
		: size_format( $file_bytes, $file_bytes ? 1 : 0 );
	return array(
		'phase' => $job['phase'],
		'label' => $labels[ $job['phase'] ],
		/* translators: 1: Size of SQL written so far. 2: Uncompressed size of site file blocks collected so far. */
		'processed' => sprintf( __( '已导出 SQL %1$s · 已处理站点文件 %2$s', 'wp-panel-backup' ), size_format( $sql_bytes, $sql_bytes ? 1 : 0 ), $file_size ),
		'running' => export_is_running( $job ),
		'error' => 'failed' === $job['phase'] ? $job['error'] : '',
	);
}

function control_export() {
	if ( ! current_user_can( 'manage_options' ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
		wp_die( esc_html__( '你没有操作备份任务的权限。', 'wp-panel-backup' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce' );
	$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
	$id = sanitize_key( wp_unslash( $_POST['job'] ?? '' ) );
	$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'normal' ) );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The entire value must match decimal digits before conversion and the size allowlist.
	$submitted_payload = wp_unslash( $_POST['zip_payload_mib'] ?? (string) DEFAULT_ZIP_PAYLOAD_MIB );
	$zip_payload_mib = is_string( $submitted_payload ) && preg_match( '/^[0-9]+$/D', $submitted_payload ) ? (int) $submitted_payload : 0;
	$has_settings = isset( $_POST['directory_exclusion_options'] );
	$options = array( 'cache' => ! $has_settings || isset( $_POST['exclude_cache'] ), 'backups' => ! $has_settings || isset( $_POST['exclude_backups'] ) );
	try {
		$job = with_workspace_lock( static function ( $root ) use ( $operation, $id, $mode, $options, $zip_payload_mib ) {
			return apply_export_operation( $root, $operation, $id, $mode, $options, $zip_payload_mib );
		}, 'start' === $operation );
	} catch ( \Throwable $error ) {
		wp_die( esc_html( $error->getMessage() ), esc_html__( '备份任务未能运行', 'wp-panel-backup' ), array( 'response' => 400, 'back_link' => true ) );
	}
	$args = $job ? array( 'job' => $job['id'] ) : array();
	if ( $job && export_is_running( $job ) ) {
		$args['run'] = '1';
	}
	$list = ( $job && 'complete' === $job['phase'] ) || 'backups' === sanitize_key( wp_unslash( $_POST['return_view'] ?? '' ) );
	wp_safe_redirect( add_query_arg( $args, backup_page_url( $list ) ), 303 );
	exit;
}

function apply_export_operation( $root, $operation, $id, $mode, $options, $zip_payload_mib ) {
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
			throw new \RuntimeException( __( '无效的备份方式。', 'wp-panel-backup' ) );
		}
		if ( $maintenance && ( 'move' !== $maintenance['mode'] || 'move' !== $mode ) ) {
			throw new \RuntimeException( __( '当前存在本插件的维护状态，请先处理该状态。', 'wp-panel-backup' ) );
		}
		foreach ( list_exports( $root ) as $job ) {
			if ( export_is_running( $job ) ) {
				throw new \RuntimeException( __( '已有待完成的备份任务，请先继续或取消。', 'wp-panel-backup' ) );
			}
		}
		return create_export( $root, $options, $mode, $zip_payload_mib );
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
		throw new \RuntimeException( __( '无效的备份操作。', 'wp-panel-backup' ) );
	}
	$was_running = export_is_running( $job );
	if ( $was_running ) {
		prepare_workspace( $root );
		prepare_directory_indexes( $directory );
	}
	$steps_left = 'database' === $job['phase'] ? 8 : 1;
	$table_index = $job['table_index'];
	$deadline = microtime( true ) + 2;
	do {
		$job = advance_export( $directory, $job );
		// Each step saves its checkpoint. Yield before starting another table.
	} while ( --$steps_left > 0 && 'database' === $job['phase'] && $table_index === $job['table_index'] && microtime( true ) < $deadline );
	if ( $was_running && 'complete' === $job['phase'] ) {
		release_maintenance( $root, 'temporary', $job['id'] );
		rotate_exports( $root, $job['id'] );
	}
	return $job;
}

function export_directory( $root, $id ) {
	$directory = $root . '/' . $id;
	if ( ! preg_match( '/^[a-f0-9]{32}$/D', $id ) || is_link( $directory ) || ! is_dir( $directory ) ) {
		throw new \RuntimeException( __( '备份任务不存在或已到期。', 'wp-panel-backup' ) );
	}
	return $directory;
}

function cancel_export( $root, $job ) {
	$job['phase'] = 'cancelled';
	write_record( $root . '/' . $job['id'] . '/job.json', $job );
	return $job;
}

function render_php_migration_notice() {
	$target_php = '8.3';
	if ( $target_php === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ) {
		return;
	}
	?>
	<section class="wppb-notice wppb-warning" aria-label="<?php esc_attr_e( 'PHP 迁移兼容提示', 'wp-panel-backup' ); ?>">
		<p><strong><?php esc_html_e( 'PHP 版本不同，迁移后请测试网站', 'wp-panel-backup' ); ?></strong></p>
		<p><?php
			/* translators: 1: PHP version on the source site, 2: PHP version on WP PANEL. */
			echo esc_html( sprintf( __( '源站使用 PHP %1$s，WP PANEL 使用 PHP %2$s。恢复后，WordPress、主题、插件或自定义代码可能因版本差异报错，导致网站无法打开。', 'wp-panel-backup' ), PHP_VERSION, $target_php ) );
		?></p>
		<p><?php esc_html_e( '备份可以继续。恢复后如有问题，请查看目标站的 PHP 错误日志，可自行修复或使用 WP PANEL 的 AI 开发功能辅助排查。', 'wp-panel-backup' ); ?></p>
	</section>
	<?php
}

function render_export_controls( $list ) {
	try {
		$root = workspace();
		$jobs = null === $root ? array() : list_exports( $root );
		$maintenance = $list || null === $root ? null : maintenance_record( $root );
	} catch ( \RuntimeException $error ) {
		echo '<p class="wppb-notice wppb-error">' . esc_html( $error->getMessage() ) . '</p>';
		return;
	}
	$active = null;
	$expired = false;
	$modes = array( 'normal' => __( '正常备份', 'wp-panel-backup' ), 'temporary' => __( '临时维护备份', 'wp-panel-backup' ), 'move' => __( '搬家备份', 'wp-panel-backup' ) );
	if ( $list ) {
		render_completed_exports( $jobs, $modes );
		return;
	}
	render_php_migration_notice();
	$unfinished = array();
	foreach ( $jobs as $job ) {
		if ( 'complete' === $job['phase'] ) {
			continue;
		}
		if ( export_expired( $job ) ) {
			$expired = true;
		} elseif ( export_is_running( $job ) ) {
			$active = $job;
		} else {
			$unfinished[] = $job;
		}
	}
	$maintenance_mode = $maintenance['mode'] ?? $active['mode'] ?? 'normal';
	if ( 'normal' !== $maintenance_mode ) {
		render_backup_maintenance( $maintenance_mode, (bool) $maintenance );
	}
	if ( $active ) {
		render_active_export( $active, $modes );
	}
	foreach ( $unfinished as $job ) {
		$progress = export_progress( $job );
		?>
		<section class="wppb-card">
			<h2><?php echo esc_html( $progress['label'] ); ?></h2>
			<p class="wppb-meta"><?php echo esc_html( $modes[ $job['mode'] ?? 'normal' ] ); ?></p>
			<?php if ( $progress['error'] ) : ?>
				<p class="wppb-notice wppb-error"><?php echo esc_html( $progress['error'] ); ?></p>
			<?php endif; ?>
			<?php render_control_button( 'delete', __( '删除未完成的材料', 'wp-panel-backup' ), $job['id'] ); ?>
		</section>
		<?php
	}
	if ( $expired ) {
		render_control_button( 'cleanup', __( '清理已过期材料', 'wp-panel-backup' ) );
	}
	if ( $active ) {
		return;
	}
	if ( $maintenance && 'temporary' === $maintenance['mode'] ) {
		return;
	}
	$matches = detected_directory_exclusions( realpath( ABSPATH ) );
	$cache_dropins = array_filter( CACHE_DROPIN_PATHS, static function ( $path ) {
		return is_file( ABSPATH . $path ) || is_link( ABSPATH . $path );
	} );
	?>
	<form class="wppb-card wppb-create" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<p class="wppb-meta"><?php esc_html_e( '开始后会自动检查站点、文件、数据库和工作目录。扫描时跳过隐藏文件和目录。', 'wp-panel-backup' ); ?></p>
		<?php wp_nonce_field( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce' ); ?>
		<input type="hidden" name="action" value="wp_panel_backup_control">
		<input type="hidden" name="operation" value="start">
		<fieldset class="wppb-section">
			<legend><?php esc_html_e( '备份方式', 'wp-panel-backup' ); ?></legend>
			<div class="wppb-modes">
				<?php if ( ! $maintenance ) : ?>
					<label class="wppb-mode">
						<input type="radio" name="mode" value="normal" checked>
						<span><strong><?php esc_html_e( '正常备份', 'wp-panel-backup' ); ?></strong><small><?php esc_html_e( '网站继续运行，文件和数据库可能来自不同时间点。', 'wp-panel-backup' ); ?></small></span>
					</label>
					<label class="wppb-mode">
						<input type="radio" name="mode" value="temporary">
						<span><strong><?php esc_html_e( '临时维护备份', 'wp-panel-backup' ); ?></strong><small><?php esc_html_e( '备份成功后自动开放原站。', 'wp-panel-backup' ); ?></small></span>
					</label>
				<?php endif; ?>
				<label class="wppb-mode">
					<input type="radio" name="mode" value="move" <?php checked( (bool) $maintenance ); ?>>
					<span><strong><?php esc_html_e( '搬家备份', 'wp-panel-backup' ); ?></strong><small><?php esc_html_e( '原站持续维护，直到管理员明确取消搬家。', 'wp-panel-backup' ); ?></small></span>
				</label>
			</div>
		</fieldset>
		<div class="wppb-section">
			<label for="wp-panel-backup-zip-size"><strong><?php esc_html_e( 'ZIP 分包大小', 'wp-panel-backup' ); ?></strong></label>
			<select id="wp-panel-backup-zip-size" name="zip_payload_mib" aria-describedby="wp-panel-backup-zip-help">
				<?php foreach ( ZIP_PAYLOAD_OPTIONS as $size ) : ?>
					<option value="<?php echo esc_attr( (string) $size ); ?>" <?php selected( $size, DEFAULT_ZIP_PAYLOAD_MIB ); ?>><?php echo esc_html( size_format( $size * 1024 * 1024 ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="wppb-meta" id="wp-panel-backup-zip-help"><?php esc_html_e( '按压缩前的数据量计算。主机容易超时时可选择较小的分包。完成后可整套下载或逐份下载。', 'wp-panel-backup' ); ?></p>
		</div>
		<details class="wppb-section wppb-fold" <?php if ( $matches || $cache_dropins ) : ?>open<?php endif; ?>>
			<summary><?php esc_html_e( '目录排除选项', 'wp-panel-backup' ); ?></summary>
			<?php if ( $cache_dropins ) : ?>
				<p><?php esc_html_e( '以下缓存加载文件将自动跳过。源站文件保留，恢复后请在目标站重新配置缓存：', 'wp-panel-backup' ); ?></p>
				<ul class="ul-disc">
					<?php foreach ( $cache_dropins as $path ) : ?>
						<li><code><?php echo esc_html( $path ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $matches ) : ?>
				<p><?php esc_html_e( '默认跳过以下已识别的缓存和备份目录，源站文件保留：', 'wp-panel-backup' ); ?></p>
				<ul class="ul-disc">
					<?php foreach ( $matches as $path => $match ) : ?>
						<li><code><?php echo esc_html( $path ); ?></code>（<?php echo esc_html( $match['plugin'] ); ?>）</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( '未发现可自动排除的第三方缓存或备份目录。', 'wp-panel-backup' ); ?></p>
			<?php endif; ?>
			<input type="hidden" name="directory_exclusion_options" value="1">
			<p><label><input type="checkbox" name="exclude_cache" checked> <?php esc_html_e( '排除已识别的缓存目录', 'wp-panel-backup' ); ?></label></p>
			<p><label><input type="checkbox" name="exclude_backups" checked> <?php esc_html_e( '排除已识别的备份目录', 'wp-panel-backup' ); ?></label></p>
		</details>
		<div class="wppb-actions"><?php submit_button( __( '开始备份', 'wp-panel-backup' ), 'primary', 'submit', false ); ?></div>
	</form>
	<?php
}

function render_backup_maintenance( $mode, $enabled ) {
	?>
	<section id="wp-panel-backup-maintenance" class="wppb-notice wppb-warning" aria-label="<?php esc_attr_e( '维护状态', 'wp-panel-backup' ); ?>" <?php if ( ! $enabled ) : ?>hidden<?php endif; ?>>
		<?php if ( 'move' === $mode ) : ?>
			<p><strong><?php esc_html_e( '搬家维护中', 'wp-panel-backup' ); ?></strong><?php esc_html_e( '　取消备份后，原站仍会保持维护。', 'wp-panel-backup' ); ?></p>
			<?php render_control_button( 'cancel_move', __( '取消搬家并恢复原站', 'wp-panel-backup' ) ); ?>
		<?php else : ?>
			<p><strong><?php esc_html_e( '临时维护中', 'wp-panel-backup' ); ?></strong><?php esc_html_e( '　备份成功后将自动恢复原站。', 'wp-panel-backup' ); ?></p>
			<?php render_control_button( 'release_temporary', __( '结束临时维护并恢复原站', 'wp-panel-backup' ) ); ?>
		<?php endif; ?>
	</section>
	<?php
}

function render_active_export( $job, $modes ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- These parameters select the rendered state. Each AJAX batch has its own nonce check.
	$run = $job['id'] === sanitize_key( wp_unslash( $_GET['job'] ?? '' ) ) && '1' === sanitize_key( wp_unslash( $_GET['run'] ?? '' ) );
	$progress = export_progress( $job );
	$running_hint = __( '备份正在进行。大型站点可能需要较长时间，请保持此页打开。关闭后可返回继续。', 'wp-panel-backup' );
	?>
	<section class="wppb-card wppb-progress" id="wp-panel-backup-progress" data-phase="<?php echo esc_attr( $progress['phase'] ); ?>">
		<div class="wppb-progress-heading">
			<h2><?php esc_html_e( '备份进度', 'wp-panel-backup' ); ?></h2>
			<span class="wppb-meta"><?php echo esc_html( $modes[ $job['mode'] ?? 'normal' ] ); ?></span>
		</div>
		<div class="wppb-progress-status" role="status" aria-atomic="true">
			<p class="wppb-phase">
				<span class="wppb-progress-spinner" data-progress="spinner" aria-hidden="true" hidden></span>
				<span data-progress="label"><?php echo esc_html( $progress['label'] ); ?></span>
			</p>
			<p class="wppb-meta" data-progress="processed"><?php echo esc_html( $progress['processed'] ); ?></p>
		</div>
		<p data-progress="hint" data-running-text="<?php echo esc_attr( $running_hint ); ?>" class="wppb-hint"><?php echo $run ? esc_html( $running_hint ) : esc_html__( '备份已暂停，可以从当前进度继续。', 'wp-panel-backup' ); ?></p>
		<p data-progress="error" class="wppb-notice wppb-error" role="alert" hidden></p>
		<div class="wppb-actions">
			<form id="wp-panel-backup-advance" data-running="<?php echo $run ? '1' : '0'; ?>" data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce' ); ?>
				<input type="hidden" name="action" value="wp_panel_backup_control">
				<input type="hidden" name="operation" value="<?php echo $run ? 'advance' : 'resume'; ?>">
				<input type="hidden" name="job" value="<?php echo esc_attr( $job['id'] ); ?>">
				<button class="button button-primary" type="submit"><?php echo $run ? esc_html__( '推进一批', 'wp-panel-backup' ) : esc_html__( '继续备份', 'wp-panel-backup' ); ?></button>
				<?php if ( $run ) : ?>
					<noscript><p><?php esc_html_e( '可手动推进一批，或开启 JavaScript 后自动推进。', 'wp-panel-backup' ); ?></p></noscript>
				<?php endif; ?>
			</form>
			<?php render_control_button( 'cancel', __( '取消备份', 'wp-panel-backup' ), $job['id'] ); ?>
			<a data-progress="return" href="<?php echo esc_url( backup_page_url() ); ?>" hidden><?php esc_html_e( '返回创建备份', 'wp-panel-backup' ); ?></a>
		</div>
	</section>
	<?php
}

function render_control_button( $operation, $label, $id = '', $list = false ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wp_panel_backup_control">
		<input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>">
		<input type="hidden" name="job" value="<?php echo esc_attr( $id ); ?>">
		<?php if ( $list ) : ?><input type="hidden" name="return_view" value="backups"><?php endif; ?>
		<?php wp_nonce_field( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce' ); ?>
		<?php submit_button( $label, 'secondary', 'submit', false ); ?>
	</form>
	<?php
}

function render_completed_exports( $jobs, $modes ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This parameter selects which completed backup to expand.
	$selected_job = sanitize_key( wp_unslash( $_GET['job'] ?? '' ) );
	$available = array();
	$expired = false;
	foreach ( $jobs as $job ) {
		if ( 'complete' !== $job['phase'] ) {
			continue;
		}
		if ( export_expired( $job ) ) {
			$expired = true;
		} else {
			$available[] = $job;
		}
	}
	if ( ! $available ) {
		?>
		<section class="wppb-card wppb-empty">
			<h2><?php esc_html_e( '暂无可下载的备份', 'wp-panel-backup' ); ?></h2>
			<a class="button button-primary" href="<?php echo esc_url( backup_page_url() ); ?>"><?php esc_html_e( '创建备份', 'wp-panel-backup' ); ?></a>
		</section>
		<?php
	} else {
		?>
		<div class="wppb-list">
			<div class="wppb-list-heading" aria-hidden="true">
				<span><?php esc_html_e( '完成时间', 'wp-panel-backup' ); ?></span><span><?php esc_html_e( '备份方式', 'wp-panel-backup' ); ?></span><span><?php esc_html_e( '分包 / 大小', 'wp-panel-backup' ); ?></span>
			</div>
			<?php foreach ( $available as $job ) :
			$date = wp_date( 'Y-m-d H:i', $job['completed_at'] );
			$bytes = array_sum( array_column( $job['packages'], 'size' ) );
			$open = count( $available ) === 1 || $selected_job === $job['id'];
			?>
			<details class="wppb-backup" <?php if ( $open ) : ?>open<?php endif; ?>>
				<summary>
					<time datetime="<?php echo esc_attr( wp_date( DATE_ATOM, $job['completed_at'] ) ); ?>"><?php echo esc_html( $date ); ?></time>
					<span><?php echo esc_html( $modes[ $job['mode'] ?? 'normal' ] ); ?></span>
					<span><?php /* translators: 1: Number of ZIP packages, 2: Combined size of the ZIP packages. */ echo esc_html( sprintf( __( '%1$d 个 ZIP · %2$s', 'wp-panel-backup' ), count( $job['packages'] ), size_format( $bytes, 1 ) ) ); ?></span>
				</summary>
				<div class="wppb-backup-content">
					<?php render_export_downloads( $job ); ?>
					<div class="wppb-section"><?php render_control_button( 'delete', __( '删除这套备份材料', 'wp-panel-backup' ), $job['id'], true ); ?></div>
				</div>
			</details>
			<?php endforeach; ?>
		</div>
		<?php
		render_download_notice();
	}
	if ( $expired ) {
		render_control_button( 'cleanup', __( '清理已过期材料', 'wp-panel-backup' ), '', true );
	}
}

function render_download_notice() {
	?>
	<div class="wppb-notice wppb-info">
		<p><strong><?php esc_html_e( '下载安全', 'wp-panel-backup' ); ?></strong><?php esc_html_e( '　请勿分享备份文件的 URL。如果服务器允许直接访问备份目录，知道完整 URL 的访客无需登录即可下载。', 'wp-panel-backup' ); ?></p>
		<p><?php esc_html_e( 'Apache：插件已在备份目录生成 .htaccess 禁访规则。主机须允许该目录的 .htaccess 生效，否则请在 Apache 站点配置中禁止直接访问备份目录。', 'wp-panel-backup' ); ?></p>
		<details>
			<summary><?php esc_html_e( '在 Nginx 禁止直接下载', 'wp-panel-backup' ); ?></summary>
			<p><?php esc_html_e( '将下面的规则添加到此站点的 Nginx server 配置，检测配置后重载 Nginx。管理员仍可从备份列表下载。', 'wp-panel-backup' ); ?></p>
			<pre><code><?php echo esc_html( 'location ^~ /wp-content/' . WORKSPACE_PREFIX . " {\n    return 403;\n}" ); ?></code></pre>
		</details>
	</div>
	<?php
}

function render_export_downloads( $job ) {
	$names = array_merge( array( 'manifest.json', 'files.ndjson' ), array_column( $job['packages'], 'name' ) );
	$expiry = wp_date( 'Y-m-d H:i T', $job['completed_at'] + 7 * DAY_IN_SECONDS );
	$link_remaining = max( 0, active_remote_link_expiry( $job ) - time() );
	$generate = __( '生成恢复链接', 'wp-panel-backup' );
	$regenerate = __( '重新生成链接', 'wp-panel-backup' );
	?>
	<p class="wppb-meta"><?php /* translators: Backup expiration date. */ echo esc_html( sprintf( __( '到期时间 %s。刷新此页可查看已发起的下载。', 'wp-panel-backup' ), $expiry ) ); ?></p>
	<div data-wppb-remote-link data-wppb-remaining="<?php echo esc_attr( $link_remaining ); ?>">
		<div class="wppb-backup-actions">
			<?php render_artifact_download( $job, BUNDLE_ARTIFACT, __( '下载整套（TAR）', 'wp-panel-backup' ), 'primary' ); ?>
			<form class="wppb-link-actions" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="wp_panel_backup_remote_link">
				<input type="hidden" name="job" value="<?php echo esc_attr( $job['id'] ); ?>">
				<?php wp_nonce_field( 'wp-panel-backup-remote-link-' . $job['id'], '_wp_panel_backup_remote_nonce' ); ?>
				<button class="button" type="submit" name="operation" value="generate" data-generate="<?php echo esc_attr( $generate ); ?>" data-regenerate="<?php echo esc_attr( $regenerate ); ?>"><?php echo esc_html( $link_remaining ? $regenerate : $generate ); ?></button>
				<button class="button-link button-link-delete" type="submit" name="operation" value="revoke" <?php if ( ! $link_remaining ) : ?>hidden<?php endif; ?>><?php esc_html_e( '撤销链接', 'wp-panel-backup' ); ?></button>
			</form>
		</div>
		<input type="text" class="large-text" data-wppb-link readonly hidden aria-label="<?php esc_attr_e( '恢复链接', 'wp-panel-backup' ); ?>">
		<p class="wppb-meta wppb-link-status" data-wppb-message role="status"><?php
		if ( $link_remaining ) {
			/* translators: Restore link expiration date. */
			echo esc_html( sprintf( __( '链接有效至 %s。重新生成会使旧链接失效。', 'wp-panel-backup' ), wp_date( 'Y-m-d H:i T', $job['remote_link']['expires'] ) ) );
		}
		?></p>
	</div>
	<details class="wppb-fold wppb-downloads">
		<summary><?php /* translators: Number of separately downloadable backup files. */ echo esc_html( sprintf( __( '逐份下载（%d 份）', 'wp-panel-backup' ), count( $names ) ) ); ?></summary>
		<ul>
			<?php foreach ( $names as $name ) : ?>
				<li><?php /* translators: Relative file or directory path. */ render_artifact_download( $job, $name, sprintf( __( '下载 %s', 'wp-panel-backup' ), $name ), 'secondary small' ); ?></li>
			<?php endforeach; ?>
		</ul>
	</details>
	<?php
}

function render_artifact_download( $job, $name, $label, $button_type ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wp_panel_backup_download">
		<input type="hidden" name="job" value="<?php echo esc_attr( $job['id'] ); ?>">
		<input type="hidden" name="artifact" value="<?php echo esc_attr( $name ); ?>">
		<?php wp_nonce_field( 'wp-panel-backup-download-' . $job['id'], '_wp_panel_backup_download_nonce' ); ?>
		<?php submit_button( $label, $button_type, 'submit', false ); ?>
		<?php if ( isset( $job['downloads'][ $name ] ) ) : ?><span class="wppb-meta wppb-download-state"><?php esc_html_e( '已发起下载', 'wp-panel-backup' ); ?></span><?php endif; ?>
	</form>
	<?php
}
