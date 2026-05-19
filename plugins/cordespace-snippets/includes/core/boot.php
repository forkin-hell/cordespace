<?php
/**
 * Initialisation de l'option wp_options['cordespace_modules_active'] au premier run.
 *
 * Au premier chargement du plugin (ou après une mise à jour qui ajoute des
 * modules), on initialise les modules `default_active` à actifs. Les modules
 * ajoutés plus tard (par mise à jour du registry) restent activables par
 * l'admin via le menu.
 *
 * Voir docs/superpowers/specs/2026-05-18-cordespace-admin-toggles-design.md §4.4, §7.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Active les modules `default_active => true` qui ne sont pas encore dans
 * l'option. N'altère JAMAIS la décision d'un admin (si un module est explicitement
 * désactivé, on ne le réactive pas).
 *
 * Stocke aussi la liste des modules vus pour la prochaine fois (sentinelle
 * `cordespace_modules_seen`) : on ne réinjecte un module en `default_active`
 * que la PREMIÈRE fois qu'on le voit. Ça évite qu'un admin qui désactive un
 * module se retrouve avec ce module réactivé à chaque mise à jour du plugin.
 */
function cordespace_modules_maybe_initialize_state(): void {
	$registry = require CORDESPACE_SNIPPETS_DIR . '/includes/registry.php';
	$active   = (array) get_option( 'cordespace_modules_active', [] );
	$seen     = (array) get_option( 'cordespace_modules_seen', [] );

	$changed_active = false;
	$changed_seen   = false;

	foreach ( $registry['modules'] as $id => $module ) {
		if ( in_array( $id, $seen, true ) ) {
			continue; // Déjà vu, ne pas toucher à l'état
		}
		$seen[]         = $id;
		$changed_seen   = true;
		if ( ! empty( $module['default_active'] ) ) {
			$active[]       = $id;
			$changed_active = true;
		}
	}

	if ( $changed_active ) {
		update_option( 'cordespace_modules_active', array_values( array_unique( $active ) ) );
	}
	if ( $changed_seen ) {
		update_option( 'cordespace_modules_seen', array_values( array_unique( $seen ) ) );
	}
}

// Au plugins_loaded très tôt, avant le chargement des modules.
add_action( 'plugins_loaded', 'cordespace_modules_maybe_initialize_state', 1 );

// Aussi à l'activation du plugin (1er install).
register_activation_hook( CORDESPACE_SNIPPETS_FILE, 'cordespace_modules_maybe_initialize_state' );
