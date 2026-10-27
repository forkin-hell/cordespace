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
	// Si le nombre de personnes a baissé (billet changé), les places excédentaires restent en base mais ne comptent plus.
	$rows = array_values( array_filter( is_array( $rows ) ? $rows : [], static fn( $r ) => (int) $r['slot_no'] <= $n ) );
	return array_map( 'cordespace_waivers_partner_normalize_row', $rows );
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
function cordespace_waivers_partner_set( int $partner_id, string $name, string $email, int $acting_user_id, bool $staff = false ) {
	global $wpdb;
	$row = cordespace_waivers_partner_get( $partner_id );
	if ( $row === null ) {
		return new WP_Error( 'not_found', __( 'Cette place n\'existe pas.', 'cordespace-snippets' ) );
	}
	// Jamais « ouvert par défaut » : l'hôte doit être connecté·e et correspondre ; l'équipe passe $staff = true
	// après ses propres vérifications de droits (réservations faites en admin, hôte sans compte).
	$is_host  = $acting_user_id > 0 && $row['host_user_id'] === $acting_user_id;
	$is_admin = $staff || ( $acting_user_id > 0 && function_exists( 'user_can' ) && user_can( $acting_user_id, 'manage_options' ) );
	if ( ! $is_host && ! $is_admin ) {
		return new WP_Error( 'forbidden', __( 'Tu ne peux modifier que tes propres réservations.', 'cordespace-snippets' ) );
	}
	$email = cordespace_waivers_normalize_email( $email );
	if ( $email !== '' && ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Ce courriel n\'est pas valide.', 'cordespace-snippets' ) );
	}
	if ( $email !== '' ) {
		// Ni le courriel de l'hôte (compte WordPress ou fiche Amelia de la réservation), ni une adresse déjà sur une autre place.
		$host         = $row['host_user_id'] > 0 ? get_userdata( $row['host_user_id'] ) : false;
		$amelia_email = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT u.email FROM {$wpdb->prefix}amelia_users u JOIN {$wpdb->prefix}amelia_customer_bookings b ON b.customerId = u.id WHERE b.id = %d",
			$row['booking_id']
		) );
		if ( ( $host && cordespace_waivers_normalize_email( (string) $host->user_email ) === $email ) || cordespace_waivers_normalize_email( $amelia_email ) === $email ) {
			return new WP_Error( 'own_email', __( 'Entre le courriel de l\'autre personne, pas le tien.', 'cordespace-snippets' ) );
		}
		$dup = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . cordespace_waivers_partners_table_name() . ' WHERE booking_id = %d AND id <> %d AND LOWER(partner_email) = %s LIMIT 1',
			$row['booking_id'], $partner_id, $email
		) );
		if ( $dup ) {
			return new WP_Error( 'duplicate_email', __( 'Ce courriel est déjà sur une autre place de cette réservation.', 'cordespace-snippets' ) );
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

// ─── Courriel d'invitation (gabarit par waiver, composition, envoi, relance) ─

const CORDESPACE_WAIVER_META_PARTNER_INVITE_SUBJECT = '_cordespace_waiver_partner_invite_subject';
const CORDESPACE_WAIVER_META_PARTNER_INVITE_BODY    = '_cordespace_waiver_partner_invite_body';

function cordespace_waivers_partner_invite_defaults(): array {
	return [
		'subject' => "{prenom_hote} t'invite à signer un document pour {cours}",
		'body'    => "Bonjour {prenom_partenaire},\n\n{prenom_hote} a réservé une place pour toi au cours {cours}, {date}. Pour y participer, Cordespace te demande de lire et signer le document « {titre_waiver} » : ça prend deux minutes, aucun compte n'est nécessaire.\n\n{lien_signature}\n\nSi finalement tu ne viens pas : {lien_declin}\n\nL'équipe Cordespace",
	];
}

/** Sujet et corps du courriel d'invitation d'un waiver (métas, sinon défauts). */
function cordespace_waivers_partner_invite_texts( int $waiver_id ): array {
	$d       = cordespace_waivers_partner_invite_defaults();
	$subject = trim( (string) get_post_meta( $waiver_id, CORDESPACE_WAIVER_META_PARTNER_INVITE_SUBJECT, true ) );
	$body    = trim( (string) get_post_meta( $waiver_id, CORDESPACE_WAIVER_META_PARTNER_INVITE_BODY, true ) );
	return [ 'subject' => $subject !== '' ? $subject : $d['subject'], 'body' => $body !== '' ? $body : $d['body'] ];
}

/**
 * Compose le courriel : remplacement des mots-clés, puis mise en forme HTML (même mécanique que le rappel).
 * Les valeurs de {lien_signature} et {lien_declin} peuvent contenir du HTML (lien cliquable).
 */
function cordespace_waivers_compose_partner_invite( int $waiver_id, array $placeholders ): array {
	$t       = cordespace_waivers_partner_invite_texts( $waiver_id );
	$plain   = array_map( static fn( $v ): string => wp_strip_all_tags( (string) $v ), $placeholders );
	$subject = wp_strip_all_tags( strtr( $t['subject'], $plain ) );
	// Le gabarit est mis en forme AVEC ses mots-clés (texte inerte), puis les valeurs sont injectées :
	// les textes échappés (aucun HTML venant d'un nom, d'un cours ou d'un titre), les deux liens tels quels.
	$trusted = [ '{lien_signature}', '{lien_declin}' ];
	$safe    = [];
	foreach ( $placeholders as $key => $value ) {
		$safe[ $key ] = in_array( $key, $trusted, true ) ? (string) $value : esc_html( wp_strip_all_tags( (string) $value ) );
	}
	$html = function_exists( 'cordespace_waivers_wrap_custom_email_body' ) ? cordespace_waivers_wrap_custom_email_body( $t['body'] ) : nl2br( esc_html( $t['body'] ) );
	return [ 'subject' => $subject, 'body' => strtr( $html, $safe ) ];
}

/** Lien de signature partenaire (page de signature en mode partenaire). */
function cordespace_waivers_partner_sign_url( array $partner, int $waiver_id ): string {
	$base = function_exists( 'cordespace_waivers_get_sign_url' )
		? cordespace_waivers_get_sign_url( $waiver_id )
		: add_query_arg( [ 'cordespace_sign_waiver' => '1', 'waiver_id' => $waiver_id ], home_url( '/' ) );
	return add_query_arg( [ 'partner' => (int) $partner['id'], 'k' => cordespace_waivers_partner_key( $partner ) ], $base );
}

/** Lien « je ne viens pas ». */
function cordespace_waivers_partner_decline_url( array $partner ): string {
	return home_url( '/?cordespace_partner_decline=' . (int) $partner['id'] . '&k=' . cordespace_waivers_partner_key( $partner ) );
}

/** Nom et première séance du cours d'une place : [ 'name' => string, 'start' => ?string UTC ]. */
function cordespace_waivers_partner_event_info( array $partner ): array {
	global $wpdb;
	$p    = $wpdb->prefix;
	$name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$p}amelia_events WHERE id = %d", (int) $partner['event_id'] ) );
	$start = $wpdb->get_var( $wpdb->prepare(
		"SELECT MIN(ep.periodStart) FROM {$p}amelia_customer_bookings_to_events_periods bp
		 JOIN {$p}amelia_events_periods ep ON ep.id = bp.eventPeriodId WHERE bp.customerBookingId = %d",
		(int) $partner['booking_id']
	) );
	return [ 'name' => $name, 'start' => $start ? (string) $start : null ];
}

/** Prénom affichable : premier mot d'un nom, ou repli. */
function cordespace_waivers_first_name( string $full, string $fallback = '' ): string {
	$full  = trim( $full );
	$first = $full !== '' ? (string) preg_split( '/\s+/', $full )[0] : '';
	return $first !== '' ? $first : $fallback;
}

/** Prénom de la personne hôte d'une place. */
function cordespace_waivers_partner_host_first_name( array $partner ): string {
	$host = $partner['host_user_id'] > 0 ? get_userdata( $partner['host_user_id'] ) : false;
	if ( ! $host ) {
		return __( 'Quelqu\'un', 'cordespace-snippets' );
	}
	$first = trim( (string) ( $host->first_name ?? '' ) );
	return $first !== '' ? $first : cordespace_waivers_first_name( (string) ( $host->display_name ?? '' ), __( 'Quelqu\'un', 'cordespace-snippets' ) );
}

/**
 * Waivers applicables au cours de la place que ce courriel n'a pas signés (version en vigueur).
 * [] si la place n'a pas de courriel ou est déclinée.
 */
function cordespace_waivers_partner_missing_waivers( array $partner ): array {
	if ( $partner['partner_email'] === '' || $partner['declined_at'] !== null ) {
		return [];
	}
	if ( ! function_exists( 'cordespace_waivers_applicable_defaults_for_amelia_event' ) ) {
		return [];
	}
	$missing = [];
	foreach ( cordespace_waivers_applicable_defaults_for_amelia_event( (int) $partner['event_id'] ) as $wid ) {
		$wid = (int) $wid;
		if ( $wid > 0 && ! cordespace_waivers_email_has_signed_current( $partner['partner_email'], $wid ) ) {
			$missing[] = $wid;
		}
	}
	return $missing;
}

/** La dernière séance de la réservation est-elle passée ? */
function cordespace_waivers_partner_booking_over( array $partner ): bool {
	$end = cordespace_waivers_booking_last_end( (int) $partner['booking_id'] );
	return $end !== null && strtotime( $end . ' UTC' ) < time();
}

/**
 * Envoie l'invitation à une place : un courriel par waiver manquant. 0 si rien à envoyer
 * (courriel vide, déclinée, tout signé, dernière séance passée, ou relance trop rapprochée sans $force).
 * Retourne le nombre de courriels envoyés ; pose invited_at.
 */
const CORDESPACE_WAIVERS_PARTNER_SENDS_PER_HOUR = 3;

/** Horodatages (moins d'une heure) des envois d'invitation d'une place, quel que soit le courriel du moment. */
function cordespace_waivers_partner_recent_sends( int $partner_id ): array {
	$log = get_transient( 'cordespace_partner_sends_' . $partner_id );
	$log = is_array( $log ) ? $log : [];
	return array_values( array_filter( $log, static fn( $t ) => (int) $t > time() - HOUR_IN_SECONDS ) );
}

/** Plafond atteint pour cette place (3 envois par heure, même en changeant le courriel : pas de spam possible). */
function cordespace_waivers_partner_send_capped( int $partner_id ): bool {
	return count( cordespace_waivers_partner_recent_sends( $partner_id ) ) >= CORDESPACE_WAIVERS_PARTNER_SENDS_PER_HOUR;
}

function cordespace_waivers_partner_note_send( int $partner_id ): void {
	$log   = cordespace_waivers_partner_recent_sends( $partner_id );
	$log[] = time();
	set_transient( 'cordespace_partner_sends_' . $partner_id, $log, HOUR_IN_SECONDS );
}

function cordespace_waivers_partner_invite( int $partner_id, bool $force = false ): int {
	global $wpdb;
	$partner = cordespace_waivers_partner_get( $partner_id );
	if ( $partner === null || ! cordespace_waivers_partner_link_is_live( $partner ) ) {
		return 0;
	}
	if ( ! $force && $partner['invited_at'] !== null && strtotime( $partner['invited_at'] . ' UTC' ) > time() - HOUR_IN_SECONDS ) {
		return 0;
	}
	$missing = cordespace_waivers_partner_missing_waivers( $partner );
	if ( ! $missing || cordespace_waivers_partner_send_capped( $partner_id ) ) {
		return 0;
	}
	$event = cordespace_waivers_partner_event_info( $partner );
	$date  = $event['start'] && function_exists( 'cordespace_waivers_format_signed_at' ) ? cordespace_waivers_format_signed_at( $event['start'] ) : (string) $event['start'];
	$sent  = 0;
	foreach ( $missing as $wid ) {
		$sign_url    = cordespace_waivers_partner_sign_url( $partner, $wid );
		$decline_url = cordespace_waivers_partner_decline_url( $partner );
		$mail        = cordespace_waivers_compose_partner_invite( $wid, [
			'{prenom_partenaire}' => cordespace_waivers_first_name( $partner['partner_name'], __( 'à toi', 'cordespace-snippets' ) ),
			'{prenom_hote}'       => cordespace_waivers_partner_host_first_name( $partner ),
			'{cours}'             => $event['name'],
			'{date}'              => $date,
			'{lien_signature}'    => '<a href="' . esc_url( $sign_url ) . '" style="display:inline-block;background:#1a1a2e;color:#fff;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:4px;">' . esc_html__( 'Lire et signer le document', 'cordespace-snippets' ) . '</a>',
			'{lien_declin}'       => '<a href="' . esc_url( $decline_url ) . '">' . esc_html__( 'je ne viens pas', 'cordespace-snippets' ) . '</a>',
			'{titre_waiver}'      => get_the_title( $wid ),
		] );
		$ok = function_exists( 'cordespace_waivers_mail' ) ? cordespace_waivers_mail( $partner['partner_email'], $mail['subject'], $mail['body'] ) : wp_mail( $partner['partner_email'], $mail['subject'], $mail['body'], [ 'Content-Type: text/html; charset=UTF-8' ] );
		if ( $ok ) {
			$sent++;
		}
	}
	if ( $sent > 0 ) {
		$wpdb->update( cordespace_waivers_partners_table_name(), [ 'invited_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $partner_id ] );
		cordespace_waivers_partner_note_send( $partner_id );
	}
	return $sent;
}

// ─── Metabox « Courriel d'invitation partenaire » + courriel test ───────────

function cordespace_waivers_partners_register_metabox(): void {
	add_meta_box( 'cordespace_waiver_partner_invite', '👥 Courriel d\'invitation partenaire', 'cordespace_waivers_partners_render_metabox', CORDESPACE_WAIVER_POST_TYPE, 'normal', 'default' );
}

function cordespace_waivers_partners_render_metabox( $post ): void {
	$t = cordespace_waivers_partner_invite_texts( (int) $post->ID );
	wp_nonce_field( 'cordespace_waiver_partner_invite_save', 'cordespace_waiver_partner_invite_nonce' );
	$is_auto_draft = ( $post->post_status ?? '' ) === 'auto-draft';
	?>
	<p style="margin-top:0;color:#555;"><?php esc_html_e( 'Envoyé à la personne partenaire que l\'acheteur·euse a inscrite dans Mon espace, quand ce document est exigé par le cours. Mots-clés : {prenom_partenaire}, {prenom_hote}, {cours}, {date}, {titre_waiver}, {lien_signature} (bouton), {lien_declin} (lien « je ne viens pas »). Laisse vide pour garder le texte par défaut.', 'cordespace-snippets' ); ?></p>
	<p><label for="cordespace_waiver_partner_invite_subject"><strong><?php esc_html_e( 'Sujet', 'cordespace-snippets' ); ?></strong></label><br>
		<input type="text" id="cordespace_waiver_partner_invite_subject" name="cordespace_waiver_partner_invite_subject" class="widefat" value="<?php echo esc_attr( (string) get_post_meta( (int) $post->ID, CORDESPACE_WAIVER_META_PARTNER_INVITE_SUBJECT, true ) ); ?>" placeholder="<?php echo esc_attr( $t['subject'] ); ?>"></p>
	<p><label for="cordespace_waiver_partner_invite_body"><strong><?php esc_html_e( 'Corps', 'cordespace-snippets' ); ?></strong></label><br>
		<textarea id="cordespace_waiver_partner_invite_body" name="cordespace_waiver_partner_invite_body" class="widefat" rows="9" placeholder="<?php echo esc_attr( cordespace_waivers_partner_invite_defaults()['body'] ); ?>"><?php echo esc_textarea( (string) get_post_meta( (int) $post->ID, CORDESPACE_WAIVER_META_PARTNER_INVITE_BODY, true ) ); ?></textarea></p>
	<p><button type="button" class="button" id="cordespace-waiver-partner-test" data-post="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'cordespace_waiver_partner_test_email_' . (int) $post->ID ) ); ?>" <?php disabled( $is_auto_draft ); ?>>✉️ <?php esc_html_e( 'M\'envoyer un courriel test', 'cordespace-snippets' ); ?></button>
		<span id="cordespace-waiver-partner-test-msg" style="margin-left:8px;color:#555;"></span>
		<?php if ( $is_auto_draft ) : ?><br><em style="color:#777;"><?php esc_html_e( 'Enregistre d\'abord le waiver pour pouvoir envoyer un test.', 'cordespace-snippets' ); ?></em><?php endif; ?></p>
	<script>
	(function(){var b=document.getElementById('cordespace-waiver-partner-test');if(!b){return;}b.addEventListener('click',function(){var m=document.getElementById('cordespace-waiver-partner-test-msg');m.textContent='Envoi…';var d=new FormData();d.append('action','cordespace_waiver_partner_test_email');d.append('post_id',b.getAttribute('data-post'));d.append('_ajax_nonce',b.getAttribute('data-nonce'));fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:d}).then(function(r){return r.json();}).then(function(j){m.textContent=(j&&j.data&&j.data.message)?j.data.message:'Réponse inattendue.';}).catch(function(){m.textContent='Erreur réseau.';});});})();
	</script>
	<?php
}

function cordespace_waivers_partners_save_metabox( int $post_id ): void {
	if ( ! isset( $_POST['cordespace_waiver_partner_invite_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cordespace_waiver_partner_invite_nonce'] ) ), 'cordespace_waiver_partner_invite_save' ) ) {
		return;
	}
	if ( get_post_type( $post_id ) !== CORDESPACE_WAIVER_POST_TYPE || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	foreach ( [ 'cordespace_waiver_partner_invite_subject' => CORDESPACE_WAIVER_META_PARTNER_INVITE_SUBJECT, 'cordespace_waiver_partner_invite_body' => CORDESPACE_WAIVER_META_PARTNER_INVITE_BODY ] as $field => $meta ) {
		$value = isset( $_POST[ $field ] ) ? trim( (string) wp_unslash( $_POST[ $field ] ) ) : '';
		$value = $field === 'cordespace_waiver_partner_invite_subject' ? sanitize_text_field( $value ) : wp_kses_post( $value );
		if ( $value === '' ) {
			delete_post_meta( $post_id, $meta );
		} else {
			update_post_meta( $post_id, $meta, $value );
		}
	}
}

function cordespace_waivers_partners_ajax_test_email(): void {
	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	check_ajax_referer( 'cordespace_waiver_partner_test_email_' . $post_id );
	if ( $post_id <= 0 || get_post_type( $post_id ) !== CORDESPACE_WAIVER_POST_TYPE || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'cordespace-snippets' ) ] );
	}
	$user = wp_get_current_user();
	$to   = (string) $user->user_email;
	if ( ! is_email( $to ) ) {
		wp_send_json_error( [ 'message' => __( 'Ton compte n\'a pas d\'adresse courriel valide.', 'cordespace-snippets' ) ] );
	}
	$title = trim( (string) get_the_title( $post_id ) );
	$date  = new DateTimeImmutable( 'today +2 days 18:30', wp_timezone() );
	$mail  = cordespace_waivers_compose_partner_invite( $post_id, [
		'{prenom_partenaire}' => __( 'Camille', 'cordespace-snippets' ),
		'{prenom_hote}'       => trim( (string) $user->first_name ) !== '' ? trim( (string) $user->first_name ) : trim( (string) $user->display_name ),
		'{cours}'             => __( 'Initiation au ligotage (exemple)', 'cordespace-snippets' ),
		'{date}'              => wp_date( 'l j F Y à H\hi', $date->getTimestamp() ),
		'{titre_waiver}'      => $title !== '' ? $title : __( 'Décharge de responsabilité (titre du waiver)', 'cordespace-snippets' ),
		'{lien_signature}'    => '<a href="' . esc_url( home_url( '/' ) ) . '" style="display:inline-block;background:#1a1a2e;color:#fff;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:4px;">' . esc_html__( 'Lire et signer le document (exemple)', 'cordespace-snippets' ) . '</a>',
		'{lien_declin}'       => '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'je ne viens pas (exemple)', 'cordespace-snippets' ) . '</a>',
	] );
	$ok = function_exists( 'cordespace_waivers_mail' )
		? cordespace_waivers_mail( $to, '[TEST] ' . $mail['subject'], $mail['body'] )
		: wp_mail( $to, '[TEST] ' . $mail['subject'], $mail['body'], [ 'Content-Type: text/html; charset=UTF-8' ] );
	if ( ! $ok ) {
		wp_send_json_error( [ 'message' => __( 'L\'envoi a été refusé par WordPress (wp_mail).', 'cordespace-snippets' ) ] );
	}
	wp_send_json_success( [ 'message' => sprintf( __( 'Envoyé à %s.', 'cordespace-snippets' ), $to ) ] );
}

// ─── Lien partenaire, déclin, création de compte ────────────────────────────

/**
 * La place désignée par ?partner={id}&k={clé}, si la clé correspond, le courriel est renseigné,
 * la place n'est pas déclinée et la dernière séance n'est pas passée. Sinon null.
 */
function cordespace_waivers_partner_from_request( array $query ): ?array {
	$id = isset( $query['partner'] ) ? absint( $query['partner'] ) : 0;
	$k  = isset( $query['k'] ) ? sanitize_text_field( (string) $query['k'] ) : '';
	if ( $id <= 0 || $k === '' ) {
		return null;
	}
	$partner = cordespace_waivers_partner_get( $id );
	if ( $partner === null || ! cordespace_waivers_partner_link_is_live( $partner ) || ! hash_equals( cordespace_waivers_partner_key( $partner ), $k ) ) {
		return null;
	}
	return $partner;
}

/** Un lien partenaire est vivant tant que la place a un courriel, n'est pas déclinée et que la dernière séance n'est pas passée. */
function cordespace_waivers_partner_link_is_live( array $partner ): bool {
	return $partner['partner_email'] !== '' && $partner['declined_at'] === null && ! cordespace_waivers_partner_booking_over( $partner )
		&& in_array( cordespace_waivers_booking_status( (int) $partner['booking_id'] ), [ 'approved', 'pending' ], true );
}

/** Statut Amelia d'une réservation ('' si elle n'existe plus). */
function cordespace_waivers_booking_status( int $booking_id ): string {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}amelia_customer_bookings WHERE id = %d", $booking_id ) );
}

/**
 * Vrai si la requête ressemble à un lien partenaire (clé au bon format) vers une place qui existe :
 * lien périmé (clé d'un ancien courriel, place déclinée ou séance passée). La page affichée ne révèle rien ;
 * sans clé au bon format, on garde le comportement d'un inconnu (connexion).
 */
function cordespace_waivers_partner_dead_link_requested( array $query ): bool {
	$id = isset( $query['partner'] ) ? absint( $query['partner'] ) : 0;
	$k  = isset( $query['k'] ) ? (string) $query['k'] : '';
	return $id > 0 && preg_match( '/^[a-f0-9]{32}$/', $k ) === 1 && cordespace_waivers_partner_get( $id ) !== null;
}

/** Page autonome « Ce lien n'est plus valide » (lien partenaire périmé). */
function cordespace_waivers_render_partner_dead_link(): void {
	if ( function_exists( 'nocache_headers' ) ) {
		nocache_headers();
	}
	$esc = static fn( string $s ): string => htmlspecialchars( $s, ENT_NOQUOTES, 'UTF-8' );
	echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . $esc( __( 'Lien plus valide', 'cordespace-snippets' ) ) . '</title>'
		. '<style>body{font-family:Helvetica,Arial,sans-serif;background:#f5f3f8;color:#222;margin:0;padding:24px}main{max-width:520px;margin:40px auto;background:#fff;border-radius:10px;padding:28px 32px;box-shadow:0 2px 12px rgba(0,0,0,.08)}h1{font-size:1.4em;margin:0 0 12px}p{line-height:1.5}a{color:#5b3ea6}</style></head><body><main>'
		. '<h1>' . $esc( __( 'Ce lien n\'est plus valide.', 'cordespace-snippets' ) ) . '</h1>'
		. '<p>' . $esc( __( 'Demande un nouveau lien à la personne qui t\'a invité·e : elle peut le renvoyer depuis son espace sur le site. Si le cours est déjà passé, il n\'y a plus rien à signer.', 'cordespace-snippets' ) ) . '</p>'
		. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . $esc( __( 'Retour au site', 'cordespace-snippets' ) ) . '</a></p>'
		. '</main></body></html>';
}

/** Dernière signature portant ce courriel pour ce waiver (sans compte ou compte au même courriel). */
function cordespace_waivers_partner_last_signature( string $email, int $waiver_id ): ?array {
	global $wpdb;
	$email = cordespace_waivers_normalize_email( $email );
	$user  = $email !== '' ? get_user_by( 'email', $email ) : false;
	$row   = $wpdb->get_row( $wpdb->prepare(
		'SELECT id, waiver_version, signed_name, signed_at, email FROM ' . cordespace_waivers_table_name() . ' WHERE waiver_id = %d AND (LOWER(email) = %s OR (user_id > 0 AND user_id = %d)) ORDER BY signed_at DESC, id DESC LIMIT 1',
		$waiver_id,
		$email,
		$user ? (int) $user->ID : 0
	), ARRAY_A );
	return $row ?: null;
}

/**
 * Crée un compte WordPress pour une personne partenaire (courriel de la place) et lui envoie
 * un lien pour choisir son mot de passe. Si un compte existe déjà avec ce courriel : son ID.
 */
function cordespace_waivers_partner_create_account( array $partner, string $display_name = '' ) {
	$email = cordespace_waivers_normalize_email( (string) $partner['partner_email'] );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Courriel invalide.', 'cordespace-snippets' ) );
	}
	$existing = email_exists( $email );
	if ( $existing ) {
		return (int) $existing;
	}
	$base  = sanitize_user( (string) strstr( $email, '@', true ), true );
	$base  = $base !== '' ? $base : 'cordespace';
	$login = $base;
	$n     = 1;
	while ( username_exists( $login ) ) {
		$login = $base . ++$n;
	}
	$user_id = wp_create_user( $login, wp_generate_password( 20, false ), $email );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	$name = trim( $display_name ) !== '' ? trim( $display_name ) : trim( (string) $partner['partner_name'] );
	if ( $name !== '' ) {
		wp_update_user( [ 'ID' => (int) $user_id, 'display_name' => $name, 'first_name' => cordespace_waivers_first_name( $name, '' ) ] );
	}
	$user = get_userdata( (int) $user_id );
	$key  = $user ? get_password_reset_key( $user ) : null;
	if ( is_string( $key ) && $key !== '' ) {
		$link    = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $login ), 'login' );
		$subject = __( 'Ton compte Cordespace', 'cordespace-snippets' );
		$body    = '<p>' . esc_html( sprintf( __( 'Bonjour %s,', 'cordespace-snippets' ), cordespace_waivers_first_name( $name, __( 'à toi', 'cordespace-snippets' ) ) ) ) . '</p>'
			. '<p>' . esc_html__( 'Ton compte Cordespace est créé. Choisis ton mot de passe avec ce lien :', 'cordespace-snippets' ) . '</p>'
			. '<p><a href="' . esc_url( $link ) . '">' . esc_html( $link ) . '</a></p>'
			. '<p>' . esc_html__( 'Tu pourras ensuite retrouver tes documents signés et réserver toi-même.', 'cordespace-snippets' ) . '</p>';
		if ( function_exists( 'cordespace_waivers_mail' ) ) {
			cordespace_waivers_mail( $email, $subject, $body );
		} else {
			wp_mail( $email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
		}
	}
	return (int) $user_id;
}

/** Page autonome (sans thème, non mise en cache) : « Tu ne viens pas ? » avec bouton, ou « C'est noté ». */
function cordespace_waivers_partner_render_decline_page( array $partner, bool $done ): void {
	if ( function_exists( 'nocache_headers' ) ) {
		nocache_headers();
	}
	$event = cordespace_waivers_partner_event_info( $partner );
	$host  = cordespace_waivers_partner_host_first_name( $partner );
	$esc   = static fn( string $s ): string => htmlspecialchars( $s, ENT_NOQUOTES, 'UTF-8' );
	echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . $esc( __( 'Je ne viens pas', 'cordespace-snippets' ) ) . '</title>'
		. '<style>body{font-family:Helvetica,Arial,sans-serif;background:#f5f3f8;color:#222;margin:0;padding:24px}main{max-width:520px;margin:40px auto;background:#fff;border-radius:10px;padding:28px 32px;box-shadow:0 2px 12px rgba(0,0,0,.08)}h1{font-size:1.4em;margin:0 0 12px}p{line-height:1.5}button{background:#5b3ea6;color:#fff;border:0;border-radius:6px;padding:12px 22px;font-size:1em;font-weight:700;cursor:pointer}</style></head><body><main>';
	if ( $done ) {
		echo '<h1>' . $esc( __( 'C\'est noté.', 'cordespace-snippets' ) ) . '</h1><p>' . $esc( sprintf( __( '%1$s verra que tu ne viens pas au cours %2$s et pourra inviter quelqu\'un d\'autre. Tu ne recevras plus de courriel pour cette réservation.', 'cordespace-snippets' ), $host, $event['name'] ) ) . '</p>';
	} else {
		echo '<h1>' . $esc( __( 'Tu ne viens pas ?', 'cordespace-snippets' ) ) . '</h1>'
			. '<p>' . $esc( sprintf( __( '%1$s t\'a réservé une place au cours %2$s. Si tu ne viens pas, dis-le-nous : la place pourra aller à quelqu\'un d\'autre.', 'cordespace-snippets' ), $host, $event['name'] ) ) . '</p>'
			. '<form method="post" action="' . esc_url( cordespace_waivers_partner_decline_url( $partner ) ) . '">'
			. '<input type="hidden" name="cordespace_partner_decline" value="' . (int) $partner['id'] . '">'
			. '<input type="hidden" name="k" value="' . esc_attr( cordespace_waivers_partner_key( $partner ) ) . '">'
			. '<button type="submit">' . $esc( __( 'Oui, je ne viens pas', 'cordespace-snippets' ) ) . '</button></form>';
	}
	echo '</main></body></html>';
	if ( ! defined( 'CS_TESTING' ) ) {
		exit;
	}
}

/** ?cordespace_partner_decline={id}&k={clé} : GET = confirmation, POST = la place est déclinée. */
function cordespace_waivers_partners_handle_decline(): void {
	global $wpdb;
	$is_post = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) === 'POST';
	$src     = $is_post ? $_POST : $_GET;
	if ( ! isset( $src['cordespace_partner_decline'] ) ) {
		return;
	}
	$id = absint( wp_unslash( $src['cordespace_partner_decline'] ) );
	$k  = isset( $src['k'] ) ? sanitize_text_field( wp_unslash( $src['k'] ) ) : '';
	$partner = $id > 0 ? cordespace_waivers_partner_get( $id ) : null;
	if ( $partner === null ) {
		return; // place inconnue : page normale du site
	}
	if ( $partner['partner_email'] === '' || ! hash_equals( cordespace_waivers_partner_key( $partner ), $k ) ) {
		if ( preg_match( '/^[a-f0-9]{32}$/', $k ) === 1 ) {
			cordespace_waivers_render_partner_dead_link(); // clé d'un ancien courriel : on explique au lieu d'afficher l'accueil
			if ( ! defined( 'CS_TESTING' ) ) {
				exit;
			}
		}
		return;
	}
	if ( $partner['declined_at'] !== null ) {
		cordespace_waivers_partner_render_decline_page( $partner, true );
		return;
	}
	if ( ! $is_post ) {
		cordespace_waivers_partner_render_decline_page( $partner, false );
		return;
	}
	$wpdb->update( cordespace_waivers_partners_table_name(), [ 'declined_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $id ] );
	cordespace_waivers_partner_render_decline_page( cordespace_waivers_partner_get( $id ) ?? $partner, true );
}

// ─── Mon espace : section « Mes réservations à plusieurs » ──────────────────

/** Textes de la section (filtre cordespace_waivers_partners_texts). */
function cordespace_waivers_partners_texts(): array {
	$t = [
		'title'       => '👥 Mes réservations à plusieurs',
		'intro'       => 'Tu as réservé pour plusieurs personnes : indique qui vient avec toi. Si le cours demande un document à signer, chaque personne recevra un courriel pour le signer elle-même, sans avoir besoin d\'un compte.',
		'people'      => '%d personnes',
		'empty'       => 'Non renseigné·e',
		'invited'     => 'Invitation envoyée le %s',
		'signed'      => 'A signé le %s',
		'declined'    => 'A décliné le %s',
		'pending'     => 'Invitation en attente d\'envoi',
		'no_waiver'   => 'Pas de document à signer pour ce cours',
		'no_email'    => 'Courriel manquant pour l\'invitation à signer',
		'chip_signed'   => '%s a signé',
		'chip_todo'     => 'À compléter',
		'chip_invited'  => 'Invitation envoyée',
		'chip_declined' => 'Ne vient pas',
		'saved_capped' => 'C\'est enregistré, mais trop de courriels sont partis pour cette place dans la dernière heure : réessaie plus tard pour envoyer l\'invitation.',
		'save'        => 'Enregistrer',
		'resend'      => 'Renvoyer l\'invitation',
		'name'        => 'Nom',
		'email'       => 'Courriel',
		'slot'        => 'Partenaire %d',
		'saved'       => 'C\'est enregistré.',
		'saved_sent'  => 'C\'est enregistré. Un courriel vient d\'être envoyé à %s pour signer le document.',
		'resend_wait' => 'Une invitation a déjà été envoyée il y a moins d\'une heure. Réessaie un peu plus tard.',
		'resend_ok'   => 'Invitation renvoyée à %s.',
	];
	return (array) apply_filters( 'cordespace_waivers_partners_texts', $t );
}

/**
 * Réservations à venir d'un compte pour plus d'une personne :
 * [ booking_id, event_id, event_name, period_start (UTC), people ], triées par date.
 */
function cordespace_waivers_host_upcoming_multi_bookings( int $user_id ): array {
	global $wpdb;
	$p    = $wpdb->prefix;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT b.id AS booking_id, e.id AS event_id, e.name AS event_name, MIN(ep.periodStart) AS period_start
		   FROM {$p}amelia_users u
		   JOIN {$p}amelia_customer_bookings b ON b.customerId = u.id
		   JOIN {$p}amelia_customer_bookings_to_events_periods bep ON bep.customerBookingId = b.id
		   JOIN {$p}amelia_events_periods ep ON ep.id = bep.eventPeriodId
		   JOIN {$p}amelia_events e ON e.id = ep.eventId
		  WHERE u.externalId = %d AND b.status IN ('approved', 'pending')
		  GROUP BY b.id, e.id, e.name
		 HAVING MAX(ep.periodEnd) > UTC_TIMESTAMP()
		  ORDER BY period_start ASC",
		$user_id
	), ARRAY_A );
	$out = [];
	foreach ( is_array( $rows ) ? $rows : [] as $r ) {
		$people = cordespace_waivers_booking_people( (int) $r['booking_id'] );
		if ( $people < 2 ) {
			continue;
		}
		$out[] = [
			'booking_id'   => (int) $r['booking_id'],
			'event_id'     => (int) $r['event_id'],
			'event_name'   => (string) $r['event_name'],
			'period_start' => (string) $r['period_start'],
			'people'       => $people,
		];
	}
	return $out;
}

/** État d'une place : empty | declined | no_waiver | no_email | signed | invited | pending. */
function cordespace_waivers_partner_status( array $partner, array $applicable ): string {
	if ( $partner['partner_email'] === '' && trim( $partner['partner_name'] ) === '' ) {
		return 'empty';
	}
	if ( $partner['declined_at'] !== null ) {
		return 'declined';
	}
	if ( ! $applicable ) {
		return 'no_waiver'; // le nom seul suffit : il sert à la prof
	}
	if ( $partner['partner_email'] === '' ) {
		return 'no_email'; // nom donné, mais sans courriel l'invitation à signer ne peut pas partir
	}
	if ( ! cordespace_waivers_partner_missing_waivers( $partner ) ) {
		return 'signed';
	}
	return $partner['invited_at'] !== null ? 'invited' : 'pending';
}

/** Date UTC → texte lisible (heure du site), repli brut. */
function cordespace_waivers_partners_date( ?string $utc ): string {
	if ( $utc === null || $utc === '' ) {
		return '';
	}
	return function_exists( 'cordespace_waivers_format_signed_at' ) ? cordespace_waivers_format_signed_at( $utc ) : $utc;
}

function cordespace_waivers_partners_mon_espace_url(): string {
	return function_exists( 'cordespace_get_mon_espace_url' ) ? (string) cordespace_get_mon_espace_url() : home_url( '/' );
}

/** Feuille de style de la section Mon espace (une fois par page) : cartes repliables, champs alignés, téléphone. */
function cordespace_waivers_partners_styles_once(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	echo '<style>'
		. '#section-partenaires{margin-bottom:2.5rem;padding:1.3rem 1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px}'
		. '#section-partenaires summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:0.4rem 0.7rem;flex-wrap:wrap}'
		. '#section-partenaires .cordespace-partner-title{flex:1 1 240px;display:flex;align-items:flex-start;gap:0.5rem;min-width:0}'
		. '#section-partenaires .cordespace-partner-title > .cordespace-partner-chev{flex:none;margin-top:0.35em}'
		. '#section-partenaires summary::-webkit-details-marker{display:none}'
		. '#section-partenaires .cordespace-partner-chev{display:inline-block;color:#888;font-size:0.85em;transition:transform .2s}'
		. '#section-partenaires details[open] > summary .cordespace-partner-chev{transform:rotate(90deg)}'
		. '#section-partenaires h2{margin:0;font-size:1.4rem;display:inline}'
		. '#section-partenaires .cordespace-partners-intro{color:#666;margin:0.7rem 0 1.2rem;font-size:0.95em}'
		. '#section-partenaires .cordespace-partners-msg{padding:0.8rem 1rem;background:#eef5ee;border-left:4px solid #3a7a3a;border-radius:0 4px 4px 0;margin:0 0 1.2rem}'
		. '.cordespace-partner-card{border:1px solid #eee;border-radius:8px;margin-bottom:0.8rem;padding:0.7rem 1.1rem}'
		. '.cordespace-partner-card > summary strong{color:#1d4d7e}'
		. '.cordespace-partner-card .cordespace-partner-meta{font-size:0.9em;color:#666}'
		. '.cordespace-partner-chip{font-size:0.8em;padding:0.15rem 0.6rem;border-radius:999px;font-weight:600;margin-left:auto;white-space:nowrap}'
		. '.cordespace-partner-form{display:grid;grid-template-columns:auto minmax(140px,1fr) minmax(200px,1.4fr) auto;gap:0.5rem 0.8rem;align-items:end;padding:0.8rem 0 0.3rem;margin-top:0.6rem;border-top:1px solid #f0f0f0}'
		. '.cordespace-partner-form .cordespace-partner-slot{font-weight:600;color:#444;line-height:2.6rem}'
		. '.cordespace-partner-field{display:flex;flex-direction:column;margin:0;font-size:0.85em;color:#666;font-weight:normal}'
		. '.cordespace-partner-field > span{margin:0 0 0.3rem;line-height:1.2}'
		. '.cordespace-partner-form input.cordespace-partner-input{width:100% !important;box-sizing:border-box !important;height:2.6rem !important;margin:0 !important;padding:0 0.6rem !important;border:1px solid #ccc !important;border-radius:4px !important;font-size:1rem !important;line-height:2.6rem !important;background:#fff !important}'
		. '.cordespace-partner-form button.cordespace-partner-save{height:2.6rem;padding:0 1rem;background:#1a1a2e;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;font-size:0.95em;white-space:nowrap}'
		. '.cordespace-partner-state{grid-column:1 / -1;font-size:0.9em;margin:0}'
		. '.cordespace-partner-state button{margin-left:0.6rem;padding:0.25rem 0.7rem;background:#eee;color:#333;border:1px solid #ccc;border-radius:4px;cursor:pointer;font-size:0.9em}'
		. '@media (max-width:600px){#section-partenaires{padding:1rem 1.1rem}.cordespace-partner-form{grid-template-columns:1fr;gap:0.4rem}.cordespace-partner-form .cordespace-partner-slot{line-height:1.4}.cordespace-partner-form button.cordespace-partner-save{width:100%}.cordespace-partner-chip{margin-left:0}}'
		. '</style>';
}

/**
 * État d'une carte (une réservation) d'après ses places : la carte reste ouverte tant qu'une place demande
 * quelque chose à l'hôte ; sinon elle se replie sur une ligne « ✓ … ».
 * Retourne [ 'open' => bool, 'chip' => texte, 'color' => fond, 'ink' => texte ].
 */
function cordespace_waivers_partner_card_summary( array $slots, array $applicable, array $t ): array {
	$done  = [];
	$chip  = null;
	foreach ( $slots as $slot ) {
		$status = cordespace_waivers_partner_status( $slot, $applicable );
		$name   = trim( $slot['partner_name'] ) !== '' ? trim( $slot['partner_name'] ) : $slot['partner_email'];
		switch ( $status ) {
			case 'signed':
				$done[] = sprintf( $t['chip_signed'], $name );
				break;
			case 'no_waiver':
				$done[] = $name;
				break;
			case 'declined':
				$chip = $chip ?? [ $t['chip_declined'], '#fdecea', '#b32d2e' ];
				break;
			case 'invited':
			case 'pending':
				$chip = $chip ?? [ $t['chip_invited'], '#e3f2fd', '#0d47a1' ];
				break;
			default: // empty, no_email
				$chip = [ $t['chip_todo'], '#fff3cd', '#8a5a00' ];
		}
	}
	if ( $chip !== null ) {
		return [ 'open' => true, 'chip' => $chip[0], 'color' => $chip[1], 'ink' => $chip[2] ];
	}
	return [ 'open' => false, 'chip' => "✓\u{00A0}" . implode( ', ', $done ), 'color' => '#e8f5e9', 'ink' => '#1b5e20' ];
}

/** La section dans Mon espace (slot cordespace_mon_espace_section_client_waivers, priorité 20). */
function cordespace_waivers_render_partners_section( $user ): void {
	if ( ! $user || ! isset( $user->ID ) || (int) $user->ID <= 0 ) {
		return;
	}
	$user_id  = (int) $user->ID;
	$bookings = cordespace_waivers_host_upcoming_multi_bookings( $user_id );
	if ( ! $bookings ) {
		return;
	}
	$t   = cordespace_waivers_partners_texts();
	$msg = (string) get_transient( 'cordespace_partners_msg_' . $user_id );
	if ( $msg !== '' ) {
		delete_transient( 'cordespace_partners_msg_' . $user_id );
	}
	cordespace_waivers_partners_styles_once();
	?>
	<section id="section-partenaires">
		<details class="cordespace-partners" open>
			<summary><span class="cordespace-partner-title"><span class="cordespace-partner-chev" aria-hidden="true">▶</span><h2><?php echo esc_html( $t['title'] ); ?></h2></span></summary>
			<p class="cordespace-partners-intro"><?php echo esc_html( $t['intro'] ); ?></p>
			<?php if ( $msg !== '' ) : ?>
				<p role="status" class="cordespace-partners-msg"><?php echo esc_html( $msg ); ?></p>
			<?php endif; ?>
			<?php foreach ( $bookings as $bk ) :
				$applicable = function_exists( 'cordespace_waivers_applicable_defaults_for_amelia_event' ) ? cordespace_waivers_applicable_defaults_for_amelia_event( $bk['event_id'] ) : [];
				$slots      = cordespace_waivers_partner_slots( $bk['booking_id'], $bk['event_id'], $user_id );
				$card       = cordespace_waivers_partner_card_summary( $slots, $applicable, $t );
				?>
				<details class="cordespace-partner-card"<?php echo $card['open'] ? ' open' : ''; ?>>
					<summary>
						<span class="cordespace-partner-title"><span class="cordespace-partner-chev" aria-hidden="true">▶</span><strong><?php echo esc_html( $bk['event_name'] ); ?></strong></span>
						<span class="cordespace-partner-meta">📅 <?php echo esc_html( cordespace_waivers_partners_date( $bk['period_start'] ) ); ?> · <?php echo esc_html( sprintf( $t['people'], $bk['people'] ) ); ?></span>
						<span class="cordespace-partner-chip" style="background:<?php echo esc_attr( $card['color'] ); ?>;color:<?php echo esc_attr( $card['ink'] ); ?>;"><?php echo esc_html( $card['chip'] ); ?></span>
					</summary>
					<?php foreach ( $slots as $slot ) :
						$status = cordespace_waivers_partner_status( $slot, $applicable );
						// Rien sous le formulaire quand tout est en ordre (signé, ou pas de document pour ce cours) : l'en-tête le dit déjà.
						switch ( $status ) {
							case 'empty':    $label = $t['empty']; $color = '#8a5a00'; break;
							case 'no_email': $label = $t['no_email']; $color = '#8a5a00'; break;
							case 'declined': $label = sprintf( $t['declined'], cordespace_waivers_partners_date( $slot['declined_at'] ) ); $color = '#b32d2e'; break;
							case 'invited':  $label = sprintf( $t['invited'], cordespace_waivers_partners_date( $slot['invited_at'] ) ); $color = '#0d47a1'; break;
							case 'pending':  $label = $t['pending']; $color = '#666'; break;
							default:         $label = ''; $color = '';
						}
						?>
						<form method="post" action="<?php echo esc_url( cordespace_waivers_partners_mon_espace_url() ); ?>" class="cordespace-partner-form">
							<?php wp_nonce_field( 'cordespace_partner_' . (int) $slot['id'] ); ?>
							<input type="hidden" name="cordespace_partner_id" value="<?php echo (int) $slot['id']; ?>">
							<span class="cordespace-partner-slot"><?php echo esc_html( sprintf( $t['slot'], $slot['slot_no'] ) ); ?></span>
							<label class="cordespace-partner-field"><span><?php echo esc_html( $t['name'] ); ?></span>
								<input type="text" name="partner_name" class="cordespace-partner-input" value="<?php echo esc_attr( $slot['partner_name'] ); ?>"></label>
							<label class="cordespace-partner-field"><span><?php echo esc_html( $t['email'] ); ?></span>
								<input type="email" name="partner_email" class="cordespace-partner-input" value="<?php echo esc_attr( $slot['partner_email'] ); ?>"></label>
							<button type="submit" class="cordespace-partner-save"><?php echo esc_html( $t['save'] ); ?></button>
							<?php if ( $label !== '' ) : ?>
								<p class="cordespace-partner-state" style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $label ); ?>
									<?php if ( $status === 'invited' ) : ?>
										<button type="submit" name="resend" value="1"><?php echo esc_html( $t['resend'] ); ?></button>
									<?php endif; ?>
								</p>
							<?php endif; ?>
						</form>
					<?php endforeach; ?>
				</details>
			<?php endforeach; ?>
		</details>
	</section>
	<?php
}

/** Traitement du formulaire d'une place (personne connectée = hôte), puis retour à la section. */
function cordespace_waivers_partners_handle_post(): void {
	if ( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) !== 'POST' || ! isset( $_POST['cordespace_partner_id'] ) ) {
		return;
	}
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return;
	}
	$pid = absint( wp_unslash( $_POST['cordespace_partner_id'] ) );
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( (string) wp_unslash( $_POST['_wpnonce'] ), 'cordespace_partner_' . $pid ) ) {
		return;
	}
	$t      = cordespace_waivers_partners_texts();
	$name   = isset( $_POST['partner_name'] ) ? sanitize_text_field( wp_unslash( $_POST['partner_name'] ) ) : '';
	// Pas sanitize_email() ici : il renvoie '' sur une faute de frappe (« camille@test ») et la place serait vidée
	// en silence ; partner_set() valide avec is_email() et renvoie une erreur affichée.
	$email  = isset( $_POST['partner_email'] ) ? sanitize_text_field( wp_unslash( $_POST['partner_email'] ) ) : '';
	$resend = ! empty( $_POST['resend'] );
	$before = cordespace_waivers_partner_get( $pid );
	$res    = cordespace_waivers_partner_set( $pid, $name, $email, $user_id );
	if ( is_wp_error( $res ) ) {
		$msg = $res->get_error_message();
	} else {
		$changed = $before !== null && $before['partner_email'] !== $res['partner_email'];
		$msg     = $t['saved'];
		if ( $res['partner_email'] !== '' && ( $changed || $res['invited_at'] === null ) ) {
			if ( cordespace_waivers_partner_send_capped( $pid ) ) {
				$msg = $t['saved_capped'];
			} elseif ( cordespace_waivers_partner_invite( $pid, true ) > 0 ) {
				$msg = sprintf( $t['saved_sent'], $res['partner_email'] );
			}
		} elseif ( $resend && $res['partner_email'] !== '' ) {
			$msg = cordespace_waivers_partner_invite( $pid, false ) > 0 ? sprintf( $t['resend_ok'], $res['partner_email'] ) : $t['resend_wait'];
		}
	}
	set_transient( 'cordespace_partners_msg_' . $user_id, $msg, 60 );
	wp_safe_redirect( cordespace_waivers_partners_mon_espace_url() . '#section-partenaires' );
	if ( ! defined( 'CS_TESTING' ) ) {
		exit;
	}
}

// ─── Vue prof : lignes partenaires sous l'hôte ──────────────────────────────

/**
 * Sous chaque hôte d'une réservation à plusieurs : une ligne par place partenaire
 * (« + Camille ✓ a signé », « + Yann ✍️ Waiver à signer », « + 1 place : partenaire non renseigné·e »,
 * « + Yann · ne vient pas »). Rien pour une réservation d'une personne. Cours sans waiver : le nom seul.
 */
function cordespace_waivers_partners_render_teacher_lines( $b, $event ): void {
	if ( ! is_array( $b ) || ! is_array( $event ) ) {
		return;
	}
	$booking_id = (int) ( $b['booking_id'] ?? 0 );
	$event_id   = (int) ( $event['event_id'] ?? 0 );
	if ( $booking_id <= 0 || $event_id <= 0 || cordespace_waivers_booking_people( $booking_id ) < 2 ) {
		return;
	}
	static $applicable_cache = [];
	if ( ! isset( $applicable_cache[ $event_id ] ) ) {
		$applicable_cache[ $event_id ] = function_exists( 'cordespace_waivers_applicable_defaults_for_amelia_event' )
			? cordespace_waivers_applicable_defaults_for_amelia_event( $event_id ) : [];
	}
	$applicable = $applicable_cache[ $event_id ];
	$slots      = cordespace_waivers_partner_slots( $booking_id, $event_id, (int) ( $b['wp_user_id'] ?? 0 ) );
	if ( ! $slots ) {
		return;
	}
	echo '<div class="cordespace-partner-lines">';
	foreach ( $slots as $slot ) {
		echo '<div>';
		$name_empty = trim( $slot['partner_name'] ) === '';
		if ( $slot['partner_email'] === '' && $name_empty ) {
			echo '<span style="color:#8a5a00;">+ ' . esc_html__( '1 place : partenaire non renseigné·e', 'cordespace-snippets' ) . '</span>';
			echo '</div>';
			continue;
		}
		$name = ! $name_empty ? $slot['partner_name'] : $slot['partner_email'];
		echo '<span style="font-weight:600;color:#333;">+ ' . esc_html( $name ) . '</span>';
		if ( $slot['declined_at'] !== null ) {
			echo '<span style="color:#888;">· ' . esc_html__( 'ne vient pas', 'cordespace-snippets' ) . '</span>';
		} elseif ( $applicable ) {
			// Sans courriel, missing_waivers() ne peut rien vérifier : le nom seul ne signe rien, donc « à signer ».
			if ( $slot['partner_email'] === '' || cordespace_waivers_partner_missing_waivers( $slot ) ) {
				if ( $slot['partner_email'] === '' ) {
					$title = __( 'Cette personne n\'a pas signé le document requis : il manque son courriel pour l\'inviter.', 'cordespace-snippets' );
				} elseif ( $slot['invited_at'] !== null ) {
					$title = __( 'Cette personne n\'a pas encore signé le document requis pour ce cours (invitation envoyée par courriel).', 'cordespace-snippets' );
				} else {
					$title = __( 'Cette personne n\'a pas encore signé le document requis pour ce cours (invitation à venir).', 'cordespace-snippets' );
				}
				if ( function_exists( 'cordespace_waivers_prof_badge_print' ) ) {
					cordespace_waivers_prof_badge_print( __( 'Waiver à signer', 'cordespace-snippets' ), $title );
				} else {
					echo '<span style="color:#b32d2e;">✍️ ' . esc_html__( 'Waiver à signer', 'cordespace-snippets' ) . '</span>';
				}
			} else {
				echo '<span style="display:inline-block;background:#e8f5e9;color:#1b5e20;font-size:0.85em;padding:0.1rem 0.5rem;border-radius:999px;">✓ ' . esc_html__( 'a signé', 'cordespace-snippets' ) . '</span>';
			}
		}
		echo '</div>';
	}
	echo '</div>';
}

// ─── Rappel 48 h : places vides (hôte) et partenaires non signés ─────────────

/**
 * Une place est « à compléter » quand elle n'est pas déclinée et qu'il manque ce qui sert :
 * le courriel sur un cours à waiver (sans lui, pas d'invitation), ou le nom et le courriel sur un cours sans waiver.
 */
function cordespace_waivers_partner_slot_incomplete( array $slot, bool $has_waiver ): bool {
	return $slot['declined_at'] === null && $slot['partner_email'] === '' && ( $has_waiver || trim( $slot['partner_name'] ) === '' );
}

/** Nombre de places partenaire encore à compléter pour une réservation (voir partner_slot_incomplete). */
function cordespace_waivers_partners_empty_slots_for_booking( int $booking_id, int $event_id, int $host_user_id, ?bool $has_waiver = null ): int {
	if ( $has_waiver === null ) {
		$has_waiver = function_exists( 'cordespace_waivers_applicable_defaults_for_amelia_event' ) && (bool) cordespace_waivers_applicable_defaults_for_amelia_event( $event_id );
	}
	$n = 0;
	foreach ( cordespace_waivers_partner_slots( $booking_id, $event_id, $host_user_id ) as $slot ) {
		if ( cordespace_waivers_partner_slot_incomplete( $slot, $has_waiver ) ) {
			$n++;
		}
	}
	return $n;
}

/** Places vides sur toutes les réservations à venir d'un compte (banderoles). */
function cordespace_waivers_partners_empty_slots_for_user( int $user_id ): int {
	$n = 0;
	foreach ( cordespace_waivers_host_upcoming_multi_bookings( $user_id ) as $bk ) {
		$n += cordespace_waivers_partners_empty_slots_for_booking( $bk['booking_id'], $bk['event_id'], $user_id );
	}
	return $n;
}

/**
 * Relance des partenaires d'une réservation de la fenêtre 48 h (cours à waiver) : pour chaque place avec
 * courriel, non déclinée, jamais relancée et avec au moins un document manquant, renvoie l'invitation
 * et pose reminded_at (une seule relance par place). Retourne le nombre de courriels envoyés.
 */
function cordespace_waivers_partners_remind( array $booking_row ): int {
	global $wpdb;
	$booking_id = (int) ( $booking_row['booking_id'] ?? 0 );
	$event_id   = (int) ( $booking_row['event_id'] ?? 0 );
	$host       = (int) ( $booking_row['wp_user_id'] ?? 0 );
	if ( $booking_id <= 0 || $event_id <= 0 || ! function_exists( 'cordespace_waivers_applicable_defaults_for_amelia_event' ) || ! cordespace_waivers_applicable_defaults_for_amelia_event( $event_id ) ) {
		return 0;
	}
	$sent = 0;
	foreach ( cordespace_waivers_partner_slots( $booking_id, $event_id, $host ) as $slot ) {
		if ( $slot['partner_email'] === '' || $slot['declined_at'] !== null || $slot['reminded_at'] !== null || ! cordespace_waivers_partner_missing_waivers( $slot ) ) {
			continue;
		}
		$n = cordespace_waivers_partner_invite( (int) $slot['id'], true );
		if ( $n > 0 ) {
			$wpdb->update( cordespace_waivers_partners_table_name(), [ 'reminded_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => (int) $slot['id'] ] );
			$sent += $n;
		}
	}
	return $sent;
}

/** Phrase « Il manque encore le nom ou le courriel de N personne(s)… » du rappel à l'hôte. */
function cordespace_waivers_partners_missing_names_sentence( int $empty_slots ): string {
	return sprintf(
		_n( 'Il manque encore le nom ou le courriel de %d personne qui vient avec toi : indique-les dans Mon espace pour qu\'elle reçoive son document à signer.', 'Il manque encore le nom ou le courriel de %d personnes qui viennent avec toi : indique-les dans Mon espace pour qu\'elles reçoivent leur document à signer.', $empty_slots, 'cordespace-snippets' ),
		$empty_slots
	);
}

function cordespace_waivers_partners_section_url(): string {
	return cordespace_waivers_partners_mon_espace_url() . '#section-partenaires';
}

/**
 * Adapte le rappel 48 h de l'hôte quand des places sont vides : si l'hôte n'a pas signé, ajoute la phrase
 * au courriel habituel ; si l'hôte a déjà signé, remplace le courriel par un message dédié aux places vides.
 * $ctx : first_name, event_name, event_date, signed (bool).
 */
function cordespace_waivers_partners_host_reminder( array $mail, int $empty_slots, array $ctx ): array {
	if ( $empty_slots <= 0 ) {
		return $mail;
	}
	$url      = cordespace_waivers_partners_section_url();
	$sentence = cordespace_waivers_partners_missing_names_sentence( $empty_slots );
	$button   = '<p style="margin:1.2rem 0;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:0.8rem 1.5rem;background:#1a1a2e;color:#fff;text-decoration:none;border-radius:5px;font-weight:700;">👥 ' . esc_html__( 'Indiquer qui vient avec moi', 'cordespace-snippets' ) . '</a></p>';
	if ( empty( $ctx['signed'] ) ) {
		$block = '<div style="margin:1.2rem 0;padding:1rem 1.2rem;background:#eef3fb;border-left:6px solid #1d4d7e;border-radius:6px;"><p style="margin:0;">👥 ' . esc_html( $sentence ) . '</p>' . $button . '</div>';
		$body  = (string) $mail['body'];
		$pos = strpos( $body, '<hr' );
		if ( $pos !== false ) {
			$body = substr_replace( $body, $block, $pos, 0 );
		} elseif ( str_contains( $body, '</body>' ) ) {
			$body = str_replace( '</body>', $block . '</body>', $body );
		} else {
			$body .= $block;
		}
		return [ 'subject' => $mail['subject'], 'body' => $body ];
	}
	$first = trim( (string) ( $ctx['first_name'] ?? '' ) );
	$event = (string) ( $ctx['event_name'] ?? '' );
	$date  = (string) ( $ctx['event_date'] ?? '' );
	$esc   = static fn( string $t ): string => htmlspecialchars( $t, ENT_NOQUOTES, 'UTF-8' );
	$body  = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>' . $esc( __( 'Qui vient avec toi ?', 'cordespace-snippets' ) ) . '</title></head>'
		. '<body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color:#222; line-height:1.5;">'
		. '<p style="margin:0 0 1rem;">' . $esc( sprintf( __( 'Bonjour %s,', 'cordespace-snippets' ), $first !== '' ? $first : __( 'à toi', 'cordespace-snippets' ) ) ) . '</p>'
		. '<p style="margin:0 0 1rem;">' . $esc( sprintf( __( 'Ton cours « %s » est dans environ 48 h%s.', 'cordespace-snippets' ), $event, $date !== '' ? ' (' . $date . ')' : '' ) ) . '</p>'
		. '<p style="margin:0 0 1rem;">👥 ' . $esc( $sentence ) . '</p>'
		. $button
		. '<hr style="border:none;border-top:1px solid #eee;margin:2rem 0 1rem;">'
		. '<p style="font-size:0.85em;color:#999;margin:0.5rem 0;">' . $esc( __( 'Si tu as déjà complété l\'information entre-temps, tu peux ignorer ce courriel.', 'cordespace-snippets' ) ) . '</p>'
		. '<p style="font-size:0.85em;color:#999;margin:0.5rem 0;">' . $esc( __( 'À bientôt sur les cordes ! — L\'équipe Cordespace', 'cordespace-snippets' ) ) . '</p>'
		. '</body></html>';
	return [
		'subject' => sprintf( __( '👥 Qui vient avec toi au cours « %s » ?', 'cordespace-snippets' ), $event !== '' ? $event : __( 'à venir', 'cordespace-snippets' ) ),
		'body'    => $body,
	];
}

// ─── Démarrage : hooks enregistrés seulement si les modules requis sont actifs ─

/**
 * Le module est chargé en dernier par le registre : si une fonction d'un module requis manque,
 * on n'accroche rien (pas d'écran blanc côté client·es) et on l'explique dans wp-admin.
 */
function cordespace_waivers_partners_boot(): void {
	$required = [
		'cordespace_waivers_table_name'                        => 'Tables',
		'cordespace_waivers_sign_guest'                        => 'CRUD signatures (helpers)',
		'cordespace_waivers_get_sign_url'                      => 'Page de signature',
		'cordespace_waivers_applicable_defaults_for_amelia_event' => 'Application par étiquettes',
		'cordespace_waivers_wrap_custom_email_body'            => 'Courriel de rappel 48 h',
	];
	$missing = [];
	foreach ( $required as $fn => $label ) {
		if ( ! function_exists( $fn ) ) {
			$missing[] = $label;
		}
	}
	if ( $missing ) {
		add_action( 'admin_notices', static function () use ( $missing ): void {
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Le module « Partenaires (réservations à plusieurs) » est en veille : il nécessite aussi les modules %s.', 'cordespace-snippets' ), implode( ', ', $missing ) ) ) . '</p></div>';
		} );
		return;
	}
	add_action( 'template_redirect', 'cordespace_waivers_partners_handle_decline', 1 );
	add_action( 'template_redirect', 'cordespace_waivers_partners_handle_post', 2 );
	add_action( 'cordespace_mon_espace_section_client_waivers', 'cordespace_waivers_render_partners_section', 20 );
	add_action( 'cordespace_teacher_presence_after_student', 'cordespace_waivers_partners_render_teacher_lines', 10, 2 );
	add_action( 'wp_ajax_cordespace_waiver_partner_test_email', 'cordespace_waivers_partners_ajax_test_email' );
	if ( defined( 'CORDESPACE_WAIVER_POST_TYPE' ) ) {
		add_action( 'add_meta_boxes_' . CORDESPACE_WAIVER_POST_TYPE, 'cordespace_waivers_partners_register_metabox' );
		add_action( 'save_post_' . CORDESPACE_WAIVER_POST_TYPE, 'cordespace_waivers_partners_save_metabox' );
	}
}
cordespace_waivers_partners_boot();
