<?php
/**
 * Module: waivers-defaults
 *
 * Configure quels waivers s'appliquent par défaut à un event Amelia,
 * selon les **étiquettes (tags) Amelia** portées par l'event.
 *
 * Modèle whitelist : chaque waiver liste les tags Amelia auxquels il
 * s'applique. Si un event porte au moins un tag présent dans cette liste,
 * le waiver est requis pour ce·tte client·e (sauf override per-event à Task 6).
 *
 * Meta post sur chaque cordespace_waiver :
 *   _cordespace_waiver_apply_to_amelia_tags  string[]  (noms de tags Amelia)
 *
 * Dépend de :
 *   - waivers-cpt (constante CORDESPACE_WAIVER_POST_TYPE)
 *   - Plugin Amelia (table wphu_amelia_events_tags)
 *
 * Voir docs/superpowers/specs/waivers.md §2.2 et §4.1
 */

defined( 'ABSPATH' ) || exit;

const CORDESPACE_WAIVER_META_APPLY_TO_AMELIA_TAGS = '_cordespace_waiver_apply_to_amelia_tags';

/**
 * Liste des tags Amelia auxquels CE waiver s'applique.
 *
 * @return string[]
 */
function cordespace_waivers_get_apply_to_amelia_tags( int $waiver_id ): array {
	$raw = get_post_meta( $waiver_id, CORDESPACE_WAIVER_META_APPLY_TO_AMELIA_TAGS, true );
	if ( ! is_array( $raw ) ) {
		return [];
	}
	return array_values( array_filter( $raw, 'is_string' ) );
}

/**
 * Tags Amelia portés par un event donné.
 *
 * @return string[]
 */
function cordespace_waivers_amelia_event_tags( int $event_id ): array {
	global $wpdb;
	$table = $wpdb->prefix . 'amelia_events_tags';
	$names = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT name FROM {$table} WHERE eventId = %d",
		$event_id
	) );
	return is_array( $names ) ? $names : [];
}

/**
 * Renvoie la liste des waivers (publish) qui s'appliquent à un event Amelia
 * via au moins un tag commun. Ne tient PAS compte d'overrides per-event
 * (ceux-ci viendront dans Task 6).
 *
 * @return int[] waiver IDs
 */
function cordespace_waivers_applicable_defaults_for_amelia_event( int $event_id ): array {
	$event_tags = cordespace_waivers_amelia_event_tags( $event_id );
	if ( empty( $event_tags ) ) {
		return [];
	}

	$waivers = get_posts( [
		'post_type'      => CORDESPACE_WAIVER_POST_TYPE,
		'posts_per_page' => 100,
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	] );

	$out = [];
	foreach ( $waivers as $wid ) {
		$wid        = (int) $wid;
		$apply_tags = cordespace_waivers_get_apply_to_amelia_tags( $wid );
		if ( empty( $apply_tags ) ) {
			continue;
		}
		if ( array_intersect( $apply_tags, $event_tags ) ) {
			$out[] = $wid;
		}
	}
	return $out;
}

/**
 * Ajoute la metabox de configuration sur l'écran d'édition du waiver.
 */
function cordespace_waivers_add_defaults_metabox(): void {
	add_meta_box(
		'cordespace-waiver-defaults',
		__( 'Application par défaut (étiquettes Amelia)', 'cordespace-snippets' ),
		'cordespace_waivers_render_defaults_metabox',
		CORDESPACE_WAIVER_POST_TYPE,
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes_' . CORDESPACE_WAIVER_POST_TYPE, 'cordespace_waivers_add_defaults_metabox' );

/**
 * Rend la metabox : liste de toutes les étiquettes Amelia connues, avec checkbox.
 */
function cordespace_waivers_render_defaults_metabox( WP_Post $post ): void {
	wp_nonce_field( 'cordespace_waiver_defaults', 'cordespace_waiver_defaults_nonce' );

	$selected = cordespace_waivers_get_apply_to_amelia_tags( $post->ID );

	global $wpdb;
	$table     = $wpdb->prefix . 'amelia_events_tags';
	$tags_rows = $wpdb->get_results(
		"SELECT name, COUNT(*) AS uses FROM {$table} GROUP BY name ORDER BY name ASC"
	);

	?>
	<p class="description">
		<?php esc_html_e( 'Coche les étiquettes Amelia pour lesquelles ce waiver doit être signé. Tout événement portant AU MOINS UNE de ces étiquettes nécessitera la signature de ce waiver à l\'achat. Les événements sans étiquette correspondante ne demanderont rien.', 'cordespace-snippets' ); ?>
	</p>
	<?php
	if ( empty( $tags_rows ) ) {
		echo '<p><em>' . esc_html__( 'Aucune étiquette Amelia trouvée. Crée des événements avec des étiquettes dans Amelia, puis reviens ici.', 'cordespace-snippets' ) . '</em></p>';
		return;
	}
	?>
	<ul style="columns:2; margin:0.5em 0; column-gap:1.5em;">
		<?php foreach ( $tags_rows as $tag ) :
			$name    = (string) $tag->name;
			$uses    = (int) $tag->uses;
			$checked = in_array( $name, $selected, true ) ? 'checked' : '';
			?>
			<li style="break-inside:avoid; margin-bottom:0.3em;">
				<label>
					<input type="checkbox" name="cordespace_waiver_apply_to_amelia_tags[]" value="<?php echo esc_attr( $name ); ?>" <?php echo $checked; ?>>
					<?php echo esc_html( $name ); ?>
					<span style="color:#999;">(<?php echo (int) $uses; ?>&nbsp;event<?php echo $uses > 1 ? 's' : ''; ?>)</span>
				</label>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}

/**
 * Sauvegarde les tags cochés. Nonce + capability + autosave guard.
 */
function cordespace_waivers_save_defaults_meta( int $post_id, WP_Post $post ): void {
	if ( ! isset( $_POST['cordespace_waiver_defaults_nonce'] )
	     || ! wp_verify_nonce( (string) $_POST['cordespace_waiver_defaults_nonce'], 'cordespace_waiver_defaults' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$tags = isset( $_POST['cordespace_waiver_apply_to_amelia_tags'] )
	        && is_array( $_POST['cordespace_waiver_apply_to_amelia_tags'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['cordespace_waiver_apply_to_amelia_tags'] ) )
		: [];

	update_post_meta(
		$post_id,
		CORDESPACE_WAIVER_META_APPLY_TO_AMELIA_TAGS,
		array_values( array_unique( array_filter( $tags ) ) )
	);
}
add_action( 'save_post_' . CORDESPACE_WAIVER_POST_TYPE, 'cordespace_waivers_save_defaults_meta', 10, 2 );
