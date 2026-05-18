<?php
/**
 * Module : mon-espace.greeting-themes
 *
 * Permet d'appliquer un thème visuel personnalisé sur la page /mon-espace/
 * d'un user particulier : fond décoratif derrière le « Bonjour » + petites
 * touches éparpillées (sprinkles) sur les sections et blocs.
 *
 * Décorrélé du nom : le thème est lié au USER ID (post meta), pas au prénom.
 *
 * Comment ajouter un nouveau thème : copier une entrée dans
 * cordespace_greeting_themes() et changer slug + label + 'decor_css'.
 * C'est tout — l'admin verra automatiquement le nouveau choix dans le profil.
 *
 * Convention CSS : chaque thème scope toutes ses règles sous
 * `.cordespace-theme-<slug>` (classe posée sur le wrapper de la vue par
 * mon-espace.shortcode via le filter cordespace_greeting_theme_class).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catalogue des thèmes disponibles.
 */
function cordespace_greeting_themes(): array {
	return [
		'dinosaurs' => [
			'label'     => '🦕 Dinosaures',
			'decor_css' => "
				/* Fond peuplé de dinos derrière le bloc Bonjour (juste 🦕 et 🦖) */
				.cordespace-theme-dinosaurs .cordespace-greeting-block { position:relative; overflow:hidden; }
				.cordespace-theme-dinosaurs .cordespace-greeting-block > * { position:relative; z-index:1; }
				.cordespace-theme-dinosaurs .cordespace-greeting-block::before {
					content: '🦕 🦖 🦕 🦖 🦕 🦖 🦖 🦕 🦖 🦕 🦕 🦖 🦕 🦖 🦕 🦖 🦖 🦕 🦖 🦕 🦕 🦖 🦕 🦖 🦕 🦖 🦖 🦕 🦖 🦕 🦕 🦖 🦕 🦖 🦕 🦖 🦖 🦕 🦖 🦕 🦕 🦖 🦕 🦖 🦕 🦖 🦖 🦕 🦖 🦕 🦕 🦖 🦕 🦖 🦕 🦖 🦖 🦕 🦖 🦕 🦕 🦖 🦕 🦖 🦕 🦖 🦖 🦕 🦖 🦕 🦕 🦖 🦕 🦖 🦕 🦖 🦖 🦕 🦖 🦕';
					position:absolute; inset:0;
					font-size:2.3rem; line-height:1.4; letter-spacing:0.4em;
					opacity:0.18; word-break:break-all; overflow:hidden;
					pointer-events:none; padding:0.5rem 0.7rem; box-sizing:border-box;
				}
				/* Petit diplodocus après chaque titre de section */
				.cordespace-theme-dinosaurs section > h2::after {
					content: ' 🦕';
					margin-left:0.4rem;
					opacity:0.55;
					font-size:0.85em;
				}
			",
		],

		'unicorns' => [
			'label'     => '🦄 Licornes',
			'decor_css' => "
				/* Fond peuplé de licornes derrière le bloc Bonjour */
				.cordespace-theme-unicorns .cordespace-greeting-block { position:relative; overflow:hidden; }
				.cordespace-theme-unicorns .cordespace-greeting-block > * { position:relative; z-index:1; }
				.cordespace-theme-unicorns .cordespace-greeting-block::before {
					content: '🦄 ✨ 🌈 🦄 ✨ 🌈 ✨ 🦄 🌈 ✨ 🦄 🌈 🦄 ✨ 🌈 🦄 ✨ 🌈 ✨ 🦄 🌈 ✨ 🦄 🌈 🦄 ✨ 🌈 🦄 ✨ 🌈 ✨ 🦄 🌈 ✨ 🦄 🌈 🦄 ✨ 🌈 🦄 🦄 ✨ 🌈 🦄 ✨ 🌈 ✨ 🦄 🌈 ✨ 🦄 🌈 🦄 ✨ 🌈 🦄 ✨ 🌈 ✨ 🦄 🌈 ✨ 🦄 🌈 🦄 ✨ 🌈 🦄 ✨ 🌈 ✨ 🦄 🌈 ✨ 🦄 🌈 🦄 ✨ 🌈 🦄';
					position:absolute; inset:0;
					font-size:2.3rem; line-height:1.4; letter-spacing:0.4em;
					opacity:0.18; word-break:break-all; overflow:hidden;
					pointer-events:none; padding:0.5rem 0.7rem; box-sizing:border-box;
				}
				/* Petite étoile après chaque titre de section */
				.cordespace-theme-unicorns section > h2::after {
					content: ' ✨';
					margin-left:0.4rem;
					opacity:0.55;
					font-size:0.85em;
				}
			",
		],

		// Pour ajouter un thème : copier une des entrées et changer slug + label +
		// chaîne d'emojis du fond + emoji après-h2. Pas de décorations dans les
		// coins des cartes — ça surcharge visuellement. Convention : ~80 emojis
		// dans le fond pour bien remplir le bloc (sinon trou à droite).
	];
}

/**
 * Slug du thème assigné à un user (ex: 'dinosaurs', 'unicorns', ou '' si aucun).
 */
function cordespace_get_user_greeting_theme( int $user_id ): string {
	if ( $user_id <= 0 ) return '';
	$theme = (string) get_user_meta( $user_id, '_cordespace_greeting_theme', true );
	if ( $theme === '' ) return '';
	$themes = cordespace_greeting_themes();
	return isset( $themes[ $theme ] ) ? $theme : '';
}

// ============================================================================
// 1) Filter consommé par mon-espace.shortcode : applique la classe CSS sur le
//    wrapper de toute la vue (pas juste le Bonjour). Permet aux thèmes de
//    cibler descendants : .cordespace-theme-XYZ section h2, .event-block, etc.
// ============================================================================
add_filter( 'cordespace_greeting_theme_class', 'cordespace_apply_greeting_theme_class', 10, 2 );

function cordespace_apply_greeting_theme_class( string $class, $user ): string {
	if ( ! $user || ! isset( $user->ID ) ) return $class;
	$theme = cordespace_get_user_greeting_theme( (int) $user->ID );
	if ( $theme === '' ) return $class;
	cordespace_print_greeting_theme_css_once( $theme );
	return trim( $class . ' cordespace-theme-' . sanitize_html_class( $theme ) );
}

/**
 * Print le CSS du thème UNE seule fois par requête (lazy). Évite de polluer
 * les pages où aucun user n'a de thème actif.
 */
function cordespace_print_greeting_theme_css_once( string $theme ): void {
	static $printed = [];
	if ( isset( $printed[ $theme ] ) ) return;
	$themes = cordespace_greeting_themes();
	if ( ! isset( $themes[ $theme ]['decor_css'] ) ) return;
	$printed[ $theme ] = true;
	$css = $themes[ $theme ]['decor_css'];
	add_action( 'wp_footer', function () use ( $css, $theme ) {
		echo "<style id='cordespace-greeting-theme-{$theme}-decor'>{$css}</style>";
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
			<th><label for="cordespace_greeting_theme">Fond et décorations</label></th>
			<td>
				<select name="cordespace_greeting_theme" id="cordespace_greeting_theme">
					<option value="" <?php selected( $current, '' ); ?>>— Par défaut (aucune décoration) —</option>
					<?php foreach ( $themes as $slug => $theme ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
							<?php echo esc_html( $theme['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					Applique un thème visuel discret sur la page <code>/mon-espace/</code> :
					fond derrière le « Bonjour », petits émoji décoratifs sur les titres de
					section et un émoji dans le coin de chaque carte de cours. Lié au compte,
					pas au prénom.
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
		// Slug inconnu → on retombe sur défaut
		$raw = '';
	}
	if ( $raw === '' ) {
		delete_user_meta( $user_id, '_cordespace_greeting_theme' );
	} else {
		update_user_meta( $user_id, '_cordespace_greeting_theme', $raw );
	}
}
