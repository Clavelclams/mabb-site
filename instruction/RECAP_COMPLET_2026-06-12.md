# 📊 RÉCAP COMPLET MABB / PIRB — 12/06/2026

> Fait après 26h de combat sur le déploiement + import FFBB.
> Source : audit code + tous les docs (CDC V5, docx techniques, retours Willy, retours Clavel).
> Ce fichier est la **source de vérité unique** pour savoir où on en est et ce qui reste.

---

## I. 📐 État brut au 12/06/2026

| Métrique | Valeur |
|---|---|
| **Entités Doctrine** | **37** (Core: 6 · Sport: 28 · Vitrine: 3) |
| **Contrôleurs** | **~50** (Manager: 29 · PIRB: 9 · Vitrine: 5 · Admin: 8) |
| **Services métier** | **25** |
| **Migrations BDD** | **38** (jusqu'à `Version20260610140000`) |
| **Templates Twig** | ~120 |
| **Routes** | ~200 |
| **Commands console** | **5** (CreateAdmin, LoadFixturesManual, Puml, **app:import-rencontres**, **app:import-pdfs-ffbb**) |
| **Tests PHPUnit** | 14 fichiers (~60 méthodes) |
| **Sprint B1-B22 livrés** | 12 features + 18 rencontres importées + 51 PDFs FFBB en BDD |

---

## II. ✅ Tout ce qui est LIVRÉ par module

### 🌐 A. VITRINE — mabb.fr (publique)

| Feature | Statut | Fichier |
|---|---|---|
| Page d'accueil | ✅ | `Vitrine/AccueilController` + 26 templates |
| Page Club / organigramme | ✅ | `EmployesController` |
| Page Équipes | ✅ | template |
| Page Calendrier | ✅ | `Vitrine/PlanningController` |
| Page Cité Éducative | ✅ | template |
| Page Numérique | ✅ | `NumeriquePagesController` |
| Page Victoires | ✅ | template |
| Compte vitrine | ✅ | `CompteController` |
| Triple label FFBB affiché | ✅ | accueil |
| Actualités (Articles CMS) | ✅ | `Article` entité + `AdminArticlesController` |
| Pages dynamiques (CMS) | ✅ | `PageContenu` + `AdminPagesController` |
| Médiathèque (CMS) | ✅ | `Media` + `AdminMediasController` |

### 🏀 B. MANAGER — manager.mabb.fr

#### B.1 Auth & sécurité
| Feature | Statut |
|---|---|
| Login/Logout multi-firewall (7 firewalls) | ✅ |
| Inscription RGPD avec consentement | ✅ |
| **Reset password** (token sha256 + Mailer) | ✅ **B1** |
| **Logs connexion** (succès + échecs + anti-brute-force) | ✅ **B2** |
| **RGPD droit à l'oubli** (anonymisation + export JSON) | ✅ **B2** |
| Multi-tenant strict via `ClubVoter` + `TenantResolver` | ✅ |
| CSRF sur toutes routes destructives | ✅ |
| Voters (`ClubVoter`, `NoteFraisVoter`, `TresorerieVoter`) | ✅ |

#### B.2 Gestion sportive
| Feature | Statut |
|---|---|
| Équipes CRUD + composer effectif | ✅ |
| Joueurs CRUD complet | ✅ |
| Trombinoscope FFBB import (parser FFBB) | ✅ |
| Catégories FFBB (U11, U13, U15, U18, Sénior) | ✅ |
| **Coach↔Équipe** (table jointure + service `EstMonCoach`) | ✅ **B5** |
| **Lien User↔Joueur** (matcher par licence FFBB) | ✅ |
| **Parent↔Joueur** + validation Manager | ✅ |
| Page **Staff** (regroupement Coach/Dirigeant/Trésorier) | ✅ |
| Fiche coach détaillée (équipes + séances) | ✅ |

#### B.3 Événements
| Feature | Statut |
|---|---|
| Séances CRUD + planning récurrent (`GenerateurSeancesService`) | ✅ |
| Rencontres CRUD avec score, lieu, statut workflow | ✅ |
| **Champs FFBB sur Rencontre** : numéro match, code e-Marque, saison, division, forfait | ✅ **B19** |
| Convocations rencontres | ✅ |
| Présences (pointage séance + rencontre) | ✅ |
| Évaluations match (saisie manuelle par joueuse) | ✅ |
| Événements génériques + participations + missions liées | ✅ |
| Mission "Spectateur présent" + 5 badges spectateur | ✅ |
| **Inscription rôles officiels bloquée si match passé** (+ 4h marge) | ✅ **B22** |

#### B.4 Stats Live (Easy Stats clone)
| Feature | Statut |
|---|---|
| SessionStatsLive (multi-auteurs sur même match) | ✅ |
| ActionMatch (chronologique) | ✅ |
| PresenceTerrain (gestion 5 sur terrain + remplaçantes) | ✅ |
| ShotChartCalculator (coordonnées X/Y SMALLINT %) | ✅ |
| Promotion d'une session en STATS OFFICIELLES | ✅ |
| Workflow brouillon → complète → officielle → archivée | ✅ |

#### B.5 Bureau
| Feature | Statut |
|---|---|
| Réunions (CA, AG, Bureau) | ✅ |
| Convocations CA avec PDF + mailing | ⏸️ (mailer Brevo non configuré prod) |
| PV versionnés | ✅ |
| Documents joints réunion | ✅ |
| Réunions publiques + feed "Pour toi" | ✅ |

#### B.6 Trésorerie
| Feature | Statut |
|---|---|
| Opérations + justificatifs upload | ✅ |
| Cotisations + tarifs par âge/catégorie | ✅ |
| Notes de frais (workflow validation) | ✅ |
| Subventions (suivi état + montant touché) | ✅ |
| Export CSV | ✅ |

#### B.7 Gamification
| Feature | Statut |
|---|---|
| Missions (11 types dont SPECTATEUR) | ✅ |
| BadgeCatalog (28+ badges) | ✅ |
| **Axe A** Régularité (11 badges) | ✅ |
| **Axe B** Performance basket (+10 badges B18) | ✅ **B18** |
| **Axe C** Vie de club (6+5 spectateur badges) | ✅ |
| **Axe D** Performance employé (6 badges) | ✅ |
| XpCalculator + BadgeChecker (idempotent) | ✅ |

#### B.8 Import FFBB (B19+B20)
| Feature | Statut |
|---|---|
| **Command `app:import-rencontres`** depuis Excel FFBB | ✅ **B19** |
| **Command `app:import-pdfs-ffbb`** depuis dossier ressource | ✅ **B20** |
| 18 rencontres importées prod | ✅ |
| 51 PDFs FFBB copiés prod | ✅ (en cours) |

### 📱 C. PIRB — pirb.mabb.fr (espace joueuse)

| Feature | Statut |
|---|---|
| Dashboard mobile-first + drawer | ✅ |
| Édition profil (email perso, téléphone, urgence) | ✅ |
| Bio + Profil public toggle | ✅ |
| Liens sociaux (Instagram, TikTok, YouTube, X, LinkedIn) | ✅ |
| Photo profil upload | ✅ |
| Badges épinglés (3 max parmi 28+) | ✅ |
| Highlights vidéo (YouTube/Instagram/TikTok embed) | ✅ |
| Profil public consultable scout `/joueuse/{id}` | ✅ |
| Page Mon équipe (coéquipiers) | ✅ |
| **Mes enfants** (parent) + validation Manager | ✅ |
| **Convocations P/A/I** depuis PIRB | ✅ **B14** |
| **Feedback séances** (note 0-5 + commentaire + anonyme) | ✅ **B9** |
| **Stats perso saison** + détail match | ✅ **B10/B11** |
| **Bilan 4 axes** gamification visible | ✅ **B12** |
| Mes événements + Mes présences sur dashboard | ✅ |

### 🛡️ D. ADMIN — mabb.fr/admin

| Feature | Statut |
|---|---|
| Login admin (firewall séparé) | ✅ |
| CMS Articles | ✅ |
| CMS Pages dynamiques | ✅ |
| Médiathèque + upload | ✅ |
| Gestion rôles membres vitrine | ✅ |
| **Logs de connexion** (filtres + pagination) | ✅ **B2** |
| **Demandes RGPD** (validation/refus admin) | ✅ **B2** |

---

## III. 🚧 Ce qui RESTE à faire — backlog priorisé

### Sprint immédiat (avant rentrée septembre 2026)

| # | Bloc | Effort | Priorité | Note |
|---|---|---|---|---|
| 🔴 | **Fix 500 `/joueuses/{id}/missions/nouvelle`** | 1h | URGENT | bug user-facing |
| 🔴 | **Fix 404 `manager.mabb.fr/signup`** depuis PIRB | 0.5h | URGENT | routing |
| 🔴 | **Promouvoir will@mabb.fr DIRIGEANT** (#4) | 5min | URGENT | script SQL prêt |
| 🟠 | **B22a** PIRB : joueuse voit ses PDFs FFBB | 2-3h | P1 | RGPD : que les matchs où elle a joué |
| 🟠 | **Manager /utilisateurs : rolesMembre Vitrine en lecture** (#5) | 1-2h | P1 | demande Clavel ancienne |
| 🟠 | **B22b** Parser `resume.pdf` → stats individuelles BDD | 6-8h | P1 | base pour toggle FFBB/Live |
| 🟠 | **B28** Refonte Stats Live terrain horizontal + tracking spatial étendu + heatmap adverse | 17-23h | P1 | demande Clavel 12/06 — préparer phase retour |
| 🟡 | **Toggle FFBB / Stats Live** sur fiche match | 3-4h | P2 | dépend B22b |
| 🟡 | **B23** Match d'entraînement multi-catégorie | 5-7h | P2 | demande Clavel |
| 🟡 | **Activation Monolog prod** | 1h | P2 | déjà patché en local, déployer |
| 🟢 | **B8** Élection employé/SC du mois | 6-8h | P3 | demande Willy |
| 🟢 | **B7** rolesMembre vitrine en lecture | 1-2h | P3 | |
| 🟢 | **B6** Entité Saison dédiée | 3-4h | P3 | refacto |

---

## VIII. 🏀 Focus sur B28 — Refonte Stats Live (demande Clavel 12/06)

### Cible UX
**Layout actuel** : demi-terrain vertical → ne montre que le côté offensif
**Souhaité** : **terrain horizontal ENTIER** entre `div joueuses (gauche)` et `div actions (droite)`

→ Permet de tracker offense ET défense dans la même session.

### 3 sous-blocs progressifs

#### B28a — Refonte UI terrain horizontal (5-7h)
- Nouveau SVG terrain entier (28m x 15m → ratio 1.87)
- Conserver coordonnées X/Y en pourcentages 0-100 (compat existant)
- Layout 3 colonnes : joueuses ↔ terrain horizontal ↔ actions
- Réutilise les data existantes (`ActionMatch.positionX/Y`)
- Migration douce : les anciennes positions demi-terrain restent valides

#### B28b — Tracking spatial étendu (4-6h)
Aujourd'hui seuls les **tirs** ont positionX/Y. À étendre aux types :
- `TYPE_PERTE_BALLE` (où l'équipe perd la balle)
- `TYPE_REBOND_OFFENSIF` / `TYPE_REBOND_DEFENSIF` (où on prend les rebonds)
- `TYPE_PASSE_DECISIVE` (d'où viennent les passes assist)
- `TYPE_INTERCEPTION` (où on récupère la balle)
- `TYPE_CONTRE` / `TYPE_CONTRE_SUBI` (où on bloque / on se fait bloquer)

Modif `TYPES_AVEC_POSITION` const + UI qui demande clic terrain avant validation action.

#### B28c — Tracking actions ADVERSAIRES (8-10h)
Nouveau use-case : noter aussi les actions de l'équipe ADVERSE pendant le match.
- Nouvel onglet "Adversaire" dans Stats Live (toggle Mon équipe ⇄ Adverse)
- Mêmes actions trackées + position
- Stockage : ajouter champ `est_adverse` (bool) sur `ActionMatch`
- Migration BDD
- **Affichage HEATMAP** : page récap match avec heatmap des zones où l'adversaire marque/perd la balle
- Service `HeatmapCalculator` qui agrège positions et calcule densité par zone

**Bénéfice métier** : préparer la phase retour avec un scouting défensif chiffré. Le coach sait dire "Adversaire X marque à 70% depuis l'aile droite → on défend en zone forcée ailleurs".

### Effort cumulé : 17-23h (~3-4 jours dev focus)

### Prérequis avant de coder
1. Lock B22a + B22b d'abord (joueuse PDF + parser resume) car plus simples et débloquent stats FFBB
2. Confirmer maquette avec Clavel avant attaque B28a (esquisse SVG)
3. Garder rétro-compat : sessions Stats Live existantes ne doivent pas casser

---

## IX. 📚 Focus sur B29 — ENT complet (demande Clavel 12/06)

### Vision
ENT = Espace Numérique de Travail. Un seul module pour stocker, organiser et partager TOUS les documents du club avec permissions fines :
- **Séances d'entraînement** des coachs (partage entre coachs)
- **Certificats médicaux** des joueuses
- **Bulletins scolaires** de la Section Sportive (collège César Franck — ouverture sept 2026)
- **Documents administratifs** divers (statuts, PV, etc.)

### 6 sous-blocs progressifs

#### B29a — Entité Document + CRUD upload (4-5h)
- Entité `Document` : titre, fichier (pdf/photo/doc), uploader_id, club_id, taille, mime_type
- Service `DocumentUploader` (pattern existant pour Note de frais, Cotisation, etc.)
- Page liste `/documents` + upload + suppression
- Stockage `public/uploads/documents/{annee}/{club_id}/`

#### B29b — Catégories séances + filtres + recherche (3-4h)
- `Document.categorie` enum : SEANCE / CERTIF_MEDICAL / BULLETIN_SCOLAIRE / DOC_ADMIN / AUTRE
- Si SEANCE : `Document.theme_seance` enum : DRIBBLE / DEFENSE / ATTAQUE / FINITION / PASSE / TIR / TACTIQUE / PHYSIQUE / 3X3 / AUTRE
- Filtre UI multi-tags + recherche par titre
- Tri par date upload / popularité (téléchargements)

#### B29c — Permissions fines via DocumentVoter (3-4h)
- Champ `Document.visibilite` JSON : `{"coach": "view", "dirigeant": "edit", "joueur": "view", "parent": "none"}`
- `DocumentVoter` qui vérifie au cas par cas
- UI admin : choix de visibilité par rôle au moment de l'upload
- Défauts par catégorie :
  - SEANCE → coach+staff: edit, dirigeant: view, joueur: none par défaut
  - CERTIF_MEDICAL → staff+parent: view, **personne d'autre** (RGPD strict)
  - BULLETIN_SCOLAIRE → joueuse_concernee + parent + dirigeant: view
  - DOC_ADMIN → dirigeant: edit, staff: view

#### B29d — Partage externe par lien tokenisé (3-4h)
- `DocumentShareToken` : token sha256, expires_at (3-7 jours), max_clicks, document_id
- Route publique `/document/share/{token}` (pas d'auth requise)
- UI : bouton "Partager hors club" sur un document → génère un lien expiré
- Anti-spam : 1 token actif max par document à la fois
- Logs des accès (qui a téléchargé via le token)

#### B29e — Sous-module Bulletins scolaires Section Sportive (5-7h)
- `BulletinScolaire` entité : joueuse_id, periode (T1/T2/T3), annee_scolaire, file_path, moyenne_generale, notes_detaillees JSON
- Page `/section-sportive/bulletins/{joueuse_id}` accessible parent + staff
- Préparation API collège César Franck : structure prête à recevoir un JSON futur de Pronote/EcoleDirecte
- Service `BulletinImporter` (squelette) : `importFromJson(array $data): Bulletin`
- Affichage : courbe moyenne au fil des trimestres
- **CRUCIAL** : la section sportive ouvre en septembre 2026 → ce sous-bloc doit être prêt fin août

#### B29f — Sous-module Certificats médicaux (4-6h)
- Workflow : parent uploade le certif → staff valide → débloque l'inscription joueuse
- `CertificatMedical` : joueuse_id, file_path, date_emission, date_expiration, valide_par_staff
- Alerte automatique 30 jours avant expiration (cron + mail)
- RGPD strict : seuls staff + parent voient le fichier, joueuse elle-même non (sauf majeure)
- Anti-fuite : URL signée avec expiration courte

### Effort cumulé : 22-30h (~4-6 jours dev focus)

### Pourquoi 6 sous-blocs

Si on attaque tout d'un coup c'est trop gros. Le découpage permet :
- B29a + B29b ensemble = MVP utilisable en 1 semaine (coachs partagent séances avec tags)
- B29c rajoute la sécurité fine (V1.5)
- B29d ouvre au partage externe (utile pour partenaires)
- B29e arrive juste pour septembre (Section Sportive)
- B29f le plus délicat RGPD, à faire après quand les patterns sont stabilisés

### Ordre conseillé
1. B29a (CRUD basique)
2. B29b (catégories séance avec thèmes)
3. B29c (permissions fines)
4. B29e (bulletins — BLOCKER septembre)
5. B29d (partage externe)
6. B29f (certifs médicaux)

### Cible date
**Fin août 2026** pour B29a + B29b + B29c + B29e (MVP utilisable pour rentrée Section Sportive).

---

## X. 👨‍👩‍👧 Focus sur B30 — Liaison parent↔enfant étendue (Clavel 12/06 soir)

### Contexte
B24 a livré le système `ParentJoueur` : parent côté PIRB peut demander un lien vers une joueuse, staff valide. Clavel veut **3 nouveaux scénarios** pour fluidifier.

### 3 sous-blocs

#### B30a — Staff lie parent existant à joueuse depuis Manager (2-3h)
- Sur la fiche joueuse Manager : section "Parents liés" + bouton "Lier un parent"
- Recherche User par email/nom dans le club
- Si trouvé → création directe `ParentJoueur` statut=ACTIVE (validé auto car c'est le staff)
- Tracé : `demandePar=STAFF`, validateur enregistré
- Cas d'usage : staff connaît la famille, n'attend pas demande parent

#### B30b — Joueuse PIRB déclare ses référents (1-2h)
- Page `/pirb/mes-parents` : "Qui sont tes parents/référents ?"
- Recherche User par email/nom dans le club
- Auto-trigger `ParentJoueur` statut=PENDING, demandePar=JOUEUR
- Validation staff requise (cf B24 workflow existant)
- Cas d'usage : joueuse majeure ou ado qui déclare elle-même
- Note RGPD : possible qu'une joueuse ne veuille pas être liée → règle "consentement final = la joueuse"

#### B30c — Invitation par mail si parent pas inscrit (3-4h)
- Sur fiche joueuse Manager (ou page PIRB joueuse) : champ "Email du parent"
- Si email pas connu en BDD → envoi mail d'invitation
- Mail contient lien `manager.mabb.fr/parent-invitation/{token}` (token sha256, expire 14j)
- Parent clique → formulaire signup pré-rempli (email lock, nom à compléter, mdp à choisir)
- Création user automatique + `UserClubRole` PARENT + `ParentJoueur` ACTIVE
- Anti-spam : 1 invitation max par couple (joueuse, email) toutes les 24h
- Cas d'usage : Section Sportive collège rentrée septembre → recrutement rapide de tous les parents

### Effort cumulé : 6-9h

### Modèle DB (existant à compléter)

`ParentJoueur` (existe) + ajout :
- `demande_par` enum : PARENT (existant) / STAFF (B30a) / JOUEUR (B30b) / INVITATION (B30c)
- Nouvelle entité `ParentInvitation` : token sha256, email_cible, joueuse_id, demandeur_id, expires_at, accepted_at

### Ordre conseillé
1. B30a (le plus simple, staff déjà connecté = pas de surprise)
2. B30b (UI PIRB joueuse, réutilise workflow B24)
3. B30c (le plus complexe car nouvelle entité + envoi mail + signup pré-rempli)

### Sécurité
- Joueuse mineure ne peut pas refuser un lien parent (autorité parentale)
- Joueuse majeure (18+) : peut révoquer un lien à tout moment depuis PIRB
- Le token d'invitation **ne révèle pas** l'identité de la joueuse (juste "Tu es invité à rejoindre le club MABB en tant que parent")

---

## XI. 🆕 Blocs ajoutés 12/06/2026 soir (Clavel)

### B31 — UI création rencontre + joueuses éphémères (8-10h)

Sur la page "Créer rencontre", 3 boutons :
- **OFFICIEL** (championnat/coupe — comportement actuel)
- **AMICAL** (mélange multi-catégorie possible)
- **ENTRAINEMENT INTERNE** (mélange + joueuses éphémères)

**Joueuses éphémères** (innovation) :
- Coach peut ajouter "Maman X" / "Dirigeant Y" / "Sparring partner Z" sans licence FFBB
- Champ : nom, prénom, ou pseudo
- Apparaissent sur la feuille de match d'entraînement
- Stats Live fonctionne avec elles (utiles pour coach qui s'exerce à saisir)
- Bénévoles peuvent s'entraîner à la saisie Stats Live sur ces sessions
- Le coach peut ensuite choisir parmi les N sessions Stats Live celle qui devient "officielle"

**Modèle BDD** : nouvelle entité `JoueurEphemere` OU champ `Rencontre.joueurs_ephemeres` JSON (déjà préparé en B23 avec `joueurs_externes`).

### B32 — Gamification : assignation multiple en 1 clic (4-5h)

Aujourd'hui : pour attribuer une mission "Buvette" à 5 bénévoles → 5 saisies individuelles fiche par fiche.
**Demain** :
- Cliquer sur le type de mission (Buvette / Tenue table / Spectateur / Animation)
- S'ouvre un sélecteur multi-personnes (checkboxes ou autocomplete groupé)
- Sélectionner toutes les présentes en 1 fois
- POST crée N missions identiques + trigger BadgeChecker pour chaque
- Gain : 10 missions créées en 1 clic au lieu de 10 saisies

L'attribution individuelle reste possible sur fiche joueuse (existe).

### B33 — ENT Section Sportive : bulletins + bilan scolaire (8-10h)

Module dédié pour la Section Sportive collège César Franck (ouverture **septembre 2026**) :

**Tag joueuse** : `Joueur.estSectionSportive` (boolean). Seules ces joueuses ont accès au module bulletins.

**Workflow** :
- Parent ou staff upload le bulletin scolaire (PDF ou image)
- Parser (best-effort V1, OCR V2) extrait : matières + notes + moyennes
- Stockage en BDD via entité `Bulletin` + `NoteScolaire`
- **Fromage de stats** : chart radar par matière
- **Suivi cursus** : progression au fil des trimestres (T1 → T2 → T3 sur 4 ans)
- Bilan complet visible parent + staff + joueuse elle-même

**Préparation API collège** :
- Structure BDD prête à recevoir JSON externe (Pronote / EcoleDirecte / autre)
- Service `BulletinImporter::importFromJson(array $data): Bulletin` (squelette)
- Quand le collège ouvre une API → on branche

**Permissions** :
- Joueuse Section Sportive : voit ses propres bulletins
- Parent (lié à la joueuse) : voit ceux de sa fille
- Staff Section Sportive : voit tout
- Autres joueuses / coachs : aucun accès (RGPD strict)

**Lié à B29e** — peut être codé conjointement.

### Mise à jour roadmap été 2026

Le nouveau planning idéal devient :

**Semaine 1** : pousser marathon 7 blocs (déjà codés) + tester
**Semaine 2-3** : B22a UI déploiement réel + parse PDFs FFBB en BDD prod (commande `app:parse-resumes-ffbb`)
**Semaine 4** : B31 UI types rencontre
**Semaine 5-6** : B32 gamification 1 clic
**Juillet** : B29a + B29b + B29c (ENT base : Document + catégories + permissions)
**Août** : B29e + B33 (Bulletins Section Sportive — BLOQUEUR septembre)
**Septembre** : B27 embed Instagram + B28 refonte Stats Live
**Octobre+** : B4 API Platform / B13 visibilité 5 paliers / B24 planning officiels auto

### V2 — Automne 2026 → début 2027 (avant soutenance CDA)

| Bloc | Effort | Source |
|---|---|---|
| **B4** API Platform + LexikJWT (Sprint 7 CDC) | 10-14h | CDC V5 |
| **B13** Visibilité 5 paliers PIRB (#105) | 6-8h | CDC V5 |
| **B15** Notifications in-app (point rouge avatar) | 3-4h | CDC V5 |
| **B22c** Parser `positiontir.pdf` → shot chart par joueuse | 15-20h | retour Clavel |
| **B24** Planning arbitres/officiels week-end auto + mailing 5j avant | 8-10h | retour Willy |
| **B25** Page admin "gérer rôles utilisateurs" | 3-4h | retour Willy |
| **B26** Page bénévole : gamification + badges visibles | 2-3h | retour Willy |
| **B27** EMBED Instagram/Facebook dans articles vitrine | 4-6h | **demande Clavel 12/06** |
| **B28** Messagerie interne (Mercure temps réel) | 12-15h | CDC V5 |
| **B29** ENT documentaire COMPLET (séances coach + bulletins + certif médicaux) | 22-30h | CDC V5 + Clavel 12/06 ⭐ |
| **B30** Export PDF feuille de match + Excel listes | 4-5h | CDC V5 |
| **B31** Vote MVP fin de match | 3-4h | CDC V5 |
| **B32** Responsable maillots/ballons (tirage mensuel auto) | 2-3h | CDC V5 |
| **B33** Suivi cotisations avancé (relances auto, reçus PDF) | 5-6h | CDC V5 |

### V3 — 2027+ (post-soutenance CDA)

| Bloc | Effort | Source |
|---|---|---|
| **B40** App mobile React Native (Capacitor wrap d'abord) | 30-40h | CDC V5 |
| **B41** Notifications push Firebase | 8-10h | CDC V5 |
| **B42** Module hors-ligne (PWA / WatermelonDB) | 12-15h | CDC V5 |
| **B43** Chatbot IA Manager (FAQ + assistance) | 10-15h | retour Clavel |
| **B44** Chatbot IA PIRB (assistance joueuse) | 5-7h | retour Clavel |
| **B45** Module covoiturage matchs extérieurs | 6-8h | retour Clavel |
| **B46** Paiement licence Stripe + boutique en ligne | 15-20h | retour Clavel |
| **B47** Intégration FFBB / FBI directe (si API ouvre) | 20+ h | CDC V5 — bloqué FFBB |
| **B48** Module 3x3 (tournois Opens Plus Access) | 12-15h | retour Clavel/docx instances |
| **B49** Site comité départemental Somme | 25-30h | retour Clavel — projet annexe |

### V4 — 2028 (vision long terme)

| Bloc | Effort | Source |
|---|---|---|
| **B50** Bally MABB (Coach IA texte personnalisé par joueuse) | 20-25h | CDC V5 |
| **B51** Abonnement Pro Bally + Stripe | 10-12h | CDC V5 |
| **B52** Bibliothèque drills/programmes entraînement | 15-20h | CDC V5 |
| **B53** Objectifs perso joueuse + leaderboard équipe | 8-10h | CDC V5 |
| **B54** Module video PIRB (highlights auto à partir du shot chart) | 20+ h | CDC V5 |
| **B55** Plateforme outils unifiée (barre de recherche centralisée) | 30+ h | retour Clavel — projet annexe |

### V5 — 2028+ (mobile natif + SaaS)

| Bloc | Effort | Source |
|---|---|---|
| **B60** Apps natives iOS/Android via React Native | 40-55h | CDC V5 |
| **B61** Apple Watch companion app | freelance | CDC V5 |
| **B62** SaaS multi-clubs avec abonnements | 50+ h | CDC V5 |
| **B63** Gestion comité départemental (matching arbitres) | 25-30h | docx instances + retour Willy |

---

## IV. 🎯 Focus sur B27 — Embed Instagram/Facebook dans Articles vitrine

> Demande nouvelle de Clavel 12/06/2026 : permettre à l'admin de coller un lien Instagram/Facebook d'une publication MABB dans un article du CMS, et que la vitrine affiche soit l'embed iframe, soit une card avec photo + description automatiquement extraites.

### Cible UX
- Admin vitrine → Articles → "Nouvel article"
- Champ "Lien publication réseau" (optionnel)
- Si rempli → l'article affiche automatiquement le contenu Instagram/Facebook
- Plus besoin de retaper le contenu manuellement

### Choix techniques

**Option A — iframe oEmbed officiel**
- Instagram + Facebook ont une API oEmbed (https://developers.facebook.com/docs/plugins/oembed)
- Demande une Facebook App (gratuite mais validation)
- Retourne du HTML embed prêt à intégrer
- ✅ Affichage natif Instagram/Facebook
- ❌ Demande approbation Meta + token rotatif

**Option B — Scraping OG tags (Recommandée pour V1)**
- Lire la page Instagram/Facebook côté serveur
- Extraire `<meta property="og:image">`, `og:title`, `og:description`
- Stocker en BDD
- Afficher en card sur la vitrine
- ✅ Aucune dépendance Meta
- ❌ Peut casser si Instagram change son HTML
- ❌ Peut être bloqué par rate-limiting si trop d'appels

**Mon conseil** : commence par **Option B** (4-6h), ajoute Option A en V2 si besoin de vrai embed interactif.

### Plan d'implémentation B27
1. Ajouter 3 champs sur `Article` : `lien_publication`, `embed_thumbnail_url`, `embed_caption`
2. Service `SocialEmbedExtractor` :
   - Détecte la plateforme (Instagram/Facebook/TikTok)
   - Fetch la page avec `Symfony\Component\HttpClient`
   - Parse les meta OG tags via regex ou DomCrawler
   - Retourne `{thumbnail, title, description, platform}`
3. Migration BDD pour ajouter les 3 champs
4. UI Admin Article : champ "Lien publication" + bouton "Récupérer le contenu"
5. UI Vitrine Article : si embed disponible, affiche card avec image+texte+lien retour

---

## V. 🔍 Retours Willy / Clavel cumulés (12 chantiers)

### Tirés de `pb et axe amelioration mabb.fr.docx`

1. ✅ **Coach↔Équipe profil coach** (fait B5)
2. ✅ **Gamification axe employé** (fait Axe D existant)
3. ⏳ Page admin gérer rôles user (V2 — B25)
4. ⏳ Bénévole : gamification visible + badges (V2 — B26)
5. ⏳ Élection employé du mois (V1 — B8)
6. ⏳ Planning officiels week-end auto + mailing (V2 — B24)
7. ⏳ Chatbot Manager/PIRB (V3 — B43/B44)
8. ⏳ Export tous matchs saison (déjà disponible via import inverse — à packager)
9. ⏳ **500 sur `/joueuses/{id}/missions/nouvelle`** — bug à fix
10. ⏳ Match all-star/entraînement multi-catégorie (V2 — B23)
11. ⏳ Service Civique + matching auto pour table de marque
12. ⏳ Comité départemental : outil arbitres (V5 — B63)
13. ⏳ **Embed Instagram/Facebook dans articles** (V2 — B27 nouveau)
14. ⏳ Equipes 3x3 par saison à compléter dans vitrine
15. ⏳ Section "Nos victoires" à déplacer dans "Le Club"
16. ⏳ Avis Google : refonte du bouton (lien direct page d'avis)
17. ⏳ Page formation : ajouter parcours de chaque coach

### Tirés de `module_ffbb_stats_live.docx`

1. ⏳ Parser `resume.pdf` → stats individuelles (V1 — B22b)
2. ⏳ Parser `positiontir.pdf` → shot chart par joueuse (V2 — B22c)
3. ⏳ Toggle FFBB / Stats Live unifié (V1 — dépend B22b)

### Tirés de `architecture_technique_mabb_pirb.docx`

1. ✅ Multi-tenant strict via Voters (fait)
2. ✅ Coordonnées X/Y relatives 0-100% (fait via ShotChartCalculator)
3. ⏳ Algo temporel substitutions absolues 0-2400s (V2 si besoin)
4. ⚠️ Anonymat strict feedback : actuellement joueur_id en BDD mais jamais affiché staff. Clavel a validé "pragmatique". OK.
5. ✅ is_verified pour stats officielles (fait via SessionStatsLive.statut)

### Tirés de `PIRB_SCOUTING_RAPPORT_PITCH.md`

Document interne — PIRB Scouting (compte Instagram) est un benchmark pour le profil scouting PIRB MABB. Toutes les fonctionnalités du profil PIRB scouting actuel (bio, highlights, badges, profil public) ont été inspirées de leur format. ✅

---

## VI. 🎓 Préparation soutenance CDA AFPA — avril 2027

### Blocs de compétences couverts

| Bloc CDA | Compétence | Couverture | Justification |
|---|---|---|---|
| **Bloc 1** Dév web sécurisé | 1.1 Maquetter | ✅ Twig + Bootstrap 5 |
| | 1.2 Concevoir base | ✅ 37 entités Doctrine, 38 migrations |
| | 1.3 Front-end | ✅ Asset Mapper, Stimulus |
| | 1.4 Auth & RBAC | ✅ 7 firewalls + Voters + ResetPassword |
| | 1.5 RGPD | ✅ Anonymizer + Exporter + logs |
| **Bloc 2** Dév back | 2.1 MVC | ✅ Symfony 7.4 |
| | 2.2 Tests | 🟡 60 tests — viser 100+ avant soutenance |
| | 2.3 API REST | ❌ À faire (B4 API Platform) |
| **Bloc 3** Gestion projet | 3.1 Spécifier | ✅ Plusieurs CDC V1-V5 |
| | 3.2 Maintenir | ✅ Sprints + this fichier |
| **Bloc 4** Maintenance & qualité | 4.1 Doc technique | ✅ Architecture + ADR |
| | 4.2 Versionning | ✅ Git + GitHub |

### Trous critiques jury

1. 🔴 **Tests** : 60 méthodes c'est limite. Viser **120+** d'ici avril 2027.
2. 🔴 **API REST + JWT** : non implémentée (B4). Indispensable pour Bloc 2.
3. 🟠 **CI/CD** : aucun pipeline. À ajouter (GitHub Actions) au moins lint + tests.
4. 🟠 **Documentation** : ADR éparpillés. À consolider en un dossier technique soutenance.

---

## VII. 🔥 Top priorités à attaquer

**Cette semaine (week-end 13-14/06)** :
1. Fix 500 `/joueuses/{id}/missions/nouvelle`
2. Fix 404 `/signup` PIRB→Manager
3. Promote will@mabb.fr DIRIGEANT
4. Compléter import des 51 PDFs FFBB (en cours)
5. B22a : PIRB → PDFs joueuse visibles

**La semaine d'après (15-21/06)** :
1. B22b parser resume.pdf
2. Toggle FFBB/Live
3. B8 Élection employé/SC du mois
4. B25 Page admin gérer rôles

**Avant rentrée septembre** :
1. B27 Embed Instagram (vitrine plus dynamique pour comm club)
2. B24 Planning officiels auto (Willy demande fort)
3. B13 Visibilité 5 paliers PIRB
4. Préparer démos jury — pages stats joueuse, shot chart, etc.

**Avant Noël 2026** :
1. B4 API Platform + JWT
2. Sprint Tests intensif (60 → 120+)
3. Pipeline GitHub Actions

**Q1 2027 (3 derniers mois avant soutenance avril)** :
1. Documentation soutenance
2. Démo end-to-end propre
3. Sécurisation finale (audit RGPD + pen-test maison)

---

*Récap consolidé 12/06/2026 · Source : audit code 50 controllers + 37 entités + 25 services + lecture CDC V5 + retours Willy/Clavel cumulés.*
*Mis à jour à chaque session de dev. Si tu modifies, redate.*

---

# 🏀 B34 — Séance de tir individuel (Ball.ia like) + workflow validation coach + CEC

## Vision (Clavel 12/06 fin de soirée)
Avant que MABB pompe Ball.ia : fournir aux joueuses MABB un module de tracking de séances de tir individuelles (perso ou avec coach), avec workflow validation et zones efficaces.

## Workflow validation 2 niveaux
- **Avec coach présent** → coach valide → **OFFICIELLE** (poids fort dans bilan)
- **Sans coach** (solo joueuse) → statut **ENTRAINEMENT** (séparé)
- Joueuse peut basculer une séance en **PUBLIQUE** pour son profil PIRB

## Modèle BDD
- `SeanceTirsIndividuel` : joueur_id, date, lieu, coach_id (nullable), est_validee_coach (bool), est_publique (bool), type (SHOOT/CEC/AUTRE), notes
- `TirIndividuel` : seance_id, positionX/Y % 0-100 du demi-terrain, est_reussi (bool), type_tir (LF/2pts_int/2pts_ext/3pts/lay-up), distance (m optionnel), secteur (raquette/mi-distance/périmètre/corner auto-calculé)

## 5 sous-blocs progressifs

| Sous-bloc | Quoi | Effort |
|---|---|---|
| B34a | Entités + migration + repos | 2h |
| B34b | UI PIRB demi-terrain horizontal tap-to-mark mobile-first | 4-5h |
| B34c | UI Manager validation coach (interface tablet pendant séance) | 2-3h |
| B34d | Bilan séance + cumul saison + heatmap zones efficaces | 3-4h |
| B34e | Toggle public + intégration profil PIRB | 1h |

## Bénéfices
1. **Différenciateur fort** : MABB a un outil que les autres clubs n'ont pas
2. **Self-tracking joueuse** : motive à s'entraîner pour faire grimper compteurs
3. **CEC intégré** : Clavel a déjà des stats CEC (cellule évaluation compétences) qui pourront être importées (type=CEC)
4. **Coach** : visualise progression individuelle au fil des séances
5. **Profil PIRB scouting** : séances publiques = vraie vitrine sportive pour scouts

## Cible
**Automne 2026 (V2)** — entre B29 (ENT) et B4 (API Platform). Effort total 12-15h.

---

# 🎥 B35 — Ball.ia full IA vidéo (V5 2027-2028)

## Vision (Clavel 12/06 soir — "grâce à Fable 5 ou autre")
Évolution V5 de B34 : quand les modèles IA vidéo seront accessibles et précis (Sora 2 / Veo 3 / Fable 5+ / Anthropic Vision / OpenAI o4-vision), automatiser ENTIÈREMENT le tracking des séances de tir.

## Comment ça marche
1. Joueuse pose son smartphone sur trépied face au panier
2. Lance l'enregistrement depuis l'app PIRB
3. L'IA détecte en temps réel :
   - Qui shoot (reconnaissance faciale ou numéro maillot)
   - D'où sur le terrain (position X/Y précise via détection joueuse + perspective)
   - Panier rentré ou non (détection trajectoire + cercle)
4. Auto-remplissage des `TirIndividuel` sans tap-to-mark manuel
5. À la fin de la séance : vidéo annotée + stats + recommandations

## Bonus possible (selon modèles dispo)
- Analyse technique : alignement épaules, suspension, follow-through
- Plan d'attaque : "Tu rentres 70% côté droit, 30% côté gauche → bosser le gauche"
- Comparaison avec joueurs pro : style de shoot le plus proche

## Bloqueurs
- Modèles IA vidéo encore trop chers à faire tourner en temps réel
- Précision détection pas encore suffisante pour le basket (différencier panier rentré du panier sur cercle qui sort)
- Coût API > 5€/séance = pas viable pour un club amateur

## Architecture à préparer dès B34
- `TirIndividuel.source` enum : MANUEL / IA_VIDEO
- `SeanceTirsIndividuel.video_path` (nullable, stockage cloud quand on en aura besoin)
- Service `TirAutoImporter` (squelette) — quand l'API IA sera dispo, on l'implémente sans refacto majeure

## Cible
**V5 2027-2028.** Effort 30-50h selon API choisie.

---

*Maj 12/06/2026 fin de soirée — ajout B34 + B35 + 11 blocs livrés marathon.*
