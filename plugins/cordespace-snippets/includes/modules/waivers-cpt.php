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
