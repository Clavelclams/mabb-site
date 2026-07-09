# Journal d’exécution — MABB / PIRB

## Format
### YYYY-MM-DD
- Objectif :
- Actions réalisées :
- Fichiers modifiés :
- Décisions (ADR si applicable) :
- Points de vigilance / risques :

---

### 2026-07-08 — Multi-club Lot 2b-1 : entité Club (création & officialisation)

- Référentiel FFBB importé en prod ✅ (~7105 organismes ; MABB HDF0080036 reconnu).
- Décisions produit (défauts validés « fais le max ») : discipline = féminin/masculin/mixte ; champ `plan` défaut « Découverte » sans facturation ; couleurs = skip (v2).
- Actions (couche données) :
  1. Entité `Club` : + `discipline` (nullable), + `numeroFfbb` (nullable, **unique** — anti-doublon, MySQL autorise plusieurs NULL), + `isOfficiel` (bool défaut false), + `plan` (défaut PLAN_DECOUVERTE), + `createur` (ManyToOne User, onDelete SET NULL). Constantes DISCIPLINE_* / PLAN_* + libellés + getters/setters (numeroFfbb normalisé majuscules/trim).
  2. Service `ClubOfficialisation` : `rafraichir(Club)` pose isOfficiel selon `OrganismeFfbbRepository::estOfficiel` ; `organismePour(Club)` renvoie l'organisme (pré-remplissage nom officiel).
- À faire par Clavel : `doctrine:migrations:diff` + relire + `migrate` (ajoute les colonnes club + contrainte unique numero_ffbb).
- Reste (Lot 2b-2) : écran d'accueil public « Créer / Rejoindre un club » + formulaire de création (vérif FFBB en direct) + le créateur devient **admin auto** (UserClubRole DIRIGEANT/ADMIN actif). À faire APRÈS la migration + lecture du flux ManagerInscription/UserClubRole pour câbler le rôle correctement.

---

### 2026-07-08 — Multi-club Lot 2a : référentiel officiel FFBB (socle « club officiel »)

- Décision produit (Clavel) : n'importe qui peut créer un club → créé **non-officiel**, créateur = admin auto. Un club devient **officiel** si son n° FFBB existe dans l'annuaire officiel FFBB. Officiel et non-officiel = **mêmes fonctionnalités**. Anti-doublon via le n° FFBB.
- Réponse à « comment un club devient officiel » : il faut un **numéro FFBB présent dans le référentiel FFBB**. Clavel a fourni les exports FFBB « Rechercher un organisme » (`rechercherOrganisme.xlsx`, ~7100 clubs/ententes : N° groupement type `HDF0080036`, Nom, Type).
- Actions (socle, autonome) :
  1. Entité `OrganismeFfbb` (référence read-only : `numero` unique, `nom`, `type`). Table `organisme_ffbb`.
  2. `OrganismeFfbbRepository` : `findOneByNumero()` + `estOfficiel()`.
  3. Commande `app:import-ffbb-organismes <fichier.xlsx> [--dry-run]` (PhpSpreadsheet) : upsert par numéro, idempotent (relançable sur les 2 exports), flush par lots de 500, validation format `^[A-Z]{2,4}\d{5,}$`.
- À faire par Clavel : `doctrine:migrations:diff` + `migrate` (crée la table), puis importer les xlsx (voir commandes du jour). Aucune donnée club touchée.
- Reste (Lot 2b) : champs `Club` (numeroFfbb, isOfficiel, discipline, createur, plan) + migration + formulaire de création + écran d'accueil public + officialisation (match sur `OrganismeFfbb`). En attente de 3 confirmations (discipline / champ plan / couleurs).

---

### 2026-07-08 — Multi-club Lot 1 : super-admin cross-club (admin@velito.fr)

- Objectif : un compte support (admin@velito.fr) qui voit TOUS les clubs et peut entrer dans n'importe lequel pour dépanner, sans en être membre.
- Constat : `ROLE_SUPER_ADMIN` existe déjà et `ClubVoter` le court-circuite (tous droits dans un club). La commande `app:create-admin` crée déjà un super-admin. Le blocage était `TenantResolver::getCurrentClub/setCurrentClub` qui exigeait l'appartenance au club.
- Actions :
  1. `TenantResolver` : bypass `userBelongsToClub` pour un super-admin (getCurrentClub + setCurrentClub), + helper `isSuperAdmin()`.
  2. `SuperAdminController` (`#[IsGranted('ROLE_SUPER_ADMIN')]`) : `GET /super-admin/clubs` (liste tous les clubs) + `POST /super-admin/clubs/{id}/entrer` (pose le club actif, CSRF).
  3. Template `manager/super_admin/clubs.html.twig` : page autonome (n'étend pas le layout Manager → pas de dépendance à un club actif).
  4. `ManagerLoginController::dashboard` : super-admin sans club actif → redirigé vers la console cross-club (flux fluide à la connexion).
- Sécurité : accès réservé au seul rôle global ROLE_SUPER_ADMIN (aucun rôle de club ne l'accorde). Le club actif d'un super-admin est posé en session comme pour un user normal.
- Fichiers : `src/Security/Tenant/TenantResolver.php`, `src/Controller/Manager/SuperAdminController.php` (nouveau), `templates/manager/super_admin/clubs.html.twig` (nouveau), `src/Controller/Manager/ManagerLoginController.php`, ce log.
- Compte à créer (Clavel, sur OVH, mdp saisi en caché) : `php bin/console app:create-admin --email=admin@velito.fr --prenom=Clavel --nom=Velito`.
- Reste (Lot 2) : création/rejoindre un club (public) → migration Club (discipline, n° FFBB, officiel, couleurs, créateur) + décisions produit à trancher avant de coder.

---

### 2026-07-08 — Vitrine : responsive mobile (navbar admin + tableaux + images)

- Objectif : corriger les débordements sur mobile/iPad quand connecté admin (navbar qui déborde, tableaux/divs coupés, scroll horizontal).
- Analyse (avant de coder) :
  - **Découverte** : `templates/vitrine/navbar.html.twig` n'est inclus NULLE PART → fichier MORT. La navbar LIVE est inline dans `base.html.twig` (`#navbarMain`). Conséquence : le bouton « Espace membre » ajouté plus tôt dans navbar.html.twig ne s'affiche pas (à refaire dans base.html.twig si voulu).
  - Cause navbar : zone auth `d-flex align-items-center gap-2` SANS flex-wrap ni stacking → en admin (6-7 boutons), débordement horizontal dans le menu replié (hamburger jusqu'à 1400px car `navbar-expand-xxl`).
  - Cause tableaux : `admin/articles/index`, `logs_connexion`, `rgpd` ont des `<table>` SANS `.table-responsive` → coupés par l'`overflow-x:hidden` du body. (Le calendrier vitrine a déjà son wrapper.)
- Actions (`templates/vitrine/base.html.twig` uniquement) :
  1. Zone auth navbar → `flex-wrap` + `justify-content-center justify-content-xxl-end` + `w-100 w-xxl-auto mt-3 mt-xxl-0` : les boutons passent à la ligne / se centrent au lieu de déborder.
  2. Filet CSS responsive global : `img/video/iframe { max-width:100% }`, `.article-content { overflow-wrap:break-word }`, et **@media ≤991px** : `table.table, .table-mabb { display:block; overflow-x:auto; white-space:nowrap }` → scroll horizontal INTERNE aux tableaux au lieu d'être coupés. Couvre les tableaux admin actuels ET futurs sans éditer chaque fichier.
- Fichiers : `templates/vitrine/base.html.twig`, ce log.
- Vigilance :
  - Je ne vois pas le rendu : j'ai corrigé les causes structurelles/CSS. Si une page précise déborde encore après déploiement, l'indiquer pour un fix ciblé.
  - `navbar.html.twig` mort : à supprimer un jour (ménage), et re-créer « Espace membre » dans base.html.twig si on le veut vraiment.

---

### 2026-07-08 — Manager : filtre saison sur l'ENT (PDF FFBB) + fix fuite multi-tenant

- Objectif : sur `/ent`, ne plus montrer les PDF officiels FFBB des saisons passées en 2026-2027 (sauf via sélecteur), en gardant les documents uploadés (réunions, règlements) visibles en continu — demande explicite de Clavel.
- Constat clé : **aucune migration nécessaire.** Les PDF FFBB ne sont pas des `Document` mais des fichiers rattachés aux `Rencontre` (datées) → filtrables par saison comme Stats Live. Les `Document` uploadés n'ont pas de saison et restent affichés (= règle « les réunions restent continues »).
- Actions :
  1. `RencontreRepository::findWithPdfsByClubAndSaison(clubId, saison)` : variante saison de `findWithPdfsByClub`, filtre par plage de dates (01/07 → 01/07).
  2. **FIX SÉCURITÉ** : `findWithPdfsByClub` avait un `OR` NON parenthésé → précédence SQL (AND avant OR) court-circuitant le filtre `club` → **fuite multi-tenant potentielle**. Parenthèses ajoutées aux deux méthodes.
  3. `DocumentController::index` : injection `SaisonService`, PDF FFBB filtrés par `getSaisonActive()`, passage `saison_affichee`.
  4. Template `document/index.html.twig` : sélecteur de saison sur la section PDF FFBB (poste vers `saison_changer`) + empty-state « aucun PDF pour la saison X », visible même sans PDF.
- Décisions : aucune ADR. Choix produit acté : PDF FFBB = par saison ; documents uploadés = continus. Si un jour des documents uploadés doivent aussi être scopés par saison, ce sera une migration `Document.saison` séparée (non nécessaire aujourd'hui).
- Fichiers : `src/Repository/Sport/RencontreRepository.php`, `src/Controller/Manager/DocumentController.php`, `templates/manager/document/index.html.twig`, ce log.
- Vigilance : non linté ici (pas de PHP) → `php -l` + `lint:twig` + `cache:clear` avant commit. Le fix multi-tenant sur `findWithPdfsByClub` mérite un coup d'œil rapide (comportement inchangé pour MABB seul, mais plus correct).

---

### 2026-07-08 — Vitrine Lot 2 : bouton « Espace membre »

- Objectif : CDC §3.10 + §6.1 — accès membre depuis le site public.
- Action : bouton **« Espace membre »** (menu déroulant Manager / PIRB) ajouté à la navbar (`templates/vitrine/navbar.html.twig`), toujours visible.
- Décision : choix explicite plutôt que redirection auto par rôle. Les 3 apps ont des sessions séparées par sous-domaine, et le rôle (coach/joueuse) est défini PAR CLUB (non résolu sur le site public) → une auto-redirection ne ferait pas gagner la connexion et serait peu fiable. Menu de choix = fallback prévu par le CDC.
- Reste vitrine : Lot 3 (« comment s'inscrire » + tarifs + pré-inscription → dépend mailer B-304 ; carte d'accès + horaires Contact), Lot 4 (calendrier dynamique, fiches équipe, galerie par équipe).

---

### 2026-07-07 — Module Sorties : Lot C (dashboard global saison)

- Objectif : vue d'ensemble des sorties d'une saison (doc 23 §6.2).
- Actions réalisées :
  1. `EvenementRepository::sortiesParClubEtSaison(club, debut, fin)` : sorties (type SORTIE) dans une fenêtre de dates.
  2. `EvenementController::dashboardSorties()` route `GET /evenements/sorties/dashboard` (CLUB_STAFF). Bornes de saison calculées par la bascule 1er juillet ([1er juil. YYYY, 1er juil. YYYY+1[). Agrège les inscriptions de chaque sortie (réutilise `agregatsInscriptions`). Sélecteur de saison via `?saison=`. `SaisonService` injecté.
  3. Template `sorties_dashboard.html.twig` : cartes globales (nb sorties, participations, encaissé, autorisations manquantes) + tableau par sortie (inscrits, autor. reçues, encaissé, présents, lien vers l'événement).
  4. Lien « Dashboard sorties » ajouté sur la page Événements (staff).
- Points de vigilance :
  - **À tester** après `cache:clear` : ouvrir `/evenements/sorties/dashboard` (ou le bouton sur la page Événements), changer de saison.
  - Reste : Lot D (RGPD : entrée registre, purge fin de saison, upload décharge v2).

---

### 2026-07-07 — Module Sorties : Lot B (inscriptions, autorisation, paiement, présence)

- Objectif : rendre les sorties gérables depuis /evenements/{id} (remplacer le Sheet).
- Actions réalisées (`src/Controller/Manager/EvenementController.php`) :
  1. Injection `InscriptionSortieRepository` + `JoueurRepository`.
  2. `show()` enrichi (staff only) : liste des inscriptions + agrégats (inscrits, autorisations reçues/manquantes, payés + total €, à payer, présents) + joueuses du club pour le formulaire.
  3. 5 routes STAFF + CSRF : `POST .../inscriptions` (ajout licenciée OU saisie libre selon `ouvertA`, cohérences dérivées doc 23 §4.3), `.../{iid}/autorisation` (bascule EN_ATTENTE↔RECUE), `.../{iid}/paiement` (statut/montant/moyen/date), `.../{iid}/presence`, `.../{iid}/supprimer`. Helper `chargerInscription` (CSRF + appartenance à l'événement + `ClubVoter` sur l'inscription via ClubAwareInterface).
  4. `hydraterDepuisRequete` : gère `est_payant` / `prix` / `autorisation_requise`.
- Templates : `show.html.twig` → section « Inscriptions à la sortie » (staff + type sortie) : dashboard agrégats + formulaire d'ajout (licenciée vs saisie libre) + tableau avec actions inline. `edit.html.twig` → section « Sortie : paiement & autorisation ».
- Points de vigilance / risques :
  - **À tester** : `php bin/console cache:clear` (surface les erreurs PHP), puis créer/éditer une sortie payante + autorisation, ouvrir l'événement, ajouter des participants, gérer autorisation/paiement/présence. Il faut être CLUB_STAFF.
  - Isolation : chaque route exige CLUB_STAFF + CSRF ; l'inscription est protégée par le ClubVoter (ClubAwareInterface via evenement.club).
  - Reste : Lot C (dashboard global saison), Lot D (RGPD : registre, purge, upload décharge v2). À déployer avec le Lot A (migration) une fois validé en local.

---

### 2026-07-07 — Module Sorties : Lot A (fondations, aucune UI)

- Objectif : démarrer le module Sorties (doc 23) pour remplacer le Google Sheet. Lot A = migration + entités + repo, zéro UI.
- Actions réalisées :
  1. `src/Entity/Sport/Evenement.php` : 3 champs ajoutés (`estPayant` bool défaut false, `prix` decimal(6,2) nullable, `autorisationRequise` bool défaut false) + getters/setters.
  2. `src/Entity/Sport/InscriptionSortie.php` (NOUVEAU) : entité participant à une sortie (identité licenciée OU saisie libre, autorisation, paiement en suivi, présence). Implémente `ClubAwareInterface` via `evenement.club`. Helpers `getNomAffichage()`, `isMineur()`. Constantes pour les enums (autorisation/paiement/moyen/présence).
  3. `src/Repository/Sport/InscriptionSortieRepository.php` (NOUVEAU) : `findByEvenement()`.
  4. **ADR-0011** acté dans `08_ADR.md` (entité séparée de EvenementParticipation).
- Décisions : cf. ADR-0011. Paiement = suivi seul ; autorisation v1 = case reçue ; RGPD mineurs (staff only) à cadrer au Lot D.
- Points de vigilance / risques :
  - **Migration à générer + jouer sur le PC** : `php bin/console make:migration` (valide aussi que les entités compilent), relire le SQL, puis `php bin/console doctrine:migrations:migrate`. Puis `--env=test doctrine:schema:update --force` (ou recréer la base de test) pour que les tests suivent.
  - Défauts sur les 2 booléens → zéro régression sur les événements existants.
  - Prochain : Lot B (formulaire d'inscription + tableau dans /evenements/{id} + actions autorisation/paiement/présence, avec ClubVoter + CSRF).

---

### 2026-07-08 — Vitrine « lot 1 » : compléments CDC §3 (front)

- Objectif : faire monter la vitrine vers 100 % (hors boutique, descopée pour MABB, cf. doc 24). Lot 1 = quick wins front, zéro migration.
- Actions :
  1. **Compteurs accueil éditables CMS** (`vitrine/accueil/index.html.twig`) : les 4 chiffres (licenciées, labels, quartiers, années) passent de valeurs en dur à `cms('clé','défaut')` → éditables dans `/admin/contenus`, valeur numérique conservée pour l'animation. Choix : NE PAS auto-compter les licenciées (le nb de joueuses dans l'app ≠ nb réel de licenciées → chiffre faux).
  2. **Page 404 stylisée** : `templates/bundles/TwigBundle/Exception/error404.html.twig` (override Symfony, étend la vitrine, identité MABB).
  3. **Bandeau cookies RGPD** : `vitrine/_cookies.html.twig` (informatif, cookies nécessaires only, acquittement + localStorage) inclus dans `base.html.twig`.
  4. **CGU + Plan du site** : 2 routes dans `LegalController` (`/conditions-generales`, `/plan-du-site`) + templates `legal/cgu.html.twig` et `legal/plan_site.html.twig` + liens ajoutés au footer.
  5. **Boutons de partage** (Facebook/X/WhatsApp/copier-lien) sur `vitrine/accueil/article.html.twig`.
- Décisions : boutique e-commerce (CDC §3.7) **descopée pour MABB**, conservée comme feature SaaS backlog (demande Willy) — actée dans doc 24.
- Fichiers : index.html.twig, error404.html.twig (nouveau), _cookies.html.twig (nouveau), cgu.html.twig (nouveau), plan_site.html.twig (nouveau), LegalController.php, base.html.twig, article.html.twig, docs 24 + ce log.
- Points de vigilance :
  - Non linté ici (pas de PHP) → `lint:twig` + `php -l LegalController` + `cache:clear` avant commit.
  - CGU = document type, à faire relire par un juriste (noté dans la page).
  - Reste vitrine : lot 2 (bouton « Espace membre » par rôle), lot 3 (inscription/tarifs + pré-inscription → dépend mailer B-304), lot 4 (calendrier dynamique, fiches équipe).

---

### 2026-07-07 (quinquies) — Manager : filtre saison sur Stats Live

- Objectif : sur `/stats-live`, ne plus afficher les matchs des saisons passées en 2026-2027, sauf si on bascule la saison via un dropdown sur la page.
- Constat : la page listait TOUTES les rencontres du club sans filtre saison (`findByClubOrderedDesc`). Un sélecteur de saison GLOBAL existe déjà (base.html.twig, `saison_active()` + POST `/saison/changer`, session `active_saison`), fonctionnel (redirection Referer). Il suffisait de faire respecter la saison active à stats-live.
- Actions :
  1. `RencontreRepository::findByClubAndSaisonOrderedDesc($clubId, $saison)` — variante saison de `findByClubOrderedDesc`, filtre par PLAGE DE DATES (01/07 → 01/07) et non par la colonne `r.saison` (nullable, peu fiable sur rencontres créées à la main) — même règle que `JoueurStatsAggregator` et `SaisonService`.
  2. `StatsLiveController::liste()` : injection de `SaisonService`, filtrage par `getSaisonActive()`, passage de `saison_affichee` au template.
  3. Template `stats_live/index.html.twig` : dropdown saison visible en tête (poste vers `saison_changer`, `onchange submit`) + empty-state « Aucun match pour la saison X ».
- Décisions : aucune ADR (réutilise l'infra saison existante). Choix de filtrer par date plutôt que par `r.saison` documenté dans le repo. Le dropdown de page pilote la MÊME saison de session que le sélecteur global (les deux restent synchro via `saison_active()`).
- Fichiers modifiés : `src/Repository/Sport/RencontreRepository.php`, `src/Controller/Manager/StatsLiveController.php`, `templates/manager/stats_live/index.html.twig`, ce log.
- Points de vigilance :
  - Effet de bord assumé : une rencontre SANS date n'appartient à aucune saison → n'apparaît plus dans la vue filtrée.
  - Non testé côté PHP dans l'environnement (pas de PHP dispo) : à linter + `cache:clear` en local avant commit.
  - Même logique à rappliquer ensuite sur l'ENT (mais là il faudra une migration : Document n'a pas de champ saison).

---

### 2026-07-07 (quater) — API PIRB B4 lot 2 : sélecteur de saison

- Objectif : débloquer côté app le menu déroulant des saisons (demande #3 de `DEMANDES_APP_PIRB_B4_PHASE2`). La joueuse veut consulter ses saisons passées, jamais les futures.
- Contexte : session d'audit complet des 3 dépôts (mabb-site, Pirb store, mabb) → rapport dans `instruction/22_AUDIT_ECOSYSTEME_2026-07-07.md`. Chantier retenu ensuite : endpoints API pour le mobile. Premier lot = sélecteur de saison (zéro migration, zéro décision produit, s'appuie sur l'existant).
- Actions réalisées (`src/Controller/Api/PirbApiController.php`) :
  1. **Nouveau `GET /api/pirb/saisons`** → `{ courante, saisons[] }`. Source unique : `SaisonService::getSaisonsDisponibles()` (courante → 2023-2024, jamais de futur) + `getSaisonCourante()`. On renvoie `courante` pour que l'app présélectionne sans redupliquer la logique de bascule 1er juillet côté client. Pas de dépendance au Joueur (liste identique pour tous), mais auth Bearer exigée par le firewall `api`.
  2. **`GET /api/pirb/stats/saison` accepte `?saison=YYYY-YYYY`** (facultatif) : absent → saison courante (rétrocompatible) ; valide → cette saison ; invalide/futur → **400**. La validation réutilise `SaisonService::isValide()` (une saison future n'est pas dans la liste, donc rejetée sans logique en plus). Le champ `saison` de la réponse reflète désormais la saison réellement servie.
- Tests : nouveau `tests/Functional/Pirb/PirbSaisonsApiTest.php` — **premier test fonctionnel de l'API Bearer** (lacune signalée à l'audit). Couvre : 401 sans jeton, forme de `/saisons` (courante en tête, format YYYY-YYYY), 400 sur saison future, écho du libellé sur saison valide, rétrocompat sans paramètre. Auth via `ApiToken::creerPour()`, isolation par transaction annulée (schéma `PirbIdorTestCase`).
- Fichiers modifiés : `src/Controller/Api/PirbApiController.php`, `tests/Functional/Pirb/PirbSaisonsApiTest.php` (nouveau), `instruction/22_AUDIT_ECOSYSTEME_2026-07-07.md` (nouveau), ce log.
- Décisions : aucune ADR (extension d'endpoints existants, pas d'architecture nouvelle ; réutilise SaisonService, la source de vérité unique).
- Points de vigilance / risques :
  - **Prod uniquement après déploiement OVH** : l'app tape sur pirb.mabb.fr → tant que ce n'est pas déployé (git pull + cache:clear), l'app ne voit ni `/saisons` ni le param.
  - Côté app (Pirb store) : brancher `getStatsSaison(saison?)` sur `?saison=` et construire le menu depuis `/api/pirb/saisons`. Rien d'autre à changer (contrat `StatsSaison.saison` déjà prévu).
  - Non couvert ici : `pointsParSource` (demande #4) — lot suivant si besoin.

---

### 2026-07-07 (quater) — API PIRB : endpoint Commu (vraies joueuses du club)

- Objectif : l'app affichait de fausses joueuses (Lina_du13...). Servir les VRAIES joueuses liées au club MABB.
- Actions (`src/Controller/Api/PirbApiController.php`) : nouveau `GET /api/pirb/commu` → `JoueurPublicCard[]`. Renvoie les joueuses actives du club de la joueuse connectée (via `JoueurRepository::findByClub`), hors elle-même. Mapping : `pseudo` = « Prénom Nom » (pas de compte app → pas de pseudo), `equipe` = nom d'équipe (saison courante), `club`, `poste`, `photoUrl` absolue, `suivie=false` (Follow pas encore posé), `estCoequipiere` = même équipe.
- Décisions : profils NON publics tant qu'elles n'ont pas de compte app → on n'expose que le minimum club (nom, équipe, poste, photo), pas de stats. Non cliquable côté app.
- Points de vigilance :
  - **À déployer sur OVH** pour visibilité dans l'app.
  - ⚠️ RGPD mineures : liste intra-club (visible d'un membre connecté du club). Consentement parental à cadrer avant toute exposition plus large.
  - **Reste signalé (NON fait)** : le shot chart n'est PAS filtré par saison (positionsTirs + TirFfbb sans saison) → même rendu pour 2025-26 et 2026-27, côté web ET API. À traiter avec le chantier « sélecteur de saison » (threader la saison dans positionsTirs + filtrer les TirFfbb).

---

### 2026-07-07 (ter) — API PIRB : libellé de saison + zones incluant les tirs FFBB

- Objectif : deux manques constatés en testant l'app avec un vrai compte joueuse (U13, données FFBB) : (1) la saison n'est pas identifiée dans /stats/saison ; (2) « zone par zone » est vide pour un joueur 100 % FFBB.
- Actions réalisées (`src/Controller/Api/PirbApiController.php`) :
  1. **/stats/saison** : ajout du champ `'saison' => $this->saisonService->getSaisonCourante()` à la réponse. Les stats étaient déjà calculées pour la saison courante ; il manquait juste le libellé. L'app affiche la puce de saison dès que le champ est présent (contrat `StatsSaison.saison`, déjà prévu).
  2. **/shot-chart** : l'agrégat `zones` était construit par `ShotChartCalculator::statsParZone()`, qui ne compte QUE les tirs Stats Live (positionsTirs ← ActionMatch). Un joueur 100 % FFBB voyait donc 8 zones à zéro alors que ses tirs FFBB étaient bien dans `tirs`. Correctif : l'agrégat est désormais calculé DANS le controller à partir de la même liste `$tirs` (LIVE + FFBB), format identique (tentes/reussis/pourcentage arrondi à 1 décimale, zones exhaustives via `ZONE_LIBELLES`).
- Décision d'archi : NE PAS modifier `statsParZone()` (utilisé par le web + `shootPreferentiel`) → agrégation locale au endpoint API. Zéro impact sur le web, l'API devient cohérente (tirs et zones même source). Le filet client de l'app (session 11 côté Pirb store) devient redondant mais reste inoffensif.
- Fichiers modifiés : `src/Controller/Api/PirbApiController.php`, ce log.
- Points de vigilance / risques :
  - **Effet visible uniquement là où l'API tourne** : si l'app pointe sur pirb.mabb.fr (prod), il faut DÉPLOYER sur OVH pour voir le changement ; en local, pointer l'app sur le serveur local.
  - Vérifier vite que le shot chart WEB n'a pas bougé (on n'a pas touché `statsParZone`, donc normalement identique).
  - `pourcentages`/`pointsParSource` non traités ici (hors périmètre ; l'app gère leur absence).
  - Reste possible : un test fonctionnel de l'endpoint (auth Bearer via ApiToken + seed TirFfbb) pour verrouiller l'agrégat FFBB.

---

### 2026-07-07 (bis) — BUG-01 confirmé résolu + verrouillé par un test

- Objectif : chantier 2 (P0 web), volet bugs. Investiguer BUG-01 (500 sur `/joueuses/{id}/missions/nouvelle`) et le clôturer proprement.
- Constat : le 500 venait du recalcul XP/badges après création de mission ; déjà corrigé par B-205 (try/catch `\Throwable` autour de la gamification). Template, constantes `Mission::TYPES`, route de redirection : tous sains. Aucune autre voie de 500 trouvée.
- Actions réalisées :
  1. **`tests/Functional/Manager/MissionAccessTest.php`** (nouveau) — 2 cas verts sur le host `manager.localhost` : un staff du club voit le formulaire (200 → régression BUG-01 verrouillée, plus de 500) ; un staff d'un AUTRE club est refusé (403 via `ClubVoter::CLUB_STAFF`). Réutilise `PirbIdorTestCase` (seed + transaction). Helper `staffDeClub` (UserClubRole DIRIGEANT actif).
  2. `09_BACKLOG.md` : BUG-01 passé à « corrigé + testé ».
- Fichiers modifiés : `tests/Functional/Manager/MissionAccessTest.php` (créé), `09_BACKLOG.md`, cette entrée.
- Décisions (ADR) : aucune.
- Points de vigilance / risques :
  - BUG-02 (404 /signup) : déjà couvert par la redirection 301 de B-206 (log sexies) — à confirmer, sinon rien à faire. BUG-03 : cosmétique (B-102 en cours).
  - Mailer Brevo (B-304) : reste une config prod OVH côté Clavel (secrets non touchés).

---

### 2026-07-07 — Socle de tests fonctionnels + premier test IDOR PIRB (HTTP réel)

- Objectif : passer des tests unitaires (logique) aux tests fonctionnels (comportement HTTP réel) pour prouver l'isolation PIRB de bout en bout. Prérequis manquant découvert : aucune config d'environnement de test (`config/packages/test/` était vide).
- Actions réalisées :
  1. **`config/packages/test/framework.yaml`** (nouveau) : `framework.test: true` + session `mock_file`. Sans lui, `WebTestCase::createClient()` refuse de démarrer (erreur rencontrée puis résolue).
  2. **`.env.test.local`** (nouveau, gitignored) : `DATABASE_URL` de test pointant sur le `root` local (Laragon), pour ne pas dépendre des identifiants de prod. La config Doctrine ajoute déjà le suffixe `_test` → base `mabb_pirb_test`, isolée.
  3. **`tests/Functional/Pirb/PirbSeancesIdorTest.php`** (nouveau) — 3 cas, tous verts : anonyme → redirigé vers login (firewall par host `pirb.localhost`) ; joueuse voit SA séance (200) ; joueuse reçoit 403 sur la séance d'une autre équipe (LE test IDOR). Isolation par transaction annulée en tearDown (pas de bundle DAMA requis).
- Fichiers modifiés : `config/packages/test/framework.yaml` (créé), `.env.test.local` (créé, non commité), `tests/Functional/Pirb/PirbSeancesIdorTest.php` (créé), cette entrée.
- Décisions (ADR) : aucune. Choix : `loginUser()` (auth sans formulaire, on teste l'autorisation) ; seed via EntityManager calqué sur `SportFixtures` ; base de test séparée créée via `doctrine:schema:create` (pas les migrations, plus rapide pour les tests).
- Points de vigilance / risques :
  - **Prérequis run** : MySQL/MariaDB local démarré + `php bin/console --env=test doctrine:database:create` puis `doctrine:schema:create` (une fois). Si le schéma des entités change, refaire un `doctrine:schema:update --force --env=test` ou recréer la base de test.
  - `config/packages/test/framework.yaml` **doit être commité** (nécessaire à tout run de test fonctionnel). `.env.test.local` **ne doit pas** l'être.
  - Patron décliné le 07/07 sur 2 routes de plus : `PirbStatsMatchIdorTest` (GET /stats/match/{id} → 403 sur match d'une autre équipe, sans CSRF) et `PirbShotChartIdorTest` (route destructive : prouve auth requise + protection CSRF ; le franchissement complet du CSRF en test est laissé de côté, friction connue du SameOriginCsrfTokenManager Symfony 7). Classe de base commune `PirbIdorTestCase`. Total functional : 6 tests verts.
  - Reste à décliner (plus tard) sur : convocations, documents, mes-parents.
  - Note CSRF-en-test : pour tester le franchissement complet d'une route CSRF (POST/DELETE) jusqu'au contrôle métier, il faudra soit crawler le formulaire (token dans le HTML), soit pré-injecter le token en session de façon fiable. Non bloquant pour l'isolation, qui est prouvée par ailleurs (unitaires + séances/stats + audit).

---

### 2026-07-06 (octies) — Tests du cœur multi-tenant : ClubVoter + TenantResolver (P0 audit 29/06)

- Objectif : couvrir de tests le cœur d'autorisation multi-tenant, resté à ~0 test et qualifié « indéfendable jury CDA » par l'audit du 29/06 (P0). Aucun code de prod modifié : uniquement de nouveaux tests unitaires purs (pas de base de données).
- Actions réalisées :
  1. **`tests/Unit/Security/Voter/ClubVoterTest.php`** (nouveau) — 18 cas : garde-fous (attribut non supporté → abstain, subject non-Club → abstain, token anonyme → deny, entité ClubAware sans club → deny), court-circuit `ROLE_SUPER_ADMIN`, chaque attribut (MEMBER/COACH/ADMIN/STAFF/JOUEUR/STAFF_ELARGI) avec un cas accepté et un cas refusé, rejet des rôles `pending` et désactivés, extraction du club via `ClubAwareInterface`. **Test central : `testCoachDuClubANeVotePasPourLeClubB`** (anti-fuite inter-club) + `testEntiteDunAutreClubRefusee`.
  2. **`tests/Unit/Security/Tenant/TenantResolverTest.php`** (nouveau) — 15 cas : `userBelongsToClub` (valide / pending / désactivé / autre club), `getUserClubs` (exclusion pending + club inactif + dédoublonnage), `getCurrentClub` (pas d'user → null, auto-sélection mono-club, multi-club → null, club choisi en session respecté), `setCurrentClub` (refus si non affilié / accept si affilié), `hasPendingMembership`, `getCurrentUserRoles`/`hasRole`. **Test sécurité central : `testSessionForgeeVersUnAutreClubEstIgnoree`** (un `active_club_id` forgé vers un club non affilié n'est jamais servi).
- Fichiers modifiés : 2 fichiers de test créés + cette entrée. **AUCUN fichier de src/ touché. AUCUN commit** (Clavel commit lui-même).
- Décisions (ADR si applicable) : aucune. Choix de test : unitaires purs (TestCase, sans Kernel/DB) pour rapidité et déterminisme ; IDs posés par réflexion (les entités n'ont pas de setId) ; `Security` et `ClubRepository` mockés, session réelle via `MockArraySessionStorage` (même pattern que `SaisonServiceTest`).
- Points de vigilance / risques :
  - **À lancer sur le PC** (PHP absent du sandbox) : `php bin/phpunit --filter 'ClubVoterTest|TenantResolverTest'`. Le lint et l'exécution n'ont pas pu être faits ici.
  - Un seul point à confirmer au premier run : le mock de `Symfony\Bundle\SecurityBundle\Security` (OK tant que la classe n'est pas `final` — cas standard). Si souci, remplacer par un double simple.
  - Reste au P0 audit après ça : mailer Brevo prod et bugs ouverts (hors périmètre de cette session).

---

### 2026-07-06 (septies) — B4 PHASE 1 : l'API mobile PIRB est née (les données Manager arrivent dans l'app)

- Objectif : valider le chantier B4 de bout en bout — l'app PIRB Mobile (dépôt « Pirb store », Expo/palier P0) consomme les données RÉELLES de Manager. Contrainte : pas de Composer en sandbox → implémentation SANS nouvelle dépendance (ADR-0010).
- Actions réalisées (côté mabb-site) :
  1. **Auth par jetons opaques** : entité `ApiToken` (hash SHA-256 seul en base, expiration 30 j, révocable, libellé appareil) + `ApiTokenRepository` + migration `Version20260706100000` (table api_token).
  2. **`ApiTokenHandler`** (AccessTokenHandlerInterface) branché sur l'authenticator NATIF `access_token` de Symfony — firewall `api` reconfiguré (le stub `# jwt: ~` de 2026-02 prend enfin vie), firewall `api_login` public, access_control ^/api → IS_AUTHENTICATED_FULLY.
  3. **`ApiAuthController`** : POST /api/auth/login (mêmes comptes que pirb.mabb.fr, message anti-énumération, jeton montré une seule fois) + POST /api/auth/logout (révocation).
  4. **`PirbApiController`** : 5 endpoints au CONTRAT EXACT de `Pirb store/src/types/pirb.ts` — /api/pirb/profil (équipe par saison, photo en URL absolue), /stats/saison (mapping snake→camel de JoueurStatsAggregator), /shot-chart (tirs LIVE via ShotChartCalculator + tirs FFBB via ffbb_x/y pour-mille, 8 zones agrégées), /badges (catalogue complet + dates de déblocage), /niveau (XpCalculator + NiveauCatalog). AUCUN paramètre {id} : chaque endpoint sert le user du Bearer (isolation par construction).
  5. **routes.yaml** : prefix /api retiré de l'import api_controllers (aurait doublonné en /api/api/… ; dossier vide avant B4).
- Actions réalisées (côté « Pirb store » — gouvernance locale respectée, AUCUN commit) :
  6. **`ApiPirbDataService`** implémentant l'interface `PirbDataService` : 5 domaines cœur via fetch+Bearer avec re-login auto sur 401 ; le reste du contrat (social/practice/carte) délégué au mock interne tant que les endpoints n'existent pas — écrans inchangés, promesse de l'architecture tenue.
  7. **Factory** : case 'api' activé (EXPO_PUBLIC_DATA_SOURCE=api + EXPO_PUBLIC_API_URL), erreur bruyante si URL manquante. `.env.example` mis à jour (URL prod/dev + identifiants de TEST P0 avec avertissement sécurité).
- Décisions (ADR) : **ADR-0010** — B4 phase 1 en Symfony natif (access_token opaque), API Platform/LexikJWT = phase 2 depuis un poste de dev ; le point structurant d'ADR-0007 (API sur le monolithe) est respecté.
- Points de vigilance / risques :
  - **Migration à jouer** : `doctrine:migrations:migrate` (api_token) + cache:clear.
  - **Test rapide serveur** : `curl -X POST https://pirb.mabb.fr/api/auth/login -H 'Content-Type: application/json' -d '{"email":"…","password":"…"}'` puis `curl https://pirb.mabb.fr/api/pirb/profil -H 'Authorization: Bearer <token>'`.
  - **Identifiants en .env côté app = P0 test uniquement** — l'écran de connexion (avec expo-secure-store) est LE prérequis avant toute distribution.
  - Pas de refresh token en phase 1 : re-login à expiration (30 j), assumé.
  - Typecheck TS impossible en sandbox (copies montées tronquées) : `npm run typecheck` à lancer sur le PC.

---

### 2026-07-06 (sexies) — Lot chantiers courts + fond : bugs, légal, B-201, CMS, A/B, tests, sécu PIRB, CI, doc

- Objectif : traiter le lot confié par Clavel — chantiers courts (bugs B-102/205/206, pages légales, B-201, CMS généralisé, Composer le 5 A/B), chantiers de fond faisables en sandbox (tests unitaires, passe sécurité PIRB, GitHub Action parse PDFs) et dette documentaire (roadmap, ADR-0007).
- Actions réalisées :
  1. **B-205** — cause exacte non reproductible sans stack trace ; le point fragile (recalcul XP/badges post-création de mission) est mis sous try/catch : la mission est toujours enregistrée, plus jamais de 500 pour un bonus d'affichage.
  2. **B-206** — plus aucun lien `/signup` dans le code ; redirection 301 `/signup` → `/inscription` ajoutée pour les favoris/liens externes.
  3. **B-102** — le flash existait mais PIRB ne rendait les flashes QUE sur le dashboard et SANS le type `warning`. Rendu des flashes centralisé dans `pirb/base.html.twig` (tous types, toutes pages), bloc local du dashboard retiré.
  4. **Pages légales** — `LegalController` + `/mentions-legales` + `/politique-confidentialite`, liens footer vitrine. Parties variables en blocs `cms()` (`legal.*`) → adresse/président complétables dans Admin → Contenus. Entrée RGPD-0009 au registre.
  5. **B-201** — bouton "✓ Valider officielle" directement dans la liste `/stats-live` pour toute session COMPLETE (POST + CSRF + confirm, route de promotion existante réutilisée) + lien vers la page Sessions.
  6. **CMS généralisé** — club (histoire 3 paragraphes + photo), contact (gymnase, siège, email), victoires (titre, sous-titre saison, 4 chiffres clés).
  7. **Composer le 5 en mode A/B** — 5 par ÉQUIPE (2×5), chips A/B colorées dans le modal, compteur "A : n/5 · B : n/5" ; sheet Effectif redirigée vers la page de composition (l'effectif d'un match interne EST la composition).
  8. **Tests unitaires** (tests/Unit) : `CategorieCalculatorTest` (règle FFBB, surclassement, bornes — data provider), `SaisonServiceTest` (invariants, pas de saison future, session vs calcul), `RencontreCompositionInterneTest` (exclusivité A/B, activation du mode, helpers). À exécuter en local : `php bin/phpunit`.
  9. **Sécurité PIRB** — audit route par route : 1 vraie faille (IDOR inscription bénévole cross-club) CORRIGÉE (`PirbRencontreController::sInscrire` vérifie désormais le club). Constat + règle permanente au registre (SEC-0009).
  10. **GitHub Action** `.github/workflows/parse-positions-ffbb.yml` — rapatrie les PDFs d'OVH, parse (Tesseract + PyMuPDF sur le runner), renvoie les sidecars JSON, relance l'import Symfony. 3 secrets à configurer (OVH_SSH_HOST/USER/KEY) — remplace le workflow scp manuel.
  11. **Doc** — ADR-0007 (mobile Expo + API monolithe) officialisée dans 08_ADR ; `02_ROADMAP_GLOBALE` réécrite sur l'état réel prod (dérive de mars résolue) ; liste des Voters corrigée dans `01_LIRE_AVANT_TOUT` (conflit n°3 du 04/07).
- Volontairement NON traité (honnêteté de périmètre) :
  - **Chantier B4 (API Platform + JWT)** : requiert Composer + exécution PHP, indisponibles en sandbox — à faire depuis le poste de dev (l'ADR-0007 en fixe le cadre).
  - **Entité Saison dédiée** : grosse migration structurante, mérite une session dédiée avec dry-run BDD.
- Fichiers modifiés/créés : MissionController, ManagerInscriptionController, PirbRencontreController, LegalController (nouveau), templates légal ×2 (nouveaux), pirb/base + dashboard (flashes), vitrine/base (footer), stats_live/index (B-201), club/contact/victoires (CMS), stats-live.html.twig (modal 2×5), tests ×3 (nouveaux), workflow GitHub (nouveau), 01/02/07/08_*.md, 13_CLAUDE_LOG.
- Points de vigilance :
  - Compléter dans Admin → Contenus : adresse du siège, nom du·de la président·e (pages légales).
  - `php bin/phpunit` + `lint:twig` à lancer en local avant push (pas de PHP sandbox).
  - GitHub Action : générer une clé ed25519 dédiée et créer les 3 secrets avant le premier run.

---

### 2026-07-06 (quinquies) — PIRB : nav "Mes tirs", stats filtrées par saison, plus de saison future

- Objectif : (1) bottom nav PIRB : remplacer l'onglet Séances par "Mes tirs" (shot chart), (2) le sélecteur de saison PIRB changeait la session mais la page Stats affichait toujours tout ("toujours 2025-2026"), (3) interdire la sélection d'une saison future (piloté par le calendrier réel).
- Actions réalisées :
  1. **Bottom nav PIRB** : onglet Séances → 🎯 "Mes tirs" (`pirb_shot_chart`). Les séances restent accessibles via le drawer profil (lien déjà présent).
  2. **`JoueurStatsAggregator::statsSaison()`** : le paramètre $saison (TODO historique) est IMPLÉMENTÉ — filtre par PLAGE DE DATES réelles (01/07/N → 30/06/N+1, cohérent avec la bascule juillet de SaisonService) et non par le champ `rencontre.saison` (nullable, non fiable).
  3. **`PirbStatsController::index`** : stats + liste des matchs filtrées sur la SAISON ACTIVE du sélecteur ; équipe résolue par saison (`equipePourSaison`) avec fallback legacy ; empty states "Pas de données pour la saison X, change de saison avec le sélecteur" (stats et matchs). Le libellé de saison du header de page suit enfin le sélecteur (avant : `joueur.equipe.saisonCourante ?? '2025-2026'`, propriété inexistante + hardcode).
  4. **`SaisonService::getSaisonsDisponibles()`** : plus de saison courante+1 — le dropdown s'arrête à la saison EN COURS calculée par la date (demande explicite : pas de 2027-2028 sélectionnable avant le 01/07/2027 ; elle apparaîtra automatiquement ce jour-là).
- Fichiers modifiés : `templates/pirb/base.html.twig`, `templates/pirb/stats.html.twig`, `src/Controller/Pirb/PirbStatsController.php`, `src/Service/Stats/JoueurStatsAggregator.php`, `src/Service/SaisonService.php`
- Décisions : pas d'ADR. Le filtre saison par dates est réutilisable pour les autres pages PIRB (shot chart saison, dashboard présences) — à généraliser au besoin.
- Points de vigilance : le retrait du "+1" retire aussi la préparation ANTICIPÉE de la saison suivante côté Manager (avant le 1er juillet). Si ce besoin revient, réintroduire le +1 uniquement pour les rôles staff.

---

### 2026-07-06 (quater) — Affichage de l'équipe des joueuses par SAISON (Manager + PIRB)

- Objectif : en nouvelle saison non composée, les joueuses ne doivent PLUS apparaître affectées à leur équipe de l'an passé (le lien direct `Joueur.equipe` pointait encore dessus). Par défaut on affiche la saison active ("pas encore d'équipe"), et la saison passée reste consultable.
- Actions réalisées :
  1. **`Joueur::equipePourSaison(saison)`** (+ `aUneEquipeEnSaison()`) — résolution : affectation `JoueurEquipe` principale active de la saison, sinon fallback legacy `Joueur.equipe` si son équipe appartient à la saison demandée, sinon null.
  2. **Manager — liste Joueuses** : colonne Équipe = équipe de la saison active ; sinon badge "À affecter (2026-2027)" avec l'ancienne équipe en tooltip.
  3. **Manager — fiche joueuse** : idem en tête de fiche ("À affecter pour 2026-2027 (ex-U13 Féminine A)").
  4. **PIRB — Mon équipe** : saison active par défaut (SaisonService, plus de calcul local bascule septembre) ; en nouvelle saison non composée → message "Les équipes de la saison X ne sont pas encore composées" + bouton "👀 Revoir mon équipe 2025-2026" (`?saison=`) ; badge saison + "retour à aujourd'hui" quand on consulte une archive ; coéquipières résolues via affectations de la saison FUSIONNÉES avec le legacy (dédupliqué) ; "autres équipes" filtrées sur la même saison.
- Fichiers modifiés : `src/Entity/Sport/Joueur.php`, `src/Controller/Pirb/PirbEquipeController.php`, `templates/manager/joueur/index.html.twig`, `templates/manager/joueur/show.html.twig`, `templates/pirb/equipe.html.twig`
- Décisions : pas d'ADR — le modèle (pivot JoueurEquipe + FK legacy) existait, on corrige les AFFICHAGES pour qu'ils lisent le pivot par saison. `Joueur.equipe` reste la "dernière équipe connue" (rétrocompat).
- Points de vigilance : la consultation d'une saison archivée dépend des lignes `joueur_equipe` de cette saison ; si l'historique pivot est incomplet ET que `Joueur.equipe` a été déplacé sur la nouvelle saison (passage-saison --apply), l'archive peut être partielle. Gamification/affectations XP inchangées (saison sportive).

---

### 2026-07-06 (ter) — Page Équipes alignée sur le sélecteur de saison global

- Objectif : la page Équipes affichait toujours "2025-2026" alors que le sélecteur global (navbar) disait "2026-2027" — dernier vestige des logiques saison dupliquées.
- Actions réalisées :
  1. `EquipeController::getSaisonCourante()` délègue à `SaisonService::getSaisonActive()` (source unique : bascule auto 1er juillet + choix manuel respecté). Impacte : filtre par défaut de la liste + saison pré-remplie à la création d'équipe.
  2. `categorieAgeJoueur()` (page Composer) : l'âge est calculé via `CategorieCalculator::ageReference()` pour la SAISON ACTIVE — en basculant sur 2026-2027, une U10 de 2025-2026 apparaît U11 (catégories qui suivent l'âge, demande explicite de Clavel). Tranches d'affichage club (U14/U16/U17) inchangées.
  3. Le sélecteur de saisons de la page inclut TOUJOURS la saison active même sans équipe créée dedans ; nouvel empty state "Nouvelle saison : on repart de zéro" qui explique la recomposition et pointe la commande `app:passage-saison`.
- Fichiers modifiés : `src/Controller/Manager/EquipeController.php`, `templates/manager/equipe/index.html.twig`
- Décisions : pas d'ADR. Reste UNE logique saison locale assumée : `CotisationJoueur::getSaisonCourante()` (statique, domaine cotisations) — à unifier plus tard.
- Points de vigilance : en début de saison la liste est VIDE par défaut (voulu — archives accessibles via le filtre). Le staff doit lancer `app:passage-saison --to=2026-2027 --apply` (après dry-run) ou créer les équipes à la main.

- Objectif : corriger les retours de Clavel sur pirb.mabb.fr/shot-chart : (1) la carte séances affichait "vs ADVERSAIRE · 0 tirs" quand un match était sélectionné alors qu'elle ne montre que des séances, (2) le chiffre 🏀 des mini-cards ne correspondait pas visuellement aux points bleus, (3) wording robotique (tirets cadratins), (4) ligne à 3 points du SVG à la mauvaise distance.
- Actions réalisées :
  1. **Filtre match découplé** : la sélection d'un match ne filtre plus QUE les points FFBB — la carte séances n'est plus jamais vidée/labellisée "vs X". Son label devient "N zones de tir".
  2. **Cohérence 🏀 = points bleus** : `buildMatchesList()` consomme désormais les MÊMES zones JSON que le rendu terrain (une seule source de vérité, plus de divergence possible) + anti-superposition JS (spirale déterministe) pour les paniers marqués au même endroit qui s'empilaient visuellement. Explication du chiffre ajoutée sous le carrousel + title sur les cards.
  3. **Géométrie SVG corrigée à l'échelle réelle** (374×200 = 28×15 m, 13,357 px/m) sur les DEUX terrains paysage (carte séances + modal Nouvelle séance) : panier 1,575 m → x=21 (avant 16), arc 3 pts 6,75 m → r=90 (avant 100 = 7,5 m !), coins 0,9 m → y=12/188, raquette 5,8×4,9 m, planche à 1,2 m. Le modal avait en plus un arc totalement cassé (départ à x=16). `autoDetectType()` refait en pixels du viewBox (l'ancien mélangeait les échelles x/y → cercle de détection ovale).
  4. **Wording humanisé** : titres sans tirets cadratins ("🎯 Tes séances de shoot", "🏀 Tes paniers en match"), bloc explicatif réécrit (l'avertissement "zones approximatives" était périmé depuis le terrain FFBB fidèle), labels "N paniers".
- Fichiers modifiés : `src/Controller/Pirb/PirbShotChartController.php`, `templates/pirb/shot_chart/index.html.twig`
- Décisions : pas d'ADR (corrections UX/géométrie).
- Points de vigilance : `autoDetectType` ne resservira que pour les NOUVELLES saisies de séances — les zones déjà saisies gardent leur type. Si un jour les proportions du SVG changent, resynchroniser autoDetectType.

---

### 2026-07-06 — Sidecar JSON pour le parse des positions FFBB (OVH sans Tesseract)

- Objectif : le re-parse `app:process-positions-tirs` échoue sur OVH mutualisé (Python sans PyMuPDF, et surtout Tesseract non installable — or les pages des PDF e-Marque sont des IMAGES rasterisées 1260×1260, l'OCR est obligatoire pour lire les noms des joueuses).
- Actions réalisées : `FfbbPositionTirParser::parseEtPersister()` accepte désormais un **sidecar `{pdf}.json`** (sortie brute de `bin/ffbb_parse_positions.py`) posé à côté du PDF : s'il existe, il est utilisé directement — aucun Python requis sur le serveur. Workflow : télécharger les PDFs d'OVH → parser sur une machine avec Python+Tesseract (PC local ou sandbox IA, pipeline validé sur le PDF Tergnier : noms OCR + positions OK) → renvoyer les JSON sur OVH → relancer la commande.
- Fichiers modifiés : `src/Service/Ffbb/FfbbPositionTirParser.php`, `instruction/13_CLAUDE_LOG.md`
- Décisions : pas d'ADR (mécanisme de contournement infra, logique métier inchangée — le matching joueuse reste 100 % côté PHP).
- Points de vigilance : les sidecars doivent être régénérés si de nouveaux PDFs sont uploadés (nouveau match) ; à terme, évaluer un VPS ou une GitHub Action qui parse et pousse les JSON automatiquement.

---

### 2026-07-05 (quater) — Passage de saison automatique (catégories) + CMS vitrine bloc par bloc

- Objectif : (1) répondre à la question "les catégories des joueuses sont-elles recalculées automatiquement au changement de saison ?" (réponse : NON, tout était manuel) et construire l'automatisation ; (2) démarrer le CMS vitrine : l'admin modifie textes, chiffres et photos du site sans toucher au code.
- Actions réalisées :
  1. **`CategorieCalculator`** (`src/Service/Sport/`) — règle FFBB : âge de référence = année de fin de saison − année de naissance → catégorie (U7/U9/U11/U13/U15/U18/Senior). Helpers `estCompatible()` (âge ≤ borne équipe, informatif jamais bloquant) et `estSurclassement()`.
  2. **Commande `app:passage-saison --to=YYYY-YYYY [--apply]`** — duplique les équipes actives vers la nouvelle saison (idempotent) puis ré-affecte chaque joueuse active par catégorie CALCULÉE : match exact d'abord, sinon plus proche catégorie supérieure compatible. Crée `JoueurEquipe` principale + met à jour `Joueur.equipe`. DRY-RUN par défaut ; rapport des cas à arbitrer (sans date de naissance, sans équipe compatible, plusieurs équipes A/B).
  3. **CMS V2 — blocs de contenu** : entité `BlocContenu` (clé unique `page.section.champ`, types texte/long/image, `valeur` NULL = défaut du template conservé), migration `Version20260705120000` (table `bloc_contenu`), repository.
  4. **`CmsExtension`** — fonctions Twig `cms(cle, defaut, type)` et `cms_img(cle, defaut)` : renvoient la valeur admin sinon le défaut ; AUTO-ENREGISTREMENT des clés au premier rendu (zéro fixture) ; try/catch intégral → la vitrine ne casse jamais à cause du CMS (table absente = défauts affichés).
  5. **`AdminContenusController`** (`/admin/contenus`, ROLE_SUPER_ADMIN, même modèle que l'admin existant) — liste groupée par page, édition inline (input/textarea/upload image vers `uploads/cms/`), bouton "Revenir à l'origine" (valeur → NULL). Template `admin/contenus/index.html.twig`, lien "Contenus" dans la nav admin vitrine.
  6. **Premier câblage accueil** : hero (badge, titre 2 lignes, description, photo) + section "Un club engagé" (titres, 2 paragraphes, photo) passés en `cms()`/`cms_img()`.
- Fichiers modifiés/créés :
  - `src/Service/Sport/CategorieCalculator.php` (nouveau), `src/Command/PassageSaisonCommand.php` (nouveau)
  - `src/Entity/Vitrine/BlocContenu.php`, `src/Repository/Vitrine/BlocContenuRepository.php`, `migrations/Version20260705120000.php`, `src/Twig/CmsExtension.php`, `src/Controller/Admin/AdminContenusController.php`, `templates/admin/contenus/index.html.twig` (nouveaux)
  - `templates/vitrine/accueil/index.html.twig`, `templates/vitrine/base.html.twig`
- Décisions (ADR si applicable) : pas d'ADR — extensions du modèle CMS existant (PageContenu/Article/Media) et service+commande sans impact structurel. Choix notable documenté en code : `cms()` auto-enregistre les clés au premier rendu (writes-on-read assumé, table minuscule, jamais bloquant).
- Points de vigilance / risques :
  - **Migration à jouer** : `doctrine:migrations:migrate` (3 en attente : composition_interne, ffbb_x/y, bloc_contenu).
  - **Passage de saison** : lancer d'abord SANS --apply et lire le rapport ; la commande n'affecte PAS les joueuses aux équipes de niveau (A vs B) — arbitrage humain listé.
  - Le câblage cms() ne couvre que l'accueil (hero + club engagé) — généraliser page par page (club, contact, victoires…).
  - `php -l`/`lint:twig` à exécuter en local (pas de PHP sandbox).

- Objectif : (1) réparer la page 500 au clic "Créer bilan" sur une joueuse, (2) supprimer le décalage des points bleus du shot chart PIRB via un terrain identique au doc FFBB (proposition validée par Clavel, doc e-Marque fourni en exemple), (3) automatiser le passage à la saison suivante sur les deux sites.
- Actions réalisées :
  1. **FIX Créer bilan** — `ManagerBilanController::nouveau()` appelait `$joueur->getLicenceNumero()` qui N'EXISTE PAS sur `Joueur` (méthode réelle : `getLicence()`) → fatal error. Corrigé.
  2. **Shot chart FFBB précis (ADR-0009)** — cause du décalage : transformation affine approximative (`zoneX = normY*0.46+0.04`) + arrondi 0-100 vers un terrain paysage aux proportions ≠ du doc FFBB. Correctif : colonnes `tir_ffbb.ffbb_x/ffbb_y` (pour-mille, coordonnées BRUTES du repère PDF — migration `Version20260705110000`), parser rempli les bruts, nouveau terrain SVG "FFBB officiel" dans PIRB (portrait 15/14, cotes FIBA exactes), points placés sans transformation. Fallback inverse pour les lignes non re-parsées. Shot map paysage désormais réservée aux séances d'entraînement (sources séparées). Sélecteur de match + badges filtrent les deux terrains.
  3. **Saisons dynamiques** — `SaisonService` refondu : plus AUCUNE saison en dur (avant : liste const + défaut '2026-2027' hardcodé). Saisons générées de 2023-2024 à courante+1 ; saison active = choix session sinon saison CALCULÉE avec bascule au **1er juillet** (début administratif FFBB — préserve le comportement du défaut bumpé manuellement en juillet). Passage à la saison d'après = automatique, Manager + PIRB (service partagé), zéro déploiement annuel.
  4. **Déduplication saisonCourante()** — ManagerBilanController, ManagerCoachDashboardController, ManagerStaffController (était static), ProfilController délèguent désormais à `SaisonService::getSaisonActive()` (respect du sélecteur global). **Choix documenté** : `PirbEquipeController` (affectations) et la Gamification (XP/badges) GARDENT la saison sportive à bascule septembre — sinon en juillet/août les joueuses n'auraient plus d'équipe ni d'XP affichés.
- Fichiers modifiés :
  - `src/Controller/Manager/ManagerBilanController.php` (fix + saison service)
  - `src/Entity/Sport/TirFfbb.php`, `migrations/Version20260705110000.php` (nouveau), `src/Service/Ffbb/FfbbPositionTirParser.php`
  - `src/Controller/Pirb/PirbShotChartController.php`, `templates/pirb/shot_chart/index.html.twig`
  - `src/Service/SaisonService.php` (refonte), `src/Controller/Manager/{ManagerCoachDashboardController,ManagerStaffController,ProfilController}.php`
  - `instruction/08_ADR.md` (ADR-0009), `instruction/13_CLAUDE_LOG.md`
- Décisions (ADR si applicable) : **ADR-0009** (coordonnées brutes + terrain FFBB fidèle). Saisons : pas d'entité `Saison` créée (chantier backlog inchangé) — refonte du service uniquement, réversible.
- Points de vigilance / risques :
  - **Migrations à exécuter** : `php bin/console doctrine:migrations:migrate` (2 nouvelles : composition_interne + ffbb_x/y).
  - **Re-parse recommandé** pour la précision maximale : `php bin/console app:process-positions-tirs --saison=2025-2026` (les anciennes lignes utilisent le fallback inversé en attendant).
  - Bascule saison au 1er juillet : les bilans/staff/profil créés en juillet-août sont tagués sur la NOUVELLE saison. Si indésirable pour un cas précis, l'utilisateur peut re-sélectionner l'ancienne saison dans le menu (le tag suit le sélecteur).
  - PHP non exécutable dans la sandbox : `php -l` + `lint:twig` + test manuel à faire depuis le terminal local.

---

### 2026-07-05 (bis) — Refonte responsive Manager + fix scroll horizontal PIRB

- Objectif : (1) sortir le sélecteur de saison de la topbar Manager (il cassait le responsive), (2) éliminer le scroll horizontal DE PAGE en mobile/iPad tout en préservant les carrousels voulus (ex: sélecteur de match du shot chart PIRB), (3) durcir le responsive globalement sans retoucher ~20 templates un par un.
- Actions réalisées :
  1. **Menu utilisateur `<details>` natif (Manager topbar)** — nouveau dropdown pure CSS (AUCUNE dépendance Bootstrap JS, qui n'est d'ailleurs PAS chargé dans Manager) regroupant : profil, **sélecteur de saison** (déplacé ici depuis la topbar inline), déconnexion. Chip saison visible dans le bouton. Prénom masqué < 480px.
  2. **Navbar élastique** — `.nav-modules` : `flex:1 + min-width:0 + overflow-x:auto` à TOUTES les largeurs (11 modules débordaient déjà en desktop 1024-1300px), liens `white-space:nowrap`.
  3. **Barrière document Manager** — `html, body { overflow-x: clip }` (clip et non hidden : ne casse pas `position:sticky`). Médias `max-width:100%` dans `main`.
  4. **Tables débordantes** — cause racine identifiée : ~18 templates posent `.table-mabb` (min-width:600px) DIRECTEMENT dans `.card-mabb` sans `.card-body`, or le scroll interne n'était géré QUE sur card-body → page entière scrollait. Correctif centralisé via `:has()` : toute card contenant directement une table devient son propre conteneur de scroll (≤1023px).
  5. **Grids inline non responsives** — correctifs centralisés par sélecteur d'attribut `[style*=...]` : auto-fill/auto-fit minmax(200-360px) → 1 colonne <400px, 2 colonnes en iPad portrait pour les très larges ; grids fixes `1fr 1fr`/`1fr 2fr`/`2fr 1fr` empilées ≤600px ; grids label/valeur `140px|170px 1fr` empilées <480px.
  6. **Planning hebdo** — grille 7 jours illisible à 360px : wrapper `.planning-scroll` (scroll horizontal DANS le conteneur) + `min-width:700px` sur la grille.
  7. **Staff rencontre** — `.role-row` (180px|1fr|auto) empilée ≤600px (label pleine largeur).
  8. **PIRB fix scroll page** — `.pirb-content` avait `overflow-y:auto` SANS overflow-x → coercé en auto : le panneau entier scrollait latéralement au moindre débordement. Correctif : `overflow-x:hidden` sur le panneau ; les carrousels internes (match-selector du shot chart) gardent leur propre scroll. Médias bornés à 100%.
  9. **Alertes réparées** — les `.btn-close` / `data-bs-dismiss="alert"` étaient MORTS partout dans Manager (Bootstrap JS absent). JS vanilla délégué ajouté dans base : clic → suppression de l'alerte. + fermeture du menu user au clic extérieur/Échap.
- Fichiers modifiés :
  - `templates/manager/base.html.twig` (topbar + user-menu + blindage responsive + JS léger)
  - `templates/pirb/base.html.twig` (overflow-x du panneau contenu)
  - `templates/manager/planning/index.html.twig` (wrapper scroll grille)
  - `templates/manager/rencontre/staff.html.twig` (role-row responsive)
- Décisions (ADR si applicable) : aucune ADR (UI uniquement, pas de modèle de données). Choix technique notable : correctifs responsive CENTRALISÉS dans base.html.twig via `:has()` et sélecteurs `[style*=]` plutôt que 20 templates modifiés — moins de diff, une seule source. `:has()` requiert Chrome/Edge 105+, Safari 15.4+, Firefox 121+ (OK pour l'usage club 2026).
- Points de vigilance / risques :
  - Tester visuellement : topbar desktop/iPad/mobile, menu saison (changement de saison = POST → reload), planning mobile, page staff mobile, shot chart PIRB (carrousel doit glisser, page ne doit plus bouger).
  - Les sélecteurs `[style*="minmax(...)"]` sont sensibles à l'espacement exact des styles inline — variantes avec/sans espace couvertes, mais un nouveau template avec un espacement exotique ne serait pas attrapé.
  - `overflow-x:hidden` sur `.pirb-content` CLIPPE tout enfant plus large sans scroll propre — si une future page PIRB a une vraie table large, lui donner un wrapper `overflow-x:auto` dédié.

---

### 2026-07-05 — Stats Live V2.3 : match interne à deux équipes (A/B)

- Objectif : Permettre de statter DEUX équipes composées de l'effectif du club sur le même écran live (entraînement interne / amical intra-club), avec conservation des stats des deux côtés, rattachement au type de match et moyennes de saison non gonflées par les matchs internes.
- Constat préalable (Étape 0) : le type de match (`Rencontre.typeRencontre` — OFFICIEL/AMICAL/ENTRAINEMENT_INTERNE/EXHIBITION) existait DÉJÀ (B23/V2.2), ainsi que le mode stats et les joueuses éphémères. Manquaient : la répartition A/B de l'effectif, l'écran 2 colonnes, et surtout le filtrage des moyennes de saison (bug : `statsSaison()` agrégeait TOUT, internes compris). Aucun conflit doc → pas de STOP.
- Actions réalisées :
  1. **Entité `Rencontre`** : colonne JSON nullable `composition_interne` + helpers (`isInterneDeuxEquipes()`, `setCompositionInterne()` avec exclusivité A/B garantie, `coteJoueur()`, `estDansComposition()`, `peutComposerDeuxEquipes()` — réservé ENTRAINEMENT_INTERNE + AMICAL).
  2. **Migration `Version20260705100000`** : `ALTER TABLE rencontre ADD composition_interne JSON DEFAULT NULL`. NULL = comportement historique, zéro donnée migrée, zéro régression.
  3. **`JoueurRepository::findEffectifClubPourComposition()`** : joueuses actives non temporaires du club courant, toutes équipes (match interne multi-catégorie possible), tri équipe/nom.
  4. **`StatsLiveController`** : routes GET/POST `/rencontres/{id}/composition-interne` (page de répartition + save avec CSRF, whitelist club, validation type) ; `index()` charge les joueuses depuis la composition en mode A/B ; anti-IDOR adapté (`createAction` et `entrerSurTerrain` : joueuse ∈ composition A∪B).
  5. **Template `composition_interne.html.twig`** (nouveau) : répartition — / A / B par boutons radio (exclusivité native), noms d'équipes personnalisables, compteurs, revalidation serveur systématique.
  6. **`stats-live.html.twig`** : sidebar joueuses coupée en 2 verticalement (A gauche/bleu, B droite/rouge) via macro `carte_joueuse` (markup identique → JS existant inchangé) ; scores du header calculés par colonne (A vs B) ; carte adversaire et boutons score adverse manuels masqués en mode A/B ; garde null sur `cardAdversaire`.
  7. **`JoueurStatsAggregator::statsSaison()`** : filtre par type de rencontre, défaut = OFFICIEL uniquement (correction du gonflage des moyennes — changement de comportement assumé et documenté).
  8. **`rencontre/show.html.twig`** : bouton "Composer A/B" pour les types éligibles.
- Fichiers modifiés :
  - `src/Entity/Sport/Rencontre.php`
  - `src/Repository/Sport/JoueurRepository.php`
  - `src/Controller/Manager/StatsLiveController.php`
  - `src/Service/Stats/JoueurStatsAggregator.php`
  - `migrations/Version20260705100000.php` (nouveau)
  - `templates/manager/stats_live/composition_interne.html.twig` (nouveau)
  - `templates/manager/evaluation/stats-live.html.twig`
  - `templates/manager/rencontre/show.html.twig`
  - `instruction/08_ADR.md` (ADR-0008 + note réservation ADR-0007), `instruction/06_REGISTRE_TECHNIQUE.md` (RT-0010), `instruction/02_ROADMAP_GLOBALE.md`, `instruction/13_CLAUDE_LOG.md`
- Décisions (ADR si applicable) : **ADR-0008** — composition A/B en JSON sur `rencontre` (précédent `joueursNonConvoques`), type de match rattaché aux stats par JOINTURE (pas de dénormalisation). ADR-0007 laissé réservé au brouillon PIRB Mobile (20_ANALYSE du 04/07).
- Points de vigilance / risques :
  - **Migration à exécuter** : `php bin/console doctrine:migrations:migrate` (local puis prod).
  - **Changement de comportement fiche joueuse PIRB** : les moyennes de saison n'incluent plus que les matchs OFFICIELS. Les anciens amicaux mal typés "OFFICIEL" (défaut B23) continuent de compter — retyper à la main si besoin.
  - Modal "Composer le 5" et sheet "Effectif" non adaptés au mode A/B (fonctionnels mais pensés mono-équipe) — les badges ON/OFF individuels couvrent le besoin.
  - `ShotChartCalculator` agrège encore tous types de rencontres (choix à acter, cf. RT-0010).

---

### 2026-07-04 — Analyse décisionnelle architecture PIRB Mobile (analyse seule, zéro code)

- Objectif : Analyser le projet existant (code + instruction/) et trancher l'architecture de l'app mobile PIRB (backend, stack mobile, mode vision, priorisation). Livrable = analyse décisionnelle, aucun code applicatif.
- Actions réalisées :
  1. Scan complet du code : composer.json (Symfony 7.4/PHP 8.3, **API Platform et LexikJWT ABSENTS**, `src/Controller/Api/` vide, firewall `api` en stub jwt commenté), Voters réels (ClubVoter/NoteFraisVoter/TresorerieVoter), TenantResolver, 61 migrations, Gamification/Feed déjà codés serveur, shot chart PIRB V2.3 en prod, google/cloud-vision = OCR PDFs FFBB uniquement.
  2. Lecture intégrale de instruction/ (gouvernance, roadmaps, ADR 0001-0006, backlog 26/06, audit features 29/06, CHANTIERS 10/06) + des 3 documents fournis (Vision Produit V5, CDC fonctionnel écosystème, rapport PIRB Scouting).
  3. Rédaction de l'analyse : reco = API Platform+LexikJWT sur le monolithe (chantier B4, pas de backend Node), client Expo/React Native TS en dev builds Android d'abord, vision auto HORS périmètre pré-soutenance (fallback mode practice manuel + spike pose estimation borné), ordre = P0 web (tests) → B4 API → mobile socle M1-M3.
- Fichiers modifiés :
  - `instruction/20_ANALYSE_ARCHI_PIRB_MOBILE_2026-07-04.md` (nouveau — livrable de l'analyse, contient un brouillon ADR-0007)
  - `instruction/13_CLAUDE_LOG.md` (cette entrée)
  - Roadmaps NON modifiées : aucune décision actée tant que Clavel n'a pas validé (analyse seulement).
- Décisions (ADR si applicable) : aucune actée. Brouillon **ADR-0007** fourni dans le livrable, à coller dans `08_ADR.md` après validation.
- Points de vigilance / risques :
  - **Conflit signalé** : le prompt référençait `Instruction/03_CLAUDE_LOG.md` (inexistant — 03 = ROADMAP_V1) → log écrit ici (13). Le prompt supposait aussi une API Platform « existante » : faux dans le code.
  - Dérives doc à corriger : `01_LIRE_AVANT_TOUT.md` (noms de Voters obsolètes), `02_ROADMAP_GLOBALE.md` (état mars 2026 obsolète vs prod), dossier doublon `instructions/` (contient seulement le CDC PDF).
  - Le mode vision type HomeCourt n'apparaît dans aucun document acté : si retenu, exige entrée ADR + mise à jour roadmap V3.

### 2026-03-26 (session 41) — Sprint 1 Sécurité : firewalls + access_control + login controllers

- Objectif : Activer la sécurité Symfony sur les 3 espaces (admin mabb.fr, manager.mabb.fr, pirb.mabb.fr)
- Actions réalisées :
  1. **security.yaml** reécrit complet :
     - Firewall `vitrine_admin` ajouté (pattern `^/admin`, host mabb.fr, session isolée du firewall vitrine)
     - `role_hierarchy` enrichi : `ROLE_SUPER_ADMIN > ROLE_DIRIGEANT > ROLE_COACH > ROLE_USER`
     - `access_control` activé : `/admin` → ROLE_SUPER_ADMIN, `manager.*` → ROLE_USER, `pirb.*` → ROLE_USER, `/compte` → ROLE_USER, reste vitrine → public
     - JWT conservé commenté (Phase 2+)
  2. **AdminLoginController** créé (`/admin/login` + `/admin/deconnexion`)
  3. **ManagerLoginController** créé (`/login` + `/` dashboard stub + `/deconnexion`)
  4. **PirbLoginController** créé (`/login` + `/` dashboard stub + `/deconnexion`)
  5. **templates** : `admin/login.html.twig`, `manager/login.html.twig`, `manager/dashboard.html.twig` (stub), `pirb/login.html.twig`, `pirb/dashboard.html.twig` (stub)
  6. **CreateAdminCommand** : `php bin/console app:create-admin --email=x --password=x`
- Fichiers créés/modifiés :
  - `config/packages/security.yaml` (modifié)
  - `src/Controller/Admin/AdminLoginController.php` (nouveau)
  - `src/Controller/Manager/ManagerLoginController.php` (nouveau)
  - `src/Controller/Pirb/PirbLoginController.php` (nouveau)
  - `templates/admin/login.html.twig` (nouveau)
  - `templates/manager/login.html.twig` (nouveau)
  - `templates/manager/dashboard.html.twig` (nouveau, stub)
  - `templates/pirb/login.html.twig` (nouveau)
  - `templates/pirb/dashboard.html.twig` (nouveau, stub)
  - `src/Command/CreateAdminCommand.php` (nouveau)
- Commit : `cd101c6`
- Points de vigilance :
  - **IMPORTANT** : Les admin controllers existants utilisent `denyAccessUnlessGranted('ROLE_SUPER_ADMIN')`. L'access_control est une protection supplémentaire (firewall = première barrière, denyAccess = deuxième). Double sécurité intentionnelle.
  - La commande `app:create-admin` doit être lancée une fois en prod pour bootstrapper le premier admin : `php bin/console app:create-admin --env=prod --email=admin@mabb.fr --password=XXXXX`
  - Push git + déploiement OVH à faire depuis terminal : `git push origin main` puis SSH `git pull && php bin/console cache:clear --env=prod`
  - Manager et Pirb : dashboards sont des STUBS, seront développés en Phase 2 (manager) et Phase 4 (pirb)

---

### 2026-02-12 (session 1)
- Objectif : Initialiser la gouvernance documentaire
- Actions réalisées : création des roadmaps + registres + templates
- Fichiers modifiés : instruction/00_..., 02_..., 06_...
- Décisions : ADR-0001 créée (monolithe modulaire)
- Points de vigilance : éviter dérive V1 vers messagerie (V2)

---

### 2026-02-12 (session 2) — Audit & mise à jour complète de la gouvernance
- Objectif : Auditer les 15 fichiers de gouvernance + 2 CDCs PDF, détecter incohérences/manques/redondances, appliquer les corrections.
- Actions réalisées :
  - Lecture intégrale de tous les fichiers (15 markdown + 2 PDFs)
  - Vérification du composer.json (confirme Symfony 7.4, pas d'API Platform ni JWT installés)
  - **Conflits détectés et documentés** :
    - CDC mentionne Symfony 6.4 LTS → projet utilise 7.4 → ADR-0005 créé
    - CDC Vitrine recommande Node.js/React → supersédé par ADR-0001/0004 → documenté
  - **Incohérences corrigées** (7) : version Symfony, stack CDC Vitrine, phases concurrentes non documentées, API Platform/JWT pas encore installés, référence manquante à 07_REGISTRE_SECURITE_RGPD dans "Avant de coder"
  - **Manques comblés** (13) :
    - ADR-0005 (Symfony 7.4)
    - RT-0006 (soft delete), RT-0007 (hachage mdp), RT-0008 (API Platform CORS)
    - RGPD-0006 (consentement inscription), RGPD-0007 (droit export), RGPD-0008 (droit effacement)
    - 09_BACKLOG.md peuplé (67 items répartis sur 6 phases)
    - 10_DEFINITION_OF_DONE.md peuplé (critères issus du CDC section 11)
    - 11_CHECKLIST_RELEASE.md peuplé
    - 12_TEMPLATE_PROMPTS_IA.md peuplé (4 templates)
  - **Redondances traitées** (4) : ajout de cross-références au lieu de duplication (03→02, 01→multi-tenant)
  - **Rappel multi-tenant** ajouté dans roadmaps V2 et V3
  - **Inventaire complet** ajouté dans 00_GOUVERNANCE_DOC.md
- Fichiers modifiés :
  - instruction/00_GOUVERNANCE_DOC.md (inventaire fichiers, règle CDCs, date)
  - instruction/01_LIRE_AVANT_TOUT.md (note CDCs supersédés, référence 07)
  - instruction/02_ROADMAP_GLOBALE.md (date, cross-ref, clarification concurrence phases, rappel multi-tenant)
  - instruction/03_ROADMAP_V1.md (date, cross-ref vers 02, contraintes transverses)
  - instruction/04_ROADMAP_V2.md (date, rappel multi-tenant/RBAC/RGPD)
  - instruction/05_ROADMAP_V3.md (date, rappel contraintes héritées)
  - instruction/06_REGISTRE_TECHNIQUE.md (RT-0006, RT-0007, RT-0008)
  - instruction/07_REGISTRE_SECURITE_RGPD.md (RGPD-0006, RGPD-0007, RGPD-0008)
  - instruction/08_ADR.md (ADR-0005, note sur ADR-0004)
  - instruction/09_BACKLOG.md (peuplé intégralement)
  - instruction/10_DEFINITION_OF_DONE.md (peuplé intégralement)
  - instruction/11_CHECKLIST_RELEASE.md (peuplé intégralement)
  - instruction/12_TEMPLATE_PROMPTS_IA.md (peuplé intégralement)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : ADR-0005 créée (Symfony 7.4 au lieu de 6.4 LTS)
- Points de vigilance :
  - API Platform et LexikJWTAuthenticationBundle doivent être installés respectivement en Phase 3 et Phase 1
  - Le CDC Vitrine (Node/React) est obsolète sur le plan technique mais reste valide fonctionnellement
  - 4 fichiers étaient vides (09, 10, 11, 12) → maintenant peuplés, à maintenir activement

---

### 2026-02-13 (session 3) — Correction structurée du backlog
- Objectif : Corriger 09_BACKLOG.md (rôles multi-tenant, RGPD inscription, blocage composer, règle club_id, API auth)
- Actions réalisées :
  - Ajout BL-0000 (P0 bloquant) : résolution blocage installation dépendances composer SSL + vendor + php.ini
  - Correction BL-0004 : rôles liés à ClubUser (par club) au lieu de relation M:N globale User-Role
  - Correction BL-0009 : opt-in explicite bénévole + consentement CGU/RGPD non pré-coché (conformité RGPD)
  - Ajout section "Règle transverse obligatoire" après Format (club_id + ClubScopeVoter)
  - Ajout BL-0014 (P1) : endpoints API auth + contexte club sans API Platform (Controller Symfony natif)
  - Date mise à jour → 2026-02-13
- Fichiers modifiés :
  - instruction/09_BACKLOG.md (5 modifications)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune ADR nécessaire (corrections de cohérence, pas de décision structurante nouvelle)
- Points de vigilance :
  - BL-0000 doit être résolu avant tout autre item de Phase 1
  - La gestion des rôles par club (BL-0004) impactera le modèle ClubUser — à aligner avec shemas/dictionnaire_db.md lors de l'implémentation

---

### 2026-02-13 (session 4) — Passage rôles enterprise + doc
- Objectif : Passer la gestion des rôles en modèle "enterprise" (Role + ClubUserRole) et mettre à jour la documentation correspondante.
- Actions réalisées :
  - **09_BACKLOG.md** :
    - BL-0003 reformulé : ClubUser simplifié (user_id, club_id, statut, created_at, deleted_at) — les rôles ne sont plus portés par ClubUser
    - BL-0004 reformulé : création entité Role + pivot ClubUserRole (M:N ClubUser-Role)
    - BL-0004b ajouté (P0) : création entité ClubUserRole (club_user_id, role_id, created_at, created_by) + contraintes uniques
    - BL-0011 mis à jour : migration inclut désormais ClubUserRole en plus de User, Club, ClubUser, Role
    - Ajout contraintes d'unicité DB sous "Règle transverse obligatoire"
  - **08_ADR.md** : ADR-0006 créée — "Rôles par club via Role + ClubUserRole (enterprise)"
  - **06_REGISTRE_TECHNIQUE.md** : RT-0009 ajouté — "Gestion rôles par club (pivot ClubUserRole)"
- Fichiers modifiés :
  - instruction/09_BACKLOG.md (BL-0003, BL-0004, BL-0004b, BL-0011, contraintes unicité)
  - instruction/08_ADR.md (ADR-0006)
  - instruction/06_REGISTRE_TECHNIQUE.md (RT-0009)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : ADR-0006 créée (rôles enterprise vs JSON — choix du pivot auditable)
- Points de vigilance :
  - Implémenter TenantContext + RoleResolver en Phase 1 pour résoudre les rôles selon le club courant (RT-0009)
  - La role_hierarchy de security.yaml reste valide pour la hiérarchie globale, mais les rôles effectifs viennent de ClubUserRole
  - Aligner shemas/dictionnaire_db.md avec le nouveau modèle lors de la création des entités

---

### 2026-03-12 (session 5) — Vitrine compte + suivi CDC
- Objectif : Créer les pages Connexion/Inscription + fichier de suivi CDC
- Actions réalisées :
  - Création `src/Controller/Vitrine/CompteController.php` (routes `vitrine_compte_se_connecter` + `vitrine_compte_s_inscrire`)
  - Création `templates/vitrine/compte/se_connecter.html.twig` (formulaire connexion, design MABB)
  - Création `templates/vitrine/compte/s_inscrire.html.twig` (formulaire inscription + jauge force mot de passe)
  - Mise à jour `templates/vitrine/base.html.twig` : remplacement bouton "S'inscrire → contact" par boutons "Connexion" + "S'inscrire" pointant vers CompteController
  - Nettoyage `templates/vitrine/accueil/news.html.twig` : suppression `<li>` mal placé avec liens connexion/inscription
  - Création `instruction/14_SUIVI_CDC_MARS.md` : tableau de suivi complet fait/en cours/à faire (70 items, ~24% d'avancement)
  - Mise à jour `instruction/00_GOUVERNANCE_DOC.md` : ajout 14 dans l'inventaire
- Fichiers modifiés :
  - src/Controller/Vitrine/CompteController.php (nouveau)
  - templates/vitrine/compte/se_connecter.html.twig (nouveau)
  - templates/vitrine/compte/s_inscrire.html.twig (nouveau)
  - templates/vitrine/base.html.twig (navbar)
  - templates/vitrine/accueil/news.html.twig (nettoyage)
  - instruction/14_SUIVI_CDC_MARS.md (nouveau)
  - instruction/00_GOUVERNANCE_DOC.md (inventaire)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune ADR nécessaire
- Points de vigilance :
  - Les pages Connexion/Inscription sont des templates HTML uniquement — le SecurityBundle Symfony (security.yaml, UserAuthenticator, firewalls) reste à implémenter (BL-0008 à BL-0013)
  - BL-0000 (blocage composer SSL) reste le vrai bloquant pour démarrer Phase 1

---

### 2026-03-13 (session 6) — Mise à jour documentation post-session 5
- Objectif : Synchroniser toute la documentation MD avec l'état réel du code
- Actions réalisées :
  - `instruction/arborescence.md` : ajout `CompteController.php`, dossier `templates/vitrine/compte/` avec les 2 templates, ajout `14_SUIVI_CDC_MARS.md`
  - `instruction/02_ROADMAP_GLOBALE.md` : date + détail Phase 5 mis à jour (pages compte ajoutées)
  - `instruction/14_SUIVI_CDC_MARS.md` : date + notes affinées sur les templates connexion/inscription (formulaires HTML + CSRF + consentement RGPD présents)
- Fichiers modifiés :
  - instruction/arborescence.md
  - instruction/02_ROADMAP_GLOBALE.md
  - instruction/14_SUIVI_CDC_MARS.md
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune
- Points de vigilance :
  - Les templates `se_connecter` et `s_inscrire` ont été simplifiés (HTML structuré sans Bootstrap élaboré) — à styler avec le design system MABB quand le SecurityBundle sera branché

---

### 2026-03-13 (session 7) — Correction structure Repository
- Objectif : Déplacer les Repository mal placés dans src/Entity/Repository/ vers src/Repository/Core/
- Actions réalisées :
  - Déplacement `ClubRepository.php` : `src/Entity/Repository/` → `src/Repository/Core/`
  - Déplacement `UserRepository.php` : `src/Entity/Repository/` → `src/Repository/Core/`
  - Déplacement `UserClubRoleRepository.php` : `src/Entity/Repository/` → `src/Repository/Core/`
  - Suppression du dossier vide `src/Entity/Repository/`
  - Vérification : les 3 namespaces `App\Repository\Core` étaient déjà corrects — aucune modification de contenu
  - `php bin/console cache:clear` exécuté avec succès (env dev)
- Fichiers modifiés :
  - src/Repository/Core/ClubRepository.php (déplacé)
  - src/Repository/Core/UserRepository.php (déplacé)
  - src/Repository/Core/UserClubRoleRepository.php (déplacé)
  - src/Entity/Repository/ (dossier supprimé)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune ADR nécessaire (correction structurelle, pas de décision d'architecture)
- Points de vigilance :
  - Vérifier que les entités Core (User, Club, UserClubRole) référencent bien ces repositories via l'attribut `#[ORM\Entity(repositoryClass: ...)]` lors de leur création (BL-0001 à BL-0004b)
  - ✅ Confirmé lors de la session 8 : les 3 entités référencent bien leurs repositories

---

### 2026-03-13 (session 8) — Synchronisation documentation avec état réel du code
- Objectif : Mettre à jour toute la doc MD pour refléter les fichiers existants non documentés
- Constat : audit du répertoire `src/` révèle que les entités Core, repositories et sécurité ont été créés sans mise à jour de la doc
- Fichiers existants découverts non documentés :
  - `src/Entity/Core/User.php` (UserInterface, RGPD consent, lifecycle callbacks)
  - `src/Entity/Core/Club.php` (slug unique, isActive, lifecycle callbacks)
  - `src/Entity/Core/UserClubRole.php` (pivot User<->Club<->Rôle, UNIQUE user_id+club_id+role)
  - `src/Repository/Core/UserRepository.php` (upgradePassword, findActiveByEmail)
  - `src/Repository/Core/ClubRepository.php` (findActiveBySlug)
  - `src/Repository/Core/UserClubRoleRepository.php` (findActiveRolesForUserInClub, hasRole)
  - `src/Security/Voter/ClubVoter.php` (CLUB_MEMBER/COACH/ADMIN/STAFF/JOUEUR)
  - `src/Security/Tenant/TenantResolver.php` (résolution club actif en session, multi-clubs)
  - `src/DataFixtures/AppFixtures.php`
- Actions réalisées :
  - `instruction/arborescence.md` : liste détaillée de tous les fichiers src/ existants avec descriptions
  - `instruction/14_SUIVI_CDC_MARS.md` : BL-0001 à BL-0005 passés en ✅, compteurs mis à jour (~33%)
  - `instruction/02_ROADMAP_GLOBALE.md` : Phase 1 détail mis à jour
  - `instruction/13_CLAUDE_LOG.md` : cette entrée
- Fichiers modifiés :
  - instruction/arborescence.md
  - instruction/14_SUIVI_CDC_MARS.md
  - instruction/02_ROADMAP_GLOBALE.md
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune — audit documentaire uniquement
- Points de vigilance :
  - BL-0011 (migration Doctrine) est débloqué : les 3 entités Core sont prêtes → prochaine priorité après BL-0000
  - L'architecture a fusionné ClubUser + Role + ClubUserRole en une seule entité `UserClubRole` — cohérent avec ADR-0006 mais différent du backlog initial (BL-0003/0004/0004b)

---

### 2026-03-13 (session 9) — Correction CSS checkbox pages compte
- Objectif : Remplacer les règles `.form-check-input` / `.form-check-label` dans les deux pages compte
- Actions réalisées :
  - `templates/vitrine/compte/se_connecter.html.twig` : remplacement des 2 règles `.form-check-*` par le bloc complet (width, height, border, states hover/checked/focus, cursor, label)
  - `templates/vitrine/compte/s_inscrire.html.twig` : idem — remplacement des 3 règles `.form-check-*` par le même bloc complet
  - Aucune modification HTML, Twig ni routes
- Fichiers modifiés :
  - templates/vitrine/compte/se_connecter.html.twig (bloc style uniquement)
  - templates/vitrine/compte/s_inscrire.html.twig (bloc style uniquement)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune

---

### 2026-03-13 (session 10) — Connexion BDD + migrations + fixtures Phase 1
- Objectif : finaliser connexion MySQL, créer les tables, charger les données initiales
- Actions réalisées :
  1. **PDO manquant** → créé `php.ini` depuis `php.ini-development` dans Scoop PHP 8.5.3 + activé `extension=pdo_mysql` + `extension_dir="ext"` → PDO mysql OK
  2. **Cache vidé** → `php bin/console cache:clear` OK
  3. **Connexion Doctrine** → `SELECT 1` OK (mabb_db joignable)
  4. **Migration générée** → `Version20260313190624.php` (6 SQL queries)
  5. **Migration exécutée** → tables créées (club, user, user_club_role, messenger_messages)
  6. **DoctrineFixturesBundle** → impossible à installer via Composer (curl error 60, antivirus intercepte SSL)
  7. **Contournement** → créé `src/Command/LoadFixturesManualCommand.php` (commande Symfony temporaire avec DI)
  8. **Fixtures chargées** → 1 club + 5 users + UserClubRole via `php bin/console app:load-fixtures-manual`
  9. **Tables vérifiées** → 5 tables présentes dans mabb_db
- Fichiers créés/modifiés :
  - `C:/Users/Velito Adventure/scoop/apps/php/current/php.ini` (créé + pdo_mysql activé)
  - `migrations/Version20260313190624.php` (généré automatiquement)
  - `src/Command/LoadFixturesManualCommand.php` (créé, temporaire — supprimer après install du bundle)
  - `instruction/13_CLAUDE_LOG.md` (cette entrée)
- Décisions :
  - Blocage SSL `curl error 60` structurel (antivirus). Résolution : désactiver interception SSL antivirus pour PHP, puis `composer require doctrine/doctrine-fixtures-bundle --dev`, puis supprimer `LoadFixturesManualCommand`.
  - BL-0011 ✅ Migration exécutée — Phase 1 BDD opérationnelle

---

### 2026-03-13 (session 11) — Correction firewall vitrine host pattern
- Objectif : autoriser 127.0.0.1 dans le firewall vitrine pour les tests en dev
- Actions réalisées :
  - `config/packages/security.yaml` firewall `vitrine` : `localhost` → `localhost|127\.0\.0\.1` dans le pattern host
  - `php bin/console cache:clear` OK
- Fichiers modifiés :
  - config/packages/security.yaml
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune — changement dev uniquement, à ne pas reporter en prod

---

### 2026-03-13 (session 12) — Correction 404 sur /compte/se-connecter
- Objectif : diagnostiquer et corriger le 404 sur les routes /compte/*
- Diagnostic :
  - Les routes `vitrine_compte_*` existaient bien dans le router (`debug:router` OK)
  - Le host constraint dans `config/routes/vitrine.yaml` ne contenait pas `127.0.0.1`
  - → Le routeur rejetait les requêtes venant de `127.0.0.1:8000` (domain non matché)
- Actions réalisées :
  - `config/routes/vitrine.yaml` requirements.domain : ajout de `127\.0\.0\.1`
  - `php bin/console cache:clear` OK
- Fichiers modifiés :
  - config/routes/vitrine.yaml
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : même pattern à corriger dans manager.yaml et pirb.yaml si tests locaux nécessaires

---

### 2026-03-14 (session 13) — Navbar : bloc auth app.user
- Objectif : Remplacer le formulaire de recherche de la navbar vitrine par le bloc Connexion/Déconnexion contextuel
- Actions réalisées :
  - `templates/vitrine/navbar.html.twig` : remplacement du `<form class="d-flex">` (search) par le bloc `{% if app.user %}` avec lien Mon compte + Déconnexion / `{% else %}` Connexion + S'inscrire
  - `php bin/console cache:clear` OK
- Fichiers modifiés :
  - templates/vitrine/navbar.html.twig
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune ADR nécessaire
- Points de vigilance :
  - `app.user.prenom` suppose que l'entité User expose un getter `getPrenom()` — à vérifier lors du branchement SecurityBundle
  - La route `/deconnexion` doit être configurée dans security.yaml (logout path)

---

### 2026-03-17 (session 14) — Navbar : bandeau construction + liens Membres/Formation + auth conditionnels + footer 23 ans
- Objectif : 4 modifications sur `templates/vitrine/base.html.twig`
- Actions réalisées :
  1. **Bandeau 🚧 Site en construction** ajouté après le brand MABB (visible desktop uniquement via `d-none d-lg-inline-flex`), pointant vers `vitrine_clavel`
  2. **Lien "Membres"** ajouté dans la navbar après "Le Club" (route `vitrine_membres`)
  3. **Lien "Formation"** ajouté dans la navbar avant "Numérique" (route `vitrine_formation`)
  4. **Bloc auth conditionnel** : `{% if app.user %}` → Mon compte + Déconnexion / `{% else %}` → Connexion + S'inscrire (remplace les boutons statiques)
  5. **Footer** : "depuis plus de 20 ans" → "depuis plus de 23 ans" (corrigé dans la `<meta>` description ET le paragraphe footer pour cohérence)
  - ⚠️ `php bin/console cache:clear` à lancer manuellement (PHP non disponible dans le sandbox Claude)
- Fichiers modifiés :
  - templates/vitrine/base.html.twig
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune ADR nécessaire
- Points de vigilance :
  - Les routes `vitrine_membres`, `vitrine_formation` et `vitrine_clavel` doivent exister (controllers + YAML) — Twig lèvera une exception sinon
  - La route `vitrine_logout` doit être configurée dans security.yaml (logout path) — cf. session 13 même remarque
  - `app.user.prenom` → getter `getPrenom()` requis sur l'entité User (idem session 13)

---

### 2026-03-17 (session 15) — Accueil : chiffres clés + bouton Aide au devoir
- Objectif : 2 modifications sur `templates/vitrine/accueil/index.html.twig`
- Actions réalisées :
  1. **Chiffres clés** : `'3' / 'Sites à Amiens'` → `'7' / 'Quartiers'`
  2. **Chiffres clés** : `'20+' / 'Ans d'engagement'` → `'23+' / 'Ans d'engagement'`
  3. **Hero** : ajout d'un 3ᵉ bouton "Aide au devoir" (bleu `#1c88b6`, `rounded-pill`) pointant vers `vitrine_club#aide-au-devoir`
  - ⚠️ `php bin/console cache:clear` à lancer manuellement
- Fichiers modifiés :
  - templates/vitrine/accueil/index.html.twig
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune ADR nécessaire
- Points de vigilance :
  - L'ancre `#aide-au-devoir` dans `vitrine_club` doit exister côté HTML pour que le scroll fonctionne — sinon navigation OK mais pas de scroll automatique

---

### 2026-03-17 (session 16) — Nouveaux controllers : NumeriquePagesController + CompteController (mon-compte / update-profil)
- Objectif : créer les routes manquantes `vitrine_membres`, `vitrine_formation`, `vitrine_cite_educative`, `vitrine_clavel`, `vitrine_compte_mon_compte`, `vitrine_compte_update_profil`
- Actions réalisées :
  1. **Création** `src/Controller/Vitrine/NumeriquePagesController.php` (4 routes) :
     - `GET /membres` → `vitrine_membres` (liste membres publics filtrables par rôle via `UserRepository::findPublicMembers`)
     - `GET /formation` → `vitrine_formation`
     - `GET /cite-educative` → `vitrine_cite_educative`
     - `GET /clavel` → `vitrine_clavel`
  2. **Mise à jour** `src/Controller/Vitrine/CompteController.php` (2 méthodes + 1 use) :
     - Ajout `use Symfony\Component\String\Slugger\SluggerInterface` (absent)
     - Ajout `GET /compte/mon-compte` → `vitrine_compte_mon_compte` (accès ROLE_USER, render `mon_compte.html.twig`)
     - Ajout `POST /compte/update-profil` → `vitrine_compte_update_profil` (CSRF, bio/roleMembre/isPublic/photo, upload dans `public/uploads/avatars/`)
  - ⚠️ `php bin/console cache:clear` + vérif `debug:router` à lancer manuellement
- Fichiers créés/modifiés :
  - src/Controller/Vitrine/NumeriquePagesController.php (nouveau)
  - src/Controller/Vitrine/CompteController.php (SluggerInterface + 2 méthodes)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune ADR nécessaire
- Points de vigilance :
  - `UserRepository::findPublicMembers($role)` doit être implémentée dans `src/Repository/Core/UserRepository.php` — elle n'existe pas encore
  - Les templates `vitrine/membres/index.html.twig`, `vitrine/formation/index.html.twig`, `vitrine/clavel/index.html.twig`, `vitrine/compte/mon_compte.html.twig` sont à créer
  - `vitrine/club/cite_educative.html.twig` est à créer (ou vérifier s'il existe déjà)
  - L'entité `User` doit exposer `setBio()`, `setRoleMembre()`, `setIsPublic()`, `setPhotoPath()` — à vérifier/créer si absent (migration nécessaire si champs manquants)
  - Le dossier `public/uploads/avatars/` doit exister et être accessible en écriture
  - Ces routes sont définies sans host constraint (`#[Route]` sur le controller, pas dans vitrine.yaml) — vérifier qu'elles sont bien importées par la config de routage vitrine

---

### 2026-03-17 (session 17) — User : profil public + migration + dossier avatars
- Objectif : compléter l'entité User (bio/photo/isPublic/roleMembre), implémenter findPublicMembers, créer le dossier uploads
- Actions réalisées :
  1. **`src/Entity/Core/User.php`** — ajout de 4 propriétés Doctrine :
     - `bio` : `text nullable`
     - `photoPath` : `string(255) nullable`
     - `isPublic` : `boolean default false`
     - `roleMembre` : `string(50) nullable, default 'benevole'`
     - + 8 getters/setters correspondants (`getBio/setBio`, `getPhotoPath/setPhotoPath`, `isPublic/setIsPublic`, `getRoleMembre/setRoleMembre`)
  2. **`src/Repository/Core/UserRepository.php`** — ajout `findPublicMembers(?string $role)` : filtre `isPublic=true`, tri `roleMembre ASC` + `prenom ASC`, filtre optionnel par rôle
  3. **`public/uploads/avatars/`** — dossier créé + `.gitkeep` pour suivi Git
  4. **Migration Doctrine** — à lancer manuellement (PHP absent du sandbox) :
     ```
     php bin/console cache:clear
     php bin/console doctrine:migrations:diff
     php bin/console doctrine:migrations:migrate --no-interaction
     php bin/console doctrine:query:sql "DESCRIBE user"
     ```
- Fichiers modifiés/créés :
  - src/Entity/Core/User.php (4 propriétés + 8 accesseurs)
  - src/Repository/Core/UserRepository.php (findPublicMembers)
  - public/uploads/avatars/.gitkeep (nouveau)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune ADR nécessaire
- Points de vigilance :
  - ✅ `findPublicMembers` implémentée — point de vigilance session 16 résolu
  - ✅ `setBio/setRoleMembre/setIsPublic/setPhotoPath` implémentés — point de vigilance session 16 résolu
  - ✅ `public/uploads/avatars/` créé — point de vigilance session 16 résolu
  - La migration `doctrine:migrations:diff` va générer les 4 colonnes : `bio TEXT NULL`, `photo_path VARCHAR(255) NULL`, `is_public TINYINT(1) DEFAULT 0`, `role_membre VARCHAR(50) NULL`
  - Les templates restants (`vitrine/membres/index.html.twig`, `vitrine/formation/index.html.twig`, `vitrine/clavel/index.html.twig`, `vitrine/compte/mon_compte.html.twig`, `vitrine/club/cite_educative.html.twig`) restent à créer

---

### 2026-03-17 (session 18) — Création des 5 templates Twig manquants
- Objectif : créer les templates des 5 routes introduites en sessions 16/17
- Actions réalisées :
  1. **`templates/vitrine/compte/mon_compte.html.twig`** — page profil connecté : avatar, bio, roleMembre, toggle isPublic, upload photo, flash messages, liens espaces + déconnexion
  2. **`templates/vitrine/membres/index.html.twig`** — grille cards membres publics, filtres par rôle (Twig for sur hash), état vide, bannière CTA selon app.user
  3. **`templates/vitrine/formation/index.html.twig`** — 3 cards parcours (Clavel/Moussa/Ugo) + section "Et bien d'autres..." avec prénoms
  4. **`templates/vitrine/clavel/index.html.twig`** — page perso Clavel : rôle MABB, VENA, 3 projets (Vitrine/App/PIRB) sur card gradient
  5. **`templates/vitrine/club/cite_educative.html.twig`** — 3 niveaux scolaires (École/Collège/Lycée), lien Aide au devoir Amiens, lien vitrine_formation
  - Dossiers créés automatiquement : `membres/`, `formation/`, `clavel/`, `club/`
  - ⚠️ `php bin/console cache:clear` à lancer manuellement
- Fichiers créés :
  - templates/vitrine/compte/mon_compte.html.twig
  - templates/vitrine/membres/index.html.twig
  - templates/vitrine/formation/index.html.twig
  - templates/vitrine/clavel/index.html.twig
  - templates/vitrine/club/cite_educative.html.twig
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune ADR nécessaire
- Points de vigilance :
  - ✅ Tous les templates manquants (sessions 16/17) sont maintenant créés
  - ✅ `btn-filtre-mabb` ajouté dans base.html.twig (résolu dans la session)
  - La migration Doctrine (session 17) doit être jouée avant de tester `/compte/mon-compte` et `/membres`
  - `/membres` retournera une liste vide tant qu'aucun user n'a `isPublic = true`

---

### 2026-03-18 (session 19) — Multi-rôles vitrine : rolesMembre JSON + admin roles
- Objectif : remplacer `roleMembre: string` par `rolesMembre: json` (multi-valeurs), créer l'interface admin de gestion des rôles
- Décision architecture : Option B (JSON sur User) plutôt qu'Option A (UserClubRole) pour la vitrine — UserClubRole reste intact pour le Manager multi-club
- Actions réalisées :
  1. **`src/Entity/Core/User.php`** :
     - `roleMembre: string` → `rolesMembre: json` (default `['benevole']`)
     - `getRoleMembre/setRoleMembre` → `getRolesMembre/setRolesMembre/hasRoleMembre/addRoleMembre/removeRoleMembre`
     - `setRolesMembre()` force toujours `benevole` en tête — indestructible
  2. **`src/Controller/Vitrine/CompteController.php`** — `updateProfil` :
     - `$request->request->all('rolesMembre')` → multi-checkboxes
     - `benevole` auto-injecté par `setRolesMembre()`, l'utilisateur ne peut pas le retirer
  3. **`templates/vitrine/compte/mon_compte.html.twig`** :
     - `<select>` single → checkboxes multi-sélection avec `benevole` grisé/disabled
     - En-tête page : badges multi-rôles au lieu d'un seul span
  4. **`src/Controller/Admin/AdminRolesController.php`** (nouveau) :
     - `GET /admin/utilisateurs` → `admin_utilisateurs` : liste tous les users (SUPER_ADMIN)
     - `GET|POST /admin/utilisateur/{id}/roles` → `admin_utilisateur_roles` : modifier les rôles d'un user
     - Périmètre volontairement réduit : prénom/nom/email/rôles uniquement — pas de bio/photo
  5. **`templates/admin/roles/liste.html.twig`** + **`templates/admin/roles/editer.html.twig`** (nouveaux)
  6. **`templates/vitrine/membres/index.html.twig`** : `roleMembre|capitalize` → badges multi-rôles en boucle
  7. **`src/Repository/Core/UserRepository.php`** : `findPublicMembers` adapté JSON avec `JSON_CONTAINS` MySQL
  - ⚠️ Migration obligatoire : `doctrine:migrations:diff` + `migrate` (renomme `role_membre VARCHAR` → `roles_membre JSON`)
- Fichiers créés/modifiés :
  - src/Entity/Core/User.php
  - src/Controller/Vitrine/CompteController.php
  - src/Controller/Admin/AdminRolesController.php (nouveau)
  - src/Repository/Core/UserRepository.php
  - templates/vitrine/compte/mon_compte.html.twig
  - templates/vitrine/membres/index.html.twig
  - templates/admin/roles/liste.html.twig (nouveau)
  - templates/admin/roles/editer.html.twig (nouveau)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : ADR pas nécessaire (décision technique mineure, scope vitrine uniquement)
- Points de vigilance :
  - `JSON_CONTAINS` dans `findPublicMembers` nécessite MySQL 5.7+ (ok sur ton env)
  - Les routes `/admin/*` n'ont pas de host constraint — vérifier qu'elles ne sont pas exposées sur le domaine manager ou pirb en prod
  - Les fixtures existantes ont `roleMembre = 'benevole'` (string) → la migration doit convertir en `["benevole"]` (JSON). Vérifier que Doctrine génère un `ALTER TABLE` propre
  - Tester que la page `/admin/utilisateurs` retourne bien 403 pour un `ROLE_USER` simple

---

### 2026-03-19 (session 20) — Équipes : Basket Loisir Mixte + coachs + Service Civique
- Objectif : 3 modifications sur `templates/vitrine/accueil/equipes.html.twig`
- Actions réalisées :
  1. **Modification 1 — Card "Basket Loisir Mixte"** : ajoutée après `{% endfor %}` de la grille des équipes (inline HTML, pas dans le tableau Twig — structure légèrement différente sans badges niveau/lieu)
  2. **Modification 2 — Section Encadrement technique** : remplacement du placeholder "Staff technique à compléter" par une `{% set coachs = [...] %}` Twig avec 4 cards (Responsable sportif 🏀, Éducateur Mini-Basket ⭐, Éducatrice U11/U13 🎯, Coach Senior 🏆) — tous marqués "À compléter" sauf le premier
  3. **Modification 3 — Section Service Civique** : nouvelle `<section>` avec gradient bleu (`#063a55 → #0b4fa3`) ajoutée après la section encadrement, 2 cards volontaires (Volontaire 1 / Volontaire 2) marquées "À compléter"
  - ⚠️ `php bin/console cache:clear` à lancer manuellement
- Fichiers modifiés :
  - templates/vitrine/accueil/equipes.html.twig
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune ADR nécessaire
- Points de vigilance :
  - La card Basket Loisir Mixte n'a pas de badges niveau/lieu (structure différente des autres cards — volontaire, la pratique loisir n'a pas de niveau compétitif)
  - Les noms de coachs et volontaires sont "À compléter" — à remplacer par les vraies données via un futur prompt ciblé
  - La section Service Civique utilise `border:1px solid rgba(255,255,255,.15)!important` pour override Bootstrap — cohérent avec le pattern déjà utilisé sur d'autres templates

---

### 2026-03-19 (session 20 suite) — Club : bouton Aide au devoir + card Cité Éducative
- Objectif : 2 modifications sur `templates/vitrine/accueil/club.html.twig`
- Actions réalisées :
  1. **Modification 1 — Card Éducation** :
     - `id="aide-au-devoir"` ajouté sur le `div.card` via `{% if v.title == 'Éducation' %}` dans la boucle Twig (pas besoin de sortir la card du loop)
     - Bouton `<a class="btn btn-mabb btn-sm mt-3 w-100">Aide au devoir</a>` ajouté après le `<p>` de la card Éducation, conditionnel au même `{% if %}`
     - URL : `https://www.amiens.fr/Vivre-a-Amiens/Education-Jeunesse/Aide-aux-devoirs`
  2. **Modification 2 — Card Cité Éducative** : ajoutée après `{% endfor %}` dans la `div.row.g-4`, bordure orange `2px solid var(--mabb-orange)`, lien vers `vitrine_cite_educative`, hover inline JS
  - ⚠️ `php bin/console cache:clear` à lancer manuellement
- Fichiers modifiés :
  - templates/vitrine/accueil/club.html.twig
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : pattern `{% if v.title == 'X' %}` dans la boucle valeurs plutôt que de dupliquer les cards en HTML — plus maintenable si les valeurs changent
- Points de vigilance :
  - Le `mb-0` sur `<p class="text-muted small mb-0">` a été retiré pour laisser de l'espace avant le bouton sur la card Éducation — les 3 autres cards ont aussi perdu `mb-0` (pas de bouton → pas d'impact visuel notable)
  - La card Cité Éducative utilise `onmouseover`/`onmouseout` inline JS pour le hover — cohérent avec le pattern demandé dans le prompt, mais `.card-mabb:hover` CSS aurait suffi si la card était dans le loop

---

### 2026-03-19 (session 20 suite 2) — Formation : Moussa + Ugo mis à jour + card Romy ajoutée
- Objectif : 3 modifications sur `templates/vitrine/formation/index.html.twig`
- Actions réalisées :
  1. **Card Moussa** : contenu placeholder remplacé — BP APT + BPJEPS Sport Co + DEJEPS en cours + badge Direction
  2. **Card Ugo** : contenu placeholder remplacé — BPJEPS Sport Co + badges Encadrement / Formation
  3. **Card Romy** (nouvelle) : gradient vert (`#2ecc71 → #1a8a4a`), rôle "Passerelle social & sportif", badges Travail social / Sport & inclusion / Lien social — ajoutée après Ugo dans le même `div.row.g-4`
  - ⚠️ `php bin/console cache:clear` à lancer manuellement
- Fichiers modifiés :
  - templates/vitrine/formation/index.html.twig
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : aucune ADR nécessaire
- Points de vigilance :
  - La grille est maintenant 4 cards en `col-lg-4` → sur desktop ça fait 3 + 1 (dernière ligne centrée Bootstrap) — acceptable visuellement, peut être ajusté en `col-lg-3` si on veut 4 en ligne plus tard
  - Les "Contenu à compléter" ont été supprimés sur Moussa et Ugo — données réelles intégrées

---

### 2026-03-19 (session 20 suite 3) — Nouveau page Projet Sport-Études + mise à jour Cité Éducative
- Objectif : créer la page `/projet-sport-etude` et brancher le bouton Lycée dans `cite_educative`
- Actions réalisées :
  1. **`src/Controller/Vitrine/NumeriquePagesController.php`** : ajout méthode `projetSportEtude()` — `GET /projet-sport-etude` → `vitrine_projet_sport_etude` → render `vitrine/club/projet_sport_etude.html.twig`
  2. **`templates/vitrine/club/projet_sport_etude.html.twig`** (nouveau) : 5 sections —
     - Intro (section-gray) : accroche projet sport-études Cité Scolaire
     - Pourquoi ce projet : texte + card gradient bleu avec 6 items du dispositif (Twig loop)
     - Modèle existant (section-gray) : 3 cards (Section sportive scolaire / Résultats / Ancrage territorial)
     - État d'avancement : gradient bleu, 4 étapes (Twig loop), étape 01 "En cours" en orange, les autres grisées
     - CTA (section-gray) : boutons Nous contacter + Retour Cité Éducative
  3. **`templates/vitrine/club/cite_educative.html.twig`** : bouton card Lycée — `vitrine_formation` → `vitrine_projet_sport_etude`, texte "Projet Sport-Études →" + icône `bi-mortarboard`
  - ⚠️ `php bin/console cache:clear` à lancer manuellement
- Fichiers créés/modifiés :
  - src/Controller/Vitrine/NumeriquePagesController.php (1 méthode ajoutée)
  - templates/vitrine/club/projet_sport_etude.html.twig (nouveau)
  - templates/vitrine/club/cite_educative.html.twig (bouton Lycée)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : route dans `NumeriquePagesController` plutôt qu'un controller dédié — cohérent avec les autres routes vitrine statiques du même controller
- Points de vigilance :
  - La route `/projet-sport-etude` n'a pas de host constraint — vérifier qu'elle est bien importée par le fichier `config/routes/vitrine.yaml` (même problème que les autres routes sans contrainte, cf. session 12)
  - La couleur de fond des cercles étapes est gérée via `{% if etape.status == 'En cours' %}` inline dans le style — si le statut change, mettre à jour les données du tableau Twig directement

---

### 2026-03-19 (session 20 suite 4) — Refonte AdminRolesController + nouveau template admin + lien navbar
- Objectif : remplacer l'ancien controller admin (2 routes GET+POST séparées) par un nouveau pattern (liste + formulaire inline), créer le template `admin/roles/index.html.twig`, brancher le bouton Admin dans la navbar
- Actions réalisées :
  1. **`src/Controller/Admin/AdminRolesController.php`** — réécriture complète :
     - Anciens noms : `admin_utilisateurs` / `admin_utilisateur_roles` (GET+POST)
     - Nouveaux noms : `admin_users_list` (GET) / `admin_user_roles_edit` (POST uniquement)
     - `index()` : `GET /admin/utilisateurs` → liste tous les users triés par prénom
     - `editRoles()` : `POST /admin/utilisateur/{id}/roles` → CSRF `edit_roles_{id}`, filtre rôles valides, `setRolesMembre()`, flash success, redirect vers liste
     - Injection directe `User $user` (ParamConverter) au lieu de `UserRepository::find($id)` manuel
  2. **`templates/admin/roles/index.html.twig`** (nouveau) : liste all users en cards 2 colonnes — avatar/emoji fallback, email, badges rôles actuels, formulaire checkboxes inline (benevole disabled/checked, 6 rôles cochables), bouton submit par card, CSRF par user
  3. **`templates/vitrine/base.html.twig`** : bouton `<i class="bi bi-shield-lock">Admin</i>` (warning, rounded-pill) ajouté après "Mon compte", visible uniquement si `is_granted('ROLE_SUPER_ADMIN')`
  - ⚠️ `php bin/console cache:clear` à lancer manuellement
  - Les anciens templates `liste.html.twig` et `editer.html.twig` sont maintenant orphelins (les routes qui les référençaient n'existent plus) — peuvent être supprimés proprement
- Fichiers créés/modifiés :
  - src/Controller/Admin/AdminRolesController.php (réécrit)
  - templates/admin/roles/index.html.twig (nouveau)
  - templates/vitrine/base.html.twig (bouton Admin)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions : POST-only sur `editRoles` — plus propre que GET+POST mixte, réduit la surface d'attaque (pas de modification via GET possible)
- Points de vigilance :
  - `User $user` dans `editRoles` utilise le ParamConverter Doctrine — Symfony résout automatiquement l'entité depuis `{id}`. Si l'id n'existe pas → 404 automatique (plus propre que le `find()` manuel)
  - Le bouton Admin est visible seulement si `ROLE_SUPER_ADMIN` — aucun user normal ne le voit
  - `admin/roles/liste.html.twig` et `admin/roles/editer.html.twig` sont désormais orphelins

---

### 2026-03-19 (session 21) — Extraction CSS inline → vitrine.css + fix double-class badge
- Objectif : supprimer tous les `style=""` inline des templates vitrine en les remplaçant par des classes CSS nommées dans `assets/styles/vitrine.css`
- Actions réalisées :
  1. **`assets/styles/vitrine.css`** (nouveau fichier) : toutes les classes extraites regroupées par section :
     - Hero : `.hero-accueil`, `.hero-accueil-bg`, `.hero-title`, `.hero-desc`, `.btn-aide-devoir`, `.btn-aide-devoir:hover`, `.hero-photo-wrap`, `.hero-photo`
     - Chiffres clés : `.section-chiffres`, `.chiffre-icon`, `.chiffre-number`
     - Badges label outline : `.badge-label-outline`, `.badge-star`
     - Photo placeholder : `.photo-placeholder`, `.photo-placeholder-icon`
     - CTA inscription : `.section-cta-inscription`, `.cta-desc`
     - Membres : `.membre-card-header`, `.membre-avatar`, `.membre-avatar-placeholder`, `.membre-nom`, `.membre-bio`
     - Mon compte : `.compte-avatar`, `.compte-avatar-placeholder`, `.compte-email`, `.badge-role-membre`
     - Formation : `.formation-card-header-blue/teal/orange/green`, `.formation-emoji`, `.formation-subtitle`, `.formation-cta`, `.formation-cta-desc`, `.formation-tag`, `.formation-tag-orange`
     - Bandeau construction : `.bandeau-construction`, `.bandeau-construction:hover`
  2. **`assets/app.js`** : ajout `import './styles/vitrine.css';` après `import './styles/app.css';`
  3. **`templates/vitrine/accueil/index.html.twig`** : 16 remplacements `style=""` → classes CSS (`hero-accueil`, `hero-accueil-bg`, `section-chiffres`, `chiffre-icon`, `chiffre-number`, `badge-label-outline`, `badge-star`, `photo-placeholder`, `photo-placeholder-icon`, `section-cta-inscription`, `cta-desc`, `btn-aide-devoir`)
  4. **`templates/vitrine/membres/index.html.twig`** : 5 remplacements (`membre-card-header`, `membre-avatar`, `membre-avatar-placeholder`, `membre-nom`, `membre-bio`)
  5. **`templates/vitrine/compte/mon_compte.html.twig`** : 4 remplacements (`compte-avatar`, `compte-avatar-placeholder`, `compte-email`, `badge-role-membre`)
  6. **`templates/vitrine/formation/index.html.twig`** : 12 remplacements (`formation-card-header-*`, `formation-emoji`, `formation-subtitle`, `formation-cta`, `formation-cta-desc`, `formation-tag`, `formation-tag-orange`)
  7. **`templates/vitrine/base.html.twig`** : bandeau construction style→class (`bandeau-construction`)
  8. **Fix double-class `badge-label-outline`** : le script de remplacement avait ajouté un second `class=""` sur les 3 spans déjà pourvus d'un `class=`. Corrigé en fusionnant les deux attributs en un seul : `class="badge px-3 py-2 rounded-pill fw-bold badge-label-outline"`
  - ⚠️ `php bin/console asset-map:compile` ou `asset-map:warm-cache` si Asset Mapper est en mode warm
  - ⚠️ `php bin/console cache:clear` à lancer manuellement
- Fichiers créés/modifiés :
  - assets/styles/vitrine.css (nouveau)
  - assets/app.js (import ajouté)
  - templates/vitrine/accueil/index.html.twig (16 remplacements + fix double-class)
  - templates/vitrine/membres/index.html.twig
  - templates/vitrine/compte/mon_compte.html.twig
  - templates/vitrine/formation/index.html.twig
  - templates/vitrine/base.html.twig
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions :
  - Les `style=""` dynamiques Twig (ex. `style="color:{{ s.color }}"`) conservés inline — impossible à extraire en CSS statique
  - `hero-accueil-bg` utilise `/images/bg.jpg` en chemin direct (pas `asset()`) — acceptable pour Asset Mapper, le fichier est dans `public/images/`
  - Compromis `.formation-subtitle` : la variation d'opacité (`.7` vs `.8` selon les cards) uniformisée à `.75` dans la classe CSS partagée
- Points de vigilance :
  - Les anciens templates orphelins `admin/roles/liste.html.twig` et `admin/roles/editer.html.twig` (session 20 suite 4) n'ont pas été supprimés — à nettoyer si souhaité
  - Migration Doctrine `roles_membre → roles_membre JSON` (session 19) toujours en attente d'exécution manuelle

---

### 2026-03-19 (session 22) — SEO : sitemap.xml + robots.txt + formulaire contact branché Symfony Mailer
- Objectif : créer les fichiers SEO statiques et connecter le formulaire contact à Symfony Mailer
- Actions réalisées :
  1. **`public/sitemap.xml`** (nouveau) : sitemap statique avec 11 URLs, priorités et changefreq adaptées (1.0 accueil → 0.5 contact/clavel). Accessible directement via `/sitemap.xml`.
  2. **`public/robots.txt`** (nouveau) : `Allow: /`, `Sitemap: https://mabb.fr/sitemap.xml`, `Disallow` sur `/admin/`, `/compte/`, `/api/`.
  3. **`.env`** : ajout `MAILER_FROM=noreply@mabb.fr` dans le bloc `###> symfony/mailer ###` (MAILER_DSN=null://null était déjà présent).
  4. **`src/Controller/Vitrine/AccueilController.php`** :
     - Ajout de 3 `use` : `Request`, `MailerInterface`, `Email`
     - Remplacement de la méthode `contact()` vide par une méthode complète `GET|POST` :
       - Vérification CSRF token (`contact_form`)
       - Nettoyage inputs (`strip_tags`, `trim`)
       - Validation : nom/prénom obligatoires, email valide (`filter_var`), sujet non vide, message ≥ 10 chars
       - Construction email HTML avec tableau récapitulatif (de, email, téléphone, sujet, message)
       - Envoi vers `contact@mabb.fr` + `reseauxmabb@gmail.com` (double destinataire)
       - `replyTo($email)` → répondre directement à l'expéditeur depuis le client mail
       - Subject formatté : `[MABB Contact] Inscription — Prénom Nom`
       - Catch `\Exception` → message d'erreur utilisateur en cas d'échec
       - Variables `success` + `errors` passées au template
  5. **`templates/vitrine/accueil/contact.html.twig`** :
     - Suppression du commentaire `TODO`
     - Ajout bloc alertes Bootstrap avant le `<form>` : `alert-success` si succès, `alert-danger` avec liste des erreurs sinon
     - Ajout `<input type="hidden" name="_csrf_token" value="{{ csrf_token('contact_form') }}">` dans le formulaire
  - ⚠️ `php bin/console cache:clear` à lancer manuellement
  - ⚠️ En dev, `MAILER_DSN=null://null` → emails absorbés (aucun envoi réel). Pour tester : installer Mailpit et mettre `MAILER_DSN=smtp://localhost:1025` dans `.env.local`
  - ⚠️ En prod : mettre le vrai DSN SMTP dans `.env.local` (jamais dans `.env`)
- Fichiers créés/modifiés :
  - public/sitemap.xml (nouveau)
  - public/robots.txt (nouveau)
  - .env (MAILER_FROM ajouté)
  - src/Controller/Vitrine/AccueilController.php (contact() réécrite, 3 use ajoutés)
  - templates/vitrine/accueil/contact.html.twig (alertes + CSRF)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions :
  - Sitemap statique plutôt que controller dynamique — les URLs vitrine sont stables, pas de contenu DB à indexer pour l'instant
  - Double destinataire `contact@mabb.fr` + `reseauxmabb@gmail.com` — boîte officielle pour archives + Gmail pour notifications mobiles Clavel
  - Adresses hardcodées dans le controller, pas de `MAILER_TO` dans `.env` — plus simple, moins de config à maintenir pour une asso
- Points de vigilance :
  - Le `sitemap.xml` est statique — à mettre à jour manuellement si de nouvelles pages sont ajoutées
  - `/news` et `/galerie` sont dans le sitemap — `/news` est maintenant dynamique (session 23), `/galerie` reste statique pour l'instant
  - Migration Doctrine (session 19) toujours en attente

---

### 2026-03-19 (session 23) — CMS vitrine complet : entités Article + Media, back-office admin, pages dynamiques
- Objectif : implémenter le CMS vitrine — entités, repositories, back-office admin CRUD, pages news/accueil dynamiques
- Actions réalisées :
  1. **`src/Entity/Vitrine/Article.php`** (nouveau) : entité Article avec `titre`, `slug` (auto-généré via `onPrePersist`), `contenu` (text), `imagePath`, `statut` (brouillon/publie/archive), `publishedAt`, `createdAt`, `updatedAt`, `auteur (ManyToOne User nullable SET NULL)`. Constantes `STATUT_BROUILLON/PUBLIE/ARCHIVE`. Méthode `isPublie()`. Slug généré avec translittération + `uniqid(-6)` pour garantir l'unicité.
  2. **`src/Entity/Vitrine/Media.php`** (nouveau) : entité Media avec `nom`, `path`, `type` (image/video), `taille`, `legende`, `createdAt`.
  3. **`src/Repository/Vitrine/ArticleRepository.php`** (nouveau) : `findDerniersPublies(limit)` (3 derniers pour accueil), `findPubliesPagines(page, perPage)` (pagination /news), `countPublies()` (pour calculer totalPages).
  4. **`src/Repository/Vitrine/MediaRepository.php`** (nouveau) : `findImages(limit)`.
  5. **`src/DataFixtures/ArticleFixtures.php`** (nouveau) : 5 articles (4 publiés + 1 brouillon) avec données réelles MABB.
  6. **`src/Controller/Admin/AdminArticlesController.php`** (nouveau) : CRUD complet sous `/admin/articles` :
     - `GET /admin/articles` → `admin_articles_list` : liste tous les articles triés par `createdAt DESC`
     - `GET|POST /admin/articles/nouveau` → `admin_articles_new` : création + upload image
     - `GET|POST /admin/articles/{id}/modifier` → `admin_articles_edit` : modification + upload image
     - `POST /admin/articles/{id}/supprimer` → `admin_articles_delete` : suppression CSRF
     - `denyAccessUnlessGranted('ROLE_SUPER_ADMIN')` sur toutes les routes
     - Upload image dans `public/uploads/articles/` via `SluggerInterface`
     - `publishedAt` auto-assigné au passage en statut `publie`
  7. **`templates/admin/articles/index.html.twig`** (nouveau) : tableau articles avec badges statut (vert/orange/gris), boutons modifier/supprimer (CSRF), liens vers `admin_users_list` et retour site
  8. **`templates/admin/articles/form.html.twig`** (nouveau) : formulaire création/édition — titre, textarea contenu (HTML accepté), upload image avec prévisualisation si existante, select statut, CSRF
  9. **`src/Controller/Vitrine/AccueilController.php`** : 3 modifications :
     - `use App\Repository\Vitrine\ArticleRepository` ajouté
     - `index()` : injecte `ArticleRepository`, passe `dernieres_actus` (3 derniers publiés) au template
     - `news()` : injecte `ArticleRepository`, passe `articles` (paginés), `page`, `totalPages`
  10. **`templates/vitrine/accueil/index.html.twig`** : bloc actus statiques `{% set actus = [...] %}` remplacé par boucle dynamique sur `dernieres_actus` — image optionnelle, titre/date/extrait `striptags|slice(120)`
  11. **`templates/vitrine/accueil/news.html.twig`** : page entièrement réécrite — grille dynamique 3 colonnes, placeholder gradient si pas d'image, pagination Bootstrap si `totalPages > 1`
  12. **`templates/vitrine/base.html.twig`** : bouton admin unique "Admin" → 2 boutons "Articles" (`admin_articles_list`) + "Rôles" (`admin_users_list`)
  13. **`public/uploads/articles/.gitkeep`** (nouveau) : dossier créé pour les images uploadées
  - ⚠️ `php bin/console doctrine:migrations:diff` — Doctrine va détecter les tables `article` et `media` → générer la migration
  - ⚠️ `php bin/console doctrine:migrations:migrate --no-interaction`
  - ⚠️ `php bin/console doctrine:fixtures:load --append --no-interaction` — charge les 5 articles de test
  - ⚠️ `php bin/console cache:clear`
- Fichiers créés/modifiés :
  - src/Entity/Vitrine/Article.php (nouveau)
  - src/Entity/Vitrine/Media.php (nouveau)
  - src/Repository/Vitrine/ArticleRepository.php (nouveau)
  - src/Repository/Vitrine/MediaRepository.php (nouveau)
  - src/DataFixtures/ArticleFixtures.php (nouveau)
  - src/Controller/Admin/AdminArticlesController.php (nouveau)
  - src/Controller/Vitrine/AccueilController.php (use + index() + news() modifiés)
  - templates/admin/articles/index.html.twig (nouveau)
  - templates/admin/articles/form.html.twig (nouveau)
  - templates/vitrine/accueil/index.html.twig (actus dynamiques)
  - templates/vitrine/accueil/news.html.twig (réécrit)
  - templates/vitrine/base.html.twig (2 boutons admin)
  - public/uploads/articles/.gitkeep (nouveau)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions :
  - Slug avec `uniqid(-6)` suffixé → garantit l'unicité sans query de vérification (acceptable pour un site asso, pas un SaaS)
  - `publishedAt` auto-assigné seulement à la première publication — si on repasse en brouillon puis re-publie, la date originale est conservée (comportement intentionnel)
  - `denyAccessUnlessGranted` dans chaque méthode plutôt que sur le controller — plus explicite, cohérent avec `AdminRolesController`
  - `striptags|slice(0,120)` pour les extraits — pas de DOMDocument, accepte les artefacts HTML simples pour un site asso
- Points de vigilance :
  - Les entités `Article` et `Media` sont dans `App\Entity\Vitrine\` — Doctrine doit les détecter automatiquement si `doctrine.yaml` mappe `src/Entity/` récursivement (standard Symfony)
  - `public/uploads/articles/` doit être accessible en écriture par le serveur web (permissions `www-data` ou `laragon`)
  - Migration double : session 19 (`roles_membre JSON`) + session 23 (`article`, `media`) — faire `migrations:diff` une seule fois couvrira les deux si pas encore migrées
  - Page `/galerie` reste statique — à brancher sur `Media` dans une session ultérieure

---

### 2026-03-19 (session 24) — EasyMDE éditeur visuel + page article détaillée + markdown_to_html
- Objectif : remplacer le textarea brut par un éditeur Markdown visuel (EasyMDE), créer la page article détaillée avec rendu Markdown → HTML
- Actions réalisées :
  1. **`importmap.php`** : ajout de 2 entrées EasyMDE :
     - `'easymde'` → `https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js`
     - `'easymde/css'` → `https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css` (type: css)
  2. **`templates/admin/articles/form.html.twig`** :
     - `<textarea>` : `id="contenu"` ajouté
     - Placeholder mis à jour
     - `div.form-text` remplacé par le message "Éditeur visuel — boutons de la barre"
     - Bloc `{% block extra_js %}` ajouté en fin de template avec CDN EasyMDE + instanciation JS : barre d'outils (bold, italic, heading, quote, list, link, image, preview, side-by-side, guide), spellChecker désactivé, autosave 3s
  3. **`src/Controller/Vitrine/AccueilController.php`** : ajout méthode `article()` :
     - `GET /news/{slug}` → `vitrine_news_article`
     - `findOneBy(['slug' => $slug, 'statut' => 'publie'])` → 404 si non trouvé
     - Render `vitrine/accueil/article.html.twig`
  4. **`templates/vitrine/accueil/article.html.twig`** (nouveau) : page détaillée — header avec titre/date/auteur, image optionnelle, `{{ article.contenu|markdown_to_html }}`, bouton retour, bouton "Modifier" si ROLE_SUPER_ADMIN
  5. **`assets/styles/vitrine.css`** : ajout section `.article-content` — font-size, line-height, couleurs h1/h2/h3, paragraphes, liens (bleu → orange hover), listes, blockquote (bordure orange), images (border-radius)
  6. **`templates/vitrine/accueil/news.html.twig`** : titre article → `<a>` vers `vitrine_news_article`, bouton "Lire l'article" ajouté en bas de chaque card
  7. **`templates/vitrine/accueil/index.html.twig`** : lien "Lire la suite" → `vitrine_news_article` au lieu de `vitrine_news`
  - ⚠️ **Commande obligatoire avant test** : `composer require league/commonmark` (requis pour le filtre Twig `|markdown_to_html`)
  - ⚠️ `php bin/console cache:clear` après
- Fichiers créés/modifiés :
  - importmap.php (EasyMDE ajouté)
  - templates/admin/articles/form.html.twig (EasyMDE intégré)
  - src/Controller/Vitrine/AccueilController.php (méthode article() ajoutée)
  - templates/vitrine/accueil/article.html.twig (nouveau)
  - assets/styles/vitrine.css (CSS article-content)
  - templates/vitrine/accueil/news.html.twig (liens article détaillé)
  - templates/vitrine/accueil/index.html.twig (liens article détaillé)
  - instruction/13_CLAUDE_LOG.md (cette entrée)
- Décisions :
  - EasyMDE chargé via CDN dans `{% block extra_js %}` uniquement sur le form admin — pas sur toutes les pages (inutile ailleurs)
  - Le contenu est stocké en Markdown dans la DB — c'est un choix volontaire pour simplifier l'édition par Willy. L'existant (HTML brut des fixtures) fonctionnera aussi car CommonMark passe le HTML brut tel quel
  - `|markdown_to_html` est le filtre natif Twig fourni par `twig/markdown-extra` (inclus dans `twig/extra-bundle`) + `league/commonmark` comme parser
- Points de vigilance :
  - **`composer require league/commonmark`** est obligatoire — sans ça, `|markdown_to_html` lève une exception Twig à l'affichage de l'article
  - Les 4 articles en DB contiennent du HTML brut (`<p>...</p>`) — CommonMark le passe tel quel, pas de régression
  - La route `/news/{slug}` est déclarée APRÈS `/news` — Symfony résout en ordre, pas de conflit
  - EasyMDE autosave stocke dans `localStorage` avec clé `article-editor` — deux onglets ouverts en même temps peuvent se conflitter (acceptable pour un usage mono-admin)

---

### 2026-03-19 (session 25) — CMS Pages vitrine : entité PageContenu + interface admin
- Objectif : Permettre au super admin de modifier les textes des pages vitrine sans toucher au code
- Actions réalisées :
  1. **CORRECTIF CRITIQUE** : suppression des entrées `easymde` et `easymde/css` de `importmap.php` — clé `'url'` invalide pour Symfony Asset Mapper → `RuntimeError` sur toutes les pages. EasyMDE reste chargé via CDN dans `{% block extra_js %}`.
  2. **`src/Entity/Vitrine/PageContenu.php`** (nouveau) : entité `page_contenu` — champs `pageSlug` (unique), `pageNom`, `contenu` (text, nullable), `sousTitre` (nullable), `updatedAt`. Lifecycle callbacks `onUpdate()` sur `PrePersist` + `PreUpdate`.
  3. **`src/Repository/Vitrine/PageContenuRepository.php`** (nouveau) : méthode `findBySlug(string $slug)`.
  4. **`src/DataFixtures/PageContenuFixtures.php`** (nouveau) : 3 pages (projet-sport-etude, formation, numerique). Guard anti-doublon : `findOneBy(['pageSlug' => ...])` avant persist.
  5. **`src/Controller/Admin/AdminPagesController.php`** (nouveau) : `#[Route('/admin/pages')]`, 2 méthodes (`index`, `edit` GET/POST), CSRF sur edit, `denyAccessUnlessGranted('ROLE_SUPER_ADMIN')`.
  6. **`templates/admin/pages/index.html.twig`** (nouveau) : liste des pages CMS en cards, bouton "Modifier", message si table vide.
  7. **`templates/admin/pages/edit.html.twig`** (nouveau) : formulaire avec EasyMDE (CDN), champs `sousTitre` + `contenu`, CSRF.
  8. **`src/Controller/Vitrine/NumeriquePagesController.php`** : ajout `use PageContenuRepository`, injection dans `formation()` et `projetSportEtude()` → passage de `pageContenu` au template.
  9. **`templates/vitrine/club/projet_sport_etude.html.twig`** : sous-titre `page-header` dynamique (`pageContenu.sousTitre` ou fallback statique), bloc `{% if pageContenu.contenu %}` + `.article-content|markdown_to_html` inséré avant le CTA.
  10. **`templates/vitrine/formation/index.html.twig`** : même logique — sous-titre dynamique + bloc CMS avant section `.formation-cta`.
  11. **`templates/vitrine/base.html.twig`** : ajout bouton "Pages" (`admin_pages_list`) dans la navbar admin entre Articles et Rôles.
- Fichiers créés/modifiés :
  - importmap.php (entries EasyMDE invalides supprimées)
  - src/Entity/Vitrine/PageContenu.php (nouveau)
  - src/Repository/Vitrine/PageContenuRepository.php (nouveau)
  - src/DataFixtures/PageContenuFixtures.php (nouveau)
  - src/Controller/Admin/AdminPagesController.php (nouveau)
  - templates/admin/pages/index.html.twig (nouveau)
  - templates/admin/pages/edit.html.twig (nouveau)
  - src/Controller/Vitrine/NumeriquePagesController.php (modifié)
  - templates/vitrine/club/projet_sport_etude.html.twig (modifié)
  - templates/vitrine/formation/index.html.twig (modifié)
  - templates/vitrine/base.html.twig (modifié)
- Commandes à lancer localement :
  ```
  php bin/console cache:clear
  php bin/console doctrine:migrations:diff
  php bin/console doctrine:migrations:migrate --no-interaction
  php bin/console doctrine:fixtures:load --append --no-interaction
  ```
- Décisions :
  - Le contenu CMS est **additif** : il s'affiche en section dédiée sur la page, sous le contenu statique existant. Le contenu statique Twig n'est pas supprimé — approche sûre et non destructive.
  - Pas de route `/numerique` existante → fixture 'numerique' créée pour préparer l'avenir, pas branchée dans un template pour l'instant.
  - La fixture intègre un guard anti-doublon (`findOneBy` avant persist) pour être safe avec `--append`.
- Points de vigilance :
  - La migration `doctrine:migrations:diff` doit détecter la nouvelle table `page_contenu` et générer une migration propre.
  - Si `doctrine:fixtures:load --append` plante : vérifier que la table `page_contenu` existe en DB (la migration a bien tourné).
  - La page `numerique` n'a pas de route dédiée dans le projet pour l'instant — la fixture est présente, à brancher quand la page sera créée.
  - `league/commonmark` doit être dans `vendor/` — vérifier avec `composer show league/commonmark`.
  - `twig/markdown-extra` est également requis pour le filtre `|markdown_to_html` — sans lui, `SyntaxError` au rendu des pages Formation/Projet. Commande : `composer require twig/markdown-extra`.

---

### 2026-03-19 (session 26) — Back-office upload médias + galerie dynamique
- Objectif : créer le back-office d'upload photos + rendre la galerie publique dynamique avec lightbox
- Actions réalisées :
  1. **`src/Controller/Admin/AdminMediasController.php`** (nouveau) : `#[Route('/admin/medias')]`, 3 méthodes :
     - `index()` `GET` → liste tous les médias triés `createdAt DESC`
     - `upload()` `POST` → CSRF `media_upload`, multi-upload `photos[]`, filtre extensions (jpg/jpeg/png/webp/gif), slug + uniqid pour nom fichier, move vers `public/uploads/galerie/`, persist entité `Media` par fichier, flash `N photo(s) uploadée(s)`
     - `delete()` `POST` → CSRF `delete_media_{id}`, suppression fichier physique via `unlink()`, `$em->remove()`, flash success
     - `denyAccessUnlessGranted('ROLE_SUPER_ADMIN')` sur les 3 méthodes
  2. **`templates/admin/medias/index.html.twig`** (nouveau) : formulaire upload multi-fichiers + grille photos 4 colonnes avec thumbnail, légende tronquée, bouton suppression CSRF par photo
  3. **`src/Controller/Vitrine/AccueilController.php`** : ajout `use MediaRepository` + réécriture `galerie()` — passe `medias` (48 images max via `findImages(48)`) au template
  4. **`templates/vitrine/accueil/galerie.html.twig`** : remplacement du bloc statique (1 photo réelle + 11 placeholders + card "Photos à venir") par contenu dynamique :
     - `{% if medias|length > 0 %}` → grille 4 colonnes + lightbox par photo (div fixe `z-index:9999`, `onclick` toggle display)
     - `{% else %}` → card "Photos à venir" avec liens Instagram/Facebook (comportement identique à l'ancien placeholder)
  5. **`templates/vitrine/base.html.twig`** : ajout bouton "Galerie" (`admin_medias_list`) dans la navbar admin entre Pages et Rôles
  6. **`public/uploads/galerie/.gitkeep`** (nouveau) : dossier créé + tracé Git
- Fichiers créés/modifiés :
  - src/Controller/Admin/AdminMediasController.php (nouveau)
  - templates/admin/medias/index.html.twig (nouveau)
  - src/Controller/Vitrine/AccueilController.php (use + galerie() modifiés)
  - templates/vitrine/accueil/galerie.html.twig (bloc dynamique + lightbox)
  - templates/vitrine/base.html.twig (bouton Galerie)
  - public/uploads/galerie/.gitkeep (nouveau)
- Commande à lancer :
  ```
  php bin/console cache:clear
  php bin/console debug:router | findstr admin_medias
  ```
- Décisions :
  - Lightbox sans librairie externe : `onclick` inline + `position:fixed` + `z-index:9999` — suffisant pour un usage asso, évite d'ajouter une dépendance JS
  - Upload multi-fichiers : `name="photos[]"` → normalisé en tableau dans le controller (un ou plusieurs fichiers)
  - Légende partagée pour tout un upload batch — acceptable pour un back-office simple
  - `$fichier->getSize()` est appelé AVANT `move()` — après le move le fichier n'est plus accessible
- Points de vigilance :
  - `public/uploads/galerie/` doit être accessible en écriture par le serveur (Laragon/PHP)
  - En prod, ce dossier doit être exclu du déploiement git (`.gitignore`) sauf le `.gitkeep`
  - `findImages(48)` est définie dans `MediaRepository` — vérifier qu'elle filtre bien `type = 'image'`
  - La navbar admin est maintenant à 4 boutons (Articles / Pages / Galerie / Rôles) — sur mobile petit écran, peut dépasser. À surveiller visuellement.

---

### 2026-03-19 (session 27) — Corrections couleurs : filtres membres + boutons news/article/accueil
- Objectif : 6 modifications ciblées sur les couleurs de 3 éléments UI
- Actions réalisées :
  1. **`.btn-filtre-mabb` migré** de `base.html.twig` (inline `<style>`) vers `assets/styles/vitrine.css` — nouvelles valeurs :
     - Inactif : `color: #ff8c00`, `border: 1.5px solid rgba(255,255,255,.5)`, `background: transparent`
     - Hover : `border-color: #ff8c00`, fond `rgba(255,140,0,.08)` (léger)
     - Actif : `background: #0b4fa3`, `border-color: #0b4fa3`, `color: #fff`, `box-shadow` bleu
  2. **`templates/vitrine/accueil/news.html.twig`** :
     - Titre article : `style="color:#063a55"` → classe `text-mabb-blue`
     - Bouton "Lire l'article" : `btn-outline-mabb` → `style="background:#ff8c00;color:#fff;border:none"`
  3. **`templates/vitrine/accueil/article.html.twig`** :
     - Bouton "Modifier cet article" : `btn-outline-warning` → `style="background:#0b4fa3;color:#fff;border:none"`
  4. **`templates/vitrine/accueil/index.html.twig`** :
     - Bouton "Voir tout" : `btn-outline-mabb text-white-force` → `style="background:#ff8c00;color:#fff;border:none"`
  5. Modification 5 (page-header article) : RAS — texte déjà blanc sur fond bleu, aucun changement
- Fichiers modifiés :
  - assets/styles/vitrine.css (`.btn-filtre-mabb` ajouté en fin de fichier)
  - templates/vitrine/base.html.twig (bloc `.btn-filtre-mabb` supprimé du `<style>` inline)
  - templates/vitrine/accueil/news.html.twig (titre + bouton Lire l'article)
  - templates/vitrine/accueil/article.html.twig (bouton Modifier)
  - templates/vitrine/accueil/index.html.twig (bouton Voir tout)
- Commandes à lancer :
  ```
  php bin/console cache:clear
  ```
- Décisions :
  - `.btn-filtre-mabb` déplacé dans `vitrine.css` (fichier compilé par Asset Mapper) plutôt que `base.html.twig` — plus propre, cohérent avec la session 21 d'extraction CSS
  - Boutons oranges/bleus avec `style=""` inline conservés comme demandé — pas de nouvelle classe CSS pour 3 boutons ponctuels
- Points de vigilance :
  - `text-mabb-blue` sur le titre article doit être défini (soit dans vitrine.css soit dans base.html.twig) — si la classe n'existe pas, le titre sera noir. Vérifier à l'affichage.

---

### 2026-03-22 (session 29) — Nos Réseaux + Partenaires + Team 3x3 + CSS
- Objectif : Nouvelle page /nos-reseaux style carrd, bande logos partenaires, Team 3x3 MABB, bandeau sport-études U18, CSS backgrounds orange
- Actions réalisées :
  - TÂCHE 1 : Route `vitrine_nos_reseaux` ajoutée dans `NumeriquePagesController.php`
  - TÂCHE 2 : `templates/vitrine/nos_reseaux/index.html.twig` créé (style carrd, 8 boutons réseaux)
  - TÂCHE 3 : Bouton "Nos réseaux" ajouté dans le hero accueil (glassmorphism)
  - TÂCHE 4 : Bande logos partenaires ajoutée dans `base.html.twig` avant le footer (10 logos)
  - TÂCHE 5A : Card "Team 3x3 MABB" ajoutée après le `{% endfor %}` dans equipes.html.twig (Ugo + Clavel + placeholder)
  - TÂCHE 5B : Bandeau sport-études U18 ajouté via `{% if eq.cat == 'U18 Féminine' %}` dans la boucle
  - TÂCHE 6 : Classes `.bg-orange-1`, `.bg-orange-2`, `.bg-2` ajoutées à `app.css` (extensions .png)
  - TÂCHE 7 : Dossier `public/images/partenaires/` créé + lien "Réseaux" navbar
  - FIX : `.btn-filtre-mabb` en double supprimé de `app.css` (conflit avec vitrine.css)
  - FIX : Images renommées — 20→bg-orange1.png, 21→bg-orange2.png, 22→bg2.png, 1-19→logo1-19.png
- Fichiers modifiés :
  - `src/Controller/Vitrine/NumeriquePagesController.php`
  - `templates/vitrine/nos_reseaux/index.html.twig` (nouveau)
  - `templates/vitrine/accueil/index.html.twig`
  - `templates/vitrine/base.html.twig`
  - `templates/vitrine/accueil/equipes.html.twig`
  - `assets/styles/app.css`
  - `public/images/` (renommages)
  - `public/images/partenaires/` (dossier créé)
- Points de vigilance :
  - Logos partenaires à déposer dans `public/images/partenaires/` (noms exacts requis)
  - Les images bg sont en `.png` pas `.jpg` (les fichiers uploadés étaient des PNG)
  - `php bin/console cache:clear` requis

---

### 2026-03-22 (session 30) — Page 3x3 + restructuration équipes + logos partenaires
- Objectif : Nouvelle page /equipes/3x3, dropdown navbar Équipes, restructuration equipes.html.twig, logos partenaires identifiés et copiés
- Actions réalisées :
  - LOGOS : Identification visuelle des 19 PNG — 10 copiés dans partenaires/ avec noms exacts
    logo9→republique-francaise, logo15→service-civique, logo1→club-formateur, logo2→ecole-francaise-basket,
    logo3→ffbb-citoyen, logo4→micro-basket, logo5→quartiers2030, logo6→cites-educatives,
    logo8→clubs-sportifs-engages, logo19→amiens-metropole
    Logos supplémentaires non utilisés : Gouvernement, Ministère Éducation, Préfet HDF, Préfet Somme ANCT, Académie Amiens, ANS, Région HDF, Conseil Départemental Somme, Quartiers d'été
  - TÂCHE 1 : Route `vitrine_equipes_3x3` ajoutée dans AccueilController.php
  - TÂCHE 2 : `templates/vitrine/accueil/equipes_3x3.html.twig` créé (Ugo, Clavel, placeholder, 5 catégories)
  - TÂCHE 3 : Navbar Équipes → dropdown "Toutes les équipes" + "Équipe 3x3"
  - TÂCHE 4A : Card "Team 3x3 MABB" supprimée de equipes.html.twig
  - TÂCHE 4B : Bouton "Voir l'équipe 3x3 →" ajouté dans la card Basket 3x3 (conditionnel if)
  - TÂCHE 4C : Bandeau U18 sport-études remplacé par lien cliquable vers /projet-sport-etude
  - TÂCHE 5 : Bouton "Projet Sport-Études →" supprimé de cite_educative.html.twig card Lycée
- Fichiers modifiés :
  - `src/Controller/Vitrine/AccueilController.php`
  - `templates/vitrine/accueil/equipes_3x3.html.twig` (nouveau)
  - `templates/vitrine/base.html.twig`
  - `templates/vitrine/accueil/equipes.html.twig`
  - `templates/vitrine/club/cite_educative.html.twig`
  - `public/images/partenaires/` (10 logos copiés)
- Points de vigilance :
  - `php bin/console cache:clear` requis
  - 9 logos supplémentaires restent dans public/images/ sans correspondance dans la bande partenaires

---

### 2026-03-22 (session 31) — TinyMCE + CMS Pages enrichi + AdminUploadController
- Objectif : Remplacer EasyMDE par TinyMCE WYSIWYG, enrichir le CMS Pages (image couverture + palette couleurs), créer le endpoint upload image TinyMCE, étendre les fixtures à 13 pages, injecter pageContenu dans tous les contrôleurs vitrine
- Actions réalisées :
  - ÉTAPE 1 : `PageContenu` entity — ajout champs `imagePath` (length:255, nullable) + `couleurTexte` (length:20, nullable) + getters/setters
  - ÉTAPE 2 : `PageContenuFixtures.php` — 3 pages → 13 pages (accueil, club, equipes, equipes-3x3, membres, formation, cite-educative, projet-sport-etude, numerique, calendrier, galerie, nos-reseaux, contact). Anti-doublon `findOneBy` conservé.
  - ÉTAPE 3 : `AccueilController.php` — `PageContenuRepository` injecté dans index(), club(), equipes(), equipes3x3(), galerie(), calendrier(), numerique(), contact(). Variable `pageContenu` passée dans toutes les vues.
  - ÉTAPE 4 : `AdminPagesController.php` — `SluggerInterface` injecté, upload image vers `public/uploads/pages/`, `setCouleurTexte()` ajouté, `enctype="multipart/form-data"` géré côté contrôleur.
  - ÉTAPE 5A : `templates/admin/articles/form.html.twig` — EasyMDE → TinyMCE 7 CDN, `images_upload_handler` custom (appel `/admin/upload-image`), `images_upload_url` configuré.
  - ÉTAPE 5B+6 : `templates/admin/pages/edit.html.twig` — EasyMDE → TinyMCE 7 CDN, champ `imagePage` (file upload), palette 6 couleurs MABB interactive (radio pill buttons avec JS), form `enctype="multipart/form-data"`.
  - ÉTAPE 7 : `src/Controller/Admin/AdminUploadController.php` créé — route POST `/admin/upload-image` (ROLE_ADMIN), validation MIME, SluggerInterface, déplace vers `public/uploads/tinymce/`, retourne `{"location": "..."}`.
  - ÉTAPE 8 : 4 templates vitrine — `|markdown_to_html` → `|raw` dans projet_sport_etude.html.twig et formation/index.html.twig. Bloc CMS `{{ pageContenu.contenu|raw }}` ajouté dans numerique.html.twig et cite_educative.html.twig. `NumeriquePagesController::citeEducative()` mis à jour pour injecter pageContenu.
  - ÉTAPE 9 : Dossiers créés : `public/uploads/pages/` + `public/uploads/tinymce/` (avec `.gitkeep`).
- Fichiers modifiés :
  - `src/Entity/Vitrine/PageContenu.php`
  - `src/DataFixtures/PageContenuFixtures.php`
  - `src/Controller/Vitrine/AccueilController.php`
  - `src/Controller/Vitrine/NumeriquePagesController.php`
  - `src/Controller/Admin/AdminPagesController.php`
  - `src/Controller/Admin/AdminUploadController.php` (nouveau)
  - `templates/admin/articles/form.html.twig`
  - `templates/admin/pages/edit.html.twig`
  - `templates/vitrine/club/projet_sport_etude.html.twig`
  - `templates/vitrine/formation/index.html.twig`
  - `templates/vitrine/accueil/numerique.html.twig`
  - `templates/vitrine/club/cite_educative.html.twig`
  - `public/uploads/pages/.gitkeep` (nouveau)
  - `public/uploads/tinymce/.gitkeep` (nouveau)
- Décisions :
  - TinyMCE 7 CDN sans API key (dev : OK illimité, prod : 1000 loads/mois plan gratuit — à surveiller)
  - `|raw` au lieu de `|markdown_to_html` car TinyMCE génère du HTML pur (pas du Markdown)
  - Palette couleurs : radio buttons cachés + pills cliquables JS (pas de dépendance externe)
  - `findBySlug()` utilisé dans NumeriquePagesController (méthode custom déjà dans le repo)
- Commandes à exécuter sur le projet (dans PowerShell) :
  ```
  php bin/console doctrine:migrations:diff
  php bin/console doctrine:migrations:migrate
  php bin/console doctrine:fixtures:load --append
  php bin/console cache:clear
  ```
- Points de vigilance :
  - TinyMCE affiche un bandeau "no-api-key" en dev — normal, inoffensif
  - Les images uploadées via TinyMCE (tinymce/) et pages (pages/) ne sont PAS supprimées automatiquement si la page/entité est supprimée — nettoyage manuel à prévoir
  - `|raw` = XSS possible si un utilisateur non admin peut éditer — ici ROLE_SUPER_ADMIN uniquement → OK
  - Le champ `couleurTexte` n'est pas encore utilisé dans les templates vitrine (page-header) — à brancher dans une session ultérieure si besoin

---

### 2026-03-22 (session 32) — Mise à jour prénoms "Et bien d'autres..."
- Objectif : Remplacer les 7 prénoms fictifs dans la section "Et bien d'autres..." de la page /formation
- Actions réalisées :
  - `templates/vitrine/formation/index.html.twig` : `['Sarah', 'Kylian', 'Amina', 'Lucas', 'Fatou', 'Théo', 'Inès']` → `['Sofyan', 'Celyan', 'Inès', 'Laryssa', 'Leny', 'Maxence', 'Tony']`
- Fichiers modifiés :
  - `templates/vitrine/formation/index.html.twig`
- Décisions : aucune
- Commande : `php bin/console cache:clear`

---

### 2026-03-22 (session 33) — Quill.js + corrections CMS
- Objectif : Remplacer TinyMCE par Quill.js (sans clé API, sans popup), corriger le champ image pages, insérer les 10 pages manquantes en SQL, passer article.html.twig en |raw
- Actions réalisées :
  - FIX 1A : `templates/admin/articles/form.html.twig` — TinyMCE → Quill 1.3.6 CDN. Textarea caché (`display:none`), div `#quill-editor` visible, sync via `form submit` event. Palette couleurs MABB dans toolbar.
  - FIX 1B : `templates/admin/pages/edit.html.twig` — même migration TinyMCE → Quill. Div `#quill-page`, textarea `#pageContenu` caché.
  - FIX 2 : `templates/vitrine/accueil/article.html.twig` — `|markdown_to_html` → `|raw` (nécessaire car articles désormais en HTML Quill). Les 4 templates CMS pages étaient déjà en `|raw` depuis session 31.
  - FIX 3 : SQL `INSERT IGNORE` fourni pour 10 pages manquantes — à exécuter via `php bin/console dbal:run-sql "..."` (accueil, club, equipes, equipes-3x3, membres, cite-educative, calendrier, galerie, nos-reseaux, contact).
  - FIX 4 : `name="imagePage"` → `name="image"` dans `pages/edit.html.twig` ET dans `AdminPagesController.php` (`$request->files->get('image')`).
- Fichiers modifiés :
  - `templates/admin/articles/form.html.twig`
  - `templates/admin/pages/edit.html.twig`
  - `templates/vitrine/accueil/article.html.twig`
  - `src/Controller/Admin/AdminPagesController.php`
- Décisions :
  - Quill 1.3.6 (stable, sans clé API, open source) préféré à TinyMCE 7 (popup "no-api-key")
  - `article.html.twig` passé en `|raw` : les anciens articles EasyMDE (Markdown) afficheront du Markdown brut — à re-éditer manuellement si besoin via l'admin
- Commandes à exécuter :
  ```
  # SQL INSERT IGNORE (10 pages manquantes)
  php bin/console dbal:run-sql "INSERT IGNORE INTO ..."
  php bin/console cache:clear
  ```
- Points de vigilance :
  - Les articles créés AVANT la migration (EasyMDE/Markdown) devront être réédités et sauvegardés via le nouvel éditeur Quill pour s'afficher correctement
  - Quill 1.3.6 : upload d'images via bouton image → insère en base64 dans le HTML (pas un vrai upload fichier). Pour les gros articles, préférer uploader l'image séparément et coller l'URL.

---

### 2026-03-22 (session 34) — Navbar Équipes simplifiée + breadcrumb 3x3
- Objectif : Retirer le dropdown Équipe 3x3 de la navbar, ajouter le lien Équipe 3x3 dans le breadcrumb de /equipes
- Actions réalisées :
  - `templates/vitrine/base.html.twig` : dropdown Équipes (2 items) → lien direct `<a>` vers `vitrine_equipes`
  - `templates/vitrine/accueil/equipes.html.twig` : ajout `<li class="breadcrumb-item">` avec lien orange vers `vitrine_equipes_3x3`
- Fichiers modifiés :
  - `templates/vitrine/base.html.twig`
  - `templates/vitrine/accueil/equipes.html.twig`
- Décisions : aucune

---

### 2026-03-26 (session 37) — Fix connexion/www, h2 3x3 blanc, bouton 3x3, diagnostic mailer

- Objectif : Corriger boutons connexion/inscription inactifs sur www.mabb.fr, mettre h2 en blanc sur page 3x3, réintégrer lien 3x3, vérifier envoi email contact
- Actions réalisées :
  1. **Fix firewall vitrine** — `config/packages/security.yaml` : regex host ajoutée `www\.mabb\.fr`. Sans ce fix, le firewall Symfony ne s'activait pas sur `www.mabb.fr`, donc `form_login` n'interceptait pas le POST → boutons connexion/inscription inactifs.
  2. **Fix h2 3x3 blanc** — `templates/vitrine/accueil/equipes_3x3.html.twig` : `style="color:#fff"` sur `<h2 class="section-title">` de la section "3x3 dans les catégories".
  3. **Réintégration lien 3x3** — `templates/vitrine/accueil/equipes.html.twig` : lien vers `vitrine_equipes_3x3` réintégré comme bouton orange sous le breadcrumb (était dans le breadcrumb en session 34 — mauvaise pratique sémantique, maintenant en bouton distinct).
  4. **Diagnostic mailer contact** — `.env` a `MAILER_DSN=null://null` → aucun email envoyé. Le formulaire affiche une popup "envoyé" mais c'est du bluff si aucun `.env.local` avec un vrai DSN SMTP n'est présent sur le serveur OVH. À configurer par l'utilisateur (voir point vigilance).
- Fichiers modifiés :
  - `config/packages/security.yaml`
  - `templates/vitrine/accueil/equipes_3x3.html.twig`
  - `templates/vitrine/accueil/equipes.html.twig`
- Décisions : aucune ADR (corrections bugs + config)
- Points de vigilance :
  - **MAILER À CONFIGURER** : Créer/éditer `~/mabb-site/.env.local` sur le serveur OVH avec le DSN SMTP OVH. Format : `MAILER_DSN=smtp://contact%40mabb.fr:MOT_DE_PASSE@ssl0.ovh.net:465`. Sans ça, zéro email envoyé depuis le formulaire contact.
  - Push git + déploiement doivent être faits depuis le terminal local (credentials GitHub HTTPS non disponibles en sandbox).

---

### 2026-03-26 (session 39) — Revert photo-stack, photo simple panierGonflable

- Objectif : Supprimer le stack animé 3 photos (layout cassé), revenir à une photo unique propre
- Actions réalisées :
  1. `index.html.twig` — section "Un club engagé" : remplacement `.photo-stack` par `<img>` unique `panierGonflable.jpeg` avec `border:4px solid #fff`, `border-radius:16px`, `box-shadow:0 8px 24px rgba(0,0,0,.3)`
  2. `assets/styles/vitrine.css` — suppression du bloc `.photo-stack` (40 lignes)
- Fichiers modifiés : `templates/vitrine/accueil/index.html.twig`, `assets/styles/vitrine.css`
- Commit : `2bc6141`
- Points de vigilance : push depuis terminal local requis

---

### 2026-03-26 (session 38) — Contenu PDF + animations + nouvelles pages

- Objectif : Intégrer les données des PDFs (Bilan 2025 + PV AG 2026), animer la home, créer la page Nos Victoires, enrichir la Cité Éducative
- Actions réalisées :
  1. **Encadrement complet** — `equipes.html.twig` : 4 coaches (COULPIED, DUFOSSE, HARTHATI, NDEMA MOUSSA) + 7 services civiques réels (DAOUDI, GUELFAT, YAHIAOUI, BADIBALOWA, PARFAIT, KOUDJIL, ATSHABO photographe)
  2. **Chiffres home** — animation compteur 0→373 (IntersectionObserver + easeOutCubic), 3 autres chiffres statiques restaurés (Labels FFBB, Quartiers, Ans)
  3. **Nouvelle page /victoi
## 2026-07-09 — Lot 2b-2 : création de club (multi-club)
Route PUBLIQUE `GET/POST /creer-un-club` (host manager). N'importe qui crée un club ;
si anonyme → compte User créé (ROLE_DIRIGEANT plateforme) ; le créateur devient
admin de SON club via UserClubRole DIRIGEANT STATUS_ACTIVE (droits via ClubVoter).
Officialisation auto : isOfficiel posé par ClubOfficialisation selon le n° FFBB
(référentiel organisme_ffbb). Anti-doublon slug + numéro FFBB. Connexion programmatique
(Security::login firewall 'manager') + setCurrentClub → redirect dashboard.
Fichiers : security.yaml (+1 règle PUBLIC_ACCESS avant catch-all),
src/Controller/Manager/ManagerCreerClubController.php (NEW),
templates/manager/creer_club.html.twig (NEW), templates/manager/login.html.twig (liens).
PAS de migration (colonnes club déjà en base via Version20260709124204). cache:clear requis (secu).
Reste : import doc FFBB (rencontres + trombinoscope) en UI Manager ; officialiser MABB (HDF0080036).
wig` (nouveau)
  - `templates/vitrine/club/cite_educative.html.twig`
  - `templates/vitrine/navbar.html.twig`
  - `src/Controller/Vitrine/AccueilController.php`
- Commits : `07fd0d1`, `039a573`, `fa513f4`, `35c53ee`, `7429861`, `0008f93`
- Points de vigilance :
  - **9 commits en attente de push** — faire `git push origin main` depuis le terminal local
  - Push HTTPS GitHub nécessite credentials (Personal Access Token) — non disponible en sandbox
  - Déploiement OVH ensuite : `ssh mabbzzyo@ssh.cluster102.hosting.ovh.net "cd ~/mabb-site && git pull && php bin/console cache:clear --env=prod"`

---

### 2026-03-26 (session 36) — Audit visuel mabb.fr + corrections bugs

- Objectif : Analyser le site live, corriger bugs connus (routes localhost, placeholder image) + bugs trouvés lors de l'audit
- Actions réalisées :
  1. **Fix routes** — suppression `host`/`defaults`/`requirements` dans `config/routes/vitrine.yaml` et bloc `admin_controllers` de `config/routes.yaml`. Ces contraintes forçaient Symfony à générer des URLs absolues avec `localhost` comme host par défaut sur le serveur OVH.
  2. **Fix placeholder index** — `templates/vitrine/accueil/index.html.twig` : remplacement du bloc `.photo-placeholder` par `<img src="images/teamsU1.jpg">` dans la section "Un club engagé pour le basket féminin".
  3. **Fix placeholder club** — `templates/vitrine/accueil/club.html.twig` : même correction, image teamsU1.jpg.
  4. **Fix breadcrumb equipes** — `templates/vitrine/accueil/equipes.html.twig` : suppression du `<li>` breadcrumb "Équipe 3x3". NOTE : ce lien avait été ajouté intentionnellement en session 34. Sa suppression est justifiée par la sémantique breadcrumb (un breadcrumb représente le chemin VERS la page courante, pas vers ses enfants). Si tu veux le conserver comme raccourci, réintroduis-le comme un bouton ou badge distinct du breadcrumb.
  5. **Ajout image** — `public/images/teamsU1.jpg` ajouté au repo git.
  6. **Audit visuel** — pages visitées : accueil, club, membres, équipes, news, contact, galerie, calendrier. Aucun autre bug fonctionnel détecté. La page galerie affiche "Photos à venir" (intentionnel). Articles de test visibles (intentionnel).
- Fichiers modifiés :
  - `config/routes/vitrine.yaml`
  - `config/routes.yaml`
  - `templates/vitrine/accueil/index.html.twig`
  - `templates/vitrine/accueil/club.html.twig`
  - `templates/vitrine/accueil/equipes.html.twig`
  - `public/images/teamsU1.jpg` (nouveau fichier)
- Décisions : aucune ADR nécessaire (corrections de bugs, pas d'architecture)
- Points de vigilance :
  - Le push git + déploiement SSH doit être fait manuellement depuis le terminal (credentials HTTPS GitHub non disponibles dans la sandbox Cowork). Commandes : `git push` puis SSH OVH `git pull && php bin/console cache:clear --env=prod`
  - Le breadcrumb 3x3 a été retiré — voir note ci-dessus si souhait de le réintégrer autrement

---

### 2026-03-22 (session 35) — Quill pages amélioration + Roadmap CMS V2
- Objectif : Améliorer le bloc Quill dans pages/edit.html.twig (variable quillPage, background, 320px), documenter la vision CMS V2 dans la roadmap
- Actions réalisées :
  - `templates/admin/pages/edit.html.twig` : variable `quill` → `quillPage`, hauteur 300→320px, ajout option `background` avec 5 couleurs MABB, CSS selector simplifié
  - `instruction/04_ROADMAP_V2.md` : ajout section "CMS Vitrine V2 — Super Admin Total" avec 4 niveaux (textes/sections/nav/médias), entités cibles, priorités
- Fichiers modifiés :
  - `templates/admin/pages/edit.html.twig`
  - `instruction/04_ROADMAP_V2.md`
- Décisions : architecture V2 retenue = blocs JSON dans PageContenu (évite une nouvelle entité en V2 early), migration vers SectionPage en V2 mature
