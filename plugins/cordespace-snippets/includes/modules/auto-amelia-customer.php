<?php
/**
 * Module : auto-amelia-customer
 *
 * Crée automatiquement une entité Amelia customer chaque fois qu'un user WP
 * est créé (inscription via /mon-compte/, via WC checkout, via wp-admin, etc.).
 * Lie l'entité au user WP via le champ externalId.
 *
 * Avant ce module : Amelia créait l'entité customer SEULEMENT au moment de la
 * première réservation. Du coup les nouveaux comptes WP voyaient un message
 * "tu n'es pas connecté·e à Amelia" dans /mon-espace/ tant qu'iels n'avaient
 * pas acheté quoi que ce soit — UX confus.
 *
 * Avec ce module : dès l'inscription, l'entité Amelia existe → /mon-espace/
 * affiche le panneau Amelia normalement (vide mais fonctionnel).
 *
 * Defensive : wrappé en try/catch + check d'orphan email préalable.
 * Si l'INSERT échoue (ex: schéma Amelia change après une maj), on log mais
 * on ne casse PAS l'inscription WP. Le user existe, juste sans entité Amelia
 * → Amelia créera l'entité elle-même à la première réservation (fallback
 * historique).
 *
 * Dépend de : plugin Amelia (table wphu_amelia_users).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'user_register', 'cordespace_auto_create_amelia_customer', 20, 1 );

function cordespace_auto_create_amelia_customer( int $user_id ): void {
	if ( $user_id <= 0 ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'amelia_users';

	// Vérification que la table existe (Amelia désinstallé ? table renommée ?)
	$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $table_exists ) {
		return; // Amelia absent, on skip silencieusement
	}

	$user = get_userdata( $user_id );
	if ( ! $user || empty( $user->user_email ) ) {
		return;
	}

	try {
		// 1. Skip si une entité Amelia existe déjà pour ce user_id (idempotent)
		$existing_by_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE externalId = %d LIMIT 1",
			$user_id
		) );
		if ( $existing_by_id ) {
			return;
		}

		// 2. Si une entité Amelia orpheline existe avec le même email (externalId NULL),
		//    on la "répare" en lui attribuant ce user_id plutôt que d'en créer une 2e
		$orphan = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table}
			  WHERE email = %s
			    AND externalId IS NULL
			    AND type = 'customer'
			  LIMIT 1",
			$user->user_email
		) );
		if ( $orphan ) {
			$wpdb->update(
				$table,
				[ 'externalId' => $user_id ],
				[ 'id' => (int) $orphan ],
				[ '%d' ],
				[ '%d' ]
			);
			return;
		}

		// 3. Sinon, on crée une nouvelle entité customer
		$first_name = $user->first_name ?: ( $user->display_name ?: '' );
		$last_name  = $user->last_name  ?: '';

		$ok = $wpdb->insert(
			$table,
			[
				'externalId' => $user_id,
				'type'       => 'customer',
				'status'     => 'visible',
				'firstName'  => $first_name,
				'lastName'   => $last_name,
				'email'      => $user->user_email,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( $ok === false ) {
			// Log mais ne pas casser l'inscription WP
			error_log( sprintf(
				'[cordespace auto-amelia] INSERT failed for user %d (%s): %s',
				$user_id,
				$user->user_email,
				$wpdb->last_error
			) );
		}
	} catch ( \Throwable $e ) {
		// Filet de sécurité ultime : on attrape TOUT pour ne jamais casser
		// la création de l'utilisateur·trice WP. L'admin verra l'erreur dans
		// les logs ; Amelia créera l'entité à la première réservation.
		error_log( sprintf(
			'[cordespace auto-amelia] Exception for user %d: %s',
			$user_id,
			$e->getMessage()
		) );
	}
}
