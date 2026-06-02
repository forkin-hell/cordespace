<?php
/**
 * Module: waivers-email-reminder
 *
 * Envoi automatique d'un email rappel 48h avant un événement Amelia, si
 * la cliente a un document à signer et ne l'a pas encore fait.
 *
 * Comment ça marche :
 *   1. WP cron quotidien (scheduled à 9h Montréal au premier load du module)
 *   2. À chaque run, query Amelia pour les bookings dont ep.periodStart est
 *      dans la fenêtre [36h-60h] à partir de maintenant. Cette fenêtre de
 *      24h centrée sur 48h absorbe les dérives possibles du cron WP (cron
 *      peut prendre du retard si le site n'est pas visité, etc.) sans rater
 *      de booking ni en doubler.
 *   3. Pour chaque booking, on liste les waivers requis (via les defaults
 *      par étiquettes Amelia) et on garde ceux qui ne sont pas signés.
 *   4. Pour chaque (booking, waiver) à rappeler : on vérifie qu'on n'a pas
 *      déjà envoyé l'email (check dans la table wp_cordespace_waiver_reminders_sent)
 *      puis on envoie via wp_mail() et on marque comme envoyé.
 *
 * Filet de sécurité #2 dans l'architecture pivot 2026-06-02 :
 *   #1 banderoles post-purchase (waivers-post-purchase-prompt)
 *   #2 CE MODULE — email reminder 48h avant
 *   #3 badge prof jour-J (waivers-prof-badge)
 *
 * Volume attendu pour Cordespace : ~5-20 emails/jour max, donc largement
 * sous toute limite Hostinger (200/heure, 5000/jour). Pas besoin d'SMTP
 * dédié pour le MVP, mais possible d'en ajouter plus tard (WP Mail SMTP +
 * Brevo/Sendgrid free tier) pour la fiabilité de delivery.
 *
 * Dépend de :
 *   - waivers-defaults     (cordespace_waivers_applicable_defaults_for_amelia_event)
 *   - waivers-store        (cordespace_waivers_has_signed_current)
 *   - waivers-signing-page (cordespace_waivers_get_sign_url)
 *   - waivers-post-purchase-prompt (cordespace_waivers_get_mon_espace_url)
 *   - Amelia
 */

defined( 'ABSPATH' ) || exit;

const CORDESPACE_WAIVERS_REMINDERS_TABLE_SUFFIX   = 'cordespace_waiver_reminders_sent';
const CORDESPACE_WAIVERS_REMINDERS_SCHEMA_VERSION = 1;
const CORDESPACE_WAIVERS_REMINDER_CRON_HOOK       = 'cordespace_waivers_email_reminder_cron';

// ============================================================================
// 1) Table de tracking des emails envoyés (self-heal pattern, comme schema)
// ============================================================================

function cordespace_waivers_reminders_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . CORDESPACE_WAIVERS_REMINDERS_TABLE_SUFFIX;
}

function cordespace_waivers_reminders_install_table(): void {
	global $wpdb;
	$table   = cordespace_waivers_reminders_table_name();
	$charset = $wpdb->get_charset_collate();

	// UNIQUE KEY (booking_id, waiver_id) = garantie qu'on n'enverra jamais
	// deux fois le même email, même en cas de course condition entre runs.
	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		booking_id BIGINT UNSIGNED NOT NULL,
		waiver_id BIGINT UNSIGNED NOT NULL,
		user_id BIGINT UNSIGNED NOT NULL,
		sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_booking_waiver (booking_id, waiver_id),
		KEY idx_user_id (user_id),
		KEY idx_sent_at (sent_at)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'cordespace_waivers_reminders_schema_version', CORDESPACE_WAIVERS_REMINDERS_SCHEMA_VERSION );
}

add_action( 'admin_init', static function (): void {
	$stored = (int) get_option( 'cordespace_waivers_reminders_schema_version', 0 );
	if ( $stored < CORDESPACE_WAIVERS_REMINDERS_SCHEMA_VERSION ) {
		cordespace_waivers_reminders_install_table();
	}
} );

// ============================================================================
// 2) Scheduling du cron quotidien (9h Montréal)
// ============================================================================

add_action( 'init', static function (): void {
	if ( wp_next_scheduled( CORDESPACE_WAIVERS_REMINDER_CRON_HOOK ) ) {
		return;
	}
	// Premier tir : demain matin 9h heure de Montréal.
	try {
		$tz   = new DateTimeZone( 'America/Toronto' );
		$next = new DateTime( 'tomorrow 9:00:00', $tz );
		wp_schedule_event( $next->getTimestamp(), 'daily', CORDESPACE_WAIVERS_REMINDER_CRON_HOOK );
	} catch ( Exception $e ) {
		// Fallback : on planifie dans 1h si le DateTime fail (peu probable)
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', CORDESPACE_WAIVERS_REMINDER_CRON_HOOK );
	}
} );

add_action( CORDESPACE_WAIVERS_REMINDER_CRON_HOOK, 'cordespace_waivers_email_reminder_run' );

// ============================================================================
// 3) Logique principale du cron
// ============================================================================

/**
 * Run principal du cron. Scan les bookings dans la fenêtre 36-60h, identifie
 * les waivers non signés, envoie les emails non encore envoyés.
 *
 * @return int Nombre d'emails effectivement envoyés ce tour.
 */
function cordespace_waivers_email_reminder_run(): int {
	global $wpdb;

	$window_start = gmdate( 'Y-m-d H:i:s', time() + 36 * HOUR_IN_SECONDS );
	$window_end   = gmdate( 'Y-m-d H:i:s', time() + 60 * HOUR_IN_SECONDS );

	$bookings = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			b.id AS booking_id,
			u.externalId AS wp_user_id,
			u.firstName,
			u.lastName,
			u.email AS amelia_email,
			e.id AS event_id,
			e.name AS event_name,
			ep.periodStart
		   FROM {$wpdb->prefix}amelia_customer_bookings b
		   JOIN {$wpdb->prefix}amelia_users u ON u.id = b.customerId
		   JOIN {$wpdb->prefix}amelia_customer_bookings_to_events_periods bep ON bep.customerBookingId = b.id
		   JOIN {$wpdb->prefix}amelia_events_periods ep ON ep.id = bep.eventPeriodId
		   JOIN {$wpdb->prefix}amelia_events e ON e.id = ep.eventId
		  WHERE b.status IN ('approved', 'pending')
		    AND ep.periodStart BETWEEN %s AND %s
		    AND u.externalId > 0",
		$window_start,
		$window_end
	), ARRAY_A );

	if ( empty( $bookings ) ) {
		return 0;
	}

	$sent_count = 0;
	foreach ( $bookings as $booking ) {
		$user_id    = (int) $booking['wp_user_id'];
		$booking_id = (int) $booking['booking_id'];
		$event_id   = (int) $booking['event_id'];
		if ( $user_id <= 0 || $booking_id <= 0 || $event_id <= 0 ) {
			continue;
		}

		$applicable = cordespace_waivers_applicable_defaults_for_amelia_event( $event_id );
		foreach ( $applicable as $waiver_id ) {
			$waiver_id = (int) $waiver_id;
			if ( $waiver_id <= 0 ) {
				continue;
			}
			if ( cordespace_waivers_has_signed_current( $user_id, $waiver_id ) ) {
				continue;
			}
			if ( cordespace_waivers_reminder_already_sent( $booking_id, $waiver_id ) ) {
				continue;
			}
			$ok = cordespace_waivers_send_reminder_email( $booking, $waiver_id );
			if ( $ok ) {
				cordespace_waivers_mark_reminder_sent( $booking_id, $waiver_id, $user_id );
				$sent_count++;
			}
		}
	}

	return $sent_count;
}

// ============================================================================
// 4) Helpers tracking : already_sent / mark_sent
// ============================================================================

function cordespace_waivers_reminder_already_sent( int $booking_id, int $waiver_id ): bool {
	global $wpdb;
	$table = cordespace_waivers_reminders_table_name();
	$row   = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE booking_id = %d AND waiver_id = %d LIMIT 1",
		$booking_id,
		$waiver_id
	) );
	return $row !== null;
}

function cordespace_waivers_mark_reminder_sent( int $booking_id, int $waiver_id, int $user_id ): bool {
	global $wpdb;
	$table  = cordespace_waivers_reminders_table_name();
	$result = $wpdb->insert(
		$table,
		[
			'booking_id' => $booking_id,
			'waiver_id'  => $waiver_id,
			'user_id'    => $user_id,
			'sent_at'    => current_time( 'mysql', true ),
		],
		[ '%d', '%d', '%d', '%s' ]
	);
	return $result !== false;
}

// ============================================================================
// 5) Construction et envoi de l'email
// ============================================================================

/**
 * Construit et envoie l'email reminder pour un booking + waiver donné.
 *
 * @param array $booking Row de la query reminder_run (booking_id, wp_user_id, etc.)
 * @param int   $waiver_id ID du post waiver à rappeler
 * @return bool true si wp_mail a accepté l'envoi
 */
function cordespace_waivers_send_reminder_email( array $booking, int $waiver_id ): bool {
	$waiver = get_post( $waiver_id );
	if ( ! $waiver ) {
		return false;
	}

	// CHECK : ce waiver veut-il un email rappel ?
	// Défaut ON (waivers existants pré-Phase D) : seule la valeur '0' désactive.
	$enabled = (string) get_post_meta( $waiver_id, '_cordespace_waiver_email_enabled', true );
	if ( $enabled === '0' ) {
		return false;
	}

	$first_name      = (string) ( $booking['firstName'] ?? '' );
	$event_name      = (string) ( $booking['event_name'] ?? '' );
	$event_start_utc = (string) ( $booking['periodStart'] ?? '' );

	// Adresse email : priorité au WP user (compte vraiment actif), fallback Amelia
	$user_id = (int) ( $booking['wp_user_id'] ?? 0 );
	$email   = '';
	if ( $user_id > 0 ) {
		$user = get_user_by( 'ID', $user_id );
		if ( $user ) {
			$email = (string) $user->user_email;
		}
	}
	if ( $email === '' ) {
		$email = (string) ( $booking['amelia_email'] ?? '' );
	}
	if ( ! is_email( $email ) ) {
		return false;
	}

	// Date du cours formatée en heure Montréal (lecture client-friendly)
	$event_date_str = '';
	if ( $event_start_utc !== '' ) {
		$ts = strtotime( $event_start_utc . ' UTC' );
		if ( $ts ) {
			$event_date_str = wp_date( 'l j F Y à H\hi', $ts );
		}
	}

	$waiver_title = (string) get_the_title( $waiver );
	$account_url  = cordespace_waivers_get_mon_espace_url();
	$sign_url     = cordespace_waivers_get_sign_url( $waiver_id, $account_url );

	// Map des placeholders → valeurs réelles (utilisé pour subject ET body)
	$placeholders = [
		'{prenom}'         => $first_name !== '' ? $first_name : 'à toi',
		'{nom_cours}'      => $event_name,
		'{date_cours}'     => $event_date_str,
		'{titre_document}' => $waiver_title,
		'{lien_signature}' => $sign_url,
	];

	// Subject : custom du waiver (post_meta) OU template par défaut
	$custom_subject = (string) get_post_meta( $waiver_id, '_cordespace_waiver_email_subject', true );
	if ( $custom_subject !== '' ) {
		$subject = strtr( $custom_subject, $placeholders );
	} else {
		$subject = sprintf(
			/* translators: %s = nom de l'événement (cours) */
			__( '⚠️ Document à signer avant ton cours « %s »', 'cordespace-snippets' ),
			$event_name !== '' ? $event_name : __( 'à venir', 'cordespace-snippets' )
		);
	}

	// Body : custom du waiver (post_meta) OU template par défaut
	$custom_body = (string) get_post_meta( $waiver_id, '_cordespace_waiver_email_body', true );
	if ( $custom_body !== '' ) {
		$body = cordespace_waivers_wrap_custom_email_body( strtr( $custom_body, $placeholders ) );
	} else {
		$body = cordespace_waivers_build_reminder_email_html(
			$first_name,
			$waiver_title,
			$event_name,
			$event_date_str,
			$sign_url
		);
	}

	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

	return wp_mail( $email, $subject, $body, $headers );
}

/**
 * Wrap le contenu HTML custom d'un email dans la structure complète
 * (DOCTYPE + body avec styles inline) pour bonne lecture dans tous les
 * clients mail (Gmail, Outlook, Apple Mail, etc.).
 *
 * Applique aussi quelques transformations de confort à l'arrivée :
 *   - Nettoie les <div data-line=...> que certains éditeurs (Markdown viewers,
 *     copies depuis chat IA, etc.) injectent autour de chaque ligne.
 *   - Convertit le markdown simple : **gras**, *italique*, [texte](url).
 *   - wpautop() pour transformer les sauts de ligne en <p>.
 *
 * Du coup l'admin peut écrire SOIT du markdown SOIT du HTML, les deux
 * marcheront correctement dans le rendu final.
 */
function cordespace_waivers_wrap_custom_email_body( string $custom_html ): string {
	$cleaned = cordespace_waivers_prepare_email_html( $custom_html );

	ob_start();
	?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Document à signer</title></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color:#222; line-height:1.6; font-size:15px;">
	<?php echo $cleaned; // déjà sanitized + transformations appliquées ?>
</body>
</html>
	<?php
	return (string) ob_get_clean();
}

/**
 * Prépare le HTML à envoyer dans l'email : strip wrappers <div data-line> +
 * convertit markdown simple → HTML + applique wpautop().
 */
function cordespace_waivers_prepare_email_html( string $raw ): string {
	// 1) Strip les <div data-line="..." ...> qui ajoutent du bruit sans valeur.
	//    Garde seulement le contenu intérieur + ajoute un \n pour préserver les
	//    sauts de ligne logiques.
	$cleaned = preg_replace(
		'#<div[^>]*data-line[^>]*>(.*?)</div>#is',
		"$1\n",
		$raw
	);
	if ( $cleaned === null ) {
		$cleaned = $raw;
	}

	// 2) Décode les entités HTML communes (genre &amp; → &) pour que le markdown
	//    qui suit puisse être matché correctement.
	$cleaned = html_entity_decode( $cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	// 3) Markdown léger.
	//    Lien d'abord pour éviter que le ** dans un titre de lien soit interprété.
	$cleaned = preg_replace_callback(
		'#\[([^\]]+)\]\(([^)]+)\)#',
		static function ( array $m ): string {
			return '<a href="' . esc_url( $m[2] ) . '" style="color:#1a1a2e; font-weight:600;">' . esc_html( $m[1] ) . '</a>';
		},
		$cleaned
	);
	//    Gras : **text** → <strong>text</strong>
	$cleaned = preg_replace( '#\*\*([^*\n]+)\*\*#', '<strong>$1</strong>', $cleaned );
	//    Italique : *text* → <em>text</em> (après gras pour pas matcher les **)
	$cleaned = preg_replace( '#(?<![\*])\*([^*\n]+)\*(?![\*])#', '<em>$1</em>', $cleaned );

	// 4) Convertit les sauts de ligne en paragraphes (= comme WP fait dans
	//    les posts). Donne du <p>…</p> propre et de l'espacement vertical.
	if ( function_exists( 'wpautop' ) ) {
		$cleaned = wpautop( $cleaned );
	}

	return $cleaned;
}

/**
 * Construit le HTML de l'email reminder. Séparé pour faciliter les tests et
 * d'éventuelles surcharges via filtre.
 */
function cordespace_waivers_build_reminder_email_html( string $first_name, string $waiver_title, string $event_name, string $event_date_str, string $sign_url ): string {
	ob_start();
	?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Document à signer</title></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color:#222; line-height:1.5;">
	<p style="margin:0 0 1rem;"><?php
		printf(
			/* translators: %s = first name */
			esc_html__( 'Bonjour %s,', 'cordespace-snippets' ),
			esc_html( $first_name !== '' ? $first_name : 'à toi' )
		);
	?></p>

	<p style="margin:0 0 1rem;"><?php esc_html_e( 'Tu as un cours dans environ 48h et il te reste un document à signer avant de pouvoir y participer :', 'cordespace-snippets' ); ?></p>

	<div style="background:#fff3cd; border:1px solid #f0ad4e; border-left:6px solid #f0ad4e; padding:1.2rem 1.4rem; margin:1.2rem 0; border-radius:6px; color:#3c2c00;">
		<p style="margin:0 0 0.5rem; font-size:1.1em;"><strong>📄 <?php echo esc_html( $waiver_title ); ?></strong></p>
		<?php if ( $event_name !== '' ) : ?>
			<p style="margin:0; color:#5c4c00; font-size:0.95em;">
				<?php esc_html_e( 'Cours :', 'cordespace-snippets' ); ?> <?php echo esc_html( $event_name ); ?>
				<?php if ( $event_date_str !== '' ) : ?>
					<br><span style="opacity:0.85;"><?php echo esc_html( $event_date_str ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>

	<p style="margin:1.5rem 0;">
		<a href="<?php echo esc_url( $sign_url ); ?>" style="display:inline-block; padding:0.9rem 1.7rem; background:#1a1a2e; color:#fff; text-decoration:none; border-radius:5px; font-weight:700; font-size:1.05em;">
			✍️ <?php esc_html_e( 'Signer le document', 'cordespace-snippets' ); ?>
		</a>
	</p>

	<p style="margin:1rem 0; font-size:0.92em; color:#666;"><?php esc_html_e( 'La signature prend 2 minutes. Tu ne le signes qu\'une seule fois — il restera valide pour tes futurs cours du même type.', 'cordespace-snippets' ); ?></p>

	<hr style="border:none; border-top:1px solid #eee; margin:2rem 0 1rem;">

	<p style="font-size:0.85em; color:#999; margin:0.5rem 0;"><?php esc_html_e( 'Si tu as déjà signé entre temps, tu peux ignorer ce courriel.', 'cordespace-snippets' ); ?></p>
	<p style="font-size:0.85em; color:#999; margin:0.5rem 0;"><?php esc_html_e( 'À bientôt sur les cordes ! — L\'équipe Cordespace', 'cordespace-snippets' ); ?></p>
</body>
</html>
	<?php
	return (string) ob_get_clean();
}

// ============================================================================
// 6) Metabox d'édition du courriel (wp-admin → Waivers → Éditer un waiver)
// ============================================================================

/**
 * Enregistre la metabox 'Email rappel 48h' sur l'écran d'édition d'un waiver.
 * 3 champs : enable/disable, subject, body (avec WP editor) + memo placeholders.
 */
function cordespace_waivers_email_reminder_register_metabox(): void {
	add_meta_box(
		'cordespace-waiver-email-reminder',
		__( '📧 Email rappel 48h avant l\'événement', 'cordespace-snippets' ),
		'cordespace_waivers_email_reminder_render_metabox',
		CORDESPACE_WAIVER_POST_TYPE,
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes', 'cordespace_waivers_email_reminder_register_metabox' );

/**
 * Rend la metabox d'édition email.
 */
function cordespace_waivers_email_reminder_render_metabox( WP_Post $post ): void {
	wp_nonce_field( 'cordespace_waivers_email_reminder_save', 'cordespace_waivers_email_reminder_nonce' );

	// Valeurs courantes. Pour 'enabled' : défaut ON (= seul '0' désactive).
	$enabled_raw = (string) get_post_meta( $post->ID, '_cordespace_waiver_email_enabled', true );
	$is_enabled  = $enabled_raw !== '0';

	$subject = (string) get_post_meta( $post->ID, '_cordespace_waiver_email_subject', true );
	$body    = (string) get_post_meta( $post->ID, '_cordespace_waiver_email_body', true );
	?>
	<p style="margin-top:0;">
		<label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
			<input type="checkbox" name="cordespace_waiver_email_enabled" value="1" <?php checked( $is_enabled ); ?>>
			<strong><?php esc_html_e( 'Envoyer un courriel de rappel 48h avant le cours si ce document n\'est pas signé', 'cordespace-snippets' ); ?></strong>
		</label>
	</p>

	<p style="margin-top:1.2rem;">
		<label for="cordespace_waiver_email_subject"><strong><?php esc_html_e( 'Objet du courriel', 'cordespace-snippets' ); ?></strong></label><br>
		<input type="text"
		       id="cordespace_waiver_email_subject"
		       name="cordespace_waiver_email_subject"
		       value="<?php echo esc_attr( $subject ); ?>"
		       class="widefat"
		       placeholder="<?php esc_attr_e( '⚠️ Document à signer avant ton cours « {nom_cours} »', 'cordespace-snippets' ); ?>">
		<span style="font-size:0.9em; color:#666;"><?php esc_html_e( 'Si laissé vide, un objet par défaut sera utilisé.', 'cordespace-snippets' ); ?></span>
	</p>

	<p style="margin-top:1.2rem;">
		<label><strong><?php esc_html_e( 'Contenu du courriel', 'cordespace-snippets' ); ?></strong></label>
	</p>
	<?php
	wp_editor( $body, 'cordespace_waiver_email_body', [
		'textarea_name' => 'cordespace_waiver_email_body',
		'textarea_rows' => 12,
		'media_buttons' => false,
		'teeny'         => false,
		'tinymce'       => [
			'toolbar1' => 'formatselect bold italic underline bullist numlist link unlink blockquote forecolor removeformat undo redo',
			'toolbar2' => '',
		],
	] );
	?>
	<p style="font-size:0.9em; color:#666; margin-top:0.4rem;">
		<?php esc_html_e( 'Si laissé vide, un contenu par défaut (avec encadré jaune + bouton noir « Signer le document ») sera utilisé.', 'cordespace-snippets' ); ?>
		<br>
		<strong><?php esc_html_e( '💡 Astuce :', 'cordespace-snippets' ); ?></strong>
		<?php esc_html_e( 'tu peux écrire en HTML (via le bouton « Visuel ») ou en markdown léger — **gras**, *italique*, [texte du lien](url) sont automatiquement convertis à l\'envoi.', 'cordespace-snippets' ); ?>
	</p>

	<div style="margin-top:1.5rem; padding:0.9rem 1.2rem; background:#f0f6fc; border-left:4px solid #2c70b8; border-radius:0 4px 4px 0; font-size:0.94em;">
		<p style="margin:0 0 0.5rem;"><strong>🔧 <?php esc_html_e( 'Placeholders disponibles', 'cordespace-snippets' ); ?></strong> — <span style="color:#555;"><?php esc_html_e( 'à utiliser dans l\'objet OU dans le contenu :', 'cordespace-snippets' ); ?></span></p>
		<ul style="margin:0.3rem 0 0 1.2rem; padding:0;">
			<li><code>{prenom}</code> — <?php esc_html_e( 'prénom de la cliente (ou « à toi » si vide)', 'cordespace-snippets' ); ?></li>
			<li><code>{nom_cours}</code> — <?php esc_html_e( 'nom du cours/événement Amelia', 'cordespace-snippets' ); ?></li>
			<li><code>{date_cours}</code> — <?php esc_html_e( 'date du cours en français (ex : « samedi 15 juin 2026 à 18h30 »)', 'cordespace-snippets' ); ?></li>
			<li><code>{titre_document}</code> — <?php esc_html_e( 'titre de ce document (= titre du waiver)', 'cordespace-snippets' ); ?></li>
			<li><code>{lien_signature}</code> — <?php esc_html_e( 'URL directe vers la page de signature (à utiliser dans un lien)', 'cordespace-snippets' ); ?></li>
		</ul>
	</div>
	<?php
}

/**
 * Sauve les 3 champs du formulaire metabox dans post_meta.
 */
function cordespace_waivers_email_reminder_save_metabox( int $post_id ): void {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( get_post_type( $post_id ) !== CORDESPACE_WAIVER_POST_TYPE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['cordespace_waivers_email_reminder_nonce'] )
	     || ! wp_verify_nonce(
	           sanitize_text_field( wp_unslash( $_POST['cordespace_waivers_email_reminder_nonce'] ) ),
	           'cordespace_waivers_email_reminder_save'
	     ) ) {
		return;
	}

	// Enabled : '1' si coché, '0' sinon.
	$enabled = isset( $_POST['cordespace_waiver_email_enabled'] ) ? '1' : '0';
	update_post_meta( $post_id, '_cordespace_waiver_email_enabled', $enabled );

	// Subject : sanitize_text_field (pas de HTML)
	$subject = isset( $_POST['cordespace_waiver_email_subject'] )
		? sanitize_text_field( wp_unslash( $_POST['cordespace_waiver_email_subject'] ) )
		: '';
	update_post_meta( $post_id, '_cordespace_waiver_email_subject', $subject );

	// Body : wp_kses_post (HTML autorisé limité, mais les balises courantes
	// pour un email passent : p, br, strong, em, ul, ol, li, a, h1-h6, blockquote, span avec style)
	$body = isset( $_POST['cordespace_waiver_email_body'] )
		? wp_kses_post( wp_unslash( $_POST['cordespace_waiver_email_body'] ) )
		: '';
	update_post_meta( $post_id, '_cordespace_waiver_email_body', $body );
}
add_action( 'save_post', 'cordespace_waivers_email_reminder_save_metabox' );
