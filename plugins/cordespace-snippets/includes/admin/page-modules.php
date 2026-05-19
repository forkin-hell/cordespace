<?php
/**
 * Page d'administration "Cordespace — Modules".
 *
 * Affiche les catégories du registry, chacune avec ses modules sous forme
 * de cartes contenant un toggle iOS-style. Les changements sont appliqués
 * en AJAX (voir ajax-toggle.php) sans rechargement.
 *
 * Voir docs/superpowers/specs/2026-05-18-cordespace-admin-toggles-design.md
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rend la page principale du menu Cordespace.
 */
if ( ! function_exists( 'cordespace_admin_render_modules_page' ) ) {
	function cordespace_admin_render_modules_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cordespace-snippets' ) );
		}

		$registry = cordespace_modules_registry();
		$active   = (array) get_option( 'cordespace_modules_active', [] );

		// Trier les catégories par `order`.
		$categories = $registry['categories'];
		uasort( $categories, static function ( $a, $b ) {
			return ( $a['order'] ?? 999 ) <=> ( $b['order'] ?? 999 );
		} );

		$github_base = CORDESPACE_GITHUB_BASE_URL . '/blob/main';
		$ajax_url    = admin_url( 'admin-ajax.php' );
		$nonce       = wp_create_nonce( 'cordespace_toggle_module' );
		?>
		<div class="wrap cordespace-modules-page">
			<h1>🪢 Cordespace — Modules</h1>
			<p class="cordespace-intro">
				<?php esc_html_e( "Active ou désactive chaque morceau de code du plugin. Les changements sont appliqués immédiatement, sans rechargement.", 'cordespace-snippets' ); ?>
			</p>

			<?php if ( defined( 'CORDESPACE_SAFE_INSTALL' ) && CORDESPACE_SAFE_INSTALL ) : ?>
				<div class="notice notice-info" style="margin:1rem 0;padding:0.8rem 1rem;border-left-color:#5b2c8f;">
					<p style="margin:0;">
						🛡️ <strong>Mode safe-install actif</strong> — La constante <code>CORDESPACE_SAFE_INSTALL</code> est définie dans <code>wp-config.php</code>.
						Les nouveaux modules ne sont <strong>jamais auto-activés</strong> ; tu dois toggler chaque module à la main.
						Retire la constante de wp-config.php pour revenir au comportement par défaut (auto-activation des modules ayant <code>default_active: true</code>).
					</p>
				</div>
			<?php endif; ?>

			<?php foreach ( $categories as $cat_id => $cat ) :
				// Modules de cette catégorie.
				$cat_modules = array_filter(
					$registry['modules'],
					static fn( $m ) => ( $m['category'] ?? '' ) === $cat_id
				);
				if ( empty( $cat_modules ) ) continue;
			?>
				<section class="cordespace-cat">
					<h2 class="cordespace-cat-title">
						<span class="cordespace-cat-icon"><?php echo esc_html( $cat['icon'] ?? '' ); ?></span>
						<?php echo esc_html( $cat['label'] ); ?>
					</h2>
					<div class="cordespace-cards">
						<?php foreach ( $cat_modules as $mod_id => $mod ) :
							$is_active   = in_array( $mod_id, $active, true );
							$deps        = cordespace_module_deps_status( $mod_id );
							$deps_ok     = true;
							foreach ( $deps as $d ) {
								if ( ! $d['active'] ) { $deps_ok = false; break; }
							}
							$github_url  = $github_base . '/' . ltrim( $mod['github_path'] ?? '', '/' );
						?>
							<div class="cordespace-card<?php echo $is_active ? ' is-active' : ''; ?>" data-module-id="<?php echo esc_attr( $mod_id ); ?>">
								<div class="cordespace-card-head">
									<label class="cordespace-switch">
										<input
											type="checkbox"
											class="cordespace-toggle"
											data-module-id="<?php echo esc_attr( $mod_id ); ?>"
											<?php checked( $is_active ); ?>
										>
										<span class="cordespace-slider" aria-hidden="true"></span>
									</label>
									<div class="cordespace-card-titles">
										<h3 class="cordespace-card-title"><?php echo esc_html( $mod['label'] ); ?></h3>
										<code class="cordespace-card-id"><?php echo esc_html( $mod_id ); ?></code>
									</div>
								</div>
								<p class="cordespace-card-desc"><?php echo esc_html( $mod['description'] ); ?></p>
								<?php if ( ! empty( $deps ) ) : ?>
									<p class="cordespace-card-deps">
										<?php if ( $deps_ok ) : ?>
											<span class="cordespace-deps-ok">✓</span>
										<?php else : ?>
											<span class="cordespace-deps-warn">⚠️</span>
										<?php endif; ?>
										<?php esc_html_e( 'Plugins requis :', 'cordespace-snippets' ); ?>
										<?php
										$bits = [];
										foreach ( $deps as $plugin_file => $d ) {
											$bits[] = sprintf(
												'<span class="cordespace-dep %s">%s</span>',
												$d['active'] ? 'is-ok' : 'is-missing',
												esc_html( $d['label'] )
											);
										}
										echo wp_kses_post( implode( ', ', $bits ) );
										?>
									</p>
								<?php endif; ?>
								<p class="cordespace-card-footer">
									<a href="<?php echo esc_url( $github_url ); ?>" target="_blank" rel="noopener noreferrer">
										<?php esc_html_e( 'Voir le code sur GitHub', 'cordespace-snippets' ); ?> ↗
									</a>
								</p>
							</div>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endforeach; ?>
		</div>

		<style>
			.cordespace-modules-page { max-width: 1100px; }
			.cordespace-intro { color: #50575e; font-size: 14px; margin-bottom: 24px; }
			.cordespace-cat { margin-top: 28px; }
			.cordespace-cat-title {
				font-size: 16px;
				margin: 0 0 12px;
				padding-bottom: 6px;
				border-bottom: 1px solid #dcdcde;
				display: flex;
				align-items: center;
				gap: 8px;
			}
			.cordespace-cat-icon { font-size: 18px; }
			.cordespace-cards {
				display: grid;
				grid-template-columns: repeat( auto-fill, minmax( 320px, 1fr ) );
				gap: 12px;
			}
			.cordespace-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 6px;
				padding: 14px 16px;
				transition: border-color .2s, opacity .2s;
				opacity: .7;
			}
			.cordespace-card.is-active { opacity: 1; border-color: #2271b1; }
			.cordespace-card-head {
				display: flex;
				align-items: flex-start;
				gap: 12px;
				margin-bottom: 8px;
			}
			.cordespace-card-titles { flex: 1; min-width: 0; }
			.cordespace-card-title { margin: 0 0 2px; font-size: 14px; }
			.cordespace-card-id { font-size: 11px; color: #646970; background: #f0f0f1; padding: 1px 6px; border-radius: 3px; }
			.cordespace-card-desc { color: #50575e; font-size: 13px; margin: 0 0 8px; }
			.cordespace-card-deps { font-size: 12px; color: #50575e; margin: 0 0 8px; }
			.cordespace-deps-ok { color: #00a32a; font-weight: bold; }
			.cordespace-deps-warn { font-size: 13px; }
			.cordespace-dep { display: inline-block; padding: 0 4px; border-radius: 3px; }
			.cordespace-dep.is-ok { color: #00a32a; }
			.cordespace-dep.is-missing { color: #d63638; background: #fcf0f1; }
			.cordespace-card-footer { margin: 0; font-size: 12px; }
			.cordespace-card-footer a { color: #2271b1; text-decoration: none; }
			.cordespace-card-footer a:hover { text-decoration: underline; }

			/* Toggle iOS-style */
			.cordespace-switch {
				position: relative;
				display: inline-block;
				width: 36px;
				height: 20px;
				flex-shrink: 0;
				margin-top: 2px;
			}
			.cordespace-switch input { opacity: 0; width: 0; height: 0; }
			.cordespace-slider {
				position: absolute;
				cursor: pointer;
				inset: 0;
				background: #c3c4c7;
				border-radius: 20px;
				transition: background .2s;
			}
			.cordespace-slider::before {
				content: "";
				position: absolute;
				height: 14px;
				width: 14px;
				left: 3px;
				top: 3px;
				background: #fff;
				border-radius: 50%;
				transition: transform .2s;
			}
			.cordespace-switch input:checked + .cordespace-slider { background: #2271b1; }
			.cordespace-switch input:checked + .cordespace-slider::before { transform: translateX(16px); }
			.cordespace-switch input:disabled + .cordespace-slider { opacity: .5; cursor: wait; }
		</style>

		<script>
		(function () {
			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

			function notice( type, message ) {
				var wrap = document.querySelector('.cordespace-modules-page h1');
				if ( ! wrap ) return;
				// Retire les notices précédentes pour éviter qu'elles s'empilent.
				document.querySelectorAll('.cordespace-modules-page > .notice.cordespace-notice')
					.forEach(function (old) { old.remove(); });
				var n = document.createElement('div');
				n.className = 'notice notice-' + type + ' is-dismissible cordespace-notice';
				var p = document.createElement('p');
				p.textContent = String( message );
				n.appendChild( p );
				// WP n'attache le bouton × qu'au DOMContentLoaded, pas aux notices
				// injectées dynamiquement. On le pose à la main.
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'notice-dismiss';
				var sr = document.createElement('span');
				sr.className = 'screen-reader-text';
				sr.textContent = <?php echo wp_json_encode( __( 'Ignorer ce message.', 'cordespace-snippets' ) ); ?>;
				btn.appendChild( sr );
				btn.addEventListener('click', function () { n.remove(); });
				n.appendChild( btn );
				wrap.parentNode.insertBefore( n, wrap.nextSibling );
				setTimeout( function () { if ( n.parentNode ) n.remove(); }, 4000 );
			}

			document.querySelectorAll('.cordespace-toggle').forEach(function (cb) {
				cb.addEventListener('change', function () {
					var moduleId = cb.dataset.moduleId;
					var active   = cb.checked ? '1' : '0';
					var card     = cb.closest('.cordespace-card');
					cb.disabled = true;

					var body = new URLSearchParams();
					body.append( 'action', 'cordespace_toggle_module' );
					body.append( '_wpnonce', nonce );
					body.append( 'module_id', moduleId );
					body.append( 'active', active );

					fetch( ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						body: body
					} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							cb.disabled = false;
							if ( res && res.success ) {
								if ( card ) {
									card.classList.toggle( 'is-active', cb.checked );
								}
								notice( 'success', res.data && res.data.message ? res.data.message : 'OK' );
							} else {
								cb.checked = ! cb.checked;
								notice( 'error', ( res && res.data && res.data.message ) || 'Erreur inattendue.' );
							}
						} )
						.catch( function () {
							cb.disabled = false;
							cb.checked = ! cb.checked;
							notice( 'error', 'Erreur réseau.' );
						} );
				});
			});
		})();
		</script>
		<?php
	}
}
