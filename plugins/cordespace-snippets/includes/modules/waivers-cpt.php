<?php
/**
 * Module: waivers-cpt
 *
 * Custom Post Type `cordespace_waiver` : un waiver = un document à signer
 * (reconnaissance des risques, décharge de responsabilité, code de conduite,
 * conditions de participation, etc. — n'importe quel doc qu'on veut faire
 * signer en ligne avec valeur LCJTI).
 *
 *   - post_title   = titre affiché au·à la client·e
 *   - post_content = texte complet du waiver (HTML, édité dans le WYSIWYG)
 *   - meta `_cordespace_waiver_version` (string) : si tu bumpes la version,
 *     toutes les signatures précédentes sont considérées obsolètes et les
 *     personnes doivent re-signer avant leur prochaine inscription.
 *
 * Accessible depuis Cordespace → Waivers (sous-menu auto-injecté via show_in_menu).
 *
 * Voir docs/superpowers/specs/waivers.md §2.1
 */

defined( 'ABSPATH' ) || exit;

const CORDESPACE_WAIVER_POST_TYPE    = 'cordespace_waiver';
const CORDESPACE_WAIVER_META_VERSION = '_cordespace_waiver_version';

/**
 * Enregistre le CPT au hook init.
 *
 * Note sur le menu : on met show_in_menu = false ICI et on enregistre le
 * sous-menu manuellement plus bas (cordespace_waivers_register_admin_submenu)
 * à une priorité explicite (30), pour garantir l'ordre voulu dans Cordespace :
 *   1. Modules (priorité 10)
 *   2. Lier des comptes / Rapports (priorité ~10-20)
 *   3. Waivers (priorité 30) ← ici
 *   4. Voir sur GitHub (priorité 100, toujours dernier)
 *
 * Si on laissait show_in_menu = 'cordespace-modules', l'auto-injection des
 * CPT submenus par WP arrive à une priorité non contrôlable et peut placer
 * Waivers APRÈS le lien GitHub. D'où le contrôle manuel.
 */
function cordespace_waivers_register_cpt(): void {
	register_post_type(
		CORDESPACE_WAIVER_POST_TYPE,
		[
			'label'           => __( 'Waivers', 'cordespace-snippets' ),
			'labels'          => [
				'name'          => __( 'Waivers', 'cordespace-snippets' ),
				'singular_name' => __( 'Waiver', 'cordespace-snippets' ),
				'add_new'       => __( 'Ajouter', 'cordespace-snippets' ),
				'add_new_item'  => __( 'Nouveau waiver', 'cordespace-snippets' ),
				'edit_item'     => __( 'Éditer le waiver', 'cordespace-snippets' ),
				'search_items'  => __( 'Rechercher un waiver', 'cordespace-snippets' ),
				'not_found'     => __( 'Aucun waiver.', 'cordespace-snippets' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false, // on enregistre le submenu manuellement (priorité explicite)
			'supports'        => [ 'title', 'editor', 'revisions' ],
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'rewrite'         => false,
			'has_archive'     => false,
			'menu_icon'       => 'dashicons-shield',
		]
	);
}
add_action( 'init', 'cordespace_waivers_register_cpt' );

/**
 * Enregistre le sous-menu « Waivers » dans Cordespace à une priorité explicite
 * (30, entre Modules à 10 et le lien GitHub à 100).
 */
function cordespace_waivers_register_admin_submenu(): void {
	add_submenu_page(
		'cordespace-modules',
		__( 'Waivers', 'cordespace-snippets' ),
		__( 'Waivers', 'cordespace-snippets' ),
		'edit_posts',
		'edit.php?post_type=' . CORDESPACE_WAIVER_POST_TYPE
	);
}
add_action( 'admin_menu', 'cordespace_waivers_register_admin_submenu', 30 );

/**
 * Highlight visuel : quand on est sur l'écran d'édition d'un waiver (ou la
 * liste), WP par défaut ne sait plus que le parent est 'cordespace-modules'
 * (puisqu'on a coupé show_in_menu). On force l'ouverture du menu Cordespace
 * et la sélection du sous-menu Waivers via les globaux parent_file/submenu_file.
 */
function cordespace_waivers_highlight_admin_menu( string $parent_file ): string {
	global $current_screen, $submenu_file;
	if ( $current_screen && $current_screen->post_type === CORDESPACE_WAIVER_POST_TYPE ) {
		$submenu_file = 'edit.php?post_type=' . CORDESPACE_WAIVER_POST_TYPE;
		return 'cordespace-modules';
	}
	return $parent_file;
}
add_filter( 'parent_file', 'cordespace_waivers_highlight_admin_menu' );

/**
 * Ajoute la metabox "Version" sur la sidebar d'édition du waiver.
 */
function cordespace_waivers_add_version_metabox(): void {
	add_meta_box(
		'cordespace-waiver-version',
		__( 'Version', 'cordespace-snippets' ),
		'cordespace_waivers_render_version_metabox',
		CORDESPACE_WAIVER_POST_TYPE,
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes_' . CORDESPACE_WAIVER_POST_TYPE, 'cordespace_waivers_add_version_metabox' );

/**
 * Rend le contenu de la metabox version (input texte + description).
 */
function cordespace_waivers_render_version_metabox( WP_Post $post ): void {
	wp_nonce_field( 'cordespace_waiver_meta', 'cordespace_waiver_meta_nonce' );
	$version = (string) get_post_meta( $post->ID, CORDESPACE_WAIVER_META_VERSION, true );
	if ( $version === '' ) {
		$version = '1';
	}
	?>
	<p>
		<label for="cordespace-waiver-version"><strong><?php esc_html_e( 'Version', 'cordespace-snippets' ); ?></strong></label><br>
		<input type="text"
		       id="cordespace-waiver-version"
		       name="cordespace_waiver_version"
		       value="<?php echo esc_attr( $version ); ?>"
		       class="widefat">
	</p>
	<p class="description">
		<?php esc_html_e( 'Bumper la version force tou·te·s les client·e·s à re-signer le waiver avant leur prochaine inscription (les signatures précédentes sont alors considérées obsolètes).', 'cordespace-snippets' ); ?>
	</p>
	<?php
}

/**
 * Sauvegarde la version (avec nonce + capability checks).
 */
function cordespace_waivers_save_version_meta( int $post_id, WP_Post $post ): void {
	// Nonce
	if ( ! isset( $_POST['cordespace_waiver_meta_nonce'] )
	     || ! wp_verify_nonce( (string) $_POST['cordespace_waiver_meta_nonce'], 'cordespace_waiver_meta' ) ) {
		return;
	}
	// Autosave : on ne touche pas
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	// Capability
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$version = isset( $_POST['cordespace_waiver_version'] )
		? sanitize_text_field( (string) wp_unslash( $_POST['cordespace_waiver_version'] ) )
		: '1';
	if ( $version === '' ) {
		$version = '1';
	}
	update_post_meta( $post_id, CORDESPACE_WAIVER_META_VERSION, $version );
}
add_action( 'save_post_' . CORDESPACE_WAIVER_POST_TYPE, 'cordespace_waivers_save_version_meta', 10, 2 );

/**
 * Helper public : retourne la version d'un waiver (ou "1" si non définie).
 * Utilisé par les modules qui comparent la version actuelle à celle d'une
 * signature stockée pour décider si la personne doit re-signer.
 */
function cordespace_waivers_get_version( int $waiver_id ): string {
	$v = (string) get_post_meta( $waiver_id, CORDESPACE_WAIVER_META_VERSION, true );
	return $v !== '' ? $v : '1';
}

// ─── Boîte « Signatures » (qui a consenti, quand, quelle version) ───────────

function cordespace_waivers_add_signatures_metabox(): void {
	add_meta_box(
		'cordespace_waiver_signatures',
		'✍️ Signatures',
		'cordespace_waivers_render_signatures_metabox',
		CORDESPACE_WAIVER_POST_TYPE,
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes_' . CORDESPACE_WAIVER_POST_TYPE, 'cordespace_waivers_add_signatures_metabox' );

/**
 * Date de signature (stockée en UTC) → « 21 octobre 2026 à 9 h 15 » en heure du site.
 */
function cordespace_waivers_format_signed_at( string $signed_at_utc ): string {
	$ts = strtotime( $signed_at_utc . ' UTC' );
	if ( ! $ts ) {
		return $signed_at_utc;
	}
	return wp_date( 'j F Y à G \h i', $ts, wp_timezone() );
}

function cordespace_waivers_render_signatures_metabox( $post ): void {
	if ( ! function_exists( 'cordespace_waivers_signatures_for_waiver' ) ) {
		echo '<p>' . esc_html__( 'Le module « CRUD signatures (helpers) » doit être actif pour afficher les signatures.', 'cordespace-snippets' ) . '</p>';
		return;
	}
	$waiver_id = (int) $post->ID;
	$rows      = cordespace_waivers_signatures_for_waiver( $waiver_id );
	if ( ! $rows ) {
		echo '<p style="color:#555;margin:0;">' . esc_html__( 'Aucune signature pour l’instant.', 'cordespace-snippets' ) . '</p>';
		return;
	}
	$version = cordespace_waivers_get_version( $waiver_id );
	$total   = cordespace_waivers_signatures_count( $waiver_id );
	$current = cordespace_waivers_signatures_count( $waiver_id, $version );
	echo '<p style="margin:0 0 0.8rem;">'
		. '<strong>' . esc_html( sprintf( _n( '%d signature', '%d signatures', $total, 'cordespace-snippets' ), $total ) ) . '</strong>, '
		. esc_html( sprintf( __( 'dont %1$d pour la version en vigueur (%2$s).', 'cordespace-snippets' ), $current, $version ) )
		. '</p>';
	echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>'
		. '<th>' . esc_html__( 'Nom', 'cordespace-snippets' ) . '</th>'
		. '<th>' . esc_html__( 'Courriel', 'cordespace-snippets' ) . '</th>'
		. '<th>' . esc_html__( 'Version signée', 'cordespace-snippets' ) . '</th>'
		. '<th>' . esc_html__( 'Date', 'cordespace-snippets' ) . '</th>'
		. '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$name    = esc_html( $r['signed_name'] );
		$name    = $r['user_id'] > 0 ? '<a href="' . esc_url( get_edit_user_link( $r['user_id'] ) ) . '">' . $name . '</a>' : $name;
		$note    = cordespace_waivers_signature_origin_note( $r );
		$is_cur  = $r['waiver_version'] === $version;
		echo '<tr>'
			. '<td>' . $name . ( $note !== '' ? '<br><span style="color:#777;font-size:0.92em;">' . esc_html( $note ) . '</span>' : '' ) . '</td>'
			. '<td>' . esc_html( $r['email'] ) . '</td>'
			. '<td' . ( $is_cur ? '' : ' style="color:#8a5a00;" title="Version antérieure : cette personne devra re-signer la version en vigueur."' ) . '>' . esc_html( $r['waiver_version'] ) . '</td>'
			. '<td>' . esc_html( cordespace_waivers_format_signed_at( $r['signed_at'] ) ) . '</td>'
			. '</tr>';
	}
	echo '</tbody></table>';
}

/**
 * Mention d'origine d'une signature : « invité·e par {hôte} » pour une signature de partenaire
 * (module Partenaires), « sans compte » pour une signature sans compte ni hôte connu.
 */
function cordespace_waivers_signature_origin_note( array $row ): string {
	$host_id = (int) ( $row['invited_by_user_id'] ?? 0 );
	if ( $host_id > 0 ) {
		$host = get_userdata( $host_id );
		$who  = $host && ! empty( $host->display_name ) ? (string) $host->display_name : sprintf( __( 'compte #%d', 'cordespace-snippets' ), $host_id );
		return sprintf( __( 'invité·e par %s', 'cordespace-snippets' ), $who );
	}
	return (int) ( $row['user_id'] ?? 0 ) > 0 ? '' : __( 'sans compte', 'cordespace-snippets' );
}

// ─── Colonne « Signatures » dans la liste des waivers ───────────────────────

function cordespace_waivers_signatures_columns( array $columns ): array {
	$out = [];
	foreach ( $columns as $key => $label ) {
		if ( $key === 'date' ) {
			$out['cordespace_signatures'] = __( 'Signatures', 'cordespace-snippets' );
		}
		$out[ $key ] = $label;
	}
	if ( ! isset( $out['cordespace_signatures'] ) ) {
		$out['cordespace_signatures'] = __( 'Signatures', 'cordespace-snippets' );
	}
	return $out;
}
add_filter( 'manage_' . CORDESPACE_WAIVER_POST_TYPE . '_posts_columns', 'cordespace_waivers_signatures_columns' );

function cordespace_waivers_signatures_column( string $column, int $post_id ): void {
	if ( $column !== 'cordespace_signatures' ) {
		return;
	}
	echo function_exists( 'cordespace_waivers_signatures_count' ) ? (int) cordespace_waivers_signatures_count( $post_id ) : '—';
}
add_action( 'manage_' . CORDESPACE_WAIVER_POST_TYPE . '_posts_custom_column', 'cordespace_waivers_signatures_column', 10, 2 );
