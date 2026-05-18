<?php
/**
 * Module : mon-espace.greeting-themes
 *
 * Permet d'appliquer un fond visuel personnalisé derrière l'en-tête
 * « Bonjour <prénom> » de la page /mon-espace/. Configurable par utilisateur·trice
 * via un champ dans le profil WP (wp-admin → Utilisateurs).
 *
 * Décorrélé du nom : le thème est lié au USER ID (post meta), pas au prénom.
 * Donc Hanna conserve son fond dinosaures même si elle change de prénom
 * affiché.
 *
 * Comment ajouter un nouveau thème :
 *   1. Ajouter une entrée dans cordespace_greeting_themes() (slug + label + CSS)
 *   2. C'est tout — l'admin verra automatiquement le nouveau choix dans le profil
 *
 * Hook d'intégration : filter `cordespace_greeting_theme_class`, appelé par
 * l'enveloppe mon-espace.shortcode pour récupérer la classe CSS à mettre
 * sur le bloc « Bonjour ». Si ce module est désactivé, le filter n'a pas
 * de listener → la classe est vide → fond par défaut pour tout le monde.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catalogue des thèmes disponibles.
 *
 * Pour en ajouter un, copier un bloc et changer slug + label + decor_css.
 * Le `decor_css` est injecté tel quel dans une <style> sur la page front.
 */
function cordespace_greeting_themes(): array {
	return [
		'dinosaurs' => [
			'label'     => '🦕 Dinosaures',
			'decor_css' => "
				.cordespace-greeting-theme-dinosaurs { position:relative; overflow:hidden; }
				.cordespace-greeting-theme-dinosaurs > * { position:relative; z-index:1; }
				.cordespace-greeting-theme-dinosaurs::before {
					content: '🦕 🦖 🦴 🦴 🦕 🦖 🦴 🦖 🦕 🦴 🦖 🦕 🦴 🦴 🦕 🦖 🦴 🦖 🦕 🦴 🦖 🦕 🦴 🦴 🦕 🦖 🦴 🦖 🦕 🦴 🦖 🦕 🦴 🦴 🦕 🦖 🦴 🦖 🦕 🦴';
					position: absolute;
					top: 0; left: 0; right: 0; bottom: 0;
					font-size: 2.3rem;
					line-height: 1.4;
					letter-spacing: 0.4em;
					opacity: 0.18;
					word-break: break-all;
					overflow: hidden;
					pointer-events: none;
					padding: 0.5rem 0.7rem;
					box-sizing: border-box;
				}
			",
		],
		// Ajouter ici : 'unicorns', 'cats', etc. Mêmes 3 propriétés.
	];
}

/**
 * Récupère le slug du thème assigné à un user (ex: 'dinosaurs' ou '').
 */
function cordespace_get_user_greeting_theme( int $user_id ): string {
	if ( $user_id <= 0 ) return '';
	$theme = (string) get_user_meta( $user_id, '_cordespace_greeting_theme', true );
	if ( $theme === '' ) return '';
	$themes = cordespace_greeting_themes();
	return isset( $themes[ $theme ] ) ? $theme : '';
}

// ============================================================================
// 1) Filter consommé par mon-espace.shortcode pour appliquer la classe CSS
// ============================================================================
add_filter( 'cordespace_greeting_theme_class', 'cordespace_apply_greeting_theme_class', 10, 2 );

function cordespace_apply_greeting_theme_class( string $class, $user ): string {
	if ( ! $user || ! isset( $user->ID ) ) return $class;
	$theme = cordespace_get_user_greeting_theme( (int) $user->ID );
	if ( $theme === '' ) return $class;
	// Lazy : on print le CSS du thème UNE seule fois par requête, et seulement
	// si un user en a vraiment besoin (évite de polluer toutes les pages avec
	// du CSS inutile)
	cordespace_print_greeting_theme_css_once( $theme );
	return trim( $class . ' cordespace-greeting-theme-' . sanitize_html_class( $theme ) );
}

function cordespace_print_greeting_theme_css_once( string $theme ): void {
	static $printed = [];
	if ( isset( $printed[ $theme ] ) ) return;
	$themes = cordespace_greeting_themes();
	if ( ! isset( $themes[ $theme ]['decor_css'] ) ) return;
	$printed[ $theme ] = true;
	$css = $themes[ $theme ]['decor_css'];
	add_action( 'wp_footer', function () use ( $css ) {
		echo "<style id='cordespace-greeting-theme-decor'>{$css}</style>";
	}, 5 );
}

// ============================================================================
// 2) Champ admin dans le profil WP : Utilisateurs → édition
// ============================================================================
add_action( 'show_user_profile', 'cordespace_greeting_theme_user_field' );
add_action( 'edit_user_profile', 'cordespace_greeting_theme_user_field' );

function cordespace_greeting_theme_user_field( $user ): void {
	if ( ! current_user_can( 'edit_users' ) && get_current_user_id() !== $user->ID ) {
		return;
	}
	$current = (string) get_user_meta( $user->ID, '_cordespace_greeting_theme', true );
	$themes  = cordespace_greeting_themes();
	?>
	<h2>Cordespace — Thème de salutation</h2>
	<table class="form-table">
		<tr>
			<th><label for="cordespace_greeting_theme">Fond derrière le « Bonjour »</label></th>
			<td>
				<select name="cordespace_greeting_theme" id="cordespace_greeting_theme">
					<option value="" <?php selected( $current, '' ); ?>>— Par défaut (aucun fond personnalisé) —</option>
					<?php foreach ( $themes as $slug => $theme ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
							<?php echo esc_html( $theme['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					Choisit un fond décoratif derrière l'en-tête « Bonjour Prénom » de la page <code>/mon-espace/</code>.
					Lié au compte, pas au prénom : si la personne change de nom, le thème suit.
				</p>
			</td>
		</tr>
	</table>
	<?php
}

add_action( 'personal_options_update', 'cordespace_greeting_theme_save_field' );
add_action( 'edit_user_profile_update', 'cordespace_greeting_theme_save_field' );

function cordespace_greeting_theme_save_field( int $user_id ): void {
	if ( ! current_user_can( 'edit_users' ) && get_current_user_id() !== $user_id ) {
		return;
	}
	$raw    = isset( $_POST['cordespace_greeting_theme'] ) ? sanitize_text_field( wp_unslash( $_POST['cordespace_greeting_theme'] ) ) : '';
	$themes = cordespace_greeting_themes();
	if ( $raw !== '' && ! isset( $themes[ $raw ] ) ) {
		// Valeur inconnue → on retombe sur défaut
		$raw = '';
	}
	if ( $raw === '' ) {
		delete_user_meta( $user_id, '_cordespace_greeting_theme' );
	} else {
		update_user_meta( $user_id, '_cordespace_greeting_theme', $raw );
	}
}
