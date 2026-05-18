# Déploiement de Cordespace Snippets

Ce document décrit comment passer de **« 6 snippets dans WPCode »** à
**« 1 plugin tiré depuis GitHub »**, sans rien casser au passage.

## Pré-requis

- Un repo GitHub privé `Tess-cdp/cordespace` (à créer une seule fois — voir
  section « Setup initial GitHub »)
- WP Pusher installé sur le site cible (à faire une seule fois — voir
  « Setup initial WP Pusher »)
- Accès admin WordPress

## Setup initial GitHub (à faire une seule fois)

1. Aller sur https://github.com/new
2. Nom du repo : `cordespace`
3. **Privé**, cocher « Add README », laisser le reste par défaut
4. Sur ton Mac, dans `/Users/tessberthier/Documents/site web/cordespace/` :

   ```bash
   cd "/Users/tessberthier/Documents/site web/cordespace"
   git init
   git remote add origin git@github.com:Tess-cdp/cordespace.git
   git add .
   git commit -m "Initial commit — plugin cordespace-snippets + docs"
   git branch -M main
   git push -u origin main
   ```

   Si SSH n'est pas configuré, utiliser l'URL HTTPS et un Personal Access
   Token GitHub (Settings → Developer Settings → Personal access tokens →
   Tokens (classic) → Generate new token avec scope `repo`).

## Setup initial WP Pusher (à faire une seule fois par site)

1. Télécharger le zip gratuit sur https://wppusher.com/
2. wp-admin → **Extensions** → **Ajouter** → **Téléverser une extension**
3. Choisir le zip, **Installer**, puis **Activer**
4. Dans le menu **WP Pusher** :
   - **GitHub** → **Connect with GitHub** (autorise WP Pusher à lire ton
     compte)
   - **Install Plugin** → coller `Tess-cdp/cordespace` → préciser le
     sous-dossier `plugins/cordespace-snippets` → installer
5. Le plugin **Cordespace Snippets** apparaît dans la liste des extensions
   mais reste **INACTIF** pour l'instant — c'est voulu.

## Migration des snippets WPCode → plugin

À faire sur le **site de prod-clone** (`cordespace-prod-local` en LocalWP)
d'abord, comme dry-run, puis sur la vraie prod.

### Étape 1 : activer le plugin

wp-admin → **Extensions** → **Cordespace Snippets** → **Activer**.

À ce stade, **les snippets WPCode tournent ENCORE** : il y a donc
double-exécution potentielle (et risque de fatal « Cannot redeclare
function »).

→ **Si fatal immédiat** : désactiver le plugin et lire la section
« Troubleshooting » en bas.

### Étape 2 : désactiver les snippets WPCode un par un

Dans wp-admin → **Code Snippets**, **désactiver** (toggle « Active » →
gris) chacun de ces six dans l'ordre :

| ID  | Titre                                    | Remplacé par                                |
|-----|------------------------------------------|---------------------------------------------|
| 1228 | Icône menu                              | `assets/css/menu-icon.css`                  |
| 1237 | Cache sidebar calendrier Amelia         | `assets/css/amelia-sidebar-cache.css`       |
| 1241 | Notes / Callouts éditoriaux             | `assets/css/callouts.css`                   |
| 1224 | Switch entre comptes liés               | `includes/switch-accounts.php`              |
| 1232 | Shortcode [cordespace_mon_espace]       | `includes/mon-espace-shortcode.php`         |
| 1249 | Toggle présence prof                    | `includes/toggle-presence-prof.php`         |

Après chaque désactivation, **vérifier en navigation privée** :

- Après les 3 CSS : `/mon-espace/` ne perd pas son style (callouts, icône
  menu, sidebar Amelia masquée)
- Après `1224` : se relogger sur un compte lié, le bouton de bascule
  fonctionne
- Après `1232` : `/mon-espace/` rend bien le panel (client·e ou prof selon
  le compte)
- Après `1249` : le toggle iOS-style apparaît pour un prof avec des cours
  dans les 24h

### Étape 3 : laisser tomber WPCode si on n'en a plus besoin

Une fois la migration validée et stable plusieurs jours, on peut désinstaller
WPCode pour réduire la surface d'attaque. Garder un export de la DB
WordPress avant.

## Workflow de modification quotidien

```bash
# 1. Sur ton Mac, modifier le code
cd "/Users/tessberthier/Documents/site web/cordespace"
git pull
$EDITOR plugins/cordespace-snippets/includes/mon-espace-shortcode.php

# 2. Tester en local (cordespace.local)
# Le plugin doit déjà être actif dans LocalWP — un simple Cmd+R recharge le
# fichier modifié

# 3. Commit + push
git add plugins/cordespace-snippets/
git commit -m "feat(mon-espace): détail concret du changement"
git push

# 4. Déployer en prod
# wp-admin → WP Pusher → ligne Cordespace Snippets → bouton "Update"
# (ou cocher "Push to Deploy" pour auto-update au push)
```

## Rollback

Si une mise à jour casse la prod :

1. **Vite fait** : wp-admin → Extensions → **Cordespace Snippets** →
   **Désactiver**. Réactiver les snippets WPCode correspondants en attendant.
2. **Propre** : `git revert <commit-hash>` côté Mac, `git push`, puis
   WP Pusher → **Update** pour ramener à l'état d'avant.

## Troubleshooting

### Fatal « Cannot redeclare function » à l'activation

Cause : un snippet WPCode définit la même fonction que le plugin. Le plugin
et le snippet ne peuvent pas coexister. **Désactiver le snippet WPCode
correspondant** (voir tableau étape 2).

### Le shortcode `[cordespace_mon_espace]` apparaît en texte brut

Cause : ni le plugin ni le snippet WPCode n'est actif. Activer **un des
deux**, pas les deux.

### WP Pusher : "Could not clone repo"

Vérifier :
- Le token GitHub est encore valide (les tokens « classic » expirent)
- Le repo est accessible depuis le compte GitHub connecté
- Le sous-dossier `plugins/cordespace-snippets` existe bien dans la branche
  ciblée (par défaut `main`)
