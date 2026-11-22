<?php

namespace WPPanelBackup;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function maintenance_record( $workspace ) {
	$path = $workspace . '/maintenance.json';
	return is_file( $path ) ? read_record( $path ) : null;
}

function open_maintenance( $workspace, $job ) {
	if ( 'normal' !== $job['mode'] ) {
		write_record( $workspace . '/maintenance.json', array( 'mode' => $job['mode'], 'job' => $job['id'] ) );
	}
}

function release_maintenance( $workspace, $mode, $job_id = '' ) {
	$state = maintenance_record( $workspace );
	if ( ! $state || $state['mode'] !== $mode || ( '' !== $job_id && $job_id !== $state['job'] ) ) {
		return;
	}
	if ( ! unlink( $workspace . '/maintenance.json' ) ) {
		throw new RuntimeException( '无法解除本插件的维护状态。' );
	}
}

function active_maintenance() {
	try {
		$workspace = workspace();
		return null !== $workspace && is_file( $workspace . '/site.json' ) && null !== maintenance_record( $workspace );
	} catch ( RuntimeException $error ) {
		return false;
	}
}

function show_maintenance_notice() {
	if ( ! active_maintenance() ) {
		return;
	}
	nocache_headers();
	if ( ! headers_sent() ) {
		header( 'Retry-After: 300' );
	}
	wp_die(
		'本站正在维护，请稍后再访问。 <a href="' . esc_url( wp_login_url() ) . '">管理员登录</a>',
		'网站维护中',
		array( 'response' => 503 )
	);
}

function maintenance_admin_access() {
	global $pagenow;
	if ( ! active_maintenance() ) {
		return;
	}
	if ( current_user_can( 'manage_options' ) ) {
		if ( 'tools.php' === $pagenow && 'wp-panel-backup' === ( $_GET['page'] ?? '' ) ) {
			return;
		}
		if ( 'admin-post.php' === $pagenow && in_array( $_POST['action'] ?? '', array( 'wp_panel_backup_control', 'wp_panel_backup_download' ), true ) ) {
			return;
		}
		if ( wp_doing_ajax() && 'wp_panel_backup_advance' === ( $_POST['action'] ?? '' ) ) {
			return;
		}
		if ( 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! wp_doing_ajax() ) {
			wp_safe_redirect( backup_page_url() );
			exit;
		}
	}
	show_maintenance_notice();
}

function maintenance_rest_access( $error ) {
	if ( active_maintenance() ) {
		return new \WP_Error( 'wp_panel_backup_maintenance', '本站正在维护，请稍后再访问。', array( 'status' => 503 ) );
	}
	return $error;
}
