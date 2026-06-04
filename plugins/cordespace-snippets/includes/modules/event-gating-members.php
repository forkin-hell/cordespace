<?php
/**
 * Module: event-gating-members
 *
 * Phase 2 du gating events : metabox « Membres et statuts » sur l'édition
 * d'un type d'événement (CPT cordespace_evtype).
 *
 * Permet à l'admin :
 *   - de rechercher un·e user WordPress avec autocomplete (Select2 + AJAX)
 *   - de l'ajouter à la liste d'approbation avec un statut (validé·e /
 *     en attente / refusé·e) et des notes optionnelles
 *   - de modifier le statut ou les notes d'un membre existant
 *   - de retirer un membre de la liste
 *
 * Stockage : table custom `wp_cordespace_event_type_approvals` (créée par
 * event-gating-schema).
 *
 * Sécurité :
 *   - Toutes les actions AJAX sont protégées par nonce
 *   - Capability check : current_user_can('edit_post', $event_type_id)
 *   - Inputs sanitizés (sanitize_text_field, sanitize_textarea_field,
 *     valeur statut validée contre une whitelist)
 *
 * Pas d'impact côté client (cart/checkout) — c'est Phase 3 qui ajoutera
 * le bandeau de blocage qui consultera cette liste.
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// 1) Constantes statuts
// ============================================================================

const CORDESPACE_EVTYPE_STATUS_PENDING  = 'pending';
const CORDESPACE_EVTYPE_STATUS_APPROVED = 'approved';
const CORDESPACE_EVTYPE_STATUS_REJECTED = 'rejected';

/**
 * Labels affichables (FR) pour chaque statut.
 */
function cordespace_evgating_status_label( string $status ): string {
	switch ( $status ) {
		case CORDESPACE_EVTYPE_STATUS_APPROVED: return __( 'Validé·e', 'cordespace-snippets' );
		case CORDESPACE_EVTYPE_STATUS_REJECTED: return __( 'Refusé·e', 'cordespace-snippets' );
		default:                                return __( 'En attente', 'cordespace-snippets' );
	}
}

/**
 * Icône (emoji) pour chaque statut.
 */
function cordespace_evgating_status_icon( string $status ): string {
	switch ( $status ) {
		case CORDESPACE_EVTYPE_STATUS_APPROVED: return '✅';
		case CORDESPACE_EVTYPE_STATUS_REJECTED: return '❌';
		default:                                return '⏳';
	}
}

/**
 * Liste des 3 statuts valides — utilisée pour validation des inputs et
 * pour générer les <option> du select.
 */
function cordespace_evgating_valid_statuses(): array {
	return [
		CORDESPACE_EVTYPE_STATUS_APPROVED,
		CORDESPACE_EVTYPE_STATUS_PENDING,
		CORDESPACE_EVTYPE_STATUS_REJECTED,
	];
}

// ============================================================================
// 2) DB helpers (CRUD sur la table wp_cordespace_event_type_approvals)
// ============================================================================

/**
 * Récupère tous les membres d'un type donné, joint avec leurs infos WP user,
 * trié : approuvé·es d'abord, puis en attente, puis refusé·es, puis par nom.
 *
 * Modèle v2 (matrice membre × tag) : une row par triple (type, user, tag).
 *
 * @return array<int, array{
 *     user_id: int, display_name: string, email: string,
 *     notes: string, approved_at: ?string,
 *     statuses: array<string, string>,    // tag => 'pending'|'approved'|'rejected'
 *                                         // Pour types sans tags, statuses = ['' => '<status>']
 * }>
 */
function cordespace_evgating_get_members( int $event_type_id ): array {
	if ( $event_type_id <= 0 ) {
		return [];
	}
	global $wpdb;
	$table = cordespace_event_gating_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT a.user_id, a.tag, a.status, a.notes, a.approved_at,
		        u.display_name, u.user_email AS email
		   FROM {$table} a
		   JOIN {$wpdb->users} u ON u.ID = a.user_id
		  WHERE a.event_type_id = %d
		  ORDER BY u.display_name ASC, a.tag ASC",
		$event_type_id
	), ARRAY_A );

	$members = [];
	foreach ( (array) $rows as $row ) {
		$uid = (int) $row['user_id'];
		if ( ! isset( $members[ $uid ] ) ) {
			$members[ $uid ] = [
				'user_id'      => $uid,
				'display_name' => (string) $row['display_name'],
				'email'        => (string) $row['email'],
				'notes'        => (string) ( $row['notes'] ?? '' ),
				'approved_at'  => $row['approved_at'] ?? null,
				'statuses'     => [],
			];
		}
		$members[ $uid ]['statuses'][ (string) $row['tag'] ] = (string) $row['status'];

		// Garde la note la plus longue (toutes les rows du même user devraient
		// être identiques, mais on est défensif au cas où une migration aurait
		// laissé des incohérences).
		$row_notes = (string) ( $row['notes'] ?? '' );
		if ( $row_notes !== '' && strlen( $row_notes ) > strlen( (string) $members[ $uid ]['notes'] ) ) {
			$members[ $uid ]['notes'] = $row_notes;
		}
	}

	// Champs dérivés rétro-compat pour l'UI binaire actuelle (Task 4 refondra
	// l'UI mais en attendant, render_member_row attend $m['status']) :
	//   - status   : si le membre a la clé '' (= type sans tags), c'est cette
	//                valeur. Sinon, agrégation : approved si au moins un tag
	//                approved, sinon rejected si au moins un rejected, sinon
	//                pending.
	foreach ( $members as &$m ) {
		if ( array_key_exists( '', $m['statuses'] ) ) {
			$m['status'] = (string) $m['statuses'][''];
		} else {
			$vals = array_values( $m['statuses'] );
			if ( in_array( CORDESPACE_EVTYPE_STATUS_APPROVED, $vals, true ) ) {
				$m['status'] = CORDESPACE_EVTYPE_STATUS_APPROVED;
			} elseif ( in_array( CORDESPACE_EVTYPE_STATUS_REJECTED, $vals, true ) ) {
				$m['status'] = CORDESPACE_EVTYPE_STATUS_REJECTED;
			} else {
				$m['status'] = CORDESPACE_EVTYPE_STATUS_PENDING;
			}
		}
	}
	unset( $m );

	return array_values( $members );
}

/**
 * Compte les membres par statut agrégé pour un type.
 *
 * Logique d'agrégation par membre :
 *   - Au moins un tag 'approved' → comptabilisé comme 'approved'
 *   - Sinon, au moins un tag 'rejected' → 'rejected'
 *   - Sinon → 'pending'
 *
 * @return array{approved:int, pending:int, rejected:int}
 */
function cordespace_evgating_count_by_status( int $event_type_id ): array {
	$members = cordespace_evgating_get_members( $event_type_id );
	$out     = [ 'approved' => 0, 'pending' => 0, 'rejected' => 0 ];
	foreach ( $members as $m ) {
		$statuses = array_values( (array) $m['statuses'] );
		if ( in_array( CORDESPACE_EVTYPE_STATUS_APPROVED, $statuses, true ) ) {
			$out['approved']++;
		} elseif ( in_array( CORDESPACE_EVTYPE_STATUS_REJECTED, $statuses, true ) ) {
			$out['rejected']++;
		} else {
			$out['pending']++;
		}
	}
	return $out;
}

/**
 * Ajoute un membre à un type d'événement.
 *
 * Si le type a des tags configurés : insère une row par tag avec le status
 * passé (typiquement 'pending'). Si le type n'a pas de tags : insère une
 * unique row avec tag = ''.
 *
 * Skip silencieusement les rows déjà existantes pour le couple (type, user, tag).
 *
 * @return int Nombre de rows insérées (0 si déjà membre sur tous les tags).
 */
function cordespace_evgating_add_member( int $event_type_id, int $user_id, string $status, string $notes, int $by_user_id ): int {
	global $wpdb;
	if ( $event_type_id <= 0 || $user_id <= 0 ) {
		return 0;
	}
	if ( ! in_array( $status, cordespace_evgating_valid_statuses(), true ) ) {
		$status = CORDESPACE_EVTYPE_STATUS_PENDING;
	}

	// Récupère les tags configurés sur le type (via le helper exposé par
	// le module CPT — si pas dispo, on tombe en mode binaire).
	$tags = function_exists( 'cordespace_event_gating_get_tags' )
		? cordespace_event_gating_get_tags( $event_type_id )
		: [];
	$tags_to_insert = empty( $tags ) ? [ '' ] : $tags;

	$table = cordespace_event_gating_table_name();
	$now   = current_time( 'mysql', true );

	$inserted = 0;
	foreach ( $tags_to_insert as $tag ) {
		// Skip si la row existe déjà pour ce couple
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE event_type_id = %d AND user_id = %d AND tag = %s",
			$event_type_id, $user_id, (string) $tag
		) );
		if ( $existing ) {
			continue;
		}

		$result = $wpdb->insert(
			$table,
			[
				'event_type_id' => $event_type_id,
				'user_id'       => $user_id,
				'tag'           => (string) $tag,
				'status'        => $status,
				'notes'         => $notes,
				'approved_at'   => ( $status === CORDESPACE_EVTYPE_STATUS_APPROVED ) ? $now : null,
				'approved_by'   => ( $status === CORDESPACE_EVTYPE_STATUS_APPROVED ) ? $by_user_id : null,
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		if ( $result ) {
			$inserted++;
		}
	}

	return $inserted;
}

/**
 * Met à jour le statut d'UNE cellule (membre × tag) précise.
 * Utilisé par l'UI matricielle quand on change un dropdown.
 *
 * Pour un type sans tags, passer $tag = ''.
 *
 * Si la row (type, user, tag) n'existe pas encore, on l'insère.
 *
 * CASCADE : si $status === 'approved' et que le type a des implications
 * configurées pour ce tag, propage l'approbation aux tags enfants
 * automatiquement (asymétrique : pas de cascade sur pending/rejected).
 * Protection contre les cycles via $visited.
 *
 * @param string[] $visited Tags déjà traités dans la chaîne de cascade (interne).
 * @return bool true si succès (insert ou update).
 */
function cordespace_evgating_set_tag_status( int $event_type_id, int $user_id, string $tag, string $status, int $by_user_id, array $visited = [] ): bool {
	if ( $event_type_id <= 0 || $user_id <= 0 ) {
		return false;
	}
	if ( ! in_array( $status, cordespace_evgating_valid_statuses(), true ) ) {
		return false;
	}

	global $wpdb;
	$table = cordespace_event_gating_table_name();
	$now   = current_time( 'mysql', true );

	$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE event_type_id = %d AND user_id = %d AND tag = %s",
		$event_type_id, $user_id, $tag
	) );

	$is_approved = ( $status === CORDESPACE_EVTYPE_STATUS_APPROVED );

	if ( $existing_id > 0 ) {
		$result = $wpdb->update(
			$table,
			[
				'status'      => $status,
				'approved_at' => $is_approved ? $now : null,
				'approved_by' => $is_approved ? $by_user_id : null,
				'updated_at'  => $now,
			],
			[ 'id' => $existing_id ],
			[ '%s', '%s', '%d', '%s' ],
			[ '%d' ]
		);
		$ok = ( $result !== false );
	} else {
		$result = $wpdb->insert(
			$table,
			[
				'event_type_id' => $event_type_id,
				'user_id'       => $user_id,
				'tag'           => $tag,
				'status'        => $status,
				'notes'         => '',
				'approved_at'   => $is_approved ? $now : null,
				'approved_by'   => $is_approved ? $by_user_id : null,
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		$ok = ( $result !== false );
	}

	// Cascade des implications (uniquement sur 'approved')
	if ( $ok && $is_approved && function_exists( 'cordespace_event_gating_get_tag_implications' ) ) {
		$visited[] = $tag;
		$implications = cordespace_event_gating_get_tag_implications( $event_type_id );
		$implied      = isset( $implications[ $tag ] ) && is_array( $implications[ $tag ] )
			? $implications[ $tag ]
			: [];
		foreach ( $implied as $child_tag ) {
			$child_tag = (string) $child_tag;
			if ( $child_tag === '' || in_array( $child_tag, $visited, true ) ) {
				continue; // cycle protection
			}
			cordespace_evgating_set_tag_status( $event_type_id, $user_id, $child_tag, CORDESPACE_EVTYPE_STATUS_APPROVED, $by_user_id, $visited );
		}
	}

	return $ok;
}

/**
 * Met à jour la note partagée d'un membre pour un type.
 * Update toutes les rows (type, user, *) en une fois.
 *
 * @return bool true si succès (même si 0 rows touchées).
 */
function cordespace_evgating_set_member_note( int $event_type_id, int $user_id, string $notes ): bool {
	if ( $event_type_id <= 0 || $user_id <= 0 ) {
		return false;
	}
	global $wpdb;
	$table  = cordespace_event_gating_table_name();
	$result = $wpdb->update(
		$table,
		[
			'notes'      => $notes,
			'updated_at' => current_time( 'mysql', true ),
		],
		[ 'event_type_id' => $event_type_id, 'user_id' => $user_id ],
		[ '%s', '%s' ],
		[ '%d', '%d' ]
	);
	return $result !== false;
}

/**
 * Met à jour le statut et/ou les notes d'un membre pour TOUS ses tags.
 * Utile pour le mode binaire (type sans tags). En mode matrice, préférer
 * cordespace_evgating_set_tag_status() pour cibler une cellule précise et
 * cordespace_evgating_set_member_note() pour la note partagée.
 *
 * Si $status est null, ne touche pas au statut. Idem pour $notes.
 */
function cordespace_evgating_update_member( int $event_type_id, int $user_id, ?string $status, ?string $notes, int $by_user_id ): bool {
	if ( $event_type_id <= 0 || $user_id <= 0 ) {
		return false;
	}

	$ok = true;

	if ( $status !== null ) {
		if ( ! in_array( $status, cordespace_evgating_valid_statuses(), true ) ) {
			return false;
		}
		global $wpdb;
		$table = cordespace_event_gating_table_name();
		$tags  = $wpdb->get_col( $wpdb->prepare(
			"SELECT tag FROM {$table} WHERE event_type_id = %d AND user_id = %d",
			$event_type_id, $user_id
		) );
		if ( empty( $tags ) ) {
			// Pas encore membre : on l'ajoute (créera 1 row par tag du type, ou tag='')
			cordespace_evgating_add_member( $event_type_id, $user_id, $status, $notes ?? '', $by_user_id );
		} else {
			foreach ( $tags as $tag ) {
				if ( ! cordespace_evgating_set_tag_status( $event_type_id, $user_id, (string) $tag, $status, $by_user_id ) ) {
					$ok = false;
				}
			}
		}
	}

	if ( $notes !== null ) {
		if ( ! cordespace_evgating_set_member_note( $event_type_id, $user_id, $notes ) ) {
			$ok = false;
		}
	}

	return $ok;
}

/**
 * Retire un membre du type : DELETE toutes ses rows (un par tag).
 */
function cordespace_evgating_remove_member( int $event_type_id, int $user_id ): bool {
	global $wpdb;
	$table = cordespace_event_gating_table_name();
	return false !== $wpdb->delete(
		$table,
		[ 'event_type_id' => $event_type_id, 'user_id' => $user_id ],
		[ '%d', '%d' ]
	);
}

// ============================================================================
// 3) Metabox registration + render
// ============================================================================

function cordespace_evgating_add_members_metabox(): void {
	add_meta_box(
		'cordespace-evtype-members',
		__( '👥 Membres et statuts', 'cordespace-snippets' ),
		'cordespace_evgating_render_members_metabox',
		CORDESPACE_EVENT_TYPE_POST_TYPE,
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes_' . CORDESPACE_EVENT_TYPE_POST_TYPE, 'cordespace_evgating_add_members_metabox' );

/**
 * Enqueue Select2 (bundlé par WooCommerce sous le handle 'selectWoo').
 * Si WC pas dispo, fallback sur un <select> natif (sans autocomplete).
 */
function cordespace_evgating_enqueue_assets( string $hook ): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || $screen->post_type !== CORDESPACE_EVENT_TYPE_POST_TYPE ) {
		return;
	}
	if ( wp_script_is( 'selectWoo', 'registered' ) ) {
		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_style( 'select2' );
	} elseif ( wp_script_is( 'select2', 'registered' ) ) {
		wp_enqueue_script( 'select2' );
		wp_enqueue_style( 'select2' );
	}
}
add_action( 'admin_enqueue_scripts', 'cordespace_evgating_enqueue_assets' );

/**
 * Router : selon les tags configurés sur le type, on affiche soit l'UI
 * matricielle (= tableau avec 1 colonne par tag), soit l'UI binaire
 * historique (= liste simple avec dropdown global de statut).
 *
 *   - Type avec ≥ 1 tag : mode matrice (granularité fine par tag)
 *   - Type sans tag (cas « Réservation des salles ») : mode binaire
 */
function cordespace_evgating_render_members_metabox( WP_Post $post ): void {
	$tags = function_exists( 'cordespace_event_gating_get_tags' )
		? cordespace_event_gating_get_tags( (int) $post->ID )
		: [];

	if ( empty( $tags ) ) {
		cordespace_evgating_render_members_metabox_binary( $post );
	} else {
		cordespace_evgating_render_members_metabox_matrix( $post, $tags );
	}
}

/**
 * Mode binaire (UI historique inchangée).
 * Utilisé pour les types sans tags configurés (cas « Réservation des salles »).
 */
function cordespace_evgating_render_members_metabox_binary( WP_Post $post ): void {
	$event_type_id = (int) $post->ID;
	$members       = cordespace_evgating_get_members( $event_type_id );
	$counts        = cordespace_evgating_count_by_status( $event_type_id );
	$nonce         = wp_create_nonce( 'cordespace_evgating_ajax_' . $event_type_id );
	$ajax_url      = admin_url( 'admin-ajax.php' );
	?>
	<div class="cordespace-evgating-members"
	     data-event-type-id="<?php echo (int) $event_type_id; ?>"
	     data-nonce="<?php echo esc_attr( $nonce ); ?>"
	     data-ajax-url="<?php echo esc_attr( $ajax_url ); ?>">

		<!-- Compteurs en haut -->
		<p class="cordespace-evgating-counts" style="margin:0 0 1rem; font-size:0.95em; color:#444;">
			<span style="margin-right:0.8rem;">✅ <strong><?php echo (int) $counts['approved']; ?></strong> validé·es</span>
			<span style="margin-right:0.8rem;">⏳ <strong><?php echo (int) $counts['pending']; ?></strong> en attente</span>
			<span>❌ <strong><?php echo (int) $counts['rejected']; ?></strong> refusé·es</span>
		</p>

		<!-- Formulaire d'ajout -->
		<div class="cordespace-evgating-add" style="padding:1rem 1.2rem; background:#f7f7f9; border-radius:6px; margin-bottom:1.2rem;">
			<p style="margin-top:0; font-weight:600;">➕ <?php esc_html_e( 'Ajouter une personne', 'cordespace-snippets' ); ?></p>
			<div style="display:flex; flex-wrap:wrap; gap:0.6rem; align-items:flex-end;">
				<div style="flex:1; min-width:260px;">
					<label for="cordespace-evgating-user-select" style="display:block; font-size:0.9em; color:#555; margin-bottom:0.3rem;">
						<?php esc_html_e( 'Rechercher (nom, courriel ou login)', 'cordespace-snippets' ); ?>
					</label>
					<select class="cordespace-evgating-user-select" id="cordespace-evgating-user-select" style="width:100%;">
						<option value=""></option>
					</select>
				</div>
				<div>
					<label for="cordespace-evgating-add-status" style="display:block; font-size:0.9em; color:#555; margin-bottom:0.3rem;">
						<?php esc_html_e( 'Statut', 'cordespace-snippets' ); ?>
					</label>
					<select id="cordespace-evgating-add-status">
						<?php foreach ( cordespace_evgating_valid_statuses() as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $s, CORDESPACE_EVTYPE_STATUS_APPROVED ); ?>>
								<?php echo esc_html( cordespace_evgating_status_icon( $s ) . ' ' . cordespace_evgating_status_label( $s ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<button type="button" class="button button-primary cordespace-evgating-add-btn">
						<?php esc_html_e( 'Ajouter', 'cordespace-snippets' ); ?>
					</button>
				</div>
			</div>
			<details style="margin-top:0.6rem;">
				<summary style="cursor:pointer; font-size:0.9em; color:#666;"><?php esc_html_e( 'Ajouter une note (optionnel)', 'cordespace-snippets' ); ?></summary>
				<textarea class="cordespace-evgating-add-notes" rows="2" style="width:100%; margin-top:0.4rem;" placeholder="<?php esc_attr_e( 'Ex : validé·e après entretien, à recontacter en juin, etc.', 'cordespace-snippets' ); ?>"></textarea>
			</details>
			<p class="cordespace-evgating-add-status-msg" style="margin:0.6rem 0 0; font-size:0.92em;"></p>
		</div>

		<!-- Liste des membres existants -->
		<div class="cordespace-evgating-list">
			<?php if ( empty( $members ) ) : ?>
				<p style="padding:1rem 1.2rem; background:#f7f7f9; border-radius:5px; color:#666; font-style:italic; margin:0;">
					<?php esc_html_e( 'Aucun membre ajouté pour le moment.', 'cordespace-snippets' ); ?>
				</p>
			<?php else : ?>
				<?php foreach ( $members as $m ) : ?>
					<?php cordespace_evgating_render_member_row( $m ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>

	<?php cordespace_evgating_render_import_and_pending_sections( $event_type_id, [] ); ?>

	<?php cordespace_evgating_render_member_template(); ?>
	<?php cordespace_evgating_print_inline_js(); ?>
	<?php cordespace_evgating_print_inline_css(); ?>
	<?php
}

/**
 * Helper partagé : rendu des 2 sections « Importer un CSV » + « En attente
 * d'inscription ». Utilisé par les 2 modes (matrice et binaire).
 *
 * En mode binaire, $tags est vide → pas de checkboxes de tags dans le form
 * d'import, et la table pending n'a qu'une colonne statut.
 */
function cordespace_evgating_render_import_and_pending_sections( int $event_type_id, array $tags ): void {
	?>
	<details class="cordespace-evgating-import-csv" style="margin-top:1.5rem; padding:0.8rem 1rem; background:#f7f7f9; border-radius:6px;">
		<summary style="cursor:pointer; font-weight:600; color:#333;">
			📥 <?php esc_html_e( 'Importer un CSV (bootstrap initial)', 'cordespace-snippets' ); ?>
		</summary>
		<div style="padding-top:0.8rem;">
			<p style="font-size:0.9em; color:#555; margin:0 0 0.8rem;">
				<?php esc_html_e( 'Format accepté : 1 colonne « Email » (header obligatoire). Le format Momence est détecté automatiquement (col Email en position 3).', 'cordespace-snippets' ); ?>
				<br>
				<strong><?php esc_html_e( 'Comportement :', 'cordespace-snippets' ); ?></strong>
				<?php esc_html_e( "si la personne a déjà un compte WP, elle est ajoutée directement à la liste. Sinon son email est mis « en attente » et la validation s'activera automatiquement à la création de son compte (1er achat ou inscription).", 'cordespace-snippets' ); ?>
			</p>
			<?php if ( ! empty( $tags ) ) : ?>
				<p style="margin:0.6rem 0 0.3rem; font-weight:600;"><?php esc_html_e( 'Tag(s) à valider pour chaque ligne du CSV :', 'cordespace-snippets' ); ?></p>
				<div style="display:flex; flex-wrap:wrap; gap:0.4rem 1rem; margin-bottom:0.6rem;">
					<?php foreach ( $tags as $tag ) : ?>
						<label style="display:flex; align-items:center; gap:0.3rem; font-size:0.95em;">
							<input type="checkbox" class="cordespace-evgating-import-tag" value="<?php echo esc_attr( $tag ); ?>">
							<?php echo esc_html( $tag ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div style="display:flex; flex-wrap:wrap; gap:0.6rem; align-items:flex-end; margin-bottom:0.4rem;">
				<div>
					<label style="display:block; font-size:0.9em; color:#555; margin-bottom:0.3rem;"><?php esc_html_e( 'Statut', 'cordespace-snippets' ); ?></label>
					<select class="cordespace-evgating-import-status">
						<?php foreach ( cordespace_evgating_valid_statuses() as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $s, CORDESPACE_EVTYPE_STATUS_APPROVED ); ?>>
								<?php echo esc_html( cordespace_evgating_status_icon( $s ) . ' ' . cordespace_evgating_status_label( $s ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div style="flex:1; min-width:240px;">
					<label style="display:block; font-size:0.9em; color:#555; margin-bottom:0.3rem;"><?php esc_html_e( 'Fichier CSV', 'cordespace-snippets' ); ?></label>
					<input type="file" class="cordespace-evgating-import-file" accept=".csv,text/csv">
				</div>
				<div>
					<button type="button" class="button button-primary cordespace-evgating-import-btn">
						<?php esc_html_e( 'Importer', 'cordespace-snippets' ); ?>
					</button>
				</div>
			</div>
			<p class="cordespace-evgating-import-result" style="margin:0.6rem 0 0; font-size:0.9em;"></p>
		</div>
	</details>

	<?php
	$pending_list  = function_exists( 'cordespace_evgating_pending_get_for_type' )
		? cordespace_evgating_pending_get_for_type( $event_type_id )
		: [];
	$pending_count = count( $pending_list );
	?>
	<details class="cordespace-evgating-pending-list" style="margin-top:1rem; padding:0.8rem 1rem; background:#fffcf0; border:1px solid #f0e0a0; border-radius:6px;">
		<summary style="cursor:pointer; font-weight:600; color:#5c4a00;">
			📋 <?php printf( esc_html__( 'En attente d\'inscription : %d email(s)', 'cordespace-snippets' ), (int) $pending_count ); ?>
			<small style="font-weight:normal; color:#7c5a00;">
				<?php esc_html_e( '(seront validé·es automatiquement à la création de leur compte)', 'cordespace-snippets' ); ?>
			</small>
		</summary>
		<?php if ( $pending_count === 0 ) : ?>
			<p style="padding:0.6rem 0 0; font-size:0.9em; color:#7c5a00; font-style:italic; margin:0;">
				<?php esc_html_e( 'Aucun email en attente pour ce type.', 'cordespace-snippets' ); ?>
			</p>
		<?php else : ?>
			<table class="widefat cordespace-evgating-pending-table" style="margin-top:0.6rem;">
				<thead>
					<tr>
						<th style="text-align:left; padding:0.4rem;"><?php esc_html_e( 'Email', 'cordespace-snippets' ); ?></th>
						<?php foreach ( $tags as $tag ) : ?>
							<th style="text-align:left; padding:0.4rem;"><?php echo esc_html( $tag ); ?></th>
						<?php endforeach; ?>
						<?php if ( empty( $tags ) ) : ?>
							<th style="text-align:left; padding:0.4rem;"><?php esc_html_e( 'Statut', 'cordespace-snippets' ); ?></th>
						<?php endif; ?>
						<th style="text-align:left; padding:0.4rem;"><?php esc_html_e( 'Ajout', 'cordespace-snippets' ); ?></th>
						<th style="text-align:left; padding:0.4rem;"><?php esc_html_e( 'Actions', 'cordespace-snippets' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pending_list as $p ) :
						$stats = (array) $p['statuses'];
						?>
						<tr class="cordespace-evgating-pending-row" data-email="<?php echo esc_attr( $p['email'] ); ?>">
							<td style="padding:0.4rem;"><?php echo esc_html( $p['email'] ); ?></td>
							<?php if ( ! empty( $tags ) ) : ?>
								<?php foreach ( $tags as $tag ) :
									$st = (string) ( $stats[ $tag ] ?? '' );
									?>
									<td style="padding:0.4rem; font-size:0.9em;">
										<?php echo $st === '' ? '—' : esc_html( cordespace_evgating_status_icon( $st ) ); ?>
									</td>
								<?php endforeach; ?>
							<?php else :
								$bin_status = (string) ( $stats[''] ?? CORDESPACE_EVTYPE_STATUS_APPROVED );
								?>
								<td style="padding:0.4rem; font-size:0.9em;">
									<?php echo esc_html( cordespace_evgating_status_icon( $bin_status ) . ' ' . cordespace_evgating_status_label( $bin_status ) ); ?>
								</td>
							<?php endif; ?>
							<td style="padding:0.4rem; font-size:0.85em; color:#666;">
								<?php echo esc_html( mysql2date( 'Y-m-d', (string) $p['created_at'] ) ); ?>
							</td>
							<td style="padding:0.4rem;">
								<button type="button" class="button button-small cordespace-evgating-pending-remove" title="<?php esc_attr_e( 'Retirer cet email de la liste d\'attente', 'cordespace-snippets' ); ?>" style="color:#a00;">✗</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</details>
	<?php
}

/**
 * Mode matrice : tableau avec une colonne par tag du type.
 * Chaque cellule est un dropdown de statut (pending / approved / rejected)
 * géré indépendamment via AJAX (endpoint set_tag_status).
 *
 * @param WP_Post $post Le type d'événement (CPT cordespace_evtype)
 * @param array   $tags Les tags Amelia configurés sur ce type
 */
function cordespace_evgating_render_members_metabox_matrix( WP_Post $post, array $tags ): void {
	$event_type_id = (int) $post->ID;
	$members       = cordespace_evgating_get_members( $event_type_id );
	$counts        = cordespace_evgating_count_by_status( $event_type_id );
	$nonce         = wp_create_nonce( 'cordespace_evgating_ajax_' . $event_type_id );
	$ajax_url      = admin_url( 'admin-ajax.php' );
	?>
	<div class="cordespace-evgating-members cordespace-evgating-matrix"
	     data-event-type-id="<?php echo $event_type_id; ?>"
	     data-nonce="<?php echo esc_attr( $nonce ); ?>"
	     data-ajax-url="<?php echo esc_attr( $ajax_url ); ?>">

		<!-- Compteurs en haut -->
		<p class="cordespace-evgating-counts" style="margin:0 0 0.6rem; font-size:0.95em; color:#444;">
			<span style="margin-right:0.8rem;">✅ <strong><?php echo (int) $counts['approved']; ?></strong> validé·es</span>
			<span style="margin-right:0.8rem;">⏳ <strong><?php echo (int) $counts['pending']; ?></strong> en attente</span>
			<span>❌ <strong><?php echo (int) $counts['rejected']; ?></strong> refusé·es</span>
		</p>

		<p class="cordespace-evgating-help" style="background:#fff8e1; border-left:3px solid #fbc02d; padding:0.5rem 0.8rem; margin:0.4rem 0 1rem; font-size:0.9em; color:#5c3d00;">
			ℹ️ <?php esc_html_e( "Logique OR par tag : une personne peut réserver un event si elle est validée sur AU MOINS UN tag commun entre l'event et ce type.", 'cordespace-snippets' ); ?>
		</p>

		<?php
		// Bouton "Appliquer la hiérarchie aux validations existantes" :
		// disponible uniquement si des implications sont configurées pour ce type.
		$implications = function_exists( 'cordespace_event_gating_get_tag_implications' )
			? cordespace_event_gating_get_tag_implications( $event_type_id )
			: [];
		if ( ! empty( $implications ) ) :
			?>
			<p style="margin:0 0 1rem;">
				<button type="button" class="button cordespace-evgating-apply-implications">
					🪜 <?php esc_html_e( "Appliquer la hiérarchie aux validations existantes", 'cordespace-snippets' ); ?>
				</button>
				<small style="display:block; margin-top:0.3rem; color:#666;">
					<?php esc_html_e( "Pour chaque membre déjà validé·e sur un tag « parent », valide aussi automatiquement les tags « enfants » configurés. Utile après import.", 'cordespace-snippets' ); ?>
				</small>
				<span class="cordespace-evgating-apply-implications-result" style="display:block; margin-top:0.4rem; font-size:0.9em;"></span>
			</p>
		<?php endif; ?>

		<!-- Formulaire d'ajout -->
		<div class="cordespace-evgating-add" style="padding:0.9rem 1.1rem; background:#f7f7f9; border-radius:6px; margin-bottom:1rem;">
			<p style="margin-top:0; font-weight:600;">➕ <?php esc_html_e( 'Ajouter une personne', 'cordespace-snippets' ); ?></p>
			<div style="display:flex; flex-wrap:wrap; gap:0.6rem; align-items:flex-end;">
				<div style="flex:1; min-width:260px;">
					<label for="cordespace-evgating-user-select" style="display:block; font-size:0.9em; color:#555; margin-bottom:0.3rem;">
						<?php esc_html_e( 'Rechercher (nom, courriel ou login)', 'cordespace-snippets' ); ?>
					</label>
					<select class="cordespace-evgating-user-select" id="cordespace-evgating-user-select" style="width:100%;">
						<option value=""></option>
					</select>
				</div>
				<div>
					<button type="button" class="button button-primary cordespace-evgating-add-btn">
						<?php esc_html_e( 'Ajouter (pending sur tous les tags)', 'cordespace-snippets' ); ?>
					</button>
				</div>
			</div>
			<p class="cordespace-evgating-add-status-msg" style="margin:0.6rem 0 0; font-size:0.92em;"></p>
		</div>

		<!-- Tableau matriciel -->
		<?php if ( empty( $members ) ) : ?>
			<p style="padding:1rem 1.2rem; background:#f7f7f9; border-radius:5px; color:#666; font-style:italic; margin:0;">
				<?php esc_html_e( 'Aucune personne associée à ce type pour le moment.', 'cordespace-snippets' ); ?>
			</p>
		<?php else : ?>
			<!-- Barre de filtres + recherche -->
			<div class="cordespace-evgating-matrix-filters" style="display:flex; flex-wrap:wrap; gap:0.6rem 1.2rem; align-items:flex-end; padding:0.8rem 1rem; background:#f0f6fc; border:1px solid #c5d9eb; border-radius:6px; margin-bottom:0.6rem;">
				<div style="flex:1; min-width:220px;">
					<label style="display:block; font-size:0.85em; color:#555; margin-bottom:0.2rem;">🔍 <?php esc_html_e( 'Recherche (nom ou email)', 'cordespace-snippets' ); ?></label>
					<input type="search" class="cordespace-evgating-search-input" placeholder="<?php esc_attr_e( 'Tape pour filtrer…', 'cordespace-snippets' ); ?>" style="width:100%; padding:0.4rem 0.6rem;">
				</div>
				<?php foreach ( $tags as $tag ) : ?>
					<div>
						<label style="display:block; font-size:0.85em; color:#555; margin-bottom:0.2rem;"><?php echo esc_html( $tag ); ?></label>
						<select class="cordespace-evgating-status-filter" data-tag="<?php echo esc_attr( $tag ); ?>">
							<option value=""><?php esc_html_e( 'Tous', 'cordespace-snippets' ); ?></option>
							<?php foreach ( cordespace_evgating_valid_statuses() as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>">
									<?php echo esc_html( cordespace_evgating_status_icon( $s ) . ' ' . cordespace_evgating_status_label( $s ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endforeach; ?>
				<div>
					<button type="button" class="button cordespace-evgating-filters-reset" title="<?php esc_attr_e( 'Réinitialiser les filtres', 'cordespace-snippets' ); ?>">
						✗ <?php esc_html_e( 'Réinit.', 'cordespace-snippets' ); ?>
					</button>
				</div>
				<div style="font-size:0.9em; color:#555;">
					<span class="cordespace-evgating-filter-count"><?php printf( esc_html__( '%d affiché·es', 'cordespace-snippets' ), count( $members ) ); ?></span>
				</div>
			</div>

			<table class="widefat cordespace-evgating-matrix-table" style="width:100%; border-collapse:collapse;">
				<thead>
					<tr>
						<th class="cordespace-evgating-sortable" data-sort="name" style="text-align:left; padding:0.5rem; cursor:pointer; user-select:none;">
							<?php esc_html_e( 'Personne', 'cordespace-snippets' ); ?> <span class="cordespace-evgating-sort-arrow"></span>
						</th>
						<?php foreach ( $tags as $tag ) : ?>
							<th class="cordespace-evgating-sortable" data-sort="tag:<?php echo esc_attr( $tag ); ?>" style="text-align:left; padding:0.5rem; cursor:pointer; user-select:none;">
								<small><?php esc_html_e( 'Tag', 'cordespace-snippets' ); ?></small><br>
								<?php echo esc_html( $tag ); ?> <span class="cordespace-evgating-sort-arrow"></span>
							</th>
						<?php endforeach; ?>
						<th style="text-align:left; padding:0.5rem;"><?php esc_html_e( 'Note', 'cordespace-snippets' ); ?></th>
						<th style="text-align:left; padding:0.5rem;"><?php esc_html_e( 'Actions', 'cordespace-snippets' ); ?></th>
					</tr>
				</thead>
				<tbody id="cordespace-evgating-matrix-body">
					<?php foreach ( $members as $m ) : ?>
						<?php cordespace_evgating_render_matrix_row( $m, $tags ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php cordespace_evgating_render_import_and_pending_sections( $event_type_id, $tags ); ?>
	</div>

	<?php cordespace_evgating_print_inline_js(); ?>
	<?php cordespace_evgating_print_inline_css(); ?>
	<?php
}

/**
 * Rendu d'une ligne matrice (1 row = 1 membre avec dropdowns pour chaque tag).
 */
function cordespace_evgating_render_matrix_row( array $m, array $tags ): void {
	$uid       = (int) $m['user_id'];
	$display   = $m['display_name'] !== '' ? $m['display_name'] : $m['email'];
	$stats     = (array) $m['statuses'];
	$search_str = strtolower( $display . ' ' . $m['email'] );
	?>
	<tr class="cordespace-evgating-matrix-row" data-user-id="<?php echo $uid; ?>" data-search="<?php echo esc_attr( $search_str ); ?>" data-sort-name="<?php echo esc_attr( strtolower( $display ) ); ?>">
		<td style="padding:0.5rem; vertical-align:top; border-bottom:1px solid #e0e0e0;">
			<strong><?php echo esc_html( $display ); ?></strong>
			<br><small style="color:#777;"><?php echo esc_html( $m['email'] ); ?></small>
		</td>
		<?php foreach ( $tags as $tag ) :
			$current = (string) ( $stats[ $tag ] ?? CORDESPACE_EVTYPE_STATUS_PENDING );
			?>
			<td style="padding:0.5rem; vertical-align:top; border-bottom:1px solid #e0e0e0;">
				<select class="cordespace-evgating-tag-status" data-tag="<?php echo esc_attr( $tag ); ?>" style="width:100%;">
					<?php foreach ( cordespace_evgating_valid_statuses() as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $current, $s ); ?>>
							<?php echo esc_html( cordespace_evgating_status_icon( $s ) . ' ' . cordespace_evgating_status_label( $s ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		<?php endforeach; ?>
		<td style="padding:0.5rem; vertical-align:top; border-bottom:1px solid #e0e0e0;">
			<textarea class="cordespace-evgating-note" rows="2" style="width:100%; min-width:160px; font-size:0.9em;"
				placeholder="<?php esc_attr_e( 'Note interne…', 'cordespace-snippets' ); ?>"><?php echo esc_textarea( (string) ( $m['notes'] ?? '' ) ); ?></textarea>
			<span class="cordespace-evgating-note-status" style="display:block; font-size:0.85em; color:#777; margin-top:0.2rem;"></span>
		</td>
		<td style="padding:0.5rem; vertical-align:top; border-bottom:1px solid #e0e0e0;">
			<button type="button" class="button button-small cordespace-evgating-remove-matrix" title="<?php esc_attr_e( 'Retirer', 'cordespace-snippets' ); ?>" style="color:#a00;">❌</button>
		</td>
	</tr>
	<?php
}

/**
 * Rendu d'une ligne membre (réutilisable pour le rendu initial + via JS).
 */
function cordespace_evgating_render_member_row( array $m ): void {
	?>
	<div class="cordespace-evgating-member"
	     data-user-id="<?php echo (int) $m['user_id']; ?>"
	     data-status="<?php echo esc_attr( $m['status'] ); ?>"
	     style="padding:0.8rem 1rem; background:#fff; border:1px solid #e0e0e0; border-radius:5px; margin-bottom:0.5rem; display:flex; align-items:center; gap:0.8rem; flex-wrap:wrap;">

		<div style="flex:1; min-width:200px;">
			<strong><?php echo esc_html( $m['display_name'] ); ?></strong>
			<br><span style="color:#666; font-size:0.85em;"><?php echo esc_html( $m['email'] ); ?></span>
		</div>

		<select class="cordespace-evgating-status-select" data-action="update-status">
			<?php foreach ( cordespace_evgating_valid_statuses() as $s ) : ?>
				<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $s, $m['status'] ); ?>>
					<?php echo esc_html( cordespace_evgating_status_icon( $s ) . ' ' . cordespace_evgating_status_label( $s ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<button type="button" class="button button-small cordespace-evgating-notes-toggle" title="<?php esc_attr_e( 'Note admin', 'cordespace-snippets' ); ?>">
			📝<?php if ( $m['notes'] !== '' ) : ?> <span style="color:#2c70b8;">●</span><?php endif; ?>
		</button>

		<button type="button" class="button button-small cordespace-evgating-remove" title="<?php esc_attr_e( 'Retirer', 'cordespace-snippets' ); ?>" style="color:#a00;">❌</button>

		<div class="cordespace-evgating-notes-edit" style="flex:1 1 100%; <?php echo $m['notes'] === '' ? 'display:none;' : ''; ?> margin-top:0.4rem;">
			<textarea rows="2" style="width:100%;" placeholder="<?php esc_attr_e( 'Note admin (visible seulement par l\'équipe)…', 'cordespace-snippets' ); ?>"><?php echo esc_textarea( $m['notes'] ); ?></textarea>
			<p style="margin:0.3rem 0 0;">
				<button type="button" class="button button-small cordespace-evgating-save-notes"><?php esc_html_e( 'Enregistrer la note', 'cordespace-snippets' ); ?></button>
				<span class="cordespace-evgating-notes-status" style="margin-left:0.6rem; font-size:0.9em; color:#666;"></span>
			</p>
		</div>
	</div>
	<?php
}

/**
 * Template HTML (caché) utilisé par le JS pour insérer une nouvelle ligne
 * après ajout AJAX. Placeholders {{KEY}} remplacés côté JS.
 */
function cordespace_evgating_render_member_template(): void {
	?>
	<script type="text/template" id="cordespace-evgating-member-template">
	<div class="cordespace-evgating-member" data-user-id="{{user_id}}" data-status="{{status}}"
	     style="padding:0.8rem 1rem; background:#fff; border:1px solid #e0e0e0; border-radius:5px; margin-bottom:0.5rem; display:flex; align-items:center; gap:0.8rem; flex-wrap:wrap;">
		<div style="flex:1; min-width:200px;">
			<strong>{{display_name}}</strong>
			<br><span style="color:#666; font-size:0.85em;">{{email}}</span>
		</div>
		<select class="cordespace-evgating-status-select" data-action="update-status">{{status_options}}</select>
		<button type="button" class="button button-small cordespace-evgating-notes-toggle" title="Note admin">📝{{notes_dot}}</button>
		<button type="button" class="button button-small cordespace-evgating-remove" title="Retirer" style="color:#a00;">❌</button>
		<div class="cordespace-evgating-notes-edit" style="flex:1 1 100%; {{notes_display}} margin-top:0.4rem;">
			<textarea rows="2" style="width:100%;" placeholder="Note admin…">{{notes}}</textarea>
			<p style="margin:0.3rem 0 0;">
				<button type="button" class="button button-small cordespace-evgating-save-notes">Enregistrer la note</button>
				<span class="cordespace-evgating-notes-status" style="margin-left:0.6rem; font-size:0.9em; color:#666;"></span>
			</p>
		</div>
	</div>
	</script>
	<?php
}

function cordespace_evgating_print_inline_css(): void {
	?>
	<style>
		.cordespace-evgating-members .select2-container { min-height: 28px; }
		.cordespace-evgating-add-status-msg.error  { color: #a00; }
		.cordespace-evgating-add-status-msg.success { color: #2a7a2a; }
	</style>
	<?php
}

// ============================================================================
// 4) JS inline (jQuery + Select2 + AJAX)
// ============================================================================

function cordespace_evgating_print_inline_js(): void {
	?>
	<script>
	(function ($) {
		'use strict';
		var $root     = $('.cordespace-evgating-members').last();
		if ( ! $root.length ) return;
		var typeId    = parseInt($root.data('event-type-id'), 10);
		var nonce     = $root.data('nonce');
		var ajaxUrl   = $root.data('ajax-url');
		var isMatrix  = $root.hasClass('cordespace-evgating-matrix');
		var statusOpts = <?php
			$opts = [];
			foreach ( cordespace_evgating_valid_statuses() as $s ) {
				$opts[] = [
					'value' => $s,
					'label' => cordespace_evgating_status_icon( $s ) . ' ' . cordespace_evgating_status_label( $s ),
				];
			}
			echo wp_json_encode( $opts );
		?>;

		function buildStatusOptions(selected) {
			return statusOpts.map(function (o) {
				return '<option value="' + o.value + '"' + (o.value === selected ? ' selected' : '') + '>' + o.label + '</option>';
			}).join('');
		}

		function ajax(action, data, onSuccess, onError) {
			var payload = Object.assign({
				action: 'cordespace_evgating_' + action,
				event_type_id: typeId,
				nonce: nonce,
			}, data);
			$.post(ajaxUrl, payload).done(function (resp) {
				if (resp && resp.success) onSuccess(resp.data || {});
				else onError && onError((resp && resp.data && resp.data.message) || 'Erreur inconnue');
			}).fail(function () { onError && onError('Erreur réseau'); });
		}

		function updateCounts(counts) {
			if (!counts) return;
			$root.find('.cordespace-evgating-counts').html(
				'<span style="margin-right:0.8rem;">✅ <strong>' + counts.approved + '</strong> validé·es</span>' +
				'<span style="margin-right:0.8rem;">⏳ <strong>' + counts.pending  + '</strong> en attente</span>' +
				'<span>❌ <strong>' + counts.rejected + '</strong> refusé·es</span>'
			);
		}

		// --- Select2 pour la recherche user (commun matrice + binaire) -----
		var $sel = $('#cordespace-evgating-user-select');
		if ($.fn.select2) {
			$sel.select2({
				placeholder: 'Tape un nom, courriel ou login…',
				minimumInputLength: 2,
				allowClear: true,
				ajax: {
					transport: function (params, success, failure) {
						$.post(ajaxUrl, {
							action: 'cordespace_evgating_search_users',
							event_type_id: typeId,
							nonce: nonce,
							term: params.data.q || '',
						}).done(success).fail(failure);
					},
					processResults: function (resp) {
						return { results: (resp && resp.success && resp.data && resp.data.results) || [] };
					},
				},
			});
		}

		// ============================================================
		// HANDLERS PARTAGES (matrice + binaire)
		// Import CSV et retrait des emails en attente sont identiques
		// dans les 2 modes (le mode binaire n'a juste pas de checkboxes
		// de tags dans le form d'import).
		// ============================================================

		// --- Import CSV ----------------------------------------------------
		$root.on('click', '.cordespace-evgating-import-btn', function () {
			var $btn    = $(this);
			var $result = $root.find('.cordespace-evgating-import-result');
			var $file   = $root.find('.cordespace-evgating-import-file');
			var status  = $root.find('.cordespace-evgating-import-status').val();
			var tags    = $root.find('.cordespace-evgating-import-tag:checked').map(function () { return $(this).val(); }).get();

			var file = ($file[0] && $file[0].files && $file[0].files[0]) ? $file[0].files[0] : null;
			if (!file) {
				$result.text('✗ Choisis un fichier CSV.').css('color', '#a00');
				return;
			}

			var fd = new FormData();
			fd.append('action',        'cordespace_evgating_import_csv');
			fd.append('event_type_id', typeId);
			fd.append('nonce',         nonce);
			fd.append('status',        status);
			fd.append('tags',          JSON.stringify(tags));
			fd.append('csv_file',      file);

			$btn.prop('disabled', true);
			$result.text('Import en cours…').css('color', '#555');

			$.ajax({
				url:         ajaxUrl,
				type:        'POST',
				data:        fd,
				processData: false,
				contentType: false,
			}).done(function (resp) {
				$btn.prop('disabled', false);
				if (!resp || !resp.success) {
					var msg = (resp && resp.data && resp.data.message) || 'Erreur inconnue.';
					$result.text('✗ ' + msg).css('color', '#a00');
					return;
				}
				var d = resp.data;
				$result.html(
					'✓ Import terminé : <strong>' + d.direct + '</strong> validé·es direct, ' +
					'<strong>' + d.pending + '</strong> en attente d\'inscription, ' +
					'<strong>' + d.skipped + '</strong> lignes ignorées' +
					(d.invalid > 0 ? ', <strong>' + d.invalid + '</strong> emails invalides' : '') + '. ' +
					'<a href="" style="color:#1a1a2e;">Rafraîchir la page</a> pour voir le nouvel état.'
				).css('color', '#1a5c1a');
			}).fail(function () {
				$btn.prop('disabled', false);
				$result.text('✗ Erreur réseau.').css('color', '#a00');
			});
		});

		// --- Retirer un email en attente -----------------------------------
		$root.on('click', '.cordespace-evgating-pending-remove', function () {
			var $row  = $(this).closest('tr');
			var email = $row.data('email');
			if (!confirm('Retirer ' + email + ' de la liste d\'attente ?')) return;
			ajax('pending_remove', { email: email }, function () {
				$row.fadeOut(200, function () { $(this).remove(); });
			}, function (msg) {
				alert('Erreur : ' + msg);
			});
		});

		// --- Appliquer la hiérarchie aux validations existantes ------------
		$root.on('click', '.cordespace-evgating-apply-implications', function () {
			if (!confirm('Pour chaque membre déjà validé·e sur un tag parent, le système va aussi valider automatiquement les tags enfants configurés. Continuer ?')) return;
			var $btn    = $(this).prop('disabled', true);
			var $result = $root.find('.cordespace-evgating-apply-implications-result');
			$result.text('En cours…').css('color', '#555');
			ajax('apply_implications', {}, function (data) {
				$btn.prop('disabled', false);
				var html = '✓ Terminé : <strong>' + data.cascaded + '</strong> validations ajoutées sur <strong>' + data.members_processed + '</strong> membre(s) WP';
				if (data.pending_cascaded > 0) {
					html += ' + <strong>' + data.pending_cascaded + '</strong> validations sur <strong>' + data.pending_processed + '</strong> email(s) en attente';
				}
				html += '. <a href="" style="color:#1a1a2e;">Rafraîchir</a>';
				$result.html(html).css('color', '#1a5c1a');
			}, function (msg) {
				$btn.prop('disabled', false);
				$result.text('✗ ' + msg).css('color', '#a00');
			});
		});

		// ============================================================
		// MODE MATRICE
		// ============================================================
		if (isMatrix) {
			// --- Bouton AJOUTER (matrice) : insert N rows en DB, reload page
			$root.on('click', '.cordespace-evgating-add-btn', function () {
				var userId = parseInt($sel.val(), 10);
				if (!userId) {
					$('.cordespace-evgating-add-status-msg').text("Choisis d'abord une personne dans la recherche.").css('color', '#a00');
					return;
				}
				var $btn = $(this).prop('disabled', true);
				$('.cordespace-evgating-add-status-msg').text('Ajout en cours…').css('color', '#555');
				ajax('add_member', { user_id: userId, status: 'pending', notes: '' }, function () {
					// Recharge la page pour afficher la nouvelle row avec ses N dropdowns
					location.reload();
				}, function (msg) {
					$('.cordespace-evgating-add-status-msg').text('✗ ' + msg).css('color', '#a00');
					$btn.prop('disabled', false);
				});
			});

			// --- Dropdown status d'une CELLULE (membre × tag) ---------------
			$root.on('change', '.cordespace-evgating-tag-status', function () {
				var $sel2 = $(this);
				var $row  = $sel2.closest('tr');
				var userId = parseInt($row.data('user-id'), 10);
				var tag    = $sel2.data('tag');
				var status = $sel2.val();
				ajax('set_tag_status', { user_id: userId, tag: tag, status: status }, function (data) {
					if (data && data.counts) updateCounts(data.counts);
				}, function (msg) {
					alert('Erreur : ' + msg);
				});
			});

			// --- Note partagée par membre (textarea blur) -------------------
			$root.on('blur', '.cordespace-evgating-note', function () {
				var $ta  = $(this);
				var $row = $ta.closest('tr');
				var userId = parseInt($row.data('user-id'), 10);
				var notes  = $ta.val();
				var $status = $row.find('.cordespace-evgating-note-status').text('Enregistrement…');
				ajax('set_member_note', { user_id: userId, notes: notes }, function () {
					$status.text('✓ Enregistrée');
					setTimeout(function () { $status.text(''); }, 2000);
				}, function (msg) {
					$status.text('✗ ' + msg);
				});
			});

			// --- Bouton retirer un membre (matrice) -------------------------
			$root.on('click', '.cordespace-evgating-remove-matrix', function () {
				var $row    = $(this).closest('tr');
				var userId  = parseInt($row.data('user-id'), 10);
				var name    = $row.find('strong').text();
				if (!confirm('Retirer ' + name + ' du type ?')) return;
				ajax('remove_member', { user_id: userId }, function (data) {
					$row.fadeOut(200, function () { $(this).remove(); });
					if (data && data.counts) updateCounts(data.counts);
				}, function (msg) {
					alert('Erreur : ' + msg);
				});
			});

			// --- Filtrage et tri du tableau matrice -------------------------
			var $matrixBody = $('#cordespace-evgating-matrix-body');

			function applyFilters() {
				var search = ($root.find('.cordespace-evgating-search-input').val() || '').toLowerCase().trim();
				var statusFilters = {};
				$root.find('.cordespace-evgating-status-filter').each(function () {
					var tag = $(this).data('tag');
					var val = $(this).val();
					if (val) statusFilters[tag] = val;
				});

				var visible = 0;
				$matrixBody.find('tr.cordespace-evgating-matrix-row').each(function () {
					var $row = $(this);
					var match = true;
					// Recherche texte
					if (search && ($row.data('search') || '').indexOf(search) === -1) {
						match = false;
					}
					// Filtres statut par tag
					if (match) {
						$row.find('select.cordespace-evgating-tag-status').each(function () {
							var t = $(this).data('tag');
							var expected = statusFilters[t];
							if (expected && $(this).val() !== expected) {
								match = false;
								return false; // break .each
							}
						});
					}
					if (match) {
						$row.show();
						visible++;
					} else {
						$row.hide();
					}
				});
				$root.find('.cordespace-evgating-filter-count').text(visible + ' affiché·es');
			}

			$root.on('input', '.cordespace-evgating-search-input', applyFilters);
			$root.on('change', '.cordespace-evgating-status-filter', applyFilters);
			$root.on('click', '.cordespace-evgating-filters-reset', function () {
				$root.find('.cordespace-evgating-search-input').val('');
				$root.find('.cordespace-evgating-status-filter').val('');
				applyFilters();
			});

			// Re-applique le filtre quand le statut d'une cellule change
			// (= la row pourrait ne plus matcher le filtre actif)
			$root.on('change', '.cordespace-evgating-tag-status', function () {
				// Petit délai pour laisser l'AJAX se finir
				setTimeout(applyFilters, 100);
			});

			// --- Tri par colonne -------------------------------------------
			var sortState = { key: null, asc: true };
			$root.on('click', 'th.cordespace-evgating-sortable', function () {
				var key = $(this).data('sort');
				if (sortState.key === key) {
					sortState.asc = !sortState.asc;
				} else {
					sortState.key = key;
					sortState.asc = true;
				}
				// Update arrow indicators
				$root.find('.cordespace-evgating-sort-arrow').text('');
				$(this).find('.cordespace-evgating-sort-arrow').text(sortState.asc ? ' ▲' : ' ▼');

				var $rows = $matrixBody.find('tr.cordespace-evgating-matrix-row').get();
				$rows.sort(function (a, b) {
					var aVal, bVal;
					if (key === 'name') {
						aVal = $(a).data('sort-name') || '';
						bVal = $(b).data('sort-name') || '';
					} else if (key.indexOf('tag:') === 0) {
						var tag = key.substring(4);
						aVal = $(a).find('select.cordespace-evgating-tag-status[data-tag="' + tag + '"]').val() || '';
						bVal = $(b).find('select.cordespace-evgating-tag-status[data-tag="' + tag + '"]').val() || '';
					}
					if (aVal === bVal) return 0;
					return (aVal > bVal ? 1 : -1) * (sortState.asc ? 1 : -1);
				});
				$matrixBody.empty().append($rows);
			});

			return; // skip binary handlers
		}

		// ============================================================
		// MODE BINAIRE (UI historique, types sans tags)
		// ============================================================

		// --- Bouton AJOUTER ------------------------------------------------
		$root.on('click', '.cordespace-evgating-add-btn', function () {
			var userId = parseInt($sel.val(), 10);
			if (!userId) {
				$('.cordespace-evgating-add-status-msg').text('Choisis d\'abord une personne dans la recherche.').removeClass('success').addClass('error');
				return;
			}
			var status = $('#cordespace-evgating-add-status').val();
			var notes  = $('.cordespace-evgating-add-notes').val() || '';
			var $btn   = $(this).prop('disabled', true);
			ajax('add_member', { user_id: userId, status: status, notes: notes }, function (data) {
				// Append la nouvelle ligne au début de la liste
				var tpl = $('#cordespace-evgating-member-template').html();
				var html = tpl
					.replace(/\{\{user_id\}\}/g, data.user_id)
					.replace(/\{\{status\}\}/g, data.status)
					.replace(/\{\{display_name\}\}/g, $('<div>').text(data.display_name).html())
					.replace(/\{\{email\}\}/g, $('<div>').text(data.email).html())
					.replace(/\{\{status_options\}\}/g, buildStatusOptions(data.status))
					.replace(/\{\{notes\}\}/g, $('<div>').text(data.notes || '').html())
					.replace(/\{\{notes_dot\}\}/g, data.notes ? ' <span style="color:#2c70b8;">●</span>' : '')
					.replace(/\{\{notes_display\}\}/g, data.notes ? '' : 'display:none;');
				var $list = $root.find('.cordespace-evgating-list');
				// Si la liste ne contient AUCUNE ligne membre (= juste le placeholder
				// 'Aucun membre ajouté'), on la vide d'abord. Avant on cherchait
				// 'find p' mais ça matchait aussi les <p> dans la zone notes des
				// membres existants → ça vidait la liste a chaque ajout.
				if (!$list.find('.cordespace-evgating-member').length) $list.empty();
				$list.prepend(html);
				// Reset le form
				$sel.val(null).trigger('change');
				$('.cordespace-evgating-add-notes').val('');
				$('.cordespace-evgating-add-status-msg').text('✓ Ajouté').removeClass('error').addClass('success');
				updateCounts(data.counts);
				$btn.prop('disabled', false);
			}, function (msg) {
				$('.cordespace-evgating-add-status-msg').text('✗ ' + msg).removeClass('success').addClass('error');
				$btn.prop('disabled', false);
			});
		});

		// --- Change statut (inline) ----------------------------------------
		$root.on('change', '.cordespace-evgating-status-select', function () {
			var $row   = $(this).closest('.cordespace-evgating-member');
			var userId = parseInt($row.data('user-id'), 10);
			var status = $(this).val();
			ajax('update_member', { user_id: userId, status: status }, function (data) {
				$row.attr('data-status', status);
				updateCounts(data.counts);
			}, function (msg) {
				alert('Erreur : ' + msg);
			});
		});

		// --- Toggle notes / Save notes -------------------------------------
		$root.on('click', '.cordespace-evgating-notes-toggle', function () {
			var $row = $(this).closest('.cordespace-evgating-member');
			$row.find('.cordespace-evgating-notes-edit').toggle();
		});
		$root.on('click', '.cordespace-evgating-save-notes', function () {
			var $row    = $(this).closest('.cordespace-evgating-member');
			var userId  = parseInt($row.data('user-id'), 10);
			var notes   = $row.find('.cordespace-evgating-notes-edit textarea').val() || '';
			var $status = $row.find('.cordespace-evgating-notes-status').text('Enregistrement…');
			ajax('update_member', { user_id: userId, notes: notes }, function () {
				$status.text('✓ Enregistrée');
				// Update le petit point bleu sur le bouton
				var $toggle = $row.find('.cordespace-evgating-notes-toggle');
				$toggle.html('📝' + (notes ? ' <span style="color:#2c70b8;">●</span>' : ''));
				setTimeout(function () { $status.text(''); }, 2000);
			}, function (msg) {
				$status.text('✗ ' + msg);
			});
		});

		// --- Retirer un membre ---------------------------------------------
		$root.on('click', '.cordespace-evgating-remove', function () {
			var $row    = $(this).closest('.cordespace-evgating-member');
			var userId  = parseInt($row.data('user-id'), 10);
			var name    = $row.find('strong').text();
			if (!confirm('Retirer ' + name + ' de la liste ?')) return;
			ajax('remove_member', { user_id: userId }, function (data) {
				$row.fadeOut(200, function () { $(this).remove(); });
				updateCounts(data.counts);
			}, function (msg) {
				alert('Erreur : ' + msg);
			});
		});

		// Note : updateCounts() est defini en haut (avant le routing isMatrix)
		// pour etre partage entre les modes matrice et binaire.
	})(jQuery);
	</script>
	<?php
}

// ============================================================================
// 5) AJAX handlers
// ============================================================================

/**
 * Helper sécurité commune : valide nonce + capability + event_type_id.
 * Renvoie l'event_type_id en cas de succès, sinon wp_send_json_error et exit.
 */
function cordespace_evgating_ajax_authorize(): int {
	$event_type_id = isset( $_POST['event_type_id'] ) ? (int) $_POST['event_type_id'] : 0;
	$nonce         = isset( $_POST['nonce'] ) ? (string) wp_unslash( $_POST['nonce'] ) : '';
	if ( $event_type_id <= 0 || ! wp_verify_nonce( $nonce, 'cordespace_evgating_ajax_' . $event_type_id ) ) {
		wp_send_json_error( [ 'message' => 'Nonce invalide.' ], 403 );
	}
	if ( ! current_user_can( 'edit_post', $event_type_id ) ) {
		wp_send_json_error( [ 'message' => 'Permission insuffisante.' ], 403 );
	}
	return $event_type_id;
}

// Search users (autocomplete Select2)
add_action( 'wp_ajax_cordespace_evgating_search_users', 'cordespace_evgating_ajax_search_users' );
function cordespace_evgating_ajax_search_users(): void {
	$event_type_id = cordespace_evgating_ajax_authorize();
	$term          = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
	if ( strlen( $term ) < 2 ) {
		wp_send_json_success( [ 'results' => [] ] );
	}

	// Exclut les users déjà dans la liste
	global $wpdb;
	$table   = cordespace_event_gating_table_name();
	$exclude = $wpdb->get_col( $wpdb->prepare(
		"SELECT user_id FROM {$table} WHERE event_type_id = %d",
		$event_type_id
	) );
	$exclude = array_map( 'intval', (array) $exclude );

	$users = get_users( [
		'search'         => '*' . $term . '*',
		'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
		'number'         => 20,
		'exclude'        => $exclude,
		'orderby'        => 'display_name',
		'order'          => 'ASC',
	] );

	$results = array_map( function ( $u ) {
		return [
			'id'   => (int) $u->ID,
			'text' => sprintf( '%s — %s', $u->display_name ?: $u->user_login, $u->user_email ),
		];
	}, $users );

	wp_send_json_success( [ 'results' => $results ] );
}

// Add member
add_action( 'wp_ajax_cordespace_evgating_add_member', 'cordespace_evgating_ajax_add_member' );
function cordespace_evgating_ajax_add_member(): void {
	$event_type_id = cordespace_evgating_ajax_authorize();
	$user_id       = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
	$status        = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : CORDESPACE_EVTYPE_STATUS_PENDING;
	$notes         = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

	if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
		wp_send_json_error( [ 'message' => 'Utilisateur·trice introuvable.' ] );
	}

	$inserted = cordespace_evgating_add_member( $event_type_id, $user_id, $status, $notes, get_current_user_id() );
	if ( ! $inserted ) {
		wp_send_json_error( [ 'message' => 'Cette personne est déjà dans la liste.' ] );
	}

	$user   = get_user_by( 'id', $user_id );
	$counts = cordespace_evgating_count_by_status( $event_type_id );
	wp_send_json_success( [
		'user_id'      => $user_id,
		'display_name' => $user->display_name ?: $user->user_login,
		'email'        => $user->user_email,
		'status'       => $status,
		'notes'        => $notes,
		'counts'       => $counts,
	] );
}

// Update member (status and/or notes)
add_action( 'wp_ajax_cordespace_evgating_update_member', 'cordespace_evgating_ajax_update_member' );
function cordespace_evgating_ajax_update_member(): void {
	$event_type_id = cordespace_evgating_ajax_authorize();
	$user_id       = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
	$status        = array_key_exists( 'status', $_POST ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : null;
	$notes         = array_key_exists( 'notes', $_POST )  ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : null;

	$ok = cordespace_evgating_update_member( $event_type_id, $user_id, $status, $notes, get_current_user_id() );
	if ( ! $ok ) {
		wp_send_json_error( [ 'message' => 'Mise à jour impossible.' ] );
	}

	$counts = cordespace_evgating_count_by_status( $event_type_id );
	wp_send_json_success( [ 'counts' => $counts ] );
}

// Remove member
add_action( 'wp_ajax_cordespace_evgating_remove_member', 'cordespace_evgating_ajax_remove_member' );
function cordespace_evgating_ajax_remove_member(): void {
	$event_type_id = cordespace_evgating_ajax_authorize();
	$user_id       = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
	$ok            = cordespace_evgating_remove_member( $event_type_id, $user_id );
	if ( ! $ok ) {
		wp_send_json_error( [ 'message' => 'Suppression impossible.' ] );
	}
	$counts = cordespace_evgating_count_by_status( $event_type_id );
	wp_send_json_success( [ 'counts' => $counts ] );
}

// Set tag status (mode matrice) : modifie le statut d'UNE cellule (membre × tag)
add_action( 'wp_ajax_cordespace_evgating_set_tag_status', 'cordespace_evgating_ajax_set_tag_status' );
function cordespace_evgating_ajax_set_tag_status(): void {
	$event_type_id = cordespace_evgating_ajax_authorize();
	$user_id       = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
	$tag           = isset( $_POST['tag'] )     ? sanitize_text_field( wp_unslash( $_POST['tag'] ) )    : '';
	$status        = isset( $_POST['status'] )  ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

	if ( $user_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Utilisateur·trice invalide.' ], 400 );
	}
	if ( ! in_array( $status, cordespace_evgating_valid_statuses(), true ) ) {
		wp_send_json_error( [ 'message' => 'Statut invalide.' ], 400 );
	}

	$ok = cordespace_evgating_set_tag_status( $event_type_id, $user_id, $tag, $status, get_current_user_id() );
	if ( ! $ok ) {
		wp_send_json_error( [ 'message' => 'Erreur lors de la sauvegarde.' ], 500 );
	}

	$counts = cordespace_evgating_count_by_status( $event_type_id );
	wp_send_json_success( [ 'counts' => $counts ] );
}

// Set member note (mode matrice) : note partagée par membre (toutes ses rows)
add_action( 'wp_ajax_cordespace_evgating_set_member_note', 'cordespace_evgating_ajax_set_member_note' );
function cordespace_evgating_ajax_set_member_note(): void {
	$event_type_id = cordespace_evgating_ajax_authorize();
	$user_id       = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
	$notes         = isset( $_POST['notes'] )   ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

	if ( $user_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Utilisateur·trice invalide.' ], 400 );
	}

	$ok = cordespace_evgating_set_member_note( $event_type_id, $user_id, $notes );
	if ( ! $ok ) {
		wp_send_json_error( [ 'message' => 'Erreur lors de la sauvegarde de la note.' ], 500 );
	}

	wp_send_json_success( [ 'message' => 'OK' ] );
}

// Import CSV (mode matrice) : ingère un fichier d'emails et les ajoute soit
// directement à la matrice (si user WP existe) soit à la liste pending.
add_action( 'wp_ajax_cordespace_evgating_import_csv', 'cordespace_evgating_ajax_import_csv' );
function cordespace_evgating_ajax_import_csv(): void {
	$event_type_id = cordespace_evgating_ajax_authorize();
	$by_user_id    = get_current_user_id();
	$status        = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : CORDESPACE_EVTYPE_STATUS_APPROVED;
	if ( ! in_array( $status, cordespace_evgating_valid_statuses(), true ) ) {
		$status = CORDESPACE_EVTYPE_STATUS_APPROVED;
	}

	// Tags : array JSON encodée. Pour les types sans tags, le client envoie
	// un array vide → on cible tag = ''.
	$tags_raw = isset( $_POST['tags'] ) ? wp_unslash( $_POST['tags'] ) : '[]';
	$tags     = json_decode( (string) $tags_raw, true );
	$tags     = is_array( $tags ) ? array_values( array_filter( array_map( 'sanitize_text_field', $tags ) ) ) : [];

	// Détermine le mode : matrice (>= 1 tag fourni) ou binaire (tag='')
	$type_tags_configured = function_exists( 'cordespace_event_gating_get_tags' )
		? cordespace_event_gating_get_tags( $event_type_id )
		: [];
	if ( ! empty( $type_tags_configured ) && empty( $tags ) ) {
		wp_send_json_error( [ 'message' => 'Choisis au moins un tag à valider.' ], 400 );
	}
	$target_tags = ! empty( $tags ) ? $tags : [ '' ];

	// Vérif fichier
	if ( empty( $_FILES['csv_file'] ) || ! isset( $_FILES['csv_file']['tmp_name'] ) ) {
		wp_send_json_error( [ 'message' => 'Fichier CSV manquant.' ], 400 );
	}
	$tmp_name = (string) $_FILES['csv_file']['tmp_name'];
	if ( ! is_uploaded_file( $tmp_name ) ) {
		wp_send_json_error( [ 'message' => 'Upload invalide.' ], 400 );
	}

	$emails = cordespace_evgating_parse_csv_emails( $tmp_name );
	if ( $emails === null ) {
		wp_send_json_error( [ 'message' => 'Format CSV non reconnu. Le fichier doit avoir un header avec une colonne « Email ».' ], 400 );
	}

	$direct  = 0; // ajoutés direct à la matrice (user WP existe)
	$pending = 0; // ajoutés à la liste d'attente
	$skipped = 0; // déjà existants (matrice ou pending)
	$invalid = 0; // emails malformés

	foreach ( $emails as $raw_email ) {
		$email = cordespace_evgating_normalize_email( (string) $raw_email );
		if ( $email === '' ) {
			$invalid++;
			continue;
		}

		$user = get_user_by( 'email', $email );

		if ( $user ) {
			// User WP existe : on ingère direct dans la matrice via set_tag_status.
			// Ce dernier upserte par cellule, donc on appelle pour chaque tag cible.
			$any_inserted = false;
			foreach ( $target_tags as $tag ) {
				if ( cordespace_evgating_set_tag_status( $event_type_id, (int) $user->ID, (string) $tag, $status, $by_user_id ) ) {
					$any_inserted = true;
				}
			}
			if ( $any_inserted ) {
				$direct++;
			} else {
				$skipped++;
			}
		} else {
			// Pas de user WP : on ajoute en attente.
			$any_pending = false;
			foreach ( $target_tags as $tag ) {
				if ( cordespace_evgating_pending_add( $email, $event_type_id, (string) $tag, $status, '', $by_user_id ) > 0 ) {
					$any_pending = true;
				}
			}
			if ( $any_pending ) {
				$pending++;
			} else {
				$skipped++;
			}
		}
	}

	wp_send_json_success( [
		'direct'  => $direct,
		'pending' => $pending,
		'skipped' => $skipped,
		'invalid' => $invalid,
	] );
}

/**
 * Parse un fichier CSV et extrait les emails de la colonne « Email ».
 *
 * Détection auto du header (insensible à la casse). Si pas trouvé,
 * fallback sur position 3 (format Momence : First Name, Last Name, Email, ...).
 *
 * @return string[]|null Liste des emails bruts (pas encore normalisés),
 *                       ou null si format non reconnu.
 */
function cordespace_evgating_parse_csv_emails( string $file_path ): ?array {
	$handle = @fopen( $file_path, 'r' );
	if ( ! $handle ) {
		return null;
	}

	// Lit le header
	$header = fgetcsv( $handle );
	if ( ! is_array( $header ) ) {
		fclose( $handle );
		return null;
	}

	// Trouve la colonne Email (insensible à la casse, accepte "Email", "EMAIL", etc.)
	$email_col = -1;
	foreach ( $header as $i => $col ) {
		if ( strtolower( trim( (string) $col ) ) === 'email' ) {
			$email_col = $i;
			break;
		}
	}

	// Fallback : format Momence (col 3 = index 2)
	if ( $email_col === -1 && count( $header ) >= 3 ) {
		// On valide qu'au moins une row a un email valide en position 2
		$probe = fgetcsv( $handle );
		if ( is_array( $probe ) && isset( $probe[2] ) && is_email( trim( strtolower( (string) $probe[2] ) ) ) ) {
			$email_col = 2;
			// Rewind : on remet la position 0 pour relire depuis le début
			rewind( $handle );
			fgetcsv( $handle ); // skip le header
		} else {
			fclose( $handle );
			return null;
		}
	}

	if ( $email_col === -1 ) {
		fclose( $handle );
		return null;
	}

	// Extrait tous les emails
	$emails = [];
	while ( ( $row = fgetcsv( $handle ) ) !== false ) {
		if ( ! is_array( $row ) || ! isset( $row[ $email_col ] ) ) {
			continue;
		}
		$emails[] = (string) $row[ $email_col ];
	}
	fclose( $handle );
	return $emails;
}

// Applique les implications configurées aux validations existantes
add_action( 'wp_ajax_cordespace_evgating_apply_implications', 'cordespace_evgating_ajax_apply_implications' );
function cordespace_evgating_ajax_apply_implications(): void {
	$event_type_id = cordespace_evgating_ajax_authorize();
	$by_user_id    = get_current_user_id();

	if ( ! function_exists( 'cordespace_event_gating_get_tag_implications' ) ) {
		wp_send_json_error( [ 'message' => 'Module CPT non chargé.' ], 500 );
	}
	$implications = cordespace_event_gating_get_tag_implications( $event_type_id );
	if ( empty( $implications ) ) {
		wp_send_json_success( [ 'cascaded' => 0, 'members_processed' => 0 ] );
	}

	$members           = cordespace_evgating_get_members( $event_type_id );
	$cascaded          = 0;
	$members_processed = 0;

	// 1. WP users (matrice principale)
	foreach ( $members as $m ) {
		$user_id  = (int) $m['user_id'];
		$statuses = (array) $m['statuses'];
		$any_cascade_for_this_member = false;

		foreach ( $statuses as $tag => $status ) {
			if ( $status !== CORDESPACE_EVTYPE_STATUS_APPROVED ) {
				continue;
			}
			$implied = isset( $implications[ $tag ] ) && is_array( $implications[ $tag ] )
				? $implications[ $tag ]
				: [];
			foreach ( $implied as $child_tag ) {
				$child_tag = (string) $child_tag;
				if ( $child_tag === '' ) {
					continue;
				}
				$current = (string) ( $statuses[ $child_tag ] ?? '' );
				if ( $current === CORDESPACE_EVTYPE_STATUS_APPROVED ) {
					continue;
				}
				if ( cordespace_evgating_set_tag_status( $event_type_id, $user_id, $child_tag, CORDESPACE_EVTYPE_STATUS_APPROVED, $by_user_id, [ $tag ] ) ) {
					$cascaded++;
					$any_cascade_for_this_member = true;
				}
			}
		}

		if ( $any_cascade_for_this_member ) {
			$members_processed++;
		}
	}

	// 2. Pending emails (les imports CSV pour les emails sans compte WP)
	$pending_cascaded = 0;
	$pending_emails_processed = 0;
	if ( function_exists( 'cordespace_evgating_pending_get_for_type' ) && function_exists( 'cordespace_evgating_pending_add' ) ) {
		$pending_list = cordespace_evgating_pending_get_for_type( $event_type_id );
		foreach ( $pending_list as $p ) {
			$email    = (string) $p['email'];
			$statuses = (array) $p['statuses'];
			$any_for_this_email = false;

			foreach ( $statuses as $tag => $status ) {
				if ( $status !== CORDESPACE_EVTYPE_STATUS_APPROVED ) {
					continue;
				}
				$implied = isset( $implications[ $tag ] ) && is_array( $implications[ $tag ] )
					? $implications[ $tag ]
					: [];
				foreach ( $implied as $child_tag ) {
					$child_tag = (string) $child_tag;
					if ( $child_tag === '' ) {
						continue;
					}
					$current = (string) ( $statuses[ $child_tag ] ?? '' );
					if ( $current === CORDESPACE_EVTYPE_STATUS_APPROVED ) {
						continue;
					}
					if ( cordespace_evgating_pending_add( $email, $event_type_id, $child_tag, CORDESPACE_EVTYPE_STATUS_APPROVED, '', $by_user_id, [ $tag ] ) > 0 ) {
						$pending_cascaded++;
						$any_for_this_email = true;
					}
				}
			}

			if ( $any_for_this_email ) {
				$pending_emails_processed++;
			}
		}
	}

	wp_send_json_success( [
		'cascaded'          => $cascaded,
		'members_processed' => $members_processed,
		'pending_cascaded'  => $pending_cascaded,
		'pending_processed' => $pending_emails_processed,
	] );
}

// Retire un email de la liste d'attente
add_action( 'wp_ajax_cordespace_evgating_pending_remove', 'cordespace_evgating_ajax_pending_remove' );
function cordespace_evgating_ajax_pending_remove(): void {
	$event_type_id = cordespace_evgating_ajax_authorize();
	$email         = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

	if ( $email === '' || ! function_exists( 'cordespace_evgating_pending_remove' ) ) {
		wp_send_json_error( [ 'message' => 'Email invalide ou module manquant.' ], 400 );
	}

	$n = cordespace_evgating_pending_remove( $email, $event_type_id );
	if ( $n <= 0 ) {
		wp_send_json_error( [ 'message' => 'Email introuvable en attente.' ], 404 );
	}
	wp_send_json_success( [ 'removed' => $n ] );
}
