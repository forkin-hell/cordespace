<?php
/**
 * Lit le registry et charge les modules actifs.
 *
 * - PHP  : require_once au hook plugins_loaded priority 5
 * - CSS  : wp_enqueue_style au hook wp_enqueue_scripts (front uniquement)
 *
 * Voir docs/superpowers/specs/2026-05-18-cordespace-admin-toggles-design.md §4.5
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renvoie le registry (cache statique pour ne pas lire le fichier 2 fois).
 */
function cordespace_modules_registry(): array {
	static $registry = null;
	if ( $registry === null ) {
		$registry = require CORDESPACE_SNIPPETS_DIR . '/includes/registry.php';
	}
	return $registry;
}

/**
 * Liste des IDs de modules actifs (lecture de l'option, cache statique).
 */
function cordespace_modules_active_ids(): array {
	static $ids = null;
	if ( $ids === null ) {
		$ids = (array) get_option( 'cordespace_modules_active', [] );
	}
	return $ids;
}

/**
 * Charge les modules PHP actifs.
 */
function cordespace_modules_boot_php(): void {
	$registry = cordespace_modules_registry();
	$active   = cordespace_modules_active_ids();

	foreach ( $registry['modules'] as $id => $module ) {
		if ( $module['type'] !== 'php' ) continue;
		if ( ! in_array( $id, $active, true ) ) continue;
		$path = CORDESPACE_SNIPPETS_DIR . '/' . $module['file'];
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
add_action( 'plugins_loaded', 'cordespace_modules_boot_php', 5 );

/**
 * Enqueue les modules CSS actifs sur le front.
 */
function cordespace_modules_enqueue_css(): void {
	$registry = cordespace_modules_registry();
	$active   = cordespace_modules_active_ids();

	foreach ( $registry['modules'] as $id => $module ) {
		if ( $module['type'] !== 'css' ) continue;
		if ( ! in_array( $id, $active, true ) ) continue;
		$path = CORDESPACE_SNIPPETS_DIR . '/' . $module['file'];
		if ( ! file_exists( $path ) ) continue;
		wp_enqueue_style(
			'cordespace-' . sanitize_key( $id ),
			CORDESPACE_SNIPPETS_URL . $module['file'],
			[],
			(string) filemtime( $path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'cordespace_modules_enqueue_css' );
