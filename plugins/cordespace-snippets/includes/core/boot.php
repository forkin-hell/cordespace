<?php
/**
 * Initialisation de l'option wp_options['cordespace_modules_active'] au premier run.
 *
 * Au premier chargement du plugin (ou après une mise à jour qui ajoute des
 * modules), on initialise les modules `default_active` à actifs. Les modules
 * ajoutés plus tard (par mise à jour du registry) restent activables par
 * l'admin via le menu.
 *
 * ── Mode safe-install (déploiement prod prudent) ───────────────────────
 * Si la constante CORDESPACE_SAFE_INSTALL est définie à true dans wp-config.php
 * AVANT le premier chargement du plugin, AUCUN module n'est auto-activé : la
 * personne admin doit tout toggler à la main depuis wp-admin → Cordespace →
 * Modules. Les modules sont quand même marqués "vus" pour qu'ils ne soient
 * jamais auto-activés rétroactivement quand on retire la constante.
 *
 * Usage typique :
 *   1. Définir CORDESPACE_SAFE_INSTALL dans wp-config.php de prod
 *   2. Déployer le plugin via WP Pusher
 *   3. Toggle les modules un par un en vérifiant chaque étape
 *   4. (Optionnel) Retirer la constante pour les futurs modules
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
 *
 * Si CORDESPACE_SAFE_INSTALL=true est défini en wp-config.php, on marque
 * "vus" sans auto-activer — utile pour un premier déploiement prudent.
 */
function cordespace_modules_maybe_initialize_state(): void {
	$registry = require CORDESPACE_SNIPPETS_DIR . '/includes/registry.php';
	$active   = (array) get_option( 'cordespace_modules_active', [] );
	$seen     = (array) get_option( 'cordespace_modules_seen', [] );

	$safe_install   = defined( 'CORDESPACE_SAFE_INSTALL' ) && CORDESPACE_SAFE_INSTALL;
	$changed_active = false;
	$changed_seen   = false;

	foreach ( $registry['modules'] as $id => $module ) {
		if ( in_array( $id, $seen, true ) ) {
			continue; // Déjà vu, ne pas toucher à l'état
		}
		$seen[]       = $id;
		$changed_seen = true;

		// En mode safe-install, on marque "vu" mais on n'auto-active rien.
		// L'admin toggle manuellement chaque module depuis wp-admin.
		if ( $safe_install ) {
			continue;
		}

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
