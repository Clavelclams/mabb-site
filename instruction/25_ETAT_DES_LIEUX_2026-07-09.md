# État des lieux — mabb-site (serveur / webapp / API) — 9 juillet 2026

Photo précise du dépôt serveur à l'instant T. L'app mobile a son propre doc dans
`Pirb store/instruction/10_ETAT_DES_LIEUX_2026-07-09.md`.

---

## 1. Ce que c'est

**Symfony 7.4 / PHP 8.3**, hébergé sur **OVH**. Un seul dépôt, plusieurs domaines
front (host-based) :

- **pirb.mabb.fr** — espace joueuse (webapp) **+ l'API** consommée par l'app mobile.
- **manager.mabb.fr** — espace staff/club (Manager).
- **mabb.fr** — vitrine.

C'est la **source de vérité des données**. Règle d'or : une correction n'est visible
en prod qu'après **commit → push → `git pull` sur OVH → `php bin/console
cache:clear --env=prod`**. Le cache Twig compilé est la cause classique d'un fix
"qui ne prend pas".

---

## 2. État git (mabb-site)

- **Poussé sur GitHub** (origin/main à jour) :
  - `14a7a9c` — fix **500 shot chart** (Twig 3 `for…if` → `|filter`), formulaire
    de séance **auto-ouvert** (`?nouvelle=1`), bouton **"Envoyer au coach"** collé
    en bas (était caché sous la barre d'onglets), courbe `[object Object]` corrigée.
  - `b41970f` — **zone par zone Live-only**, **filtre saison** du shot chart,
    **accès match par participation** (corrige le 403 "ce match ne concerne pas ton équipe").
  - `ddce615` — hébergement du **jeu dribble auto** en statique.
- **⚠️ À FAIRE sur OVH** : `git pull origin main` **+** `cache:clear --env=prod`.
  Tant que ce n'est pas fait, l'app voit encore l'ancien code (500, zones FFBB,
  saison non filtrée). Vérif : sur OVH `grep -n "!== 'LIVE'" src/Controller/Api/PirbApiController.php`
  doit afficher la ligne du filtre.
- **Encore à committer** :
  - `public/playground/dribble.html` — réécrit en version complète (316 lignes)
    APRÈS `ddce615`. **À committer + pousser.**
  - Chantiers WIP séparés (à ne pas déployer à moitié) : **Sorties**
    (`EvenementController`, `EvenementRepository`, `evenement/index.html.twig`,
    `sorties_dashboard.html.twig` non suivi), `DocumentController`,
    vitrine (`base.html.twig`, `navbar.html.twig`).

---

## 3. API PIRB (`src/Controller/Api/PirbApiController.php`)

- `GET /api/pirb/stats/saison` — moyennes de la saison ; accepte `?saison=YYYY-YYYY`
  (saisons passées uniquement, jamais future).
- `GET /api/pirb/shot-chart` — `{ tirs, zones }` filtrés par saison. **Zones =
  Stats Live uniquement** (`if source !== 'LIVE' continue`) car la FFBB ne fournit
  que les tirs réussis (100 % partout serait trompeur). Les tirs FFBB restent
  visibles en pastilles.
- `GET /api/pirb/saisons` — liste pour le sélecteur (source `SaisonService`).
- `GET /api/pirb/commu` — vraies joueuses du club (nom, équipe, poste, photo),
  non cliquables. **RGPD** : mineures → liste intra-club uniquement.

---

## 4. Web PIRB (`src/Controller/Pirb/PirbStatsController.php`)

- Page **shot chart** (`/stats/shotchart`) : 500 corrigé + **filtre saison** ajouté
  (avant : tous les tirs, donc 2026/27 montrait ceux de 2025/26). Respecte
  `getSaisonActive()` et l'`?saison=` passé par l'app.
- **Accès page match** (`/stats/match/{id}`) : accordé si la joueuse appartient à
  l'équipe **OU** a des tirs sur ce match (participation). Robuste après la bascule
  de saison, quand le mapping équipe/saison est incomplet.
- **Créateur de séance de shoot** (`templates/pirb/shot_chart/index.html.twig`) :
  formulaire auto-ouvert via `?nouvelle=1`, bouton **"Envoyer au coach"** sticky en
  bas (visible sans scroller), courbe de progression corrigée (date formatée dans
  `SeanceTirRepository::findProgressionData`).

---

## 5. Manager & vitrine

- **Manager — module Sorties** : entités `Evenement` (payant/prix/autorisation) +
  `InscriptionSortie`, repositories, contrôleur (inscriptions, dashboard), templates.
  **Construit mais NON déployé.** Nécessite sa **migration en base sur la prod**
  avant mise en ligne (sinon 500 : table `inscription_sortie` absente). Lot D (RGPD :
  registre, purge fin de saison, décharge) reste à faire.
- **Vitrine** : responsive mobile + contact vers `admin@mabb.fr` (commits poussés).
  Modifs `base`/`navbar` encore en WIP local.

---

## 6. Le dribble auto (hébergement)

- Jeu de détection du ballon (MediaPipe/WASM) dans `public/playground/dribble.html`,
  **100 % autonome** (CDN publics, aucun asset local). Servi en statique →
  `https://pirb.mabb.fr/playground/dribble.html`.
- L'app l'ouvre en WebView (voir doc app). **À committer + déployer** (fichier
  statique, pas besoin de cache:clear).

---

## 7. Backlog serveur (priorisé)

| Priorité | Item | État |
|---|---|---|
| **P0** | `git pull` + `cache:clear` OVH (recevoir zones/saison/500) | prêt, à faire |
| **P0** | Committer + pousser `public/playground/dribble.html` | à faire |
| P1 | Sorties : **migration prod** + mise en ligne + Lot D RGPD | code prêt |
| P2 | Système **Follow** (abonnés/suivis) : entité + endpoints | non commencé |
| P2 | Endpoint feedback/bug + upload screenshot (pour l'app) | non commencé |

---

## 8. Dette technique & risques

- **Cache Twig prod** : LE piège. Toujours `cache:clear --env=prod` après un pull
  qui touche des templates. Un fix commité mais non "cache-cleared" ne sert à rien.
- **Discipline push** : vérifier `git log origin/main..HEAD` (vide = tout poussé)
  AVANT de croire qu'un déploiement va prendre. Plusieurs bugs venaient d'un commit
  jamais poussé.
- **MySQL 8.4 + Doctrine** : introspection incompatible → historique de migrations
  local vide, delta écrits à la main. Dette à traiter (mettre à jour doctrine/dbal).
- **Working tree encombré** : plein de fichiers WIP mélangés → risque de déployer du
  travail à moitié fini. Committer sélectivement, jamais `git add .` à l'aveugle.
- **CRLF Windows** : fichiers "modified" en boucle, bruit sans gravité.

---

## 9. Prochaines étapes conseillées

1. **Déploiement propre** : pull + cache:clear → valider (zones vides, 2026/27
   propre, 500 tombé, match cliquable, `/playground/dribble.html` ouvre).
2. Committer/pousser `dribble.html`.
3. **Sorties** : préparer la migration prod avant d'ouvrir les inscriptions.
4. Endpoint feedback (pour la boucle de retours beta de l'app).
