# 37 — Inventaire complet des fonctionnalités · 26 juillet 2026

> Recensement exhaustif, établi depuis le code (88 contrôleurs, 47 services,
> 22 commandes, 67 entités). Référence pour le dossier CDA et les démos.

---

## 1. Vitrine publique — mabb.fr

- Site du club : accueil, actualités/articles, pages CMS éditables, galerie médias
- Pages « numérique » (CMS dédié)
- Page employés du club
- Planning public des créneaux d'entraînement
- **Pré-inscription licence en ligne** (remplace le Google Form) : formulaire
  public avec honeypot anti-bot, CSRF, consentement RGPD → atterrit dans le
  secrétariat Manager
- Pages légales (mentions, politique de confidentialité)
- **Compte membre** : inscription publique, connexion, profil avec avatar
  (upload sécurisé), rattachement au club comme bénévole (validation staff),
  page de redirection pour les membres d'autres clubs
- **Un seul compte pour tout l'écosystème** : mabb.fr ↔ Manager ↔ Venaball

## 2. Manager — manager.mabb.fr (gestion du club)

### Structure & accès
- **Multi-club (multi-tenant)** : isolation stricte par club, voters de rôles
- Rôles : dirigeant, coach, staff, secrétaire, trésorier, joueuse, parent,
  bénévole, employé — gestion des utilisateurs et de leurs rôles
- Demandes d'adhésion, membres en attente de validation
- Création de club, officialisation, super-admin
- Connexion, profil, **reset mot de passe par e-mail**, logs de connexion
- **Sélecteur de saison global** (bascule automatique au 1er juillet)

### Dashboard & onboarding
- Dashboard personnalisé « Pour toi » : convocations aux réunions, PV à lire,
  tout ce qui arrive dans les 7 jours (matchs, événements, séances)
- **Guide « première visite »** pas à pas, adapté au rôle
- Dashboard coach dédié, dashboard OTM (missions de match) en carte

### Équipes & joueuses
- Équipes : CRUD, affectation coachs, classements, sections sportives
  (+ import de bulletins scolaires)
- **Fiches joueuses complètes** : identité, licence centralisée (type, numéro),
  photo, archivage, multi-équipes (surclassement / réserve / doublage)
- Joueuses **éphémères** (invitées d'un match, adverses comprises)
- Lien fiche ↔ compte utilisateur, **liens parent-enfant validés par le staff**
- Import trombinoscope (photos + fiches en masse)

### Rencontres & convocations
- Rencontres : CRUD, formats de période configurables (4×8, 4×10…),
  domicile/extérieur, score, import FFBB et Excel
- **Convocations** avec PDF généré et envoi e-mail, réponses des joueuses
- Présences aux matchs, feuilles de match PDF archivées
- **Matchs internes à deux équipes A/B** (composition depuis tout l'effectif)

### Missions de match (bénévolat)
- Postes par rencontre : e-marque, chrono, buvette… affectation individuelle
  ou en masse, **candidatures des bénévoles** sur postes vacants,
  page « mes missions », détection des rôles vacants

### Stats Live (table de marque)
- Saisie tactile en direct type Easy Stats : clic joueuse + action + terrain
- **Shot chart** : position de chaque tir sur le demi-terrain
- Entrées/sorties → **temps de jeu réel** (auto-clôture au buzzer)
- Chrono intégré, score adverse, effectif du match (non-convoquées)
- **Sessions multi-bénévoles** en parallèle, promotion d'UNE session en
  « officielle » (source de vérité), archivage
- Mode débutant / expert, mode « localiser » (cartographier pertes, rebonds…)
- **Résumé de match** façon feuille FFBB : stats par joueuse, totaux,
  score par période, pour les deux camps

### Évaluations & performances
- Évaluations FIBA par match : saisie coach, **import Excel** (export du
  classeur type), **import OCR des PDF officiels FFBB** (Google Vision)
- **Toggle Stats Live / FFBB** sur la fiche joueuse (sources séparées,
  jamais mélangées)
- Moyennes saison, 5 derniers matchs, filtre par équipe, dropdown saison
- Minutes jouées réelles (depuis les présences terrain)
- Shot chart par joueuse (par match ou saison)
- **Gamification** : XP, niveaux, badges par saison
- **Bilans de compétences** rédigés par le coach, validés, visibles
  parents/joueuses

### Entraînements
- Plannings récurrents par équipe/secteur, **génération automatique des
  séances**, présences aux séances (taux de présence par joueuse)
- Contenus de séance : exercices, médias
- **Séances solo** (travail individuel des joueuses)

### Vie du club
- **Événements** (AG, tournois, sorties, fêtes) : publication, inscription
  en 1 clic, décharges de sortie avec upload
- **Réunions** : convocations, ordre du jour, PV, documents, présences
- Documents du club (upload sécurisé, accès par rôle)

### Secrétariat
- **Classeur licences numérique** (remplace les Excel) : secteurs
  Ouest/Étouvie, Nord, Sud, catégories, types et numéros de licence,
  paiements, relances
- **Placement drag & drop** des joueuses par secteur (4 colonnes)
- **Pré-inscriptions publiques** : conversion en 1 clic en dossier licence +
  fiche joueuse + contact parent, anti-doublon visible, refus motivé
- Annuaire du club, import des fichiers Excel de la secrétaire, sync fiches
- Organisation des week-ends de match

### Trésorerie
- Opérations avec justificatifs uploadés, exports comptables
- **Cotisations** : tarifs par catégorie, génération en masse, suivi
  paiement/exemption, affichage sur la fiche joueuse
- **Notes de frais** avec circuit de validation
- **Subventions** : suivi des dossiers, encaissements

## 3. Venaball web — pirb.mabb.fr (espace joueuse/parent)

- Dashboard joueuse
- Profil avec **6 réglages de confidentialité** (défaut : tout privé)
- Stats perso, shot chart, bilans de compétences, équipe, rencontres,
  séances, documents
- **Convocations** : consultation et réponse
- Notifications, feedback utilisateur
- **Mes enfants** (parent) : recherche bornée au club, demande de lien,
  validation staff
- **Mes parents** (joueuse) : déclaration inverse, révocation à 18+
- Fiches joueuses publiques intra-club, **follow** entre joueuses, classement
- SSO transparent depuis l'app mobile (ticket HMAC 90 s)

## 4. App mobile Venaball (Expo / API)

- **Auth par jetons opaques** (hashés SHA-256, 30 j, révocation logout),
  throttling anti-force-brute, journalisation des connexions
- Compte, profil, édition, réglages de confidentialité
- Stats, bilans, convocations avec réponse, club
- **Notifications push** (Expo)
- Follow / classement intra-club
- **Playground** : mini-jeux tir automatique et dribble automatique
  (détection caméra MediaPipe, WebView verrouillée au domaine), scores
- SSO app → web

## 5. Admin vitrine — CMS

- Articles, pages, contenus éditoriaux, médiathèque (upload sécurisé)
- Gestion des rôles, **outils RGPD** (export, anonymisation)
- Consultation des logs de connexion (web + API)

## 6. Transverse & sécurité

- Monolithe modulaire **7 firewalls par sous-domaine**
- Isolation multi-tenant systématique (TenantResolver + ClubVoter)
- CSRF sur tous les formulaires, uploads en liste blanche MIME,
  `.htaccess` de verrouillage des uploads, anti-IDOR audité (doc 34)
- RGPD : consentements, export, anonymisation, purge des logs,
  invitations parents sécurisées
- Notifications internes + e-mails transactionnels (Brevo)
- SaisonService : source unique de vérité pour la saison

## 7. Ligne de commande (22 commandes)

Création admin · imports (rencontres FFBB/Excel, joueuses depuis trombi,
PDFs FFBB, organismes FFBB, bilans, CR de réunion, championnat U15B) ·
OCR des résumés FFBB · parsing positions de tirs · validation en masse des
stats FFBB · clôture OTM · **passage de saison** · purge RGPD des
inscriptions sorties · fixtures · diagramme PlantUML · officialisation
club · seed plannings 2026-27 · sync secrétariat · régénération des
minutes jouées.
