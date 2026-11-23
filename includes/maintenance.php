<?php

namespace WPPanelBackup;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are plain text. Escape at HTML output so JSON responses and saved job status retain the original text.

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
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the verified local maintenance marker while holding the workspace lock.
	if ( ! unlink( $workspace . '/maintenance.json' ) ) {
		throw new RuntimeException( __( '无法解除本插件的维护状态。', 'wp-panel-backup' ) );
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
		esc_html__( '本站正在维护，请稍后再访问。', 'wp-panel-backup' ) . ' <a href="' . esc_url( wp_login_url() ) . '">' . esc_html__( '管理员登录', 'wp-panel-backup' ) . '</a>',
		esc_html__( '网站维护中', 'wp-panel-backup' ),
		array( 'response' => 503 )
	);
}

function maintenance_admin_access() {
	global $pagenow;
	if ( ! active_maintenance() ) {
		return;
	}
	// This route serves one backup artifact only after validating a scoped token.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This branch only routes the request.
	if ( 'admin-post.php' === $pagenow && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' )
		&& 'wp_panel_backup_remote' === ( $_GET['action'] ?? '' ) ) {
		return;
	}
	if ( current_user_can( 'manage_options' ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page selection during maintenance.
		if ( 'tools.php' === $pagenow && 'wp-panel-backup' === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) {
			return;
		}
		$method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) );
		if ( 'POST' === $method ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This only routes the request. The handlers verify the nonce before doing any work.
			$action = sanitize_key( wp_unslash( $_POST['action'] ?? '' ) );
			if ( ( 'admin-post.php' === $pagenow && in_array( $action, array( 'wp_panel_backup_control', 'wp_panel_backup_download' ), true ) )
				|| ( wp_doing_ajax() && in_array( $action, array( 'wp_panel_backup_advance', 'wp_panel_backup_remote_link' ), true ) ) ) {
				return;
			}
		}
		if ( 'GET' === $method && ! wp_doing_ajax() ) {
			wp_safe_redirect( backup_page_url() );
			exit;
		}
	}
	show_maintenance_notice();
}

function maintenance_rest_access( $error ) {
	if ( active_maintenance() ) {
		return new \WP_Error( 'wp_panel_backup_maintenance', __( '本站正在维护，请稍后再访问。', 'wp-panel-backup' ), array( 'status' => 503 ) );
	}
	return $error;
}
