<?php
/**
 * Module: event-gating-frontend-block
 *
 * Phase 4 du gating events : masque le bouton « Réservez votre place » sur le
 * frontend Amelia (calendrier, page event individuelle) pour les events gated
 * dont l'user n'est pas validé. Remplace par un encadré rouge « Validation
 * requise » avec le titre du bassin + bouton « En savoir plus ».
 *
 * Pourquoi ce module existe :
 *   event-gating-checkout-blocker bloque au panier/checkout WooCommerce, mais
 *   Amelia BYPASS WC pour les events GRATUITS (= il enregistre la réservation
 *   directement sans créer de panier WC). Du coup, sans ce module, les events
 *   gratuits gated peuvent être réservés par n'importe qui. Ici on intercepte
 *   en amont : on retire le bouton de réservation côté UI dès le calendrier.
 *
 * Architecture (UI-only, défense en profondeur) :
 *   1. PHP : collecte les events Amelia à venir qui sont gated pour l'user
 *      connecté (ou tous si anonyme). Pour chacun : récupère les types
 *      applicables, leur titre, et leur URL d'info.
 *   2. Injecte ces données dans `window.CordespaceEvgatingBlock` via
 *      wp_add_inline_script (footer).
 *   3. JS (vanilla) : MutationObserver sur document.body. Pour chaque
 *      bouton dont le texte contient « réserv » / « book », remonte au
 *      conteneur de la card, lit le titre, match contre la liste des events
 *      gated. Si match : cache le bouton, insère un encadré rouge devant
 *      construit via DOM methods (createElement + textContent, pas
 *      d'innerHTML pour éviter XSS).
 *   4. CSS : style de l'encadré, calqué sur la banderole cart/checkout.
 *
 * Ce qui est volontairement PAS dans l'encadré frontend :
 *   - Le content_html du bassin (le texte WYSIWYG type « Ton panier contient
 *     une ou plusieurs réservations... ») : pertinent dans la banderole
 *     cart/checkout où il y a la place, surdimensionné dans une card
 *     calendrier. La banderole cart/checkout reste affichée si malgré tout
 *     l'item arrive au panier (events payants), donc le texte complet reste
 *     accessible.
 *
 * Limites assumées :
 *   - UI seulement : ne bloque PAS au niveau de l'API REST Amelia. Un user
 *     averti peut contourner via requête directe. Le risque est faible (la
 *     plupart des gens passent par l'UI) mais réel. Le module checkout-blocker
 *     fait la défense côté serveur pour les events payants.
 *   - Détection par titre : si Amelia change le markup ou les classes, le JS
 *     peut casser. Sélecteurs volontairement larges pour résister aux refontes.
 *   - Pas de cache : la liste des events gated est recalculée à chaque page
 *     load. Pour < 100 events à venir c'est OK. Si ça devient lent, ajouter
 *     un transient invalidé sur set_tag_status / approval changes.
 *
 * Dépend de :
 *   - event-gating-checkout-blocker (helpers is_user_approved_for_type)
 *   - event-gating-cpt (helpers applicable_types_for_amelia_event,
 *     get_info_url, get_amelia_event_tags)
 *   - Amelia (table wphu_amelia_events + wphu_amelia_events_periods)
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// 1) Helpers — collecte des events gated pour l'user actuel
// ============================================================================

/**
 * L'event Amelia $event_id est-il gated pour l'user $user_id ?
 * (user_id = 0 → anonyme, considéré non-validé)
 *
 * Retourne true si :
 *   - L'event a au moins 1 type applicable (= un bassin matche un de ses tags)
 *   - ET l'user n'est validé dans AUCUN des types applicables
 *
 * Réutilise la même logique OR par type/tag que le checkout-blocker.
 */
function cordespace_evgating_is_event_gated_for_user( int $event_id, int $user_id ): bool {
	if ( $event_id <= 0 ) {
		return false;
	}
	if ( ! function_exists( 'cordespace_event_gating_applicable_types_for_amelia_event' ) ) {
		return false;
	}

	$applicable = cordespace_event_gating_applicable_types_for_amelia_event( $event_id );
	if ( empty( $applicable ) ) {
		return false; // aucun bassin ne s'applique → pas gated
	}

	if ( $user_id <= 0 ) {
		return true; // anonyme + bassins applicables → bloqué
	}

	$event_tags = function_exists( 'cordespace_event_gating_get_amelia_event_tags' )
		? cordespace_event_gating_get_amelia_event_tags( $event_id )
		: [];

	foreach ( $applicable as $type_id ) {
		if ( cordespace_evgating_is_user_approved_for_type( $user_id, (int) $type_id, $event_tags ) ) {
			return false; // approved dans au moins 1 type → libre
		}
	}
	return true;
}

/**
 * Récupère tous les events Amelia à venir qui sont gated pour l'user actuel.
 *
 * Pour chaque event gated, renvoie son nom (clé) + les types applicables avec
 * leur titre et leur URL d'info. Pas de HTML : tout est texte safe-by-default.
 *
 * Structure de retour :
 *   [
 *     'Nom de l\'event' => [
 *       'event_id' => 132,
 *       'types' => [
 *         [ 'id' => 1563, 'title' => 'Semi-privé', 'info_url' => 'https://...' ],
 *         ...
 *       ],
 *     ],
 *     ...
 *   ]
 */
function cordespace_evgating_collect_gated_events_for_frontend(): array {
	global $wpdb;

	// Tables Amelia : on prend les events approved avec au moins 1 période future
	$events_table  = $wpdb->prefix . 'amelia_events';
	$periods_table = $wpdb->prefix . 'amelia_events_periods';

	// Sécurité : si Amelia n'est pas installé, return vide
	$has_amelia = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events_table ) );
	if ( ! $has_amelia ) {
		return [];
	}

	$rows = $wpdb->get_results(
		"SELECT DISTINCT e.id, e.name
		 FROM {$events_table} e
		 INNER JOIN {$periods_table} p ON p.eventId = e.id
		 WHERE e.status = 'approved' AND p.periodStart >= NOW()",
		ARRAY_A
	);

	if ( empty( $rows ) ) {
		return [];
	}

	$user_id = get_current_user_id();
	$gated   = [];

	foreach ( $rows as $row ) {
		$event_id = (int) $row['id'];
		$name     = (string) $row['name'];
		if ( $event_id <= 0 || $name === '' ) {
			continue;
		}
		if ( ! cordespace_evgating_is_event_gated_for_user( $event_id, $user_id ) ) {
			continue;
		}

		$applicable = cordespace_event_gating_applicable_types_for_amelia_event( $event_id );
		$types_info = [];
		foreach ( $applicable as $type_id ) {
			$type_post = get_post( (int) $type_id );
			if ( ! $type_post || $type_post->post_status !== 'publish' ) {
				continue;
			}
			$types_info[] = [
				'id'       => (int) $type_id,
				'title'    => (string) get_the_title( $type_post ),
				'info_url' => function_exists( 'cordespace_event_gating_get_info_url' )
					? (string) cordespace_event_gating_get_info_url( (int) $type_id )
					: '',
			];
		}

		if ( empty( $types_info ) ) {
			continue;
		}

		$gated[ $name ] = [
			'event_id' => $event_id,
			'types'    => $types_info,
		];
	}

	return $gated;
}

// ============================================================================
// 2) Enqueue : JS + données + CSS dans le footer du frontend
// ============================================================================

/**
 * Inject la liste des events gated + le JS d'interception + le CSS.
 *
 * Hook sur wp_enqueue_scripts mais en priorité 20 pour s'assurer que les
 * handles WC/Amelia sont déjà enregistrés (au cas où on veuille en dépendre).
 */
function cordespace_evgating_frontend_block_enqueue(): void {
	if ( is_admin() ) {
		return;
	}

	$data = cordespace_evgating_collect_gated_events_for_frontend();
	if ( empty( $data ) ) {
		return; // rien à bloquer côté UI sur cette page
	}

	// Handle « factice » : pas de fichier physique, juste un porteur pour les
	// inline scripts/styles. Pattern utilisé par d'autres modules du plugin.
	wp_register_script( 'cordespace-evgating-frontend-block', '', [], '1.0.0', true );
	wp_enqueue_script( 'cordespace-evgating-frontend-block' );

	$payload = [
		'gatedEvents' => $data,
		'i18n'        => [
			'requiresValidation' => __( 'Validation requise pour réserver', 'cordespace-snippets' ),
			'learnMore'          => __( 'En savoir plus', 'cordespace-snippets' ),
			'lockIcon'           => '🔒',
			'banIcon'            => '⛔',
		],
	];
	wp_add_inline_script(
		'cordespace-evgating-frontend-block',
		'window.CordespaceEvgatingBlock = ' . wp_json_encode( $payload ) . ';',
		'before'
	);
	wp_add_inline_script(
		'cordespace-evgating-frontend-block',
		cordespace_evgating_frontend_block_js()
	);

	wp_register_style( 'cordespace-evgating-frontend-block', false, [], '1.0.0' );
	wp_enqueue_style( 'cordespace-evgating-frontend-block' );
	wp_add_inline_style(
		'cordespace-evgating-frontend-block',
		cordespace_evgating_frontend_block_css()
	);
}
add_action( 'wp_enqueue_scripts', 'cordespace_evgating_frontend_block_enqueue', 20 );

// ============================================================================
// 3) JS d'interception (MutationObserver + matching titre)
// ============================================================================

/**
 * Vanilla JS, no dependencies. Volontairement défensif :
 * - MutationObserver pour le SPA Amelia (Vue.js)
 * - Sélecteurs CSS larges (Amelia peut changer les classes)
 * - Matching titre par equality OU substring (résiste aux ajouts de date/heure)
 * - Idempotent : data-cordespace-blocked marque les éléments déjà traités
 * - DOM construction via createElement + textContent UNIQUEMENT (pas
 *   d'innerHTML) — toutes les strings sont traitées comme du texte par le
 *   navigateur, donc XSS-safe même si les données du back sont compromises.
 */
function cordespace_evgating_frontend_block_js(): string {
	return <<<'JS'
(function () {
	var DATA = window.CordespaceEvgatingBlock || {};
	var gated = DATA.gatedEvents || {};
	var i18n  = DATA.i18n || { requiresValidation: 'Validation requise', learnMore: 'En savoir plus', lockIcon: '🔒', banIcon: '⛔' };
	var gatedNames = Object.keys(gated);
	if (gatedNames.length === 0) return;

	// Normalise pour matcher des titres avec espaces/accents/casse variables
	function norm(s) {
		return (s || '').trim().toLowerCase().replace(/\s+/g, ' ');
	}
	var gatedNorm = gatedNames.map(function (n) { return { raw: n, norm: norm(n) }; });

	// Cherche l'event gated qui matche le texte d'un titre.
	function matchGated(titleText) {
		var t = norm(titleText);
		if (!t) return null;
		for (var i = 0; i < gatedNorm.length; i++) {
			var g = gatedNorm[i];
			if (t === g.norm || t.indexOf(g.norm) !== -1 || g.norm.indexOf(t) !== -1) {
				return { name: g.raw, data: gated[g.raw] };
			}
		}
		return null;
	}

	// Remonte les ancêtres jusqu'à trouver un container qui contient
	// un titre matchant un gated event. Retourne {card, match} ou null.
	function findCardForButton(btn) {
		var titleSelectors = 'h1, h2, h3, h4, h5, [class*="title" i], [class*="name" i], [class*="event-name" i]';
		var node = btn.parentElement;
		var depth = 0;
		while (node && node !== document.body && depth < 12) {
			var titleEls = node.querySelectorAll(titleSelectors);
			for (var i = 0; i < titleEls.length; i++) {
				var m = matchGated(titleEls[i].textContent || '');
				if (m) return { card: node, match: m };
			}
			node = node.parentElement;
			depth++;
		}
		return null;
	}

	// Construit l'encadré « Validation requise » via DOM methods uniquement.
	// AUCUN innerHTML : XSS-safe par construction.
	function buildBox(matchData) {
		var box = document.createElement('div');
		box.className = 'cordespace-evgating-frontend-block';
		box.setAttribute('role', 'note');

		var header = document.createElement('div');
		header.className = 'ceb-header';
		header.textContent = i18n.banIcon + ' ' + i18n.requiresValidation;
		box.appendChild(header);

		var types = matchData.types || [];
		for (var i = 0; i < types.length; i++) {
			var type = types[i];

			var typeBox = document.createElement('div');
			typeBox.className = 'ceb-type';

			var typeTitle = document.createElement('div');
			typeTitle.className = 'ceb-type-title';
			typeTitle.textContent = i18n.lockIcon + ' ' + (type.title || '');
			typeBox.appendChild(typeTitle);

			if (type.info_url) {
				var btn = document.createElement('a');
				btn.className = 'ceb-info-btn';
				btn.href = type.info_url;
				btn.target = '_blank';
				btn.rel = 'noopener noreferrer';
				btn.textContent = 'ℹ️ ' + i18n.learnMore;
				typeBox.appendChild(btn);
			}

			box.appendChild(typeBox);
		}

		return box;
	}

	function processButtons(root) {
		var scope = root && root.querySelectorAll ? root : document;
		var candidates = scope.querySelectorAll('button, a, [role="button"], [class*="button" i]');
		for (var i = 0; i < candidates.length; i++) {
			var btn = candidates[i];
			if (btn.dataset && btn.dataset.cordespaceBlocked) continue;
			var txt = (btn.textContent || '').toLowerCase();
			var isReserveBtn = (
				txt.indexOf('réserv') !== -1 ||
				txt.indexOf('reserv') !== -1 ||
				txt.indexOf('book now') !== -1 ||
				/(^|\s)book(\s|$)/.test(txt)
			);
			if (!isReserveBtn) continue;

			var found = findCardForButton(btn);
			if (!found) continue;

			// Marque traité (idempotent même si MutationObserver re-passe)
			if (btn.dataset) btn.dataset.cordespaceBlocked = '1';

			// Cache le bouton sans le retirer (Amelia peut le re-monter sinon)
			btn.style.display = 'none';

			// Évite les doublons si une autre passe a déjà inséré pour cette card
			var name = found.match.name;
			var safeName = name.replace(/"/g, '\\"');
			var existing = found.card.querySelector('.cordespace-evgating-frontend-block[data-cordespace-blocked-for="' + safeName + '"]');
			if (existing) continue;

			var box = buildBox(found.match.data);
			box.setAttribute('data-cordespace-blocked-for', name);

			// Insère juste avant le bouton (= remplace visuellement le CTA)
			var anchor = btn.parentNode || found.card;
			if (anchor.parentNode) {
				anchor.parentNode.insertBefore(box, anchor);
			} else {
				found.card.appendChild(box);
			}
		}
	}

	function run() {
		try { processButtons(document); } catch (e) { /* swallow */ }
	}

	// Passe initiale
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}

	// MutationObserver pour le SPA Amelia : le calendrier monte ses cards
	// après le ready DOM. Throttle léger pour éviter les boucles.
	var pending = false;
	var observer = new MutationObserver(function () {
		if (pending) return;
		pending = true;
		setTimeout(function () { pending = false; run(); }, 80);
	});
	observer.observe(document.body, { childList: true, subtree: true });
})();
JS;
}

// ============================================================================
// 4) CSS (calqué sur la banderole cart/checkout, en plus compact)
// ============================================================================

function cordespace_evgating_frontend_block_css(): string {
	return <<<'CSS'
.cordespace-evgating-frontend-block {
	margin: 0.6rem 0;
	padding: 0.9rem 1rem;
	background: #fdecea;
	border: 2px solid #d63638;
	border-left: 6px solid #d63638;
	border-radius: 6px;
	color: #3c1c1c;
	font-size: 0.95em;
	line-height: 1.4;
}
.cordespace-evgating-frontend-block .ceb-header {
	font-weight: 700;
	font-size: 1.05em;
	margin: 0 0 0.5rem;
}
.cordespace-evgating-frontend-block .ceb-type {
	margin: 0.4rem 0 0;
	padding: 0.6rem 0.8rem;
	background: rgba(255, 255, 255, 0.55);
	border-radius: 4px;
}
.cordespace-evgating-frontend-block .ceb-type-title {
	font-weight: 600;
	margin: 0 0 0.4rem;
}
.cordespace-evgating-frontend-block .ceb-info-btn {
	display: inline-block;
	margin-top: 0.2rem;
	padding: 0.4rem 0.9rem;
	background: #1a1a2e;
	color: #fff !important;
	text-decoration: none;
	border-radius: 4px;
	font-size: 0.9em;
	font-weight: 600;
}
.cordespace-evgating-frontend-block .ceb-info-btn:hover,
.cordespace-evgating-frontend-block .ceb-info-btn:focus {
	background: #2a2a4e;
	color: #fff !important;
}
CSS;
}
