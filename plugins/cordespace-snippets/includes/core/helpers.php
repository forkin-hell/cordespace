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
 * Force la plage de dates par défaut des panels Amelia à 1 an dans le futur.
 */
if ( ! function_exists( 'cordespace_render_amelia_default_range' ) ) {
	function cordespace_render_amelia_default_range() {
		?>
		<script>
		(function () {
			function setMinCookie(name, minVal) {
				var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]+)'));
				var current = m ? parseInt(m[1], 10) : 0;
				if (isNaN(current) || current < minVal) {
					document.cookie = name + '=' + minVal + '; path=/; max-age=31536000';
				}
			}
			setMinCookie('ameliaRangeFuture', 365);
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
