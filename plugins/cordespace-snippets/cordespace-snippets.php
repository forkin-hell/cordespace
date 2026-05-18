<?php
/**
 * Plugin Name:       Cordespace Snippets
 * Plugin URI:        https://github.com/Tess-cdp/cordespace
 * Description:       Glue entre Amelia, MyCred, User Switching et WooCommerce pour Cordespace. Remplace nos snippets WPCode par du code versionné en fichiers. Contient le shortcode [cordespace_mon_espace], le switch entre comptes liés, le toggle de présence prof, et les styles éditoriaux.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Cordespace
 * License:           GPL-2.0-or-later
 * Text Domain:       cordespace-snippets
 *
 * Ce plugin remplace les snippets WPCode suivants :
 *   - 1224 → includes/switch-accounts.php
 *   - 1228 → assets/css/menu-icon.css
 *   - 1232 → includes/mon-espace-shortcode.php
 *   - 1237 → assets/css/amelia-sidebar-cache.css
 *   - 1241 → assets/css/callouts.css
 *   - 1249 → includes/toggle-presence-prof.php
 *
 * Quand ce plugin est actif en prod, DÉSACTIVER les snippets WPCode
 * correspondants (sinon double exécution = fatal "Cannot redeclare function").
 */

defined( 'ABSPATH' ) || exit;

define( 'CORDESPACE_SNIPPETS_VERSION', '1.0.0' );
define( 'CORDESPACE_SNIPPETS_FILE', __FILE__ );
define( 'CORDESPACE_SNIPPETS_DIR', __DIR__ );
define( 'CORDESPACE_SNIPPETS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Charge tous les fichiers PHP de includes/ par ordre alphabétique.
 *
 * L'ordre alphabétique nous donne un loader déterministe sans avoir à
 * maintenir une liste explicite. Si un fichier doit charger avant un autre,
 * préfixer son nom (ex: 00-helpers.php).
 */
add_action( 'plugins_loaded', static function (): void {
	$includes_dir = CORDESPACE_SNIPPETS_DIR . '/includes';
	if ( ! is_dir( $includes_dir ) ) {
		return;
	}
	$files = glob( $includes_dir . '/*.php' );
	if ( ! is_array( $files ) ) {
		return;
	}
	sort( $files );
	foreach ( $files as $file ) {
		require_once $file;
	}
}, 5 );

/**
 * Enqueue les CSS éditoriaux du plugin sur le front et dans wp-admin pour
 * la barre/menu.
 */
add_action( 'wp_enqueue_scripts', static function (): void {
	$assets = [
		'cordespace-menu-icon'       => 'menu-icon.css',
		'cordespace-amelia-sidebar'  => 'amelia-sidebar-cache.css',
		'cordespace-callouts'        => 'callouts.css',
	];
	foreach ( $assets as $handle => $filename ) {
		$path = CORDESPACE_SNIPPETS_DIR . '/assets/css/' . $filename;
		if ( ! file_exists( $path ) ) {
			continue;
		}
		wp_enqueue_style(
			$handle,
			CORDESPACE_SNIPPETS_URL . 'assets/css/' . $filename,
			[],
			(string) filemtime( $path )
		);
	}
} );
