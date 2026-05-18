<?php
/**
 * Plugin Name:       Cordespace Snippets
 * Plugin URI:        https://github.com/forkin-hell/cordespace
 * Description:       Glue entre Amelia, MyCred, User Switching et WooCommerce pour Cordespace. Architecture modulaire avec menu wp-admin Cordespace pour activer/désactiver chaque morceau de code individuellement.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Cordespace
 * License:           GPL-2.0-or-later
 * Text Domain:       cordespace-snippets
 *
 * Architecture : modules logiques déclarés dans includes/registry.php.
 * État des toggles dans wp_options['cordespace_modules_active'].
 * Doc : docs/superpowers/specs/2026-05-18-cordespace-admin-toggles-design.md
 */

defined( 'ABSPATH' ) || exit;

define( 'CORDESPACE_SNIPPETS_VERSION', '2.0.0' );
define( 'CORDESPACE_SNIPPETS_FILE',    __FILE__ );
define( 'CORDESPACE_SNIPPETS_DIR',     __DIR__ );
define( 'CORDESPACE_SNIPPETS_URL',     plugin_dir_url( __FILE__ ) );

// Helpers partagés : toujours chargés, avant tout le reste.
require_once CORDESPACE_SNIPPETS_DIR . '/includes/core/helpers.php';

// Bootstrap (init de l'option des modules actifs).
require_once CORDESPACE_SNIPPETS_DIR . '/includes/core/boot.php';

// Loader (lit registry, charge les modules actifs).
require_once CORDESPACE_SNIPPETS_DIR . '/includes/core/loader.php';

// Admin UI : seulement chargée en contexte admin.
if ( is_admin() ) {
	require_once CORDESPACE_SNIPPETS_DIR . '/includes/admin/deps-check.php';
	require_once CORDESPACE_SNIPPETS_DIR . '/includes/admin/icon-svg.php';
	require_once CORDESPACE_SNIPPETS_DIR . '/includes/admin/menu.php';
	require_once CORDESPACE_SNIPPETS_DIR . '/includes/admin/page-modules.php';
	require_once CORDESPACE_SNIPPETS_DIR . '/includes/admin/ajax-toggle.php';
}
