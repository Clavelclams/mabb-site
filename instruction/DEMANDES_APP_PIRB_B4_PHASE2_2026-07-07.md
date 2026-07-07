# Message du dev app (Pirb store) au dev serveur (mabb-site) — B4 phase 2

> Date : 2026-07-07. De : côté app PIRB mobile (Expo). À : côté API mabb-site.
> Objet : ce qui me bloque côté app, et le contrat exact dont j'ai besoin.
>
> Contexte : la phase 1 (5 endpoints cœur) est en prod et marche bien, merci.
> Le contrat côté client est déjà écrit dans `Pirb store/src/types/pirb.ts` et
> l'interface `PirbDataService` : je respecte ces formes, sers-les-moi telles
> quelles et je n'ai aucun écran à réécrire. Rappel important : **l'app tape
> sur la PROD** → tant que ce n'est pas déployé sur OVH, je ne vois rien.

---

## Mes problématiques (ce que je ne PEUX PAS faire tant que le serveur ne suit pas)

Tout ce qui suit est aujourd'hui affiché en **fausses données** dans l'app,
parce qu'aucun endpoint ne me les sert. Ce n'est pas un choix de design, c'est
un manque côté serveur. Résultat : ma démo montre du mock, et je ne peux pas le
rendre réel tout seul.

### 0. Déjà prêt côté serveur, il te reste juste à DÉPLOYER
- Champ `saison` sur `/api/pirb/stats/saison` (fait le 07/07).
- Agrégat `zones` incluant les tirs FFBB sur `/api/pirb/shot-chart` (fait le 07/07).
→ Sans déploiement OVH, l'app ne les voit pas. **Priorité : déployer.**

### 1. Commu réelle — LE plus visible (P1)
- Problème : le rail « La commu » et les cartes joueuses sont du mock. Je veux
  les VRAIES joueuses liées au club MABB.
- Besoin : `GET /api/pirb/commu` → `JoueurPublicCard[]` (cf. types/pirb.ts) :
  les coéquipières de la joueuse connectée (même équipe/club), profils publics.
- Règle : jamais de mineure en public sans consentement parental.

### 2. Follow + compteurs abonnés/suivis (P1)
- Problème : le bouton « Suivre » et les compteurs abonnés/suivis sont mock, et
  quand l'utilisatrice clique sur « abonnés » ou « suivis », **rien ne s'affiche**
  (aucune donnée derrière).
- Besoin : **entité `Follow`** (nouvelle table : follower → followed) +
  `POST /api/pirb/follow/{joueurId}` et `DELETE /api/pirb/follow/{joueurId}` +
  `GET /api/pirb/social-counts` (vrais compteurs) + de quoi lister les abonnés
  et les abonnements (pour construire l'écran liste côté app).

### 3. Sélecteur de saison dans Stats (P1)
- Problème : je n'affiche que la saison courante. La joueuse veut voir ses
  saisons PASSÉES (mais pas les futures).
- Besoin : `GET /api/pirb/stats/saison?saison=YYYY-YYYY` (accepte le paramètre,
  rejette une saison future — ta logique `SaisonService` sait déjà le faire) +
  `GET /api/pirb/saisons` → la liste des saisons disponibles, pour que je
  construise le menu déroulant.

### 4. Détail des points FFBB vs Live (P2)
- Problème : la carte « Points » propose un détail, mais je n'ai pas la répartition.
- Besoin : champ optionnel `pointsParSource: { ffbb, live }` sur
  `/api/pirb/stats/saison`.

### 5. Recherche (P2)
- Problème : la recherche tourne sur du mock, et cliquer sur une joueuse trouvée
  ne mène nulle part.
- Besoin : `GET /api/pirb/recherche?q=` → `{ joueuses: JoueurPublicCard[],
  highlights: HighlightPost[] }` + de quoi ouvrir un profil public de joueuse.

### 6. Profil public de joueuse (P2)
- Problème : le profil scout `/joueuse/{id}` existe en web mais pas dans l'app.
- Besoin : un endpoint qui me sert le profil public d'une joueuse par id
  (respectant sa confidentialité), pour un écran natif.

### 7. Confidentialité « qui voit quoi » (P2)
- Problème : les 6 interrupteurs sauvegardent dans le vide, et surtout **aucune
  règle n'est appliquée** côté serveur.
- Besoin : `GET/POST /api/pirb/confidentialite` + APPLICATION réelle des règles
  de visibilité sur tous les endpoints publics (commu, recherche, profil public).

### 8. Mon club — XP par équipe (P2)
- Besoin : `GET /api/pirb/club-overview` → `ClubOverview` (cf. types/pirb.ts) :
  équipes du club + XP cumulée par équipe + classement.

### 9. Carte Explorer — vrais clubs (P3)
- Problème : les 26 clubs sont codés en dur dans l'app.
- Besoin : `GET /api/pirb/carte` → clubs réels ; un club « allumé » = un club
  présent dans Manager.

### 10. Attributs (Adresse/Dribble/Défense/Physique, 0-20) (P3, bloqué par une DÉCISION)
- Problème : valeurs figées (mock). Le calcul n'existe pas.
- Bloquant AVANT tout code : **définir la formule** — comment les notes de séance
  et les stats de match se transforment en points sur 20 pour chaque attribut ?
  (règle produit à trancher, jamais faite).
- Ensuite : calcul serveur (moteur gamification) + `GET /api/pirb/attributs` +
  UI coach « ajuster les attributs » dans Manager.

### 11. Inscription native (P3)
- Besoin : `POST /api/auth/inscription` + au login, un **403 distinct** pour un
  compte « en attente de validation par le club » (pour afficher le bon message).

### 12. Édition profil : bio + photo (P3)
- Besoin : `POST /api/pirb/profil/bio` (updateBio réel) + endpoint d'upload de
  la photo de profil.

### 13. Feed highlights / Scouting (P3)
- Besoin : `GET /api/pirb/highlights` → `HighlightPost[]` réels (liens vidéo des
  profils publics).

### 14. Sync des séances practice (P4, optionnel)
- Aujourd'hui le practice est persistant en LOCAL (AsyncStorage), ça suffit.
- Plus tard : un `POST /api/pirb/practice` pour synchroniser + attribuer l'XP
  côté serveur.

---

## Deux problèmes transverses (pas des endpoints)

- **500 intermittents** : en navigation, l'app se prend parfois un 500 qui passe
  au retry. Ça sent le transitoire OVH mutualisé (connexions MySQL, worker PHP).
  Peux-tu regarder `var/log/prod.log` au moment d'un 500 ? De mon côté j'ajoute
  un retry auto pour absorber ça.
- **Déploiement** : rien de ce que tu codes n'arrive dans l'app tant que ce
  n'est pas sur OVH. Prévois un déploiement après chaque lot.

## Ordre que je te propose
1. Déployer l'existant (saison + zones FFBB).
2. Commu réelle (#1).
3. Sélecteur de saison (#3).
4. Follow + compteurs (#2) → débloque le clic mort abonnés/suivis.
5. Mon club (#8) + Carte (#9) : data existante, rapides.
6. Le reste selon priorités.
7. Attributs : d'abord trancher la formule, sinon inutile de coder.

Merci — dès qu'un lot est déployé, je remplace le mock (ou le WebView) par du
natif côté app, méthode par méthode, sans toucher aux écrans.
