<?php
/**
 * Module : mon-espace.linked-accounts
 *
 * Liaison entre 2 comptes WP d'une même personne (typiquement un compte
 * « client·e » Amelia + un compte « prof » Amelia) et bascule en un clic.
 *
 * - Ajoute un champ « Compte lié » dans le profil WP (admin seulement).
 * - Donne la cap `switch_to_user` UNIQUEMENT vers le compte lié déclaré.
 * - Fournit le shortcode [cordespace_switch_button] avec redirect intelligent
 *   vers la page /mon-espace/ résolue dynamiquement (cf. helper).
 * - Vide les cookies Amelia à chaque switch (sinon l'ancien user « colle »).
 *
 * Dépendances : plugin User Switching (john-blackbourn).
 *
 * Voir docs/MON-ESPACE.md pour la procédure de liaison des comptes.
 */

defined( 'ABSPATH' ) || exit;

// 1) Champ admin "Compte lié" sur le profil WP
add_action( 'show_user_profile', 'cordespace_linked_user_field' );
add_action( 'edit_user_profile', 'cordespace_linked_user_field' );

function cordespace_linked_user_field( $user ) {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}
	$linked_id = (int) get_user_meta( $user->ID, '_cordespace_linked_user_id', true );
	$linked    = $linked_id ? get_user_by( 'ID', $linked_id ) : null;
	?>
	<h2>Cordespace — Compte lié</h2>
	<table class="form-table">
		<tr>
			<th><label for="cordespace_linked_user_id">Autre compte WP de cette personne</label></th>
			<td>
				<input type="number" id="cordespace_linked_user_id" name="cordespace_linked_user_id"
					value="<?php echo esc_attr( $linked_id ); ?>" class="regular-text" min="0">
				<?php if ( $linked ) : ?>
					<p style="margin-top:0.5rem;color:#2c70b8;"><strong>Actuellement lié à :</strong>
						<?php echo esc_html( $linked->display_name . ' (' . $linked->user_email . ')' ); ?>
					</p>
				<?php endif; ?>
				<p class="description">
					ID de l'autre compte WordPress de cette personne (ex. son compte prof si celui-ci est client·e, ou inverse).<br>
					Permet à User Switching de basculer entre les deux d'un clic. Mets <code>0</code> pour délier.
				</p>
			</td>
		</tr>
	</table>
	<?php
}

add_action( 'personal_options_update', 'cordespace_save_linked_user' );
add_action( 'edit_user_profile_update', 'cordespace_save_linked_user' );

function cordespace_save_linked_user( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	if ( ! isset( $_POST['cordespace_linked_user_id'] ) ) {
		return;
	}
	$linked = (int) $_POST['cordespace_linked_user_id'];
	if ( $linked > 0 && get_user_by( 'ID', $linked ) ) {
		update_user_meta( $user_id, '_cordespace_linked_user_id', $linked );
	} else {
		delete_user_meta( $user_id, '_cordespace_linked_user_id' );
	}
}

// 2) Autorise switch_to_user UNIQUEMENT vers le compte lié
add_filter( 'user_has_cap', 'cordespace_grant_switch_to_linked', 10, 4 );

function cordespace_grant_switch_to_linked( $allcaps, $caps, $args, $user ) {
	if ( empty( $args[0] ) || $args[0] !== 'switch_to_user' ) {
		return $allcaps;
	}
	if ( empty( $args[2] ) ) {
		return $allcaps;
	}
	$target_id = (int) $args[2];
	$linked_id = (int) get_user_meta( $user->ID, '_cordespace_linked_user_id', true );
	if ( $linked_id > 0 && $linked_id === $target_id ) {
		$allcaps['switch_to_user'] = true;
	}
	return $allcaps;
}

// 3) Détermine où rediriger après le switch
//    Avec la page unifiée, on redirige toujours vers la page Mon espace
//    (résolue dynamiquement via le helper) qui s'adapte automatiquement
//    au rôle de l'utilisateur·trice après le switch.
function cordespace_get_switch_redirect_target() {
	return cordespace_get_mon_espace_url();
}

// 4) Shortcode pour afficher le bouton de bascule
add_shortcode( 'cordespace_switch_button', 'cordespace_render_switch_button' );

function cordespace_render_switch_button( $atts ) {
	if ( ! is_user_logged_in() ) {
		return '';
	}
	if ( ! class_exists( 'user_switching' ) ) {
		return '<p style="color:#a33;"><em>Le plugin User Switching doit être activé pour cette fonctionnalité.</em></p>';
	}

	$current   = wp_get_current_user();
	$linked_id = (int) get_user_meta( $current->ID, '_cordespace_linked_user_id', true );
	if ( $linked_id <= 0 ) {
		return '';
	}

	$linked_user = get_user_by( 'ID', $linked_id );
	if ( ! $linked_user ) {
		return '';
	}

	$url = user_switching::maybe_switch_url( $linked_user );
	if ( ! $url ) {
		return '';
	}

	// Ajoute le redirect_to vers /mon-espace/
	$redirect = cordespace_get_switch_redirect_target();
	$url      = add_query_arg( 'redirect_to', urlencode( $redirect ), $url );

	$atts = shortcode_atts( [
		'label' => '',
		'style' => 'background:#2c70b8;color:#fff;',
	], $atts );

	$default_label = sprintf(
		'Basculer vers mon autre compte (%s)',
		$linked_user->display_name ?: $linked_user->user_email
	);
	$label = $atts['label'] ?: $default_label;

	return sprintf(
		'<a href="%s" style="display:inline-block;padding:0.7rem 1.4rem;text-decoration:none;border-radius:5px;font-weight:600;%s">🔄 %s</a>',
		esc_url( $url ),
		esc_attr( $atts['style'] ),
		esc_html( $label )
	);
}

// 5) Force le redirect dans la chaîne User Switching aussi (ceinture + bretelles)
add_filter( 'user_switching_redirect_to', 'cordespace_user_switching_redirect_filter', 10, 4 );

function cordespace_user_switching_redirect_filter( $redirect_to, $context = '', $current_user = null, $new_user = null ) {
	if ( ! empty( $_REQUEST['redirect_to'] ) ) {
		$wanted = wp_unslash( (string) $_REQUEST['redirect_to'] );
		$home   = home_url();
		// Accepte les URLs du même domaine ou les chemins relatifs commençant
		// par /mon-espace (substring tolérant : reste valide même si la page
		// est renommée tant qu'elle contient « mon-espace » dans le slug).
		if ( strpos( $wanted, $home ) === 0 || strpos( $wanted, '/mon-espace' ) === 0 ) {
			return $wanted;
		}
	}
	return $redirect_to;
}

// 6) Vide les cookies Amelia (ameliaToken + ameliaUserEmail) à chaque switch.
//    Sans ça, Amelia continue d'authentifier l'ancien user via JWT cookie
//    après un User Switching, et affiche les mauvaises données.
add_action( 'switch_to_user',   'cordespace_clear_amelia_cookies', 5 );
add_action( 'switch_back_user', 'cordespace_clear_amelia_cookies', 5 );
add_action( 'switch_off_user',  'cordespace_clear_amelia_cookies', 5 );

function cordespace_clear_amelia_cookies() {
	if ( headers_sent() ) {
		return;
	}
	$past = time() - 3600;
	setcookie( 'ameliaToken',     '', $past, '/' );
	setcookie( 'ameliaUserEmail', '', $past, '/' );
	unset( $_COOKIE['ameliaToken'], $_COOKIE['ameliaUserEmail'] );
}

// ============================================================================
// 6.5) [RETIRÉ] Auto-sync WP role → Amelia type
//
//      Une version précédente de ce module synchait automatiquement le type
//      Amelia avec le rôle WP via le hook set_user_role. C'était une
//      fausse bonne idée — explication :
//
//      Le rôle WP et le type Amelia sont DEUX CONCEPTS DÉCORRÉLÉS :
//      - Rôle WP    : ce que l'user peut faire en wp-admin (caps WP,
//                     accès aux pages admin, etc.)
//      - Type Amelia: qui peut se logger dans quel cabinet
//                     (customer pour vue cliente, provider pour vue prof).
//                     Le cabinet provider REFUSE explicitement type=manager,
//                     codé en dur dans Amelia (voir UserApplicationService).
//
//      Avec User Role Editor, un user peut avoir le rôle wpamelia-manager
//      (caps admin Amelia côté wp-admin) tout en gardant Amelia type=provider
//      (pour utiliser le cabinet enseignant·e). C'est le pattern correct
//      pour les profs avec super-pouvoirs admin.
//
//      L'auto-sync précédent écrasait ce pattern et cassait l'accès au
//      cabinet pour ces users.
//
//      Conclusion : on ne sync PAS. Pour changer le type Amelia, passer
//      par wp-admin → Amelia → Users → éditer (UI Amelia native qui sait
//      gérer les deux côtés cohéremment).
// ============================================================================

// ============================================================================
// 7) Page admin « Lier des comptes »
//    Centralise la liaison prof ↔ cliente : tableau avec un dropdown par
//    prof pour choisir le compte client·e associé, sauvegarde AJAX
//    bi-directionnelle.
//
//    Le sous-menu est enregistré conditionnellement depuis admin/menu.php
//    (selon que ce module est actif ou non).
// ============================================================================

/**
 * Helpers internes pour récupérer profs et clients Amelia avec leur WP user.
 */
function cordespace_la_get_profs(): array {
	global $wpdb;
	return $wpdb->get_results(
		"SELECT au.id AS amelia_id, au.externalId AS wp_user_id,
		        au.firstName, au.lastName, au.email, au.type
		   FROM {$wpdb->prefix}amelia_users au
		  WHERE au.type IN ('provider', 'manager')
		    AND au.status = 'visible'
		    AND au.externalId IS NOT NULL
		  ORDER BY au.firstName, au.lastName",
		ARRAY_A
	) ?: [];
}

function cordespace_la_get_customers(): array {
	global $wpdb;
	return $wpdb->get_results(
		"SELECT au.externalId AS wp_user_id,
		        au.firstName, au.lastName, au.email
		   FROM {$wpdb->prefix}amelia_users au
		  WHERE au.type = 'customer'
		    AND au.status = 'visible'
		    AND au.externalId IS NOT NULL
		  ORDER BY au.firstName, au.lastName",
		ARRAY_A
	) ?: [];
}

/**
 * Liaisons orphelines réparables : entités Amelia customer avec externalId
 * NULL mais dont l'email correspond à un user WP existant. Pour chaque ligne
 * retournée, un UPDATE externalId = wp_id corrige la liaison.
 *
 * Symptôme côté UX sans réparation : la personne n'apparaît pas dans le
 * dropdown des clients de la page « Lier des comptes » → impossible de la
 * lier à son compte prof.
 */
function cordespace_la_get_orphan_customers(): array {
	global $wpdb;
	return $wpdb->get_results(
		"SELECT au.id AS amelia_id, au.email, au.firstName, au.lastName,
		        u.ID AS wp_user_id, u.user_login
		   FROM {$wpdb->prefix}amelia_users au
		   JOIN {$wpdb->users} u ON u.user_email = au.email
		  WHERE au.type = 'customer'
		    AND au.status = 'visible'
		    AND au.externalId IS NULL
		    AND au.email IS NOT NULL
		    AND au.email != ''
		  ORDER BY au.firstName, au.lastName",
		ARRAY_A
	) ?: [];
}

/**
 * Renderer de la page wp-admin « Lier des comptes ».
 */
function cordespace_admin_render_link_accounts_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'Accès refusé.', 'cordespace-snippets' ) );
	}

	$profs     = cordespace_la_get_profs();
	$customers = cordespace_la_get_customers();
	$orphans   = cordespace_la_get_orphan_customers();
	$nonce     = wp_create_nonce( 'cordespace_link_accounts' );
	$ajax_url  = admin_url( 'admin-ajax.php' );
	?>
	<div class="wrap cordespace-link-accounts-page">
		<h1>🪢 Cordespace — Lier des comptes</h1>
		<p>
			Pour chaque compte enseignant·e Cordespace, choisis le compte client·e à lui associer.
			La liaison est <strong>bi-directionnelle et automatique</strong> : pas besoin d'aller modifier les deux profils à la main.
			Le bouton de bascule entre les deux comptes apparaîtra alors sur <code>/mon-espace/</code>.
		</p>

		<?php if ( ! empty( $orphans ) ) : ?>
			<div class="notice notice-warning cordespace-la-orphans" style="margin:1.2rem 0;padding:1rem 1.2rem;">
				<p style="margin:0 0 0.6rem;">
					<strong>🔧 Liaisons orphelines détectées</strong> —
					Ces entités Amelia client·e ont un email qui correspond à un compte WordPress existant, mais le lien interne (<code>externalId</code>) est cassé. Tant que ce lien n'est pas réparé, la personne <strong>n'apparaît pas dans le dropdown</strong> du tableau ci-dessous.
				</p>
				<table class="widefat" style="background:#fffaf0;margin-top:0.6rem;">
					<thead>
						<tr>
							<th style="width:50%;">Personne</th>
							<th style="width:30%;">Match WordPress</th>
							<th style="width:20%;">Action</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $orphans as $orphan ) :
						$orphan_name = trim( ( $orphan['firstName'] ?? '' ) . ' ' . ( $orphan['lastName'] ?? '' ) ) ?: $orphan['email'];
					?>
						<tr data-amelia-id="<?php echo (int) $orphan['amelia_id']; ?>" data-wp-user-id="<?php echo (int) $orphan['wp_user_id']; ?>">
							<td>
								<strong><?php echo esc_html( $orphan_name ); ?></strong><br>
								<span style="color:#666;font-size:0.9em;"><?php echo esc_html( $orphan['email'] ); ?></span><br>
								<span style="color:#999;font-size:0.8em;">Amelia #<?php echo (int) $orphan['amelia_id']; ?> (externalId = NULL)</span>
							</td>
							<td>
								<strong><?php echo esc_html( $orphan['user_login'] ); ?></strong><br>
								<span style="color:#999;font-size:0.8em;">WP #<?php echo (int) $orphan['wp_user_id']; ?></span>
							</td>
							<td>
								<button class="button button-primary cordespace-la-repair-btn">🔧 Lier automatiquement</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<?php if ( empty( $profs ) ) : ?>
			<div class="notice notice-warning"><p>Aucun·e enseignant·e trouvé·e. Vérifier la configuration des comptes profs (wp-admin → Amelia → Users).</p></div>
		<?php else : ?>
			<table class="widefat fixed striped cordespace-la-table" style="margin-top:1rem;">
				<thead>
					<tr>
						<th style="width:38%;">Compte enseignant·e</th>
						<th style="width:52%;">Compte client·e lié</th>
						<th style="width:10%;">État</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $profs as $prof ) :
					$prof_wp_id   = (int) $prof['wp_user_id'];
					if ( $prof_wp_id <= 0 ) continue;
					$prof_name    = trim( ( $prof['firstName'] ?? '' ) . ' ' . ( $prof['lastName'] ?? '' ) ) ?: $prof['email'];
					$current_link = (int) get_user_meta( $prof_wp_id, '_cordespace_linked_user_id', true );
				?>
					<tr data-prof-id="<?php echo $prof_wp_id; ?>">
						<td>
							<strong><?php echo esc_html( $prof_name ); ?></strong><br>
							<span style="color:#666;font-size:0.9em;"><?php echo esc_html( $prof['email'] ); ?></span><br>
							<span style="color:#999;font-size:0.8em;">WP user ID : <?php echo $prof_wp_id; ?></span>
						</td>
						<td>
							<select class="cordespace-la-select" data-prof-id="<?php echo $prof_wp_id; ?>" style="max-width:100%;width:100%;">
								<option value="0">— Aucun (pas de liaison) —</option>
								<?php foreach ( $customers as $cust ) :
									$cust_wp_id = (int) $cust['wp_user_id'];
									if ( $cust_wp_id <= 0 || $cust_wp_id === $prof_wp_id ) continue; // pas soi-même
									$cust_name  = trim( ( $cust['firstName'] ?? '' ) . ' ' . ( $cust['lastName'] ?? '' ) ) ?: $cust['email'];
									$label      = $cust_name . ' — ' . $cust['email'] . ' (WP #' . $cust_wp_id . ')';
									?>
									<option value="<?php echo $cust_wp_id; ?>" <?php selected( $current_link, $cust_wp_id ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td class="cordespace-la-status">
							<?php if ( $current_link > 0 ) : ?>
								<span class="cordespace-la-status-linked">✓ Lié</span>
							<?php else : ?>
								<span class="cordespace-la-status-empty">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin-top:1rem;color:#666;font-size:0.9em;">
				💡 Le changement est sauvegardé automatiquement dès que tu modifies un dropdown.
			</p>
		<?php endif; ?>

		<style>
			.cordespace-link-accounts-page .cordespace-la-status-linked { color:#00765c; font-weight:600; }
			.cordespace-link-accounts-page .cordespace-la-status-empty { color:#999; }
		</style>

		<script>
		(function () {
			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

			function setStatusCell(cell, linked) {
				if (!cell) return;
				// Reset cell content sans innerHTML pour éviter XSS theoretical
				while (cell.firstChild) cell.removeChild(cell.firstChild);
				var span = document.createElement('span');
				if (linked) {
					span.className = 'cordespace-la-status-linked';
					span.textContent = '✓ Lié';
				} else {
					span.className = 'cordespace-la-status-empty';
					span.textContent = '—';
				}
				cell.appendChild(span);
			}

			// Réparation des orphelins (bouton « Lier automatiquement »)
			document.querySelectorAll('.cordespace-la-repair-btn').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var row        = btn.closest('tr');
					if (!row) return;
					var ameliaId   = parseInt(row.dataset.ameliaId, 10);
					var wpUserId   = parseInt(row.dataset.wpUserId, 10);
					if (!ameliaId || !wpUserId) return;

					btn.disabled = true;
					btn.textContent = '⏳ Réparation...';

					var body = new FormData();
					body.append('action',     'cordespace_repair_orphan_customer');
					body.append('_wpnonce',   nonce);
					body.append('amelia_id',  ameliaId);
					body.append('wp_user_id', wpUserId);

					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function (r) { return r.json(); })
						.then(function (data) {
							if (data && data.success) {
								cordespaceLaNotice('success', (data.data && data.data.message) || 'Liaison réparée.');
								row.style.opacity = '0.4';
								btn.textContent = '✓ Réparé — recharge la page';
								// Auto reload pour rafraîchir la liste des orphelins et le dropdown
								setTimeout(function () { window.location.reload(); }, 1500);
							} else {
								btn.disabled = false;
								btn.textContent = '🔧 Lier automatiquement';
								cordespaceLaNotice('error', (data && data.data && data.data.message) || 'Erreur de réparation.');
							}
						})
						.catch(function (err) {
							btn.disabled = false;
							btn.textContent = '🔧 Lier automatiquement';
							cordespaceLaNotice('error', 'Erreur réseau : ' + err.message);
						});
				});
			});

			document.querySelectorAll('.cordespace-la-select').forEach(function (sel) {
				sel.dataset.previousValue = sel.value;

				sel.addEventListener('change', function () {
					var profId     = parseInt(sel.dataset.profId, 10);
					var customerId = parseInt(sel.value, 10) || 0;
					var row        = sel.closest('tr');
					var statusCell = row ? row.querySelector('.cordespace-la-status') : null;

					sel.disabled = true;

					var body = new FormData();
					body.append('action', 'cordespace_save_link_accounts');
					body.append('_wpnonce', nonce);
					body.append('prof_id', profId);
					body.append('customer_id', customerId);

					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function (r) { return r.json(); })
						.then(function (data) {
							sel.disabled = false;
							if (data && data.success) {
								setStatusCell(statusCell, customerId > 0);
								sel.dataset.previousValue = sel.value;
								cordespaceLaNotice('success', data.data && data.data.message ? data.data.message : 'Liaison mise à jour.');
								// Si on vient de lier ce prof à une cliente X, d'autres profs qui étaient liés à X
								// se retrouvent automatiquement déliés côté serveur — on reflète ça dans l'UI.
								if (customerId > 0) {
									document.querySelectorAll('.cordespace-la-select').forEach(function (other) {
										if (other === sel) return;
										if (parseInt(other.value, 10) === customerId) {
											other.value = '0';
											other.dataset.previousValue = '0';
											var otherRow = other.closest('tr');
											setStatusCell(otherRow ? otherRow.querySelector('.cordespace-la-status') : null, false);
										}
									});
								}
							} else {
								sel.value = sel.dataset.previousValue || '0';
								cordespaceLaNotice('error', (data && data.data && data.data.message) || 'Erreur inconnue.');
							}
						})
						.catch(function (err) {
							sel.disabled = false;
							sel.value = sel.dataset.previousValue || '0';
							cordespaceLaNotice('error', 'Erreur réseau : ' + err.message);
						});
				});
			});

			function cordespaceLaNotice(type, message) {
				var wrap = document.querySelector('.cordespace-link-accounts-page h1');
				if (!wrap) return;
				document.querySelectorAll('.cordespace-link-accounts-page > .notice.cordespace-la-notice').forEach(function (n) { n.remove(); });
				var n = document.createElement('div');
				n.className = 'notice notice-' + type + ' is-dismissible cordespace-la-notice';
				var p = document.createElement('p');
				p.textContent = String(message);
				n.appendChild(p);
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'notice-dismiss';
				var sr = document.createElement('span');
				sr.className = 'screen-reader-text';
				sr.textContent = 'Ignorer ce message.';
				btn.appendChild(sr);
				btn.addEventListener('click', function () { n.remove(); });
				n.appendChild(btn);
				wrap.parentNode.insertBefore(n, wrap.nextSibling);
				setTimeout(function () { if (n.parentNode) n.remove(); }, 4000);
			}
		})();
		</script>
	</div>
	<?php
}

// ============================================================================
// 8) Endpoint AJAX : sauvegarde une liaison (bi-directionnelle)
// ============================================================================
add_action( 'wp_ajax_cordespace_save_link_accounts', 'cordespace_ajax_save_link_accounts' );

function cordespace_ajax_save_link_accounts(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Permission refusée.' ], 403 );
	}
	if ( ! check_ajax_referer( 'cordespace_link_accounts', '_wpnonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Nonce invalide. Recharge la page.' ], 400 );
	}

	$prof_id     = isset( $_POST['prof_id'] )     ? (int) $_POST['prof_id']     : 0;
	$customer_id = isset( $_POST['customer_id'] ) ? (int) $_POST['customer_id'] : 0;

	if ( $prof_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'ID prof invalide.' ], 400 );
	}
	if ( ! cordespace_user_is_amelia_provider( $prof_id ) ) {
		wp_send_json_error( [ 'message' => 'Cet user n\'est pas un·e prof Amelia.' ], 400 );
	}

	// Cliente précédemment liée au prof
	$prev_customer = (int) get_user_meta( $prof_id, '_cordespace_linked_user_id', true );

	if ( $customer_id === 0 ) {
		// Mode délier
		delete_user_meta( $prof_id, '_cordespace_linked_user_id' );
		if ( $prev_customer > 0 ) {
			delete_user_meta( $prev_customer, '_cordespace_linked_user_id' );
		}
		wp_send_json_success( [ 'message' => 'Liaison supprimée.' ] );
	}

	// Mode lier : valider que c'est bien un·e cliente Amelia
	global $wpdb;
	$is_customer = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}amelia_users
		  WHERE externalId = %d AND type = 'customer' AND status = 'visible'",
		$customer_id
	) );
	if ( $is_customer <= 0 ) {
		wp_send_json_error( [ 'message' => 'Cet user n\'est pas un·e client·e Amelia.' ], 400 );
	}

	// Si la cliente cible est déjà liée à un AUTRE prof, on délie côté cet autre prof
	$old_link_of_target_customer = (int) get_user_meta( $customer_id, '_cordespace_linked_user_id', true );
	if ( $old_link_of_target_customer > 0 && $old_link_of_target_customer !== $prof_id ) {
		delete_user_meta( $old_link_of_target_customer, '_cordespace_linked_user_id' );
	}

	// Si le prof change de cliente, l'ancienne cliente est déliée
	if ( $prev_customer > 0 && $prev_customer !== $customer_id ) {
		delete_user_meta( $prev_customer, '_cordespace_linked_user_id' );
	}

	// Pose les deux liaisons
	update_user_meta( $prof_id, '_cordespace_linked_user_id', $customer_id );
	update_user_meta( $customer_id, '_cordespace_linked_user_id', $prof_id );

	wp_send_json_success( [ 'message' => 'Liaison enregistrée des deux côtés.' ] );
}

/**
 * AJAX : réparer une liaison orpheline (UPDATE externalId).
 *
 * Action WP : cordespace_repair_orphan_customer
 * Params : amelia_id (entité Amelia customer à réparer), wp_user_id (WP user à lier)
 */
add_action( 'wp_ajax_cordespace_repair_orphan_customer', 'cordespace_ajax_repair_orphan_customer' );

function cordespace_ajax_repair_orphan_customer(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Permission refusée.' ], 403 );
	}
	if ( ! check_ajax_referer( 'cordespace_link_accounts', '_wpnonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Nonce invalide. Recharge la page.' ], 400 );
	}

	$amelia_id   = isset( $_POST['amelia_id'] ) ? (int) $_POST['amelia_id'] : 0;
	$wp_user_id  = isset( $_POST['wp_user_id'] ) ? (int) $_POST['wp_user_id'] : 0;

	if ( $amelia_id <= 0 || $wp_user_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'IDs invalides.' ], 400 );
	}

	global $wpdb;

	// Vérifier que l'entité Amelia existe, est bien customer, et que son
	// externalId est NULL (sinon refuser pour éviter d'écraser une liaison
	// déjà OK par erreur).
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, type, externalId, email
		   FROM {$wpdb->prefix}amelia_users
		  WHERE id = %d",
		$amelia_id
	), ARRAY_A );

	if ( ! $row ) {
		wp_send_json_error( [ 'message' => 'Entité Amelia introuvable.' ], 404 );
	}
	if ( $row['type'] !== 'customer' ) {
		wp_send_json_error( [ 'message' => 'Cette entité n\'est pas un·e client·e.' ], 400 );
	}
	if ( $row['externalId'] !== null ) {
		wp_send_json_error( [ 'message' => 'Cette entité a déjà une liaison.' ], 400 );
	}

	// Vérifier que le WP user existe ET qu'il match l'email (sécurité).
	$wp_user = get_user_by( 'ID', $wp_user_id );
	if ( ! $wp_user ) {
		wp_send_json_error( [ 'message' => 'User WP introuvable.' ], 404 );
	}
	if ( strcasecmp( $wp_user->user_email, (string) $row['email'] ) !== 0 ) {
		wp_send_json_error( [ 'message' => 'Les emails ne correspondent pas (sécurité).' ], 400 );
	}

	// UPDATE
	$updated = $wpdb->update(
		$wpdb->prefix . 'amelia_users',
		[ 'externalId' => $wp_user_id ],
		[ 'id' => $amelia_id ],
		[ '%d' ],
		[ '%d' ]
	);

	if ( $updated === false ) {
		wp_send_json_error( [ 'message' => 'Échec de l\'UPDATE en DB.' ], 500 );
	}

	wp_send_json_success( [
		'message'    => sprintf( 'Liaison réparée : Amelia #%d ↔ WP #%d (%s).', $amelia_id, $wp_user_id, $wp_user->user_email ),
		'amelia_id'  => $amelia_id,
		'wp_user_id' => $wp_user_id,
	] );
}
