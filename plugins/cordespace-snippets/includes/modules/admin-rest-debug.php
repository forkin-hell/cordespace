<?php
/**
 * Module : admin.rest-debug
 *
 * Journal de diagnostic pour l'app mobile WooCommerce : chaque écriture
 * (POST / PUT / DELETE) sur l'API commandes et clients (wc/v3/orders*,
 * wc/v3/customers*) est consignée dans les journaux WooCommerce
 * (WooCommerce → Statut → Journaux, source « cordespace-rest-debug ») :
 * compte, méthode, route, code HTTP, et en cas d'erreur le code + message
 * renvoyés par WordPress et le détail des paramètres invalides.
 *
 * Pourquoi : octobre 2026, certains membres de l'équipe ne peuvent pas
 * modifier une commande ni changer son statut depuis l'app (« Impossible
 * d'enregistrer les modifications ») alors que leur compte réussit les
 * mêmes appels en rejeu. Il faut voir la réponse réelle du serveur à leur
 * téléphone. Module à activer le temps du diagnostic, puis à désactiver.
 *
 * Données : seules les CLÉS des paramètres envoyés sont journalisées (pas
 * les valeurs), sauf pour `billing` / `shipping` où l'on note quels champs
 * sont vides — c'est le suspect n°1 (courriel vide refusé par l'API).
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'rest_post_dispatch', 'cordespace_rest_debug_log', 20, 3 );

/**
 * Résumé loggable des paramètres : clés de premier niveau ; pour billing et
 * shipping, la liste des champs vides ; pour line_items, le nombre.
 */
function cordespace_rest_debug_summarize_params( array $params ): string {
	$parts = [];
	foreach ( $params as $key => $value ) {
		if ( in_array( $key, [ 'billing', 'shipping' ], true ) && is_array( $value ) ) {
			$empty = array_keys( array_filter( $value, static fn( $v ) => $v === '' || $v === null ) );
			$parts[] = $key . '{' . count( $value ) . ' champs' . ( $empty ? ', vides: ' . implode( ',', $empty ) : '' ) . '}';
		} elseif ( $key === 'line_items' && is_array( $value ) ) {
			$parts[] = 'line_items[' . count( $value ) . ']';
		} elseif ( $key === 'status' ) {
			$parts[] = 'status=' . ( is_scalar( $value ) ? (string) $value : '?' );
		} else {
			$parts[] = (string) $key;
		}
	}
	return implode( ' ', $parts );
}

/**
 * @param WP_HTTP_Response|WP_Error $result
 * @param WP_REST_Server            $server
 * @param WP_REST_Request           $request
 * @return WP_HTTP_Response|WP_Error
 */
function cordespace_rest_debug_log( $result, $server, $request ) {
	$route  = (string) $request->get_route();
	$method = strtoupper( (string) $request->get_method() );
	if ( ! preg_match( '#^/wc/v3/(orders|customers)#', $route ) || ! in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
		return $result;
	}
	if ( ! function_exists( 'wc_get_logger' ) ) {
		return $result;
	}

	$status = 0;
	$error  = '';
	if ( is_wp_error( $result ) ) {
		$status = 500;
		$error  = $result->get_error_code() . ' — ' . $result->get_error_message();
	} elseif ( is_object( $result ) && method_exists( $result, 'get_status' ) ) {
		$status = (int) $result->get_status();
		if ( $status >= 400 ) {
			$data  = (array) $result->get_data();
			$error = (string) ( $data['code'] ?? '' ) . ' — ' . (string) ( $data['message'] ?? '' );
			if ( ! empty( $data['data']['params'] ) ) {
				$error .= ' | params: ' . wp_json_encode( $data['data']['params'], JSON_UNESCAPED_UNICODE );
			}
		}
	}

	$user   = wp_get_current_user();
	$params = (array) $request->get_json_params();
	if ( ! $params ) {
		$params = (array) $request->get_body_params();
	}
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 80 ) : '';

	$line = sprintf(
		'%s %s → HTTP %d | user #%d %s | params: %s | UA: %s%s',
		$method,
		$route,
		$status,
		(int) $user->ID,
		(string) $user->user_login,
		cordespace_rest_debug_summarize_params( $params ),
		$ua,
		$error !== '' ? ' | ERREUR: ' . $error : ''
	);

	$logger = wc_get_logger();
	$ctx    = [ 'source' => 'cordespace-rest-debug' ];
	if ( $status >= 400 ) {
		$logger->warning( $line, $ctx );
	} else {
		$logger->info( $line, $ctx );
	}
	return $result;
}
