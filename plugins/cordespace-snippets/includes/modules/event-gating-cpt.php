<?php
/**
 * Module: event-gating-cpt
 *
 * Custom Post Type `cordespace_event_type` : un « type d'événement gated »
 * que l'admin définit (ex : « Semi-privé », « Privé », « Atelier expert »).
 * Chaque type a :
 *   - un titre (post_title)
 *   - un texte de bandeau (post_content, WYSIWYG) qui s'affichera au panier
 *     si la personne n'est pas approuvée
 *   - une liste d'étiquettes Amelia qui déclenchent l'application du type
 *     (= matching auto avec les events Amelia)
 *   - une URL d'info (où la personne va apprendre comment se faire valider)
 *
 * Sous-menu Cordespace → Events gated (priorité 40, entre Waivers à 30 et
 * Voir sur GitHub à 100). show_in_menu=false sur le CPT + add_submenu_page
 * manuel pour garantir l'ordre (même pattern que waivers-cpt.php).
 */

defined( 'ABSPATH' ) || exit;

// Slug court (max 20 char autorisés par WP pour un CPT). 'cordespace_event_type'
// aurait fait 21 caractères → erreur 'Type de contenu non valide'. On garde
// les constantes meta avec leur nom long, c'est juste le slug du CPT qui
// est compact.
const CORDESPACE_EVENT_TYPE_POST_TYPE                  = 'cordespace_evtype';
const CORDESPACE_EVENT_TYPE_META_TAGS                  = '_cordespace_event_type_amelia_tags';
const CORDESPACE_EVENT_TYPE_META_INFO_URL              = '_cordespace_event_type_info_url';
const CORDESPACE_EVENT_TYPE_META_APPLIES_APPT          = '_cordespace_event_type_applies_to_appointments';
const CORDESPACE_EVENT_TYPE_META_APPT_TAGS             = '_cordespace_event_type_appt_tags';
const CORDESPACE_EVENT_TYPE_META_TAG_IMPLICATIONS      = '_cordespace_event_type_tag_implications';

// Étiquette spéciale hardcodée pour les appointments (réservations de salle).
// Ce n'est PAS une étiquette Amelia mais elle se comporte comme une étiquette
// dans tout le système (colonne dans matrice, hiérarchie, cross-type, etc.).
const CORDESPACE_EVENT_TYPE_TAG_SALLES                 = 'Réservation des salles';
const CORDESPACE_EVENT_TYPE_META_IMPLIED_FROM_TYPES    = '_cordespace_event_type_implied_from_types';
const CORDESPACE_EVENT_TYPE_META_IMPLIED_FROM_TAG_MAP  = '_cordespace_event_type_implied_from_tag_filters';
const CORDESPACE_EVENT_TYPE_META_IMPLIED_FROM_ROLE     = '_cordespace_event_type_implied_from_role';

// ============================================================================
// 1) Enregistrement du CPT
// ============================================================================

function cordespace_event_gating_register_cpt(): void {
	register_post_type(
		CORDESPACE_EVENT_TYPE_POST_TYPE,
		[
			'label'           => __( "Types d'événements à validation", 'cordespace-snippets' ),
			'labels'          => [
				'name'          => __( "Types d'événements à validation", 'cordespace-snippets' ),
				'singular_name' => __( "Type d'événement à validation", 'cordespace-snippets' ),
				'add_new'       => __( 'Ajouter', 'cordespace-snippets' ),
				'add_new_item'  => __( "Nouveau type", 'cordespace-snippets' ),
				'edit_item'     => __( 'Éditer le type', 'cordespace-snippets' ),
				'search_items'  => __( 'Rechercher un type', 'cordespace-snippets' ),
				'not_found'     => __( 'Aucun type configuré.', 'cordespace-snippets' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'supports'        => [ 'title', 'editor', 'revisions' ],
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'rewrite'         => false,
			'has_archive'     => false,
			'menu_icon'       => 'dashicons-lock',
		]
	);
}
add_action( 'init', 'cordespace_event_gating_register_cpt' );

// ============================================================================
// 2) Sous-menu Cordespace + highlight parent_file
// ============================================================================

function cordespace_event_gating_register_admin_submenu(): void {
	add_submenu_page(
		'cordespace-modules',
		__( "Types d'événements à validation", 'cordespace-snippets' ),
		__( 'Événements à validation', 'cordespace-snippets' ),
		'edit_posts',
		'edit.php?post_type=' . CORDESPACE_EVENT_TYPE_POST_TYPE
	);
}
add_action( 'admin_menu', 'cordespace_event_gating_register_admin_submenu', 40 );

/**
 * Quand on est sur l'écran d'édition / liste d'un event type, force WP à
 * highlighter Cordespace → Events gated dans le menu (sinon il perd la
 * trace du parent puisqu'on a coupé show_in_menu).
 */
function cordespace_event_gating_highlight_admin_menu( string $parent_file ): string {
	global $current_screen, $submenu_file;
	if ( $current_screen && $current_screen->post_type === CORDESPACE_EVENT_TYPE_POST_TYPE ) {
		$submenu_file = 'edit.php?post_type=' . CORDESPACE_EVENT_TYPE_POST_TYPE;
		return 'cordespace-modules';
	}
	return $parent_file;
}
add_filter( 'parent_file', 'cordespace_event_gating_highlight_admin_menu' );

// ============================================================================
// 3) Metabox : Étiquettes Amelia
// ============================================================================

function cordespace_event_gating_add_tags_metabox(): void {
	add_meta_box(
		'cordespace-event-type-tags',
		__( "🏷️ Étiquettes Amelia à matcher", 'cordespace-snippets' ),
		'cordespace_event_gating_render_tags_metabox',
		CORDESPACE_EVENT_TYPE_POST_TYPE,
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes_' . CORDESPACE_EVENT_TYPE_POST_TYPE, 'cordespace_event_gating_add_tags_metabox' );

function cordespace_event_gating_render_tags_metabox( WP_Post $post ): void {
	global $wpdb;

	$all_tags = $wpdb->get_col(
		"SELECT DISTINCT name FROM {$wpdb->prefix}amelia_events_tags ORDER BY name ASC"
	);
	$all_tags = array_filter( (array) $all_tags );

	$selected = get_post_meta( $post->ID, CORDESPACE_EVENT_TYPE_META_TAGS, true );
	if ( ! is_array( $selected ) ) {
		$selected = [];
	}

	wp_nonce_field( 'cordespace_event_gating_meta_save', 'cordespace_event_gating_nonce' );
	?>
	<p style="margin-top:0;">
		<?php esc_html_e( "Coche les étiquettes Amelia pour lesquelles ce type s'applique. Tout événement portant AU MOINS UNE de ces étiquettes nécessitera l'approbation admin avant que la personne puisse le réserver.", 'cordespace-snippets' ); ?>
	</p>

	<?php if ( empty( $all_tags ) ) : ?>
		<p style="padding:1rem 1.2rem; background:#fff8e1; border-left:4px solid #f5b800; color:#7a5d00;">
			⚠️ <?php esc_html_e( "Aucune étiquette Amelia trouvée. Crée d'abord des étiquettes dans wp-admin → Amelia → Events.", 'cordespace-snippets' ); ?>
		</p>
	<?php else : ?>
		<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:0.5rem 1rem; padding:0.6rem 0;">
			<?php foreach ( $all_tags as $tag ) :
				$checked = in_array( $tag, $selected, true );
				?>
				<label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer;">
					<input type="checkbox" name="cordespace_event_type_tags[]" value="<?php echo esc_attr( $tag ); ?>" <?php checked( $checked ); ?>>
					<span><?php echo esc_html( $tag ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<p style="margin-top:1rem; padding:0.6rem 0.9rem; background:#f0f6fc; border-left:3px solid #2c70b8; font-size:0.92em;">
			<?php
			echo wp_kses_post( sprintf(
				/* translators: %d = number of selected tags */
				_n(
					"%d étiquette sélectionnée → tout event Amelia portant cette étiquette sera gated par ce type.",
					"%d étiquettes sélectionnées → tout event Amelia portant AU MOINS UNE de ces étiquettes sera gated par ce type.",
					count( $selected ),
					'cordespace-snippets'
				),
				count( $selected )
			) );
			?>
		</p>
	<?php endif; ?>

	<?php
	// === Étiquette spéciale hardcodée : « Réservation des salles » ===
	// C'est une case à cocher unique qui se comporte comme une étiquette Amelia
	// (colonne dans la matrice, apparait dans la hiérarchie, etc.) mais qui
	// déclenche le gating sur les appointments Amelia (pas sur les events).
	$salles_enabled = in_array( CORDESPACE_EVENT_TYPE_TAG_SALLES, $selected, true );
	?>
	<h4 style="margin:1.2rem 0 0.4rem;">🏠 <?php esc_html_e( 'Étiquette spéciale pour les réservations de salle', 'cordespace-snippets' ); ?></h4>
	<p class="description" style="font-size:0.9em; margin:0 0 0.6rem;">
		<?php esc_html_e( "Cocher cette case ajoute une catégorie « Réservation des salles » à ce type. Elle se comporte comme une étiquette Amelia (colonne dans la matrice membres, apparaît dans la hiérarchie d'implications) mais déclenche le gating sur les appointments Amelia (les réservations de salle) au lieu des events.", 'cordespace-snippets' ); ?>
	</p>
	<label style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0.8rem; background:#e8f5e9; border:1px solid #a5d6a7; border-radius:5px; cursor:pointer;">
		<input type="checkbox" name="cordespace_evtype_enable_salles" value="1" <?php checked( $salles_enabled ); ?>>
		<strong>🏠 <?php esc_html_e( "Activer la catégorie « Réservation des salles »", 'cordespace-snippets' ); ?></strong>
	</label>

	<?php
	// === Section : Hiérarchie d'implications (existante, lit META_TAGS donc
	// inclut automatiquement "Réservation des salles" si activée) ===
	cordespace_event_gating_render_tag_implications_ui( $post, $selected );
	?>
	<?php
}

/**
 * Sous-section de la metabox tags : configure la hiérarchie d'implications
 * entre tags du type. Un tag « parent » peut impliquer un ou plusieurs tags
 * « enfants » : valider un membre sur le parent l'auto-valide aussi sur les
 * enfants. Asymétrique : refuser le parent n'a aucun effet sur les enfants.
 *
 * Note de design : on rend ce tableau à partir des tags ACTUELLEMENT
 * SAUVEGARDÉS (pas ceux cochés dans la session courante). Pour configurer
 * les implications d'un nouveau tag, l'admin doit d'abord cocher + sauver,
 * puis revenir ici. Permet d'éviter du JS complexe.
 */
function cordespace_event_gating_render_tag_implications_ui( WP_Post $post, array $selected_tags ): void {
	if ( count( $selected_tags ) < 2 ) {
		return; // pas assez de tags pour avoir des implications
	}

	$implications = get_post_meta( $post->ID, CORDESPACE_EVENT_TYPE_META_TAG_IMPLICATIONS, true );
	$implications = is_array( $implications ) ? $implications : [];
	?>
	<details style="margin-top:1.2rem; padding:0.8rem 1rem; background:#fffcf0; border:1px solid #f0e0a0; border-radius:6px;">
		<summary style="cursor:pointer; font-weight:600; color:#5c4a00;">
			🪜 <?php esc_html_e( "Hiérarchie d'implications (optionnel)", 'cordespace-snippets' ); ?>
		</summary>
		<p style="margin:0.6rem 0; font-size:0.9em; color:#5c4a00;">
			<?php esc_html_e( "Permet de définir qu'un tag « parent » implique automatiquement un tag « enfant ». Quand tu valides un·e membre sur le parent, l'enfant est aussi validé automatiquement. Asymétrique : refuser le parent n'a pas d'effet sur l'enfant.", 'cordespace-snippets' ); ?>
		</p>
		<table class="widefat" style="margin-top:0.6rem;">
			<thead>
				<tr>
					<th style="padding:0.4rem;"><?php esc_html_e( 'Si ce tag est validé…', 'cordespace-snippets' ); ?></th>
					<th style="padding:0.4rem;"><?php esc_html_e( '…valider aussi automatiquement :', 'cordespace-snippets' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $selected_tags as $parent_tag ) :
					$current_implied = isset( $implications[ $parent_tag ] ) && is_array( $implications[ $parent_tag ] )
						? $implications[ $parent_tag ]
						: [];
					?>
					<tr>
						<td style="padding:0.5rem;"><strong><?php echo esc_html( $parent_tag ); ?></strong></td>
						<td style="padding:0.5rem;">
							<?php foreach ( $selected_tags as $child_tag ) :
								if ( $child_tag === $parent_tag ) {
									continue;
								}
								$is_checked = in_array( $child_tag, $current_implied, true );
								?>
								<label style="display:inline-flex; align-items:center; gap:0.3rem; margin-right:1rem;">
									<input type="checkbox" name="cordespace_tag_implications[<?php echo esc_attr( $parent_tag ); ?>][]" value="<?php echo esc_attr( $child_tag ); ?>" <?php checked( $is_checked ); ?>>
									<?php echo esc_html( $child_tag ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</details>
	<?php
}

/**
 * Renvoie la map d'implications pour un type donné.
 *
 * @return array<string, string[]> [tag_parent => [tags_enfants_impliqués]]
 *         Vide si pas configuré.
 */
function cordespace_event_gating_get_tag_implications( int $type_id ): array {
	if ( $type_id <= 0 ) {
		return [];
	}
	$raw = get_post_meta( $type_id, CORDESPACE_EVENT_TYPE_META_TAG_IMPLICATIONS, true );
	if ( ! is_array( $raw ) ) {
		return [];
	}
	$out = [];
	foreach ( $raw as $parent => $children ) {
		if ( ! is_string( $parent ) || ! is_array( $children ) ) {
			continue;
		}
		$out[ $parent ] = array_values( array_unique( array_filter( array_map( 'strval', $children ) ) ) );
	}
	return $out;
}

// ============================================================================
// 4) Metabox : URL d'info
// ============================================================================

function cordespace_event_gating_add_info_url_metabox(): void {
	add_meta_box(
		'cordespace-event-type-info-url',
		__( "🔗 URL d'info pour se faire valider", 'cordespace-snippets' ),
		'cordespace_event_gating_render_info_url_metabox',
		CORDESPACE_EVENT_TYPE_POST_TYPE,
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes_' . CORDESPACE_EVENT_TYPE_POST_TYPE, 'cordespace_event_gating_add_info_url_metabox' );

function cordespace_event_gating_render_info_url_metabox( WP_Post $post ): void {
	$url = (string) get_post_meta( $post->ID, CORDESPACE_EVENT_TYPE_META_INFO_URL, true );
	?>
	<p style="margin-top:0;">
		<?php esc_html_e( "URL où la personne non-approuvée sera redirigée pour apprendre comment se faire valider. Affichée dans le bandeau de gating sous la forme d'un bouton « En savoir plus ».", 'cordespace-snippets' ); ?>
	</p>
	<p>
		<input type="url"
		       name="cordespace_event_type_info_url"
		       value="<?php echo esc_attr( $url ); ?>"
		       class="widefat"
		       placeholder="https://cordespace.com/semi-prive/">
	</p>
	<p class="description" style="font-size:0.9em;">
		<?php esc_html_e( "Laisser vide pour ne pas afficher de bouton (le bandeau montrera juste le texte).", 'cordespace-snippets' ); ?>
	</p>
	<?php
}

// ============================================================================
// 4c) Metabox : Réservations de salle (toggle global)
// ============================================================================

// L'ancienne metabox « 🏠 Réservations de salle » a été retirée. La case à
// cocher « Réservation des salles » est maintenant directement dans la
// metabox principale des étiquettes (= au même endroit que les étiquettes
// Amelia, plus intuitif). Le toggle global META_APPLIES_APPT reste lu en
// rétro-compat par applicable_types_for_amelia_appointment() mais n'est plus
// modifiable via UI — utilise la case « Activer la catégorie Réservation des
// salles » à la place.
//
// function cordespace_event_gating_add_appointments_metabox() {} (supprimée)

function cordespace_event_gating_render_appointments_metabox( WP_Post $post ): void {
	$applies = (string) get_post_meta( $post->ID, CORDESPACE_EVENT_TYPE_META_APPLIES_APPT, true );

	// Si le mode par-étiquette est utilisé (META_APPT_TAGS non vide), ce toggle
	// global est legacy/obsolète. On l'affiche dépréciable.
	$appt_tags = get_post_meta( $post->ID, CORDESPACE_EVENT_TYPE_META_APPT_TAGS, true );
	$using_per_tag = is_array( $appt_tags ) && ! empty( $appt_tags );

	if ( $using_per_tag ) :
		?>
		<p style="padding:0.6rem 0.9rem; background:#eef5fd; border-left:3px solid #2c70b8; font-size:0.9em; color:#1d4d7e; margin:0;">
			ℹ️ <?php esc_html_e( "Tu utilises déjà le mode par-étiquette (« Étiquettes qui gatent aussi les réservations de salle » dans la metabox des étiquettes). Ce toggle global est désactivé pour éviter les conflits.", 'cordespace-snippets' ); ?>
		</p>
		<input type="hidden" name="cordespace_evtype_applies_to_appointments" value="0">
		<?php
	else :
		?>
		<p style="margin-top:0;">
			<label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
				<input type="checkbox" name="cordespace_evtype_applies_to_appointments" value="1" <?php checked( $applies, '1' ); ?>>
				<strong><?php esc_html_e( "S'applique à TOUTES les réservations de salle (appointments Amelia)", 'cordespace-snippets' ); ?></strong>
			</label>
		</p>
		<p class="description" style="font-size:0.92em;">
			<?php esc_html_e( "Mode legacy (type binaire sans étiquettes). Si coché, ce type bloque la réservation de N'IMPORTE QUELLE salle. Préfère le mode par-étiquette dans la metabox « Étiquettes Amelia » plus haut si tu veux de la granularité.", 'cordespace-snippets' ); ?>
		</p>
		<?php
	endif;
}

// ============================================================================
// 4d) Metabox : Implications externes (cross-type + rôle)
// ============================================================================

function cordespace_event_gating_add_implied_from_metabox(): void {
	add_meta_box(
		'cordespace-event-type-implied-from',
		__( '🔗 Implications externes', 'cordespace-snippets' ),
		'cordespace_event_gating_render_implied_from_metabox',
		CORDESPACE_EVENT_TYPE_POST_TYPE,
		'normal',
		'default'
	);
}
// Metabox '🔗 Implications externes' DÉSACTIVÉE par décision de Tess (06/2026) :
// les profs sont ajouté·es manuellement à chaque type via la matrice plutôt
// que via une auto-validation par rôle. Les helpers get_implied_from_*()
// + le check côté is_user_approved_for_type continuent de fonctionner en
// rétro-compat pour ceux qui ont des metas configurées en DB, mais l'UI
// n'est plus exposée.
//
// add_action( 'add_meta_boxes_' . CORDESPACE_EVENT_TYPE_POST_TYPE, 'cordespace_event_gating_add_implied_from_metabox' );

function cordespace_event_gating_render_implied_from_metabox( WP_Post $post ): void {
	$implied_types = get_post_meta( $post->ID, CORDESPACE_EVENT_TYPE_META_IMPLIED_FROM_TYPES, true );
	$implied_types = is_array( $implied_types ) ? array_map( 'intval', $implied_types ) : [];
	$implied_role  = (string) get_post_meta( $post->ID, CORDESPACE_EVENT_TYPE_META_IMPLIED_FROM_ROLE, true );

	$all_types = get_posts( [
		'post_type'      => CORDESPACE_EVENT_TYPE_POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'exclude'        => [ $post->ID ],
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );

	$wp_roles = wp_roles();
	$roles    = $wp_roles ? $wp_roles->get_names() : [];
	?>
	<p style="margin-top:0;">
		<?php esc_html_e( "Ce panneau permet d'auto-valider des personnes pour CE type, sans avoir à les lister une à une. Le check est dynamique : si la config change, l'effet est immédiat.", 'cordespace-snippets' ); ?>
	</p>

	<h4 style="margin:1rem 0 0.5rem;">📥 <?php esc_html_e( 'Inclut automatiquement les membres validé·es des types suivants', 'cordespace-snippets' ); ?></h4>
	<p class="description" style="font-size:0.9em; margin:0 0 0.6rem;">
		<?php esc_html_e( "Toute personne validée pour AU MOINS UN tag d'un type coché ci-dessous sera aussi considérée comme validée pour CE type. Pratique pour dire « les membres semi-privé full ont aussi accès aux salles ».", 'cordespace-snippets' ); ?>
	</p>

	<?php
	$tag_filters = get_post_meta( $post->ID, CORDESPACE_EVENT_TYPE_META_IMPLIED_FROM_TAG_MAP, true );
	$tag_filters = is_array( $tag_filters ) ? $tag_filters : [];
	?>
	<?php if ( empty( $all_types ) ) : ?>
		<p style="padding:0.6rem 0.9rem; background:#f7f7f9; border-radius:5px; color:#666; font-style:italic;">
			<?php esc_html_e( 'Aucun autre type configuré.', 'cordespace-snippets' ); ?>
		</p>
	<?php else : ?>
		<div style="padding:0.3rem 0 0.8rem;">
			<?php foreach ( $all_types as $tid ) :
				$tid          = (int) $tid;
				$is_enabled   = in_array( $tid, $implied_types, true );
				$source_tags  = get_post_meta( $tid, CORDESPACE_EVENT_TYPE_META_TAGS, true );
				$source_tags  = is_array( $source_tags ) ? $source_tags : [];
				$selected_tags_for_source = isset( $tag_filters[ $tid ] ) && is_array( $tag_filters[ $tid ] )
					? $tag_filters[ $tid ]
					: [];
				?>
				<div style="margin:0.5rem 0; padding:0.6rem 0.8rem; background:<?php echo $is_enabled ? '#f1faf3' : '#f7f7f9'; ?>; border:1px solid <?php echo $is_enabled ? '#c1e4ca' : '#e0e0e0'; ?>; border-radius:5px;">
					<label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer; font-weight:600;">
						<input type="checkbox" name="cordespace_evtype_implied_from_types[]" value="<?php echo $tid; ?>" <?php checked( $is_enabled ); ?>>
						<span><?php echo esc_html( get_the_title( $tid ) ); ?></span>
					</label>
					<?php if ( ! empty( $source_tags ) ) : ?>
						<div style="margin:0.5rem 0 0 1.5rem; font-size:0.9em;">
							<p style="margin:0 0 0.3rem; color:#666;">
								<?php esc_html_e( "Filtre par tag (laisser tout vide = tous les tags du type comptent) :", 'cordespace-snippets' ); ?>
							</p>
							<?php foreach ( $source_tags as $stag ) :
								$tag_checked = in_array( $stag, $selected_tags_for_source, true );
								?>
								<label style="display:inline-flex; align-items:center; gap:0.3rem; margin-right:1rem;">
									<input type="checkbox" name="cordespace_evtype_implied_from_tag_filters[<?php echo $tid; ?>][]" value="<?php echo esc_attr( $stag ); ?>" <?php checked( $tag_checked ); ?>>
									<?php echo esc_html( $stag ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<h4 style="margin:1.2rem 0 0.5rem;">👥 <?php esc_html_e( 'Auto-validé·e pour les utilisateurs avec ce rôle', 'cordespace-snippets' ); ?></h4>
	<p class="description" style="font-size:0.9em; margin:0 0 0.6rem;">
		<?php esc_html_e( "Toute personne qui a ce rôle WordPress sera considérée comme validée pour ce type, sans avoir besoin d'être ajoutée à la matrice. Pratique pour « tous les profs ont accès » (rôle Amelia Provider).", 'cordespace-snippets' ); ?>
	</p>
	<select name="cordespace_evtype_implied_from_role" style="min-width:240px;">
		<option value=""><?php esc_html_e( '— Aucun rôle —', 'cordespace-snippets' ); ?></option>
		<?php foreach ( $roles as $slug => $label ) : ?>
			<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $implied_role ); ?>>
				<?php echo esc_html( $label ); ?> (<?php echo esc_html( $slug ); ?>)
			</option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * Helper : renvoie les types « sources » qui implementent CE type.
 * Validation cascade : être validé·e dans AU MOINS UN de ces types →
 * implique validation sur CE type.
 *
 * @return int[]
 */
function cordespace_event_gating_get_implied_from_types( int $type_id ): array {
	if ( $type_id <= 0 ) {
		return [];
	}
	$raw = get_post_meta( $type_id, CORDESPACE_EVENT_TYPE_META_IMPLIED_FROM_TYPES, true );
	if ( ! is_array( $raw ) ) {
		return [];
	}
	return array_values( array_filter( array_map( 'intval', $raw ) ) );
}

/**
 * Helper : renvoie les filtres par tag pour les types implementés.
 *
 * @return array<int, string[]> [type_id => [tag1, tag2]] où chaque entry
 *   indique quels tags du type source comptent. Si un type est dans
 *   `get_implied_from_types` mais PAS dans cette map, ça veut dire « tous
 *   les tags du source comptent » (= comportement par défaut).
 */
function cordespace_event_gating_get_implied_from_tag_filters( int $type_id ): array {
	if ( $type_id <= 0 ) {
		return [];
	}
	$raw = get_post_meta( $type_id, CORDESPACE_EVENT_TYPE_META_IMPLIED_FROM_TAG_MAP, true );
	if ( ! is_array( $raw ) ) {
		return [];
	}
	$out = [];
	foreach ( $raw as $tid => $tags ) {
		if ( is_array( $tags ) && ! empty( $tags ) ) {
			$out[ (int) $tid ] = array_values( array_filter( array_map( 'strval', $tags ) ) );
		}
	}
	return $out;
}

/**
 * Helper : renvoie le rôle WP qui auto-valide pour ce type.
 * Chaîne vide si pas configuré.
 */
function cordespace_event_gating_get_implied_from_role( int $type_id ): string {
	if ( $type_id <= 0 ) {
		return '';
	}
	return (string) get_post_meta( $type_id, CORDESPACE_EVENT_TYPE_META_IMPLIED_FROM_ROLE, true );
}

// ============================================================================
// 5) Sauvegarde des metas (commune à toutes les metaboxes)
// ============================================================================

function cordespace_event_gating_save_meta( int $post_id ): void {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( get_post_type( $post_id ) !== CORDESPACE_EVENT_TYPE_POST_TYPE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['cordespace_event_gating_nonce'] )
	     || ! wp_verify_nonce(
	           sanitize_text_field( wp_unslash( $_POST['cordespace_event_gating_nonce'] ) ),
	           'cordespace_event_gating_meta_save'
	     ) ) {
		return;
	}

	// Tags : étiquettes Amelia cochées + l'étiquette spéciale « Réservation des
	// salles » si activée (hardcodée, pas d'input libre).
	$amelia_tags = isset( $_POST['cordespace_event_type_tags'] ) && is_array( $_POST['cordespace_event_type_tags'] )
		? array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['cordespace_event_type_tags'] ) ) ) )
		: [];
	$salles_enabled = ! empty( $_POST['cordespace_evtype_enable_salles'] );
	$tags = $amelia_tags;
	if ( $salles_enabled && ! in_array( CORDESPACE_EVENT_TYPE_TAG_SALLES, $tags, true ) ) {
		$tags[] = CORDESPACE_EVENT_TYPE_TAG_SALLES;
	}
	$tags = array_values( array_unique( $tags ) );
	update_post_meta( $post_id, CORDESPACE_EVENT_TYPE_META_TAGS, $tags );

	// META_APPT_TAGS : si « Réservation des salles » est activée, c'est la
	// seule étiquette appt. Sinon vide. (Le concept "1 tag déclenche les salles"
	// reste sur cette étiquette spéciale uniquement, par design.)
	$appt_tags = $salles_enabled ? [ CORDESPACE_EVENT_TYPE_TAG_SALLES ] : [];
	update_post_meta( $post_id, CORDESPACE_EVENT_TYPE_META_APPT_TAGS, $appt_tags );

	// URL : esc_url_raw
	$url = isset( $_POST['cordespace_event_type_info_url'] )
		? esc_url_raw( wp_unslash( $_POST['cordespace_event_type_info_url'] ) )
		: '';
	update_post_meta( $post_id, CORDESPACE_EVENT_TYPE_META_INFO_URL, $url );

	// Implications entre tags : array<tag_parent, tag_enfant[]>. On filtre
	// pour ne garder que les paires où les 2 tags sont dans la liste sauvée.
	$raw_implications = isset( $_POST['cordespace_tag_implications'] ) && is_array( $_POST['cordespace_tag_implications'] )
		? (array) wp_unslash( $_POST['cordespace_tag_implications'] )
		: [];
	$clean_implications = [];
	foreach ( $raw_implications as $parent => $children ) {
		$parent = sanitize_text_field( (string) $parent );
		if ( ! in_array( $parent, $tags, true ) ) {
			continue;
		}
		if ( ! is_array( $children ) ) {
			continue;
		}
		$filtered = [];
		foreach ( $children as $child ) {
			$child = sanitize_text_field( (string) $child );
			if ( $child !== '' && $child !== $parent && in_array( $child, $tags, true ) ) {
				$filtered[] = $child;
			}
		}
		if ( ! empty( $filtered ) ) {
			$clean_implications[ $parent ] = array_values( array_unique( $filtered ) );
		}
	}
	update_post_meta( $post_id, CORDESPACE_EVENT_TYPE_META_TAG_IMPLICATIONS, $clean_implications );

	// META_APPLIES_APPT (ancien toggle global) : on NE TOUCHE PAS à cette
	// valeur depuis le save (la metabox UI a été retirée). Les anciens types
	// avec applies_appt='1' continuent de fonctionner en rétro-compat via
	// applicable_types_for_amelia_appointment(). Les nouveaux types utilisent
	// l'étiquette « Réservation des salles » à la place.

	// Implied from types : array d'IDs de types (auto-validation cross-type).
	// Filtre : pas le post lui-même + posts qui existent.
	$implied_types = isset( $_POST['cordespace_evtype_implied_from_types'] ) && is_array( $_POST['cordespace_evtype_implied_from_types'] )
		? array_values( array_unique( array_filter( array_map( 'intval', wp_unslash( $_POST['cordespace_evtype_implied_from_types'] ) ) ) ) )
		: [];
	$implied_types = array_values( array_diff( $implied_types, [ $post_id ] ) );
	update_post_meta( $post_id, CORDESPACE_EVENT_TYPE_META_IMPLIED_FROM_TYPES, $implied_types );

	// Filtres par tag pour chaque type implié : [type_id => [tag1, tag2]]
	// (vide ou absent = "tous les tags du type source comptent")
	$raw_filters    = isset( $_POST['cordespace_evtype_implied_from_tag_filters'] ) && is_array( $_POST['cordespace_evtype_implied_from_tag_filters'] )
		? (array) wp_unslash( $_POST['cordespace_evtype_implied_from_tag_filters'] )
		: [];
	$clean_filters = [];
	foreach ( $raw_filters as $tid => $tags ) {
		$tid = (int) $tid;
		// Ne garder que les filtres pour les types cochés
		if ( ! in_array( $tid, $implied_types, true ) ) {
			continue;
		}
		if ( ! is_array( $tags ) ) {
			continue;
		}
		// Filtrer pour ne garder que les tags qui existent réellement sur le type source
		$source_tags = get_post_meta( $tid, CORDESPACE_EVENT_TYPE_META_TAGS, true );
		$source_tags = is_array( $source_tags ) ? $source_tags : [];
		$valid_tags  = array_values( array_intersect(
			array_map( 'sanitize_text_field', $tags ),
			$source_tags
		) );
		if ( ! empty( $valid_tags ) ) {
			$clean_filters[ $tid ] = $valid_tags;
		}
	}
	update_post_meta( $post_id, CORDESPACE_EVENT_TYPE_META_IMPLIED_FROM_TAG_MAP, $clean_filters );

	// Implied from role : slug WP (vide = pas de rôle)
	$implied_role = isset( $_POST['cordespace_evtype_implied_from_role'] )
		? sanitize_key( wp_unslash( $_POST['cordespace_evtype_implied_from_role'] ) )
		: '';
	update_post_meta( $post_id, CORDESPACE_EVENT_TYPE_META_IMPLIED_FROM_ROLE, $implied_role );
}
add_action( 'save_post_' . CORDESPACE_EVENT_TYPE_POST_TYPE, 'cordespace_event_gating_save_meta' );

// ============================================================================
// 6) Helpers publics — utilisés par les futurs modules (members, checkout)
// ============================================================================

/**
 * Renvoie les tags Amelia configurés pour un type donné.
 *
 * @return string[]
 */
function cordespace_event_gating_get_tags( int $event_type_id ): array {
	$tags = get_post_meta( $event_type_id, CORDESPACE_EVENT_TYPE_META_TAGS, true );
	return is_array( $tags ) ? array_values( $tags ) : [];
}

/**
 * Renvoie l'URL d'info pour un type donné.
 */
function cordespace_event_gating_get_info_url( int $event_type_id ): string {
	return (string) get_post_meta( $event_type_id, CORDESPACE_EVENT_TYPE_META_INFO_URL, true );
}

/**
 * Renvoie les types d'events applicables à un event Amelia donné (= types
 * dont les tags configurés matchent au moins UN tag de l'event Amelia).
 *
 * Utilisé par le module de gating au checkout pour décider si une personne
 * a besoin d'être approuvée pour réserver cet event.
 *
 * @return int[] Liste des post IDs de cordespace_event_type applicables.
 */
function cordespace_event_gating_applicable_types_for_amelia_event( int $amelia_event_id ): array {
	if ( $amelia_event_id <= 0 ) {
		return [];
	}

	// 1. Tags de l'event Amelia (via le helper extrait)
	$event_tags = cordespace_event_gating_get_amelia_event_tags( $amelia_event_id );
	if ( empty( $event_tags ) ) {
		return [];
	}

	// 2. Tous les event types publiés
	$type_ids = get_posts( [
		'post_type'      => CORDESPACE_EVENT_TYPE_POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	// 3. Filtre ceux qui ont au moins un tag en commun
	$matching = [];
	foreach ( $type_ids as $type_id ) {
		$type_tags = cordespace_event_gating_get_tags( (int) $type_id );
		if ( ! empty( array_intersect( $event_tags, $type_tags ) ) ) {
			$matching[] = (int) $type_id;
		}
	}
	return $matching;
}

/**
 * Renvoie les tags Amelia configurés pour un event Amelia donné.
 *
 * Helper bas niveau extrait de applicable_types_for_amelia_event(), exposé
 * pour que le module checkout-blocker puisse calculer le check par tag
 * commun (event ∩ type).
 *
 * @return string[]
 */
function cordespace_event_gating_get_amelia_event_tags( int $amelia_event_id ): array {
	if ( $amelia_event_id <= 0 ) {
		return [];
	}
	global $wpdb;
	$tags = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT name FROM {$wpdb->prefix}amelia_events_tags WHERE eventId = %d",
		$amelia_event_id
	) );
	return array_values( array_map( 'strval', array_filter( (array) ( $tags ?: [] ) ) ) );
}

/**
 * Renvoie les types d'events applicables à un appointment Amelia (= salles).
 *
 * Un type est applicable aux appointments si :
 *   - Il a AU MOINS UNE étiquette dans META_APPT_TAGS (modèle par-tag, granulaire)
 *   - OU META_APPLIES_APPT = '1' (rétro-compat ancien toggle global)
 *
 * @return int[] Liste des post IDs de cordespace_event_type applicables.
 */
function cordespace_event_gating_applicable_types_for_amelia_appointment(): array {
	$all = get_posts( [
		'post_type'      => CORDESPACE_EVENT_TYPE_POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	$matching = [];
	foreach ( (array) $all as $tid ) {
		$tid = (int) $tid;
		$appt_tags = get_post_meta( $tid, CORDESPACE_EVENT_TYPE_META_APPT_TAGS, true );
		if ( is_array( $appt_tags ) && ! empty( $appt_tags ) ) {
			$matching[] = $tid;
			continue;
		}
		$applies = (string) get_post_meta( $tid, CORDESPACE_EVENT_TYPE_META_APPLIES_APPT, true );
		if ( $applies === '1' ) {
			$matching[] = $tid;
		}
	}
	return $matching;
}

/**
 * Helper : renvoie les étiquettes du type marquées « s'applique aux
 * réservations de salle ».
 *
 * @return string[]
 */
function cordespace_event_gating_get_appt_tags( int $type_id ): array {
	if ( $type_id <= 0 ) {
		return [];
	}
	$tags = get_post_meta( $type_id, CORDESPACE_EVENT_TYPE_META_APPT_TAGS, true );
	if ( ! is_array( $tags ) ) {
		return [];
	}
	return array_values( array_filter( array_map( 'strval', $tags ) ) );
}

