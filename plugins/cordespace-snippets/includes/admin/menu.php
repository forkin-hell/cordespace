<?php
/**
 * Menu wp-admin Cordespace.
 *
 * Crée :
 *   - Un menu top-level "Cordespace" avec l'icône SVG nœud
 *   - Sous-menu "Modules" (page principale)
 *   - Sous-menu "↗ Voir sur GitHub" (lien externe vers le repo)
 *
 * Voir docs/superpowers/specs/2026-05-18-cordespace-admin-toggles-design.md
 */

defined( 'ABSPATH' ) || exit;

const CORDESPACE_GITHUB_BASE_URL = 'https://github.com/forkin-hell/cordespace';

/**
 * Enregistre le menu et ses sous-menus.
 */
if ( ! function_exists( 'cordespace_admin_register_menu' ) ) {
	function cordespace_admin_register_menu(): void {
		add_menu_page(
			__( 'Cordespace — Modules', 'cordespace-snippets' ),
			__( 'Cordespace', 'cordespace-snippets' ),
			'manage_options',
			'cordespace-modules',
			'cordespace_admin_render_modules_page',
			cordespace_menu_icon_data_uri(),
			80
		);

		// Sous-menu "Modules" : même slug que le parent (renomme l'item auto-créé).
		add_submenu_page(
			'cordespace-modules',
			__( 'Modules', 'cordespace-snippets' ),
			__( 'Modules', 'cordespace-snippets' ),
			'manage_options',
			'cordespace-modules',
			'cordespace_admin_render_modules_page'
		);

		// Sous-menu "Lier des comptes" : enregistré seulement si le module
		// mon-espace.linked-accounts est actif (la fonction existe).
		if ( function_exists( 'cordespace_admin_render_link_accounts_page' ) ) {
			add_submenu_page(
				'cordespace-modules',
				__( 'Lier des comptes', 'cordespace-snippets' ),
				__( 'Lier des comptes', 'cordespace-snippets' ),
				'manage_options',
				'cordespace-link-accounts',
				'cordespace_admin_render_link_accounts_page'
			);
		}

	}
}
add_action( 'admin_menu', 'cordespace_admin_register_menu' );

/**
 * Enregistre le lien externe « Voir sur GitHub » à la fin du sous-menu.
 * Hooké à priorité 100 pour qu'il soit ajouté APRÈS les sous-menus des autres
 * modules (Rapports, etc.), donc apparaît toujours en dernier de la liste.
 */
if ( ! function_exists( 'cordespace_admin_register_github_link' ) ) {
	function cordespace_admin_register_github_link(): void {
		add_submenu_page(
			'cordespace-modules',
			__( 'Voir sur GitHub', 'cordespace-snippets' ),
			'↗ ' . __( 'Voir sur GitHub', 'cordespace-snippets' ),
			'manage_options',
			CORDESPACE_GITHUB_BASE_URL
		);
	}
}
add_action( 'admin_menu', 'cordespace_admin_register_github_link', 100 );

/**
 * Force le lien GitHub à s'ouvrir dans un nouvel onglet.
 *
 * add_submenu_page() ne permet pas de définir target="_blank" nativement
 * quand le slug est une URL externe — on patche en JS en footer admin.
 */
if ( ! function_exists( 'cordespace_admin_external_link_target' ) ) {
	function cordespace_admin_external_link_target(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// Le tweak est utile sur toutes les pages admin (la sidebar est partout).
		// On laisse le hook sur tout admin pour ne pas rater le cas où la page
		// active n'est pas Cordespace.
		?>
		<script>
		(function () {
			var links = document.querySelectorAll('#adminmenu a[href^="https://github.com/forkin-hell/cordespace"]');
			links.forEach(function (a) {
				a.setAttribute('target', '_blank');
				a.setAttribute('rel', 'noopener noreferrer');
			});
		})();
		</script>
		<?php
	}
}
add_action( 'admin_footer', 'cordespace_admin_external_link_target' );
