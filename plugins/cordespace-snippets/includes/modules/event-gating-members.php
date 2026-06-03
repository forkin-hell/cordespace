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
 * @return array<int, array{
 *     id: int, user_id: int, status: string, notes: string,
 *     approved_at: ?string, display_name: string, email: string,
 * }>
 */
function cordespace_evgating_get_members( int $event_type_id ): array {
	global $wpdb;
	$table = cordespace_event_gating_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT a.id, a.user_id, a.status, a.notes, a.approved_at,
		        u.display_name, u.user_email AS email
		   FROM {$table} a
		   JOIN {$wpdb->users} u ON u.ID = a.user_id
		  WHERE a.event_type_id = %d
		  ORDER BY
		    CASE a.status
		      WHEN 'approved' THEN 1
		      WHEN 'pending'  THEN 2
		      WHEN 'rejected' THEN 3
		      ELSE 4
		    END ASC,
		    u.display_name ASC",
		$event_type_id
	), ARRAY_A );
	return is_array( $rows ) ? array_map( function ( $r ) {
		return [
			'id'           => (int) $r['id'],
			'user_id'      => (int) $r['user_id'],
			'status'       => (string) $r['status'],
			'notes'        => (string) ( $r['notes'] ?? '' ),
			'approved_at'  => $r['approved_at'] ?? null,
			'display_name' => (string) $r['display_name'],
			'email'        => (string) $r['email'],
		];
	}, $rows ) : [];
}

/**
 * Compte les membres par statut (utilisé pour le badge du header).
 * @return array{approved:int, pending:int, rejected:int}
 */
function cordespace_evgating_count_by_status( int $event_type_id ): array {
	global $wpdb;
	$table = cordespace_event_gating_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT status, COUNT(*) AS n FROM {$table} WHERE event_type_id = %d GROUP BY status",
		$event_type_id
	), ARRAY_A );
	$out = [ 'approved' => 0, 'pending' => 0, 'rejected' => 0 ];
	foreach ( (array) $rows as $r ) {
		if ( isset( $out[ $r['status'] ] ) ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
	}
	return $out;
}

/**
 * Ajoute un membre. Retourne l'ID de la nouvelle ligne, ou 0 si déjà existant.
 */
function cordespace_evgating_add_member( int $event_type_id, int $user_id, string $status, string $notes, int $by_user_id ): int {
	global $wpdb;
	if ( $event_type_id <= 0 || $user_id <= 0 ) {
		return 0;
	}
	if ( ! in_array( $status, cordespace_evgating_valid_statuses(), true ) ) {
		$status = CORDESPACE_EVTYPE_STATUS_PENDING;
	}
	$table = cordespace_event_gating_table_name();

	// Vérifie qu'il n'y en a pas déjà un — UNIQUE KEY l'empêcherait de toute
	// façon, mais on évite l'erreur SQL.
	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE event_type_id = %d AND user_id = %d",
		$event_type_id, $user_id
	) );
	if ( $existing ) {
		return 0;
	}

	$now = current_time( 'mysql', true );
	$inserted = $wpdb->insert(
		$table,
		[
			'event_type_id' => $event_type_id,
			'user_id'       => $user_id,
			'status'        => $status,
			'notes'         => $notes,
			'approved_at'   => ( $status === CORDESPACE_EVTYPE_STATUS_APPROVED ) ? $now : null,
			'approved_by'   => ( $status === CORDESPACE_EVTYPE_STATUS_APPROVED ) ? $by_user_id : null,
			'created_at'    => $now,
			'updated_at'    => $now,
		],
		[ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
	);
	return $inserted ? (int) $wpdb->insert_id : 0;
}

/**
 * Met à jour le statut et/ou les notes d'un membre existant. Si le statut
 * change vers 'approved', mémorise la date/by_user (sinon les laisse).
 */
function cordespace_evgating_update_member( int $event_type_id, int $user_id, ?string $status, ?string $notes, int $by_user_id ): bool {
	global $wpdb;
	$table = cordespace_event_gating_table_name();
	$data  = [ 'updated_at' => current_time( 'mysql', true ) ];
	$fmt   = [ '%s' ];

	if ( $status !== null ) {
		if ( ! in_array( $status, cordespace_evgating_valid_statuses(), true ) ) {
			return false;
		}
		$data['status'] = $status;
		$fmt[]          = '%s';
		if ( $status === CORDESPACE_EVTYPE_STATUS_APPROVED ) {
			$data['approved_at'] = current_time( 'mysql', true );
			$data['approved_by'] = $by_user_id;
			$fmt[]               = '%s';
			$fmt[]               = '%d';
		}
	}
	if ( $notes !== null ) {
		$data['notes'] = $notes;
		$fmt[]         = '%s';
	}

	return false !== $wpdb->update(
		$table,
		$data,
		[ 'event_type_id' => $event_type_id, 'user_id' => $user_id ],
		$fmt,
		[ '%d', '%d' ]
	);
}

/**
 * Retire un membre de la liste.
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

function cordespace_evgating_render_members_metabox( WP_Post $post ): void {
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
				<textarea class="cordespace-evgating-add-notes" rows="2" style="width:100%; margin-top:0.4rem;" placeholder="<?php esc_attr_e( 'Ex : validée après entretien, à recontacter en juin, etc.', 'cordespace-snippets' ); ?>"></textarea>
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

	<?php cordespace_evgating_render_member_template(); ?>
	<?php cordespace_evgating_print_inline_js(); ?>
	<?php cordespace_evgating_print_inline_css(); ?>
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

		// --- Select2 pour la recherche user --------------------------------
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

		// --- Helpers -------------------------------------------------------
		function updateCounts(counts) {
			if (!counts) return;
			$root.find('.cordespace-evgating-counts').html(
				'<span style="margin-right:0.8rem;">✅ <strong>' + counts.approved + '</strong> validé·es</span>' +
				'<span style="margin-right:0.8rem;">⏳ <strong>' + counts.pending  + '</strong> en attente</span>' +
				'<span>❌ <strong>' + counts.rejected + '</strong> refusé·es</span>'
			);
		}
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
