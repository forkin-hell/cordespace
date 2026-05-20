<?php
/**
 * Helpers PHP partagés, toujours chargés au boot.
 *
 * Ces fonctions sont utilisées par plusieurs modules. Elles vivent ici pour
 * éviter qu'un module dépende d'un autre.
 *
 * Voir docs/superpowers/specs/2026-05-18-cordespace-admin-toggles-design.md
 */

defined( 'ABSPATH' ) || exit;

/**
 * Détecte si un user WP donné est un·e provider dans Amelia.
 *
 * Cache statique : 1 query par user, par requête PHP.
 */
if ( ! function_exists( 'cordespace_user_is_amelia_provider' ) ) {
	function cordespace_user_is_amelia_provider( int $wp_user_id ): bool {
		if ( $wp_user_id <= 0 ) return false;
		static $cache = [];
		if ( isset( $cache[ $wp_user_id ] ) ) return $cache[ $wp_user_id ];
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}amelia_users
				WHERE externalId = %d AND type IN ('provider', 'manager') AND status = 'visible'",
			$wp_user_id
		) );
		$cache[ $wp_user_id ] = $count > 0;
		return $cache[ $wp_user_id ];
	}
}

/**
 * Résout dynamiquement l'URL de la page qui contient [cordespace_mon_espace].
 *
 * Évite de hardcoder /mon-espace/ : si l'admin renomme le slug, ça suit.
 * Cache transient 1h pour ne pas tape la DB à chaque page.
 */
if ( ! function_exists( 'cordespace_get_mon_espace_url' ) ) {
	function cordespace_get_mon_espace_url(): string {
		$cached = get_transient( 'cordespace_mon_espace_url' );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}
		global $wpdb;
		$id = (int) $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts}
				WHERE post_status = 'publish'
				  AND post_type = 'page'
				  AND post_content LIKE '%[cordespace_mon_espace%'
				LIMIT 1"
		);
		$url = $id > 0 ? get_permalink( $id ) : home_url( '/mon-espace/' );
		set_transient( 'cordespace_mon_espace_url', $url, HOUR_IN_SECONDS );
		return $url;
	}
}

/**
 * Invalide la cache transient de cordespace_get_mon_espace_url() dès qu'une
 * page est sauvegardée ou (dé)trashée. Si l'admin renomme la page « Mon
 * espace » / « Mon compte », le helper renvoie tout de suite la nouvelle URL
 * au lieu d'attendre l'expiration de 1h.
 *
 * Cible toutes les pages, pas seulement celle qui contient le shortcode :
 * détecter laquelle contient le shortcode coûterait une requête supplémentaire
 * à chaque save_post, alors qu'invalider la cache et la rebuilder à la
 * prochaine lecture coûte presque rien.
 */
add_action( 'save_post_page', 'cordespace_invalidate_mon_espace_url_cache' );
add_action( 'trashed_post', 'cordespace_invalidate_mon_espace_url_cache' );
add_action( 'untrashed_post', 'cordespace_invalidate_mon_espace_url_cache' );

if ( ! function_exists( 'cordespace_invalidate_mon_espace_url_cache' ) ) {
	function cordespace_invalidate_mon_espace_url_cache(): void {
		delete_transient( 'cordespace_mon_espace_url' );
	}
}

/**
 * Force la plage de dates par défaut des panels Amelia (cookie ameliaRangeFuture).
 *
 * @param int $days Nombre de jours dans le futur à afficher. Par défaut 365
 *                  (1 an, utile pour la vue cliente qui veut voir tous ses
 *                  cours à venir). Pour la vue prof, on appelle avec 92
 *                  (≈3 mois) pour ne pas overlap avec des events trop lointains.
 *
 * Le cookie est écrasé à chaque appel : permet d'avoir une range différente
 * selon la vue rendue (prof vs cliente), sans état persistant qui colle.
 */
if ( ! function_exists( 'cordespace_render_amelia_default_range' ) ) {
	function cordespace_render_amelia_default_range( int $days = 365 ) {
		$days = max( 1, $days ); // garde-fou
		?>
		<script>
		(function () {
			// Écrase systématiquement la cookie pour cette vue (3 mois prof,
			// 1 an cliente, etc.). Si l'user va dans une autre vue, l'autre
			// appel écrasera à nouveau avec sa propre valeur.
			document.cookie = 'ameliaRangeFuture=<?php echo (int) $days; ?>; path=/; max-age=31536000';
		})();
		</script>
		<?php
	}
}

/**
 * JS auto-clear : détecte désynchro cookie Amelia ↔ session WP.
 */
if ( ! function_exists( 'cordespace_render_amelia_cookie_sync' ) ) {
	function cordespace_render_amelia_cookie_sync( $user ) {
		$expected_email = strtolower( (string) $user->user_email );
		?>
		<script>
		(function () {
			var expectedEmail = <?php echo wp_json_encode( $expected_email ); ?>;
			var m = document.cookie.match(/(?:^|; )ameliaUserEmail=([^;]+)/);
			var cookieEmail = m ? decodeURIComponent(m[1]).toLowerCase() : null;
			if (cookieEmail && cookieEmail !== expectedEmail) {
				document.cookie = 'ameliaToken=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
				document.cookie = 'ameliaUserEmail=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
				if (!sessionStorage.getItem('cordespace_amelia_sync_done')) {
					sessionStorage.setItem('cordespace_amelia_sync_done', '1');
					window.location.reload();
				}
			} else {
				sessionStorage.removeItem('cordespace_amelia_sync_done');
			}
		})();
		</script>
		<?php
	}
}

/**
 * Renvoie le prénom + nom de l'utilisateur·trice pour les salutations.
 *
 * Cascade de fallback :
 *   1. first_name + last_name (meta WP, fournis en général par Amelia)
 *   2. display_name (configuré dans le profil WP)
 *   3. user_login (en dernier recours)
 *
 * @param WP_User|null $user Objet WP_User. Si null, retourne chaîne vide.
 */
if ( ! function_exists( 'cordespace_user_greeting_name' ) ) {
	function cordespace_user_greeting_name( $user ): string {
		if ( ! $user || ! isset( $user->ID ) ) {
			return '';
		}
		$first = trim( (string) ( $user->first_name ?? '' ) );
		$last  = trim( (string) ( $user->last_name ?? '' ) );
		$name  = trim( $first . ' ' . $last );
		if ( $name === '' ) {
			$name = trim( (string) ( $user->display_name ?? '' ) );
		}
		if ( $name === '' ) {
			$name = (string) ( $user->user_login ?? '' );
		}
		return $name;
	}
}

/**
 * Renvoie true si le module donné est activé dans wp_options.
 *
 * Utilisé par l'enveloppe mon-espace.php pour décider d'émettre ou non
 * un do_action vers les sous-sections (qui de toute façon ne sont
 * chargées que si actives — c'est une seconde ligne de défense).
 */
if ( ! function_exists( 'cordespace_modules_is_active' ) ) {
	function cordespace_modules_is_active( string $module_id ): bool {
		static $active = null;
		if ( $active === null ) {
			$active = (array) get_option( 'cordespace_modules_active', [] );
		}
		return in_array( $module_id, $active, true );
	}
}
