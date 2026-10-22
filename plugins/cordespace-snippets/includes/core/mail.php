<?php
/**
 * Helper courriel partagé : envoi HTML avec l'expéditeur des courriels WooCommerce
 * (Réglages → Emails) et un Reply-To identique, sans laisser de filtre derrière soi.
 *
 * Utilisé par la liste d'attente ; les rappels waivers (branche feature/waivers-editor)
 * ont leur propre copie à rapatrier ici après leur merge.
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'cordespace_mail_from' ) ) {
	/**
	 * Expéditeur : adresse et nom des courriels WooCommerce, repli admin_email / blogname.
	 * Filtre : cordespace_mail_from( [ 'email' => …, 'name' => … ] ).
	 */
	function cordespace_mail_from(): array {
		$email = (string) get_option( 'woocommerce_email_from_address', '' );
		$name  = (string) get_option( 'woocommerce_email_from_name', '' );
		if ( ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email', '' );
		}
		if ( $name === '' ) {
			$name = (string) get_option( 'blogname', '' );
		}
		$from = (array) apply_filters( 'cordespace_mail_from', [ 'email' => $email, 'name' => $name ] );
		// Durcissement après filtre : rien ne doit pouvoir injecter un en-tête (CR/LF) ni une adresse invalide.
		$from['name']  = str_replace( [ "\r", "\n" ], '', (string) ( $from['name'] ?? '' ) );
		$from['email'] = is_email( (string) ( $from['email'] ?? '' ) ) ? (string) $from['email'] : '';
		return $from;
	}
}

if ( ! function_exists( 'cordespace_mail' ) ) {
	/**
	 * Envoie un courriel HTML avec l'expéditeur partagé. Retourne le résultat de wp_mail.
	 */
	function cordespace_mail( string $to, string $subject, string $html ): bool {
		$from       = cordespace_mail_from();
		$from_email = static fn() => $from['email'];
		$from_name  = static fn() => $from['name'];
		$headers    = [ 'Content-Type: text/html; charset=UTF-8' ];
		$valid      = is_email( $from['email'] );
		if ( $valid ) {
			$headers[] = 'Reply-To: ' . ( $from['name'] !== '' ? $from['name'] . ' <' . $from['email'] . '>' : $from['email'] );
			add_filter( 'wp_mail_from', $from_email, 99 );
			add_filter( 'wp_mail_from_name', $from_name, 99 );
		}
		$sent = wp_mail( $to, $subject, $html, $headers );
		if ( $valid ) {
			remove_filter( 'wp_mail_from', $from_email, 99 );
			remove_filter( 'wp_mail_from_name', $from_name, 99 );
		}
		return (bool) $sent;
	}
}
