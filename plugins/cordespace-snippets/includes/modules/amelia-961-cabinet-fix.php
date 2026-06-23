<?php
/**
 * Module : mon-espace.amelia-961-cabinet-fix
 *
 * CONTOURNEMENT du crash du cabinet provider sous Amelia 9.6.1.
 *
 * Symptôme (9.6.1 uniquement) : le cabinet /mon-espace d'un prof se vide.
 * Console : « TypeError: Cannot read properties of null (reading 'id') » dans
 * injectProviderOptions (public.js). Le panneau Vue de 9.6.1 ne sait pas
 * résoudre certains co-profs des events → insère un `null` dans getEmployees →
 * `.filter`/`.find` lit null.id → crash. Amelia 9.5.1 n'a PAS ce bug
 * (build frontend différent, sans public.js).
 *
 * Parade (sans patcher Amelia) : sur la réponse /entities du frontend, on
 * réinjecte tous les providers visibles manquants en stubs minimalistes
 * (id + nom, listes/relations vidées, mot de passe vidé). Le Vue résout alors
 * tous les co-profs → plus de null → plus de crash. wp-admin jamais touché.
 *
 * ⚠️ QUAND L'ACTIVER :
 *   - UNIQUEMENT si Amelia est en 9.6.1 ET que le cabinet crashe.
 *   - En 9.5.1 : INUTILE, laisser DÉSACTIVÉ.
 *   - Sur une Amelia saine, ce module changerait le comportement du cabinet
 *     (il afficherait tous les providers dans le formulaire de réservation),
 *     donc on ne l'active que si nécessaire. D'où `default_active => false`.
 *
 * Vérifié : fait fonctionner le cabinet provider en 9.6.1 sur MySQL local.
 * À confirmer en prod (MariaDB) — d'où ce toggle pour tester en conditions
 * réelles sans forcer le comportement.
 *
 * Réversible : se désactive depuis wp-admin → Cordespace → Modules.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'amelia_get_entities_filter', 'cordespace_fix_entities_employees', 1000, 1 );

function cordespace_fix_entities_employees( $resultData ) {
	// Jamais en vraie page wp-admin : on ne modifie que le frontend (cabinet).
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $resultData;
	}
	if ( ! is_array( $resultData ) || empty( $resultData['employees'] ) || ! is_array( $resultData['employees'] ) ) {
		return $resultData;
	}

	// Gabarit = 1re fiche employé existante (structure Amelia complète à copier).
	$template = null;
	$present  = [];
	foreach ( $resultData['employees'] as $e ) {
		if ( is_array( $e ) && isset( $e['id'] ) ) {
			$present[ (int) $e['id'] ] = true;
			if ( $template === null ) {
				$template = $e;
			}
		}
	}
	if ( $template === null ) {
		return $resultData;
	}

	// Champs « liste »/relations à vider dans les stubs (pour ne pas propager les
	// services/horaires du gabarit aux autres profs).
	$blank_keys = [
		'serviceList', 'locationList', 'locationsList', 'weekDayList', 'weekDayOutput',
		'specialDayList', 'dayOffList', 'timeOutList', 'appointmentList', 'eventList',
		'periodList', 'daysOff', 'specialDays', 'weekDays',
	];

	global $wpdb;
	$all = $wpdb->get_results(
		"SELECT id, firstName, lastName, email FROM {$wpdb->prefix}amelia_users WHERE type = 'provider' AND status = 'visible'",
		ARRAY_A
	);
	foreach ( (array) $all as $p ) {
		$id = (int) $p['id'];
		if ( isset( $present[ $id ] ) ) {
			continue; // déjà dans la réponse
		}
		$stub              = $template;
		$stub['id']        = $id;
		$stub['firstName'] = (string) $p['firstName'];
		$stub['lastName']  = (string) ( $p['lastName'] ?? '' );
		$stub['email']     = (string) $p['email'];
		if ( array_key_exists( 'password', $stub ) ) {
			$stub['password'] = '';
		}
		foreach ( $blank_keys as $k ) {
			if ( array_key_exists( $k, $stub ) ) {
				$stub[ $k ] = [];
			}
		}
		$resultData['employees'][] = $stub;
		$present[ $id ]            = true;
	}

	return $resultData;
}
