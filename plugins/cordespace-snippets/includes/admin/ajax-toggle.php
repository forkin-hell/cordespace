<?php
/**
 * Endpoint AJAX d'activation/désactivation d'un module.
 *
 * Action WP : cordespace_toggle_module
 * Méthode : POST (admin-ajax.php)
 * Paramètres :
 *   - _wpnonce  : nonce 'cordespace_toggle_module'
 *   - module_id : ID d'un module présent dans le registry
 *   - active    : '1' pour activer, autre valeur pour désactiver
 *
 * Sécurité : capability manage_options + nonce.
 *
 * Note cache : on n'invoque PAS cordespace_modules_active_ids() après
 * update_option, parce que sa cache statique aurait été figée plus tôt
 * dans la requête. On construit la nouvelle liste localement et on la
 * renvoie au JS — qui s'en sert directement.
 *
 * Voir docs/superpowers/specs/2026-05-18-cordespace-admin-toggles-design.md
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'cordespace_ajax_toggle_module' ) ) {
	function cordespace_ajax_toggle_module(): void {
		// 1. Capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Accès refusé.', 'cordespace-snippets' ) ],
				403
			);
		}

		// 2. Nonce.
		if ( ! check_ajax_referer( 'cordespace_toggle_module', '_wpnonce', false ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Nonce invalide ou expiré. Recharge la page.', 'cordespace-snippets' ) ],
				400
			);
		}

		// 3. module_id valide.
		$module_id = isset( $_POST['module_id'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['module_id'] ) )
			: '';

		$registry = cordespace_modules_registry();
		if ( $module_id === '' || ! isset( $registry['modules'][ $module_id ] ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Module inconnu.', 'cordespace-snippets' ) ],
				404
			);
		}

		// 4. Lire l'état souhaité.
		$activate = isset( $_POST['active'] ) && (string) $_POST['active'] === '1';

		// 5. Construire la nouvelle liste localement (pas via la cache).
		$current = (array) get_option( 'cordespace_modules_active', [] );
		// Normaliser : strings uniques, indexées à partir de 0.
		$current = array_values( array_unique( array_map( 'strval', $current ) ) );

		if ( $activate ) {
			if ( ! in_array( $module_id, $current, true ) ) {
				$current[] = $module_id;
			}
		} else {
			$current = array_values( array_filter(
				$current,
				static fn( $id ) => $id !== $module_id
			) );
		}

		// 6. Persister.
		update_option( 'cordespace_modules_active', $current );

		// 7. Réponse — pas d'appel à cordespace_modules_active_ids() ici,
		// la cache statique du loader aurait pu être figée plus tôt dans
		// la requête. On renvoie l'état qu'on vient d'écrire.
		$label = $registry['modules'][ $module_id ]['label'] ?? $module_id;
		wp_send_json_success( [
			'message'   => $activate
				? sprintf(
					/* translators: %s: module label */
					__( '« %s » activé.', 'cordespace-snippets' ),
					$label
				)
				: sprintf(
					/* translators: %s: module label */
					__( '« %s » désactivé.', 'cordespace-snippets' ),
					$label
				),
			'module_id' => $module_id,
			'active'    => $activate,
			'state'     => $current,
		] );
	}
}
add_action( 'wp_ajax_cordespace_toggle_module', 'cordespace_ajax_toggle_module' );


/**
 * Endpoint AJAX bulk : tout désactiver, ou tout réactiver selon les
 * default_active du registry. Action WP : cordespace_bulk_modules
 * Paramètres :
 *   - _wpnonce : nonce 'cordespace_toggle_module' (réutilisé)
 *   - mode     : 'disable_all' | 'enable_defaults'
 */
if ( ! function_exists( 'cordespace_ajax_bulk_modules' ) ) {
	function cordespace_ajax_bulk_modules(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Accès refusé.', 'cordespace-snippets' ) ],
				403
			);
		}

		if ( ! check_ajax_referer( 'cordespace_toggle_module', '_wpnonce', false ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Nonce invalide ou expiré. Recharge la page.', 'cordespace-snippets' ) ],
				400
			);
		}

		$mode = isset( $_POST['mode'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mode'] ) )
			: '';

		$registry = cordespace_modules_registry();

		switch ( $mode ) {
			case 'disable_all':
				$new_state = [];
				$message   = __( 'Tous les modules ont été désactivés.', 'cordespace-snippets' );
				break;

			case 'enable_defaults':
				$new_state = [];
				foreach ( $registry['modules'] as $id => $module ) {
					if ( ! empty( $module['default_active'] ) ) {
						$new_state[] = (string) $id;
					}
				}
				$message = __( 'Tous les modules « actifs par défaut » ont été réactivés.', 'cordespace-snippets' );
				break;

			default:
				wp_send_json_error(
					[ 'message' => __( 'Mode bulk inconnu.', 'cordespace-snippets' ) ],
					400
				);
		}

		update_option( 'cordespace_modules_active', $new_state );

		wp_send_json_success( [
			'message' => $message,
			'state'   => $new_state,
		] );
	}
}
add_action( 'wp_ajax_cordespace_bulk_modules', 'cordespace_ajax_bulk_modules' );
