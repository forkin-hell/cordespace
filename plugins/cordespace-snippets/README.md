# Cordespace Snippets

Plugin WordPress qui regroupe la **glue** entre Amelia, MyCred, User Switching et
WooCommerce pour Cordespace. C'est la version « versionnée en fichiers » de ce
qui était auparavant six snippets dans WPCode.

## Pourquoi ce plugin existe

Les snippets WPCode ont des limites pénibles quand le code grossit :

- Pas d'historique git (impossible de voir « qu'est-ce qui a changé depuis hier »)
- Pas de diff pour relire avant de pousser en prod
- Pas de backup hors-site (si la DB meurt, les snippets meurent avec)
- Un seul `<?php` mal placé dans un snippet plante silencieusement **tous** les
  snippets PHP « Run Everywhere » (vu en mai 2026 — voir `docs/MON-ESPACE.md`)

Avec un plugin, tout vit dans un repo git privé. Modifier = commit. Déployer =
`git pull` côté serveur (via WP Pusher si on n'a pas SFTP).

## Contenu

```
cordespace-snippets/
├── cordespace-snippets.php          ← header WP + loader auto de includes/
├── README.md                        ← ce fichier
├── DEPLOIEMENT.md                   ← procédure de mise en prod
├── includes/                        ← PHP, chargé par ordre alphabétique
│   ├── mon-espace-shortcode.php     ← [cordespace_mon_espace]
│   ├── switch-accounts.php          ← [cordespace_switch_button] + User Switching
│   └── toggle-presence-prof.php     ← [cordespace_today_students] + REST checkin
└── assets/
    └── css/                         ← enqueués par cordespace-snippets.php
        ├── menu-icon.css            ← icône bonhomme dans le menu
        ├── amelia-sidebar-cache.css ← masque la sidebar Amelia
        └── callouts.css             ← .cordespace-note { .info, .warn, .ok }
```

## Dépendances

Plugins requis (à installer séparément sur le site) :

- **Amelia** (booking + panels client·e / employé·e)
- **MyCred** + **MyCred Amelia** (crédits)
- **User Switching** par john-blackbourn (bascule entre comptes)
- **WooCommerce** (panier, comptes, commandes)

Si l'un de ces plugins n'est pas actif, certaines parties dégradent
gracefully (le shortcode `[cordespace_mon_espace]` détecte ce qui est
disponible avant d'afficher).

## Migration depuis WPCode

Voir `DEPLOIEMENT.md` pour la procédure pas-à-pas qui désactive les snippets
WPCode un par un en confirmant que rien ne casse.

## Convention de nommage

- Les **fonctions** publiques préfixées `cordespace_` (évite les collisions
  avec d'autres plugins).
- Les **classes CSS** préfixées `cordespace-`.
- Les **shortcodes** préfixés `cordespace_` (sauf historique : `mycred_*`,
  `amelia*` ne sont pas à nous).
- Les **tables custom** dans la DB préfixées `cordespace_` (ex:
  `wp_cordespace_checkins`).

## Ordre de chargement

`includes/` est chargé par ordre alphabétique. Aujourd'hui :

1. `mon-espace-shortcode.php` — définit `cordespace_user_is_amelia_provider()`
2. `switch-accounts.php` — autonome
3. `toggle-presence-prof.php` — utilise `cordespace_user_is_amelia_provider()`

Si demain un fichier doit charger avant `mon-espace-`, le préfixer (ex:
`00-helpers.php`).
