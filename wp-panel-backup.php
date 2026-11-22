<?php
/**
 * Plugin Name: WP PANEL Backup
 * Description: 从 WordPress 管理后台检查站点并创建迁移备份。
 * Version: 0.0.0-dev
 * Text Domain: wp-panel-backup
 */

namespace WPPanelBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/workspace.php';
require_once __DIR__ . '/includes/maintenance.php';

add_action( 'template_redirect', __NAMESPACE__ . '\\show_maintenance_notice', -100 );
add_filter( 'rest_authentication_errors', __NAMESPACE__ . '\\maintenance_rest_access', 100 );
if ( is_admin() ) {
	require_once __DIR__ . '/includes/preflight.php';
	require_once __DIR__ . '/includes/database.php';
	require_once __DIR__ . '/includes/export.php';
	require_once __DIR__ . '/includes/admin.php';

	add_action( 'admin_post_wp_panel_backup_control', __NAMESPACE__ . '\\control_export' );
	add_action( 'admin_init', __NAMESPACE__ . '\\maintenance_admin_access', 1 );
	add_action( 'admin_post_wp_panel_backup_download', __NAMESPACE__ . '\\download_export' );
	add_action( 'admin_menu', __NAMESPACE__ . '\\register_backup_page' );
}
