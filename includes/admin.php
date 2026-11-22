<?php

namespace WPPanelBackup;

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
	if ( 'backups' !== ( $_GET['view'] ?? '' ) ) {
		wp_enqueue_script( 'wp-panel-backup-advance', plugins_url( 'assets/advance.js', $plugin ), array(), (string) filemtime( dirname( $plugin ) . '/assets/advance.js' ), true );
	}
}

function backup_plugin_action_links( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		$links = array( 'settings' => '<a href="' . esc_url( backup_page_url() ) . '">备份</a>' ) + $links;
	}
	return $links;
}

function render_backup_page() {
	$list = 'backups' === ( $_GET['view'] ?? '' );
	?>
	<div class="wrap wp-panel-backup">
		<header class="wppb-header">
			<span class="dashicons dashicons-database-export" aria-hidden="true"></span>
			<h1>WP PANEL Backup</h1>
		</header>
		<nav class="wppb-tabs" aria-label="备份管理">
			<a href="<?php echo esc_url( backup_page_url() ); ?>" class="nav-tab<?php if ( ! $list ) : ?> nav-tab-active<?php endif; ?>" <?php if ( ! $list ) : ?>aria-current="page"<?php endif; ?>>创建备份</a>
			<a href="<?php echo esc_url( backup_page_url( true ) ); ?>" class="nav-tab<?php if ( $list ) : ?> nav-tab-active<?php endif; ?>" <?php if ( $list ) : ?>aria-current="page"<?php endif; ?>>备份列表</a>
		</nav>
		<?php render_export_controls( $list ); ?>
	</div>
	<?php
}

function backup_page_url( $list = false ) {
	$url = admin_url( 'tools.php?page=wp-panel-backup' );
	return $list ? add_query_arg( 'view', 'backups', $url ) : $url;
}

function advance_export_ajax() {
	if ( ! current_user_can( 'manage_options' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
		|| ! check_ajax_referer( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce', false ) ) {
		wp_send_json_error( array( 'message' => '无法验证备份请求。请重新打开备份页面后继续。' ), 403 );
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
		'scan' => '检查备份文件', 'database' => '导出数据库', 'package' => '生成 ZIP 分包',
		'complete' => '备份完成', 'failed' => '备份失败', 'cancelled' => '备份已取消',
	);
	$detail = '';
	if ( 'database' === $job['phase'] ) {
		$detail = sprintf( '数据库已导出 %d / %d 张表。', $job['table_index'], count( $job['tables'] ) );
	} elseif ( 'package' === $job['phase'] && ! empty( $job['pending_zip_bytes'] ) ) {
		$detail = sprintf( '当前分包已收集 %s 数据。', size_format( $job['pending_zip_bytes'], 1 ) );
	}
	return array(
		'phase' => $job['phase'],
		'label' => $labels[ $job['phase'] ],
		'detail' => $detail,
		'packages' => sprintf( '%d 个 ZIP 包已生成', count( $job['packages'] ) ),
		'running' => export_is_running( $job ),
		'error' => 'failed' === $job['phase'] ? $job['error'] : '',
	);
}

function control_export() {
	if ( ! current_user_can( 'manage_options' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_die( '你没有操作备份任务的权限。', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce' );
	$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
	$id = sanitize_key( wp_unslash( $_POST['job'] ?? '' ) );
	$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'normal' ) );
	$submitted_payload = wp_unslash( $_POST['zip_payload_mib'] ?? (string) DEFAULT_ZIP_PAYLOAD_MIB );
	$zip_payload_mib = is_string( $submitted_payload ) && preg_match( '/^[0-9]+$/D', $submitted_payload ) ? (int) $submitted_payload : 0;
	$has_settings = isset( $_POST['directory_exclusion_options'] );
	$options = array( 'cache' => ! $has_settings || isset( $_POST['exclude_cache'] ), 'backups' => ! $has_settings || isset( $_POST['exclude_backups'] ) );
	try {
		$job = with_workspace_lock( static function ( $root ) use ( $operation, $id, $mode, $options, $zip_payload_mib ) {
			return apply_export_operation( $root, $operation, $id, $mode, $options, $zip_payload_mib );
		}, 'start' === $operation );
	} catch ( \Throwable $error ) {
		wp_die( esc_html( $error->getMessage() ), '备份任务未能运行', array( 'response' => 400, 'back_link' => true ) );
	}
	$args = $job ? array( 'job' => $job['id'] ) : array();
	if ( $job && export_is_running( $job ) ) {
		$args['run'] = '1';
	}
	$list = ( $job && 'complete' === $job['phase'] ) || 'backups' === ( $_POST['return_view'] ?? '' );
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
		throw new \RuntimeException( '无效的备份操作。' );
	}
	$was_running = export_is_running( $job );
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
	$modes = array( 'normal' => '正常备份', 'temporary' => '临时维护备份', 'move' => '搬家备份' );
	if ( $list ) {
		render_completed_exports( $jobs, $modes );
		return;
	}
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
			<p class="wppb-meta"><?php echo esc_html( $modes[ $job['mode'] ?? 'normal' ] . ' · ' . $progress['packages'] ); ?></p>
			<?php if ( $progress['error'] ) : ?>
				<p class="wppb-notice wppb-error"><?php echo esc_html( $progress['error'] ); ?></p>
			<?php endif; ?>
			<?php render_control_button( 'delete', '删除未完成的材料', $job['id'] ); ?>
		</section>
		<?php
	}
	if ( $expired ) {
		render_control_button( 'cleanup', '清理已过期材料' );
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
		<h2>创建备份</h2>
		<p class="wppb-meta">开始后会自动检查站点、文件、数据库和工作目录。扫描时跳过隐藏文件和目录。</p>
		<?php wp_nonce_field( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce' ); ?>
		<input type="hidden" name="action" value="wp_panel_backup_control">
		<input type="hidden" name="operation" value="start">
		<fieldset class="wppb-section">
			<legend>备份方式</legend>
			<div class="wppb-modes">
				<?php if ( ! $maintenance ) : ?>
					<label class="wppb-mode">
						<input type="radio" name="mode" value="normal" checked>
						<span><strong>正常备份</strong><small>网站继续运行，文件和数据库可能来自不同时间点。</small></span>
					</label>
					<label class="wppb-mode">
						<input type="radio" name="mode" value="temporary">
						<span><strong>临时维护备份</strong><small>备份成功后自动开放原站。</small></span>
					</label>
				<?php endif; ?>
				<label class="wppb-mode">
					<input type="radio" name="mode" value="move" <?php checked( (bool) $maintenance ); ?>>
					<span><strong>搬家备份</strong><small>原站持续维护，直到管理员明确取消搬家。</small></span>
				</label>
			</div>
		</fieldset>
		<div class="wppb-section">
			<label for="wp-panel-backup-zip-size"><strong>ZIP 分包大小</strong></label>
			<select id="wp-panel-backup-zip-size" name="zip_payload_mib" aria-describedby="wp-panel-backup-zip-help">
				<?php foreach ( ZIP_PAYLOAD_OPTIONS as $size ) : ?>
					<option value="<?php echo esc_attr( (string) $size ); ?>" <?php selected( $size, DEFAULT_ZIP_PAYLOAD_MIB ); ?>><?php echo esc_html( size_format( $size * 1024 * 1024 ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="wppb-meta" id="wp-panel-backup-zip-help">按压缩前的数据量计算。主机容易超时时可选择较小的分包。完成后可整套下载或逐份下载。</p>
		</div>
		<details class="wppb-section wppb-fold" <?php if ( $matches || $cache_dropins ) : ?>open<?php endif; ?>>
			<summary>目录排除选项</summary>
			<?php if ( $cache_dropins ) : ?>
				<p>以下缓存加载文件将自动跳过。源站文件保留，恢复后请在目标站重新配置缓存：</p>
				<ul class="ul-disc">
					<?php foreach ( $cache_dropins as $path ) : ?>
						<li><code><?php echo esc_html( $path ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $matches ) : ?>
				<p>默认跳过以下已识别的缓存和备份目录，源站文件保留：</p>
				<ul class="ul-disc">
					<?php foreach ( $matches as $path => $match ) : ?>
						<li><code><?php echo esc_html( $path ); ?></code>（<?php echo esc_html( $match['plugin'] ); ?>）</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p>未发现可自动排除的第三方缓存或备份目录。</p>
			<?php endif; ?>
			<input type="hidden" name="directory_exclusion_options" value="1">
			<p><label><input type="checkbox" name="exclude_cache" checked> 排除已识别的缓存目录</label></p>
			<p><label><input type="checkbox" name="exclude_backups" checked> 排除已识别的备份目录</label></p>
		</details>
		<div class="wppb-actions"><?php submit_button( '开始备份', 'primary', 'submit', false ); ?></div>
	</form>
	<?php
}

function render_backup_maintenance( $mode, $enabled ) {
	?>
	<section id="wp-panel-backup-maintenance" class="wppb-notice wppb-warning" aria-label="维护状态" <?php if ( ! $enabled ) : ?>hidden<?php endif; ?>>
		<?php if ( 'move' === $mode ) : ?>
			<p><strong>搬家维护中</strong>　取消备份后，原站仍会保持维护。</p>
			<?php render_control_button( 'cancel_move', '取消搬家并恢复原站' ); ?>
		<?php else : ?>
			<p><strong>临时维护中</strong>　备份成功后将自动恢复原站。</p>
			<?php render_control_button( 'release_temporary', '结束临时维护并恢复原站' ); ?>
		<?php endif; ?>
	</section>
	<?php
}

function render_active_export( $job, $modes ) {
	$run = ( $_GET['job'] ?? '' ) === $job['id'] && '1' === ( $_GET['run'] ?? '' );
	$progress = export_progress( $job );
	?>
	<section class="wppb-card wppb-progress" id="wp-panel-backup-progress" data-phase="<?php echo esc_attr( $progress['phase'] ); ?>">
		<div class="wppb-progress-heading">
			<h2>备份进度</h2>
			<span class="wppb-pill"><?php echo esc_html( $modes[ $job['mode'] ?? 'normal' ] ); ?></span>
		</div>
		<div class="wppb-progress-status" role="status" aria-atomic="true">
			<p class="wppb-phase"><span data-progress="label"><?php echo esc_html( $progress['label'] ); ?></span></p>
			<p class="wppb-meta" data-progress="detail" <?php if ( '' === $progress['detail'] ) : ?>hidden<?php endif; ?>><?php echo esc_html( $progress['detail'] ); ?></p>
			<p class="wppb-meta" data-progress="packages"><?php echo esc_html( $progress['packages'] ); ?></p>
		</div>
		<p data-progress="hint" class="wppb-hint"><?php echo $run ? '备份正在进行。请保持此页打开，关闭后可返回继续。' : '备份已暂停，可以从当前进度继续。'; ?></p>
		<p data-progress="error" class="wppb-notice wppb-error" role="alert" hidden></p>
		<div class="wppb-actions">
			<form id="wp-panel-backup-advance" data-running="<?php echo $run ? '1' : '0'; ?>" data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wp-panel-backup-control', '_wp_panel_backup_control_nonce' ); ?>
				<input type="hidden" name="action" value="wp_panel_backup_control">
				<input type="hidden" name="operation" value="<?php echo $run ? 'advance' : 'resume'; ?>">
				<input type="hidden" name="job" value="<?php echo esc_attr( $job['id'] ); ?>">
				<button class="button button-primary" type="submit"><?php echo $run ? '推进一批' : '继续备份'; ?></button>
				<?php if ( $run ) : ?>
					<noscript><p>可手动推进一批，或开启 JavaScript 后自动推进。</p></noscript>
				<?php endif; ?>
			</form>
			<?php render_control_button( 'cancel', '取消备份', $job['id'] ); ?>
			<a data-progress="return" href="<?php echo esc_url( backup_page_url() ); ?>" hidden>返回创建备份</a>
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
			<span class="dashicons dashicons-archive" aria-hidden="true"></span>
			<h2>暂无可下载的备份</h2>
			<a class="button button-primary" href="<?php echo esc_url( backup_page_url() ); ?>">创建备份</a>
		</section>
		<?php
	} else {
		render_download_notice();
		foreach ( $available as $job ) {
			$date = wp_date( 'Y-m-d H:i', $job['completed_at'] );
			$bytes = array_sum( array_column( $job['packages'], 'size' ) );
			$open = count( $available ) === 1 || ( $_GET['job'] ?? '' ) === $job['id'];
			?>
			<details class="wppb-card wppb-backup" <?php if ( $open ) : ?>open<?php endif; ?>>
				<summary>
					<span><strong><?php echo esc_html( $date ); ?></strong><small><?php echo esc_html( sprintf( '%s · %d 个 ZIP 包 · %s', $modes[ $job['mode'] ?? 'normal' ], count( $job['packages'] ), size_format( $bytes, 1 ) ) ); ?></small></span>
					<span class="wppb-pill wppb-ready">已完成</span>
				</summary>
				<div class="wppb-backup-content">
					<?php render_export_downloads( $job ); ?>
					<div class="wppb-section"><?php render_control_button( 'delete', '删除这套备份材料', $job['id'], true ); ?></div>
				</div>
			</details>
			<?php
		}
	}
	if ( $expired ) {
		render_control_button( 'cleanup', '清理已过期材料', '', true );
	}
}

function render_download_notice() {
	?>
	<div class="wppb-notice wppb-info">
		<p><strong>下载安全</strong>　请勿分享备份文件的 URL。如果服务器允许直接访问备份目录，知道完整 URL 的访客无需登录即可下载。</p>
		<details>
			<summary>在 Nginx 禁止直接下载</summary>
			<p>在此站点的 Nginx <code>server</code> 配置中添加下面的规则，检测配置后重载 Nginx。管理员仍可从以下备份列表下载。</p>
			<pre><code><?php echo esc_html( 'location ^~ /wp-content/' . WORKSPACE_PREFIX . " {\n    return 403;\n}" ); ?></code></pre>
		</details>
	</div>
	<?php
}

function render_export_downloads( $job ) {
	$names = array_merge( array( 'manifest.json', 'files.ndjson' ), array_column( $job['packages'], 'name' ) );
	$expiry = wp_date( 'Y-m-d H:i T', $job['completed_at'] + 7 * DAY_IN_SECONDS );
	?>
	<p class="wppb-meta"><?php echo esc_html( sprintf( '到期时间 %s。刷新此页可查看已发起的下载。', $expiry ) ); ?></p>
	<?php render_artifact_download( $job, BUNDLE_ARTIFACT, '下载整套（TAR）', 'primary' ); ?>
	<details class="wppb-fold wppb-downloads">
		<summary><?php echo esc_html( sprintf( '逐份下载（%d 份）', count( $names ) ) ); ?></summary>
		<ul>
			<?php foreach ( $names as $name ) : ?>
				<li><?php render_artifact_download( $job, $name, '下载 ' . $name, 'secondary small' ); ?></li>
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
		<?php if ( isset( $job['downloads'][ $name ] ) ) : ?><span class="wppb-meta wppb-download-state">已发起下载</span><?php endif; ?>
	</form>
	<?php
}
