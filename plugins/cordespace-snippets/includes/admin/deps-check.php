<?php
/**
 * Vérification des dépendances (plugins requis) d'un module.
 *
 * Renvoie pour chaque plugin déclaré dans `requires_plugin` du registry
 * son label et son état d'activation.
 *
 * Voir docs/superpowers/specs/2026-05-18-cordespace-admin-toggles-design.md
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renvoie l'état des dépendances d'un module.
 *
 * @param string $module_id ID du module (clé du registry).
 * @return array<string, array{label: string, active: bool}>
 *         Map plugin-file => ['label' => 'Nom lisible', 'active' => bool]
 *         Tableau vide si le module n'a pas de dépendance ou n'existe pas.
 */
if ( ! function_exists( 'cordespace_module_deps_status' ) ) {
	function cordespace_module_deps_status( string $module_id ): array {
		// is_plugin_active() vit dans wp-admin/includes/plugin.php — pas
		// toujours auto-chargé selon le contexte (ex. AJAX front).
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$registry = cordespace_modules_registry();
		$module   = $registry['modules'][ $module_id ] ?? null;
		if ( ! $module ) {
			return [];
		}

		$requires = $module['requires_plugin'] ?? [];
		if ( empty( $requires ) ) {
			return [];
		}

		$status = [];
		foreach ( $requires as $plugin_file => $label ) {
			$status[ $plugin_file ] = [
				'label'  => (string) $label,
				'active' => is_plugin_active( $plugin_file ),
			];
		}
		return $status;
	}
}
