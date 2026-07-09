# Session 09/07/2026 (soir) — Social V1 (Follow) + Tir auto (tracker de shoot)

Deux chantiers livrés dans les deux dépôts, prêts à committer/déployer/tester.
Objectif produit : rapprocher la sortie store — plus rien de « verrouillé » dans l'app.

---

## 1. Ce qui a été construit

### A. Le système Follow (abonnés / suivis) — de bout en bout

**Serveur (mabb-site)** :
- `src/Entity/Pirb/Follow.php` — 1re entité du namespace Pirb. Joueur → Joueur
  (pas User → User : une coéquipière sans compte peut déjà être suivie).
  Unicité (suiveuse, suivie) en base, CASCADE des deux côtés (RGPD effacement).
- `src/Repository/Pirb/FollowRepository.php` — compteurs en COUNT SQL, listes,
  `idsSuiviesPar()` pour marquer `suivie` en 1 requête.
- `src/Controller/Api/PirbFollowController.php` — 4 endpoints :
  `GET /api/pirb/social/counts`, `GET .../abonnes`, `GET .../abonnements`,
  `POST /api/pirb/follow/{id}/toggle` (le serveur tranche l'état final →
  double-tap safe). Règles : intra-club uniquement (RGPD mineures, même
  périmètre que /commu), pas soi-même, cible active. Hors club = 404 (on ne
  confirme jamais l'existence d'une joueuse d'un autre club).
- `migrations/Version20260709230000.php` — écrite À LA MAIN (uniquement la
  table pirb_follow, zéro drift).
- `PirbApiController::commu()` — `suivie` est maintenant RÉEL (fini le
  `false` en dur).

**App (Pirb store)** :
- Contrat `PirbDataService` : + `getAbonnes()` / `getAbonnements()`.
- `ApiPirbDataService` : + helper `post()`, social branché sur les vrais
  endpoints. Résilience pré-déploiement : counts → vrais zéros, listes →
  vides (JAMAIS les chiffres du mock — décision produit : 0 = 0).
- `MockPirbDataService` : abonnés = vide (comme une vraie débutante),
  abonnements = dérivés des follows cochés en démo.
- **`app/social.tsx` (nouveau)** — l'écran derrière les compteurs : onglets
  Abonnées/Suivis, listes réelles, états vides assumés, bouton Suivre
  optimiste avec resynchro serveur.
- `profil.tsx` — compteurs abonnés/suivis CLIQUABLES → `/social?tab=…`.
- `CommuCard` — le toggle se resynchronise sur l'état renvoyé par le serveur.

### B. Le Tir auto — tracker de shoot façon ballai.app

**Serveur** : **`public/playground/tir.html` (nouveau, statique)** :
- Caméra ARRIÈRE (`facingMode: environment`), image non miroir (on filme le panier).
- Calibration de l'arceau en 1 TAP (les modèles embarquables ne connaissent
  pas la classe « panier » — COCO n'a que « sports ball » ; c'est LA limite
  technique assumée, le reste est 100 % auto). Bouton « Recaler » si le tel bouge.
- Détection ballon MediaPipe + TRAJECTOIRE dessinée (traînée orange qui
  s'estompe), machine à états repos → en_vol → décision → cooldown :
  panier si la trajectoire redescend DANS le cercle, raté si elle retombe à
  côté mais proche (< 3 rayons), rien si c'était une passe. Ballon perdu
  hors champ → tir annulé, pas compté (mieux vaut un tir non compté qu'un
  faux chiffre).
- HUD réussis / tirs / adresse % / série, sons WebAudio (swish panier, buzz
  raté), « +1 · 0.6s de vol », 🔥 séries.
- Fin de séance → `postMessage` vers l'app.

**App** :
- **`app/practice-tir-auto.tsx` (nouveau)** — permission caméra expliquée
  (RGPD), WebView vers tir.html, `onMessage` → séance enregistrée.
- **`src/services/practice/webviewBridge.ts` (nouveau)** — le pont web→app :
  validation stricte du message (re-typage, bornes anti-absurde), puis
  `saveSeancePractice` → la séance apparaît dans l'historique Playground
  sans rien saisir. Branché AUSSI sur le dribble auto.
- `dribble.html` — envoie maintenant sa fin de partie à l'app (même pont).
- `practice.tsx` — le mode TIR est DÉVERROUILLÉ : les deux cartes lancent
  la détection auto (les modes manuels restent en secours dans le code).

---

## 2. À faire sur TA machine (dans l'ordre)

1. **Vérifs locales** (la sandbox de la session ne pouvait pas les faire) :
   - Pirb store : `npx tsc --noEmit` (doit être propre).
   - mabb-site : `php bin/console lint:container` + `php -l` sur les 4
     fichiers PHP nouveaux/modifiés.
2. **Commits mabb-site** (séparés du WIP multi-club !) :
   - lot « social » : Entity/Repository/Controller Follow + migration +
     PirbApiController ;
   - lot « playground » : tir.html + dribble.html.
3. **Commits Pirb store** : lot « social app » (5 fichiers) + lot « tir auto »
   (3 fichiers) + le stats.tsx qui traînait.
4. **Déploiement OVH** : `git pull` + `php bin/console doctrine:migrations:migrate`
   (⚠️ nouvelle table pirb_follow) + `cache:clear --env=prod`.
   tir.html/dribble.html sont statiques (pas de cache:clear nécessaire pour eux).
5. **Tests** :
   - `https://pirb.mabb.fr/playground/tir.html` dans Safari : calibre, shoote,
     vérifie trajectoire + comptage + son.
   - App : `npx expo start -c` (2 NOUVELLES routes : /social et
     /practice-tir-auto → redémarrage Metro obligatoire).
   - Profil → tape « abonnés » → écran vide propre. Recherche → Suivre une
     coéquipière → compteur « suivis » passe à 1 → liste OK.
   - Playground → TIR → Démarrer → séance → Terminer → retour Playground :
     la séance est dans l'historique.

## 3. Limites honnêtes (à connaître avant de tester)

- **Bouton Suivre inopérant tant que le serveur n'est pas déployé** : le
  toggle POST échouera (404) → le bouton revient en arrière tout seul.
  C'est voulu (pas de faux état), déploie d'abord.
- **Arceau = calibration manuelle 1 tap.** La détection auto de l'arceau
  nécessitera un modèle custom (build natif, plus tard). Un tap, c'est le
  bon compromis sortie-rapide/fiabilité.
- **Tirs très rapides / caméra bas de gamme** : si le ballon n'est détecté
  que par intermittence, des tirs peuvent être annulés (pas comptés). Le
  choix est TOUJOURS « ne pas compter » plutôt que « compter faux ».
- **Expo Go + caméra WebView** : comme le dribble — parfois capricieux en
  Expo Go, fiable en build TestFlight.
- **Mount sandbox** : les fichiers modifiés pendant la session apparaissaient
  tronqués côté sandbox (artefact) ; les fichiers réels sont complets. D'où
  l'étape 1 ci-dessus sur ta machine.

## 4. Impact backlog

- ~~P2 Follow serveur~~ → FAIT (reste : déployer).
- ~~P2 Écran abonnés/suivis~~ → FAIT.
- ~~P2 Détection tir~~ → FAIT en version WebView (trajectoire + comptage +
  sons). Le pose-tracking/angles façon ballai.app reste lié au build natif (P3).
- Nouveau petit plus : les séances des jeux auto s'enregistrent dans
  l'historique Playground (dribble ET tir).
- Prochaine marche vers le store : compte Apple Developer + TestFlight
  (voir doc 26, Bloc D — c'est LE chemin critique maintenant).
