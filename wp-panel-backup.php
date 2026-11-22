<?php
/**
 * Plugin Name: WP PANEL Backup
 * Description: Export site files and the database as a downloadable migration backup.
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Author: WP PANEL
 * Author URI: https://wp-panel.org/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
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
	require_once __DIR__ . '/includes/exclusions.php';
	require_once __DIR__ . '/includes/database.php';
	require_once __DIR__ . '/includes/export.php';
	require_once __DIR__ . '/includes/download.php';
	require_once __DIR__ . '/includes/admin.php';

	add_action( 'admin_post_wp_panel_backup_control', __NAMESPACE__ . '\\control_export' );
	add_action( 'wp_ajax_wp_panel_backup_advance', __NAMESPACE__ . '\\advance_export_ajax' );
	add_action( 'admin_init', __NAMESPACE__ . '\\maintenance_admin_access', 1 );
	add_action( 'admin_post_wp_panel_backup_download', __NAMESPACE__ . '\\download_export' );
	add_action( 'admin_menu', __NAMESPACE__ . '\\register_backup_page' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_backup_assets' );
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\\backup_plugin_action_links' );
}
