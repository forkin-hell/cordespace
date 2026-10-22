<?php
/**
 * Module waivers.partners — partenaires des réservations à plusieurs.
 *
 * Sur toute réservation Amelia à venir pour plus d'une personne, l'acheteur·euse déclare ses
 * partenaires (nom + courriel) dans Mon espace ; sur les cours à waiver, chaque partenaire reçoit
 * un courriel et signe elle-même, sans compte ; la prof voit les noms et qui n'a pas signé ; le
 * rappel 48 h couvre les partenaires.
 *
 * Table {prefix}cordespace_waiver_partners : une ligne par « place partenaire » (réservation × n°).
 * Voir docs/superpowers/specs/2026-10-22-waivers-partenaires-design.md.
 */
defined( 'ABSPATH' ) || exit;

const CORDESPACE_WAIVERS_PARTNERS_SCHEMA_VERSION = 1;

function cordespace_waivers_partners_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . 'cordespace_waiver_partners';
}

function cordespace_waivers_partners_install_table(): void {
	global $wpdb;
	$table           = cordespace_waivers_partners_table_name();
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		booking_id BIGINT UNSIGNED NOT NULL,
		slot_no SMALLINT UNSIGNED NOT NULL,
		event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		host_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		partner_name VARCHAR(255) NOT NULL DEFAULT '',
		partner_email VARCHAR(255) NOT NULL DEFAULT '',
		token VARCHAR(64) NOT NULL DEFAULT '',
		invited_at DATETIME NULL,
		reminded_at DATETIME NULL,
		declined_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY uq_booking_slot (booking_id, slot_no),
		KEY idx_email (partner_email),
		KEY idx_event (event_id)
	) {$charset_collate};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( 'cordespace_waivers_partners_schema_version', CORDESPACE_WAIVERS_PARTNERS_SCHEMA_VERSION );
}

add_action( 'admin_init', static function (): void {
	$stored = (int) get_option( 'cordespace_waivers_partners_schema_version', 0 );
	if ( $stored < CORDESPACE_WAIVERS_PARTNERS_SCHEMA_VERSION ) {
		cordespace_waivers_partners_install_table();
	}
} );

// ─── Places partenaire : calcul, création, enregistrement ───────────────────

/**
 * Mots-clés qui désignent un billet couvrant deux personnes (filtre cordespace_waivers_duo_keywords).
 */
function cordespace_waivers_duo_keywords(): array {
	$keywords = (array) apply_filters( 'cordespace_waivers_duo_keywords', [ 'duo' ] );
	return array_values( array_filter( array_map( 'strval', $keywords ), static fn( string $k ): bool => trim( $k ) !== '' ) );
}

/** Minuscules sans accents, pour comparer des noms de billets. */
function cordespace_waivers_fold( string $s ): string {
	if ( function_exists( 'remove_accents' ) ) {
		$s = remove_accents( $s );
	}
	return strtolower( trim( $s ) );
}

/** Nombre de personnes couvertes par un billet : 2 si son nom contient un mot-clé « Duo », sinon 1. */
function cordespace_waivers_ticket_covers( string $ticket_name ): int {
	$name = cordespace_waivers_fold( $ticket_name );
	foreach ( cordespace_waivers_duo_keywords() as $keyword ) {
		if ( str_contains( $name, cordespace_waivers_fold( $keyword ) ) ) {
			return 2;
		}
	}
	return 1;
}

/**
 * Nombre de personnes d'une réservation (fonction pure) : Σ persons × couverture par ligne de billet ;
 * sans ligne de billet, `persons` de la réservation ; minimum 1.
 *
 * @param array $ticket_lines liste de [ 'name' => string, 'persons' => int ]
 */
function cordespace_waivers_booking_people_count( array $ticket_lines, int $persons ): int {
	if ( ! $ticket_lines ) {
		return max( 1, $persons );
	}
	$total = 0;
	foreach ( $ticket_lines as $line ) {
		$total += max( 0, (int) ( $line['persons'] ?? 0 ) ) * cordespace_waivers_ticket_covers( (string) ( $line['name'] ?? '' ) );
	}
	return max( 1, $total );
}

/** Nombre de personnes d'une réservation Amelia (lecture en base). */
function cordespace_waivers_booking_people( int $booking_id ): int {
	global $wpdb;
	$p     = $wpdb->prefix;
	$lines = $wpdb->get_results( $wpdb->prepare(
		"SELECT t.name, bt.persons FROM {$p}amelia_customer_bookings_to_events_tickets bt
		 JOIN {$p}amelia_events_to_tickets t ON t.id = bt.eventTicketId
		 WHERE bt.customerBookingId = %d",
		$booking_id
	), ARRAY_A );
	$persons = (int) $wpdb->get_var( $wpdb->prepare( "SELECT persons FROM {$p}amelia_customer_bookings WHERE id = %d", $booking_id ) );
	return cordespace_waivers_booking_people_count( is_array( $lines ) ? $lines : [], $persons );
}

/** Fin de la dernière séance d'une réservation (UTC, « Y-m-d H:i:s »), null si inconnue. */
function cordespace_waivers_booking_last_end( int $booking_id ): ?string {
	global $wpdb;
	$p   = $wpdb->prefix;
	$end = $wpdb->get_var( $wpdb->prepare(
		"SELECT MAX(ep.periodEnd) FROM {$p}amelia_customer_bookings_to_events_periods bp
		 JOIN {$p}amelia_events_periods ep ON ep.id = bp.eventPeriodId
		 WHERE bp.customerBookingId = %d",
		$booking_id
	) );
	return $end ? (string) $end : null;
}

/** Ligne de la table → tableau typé. */
function cordespace_waivers_partner_normalize_row( array $r ): array {
	$dt = static fn( $v ): ?string => ( $v === null || $v === '' || $v === '0000-00-00 00:00:00' ) ? null : (string) $v;
	return [
		'id'            => (int) $r['id'],
		'booking_id'    => (int) $r['booking_id'],
		'slot_no'       => (int) $r['slot_no'],
		'event_id'      => (int) $r['event_id'],
		'host_user_id'  => (int) $r['host_user_id'],
		'partner_name'  => (string) $r['partner_name'],
		'partner_email' => (string) $r['partner_email'],
		'token'         => (string) $r['token'],
		'invited_at'    => $dt( $r['invited_at'] ?? null ),
		'reminded_at'   => $dt( $r['reminded_at'] ?? null ),
		'declined_at'   => $dt( $r['declined_at'] ?? null ),
		'created_at'    => (string) ( $r['created_at'] ?? '' ),
		'updated_at'    => (string) ( $r['updated_at'] ?? '' ),
	];
}

/**
 * Places partenaire d'une réservation (personnes − 1), créées à la volée si elles manquent.
 * Retourne les lignes triées par slot_no ; [] si la réservation est pour une seule personne.
 */
function cordespace_waivers_partner_slots( int $booking_id, int $event_id, int $host_user_id ): array {
	global $wpdb;
	$table = cordespace_waivers_partners_table_name();
	$n     = cordespace_waivers_booking_people( $booking_id ) - 1;
	if ( $booking_id <= 0 || $n <= 0 ) {
		return [];
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY slot_no", $booking_id ), ARRAY_A );
	$rows = is_array( $rows ) ? $rows : [];
	$have = array_map( 'intval', array_column( $rows, 'slot_no' ) );
	$now  = gmdate( 'Y-m-d H:i:s' );
	$new  = false;
	for ( $slot = 1; $slot <= $n; $slot++ ) {
		if ( in_array( $slot, $have, true ) ) {
			continue;
		}
		$wpdb->insert( $table, [
			'booking_id'   => $booking_id,
			'slot_no'      => $slot,
			'event_id'     => $event_id,
			'host_user_id' => $host_user_id,
			'token'        => wp_generate_password( 32, false ),
			'created_at'   => $now,
			'updated_at'   => $now,
		] );
		$new = true;
	}
	if ( $host_user_id > 0 ) {
		foreach ( $rows as $r ) {
			if ( (int) $r['host_user_id'] === 0 ) {
				$wpdb->update( $table, [ 'host_user_id' => $host_user_id ], [ 'id' => (int) $r['id'] ] );
				$new = true;
			}
		}
	}
	if ( $new ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY slot_no", $booking_id ), ARRAY_A );
	}
	return array_map( 'cordespace_waivers_partner_normalize_row', is_array( $rows ) ? $rows : [] );
}

function cordespace_waivers_partner_get( int $partner_id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . cordespace_waivers_partners_table_name() . ' WHERE id = %d', $partner_id ), ARRAY_A );
	return $row ? cordespace_waivers_partner_normalize_row( $row ) : null;
}

/**
 * Enregistre le nom et le courriel d'une place. Seule la personne hôte (ou l'équipe) peut le faire.
 * Un changement de courriel régénère le jeton et remet l'invitation, le rappel et le déclin à zéro ;
 * un changement de nom seul ne touche à rien d'autre.
 *
 * @return array|WP_Error  codes : not_found, forbidden, invalid_email, own_email
 */
function cordespace_waivers_partner_set( int $partner_id, string $name, string $email, int $acting_user_id ) {
	global $wpdb;
	$row = cordespace_waivers_partner_get( $partner_id );
	if ( $row === null ) {
		return new WP_Error( 'not_found', __( 'Cette place n\'existe pas.', 'cordespace-snippets' ) );
	}
	$is_host  = $row['host_user_id'] === $acting_user_id;
	$is_admin = $acting_user_id > 0 && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	if ( ! $is_host && ! $is_admin ) {
		return new WP_Error( 'forbidden', __( 'Tu ne peux modifier que tes propres réservations.', 'cordespace-snippets' ) );
	}
	$email = cordespace_waivers_normalize_email( $email );
	if ( $email !== '' && ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Ce courriel n\'est pas valide.', 'cordespace-snippets' ) );
	}
	if ( $email !== '' && $row['host_user_id'] > 0 ) {
		$host = get_userdata( $row['host_user_id'] );
		if ( $host && cordespace_waivers_normalize_email( (string) $host->user_email ) === $email ) {
			return new WP_Error( 'own_email', __( 'Entre le courriel de l\'autre personne, pas le tien.', 'cordespace-snippets' ) );
		}
	}
	$name = mb_substr( trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $name ) : strip_tags( $name ) ), 0, 255 );
	$data = [ 'partner_name' => $name, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ];
	if ( $email !== $row['partner_email'] ) {
		$data['partner_email'] = $email;
		$data['token']         = wp_generate_password( 32, false );
		$data['invited_at']    = null;
		$data['reminded_at']   = null;
		$data['declined_at']   = null;
	}
	$wpdb->update( cordespace_waivers_partners_table_name(), $data, [ 'id' => $partner_id ] );
	return cordespace_waivers_partner_get( $partner_id );
}

/** Clé des liens (signature, déclin) : HMAC de id|courriel|jeton, 32 caractères. */
function cordespace_waivers_partner_key( array $partner ): string {
	return substr( hash_hmac( 'sha256', (int) $partner['id'] . '|' . $partner['partner_email'] . '|' . $partner['token'], wp_salt( 'auth' ) ), 0, 32 );
}
