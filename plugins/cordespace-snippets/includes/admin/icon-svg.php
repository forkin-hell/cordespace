<?php
/**
 * Icônes SVG candidates pour le menu wp-admin Cordespace.
 *
 * Trois nœuds de corde stylisés. Le choix par défaut est `figure-eight`,
 * filtrable via `cordespace_menu_icon_id`.
 *
 * Toutes les icônes utilisent `currentColor` pour s'adapter au thème
 * wp-admin (clair/sombre), comme attendu par add_menu_page().
 *
 * Voir docs/superpowers/specs/2026-05-18-cordespace-admin-toggles-design.md
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renvoie les 3 SVG candidats sous forme [id => svg_string].
 *
 * Ces strings sont des SVG complets prêts à être encodés en data URI
 * ou affichés inline (preview).
 */
if ( ! function_exists( 'cordespace_menu_icon_candidates' ) ) {
	function cordespace_menu_icon_candidates(): array {
		return [
			// Candidat 1 — Nœud carré (square knot)
			// Deux boucles entrelacées qui s'opposent.
			'square-knot' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
				<path d="M3 7 C 3 4, 7 4, 7 7 C 7 10, 11 10, 11 7 C 11 4, 15 4, 15 7"/>
				<path d="M3 13 C 3 16, 7 16, 7 13 C 7 10, 11 10, 11 13 C 11 16, 15 16, 15 13"/>
				<circle cx="10" cy="10" r="1" fill="currentColor"/>
			</svg>',

			// Candidat 2 — Nœud en huit (figure-eight) — DÉFAUT
			// Symbole infini légèrement noué : référence directe au shibari.
			'figure-eight' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
				<path d="M6 6 C 3 6, 3 10, 6 10 C 9 10, 11 14, 14 14 C 17 14, 17 10, 14 10 C 11 10, 9 6, 6 6 Z"/>
				<path d="M6 10 L 14 10" opacity="0.55"/>
			</svg>',

			// Candidat 3 — Boucle infinie (infinity loop)
			// Version minimaliste : 2 boucles en lemniscate.
			'infinity' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
				<path d="M6 10 C 6 7, 10 7, 10 10 C 10 13, 14 13, 14 10 C 14 7, 10 7, 10 10 C 10 13, 6 13, 6 10 Z"/>
			</svg>',
		];
	}
}

/**
 * Renvoie le data URI base64 du SVG choisi pour add_menu_page().
 *
 * Le filtre `cordespace_menu_icon_id` permet de switcher rapidement entre
 * `square-knot`, `figure-eight` (défaut) et `infinity` sans toucher au menu.
 */
if ( ! function_exists( 'cordespace_menu_icon_data_uri' ) ) {
	function cordespace_menu_icon_data_uri(): string {
		$candidates = cordespace_menu_icon_candidates();
		$id         = apply_filters( 'cordespace_menu_icon_id', 'figure-eight' );
		$svg        = $candidates[ $id ] ?? $candidates['figure-eight'];
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
