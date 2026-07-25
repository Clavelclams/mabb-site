# Analyse décisionnelle — Architecture PIRB Mobile

> Date : 2026-07-04 · Auteur : Claude (session Cowork) · Statut : **ANALYSE — aucune décision actée, zéro code écrit**
> Sources : scan complet du code (`composer.json`, `src/`, `config/`, `migrations/`, `templates/`), dossier `instruction/` intégral, `MABB_PIRB_Vision_Produit_V5.pdf`, `CDC_FONCTIONNEL_MABB_ECOSYSTEME_v1.pdf`, `PIRB_SCOUTING_RAPPORT_PITCH.md`.
> La décision finale appartient à Clavel. Une fois validée → entrée ADR-0007 dans `08_ADR.md` (brouillon fourni en fin de document).

---

## ÉTAPE 0 — Ce que le code dit vraiment (scan du 04/07/2026)

### Stack backend constatée

| Élément | État réel constaté |
|---|---|
| Framework | Symfony **7.4** (`composer.json : "7.4.*"`), PHP **8.3** (platform config 8.3.23) |
| ORM | Doctrine ORM 3.6 + **61 migrations** dans `migrations/` |
| BDD | MySQL 8.4 sur OVH mutualisé (cluster102) — **en production** (mabb.fr, manager.mabb.fr, pirb.mabb.fr) |
| Frontend | Twig + Stimulus/Turbo + **AssetMapper** (zéro build Node) — pas de SPA |
| Auth actuelle | **Sessions Symfony `form_login`**, 7 firewalls isolés par host (vitrine, vitrine_admin, manager, pirb, api, dev, main) |
| **API Platform** | ❌ **ABSENT** de `composer.json`. `src/Controller/Api/` existe mais est **VIDE** |
| **LexikJWT** | ❌ **ABSENT**. Le firewall `api` existe en stub (`stateless: true`) avec `# jwt: ~` **commenté** dans `security.yaml` |
| Voters réels | `ClubVoter` (6 attributs), `NoteFraisVoter`, `TresorerieVoter` — ⚠️ pas `ClubScopeVoter`/`OwnershipVoter`/`TeamCoachVoter` comme l'affirme `01_LIRE_AVANT_TOUT.md` (dérive doc, voir §Conflits) |
| Multi-tenant | `TenantResolver` (session `active_club_id`) + `ClubAwareInterface` sur 26/42 entités Sport. **Lacune connue** (audit `19_AUDIT_FEATURES_2026-06-29.md` : 0/13 controllers PIRB) — vérifié ce jour : **1/15 controllers PIRB** utilise le TenantResolver (`PirbNotificationController`, ajouté depuis l'audit) ; le reste s'isole par `Joueur.user` uniquement (`PirbRencontreController` le documente : « V1 mono-club ») |
| Entités | `src/Entity/{Core,Sport,Vitrine,Pirb}` — **Pirb/ est vide**, tout le métier PIRB s'appuie sur les entités Sport (SeanceTir, TirFfbb, ZoneTir, ActionMatch, JoueurBadge…) |
| Gamification | ✅ **DÉJÀ CODÉE côté serveur** : `src/Gamification/` (BadgeCatalog 28+ badges, XpCalculator, NiveauCatalog, BadgeChecker idempotent) + `src/Feed/FeedItem.php` + `FeedAggregator` |
| Vision/OCR | `google/cloud-vision` présent — mais uniquement pour `GoogleVisionOcrService` (OCR des **PDFs FFBB**). Rien à voir avec de la vidéo joueur |
| Tests | ⚠️ Quasi inexistants sur le cœur : **0 test sur ClubVoter et TenantResolver** (qualifié « indéfendable jury CDA » par l'audit du 29/06) |

### PIRB web existant (pirb.mabb.fr) — déjà riche

Dashboard mobile-first, profil éditable + photo + liens sociaux (Insta/TikTok/YouTube/X/LinkedIn), **highlights vidéo par liens externes**, badges épinglés (3 max / 28+), profil public scout `/joueuse/{id}`, convocations P/A/I, feedback séances anonyme, stats perso saison (`JoueurStatsAggregator`), détail par match, **shot chart V2.3** (SeanceTir + ZoneTir + TirFfbb fusionnés, filtres source, terrain modal SVG), bilan 4 axes gamification, PDF stats match, séances solo.
Manquants web notables : saisie tirs terrain cliquable complète (B-302), visibilité 5 paliers (B-13), notifications in-app (B-204), shot chart agrégé saison.

### Ce qui est acté dans `instruction/`

- **ADR-0001** monolithe modulaire · **ADR-0002** hosts+firewalls · **ADR-0003** multi-tenant `club_id` · **ADR-0004** Twig/UX + API Platform pour le mobile (« Le mobile V3 consommera l'API Platform sans refonte backend ») · **ADR-0005** Symfony 7.4 · **ADR-0006** rôles par club.
- **Vision Produit V5 (mars 2026)** : Web + PWA en V1, **mobile natif = V3**, React Native pressenti, API Platform + JWT = Sprint 7 « à faire ».
- **CHANTIERS 2026-06-10** : **B4 = API Platform + LexikJWT, 10-14h, priorité P1 jury** — toujours pas fait.
- **Backlog 2026-06-26 + Audit 2026-06-29** : P0 = corriger bugs (BUG-01/02/03), Mailer Brevo prod, **tests ClubVoter/TenantResolver** ; P-forts = notifications in-app, sélecteur multi-clubs, isolation tenant PIRB.
- Le **mode vision type HomeCourt/Ball.ai n'apparaît dans AUCUN document existant** (ni CDC, ni Vision V5, ni backlog). C'est une idée nouvelle → si retenue, elle exige une entrée ADR + mise à jour roadmap V3.

---

## A. Backend — l'API sur le monolithe Symfony. Pas de backend Node. ❌ tranché.

### Le malentendu à dissiper d'abord : Node.js dans un projet React Native

Dans un projet React Native, **Node.js n'est pas un serveur**. Il sert uniquement d'outillage sur ta machine de dev : `npm` installe les librairies, **Metro** (le « bundler ») empaquette ton JavaScript pour le téléphone, les scripts de build tournent dessus. Une app React Native **n'a besoin d'aucun serveur Node en production** — elle parle à n'importe quelle API HTTP, y compris une API Symfony. Choisir React Native n'implique donc en rien un backend Node.

### Pourquoi un backend Node séparé serait une faute d'architecture ici

1. **Doublon de logique métier — le risque exact que tu cites.** Ta logique vit en PHP : `XpCalculator`, `BadgeChecker`, `JoueurStatsAggregator`, `ShotChartCalculator`, `TenantResolver`, les Voters, ADR-0006 (rôles par club). Un backend Node devrait soit **réimplémenter** tout ça en JS (deux systèmes de rôles, deux calculs de stats → ils divergeront, c'est mathématique), soit appeler le Symfony par-derrière (une couche d'indirection qui n'apporte rien et double les pannes possibles).
2. **Deux sources de vérité sur les stats.** Le pipeline stats (ActionMatch → agrégation → shot chart → XP/badges) est le cœur du produit et l'argument de vente à PIRB Scouting. Le dupliquer = bugs de cohérence garantis (« mon appli mobile dit 12 pts, le web dit 14 »).
3. **Dev solo débutant.** Deux backends = deux dépôts, deux déploiements, deux surfaces de sécurité, deux fois les mises à jour. Tu n'as pas la bande passante, et le jury CDA te demandera de justifier — « j'ai dupliqué mon métier » est injustifiable.
4. **OVH mutualisé ne fait pas tourner Node en serveur persistant.** Ton hébergement exécute PHP via Apache/FPM. Un process Node long-running (Express, etc.) n'y a pas sa place → il faudrait un VPS en plus = budget non nul.
5. **C'est déjà acté.** ADR-0004 dit explicitement que le mobile consommera l'API Platform du monolithe. Partir sur Node contredirait un ADR sans raison nouvelle.

### La vraie situation : l'API n'existe pas encore

Le prompt suppose « l'API Symfony/API Platform existante » — **elle n'existe pas**. `src/Controller/Api/` est vide, API Platform et LexikJWT ne sont pas installés. Le vrai prérequis mobile est le **chantier B4** (déjà chiffré 10-14h, P1) :

- `composer require api-platform/core` + `lexik/jwt-authentication-bundle` + **`gesdinet/jwt-refresh-token-bundle`** (indispensable mobile : le *refresh token* permet de rester connecté sans retaper son mot de passe quand le JWT court expire — un JWT est un jeton signé que l'app envoie à chaque requête à la place du cookie de session).
- Décommenter `jwt: ~` sur le firewall `api` (le stub est prêt — bon réflexe passé).
- Exposer d'abord un périmètre **lecture** : profil joueur, stats saison/match, shot chart (les mêmes données que les pages Twig PIRB), avec groupes de sérialisation stricts.
- **Corriger l'isolation tenant PIRB au passage** : 14 des 15 controllers PIRB n'utilisent pas le TenantResolver. L'API doit résoudre le club autrement que par la session (stateless) → claim `club_id` dans le JWT ou header dédié, vérifié par Voter. C'est l'occasion de purger la lacune critique de l'audit.
- **Tests anti-fuite inter-club sur l'API** — obligatoires (ADR-0003) et payants pour le jury.

**Verdict A : ✅ API Platform + LexikJWT sur le monolithe existant (chantier B4 = prérequis absolu du mobile). ❌ Backend Node : aucun argument pour, cinq contre.**

---

## B. Stack mobile — comparatif honnête

Vocabulaire d'abord : **Expo** est une surcouche de React Native qui gère pour toi la configuration native (Xcode/Gradle). **Expo Go** est l'app « bac à sable » pour tester sans rien compiler — mais elle n'accepte pas les modules natifs custom. Un **development build** est ta propre version compilée de l'app Expo, qui accepte n'importe quel module natif : c'est le pont qui a rendu obsolète le vieux débat « managed vs bare ». **EAS** est le service de build cloud d'Expo (compile l'iOS sans posséder de Mac ; tier gratuit limité).

| Critère | **Expo (managed + dev builds)** | React Native bare/CLI | Flutter |
|---|---|---|---|
| Courbe d'apprentissage (débutant mobile, JS débutant) | ✅ La plus douce. Doc excellente, pas de Xcode/Gradle à toucher au début | ❌ Config native à la main dès le jour 1 (Gradle, Podfile, signing) — piège classique du débutant solo | ⚠️ Framework très cohérent MAIS **Dart = un langage de plus** à apprendre en plus du mobile |
| Synergie avec ton existant | ✅ JS/TS — même famille que ton Stimulus ; réutilise ta logique SVG du shot chart (via `react-native-svg`) | ✅ idem JS | ❌ zéro réutilisation, tout en Dart |
| Coût | ✅ 0 € en dev. Builds cloud EAS gratuits (quota) — pas besoin de Mac | ⚠️ iOS exige un Mac en local | ✅ 0 € en dev |
| Consommer une API REST + JWT | ✅ Trivial (`fetch`/axios + stockage sécurisé du token via `expo-secure-store`) | ✅ idem | ✅ idem (Dio) |
| Vision temps réel / caméra (critère décisif) | ✅ **Possible en dev build** : `react-native-vision-camera` v4 (frame processors) + TFLite/MediaPipe. ❌ Impossible dans Expo Go | ✅ Identique à Expo dev build (mêmes libs) | ✅ `google_ml_kit` pose detection intégré proprement |
| OTA / itération | ✅ Updates JS over-the-air sans repasser par les stores | ❌ non (sans lib tierce) | ❌ non |
| Publication | Android : Google Play **25 $ une fois**. iOS : Apple Developer **99 $/an** — ⚠️ **conflit direct avec ton budget zéro**, y compris pour TestFlight | idem | idem |

Point d'honnêteté sur « iOS + Android » : avec un budget zéro, **l'iOS est bloqué par les 99 $/an d'Apple** quoi que tu choisisses comme framework. Reco : **Android d'abord** (25 $ une fois), iOS quand le projet justifie la dépense. Note aussi que ton PIRB web est déjà mobile-first : une **PWA** (site installable sur l'écran d'accueil) couvre déjà 80 % du socle à coût nul — c'est d'ailleurs ce que ta Vision V5 avait acté pour V1. L'app native se justifie pour : push fiables, caméra/vision, présence store, expérience réseau social.

**Verdict B : ✅ Expo + TypeScript, en development build dès le départ (pour ne pas se fermer la porte caméra), Android en premier.** Flutter est un bon framework mais t'impose Dart pour zéro bénéfice sur ton cas ; le bare RN t'impose la douleur native sans rien t'apporter qu'Expo dev build ne donne déjà.

---

## C. Mode vision (HomeCourt / Ball.ai) — la partie où il faut être franc

### Ce que font réellement HomeCourt et Ball.ai

HomeCourt (NEX Team) et Ball.ai sont le produit d'**années de R&D par des équipes d'ingénieurs vision**, avec datasets propriétaires massifs (millions de tirs annotés), brevets, et intégration profonde CoreML/ARKit (HomeCourt a été montée sur scène par Apple). « Détecter un tir réussi » = détecter le panier dans l'image, tracker la balle à 30-60 fps, reconstruire sa trajectoire et décider si elle passe dans le cylindre — le tout on-device, sous tous les angles de caméra, tous les éclairages de gymnase. **Il n'existe aucun modèle sur étagère qui fait ça.** Il faudrait entraîner un modèle custom (collecte vidéo, annotation, entraînement, conversion TFLite, optimisation mobile). Pour un dev solo débutant en mobile ET en machine learning : plusieurs mois à haut risque, résultat probablement médiocre.

### Ce qui existe vraiment, brique par brique

| Brique | Réalité | Accessible dev solo débutant ? |
|---|---|---|
| Caméra temps réel RN | [`react-native-vision-camera`](https://react-native-vision-camera.com/docs/guides/frame-processors) v4 : les « frame processors » exécutent du code sur chaque image de la caméra | ✅ Oui (en dev build Expo) |
| Pose estimation (squelette 33 points) | MediaPipe BlazePose / MoveNet TFLite. Plugins RN communautaires existants : [react-native-mediapipe (pose landmark)](https://cdiddy77.github.io/react-native-mediapipe/docs/api_pages/pose-landmark-detection/), [@thinksys/react-native-mediapipe](https://github.com/ThinkSys/mediapipe-reactnative), [demo VisionCamera+TFLite+Skia de Marc Rousavy](https://mrousavy.com/blog/VisionCamera-Pose-Detection-TFLite) | ⚠️ Oui, avec effort. C'est LA partie faisable : afficher le squelette du shoot, angles du coude/genou |
| Détection de balle | Modèles génériques (YOLO nano) détectent « un ballon » mais le **tracking fiable d'une balle rapide et petite à l'image** exige un modèle custom | ❌ Pas sur étagère |
| Détection panier + tir réussi/raté | **Rien sur étagère.** Dataset custom + logique de trajectoire à construire | ❌ Non réaliste avant la soutenance |
| Traitement côté serveur | OVH mutualisé : pas de GPU, pas de process long, pas de ffmpeg lourd. Cloud vision payant = budget zéro | ❌ On-device uniquement |

### Verdict honnête et fallback

- **La détection automatique tirs réussis/ratés n'est pas faisable proprement en V1**, même « imparfaite assumée ». Le risque n'est pas d'avoir un truc imparfait, c'est d'y couler 3 mois et de ne rien avoir de démontrable.
- **Le fallback que tu proposes est le bon produit V1**, pas un pis-aller : mode practice avec **cibles affichées + minuteur + saisie ultra-rapide** (2 gros boutons Réussi/Raté, ou saisie vocale) → alimente directement SeanceTir/ZoneTir existants via l'API, donc le shot chart et les badges **déjà codés** côté serveur. Démontrable à coup sûr au jury.
- **Brique vision « light » réaliste en bonus** (si et seulement si le socle est fini) : enregistrer la vidéo du shoot + **pose estimation en relecture** (overlay squelette sur la vidéo, angles articulaires) — pas de temps réel exigé, pas de détection de balle, briques MediaPipe existantes. Effet démo fort pour un coût borné. À traiter comme un **spike isolé de 2 semaines max**, dans un module séparé, jetable sans casser l'app.
- ⚠️ **RGPD/mineures** : filmer des joueuses U11-U18 = consentement parental, stockage, droit à l'image. À documenter dans `07_REGISTRE_SECURITE_RGPD.md` avant toute feature caméra. Raison supplémentaire de ne pas commencer par là.
- Impact du choix B : Expo Go ❌ / **Expo dev build ✅** / bare RN ✅ (identique) / Flutter ✅ — le choix Expo-dev-build ne ferme aucune porte.

---

## D. Priorisation — le socle d'abord, la vision en brique isolée. Sans hésitation.

Le socle (auth, profil, stats, shot chart, badges) se démontre **à coup sûr** : les données et la logique existent déjà côté serveur, le mobile ne fait que les consommer. La vision est un pari de recherche. Un dev solo à 9 mois d'un jury ne met pas le pari avant la certitude.

### Découpage recommandé

**Étape 0 — Web, avant tout mobile (P0 jury, déjà priorisé par l'audit du 29/06)**
Bugs BUG-01/02/03, Mailer Brevo prod, **tests ClubVoter + TenantResolver** (8-12h). Sans ça, le cœur multi-tenant est indéfendable devant le jury — mobile ou pas.

**Étape 1 — B4 : API Platform + LexikJWT + refresh tokens + isolation tenant PIRB + tests API** (~2-3 semaines au rythme alternance). C'est un chantier **web**, valorisable seul en soutenance (conception d'API sécurisée = pile dans le référentiel CDA).

**Étape 2 — Mobile M1 « lecture » : auth JWT, profil, stats saison/match, shot chart perso** (réplique mobile du SVG existant via react-native-svg). Fin de M1 = déjà une démo jury solide.

**Étape 3 — M2 : badges/XP/niveaux (simple affichage — les calculs restent serveur), highlights (liens), feed simple** (FeedAggregator existe déjà).

**Étape 4 — M3 : notifications push** (expo-notifications/FCM) + mode practice manuel (cibles + minuteur + saisie → API SeanceTir).

**Repoussé explicitement :**
- **Vision automatique** → spike isolé post-soutenance (ou pose-overlay en relecture si tout le reste est fini en avance).
- **Audiences « public / amis proches / club »** → ⚠️ ce n'est pas un écran mobile, c'est un **nouveau domaine backend** : aucune entité de relation sociale n'existe (pas de table amis, pas de demande/acceptation, pas de modération). Ajoute la visibilité 5 paliers (B-13, déjà au backlog web) d'abord ; le graphe « amis proches » est un chantier V-suivante avec de vraies questions RGPD (mineures, contenu public).

## E. Ce que je ne valide pas — dit franchement

1. **« PIRB doit devenir une app mobile » maintenant, non.** Tes propres documents (Vision V5 §1, ADR-0004, roadmap V3) ont acté mobile = V3, après le socle — et ils avaient raison. Ce qui a changé depuis mars, c'est que le web a bien avancé ; ce qui n'a PAS changé : pas d'API, pas de tests sur le cœur, des P0 web ouverts, jury en avril 2027. Lancer React Native + vision aujourd'hui, c'est le scénario « arriver au jury avec une app mobile à moitié finie ET un web pas testé ».
2. **Le mode vision en V1 mobile : non.** C'est le scope-killer du projet. Le fallback practice manuel EST la V1 ; la pose estimation est le bonus borné ; la détection auto est post-soutenance.
3. **Le « profil réseau social » complet : à dégonfler.** Feed + highlights + profil public existent déjà en web. Le graphe d'amis, les audiences et la modération sont un produit en soi — pas avant la soutenance.
4. **En revanche, je valide le mobile socle séquencé** (étapes 0→4) : l'API sécurisée + un client mobile qui la consomme est un **plus** réel pour le dossier CDA, à condition que l'API soit testée. Le mobile n'est un atout que si le socle est irréprochable.

---

## Recommandation finale

| Question | Décision recommandée |
|---|---|
| A. Backend | **API Platform + LexikJWT (+ refresh tokens) sur le monolithe Symfony existant** (= chantier B4). Aucun backend Node. |
| B. Stack mobile | **Expo (React Native) + TypeScript, development builds dès le départ, Android d'abord** (iOS quand les 99 $/an se justifient). |
| C. Vision | V1 = **mode practice manuel** (cibles + minuteur + saisie rapide → API). Bonus borné = pose estimation en relecture (spike 2 sem max, module isolé). Détection auto tirs = **post-soutenance**. |
| D. Ordre | P0 web (tests/bugs/mailer) → B4 API → M1 lecture → M2 gamification/feed → M3 push + practice. |
| E. Garde-fou | Aucune ligne de mobile tant que l'étape 0 (tests du cœur) et l'étape 1 (API testée) ne sont pas finies. |

### Plan de démarrage (séquence, estimations rythme alternance)

1. Clôturer P0 audit 29/06 : bugs + Mailer + tests ClubVoter/TenantResolver — **~2 semaines**
2. B4 : API Platform + LexikJWT + refresh + claim club dans le token + endpoints lecture PIRB + tests anti-fuite — **~3 semaines**
3. Setup Expo + TS + dev build Android + écran login JWT (secure-store) — **~1 semaine**
4. M1 : profil + stats + shot chart lecture — **~3-4 semaines**
5. M2 : badges/XP/feed/highlights — **~2-3 semaines**
6. M3 : push + mode practice manuel — **~2-3 semaines**

Total socle mobile : **~3,5-4 mois** au rythme solo+alternance → jouable pour une démo mobile en soutenance SI démarré après les P0, SANS le pari vision.

### Brouillon ADR-0007 (à coller dans `08_ADR.md` UNIQUEMENT après validation par Clavel)

> **ADR-0007 — App mobile PIRB : client Expo/React Native consommant l'API du monolithe**
> Date : à la validation · Contexte : PIRB doit exister en mobile (V3) ; le mode vision type HomeCourt est demandé.
> Options : (A) Backend Node séparé + RN bare (B) API Platform/LexikJWT sur le monolithe + Expo (C) PWA seule (D) Flutter.
> Décision : (B). L'API est posée par le chantier B4 sur le monolithe (une seule source de vérité métier, conforme ADR-0004). Client Expo + TypeScript en development builds, Android d'abord. Vision automatique exclue du périmètre pré-soutenance ; mode practice manuel en V1 mobile ; pose estimation = spike isolé optionnel.
> Conséquences : pas de duplication de logique métier ; refresh tokens requis ; résolution du tenant par claim JWT (corrige la lacune PIRB 0/13 TenantResolver) ; coût stores : 25 $ Android, iOS différé (99 $/an).

---

## Conflits & écarts documentaires signalés (obligation gouvernance)

1. **Le prompt demandait de mettre à jour `Instruction/03_CLAUDE_LOG.md` — ce fichier n'existe pas.** Le journal est `instruction/13_CLAUDE_LOG.md` (le `03_` est `03_ROADMAP_V1.md`). Conformément à la règle « STOP et signale », le log a été écrit dans **13**_CLAUDE_LOG.md et le conflit est signalé ici.
2. **Le prompt suppose API Platform + LexikJWT présents** (« consommer l'API Symfony/API Platform existante ») — le code prouve qu'ils ne sont **pas installés** (composer.json, firewall stub, `src/Controller/Api/` vide). L'analyse en tient compte.
3. **`01_LIRE_AVANT_TOUT.md` liste des Voters qui n'existent pas** (ClubScopeVoter, OwnershipVoter, TeamCoachVoter) ; les Voters réels sont ClubVoter, NoteFraisVoter, TresorerieVoter. À corriger lors d'une prochaine session doc (non modifié ici : hors périmètre analyse).
4. **`02_ROADMAP_GLOBALE.md` (màj 2026-03-13) est obsolète** : elle indique Phase 1 « EN COURS » alors que `09_BACKLOG.md` (2026-06-26) et `19_AUDIT_FEATURES` (2026-06-29) attestent d'un produit en production avec Phases 2-4 largement livrées. La source fiable actuelle = 09 + 19.
5. **Deux dossiers coexistent : `instruction/` (gouvernance) et `instructions/`** (contenant uniquement `CDC_FONCTIONNEL_MABB_ECOSYSTEME_v1.pdf`). Doublon d'arborescence à résorber (déplacer le PDF dans `instruction/CDC/` ?) — non touché sans validation.
