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
 *
 * Chaque thème définit :
 * - label      : libellé affiché dans le dropdown du profil
 * - emojis     : liste d'emojis avec leur position (top/left en %, rotate en
 *                degrés, size en px) pour le décor de fond du bloc Bonjour.
 *                Rendus en HTML <span> absolument positionnés → utilisent
 *                la vraie police emoji système (Apple Color Emoji sur Mac/
 *                iOS, Noto sur Android, etc.) — donc rendu cohérent avec
 *                le reste des emojis du texte.
 * - title_suffix : emoji ajouté après chaque <h2> de section (via CSS).
 */
function cordespace_greeting_themes(): array {
	return [
		'dinosaurs' => [
			'label'        => '🦕 Dinosaures',
			'title_suffix' => '🦕',
			'emojis'       => [
				[ 'emoji' => '🦕', 'top' => '10%', 'left' => '5%',  'rotate' => -12, 'size' => 38 ],
				[ 'emoji' => '🦖', 'top' => '8%',  'left' => '40%', 'rotate' => 8,   'size' => 32 ],
				[ 'emoji' => '🦕', 'top' => '20%', 'left' => '75%', 'rotate' => -6,  'size' => 40 ],
				[ 'emoji' => '🦖', 'top' => '50%', 'left' => '15%', 'rotate' => 15,  'size' => 34 ],
				[ 'emoji' => '🦕', 'top' => '60%', 'left' => '50%', 'rotate' => -4,  'size' => 42 ],
				[ 'emoji' => '🦖', 'top' => '70%', 'left' => '85%', 'rotate' => 10,  'size' => 30 ],
				[ 'emoji' => '🦕', 'top' => '85%', 'left' => '8%',  'rotate' => -15, 'size' => 34 ],
			],
		],

		'unicorns' => [
			'label'        => '🦄 Licornes',
			'title_suffix' => '✨',
			'emojis'       => [
				[ 'emoji' => '🦄', 'top' => '8%',  'left' => '5%',  'rotate' => -10, 'size' => 38 ],
				[ 'emoji' => '✨', 'top' => '5%',  'left' => '40%', 'rotate' => 12,  'size' => 28 ],
				[ 'emoji' => '🌈', 'top' => '18%', 'left' => '60%', 'rotate' => -5,  'size' => 34 ],
				[ 'emoji' => '✨', 'top' => '10%', 'left' => '85%', 'rotate' => 8,   'size' => 26 ],
				[ 'emoji' => '🌈', 'top' => '45%', 'left' => '15%', 'rotate' => 15,  'size' => 32 ],
				[ 'emoji' => '🦄', 'top' => '55%', 'left' => '50%', 'rotate' => -8,  'size' => 40 ],
				[ 'emoji' => '✨', 'top' => '50%', 'left' => '82%', 'rotate' => 10,  'size' => 28 ],
				[ 'emoji' => '🌈', 'top' => '82%', 'left' => '8%',  'rotate' => -12, 'size' => 30 ],
				[ 'emoji' => '✨', 'top' => '88%', 'left' => '40%', 'rotate' => 6,   'size' => 28 ],
				[ 'emoji' => '🦄', 'top' => '78%', 'left' => '70%', 'rotate' => -4,  'size' => 36 ],
			],
		],

		'chicks' => [
			'label'        => '🐣 Poussins',
			'title_suffix' => '🐣',
			'emojis'       => [
				[ 'emoji' => '🐣', 'top' => '8%',  'left' => '5%',  'rotate' => -12, 'size' => 38 ],
				[ 'emoji' => '💛', 'top' => '5%',  'left' => '40%', 'rotate' => 10,  'size' => 28 ],
				[ 'emoji' => '🍔', 'top' => '18%', 'left' => '65%', 'rotate' => -6,  'size' => 34 ],
				[ 'emoji' => '🐣', 'top' => '8%',  'left' => '88%', 'rotate' => 14,  'size' => 30 ],
				[ 'emoji' => '💛', 'top' => '45%', 'left' => '15%', 'rotate' => 8,   'size' => 32 ],
				[ 'emoji' => '🐣', 'top' => '55%', 'left' => '48%', 'rotate' => -10, 'size' => 40 ],
				[ 'emoji' => '💛', 'top' => '50%', 'left' => '82%', 'rotate' => 12,  'size' => 28 ],
				[ 'emoji' => '🍔', 'top' => '85%', 'left' => '8%',  'rotate' => -8,  'size' => 30 ],
				[ 'emoji' => '🐣', 'top' => '88%', 'left' => '40%', 'rotate' => 6,   'size' => 34 ],
				[ 'emoji' => '🍔', 'top' => '80%', 'left' => '70%', 'rotate' => -6,  'size' => 32 ],
			],
		],

		// Pour ajouter un thème : copier une des entrées et adapter
		// `label`, `title_suffix` et `emojis`. Convention : ~7-10 emojis
		// répartis sur la zone, rotations entre -15° et +15°, tailles entre
		// 26 et 42 px.
	];
}

/**
 * CSS de base commun à tous les thèmes — positionne le décor en arrière-plan
 * du bloc Bonjour et ajoute le suffixe emoji après chaque <h2> de section.
 */
function cordespace_greeting_themes_base_css(): string {
	$css = "
		/* Wrapper Bonjour : conteneur pour le décor absolu */
		.cordespace-page .cordespace-greeting-block { position:relative; overflow:hidden; }
		.cordespace-page .cordespace-greeting-block > *:not(.cordespace-greeting-decor) {
			position:relative; z-index:1;
		}
		/* Décor lui-même : couvre tout le bloc en fond, opacité réduite,
		   pas cliquable, en dessous du contenu. */
		.cordespace-greeting-decor {
			position:absolute; inset:0;
			pointer-events:none;
			opacity:0.18;
			z-index:0;
			overflow:hidden;
		}
		.cordespace-greeting-decor span {
			position:absolute;
			line-height:1;
			user-select:none;
		}
	";

	// Suffixe emoji après h2 de section, par thème.
	foreach ( cordespace_greeting_themes() as $slug => $theme ) {
		if ( empty( $theme['title_suffix'] ) ) continue;
		$css .= sprintf(
			".cordespace-theme-%s section > h2::after { content: ' %s'; margin-left:0.4rem; font-size:0.85em; }\n",
			esc_attr( $slug ),
			esc_html( $theme['title_suffix'] )
		);
	}

	return $css;
}

/**
 * Rend le HTML décor d'un thème (à appeler à l'intérieur du bloc Bonjour).
 * Renvoie une chaîne ou rien si le thème n'a pas d'emojis configurés.
 */
function cordespace_render_greeting_decor( string $theme_slug ): void {
	$themes = cordespace_greeting_themes();
	if ( empty( $themes[ $theme_slug ]['emojis'] ) ) return;

	echo '<div class="cordespace-greeting-decor" aria-hidden="true">';
	foreach ( $themes[ $theme_slug ]['emojis'] as $e ) {
		printf(
			'<span style="top:%s;left:%s;transform:rotate(%ddeg);font-size:%dpx;">%s</span>',
			esc_attr( $e['top'] ),
			esc_attr( $e['left'] ),
			(int) $e['rotate'],
			(int) $e['size'],
			esc_html( $e['emoji'] )
		);
	}
	echo '</div>';
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
 * Print le CSS commun à tous les thèmes UNE seule fois par requête (lazy).
 * Évite de polluer les pages où aucun user n'a de thème actif. Le CSS est
 * commun à tous les thèmes (positionnement absolu du décor, suffixe h2) ;
 * la liste des emojis et leurs positions est gérée par
 * cordespace_render_greeting_decor() qui injecte directement le HTML.
 */
function cordespace_print_greeting_theme_css_once( string $theme ): void {
	static $printed = false;
	if ( $printed ) return;
	$printed = true;
	$css = cordespace_greeting_themes_base_css();
	add_action( 'wp_footer', function () use ( $css ) {
		echo "<style id='cordespace-greeting-themes-base'>{$css}</style>";
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
