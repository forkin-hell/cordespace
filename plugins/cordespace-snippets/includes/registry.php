<?php
/**
 * Manifest déclaratif des catégories et modules du plugin.
 *
 * Source de vérité unique : pour ajouter un module, on ajoute une entrée
 * ici + un fichier dans includes/modules/ ou assets/css/.
 *
 * Voir docs/superpowers/specs/2026-05-18-cordespace-admin-toggles-design.md §4.2-4.3
 */

defined( 'ABSPATH' ) || exit;

return [
	'categories' => [
		'mon-espace' => [ 'label' => 'Mon espace',           'icon' => '📋', 'order' => 10 ],
		'payments'   => [ 'label' => 'Paiements et crédits', 'icon' => '💰', 'order' => 20 ],
		'styles'     => [ 'label' => 'Styles',               'icon' => '🎨', 'order' => 30 ],
	],

	'modules' => [
		'mon-espace.shortcode' => [
			'label'           => 'Page Mon espace',
			'description'     => 'L\'enveloppe de la page /mon-espace/ et son aiguillage cliente/prof. Désactiver ce module rend tout l\'écran dormant.',
			'category'        => 'mon-espace',
			'type'            => 'php',
			'file'            => 'includes/modules/mon-espace.php',
			'requires_plugin' => [ 'ameliabooking/ameliabooking.php' => 'Amelia' ],
			'github_path'     => 'plugins/cordespace-snippets/includes/modules/mon-espace.php',
			'default_active'  => true,
		],
		'mon-espace.upcoming-qr' => [
			'label'           => 'QR cours à venir',
			'description'     => 'Affiche un QR code par cours réservé dans les 24h (vue cliente).',
			'category'        => 'mon-espace',
			'type'            => 'php',
			'file'            => 'includes/modules/upcoming-qr.php',
			'requires_plugin' => [ 'ameliabooking/ameliabooking.php' => 'Amelia' ],
			'github_path'     => 'plugins/cordespace-snippets/includes/modules/upcoming-qr.php',
			'default_active'  => true,
		],
		'mon-espace.credit-history' => [
			'label'           => 'Historique crédits',
			'description'     => 'Section historique MyCred dans la vue cliente (filtré pour ne montrer que les transactions de la personne connectée).',
			'category'        => 'mon-espace',
			'type'            => 'php',
			'file'            => 'includes/modules/credit-history.php',
			'requires_plugin' => [ 'mycred/mycred.php' => 'MyCred' ],
			'github_path'     => 'plugins/cordespace-snippets/includes/modules/credit-history.php',
			'default_active'  => true,
		],
		'mon-espace.teacher-presence' => [
			'label'           => 'Présence des élèves',
			'description'     => 'Toggle iOS-style pour marquer les élèves présent·es (vue prof) + endpoint REST + table SQL.',
			'category'        => 'mon-espace',
			'type'            => 'php',
			'file'            => 'includes/modules/teacher-presence.php',
			'requires_plugin' => [ 'ameliabooking/ameliabooking.php' => 'Amelia' ],
			'github_path'     => 'plugins/cordespace-snippets/includes/modules/teacher-presence.php',
			'default_active'  => true,
		],
		'mon-espace.linked-accounts' => [
			'label'           => 'Comptes liés (bouton + champ admin)',
			'description'     => 'Permet à une même personne d\'avoir deux comptes WP (cliente + prof) liés et de basculer entre eux en 1 clic.',
			'category'        => 'mon-espace',
			'type'            => 'php',
			'file'            => 'includes/modules/linked-accounts.php',
			'requires_plugin' => [ 'user-switching/user-switching.php' => 'User Switching' ],
			'github_path'     => 'plugins/cordespace-snippets/includes/modules/linked-accounts.php',
			'default_active'  => true,
		],
		'mon-espace.amelia-role-context' => [
			'label'           => 'Découplage rôle Amelia ↔ contexte',
			'description'     => 'Évite que les profs avec rôle wpamelia-manager doivent se relogger sur le cabinet (auto-login WP_USER). Permet aussi à un administrateur de voir tous les events depuis wp-admin → Amelia (au lieu de seulement les siens en tant que prof). Modifie uniquement l\'objet user en mémoire, jamais la DB.',
			'category'        => 'mon-espace',
			'type'            => 'php',
			'file'            => 'includes/modules/amelia-role-context.php',
			'requires_plugin' => [ 'ameliabooking/ameliabooking.php' => 'Amelia' ],
			'github_path'     => 'plugins/cordespace-snippets/includes/modules/amelia-role-context.php',
			'default_active'  => true,
		],
		'mon-espace.greeting-themes' => [
			'label'           => 'Thèmes de salutation personnalisés',
			'description'     => 'Permet d\'appliquer un fond décoratif (dinosaures, etc.) derrière le « Bonjour Prénom » de chaque utilisateur·trice. Configurable par profil dans wp-admin → Utilisateurs.',
			'category'        => 'mon-espace',
			'type'            => 'php',
			'file'            => 'includes/modules/greeting-themes.php',
			'requires_plugin' => [],
			'github_path'     => 'plugins/cordespace-snippets/includes/modules/greeting-themes.php',
			'default_active'  => true,
		],
		'payments.prof-warning' => [
			'label'           => 'Bandeau d\'achat prof',
			'description'     => 'Avertit les profs au moment du panier qu\'iels sont sur leur compte enseignant·e.',
			'category'        => 'payments',
			'type'            => 'php',
			'file'            => 'includes/modules/prof-warning.php',
			'requires_plugin' => [
				'woocommerce/woocommerce.php' => 'WooCommerce',
				'ameliabooking/ameliabooking.php' => 'Amelia',
			],
			'github_path'     => 'plugins/cordespace-snippets/includes/modules/prof-warning.php',
			'default_active'  => true,
		],
		'styles.menu-icon' => [
			'label'           => 'Icône bonhomme menu',
			'description'     => 'Remplace le texte « Mon espace » dans le menu par une icône blanche. Appliquer la classe CSS .cordespace-menu-icon sur l\'item de menu.',
			'category'        => 'styles',
			'type'            => 'css',
			'file'            => 'assets/css/menu-icon.css',
			'requires_plugin' => [],
			'github_path'     => 'plugins/cordespace-snippets/assets/css/menu-icon.css',
			'default_active'  => true,
		],
		'styles.amelia-sidebar' => [
			'label'           => 'Masque sidebar Amelia',
			'description'     => 'Cache la barre latérale « Événements à venir » du calendrier Amelia.',
			'category'        => 'styles',
			'type'            => 'css',
			'file'            => 'assets/css/amelia-sidebar-cache.css',
			'requires_plugin' => [],
			'github_path'     => 'plugins/cordespace-snippets/assets/css/amelia-sidebar-cache.css',
			'default_active'  => true,
		],
		'styles.callouts' => [
			'label'           => 'Callouts éditoriaux',
			'description'     => 'Style des encadrés .cordespace-note (variantes info / warn / ok) dans le contenu des pages.',
			'category'        => 'styles',
			'type'            => 'css',
			'file'            => 'assets/css/callouts.css',
			'requires_plugin' => [],
			'github_path'     => 'plugins/cordespace-snippets/assets/css/callouts.css',
			'default_active'  => true,
		],
	],
];
