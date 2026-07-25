# Plan de fin de projet — PIRB app + mabb-site — 9 juillet 2026 (soir)

Croisement de ton état des lieux (docs 25 + Pirb 10, écrits ce matin) avec l'état
git RÉEL vérifié ce soir. Corrections d'abord, plan bloc par bloc ensuite.

---

## 0. Ce que la vérification corrige dans ton doc

Ton doc était juste ce matin. Il est déjà périmé, et sur des points importants :

1. **Tout est poussé, sur les deux dépôts.** `git log origin/main..HEAD` est vide
   côté mabb-site ET côté Pirb store. Ta vigilance "discipline push" a payé.
2. **8 commits de l'après-midi (14h25 → 16h41) absents du doc** : multi-club
   (référentiel FFBB `OrganismeFfbb`, création de club publique, commande
   `app:club:officialiser`), import web des rencontres FFBB, stats-live 5 par
   équipe + demi-terrains teintés. C'est un chantier entier non documenté.
3. **`dribble.html` : la version complète EST commitée, poussée ET déployée.**
   Vérifié en direct : `https://pirb.mabb.fr/playground/dribble.html` sert le jeu
   complet (niveaux, score auto, MediaPipe). HEAD = 272 lignes version "jeu à
   niveaux". Ton P0 "committer dribble.html" ne concerne plus que **+50/−38
   d'ajustements locaux faits après** — à relire puis committer, mais le gros est
   en ligne.
4. **Le pull OVH du matin semble donc FAIT** (au moins jusqu'à `ddce615`).
   À confirmer sur OVH avec ton grep (`!== 'LIVE'` dans `PirbApiController.php`,
   le filtre est bien en local ligne 266). En revanche les commits de
   l'après-midi ne sont probablement pas déployés et embarquent une **migration**
   (`Version20260709124204`) → le prochain déploiement n'est plus
   « pull + cache:clear » mais **pull + `doctrine:migrations:migrate` + cache:clear**.
5. **`practice.tsx` : rien à committer.** Tes changements (route auto, label
   "score auto") sont déjà dans `f897efc`. Le seul diff local vu côté sandbox
   était une suppression accidentelle du `});` final — le fichier sur ton disque
   est intact. Vérifie chez toi : si `git status` le montre encore modifié,
   `git checkout -- "app/(tabs)/practice.tsx"`.
6. **`stats.tsx` : vrai travail non commité** (12 ajouts / 72 suppressions) :
   retrait du terrain "tous les paniers", saison passée à la page web shot chart
   (`?saison=`). Cohérent avec ton doc. **À committer, c'est le vrai P0 app.**
7. **Working tree mabb-site : plus gros que ce que ton doc dit.** En plus de
   Sorties / Document / vitrine, il y a du WIP non documenté :
   `SuperAdminController.php` + `templates/manager/super_admin/` (non suivis),
   `TenantResolver.php` (19/35), `ManagerLoginController.php`, `security.yaml`,
   et `stats-live.html.twig` re-modifié (−74 lignes) APRÈS ton commit de 16h41.
   C'est exactement le risque que tu identifies toi-même : déployer du
   multi-tenant/auth à moitié fini casserait la prod pour la joueuse.

---

## Bloc A — Hygiène git (ce soir, ~45 min, avant toute autre chose)

**Pirb store**
- Committer `app/(tabs)/stats.tsx` seul (message : retrait terrain global +
  saison propagée au shot chart web). Pousser.
- Trancher `spikes/p2-ball-detection/index.html` (le spike a été promu en prod
  côté serveur : soit committer l'état final du spike pour archive, soit
  supprimer le dossier), `test.md` (supprimer), `HomeHeader.tsx` (CRLF : restaurer).
- Supprimer `app/shot-chart.tsx` (orphelin confirmé, il existe encore).
- **Régler le CRLF une fois pour toutes** : `.gitattributes` avec `* text=auto`
  à la racine des deux dépôts. Ça élimine le bruit "modified en boucle" qui te
  fait perdre du temps à chaque `git status`.

**mabb-site** — committer par lots, jamais `git add .` :
1. `public/playground/dribble.html` (les +50/−38) — relire le diff avant.
2. Retouches stats-live post-`e24f231` (`stats-live.html.twig` −74 lignes :
   vérifier que c'est voulu).
3. Lot multi-tenant/auth : `TenantResolver`, `ManagerLoginController`,
   `security.yaml`, `SuperAdminController` + templates super_admin — **sur une
   branche** (`feat/multi-club-admin`), pas sur main, tant que ce n'est pas fini.
4. Lot Sorties : `EvenementController` (+51), repository, templates,
   `sorties_dashboard`.
5. Lot Document, lot vitrine (`base`/`navbar`).
6. La migration `Version20260709124204.php` modifiée : vérifier qu'elle
   correspond à ce qui doit tourner en prod avant de la committer.

**Critère de sortie du bloc : `git status` propre sur les deux dépôts.**

---

## Bloc B — Déploiement OVH + validation prod

1. Sur OVH : confirmer l'état actuel (`git log -1` + le grep du filtre LIVE).
2. Quand main est propre : `git pull` + `php bin/console doctrine:migrations:migrate`
   (colonnes Club) + `cache:clear --env=prod`.
3. Checklist de validation (celle de ton doc, elle est bonne) : 500 shot chart
   tombé, zones = Live only (vides), saison 2026-27 propre, match cliquable,
   formulaire séance auto-ouvert, bouton "Envoyer au coach" visible.
4. Ajouter : création de club publique et import rencontres — vérifier qu'ils ne
   sont pas accessibles publiquement s'ils ne sont pas prêts (sécurité).

---

## Bloc C — Validation dribble auto sur iPhone (tes 3 checks)

Le check 1 (URL Safari) est déjà virtuellement validé : le serveur répond avec le
jeu complet. Restent :
- `npx expo start -c` puis bouton Démarrer → l'écran caméra s'ouvre.
- La caméra s'affiche dans la WebView (sinon : on sait que c'est fiable en build
  store, ne pas s'acharner sur Expo Go).

---

## Bloc D — Publication store (LE chemin critique)

C'est ça, "terminer le projet" : l'app dans les mains de la joueuse via
TestFlight, pas une app parfaite.
1. Compte Apple Developer (99 $/an, validation parfois 24-48 h) — à lancer tôt,
   c'est le délai incompressible.
2. `eas build` iOS + soumission TestFlight.
3. Prérequis Apple à préparer : URL de politique de confidentialité (obligatoire,
   surtout avec la caméra), page support (ton écran Aide & contact existe, il
   faut l'équivalent web), justification de l'usage caméra dans
   `NSCameraUsageDescription` (ton texte RGPD existe déjà).
4. Test en build TestFlight du dribble auto (là, la caméra WebView est fiable).

---

## Bloc E — Sorties (Manager)

Code prêt selon ton doc, mais : **migration prod d'abord** (sinon 500 table
`inscription_sortie` absente), déploiement du lot complet uniquement (pas de
moitié), puis Lot D RGPD (registre, purge fin de saison, décharge) avant
d'ouvrir de vraies inscriptions avec des données de mineures. Le Lot D n'est pas
optionnel vu le public.

---

## Bloc F — Multi-club / super-admin : à GELER (avis franc)

Tu m'as demandé de ne pas être yesman, donc : ce chantier (référentiel FFBB,
création de club publique, super-admin, tenant resolver) est de l'**expansion
produit**, pas de la fin de projet. L'app n'a même pas encore été validée par UNE
joueuse d'UN club. Chaque heure passée là-dessus retarde le Bloc D, et c'est le
chantier qui touche l'auth/sécurité — le plus risqué à déployer à moitié.
Recommandation : committer l'état actuel sur une branche, geler jusqu'à ce que
l'app soit en TestFlight et que la boucle de feedback tourne. Le multi-club sera
bien meilleur une fois nourri par de vrais retours.

---

## Bloc G — Boucle feedback beta

Le mailto suffit pour démarrer TestFlight (et TestFlight a son propre canal de
feedback + screenshots intégré — ça réduit l'urgence de ton P2 "endpoint
feedback"). L'endpoint serveur devient utile quand tu auras plusieurs
testeuses, pas avant.

---

## Bloc H — Dette technique (après D, par ordre de rentabilité)

1. `.gitattributes` CRLF (fait au Bloc A — c'est la dette la plus rentable).
2. Doctrine/dbal vs MySQL 8.4 : mettre à jour doctrine/dbal pour retrouver le
   diff de migrations automatique — important AVANT le chantier multi-club qui
   va générer beaucoup de migrations.
3. Rituel de fin de session : mettre à jour les docs d'état des lieux EN FIN de
   session (les tiens, écrits le matin, étaient périmés à 17h — un doc d'état
   qui vit 12 h fait prendre de mauvaises décisions).

---

## Ce que je ne ferais PAS maintenant

- **Système Follow** : aucune valeur tant qu'il n'y a pas plusieurs utilisatrices.
  Les compteurs non cliquables peuvent même être masqués pour la beta.
- **Détection tir façon ballai.app** : V2, grosse marche technique. Après le store.
- **Dev build natif** : seulement si la WebView caméra échoue en build TestFlight.

---

## Ordre d'exécution recommandé

A (ce soir) → B (déploiement + validation) → C (iPhone) → D lancé en parallèle
(compte Apple dès demain, le délai court pendant que tu fais B/C) → E → G → H.
F reste gelé jusqu'à la beta effective.

**Définition de "terminé"** : la joueuse a l'app via TestFlight, ses stats prod
sont justes (zones Live-only, saison filtrée), le dribble auto tourne sur son
téléphone, et chaque bug remonte par un canal (TestFlight/mailto). Tout le reste
est du "projet suivant".
