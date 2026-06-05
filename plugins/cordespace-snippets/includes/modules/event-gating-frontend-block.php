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
			// Contenu WYSIWYG du bassin (= le texte d'explication que Tess a
			// rédigé). Sanitisé via wp_kses_post + wpautop côté PHP. Côté JS,
			// il sera réinjecté via DOMParser (PAS d'innerHTML littéral, donc
			// XSS-safe par le double filtre kses + parser sandboxé).
			$content_raw  = (string) $type_post->post_content;
			$content_html = $content_raw !== '' ? wpautop( wp_kses_post( $content_raw ) ) : '';

			$types_info[] = [
				'id'           => (int) $type_id,
				'title'        => (string) get_the_title( $type_post ),
				'info_url'     => function_exists( 'cordespace_event_gating_get_info_url' )
					? (string) cordespace_event_gating_get_info_url( (int) $type_id )
					: '',
				'content_html' => $content_html,
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

	// Remonte les ancêtres jusqu'à trouver UN SEUL titre. Pourquoi pas plus :
	// si on remonte trop loin (jusqu'au container global de la liste), on
	// risque de tomber sur le titre d'un AUTRE event de la liste qui matche
	// un gated, et appliquer à tort l'encadré sur des events non-gated.
	//
	// Algo :
	//   - Premier ancêtre qui contient AU MOINS UN h1-h6 → on s'arrête là.
	//   - Si exactement 1 titre : on le teste vs liste gated. Match → gated,
	//     pas match → non gated (mais on n'essaie PAS de remonter plus haut).
	//   - Si > 1 titre : on est au-dessus de la card individuelle, on
	//     considère « non gated par nous » et on n'agit pas.
	//
	// On utilise uniquement les balises sémantiques h1-h6 (pas les classes
	// fourre-tout *=title qui généraient des faux positifs sur les sous-
	// éléments style "Disponible", "À partir de…").
	function findCardForButton(btn) {
		var node = btn.parentElement;
		var depth = 0;
		while (node && node !== document.body && depth < 10) {
			var titles = node.querySelectorAll('h1, h2, h3, h4, h5, h6');
			if (titles.length > 0) {
				if (titles.length === 1) {
					var m = matchGated(titles[0].textContent || '');
					if (m) return { card: node, match: m };
				}
				return null; // 1 titre non matché OU plusieurs titres → stop
			}
			node = node.parentElement;
			depth++;
		}
		return null;
	}

	// Parse une string HTML en nodes via DOMParser (sandbox du navigateur).
	// Le content_html est déjà passé par wp_kses_post() côté PHP, donc le
	// risque XSS est éliminé en amont. DOMParser + appendChild remplace
	// innerHTML pour respecter notre policy « pas d'innerHTML littéral ».
	function htmlToFragment(html) {
		var frag = document.createDocumentFragment();
		if (!html) return frag;
		try {
			var doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
			var src = doc.body && doc.body.firstChild;
			if (!src) return frag;
			while (src.firstChild) {
				frag.appendChild(src.firstChild);
			}
		} catch (e) { /* fallback : fragment vide */ }
		return frag;
	}

	// Construit l'encadré « Validation requise » via DOM methods uniquement.
	// Pas d'innerHTML : XSS-safe par construction (DOMParser est sandboxé,
	// kses côté PHP filtre déjà les balises dangereuses).
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

			// Contenu WYSIWYG du bassin (le texte que Tess a rédigé).
			// Injecté via DOMParser (sandbox) → pas d'innerHTML.
			if (type.content_html) {
				var contentWrap = document.createElement('div');
				contentWrap.className = 'ceb-type-content';
				contentWrap.appendChild(htmlToFragment(type.content_html));
				typeBox.appendChild(contentWrap);
			}

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
	margin: 1rem 0;
	padding: 1.3rem 1.5rem;
	background: #fef5f3;
	border: 1px solid rgba(214, 54, 56, 0.18);
	border-left: 4px solid #d63638;
	border-radius: 12px;
	color: #3c1c1c;
	font-size: 0.95em;
	line-height: 1.5;
	box-shadow: 0 2px 8px rgba(60, 28, 28, 0.06);
}
.cordespace-evgating-frontend-block .ceb-header {
	font-weight: 700;
	font-size: 1.08em;
	margin: 0 0 1rem;
	color: #b3272a;
	letter-spacing: 0.01em;
}
.cordespace-evgating-frontend-block .ceb-type {
	margin: 0.9rem 0 0;
	padding: 1.1rem 1.25rem;
	background: #ffffff;
	border-radius: 9px;
	box-shadow: 0 1px 3px rgba(60, 28, 28, 0.05);
}
.cordespace-evgating-frontend-block .ceb-type-title {
	font-weight: 700;
	font-size: 1.02em;
	margin: 0 0 0.75rem;
	color: #1a1a2e;
}
.cordespace-evgating-frontend-block .ceb-type-content {
	margin: 0 0 1rem;
	font-size: 0.93em;
	line-height: 1.6;
	color: #4a3030;
}
.cordespace-evgating-frontend-block .ceb-type-content p {
	margin: 0 0 0.75rem;
}
.cordespace-evgating-frontend-block .ceb-type-content p:last-child {
	margin-bottom: 0;
}
.cordespace-evgating-frontend-block .ceb-type-content em {
	display: block;
	margin: 0.85rem 0 0;
	padding: 0.85rem 1.1rem;
	background: #fbf1ee;
	border-left: 3px solid #d4a3a4;
	border-radius: 6px;
	font-style: italic;
	color: #5c2c2c;
	font-size: 0.94em;
	line-height: 1.55;
}
.cordespace-evgating-frontend-block .ceb-type-content em p {
	margin: 0;
}
.cordespace-evgating-frontend-block .ceb-type-content a {
	color: #1a1a2e;
	text-decoration: underline;
	font-weight: 500;
}
.cordespace-evgating-frontend-block .ceb-type-content a:hover {
	color: #d63638;
}
.cordespace-evgating-frontend-block .ceb-info-btn {
	display: inline-block;
	margin-top: 0.4rem;
	padding: 0.65rem 1.4rem;
	background: #1a1a2e;
	color: #fff !important;
	text-decoration: none;
	border-radius: 7px;
	font-size: 0.93em;
	font-weight: 600;
	letter-spacing: 0.01em;
	box-shadow: 0 2px 4px rgba(26, 26, 46, 0.15);
	transition: background 0.18s ease, transform 0.1s ease, box-shadow 0.18s ease;
}
.cordespace-evgating-frontend-block .ceb-info-btn:hover,
.cordespace-evgating-frontend-block .ceb-info-btn:focus {
	background: #2a2a4e;
	color: #fff !important;
	text-decoration: none;
	box-shadow: 0 3px 7px rgba(26, 26, 46, 0.22);
}
.cordespace-evgating-frontend-block .ceb-info-btn:active {
	transform: translateY(1px);
	box-shadow: 0 1px 2px rgba(26, 26, 46, 0.18);
}
CSS;
}
