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
				/* Fond peuplé de dinos derrière le bloc Bonjour — pattern SVG tilé
				   avec emojis à positions et rotations variées pour un rendu
				   « scattered » plutôt qu'aligné en grille. */
				.cordespace-theme-dinosaurs .cordespace-greeting-block { position:relative; overflow:hidden; }
				.cordespace-theme-dinosaurs .cordespace-greeting-block > * { position:relative; z-index:1; }
				.cordespace-theme-dinosaurs .cordespace-greeting-block::before {
					content:'';
					position:absolute; inset:0;
					background-image: url(\"data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='320' height='220' font-family='Apple Color Emoji, Segoe UI Emoji, Noto Color Emoji, EmojiOne Color, Twemoji Mozilla, sans-serif'><text x='30' y='55' font-size='38' transform='rotate(-12 47 41)'>🦕</text><text x='150' y='40' font-size='32' transform='rotate(8 165 28)'>🦖</text><text x='250' y='75' font-size='40' transform='rotate(-6 270 60)'>🦕</text><text x='75' y='130' font-size='34' transform='rotate(15 92 117)'>🦖</text><text x='180' y='155' font-size='42' transform='rotate(-4 200 141)'>🦕</text><text x='285' y='180' font-size='30' transform='rotate(10 298 168)'>🦖</text><text x='20' y='200' font-size='34' transform='rotate(-15 35 188)'>🦕</text></svg>\");
					background-size: 320px 220px;
					background-repeat: repeat;
					opacity:0.18;
					pointer-events:none;
				}
				/* Petit diplodocus après chaque titre de section (pleine opacité) */
				.cordespace-theme-dinosaurs section > h2::after {
					content: ' 🦕';
					margin-left:0.4rem;
					font-size:0.85em;
				}
			",
		],

		'unicorns' => [
			'label'     => '🦄 Licornes',
			'decor_css' => "
				/* Fond peuplé de licornes — pattern SVG tilé en scatter */
				.cordespace-theme-unicorns .cordespace-greeting-block { position:relative; overflow:hidden; }
				.cordespace-theme-unicorns .cordespace-greeting-block > * { position:relative; z-index:1; }
				.cordespace-theme-unicorns .cordespace-greeting-block::before {
					content:'';
					position:absolute; inset:0;
					background-image: url(\"data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='320' height='220' font-family='Apple Color Emoji, Segoe UI Emoji, Noto Color Emoji, EmojiOne Color, Twemoji Mozilla, sans-serif'><text x='30' y='50' font-size='38' transform='rotate(-10 47 36)'>🦄</text><text x='130' y='35' font-size='28' transform='rotate(12 145 25)'>✨</text><text x='200' y='65' font-size='34' transform='rotate(-5 218 51)'>🌈</text><text x='280' y='45' font-size='26' transform='rotate(8 290 35)'>✨</text><text x='65' y='115' font-size='32' transform='rotate(15 82 102)'>🌈</text><text x='160' y='140' font-size='40' transform='rotate(-8 180 124)'>🦄</text><text x='265' y='130' font-size='28' transform='rotate(10 277 120)'>✨</text><text x='35' y='195' font-size='30' transform='rotate(-12 50 183)'>🌈</text><text x='130' y='205' font-size='28' transform='rotate(6 144 195)'>✨</text><text x='225' y='185' font-size='36' transform='rotate(-4 244 169)'>🦄</text></svg>\");
					background-size: 320px 220px;
					background-repeat: repeat;
					opacity:0.18;
					pointer-events:none;
				}
				/* Petite étoile après chaque titre de section (pleine opacité) */
				.cordespace-theme-unicorns section > h2::after {
					content: ' ✨';
					margin-left:0.4rem;
					font-size:0.85em;
				}
			",
		],

		'chicks' => [
			'label'     => '🐣 Poussins',
			'decor_css' => "
				/* Fond peuplé de poussins, cœurs jaunes et burgers — pattern
				   SVG tilé en scatter. Demandé par une utilisatrice. */
				.cordespace-theme-chicks .cordespace-greeting-block { position:relative; overflow:hidden; }
				.cordespace-theme-chicks .cordespace-greeting-block > * { position:relative; z-index:1; }
				.cordespace-theme-chicks .cordespace-greeting-block::before {
					content:'';
					position:absolute; inset:0;
					background-image: url(\"data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='320' height='220' font-family='Apple Color Emoji, Segoe UI Emoji, Noto Color Emoji, EmojiOne Color, Twemoji Mozilla, sans-serif'><text x='30' y='50' font-size='38' transform='rotate(-12 47 36)'>🐣</text><text x='130' y='38' font-size='28' transform='rotate(10 145 28)'>💛</text><text x='210' y='62' font-size='34' transform='rotate(-6 228 48)'>🍔</text><text x='285' y='42' font-size='30' transform='rotate(14 298 32)'>🐣</text><text x='60' y='115' font-size='32' transform='rotate(8 78 102)'>💛</text><text x='155' y='140' font-size='40' transform='rotate(-10 175 124)'>🐣</text><text x='265' y='135' font-size='28' transform='rotate(12 277 125)'>💛</text><text x='30' y='200' font-size='30' transform='rotate(-8 45 188)'>🍔</text><text x='130' y='205' font-size='34' transform='rotate(6 148 191)'>🐣</text><text x='230' y='190' font-size='32' transform='rotate(-6 246 176)'>🍔</text></svg>\");
					background-size: 320px 220px;
					background-repeat: repeat;
					opacity:0.18;
					pointer-events:none;
				}
				/* Petit poussin après chaque titre de section (pleine opacité) */
				.cordespace-theme-chicks section > h2::after {
					content: ' 🐣';
					margin-left:0.4rem;
					font-size:0.85em;
				}
			",
		],

		// Pour ajouter un thème : copier une des entrées et adapter le pattern SVG.
		// Convention : SVG tile 320x220 avec ~7-10 emojis à positions variées et
		// rotations entre -15° et +15°. Pas de décorations dans les coins des
		// cartes (épuré).
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
