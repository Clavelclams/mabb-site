# AUDIT COMPLET FEATURES — MABB MANAGER + PIRB
**Date : 29 juin 2026 | Mode lecture seule | Auditeur : Claude Sonnet 4.6**

---

## I. FEATURES EXISTANTES — MANAGER

### Auth & Sécurité

| Feature | Route principale | Statut | Multi-tenant |
|---|---|---|---|
| Login/logout (7 firewalls isolés) | `/login` | ✅ Complet | ✅ par host |
| Inscription + consentement RGPD horodaté | `/inscription` | ✅ Complet | ✅ |
| Reset password (token SHA256) | `/reset-password` | ✅ Complet | ✅ |
| Logs connexion (succès + échecs + raison) | `/profil/logs` | ✅ Complet | ✅ |
| Anti-brute-force (CountFailuresByIp) | — service | ✅ Complet | ✅ |
| RGPD export JSON + droit à l'oubli | `/profil/rgpd` | ✅ Complet | ✅ |
| CSRF sur toutes routes destructives | — | ✅ Complet | — |
| TenantResolver (session `active_club_id`) | — service | ✅ Complet | ✅ core |
| ClubVoter (6 attributs) | — voter | ✅ Complet | ✅ core |
| Multi-clubs (user dans plusieurs clubs) | — modèle | ✅ Modèle OK | ⚠️ Pas de sélecteur UI |

### Gestion sportive

| Feature | Route principale | Statut | Multi-tenant |
|---|---|---|---|
| Équipes CRUD + archivage/réactivation | `/equipes` | ✅ Complet | ✅ |
| Composition effectif par équipe | `/equipes/{id}/effectif` | ✅ Complet | ✅ |
| Joueurs CRUD complet + archivage | `/joueurs` | ✅ Complet | ✅ |
| Lien User↔Joueur par licence FFBB | — JoueurMatcher | ✅ Complet | ✅ |
| Import trombinoscope FFBB (Excel) | `/joueurs/import` | ✅ Complet | ✅ |
| Catégories FFBB (U11–Sénior) | — enum | ✅ Complet | ✅ |
| Coach↔Équipe (table pivot CoachEquipe) | `/equipes/{id}/coachs` | ✅ Complet | ✅ |
| Staff — page regroupée + fiche détaillée | `/staff` | ✅ Complet | ✅ |
| Parent↔Joueur + validation | `/parents` | ✅ Complet | ✅ |
| Section sportive (bilan scolaire) | `/joueurs/{id}/scolaire` | ✅ Complet | ✅ |
| Import rencontres FFBB (Excel) | `app:import-rencontres` | ✅ Complet | ✅ |
| Import PDFs FFBB | `app:import-pdfs-ffbb` | ✅ Complet | ✅ |
| Entité Saison dédiée | — | ❌ Manquant | — (string sur Equipe) |

### Séances & Événements

| Feature | Route principale | Statut | Multi-tenant |
|---|---|---|---|
| Séances CRUD + planning récurrent | `/seances` | ✅ Complet | ✅ |
| Rencontres CRUD (workflow brouillon→verrouillé→archivé) | `/rencontres` | ✅ Complet | ✅ |
| Champs FFBB (n° match, code e-Marque, division, forfait) | `/rencontres/{id}` | ✅ Complet | ✅ |
| Convocations rencontres (envoi) | `/rencontres/{id}/convoquer` | ✅ Complet | ✅ |
| Envoi emails convocations | — mailer | ⚠️ Partiel | MAILER_DSN=null://null |
| Présences (pointage séance + rencontre) | `/presences` | ✅ Complet | ✅ |
| Évaluations match (saisie manuelle) | `/evaluations` | ✅ Complet | ✅ |
| Import évaluations XLSX | `/evaluations/import` | ✅ Complet | ✅ |
| Événements génériques + participations | `/evenements` | ✅ Complet | ✅ |
| Missions + 11 types (dont SPECTATEUR) | `/missions` | ✅ Complet | ✅ |
| Validation inscription bénévole rencontre | `/rencontres/{id}/benevoles` | ⚠️ Partiel | BUG B-102 feedback visuel |
| Blocage inscription si match passé (+4h) | — | ✅ Complet | ✅ |

### Stats Live

| Feature | Route principale | Statut | Multi-tenant |
|---|---|---|---|
| SessionStatsLive (workflow 4 états) | `/stats-live` | ✅ Complet | ✅ |
| ActionMatch (chronologique, types complets) | `/stats-live/{id}/actions` | ✅ Complet | ✅ |
| PresenceTerrain (5 sur terrain + remplaçantes) | `/stats-live/{id}/terrain` | ✅ Complet | ✅ |
| ShotChartCalculator (coordonnées X/Y) | — service | ✅ Complet | ✅ |
| Promotion session → stats officielles | `/stats-live/{id}/promouvoir` | ✅ Complet | ✅ |
| Validation officielle par coach (UI) | — | ⚠️ Partiel | B-201, bouton UI manquant |
| Terrain horizontal entier (offense+défense) | — | ❌ Manquant | B-28, ~17-23h |
| Shot chart validation (coach valide SeanceTir) | `/shot-chart-validation` | ✅ Complet | ✅ |

### Trésorerie

| Feature | Route principale | Statut | Multi-tenant |
|---|---|---|---|
| Opérations + justificatifs upload | `/tresorerie` | ✅ Complet | ✅ |
| Cotisations + tarifs par âge/catégorie | `/cotisations` | ✅ Complet | ✅ |
| Notes de frais (workflow validation) | `/notes-frais` | ✅ Complet | ✅ |
| Subventions (suivi état + montant) | `/subventions` | ✅ Complet | ✅ |
| Export CSV | `/tresorerie/export` | ✅ Complet | ✅ |
| Tableau de bord trésorerie | `/tresorerie/dashboard` | ✅ Complet | ✅ |
| Gestion saisonnière des tarifs | `/tarifs-cotisations` | ✅ Complet | ✅ |

### Bureau / Réunions

| Feature | Route principale | Statut | Multi-tenant |
|---|---|---|---|
| Réunions (CA/AG/Bureau) + convocations | `/reunions` | ✅ Complet | ✅ |
| PV versionnés | `/reunions/{id}/pv` | ✅ Complet | ✅ |
| Synthèse réunion | `/reunions/{id}/synthese` | ✅ Complet | ✅ |
| Documents joints réunion | `/reunions/{id}/documents` | ✅ Complet | ✅ |
| Réunions publiques (visibles membres) | — | ✅ Complet | ✅ |
| Envoi email convocations CA | — mailer | ⚠️ Partiel | mailer non configuré prod |

### ENT / Documents

| Feature | Route principale | Statut | Multi-tenant |
|---|---|---|---|
| Upload documents (6+ types) | `/ent` | ✅ Complet | ✅ `uploads/ent/{clubId}/` |
| Lecture PIRB (joueur voit ses docs) | `/pirb/documents` | ✅ Complet | ✅ |
| PDFs FFBB dans ENT | — | ✅ Complet | ✅ |
| Demandes d'accès PDF (joueur demande, coach approuve) | `/demandes-pdf` | ✅ Complet | ✅ |
| Filtres ENT style Kalisport GED | — | ❌ Manquant | B-301 |
| Onglet "Effectif" ENT (licences, certificats) | — | ❌ Manquant | idée non qualifiée |

### Gamification

| Feature | Route principale | Statut | Multi-tenant |
|---|---|---|---|
| Missions (11 types) + MissionBulkController | `/missions` | ✅ Complet | ✅ |
| BadgeCatalog 28+ badges | — | ✅ Complet | — catalogue global |
| Axe A — Régularité (11 badges) | — | ✅ Complet | ✅ |
| Axe B — Performance basket (10+ badges) | — | ✅ Complet | ✅ |
| Axe C — Vie de club (6+5 badges spectateur) | — | ✅ Complet | ✅ |
| Axe D — Performance employé (6 badges) | — | ✅ Complet | ✅ |
| XpCalculator + BadgeChecker (idempotent) | — services | ✅ Complet | ✅ |
| Élection employé/SC du mois | — | ❌ Manquant | B-8 P3 |

### Gestion utilisateurs

| Feature | Route principale | Statut | Multi-tenant |
|---|---|---|---|
| Liste utilisateurs du club | `/utilisateurs` | ✅ Complet | ✅ |
| Ajouter/modifier/désactiver rôle UserClubRole | `/utilisateurs/{id}/roles` | ✅ Complet | ✅ |
| Workflow demandes (pending → active/rejected) | `/demandes` | ✅ Complet | ✅ |
| Profil manager + RGPD export/effacement | `/profil` | ✅ Complet | ✅ |

---

## II. FEATURES EXISTANTES — PIRB

| Feature | Route principale | Statut | Note |
|---|---|---|---|
| Dashboard mobile-first + drawer | `/pirb` | ✅ Complet | Stats présences, prochaines séances/rencontres |
| Login/Logout (firewall pirb) | `/pirb/login` | ✅ Complet | |
| Profil éditable (email, téléphone, urgence, bio) | `/pirb/profil` | ✅ Complet | |
| Photo profil upload | `/pirb/profil/photo` | ✅ Complet | |
| Liens sociaux (Instagram, TikTok, YouTube, X, LinkedIn) | `/pirb/profil/reseaux` | ✅ Complet | |
| Badges épinglés (3 max parmi 28+) | `/pirb/badges` | ✅ Complet | |
| Highlights vidéo (YouTube/Instagram/TikTok) | `/pirb/highlights` | ✅ Complet | |
| Profil public consultable scout `/joueuse/{id}` | `/joueuse/{id}` | ✅ Complet | |
| Page Mon équipe (coéquipières) | `/pirb/equipe` | ✅ Complet | |
| Mes enfants (vue parent) + validation | `/pirb/enfants` | ✅ Complet | |
| Invitation parent par lien | `/pirb/invitation-parent` | ✅ Complet | |
| Convocations P/A/I + repondueAt | `/pirb/convocations` | ✅ Complet | |
| Feedback séances (note 0-5 + commentaire + anonyme) | `/pirb/feedback-seances` | ✅ Complet | |
| Stats perso saison (JoueurStatsAggregator) | `/pirb/stats` | ✅ Complet | |
| Détail stats par match | `/pirb/stats/{matchId}` | ✅ Complet | |
| Bilan 4 axes gamification | `/pirb/bilan-badges` | ✅ Complet | |
| Shot chart stats (zones + terrain modal) | `/pirb/shot-chart` | ✅ Complet | |
| Saisie tirs sur terrain cliquable (modal) | `/pirb/shot-chart/saisir` | ⚠️ Partiel | B-302 |
| Stats shot chart avec source FFBB/Live | `/pirb/shot-chart/stats` | ✅ Complet | |
| Download PDF stats match | `/pirb/stats/{id}/pdf` | ✅ Complet | |
| Parser PDF résumé FFBB → stats BDD | — service OCR | ⚠️ Partiel | B-22b |
| Toggle FFBB/Stats Live sur fiche match | — | ❌ Manquant | B-306 |
| Notifications in-app (pastille rouge) | — | ❌ Manquant | B-204/B-15 |
| Déclaration séance solo | `/pirb/seances-solo` | ✅ Complet | validation manager |
| Visibilité 5 paliers (public → privé) | — | ❌ Manquant | B-13 |
| Stats shot chart agrégées saison | — | ❌ Manquant | idée non qualifiée |

**⚠️ LACUNE CRITIQUE PIRB** : 0 appel à `TenantResolver` dans les 13 controllers PIRB. Isolation uniquement par `Joueur.user`. Bloquant pour l'expansion multi-club.

---

## III. FEATURES EXISTANTES — VITRINE MABB.FR

| Feature | Route | Statut |
|---|---|---|
| Page Accueil (labels FFBB, compteurs animés) | `/` | ✅ Complet |
| Page Club / organigramme | `/club` | ⚠️ Partiel — organigramme pas à jour |
| Page Équipes 5x5 | `/equipes` | ✅ Complet |
| Page Équipes 3x3 | `/equipes-3x3` | ⚠️ Partiel — équipes 25/26 à compléter |
| Page Galerie | `/galerie` | ✅ Complet |
| Page Calendrier | `/calendrier` | ✅ Complet |
| Page Cité Éducative | `/cite-educative` | ✅ Complet |
| Page Numérique | `/numerique` | ✅ Complet |
| Page Nos Victoires | `/victoires` | ✅ Complet |
| Page Employes (staff) | `/employes` | ✅ Complet |
| Page Formation | `/formation` | ✅ Complet |
| Pages dynamiques CMS (13 pages éditables) | — admin | ✅ Complet |
| Actualités + pagination | `/actualites` | ✅ Complet |
| Médiathèque CMS | `/admin/mediateque` | ✅ Complet |
| Formulaire contact | `/contact` | ⚠️ Partiel — mailer null://null |
| Lien Victoires dans menu "Le Club" | — | ❌ Manquant |
| Fil d'Ariane | — | ❌ Manquant |
| Mentions légales + politique RGPD | — | ❌ Manquant — obligation légale CNIL |
| Sitemap.xml + robots.txt | — | ❌ Manquant |

---

## IV. MODÈLE MULTI-CLUB — ÉTAT ACTUEL

### Architecture TenantResolver

- Table pivot `user_club_role` : UNIQUE(user_id, club_id, role) — supporte nativement user dans N clubs
- `TenantResolver` résout le club actif depuis `session['active_club_id']`
- `ClubAwareInterface` implémentée sur **26 des 42 entités Sport** — les 16 restantes (Presence, Convocation, FeedbackSeance, CoachEquipe, JoueurBadge, SeanceSolo, ZoneTir, TirFfbb, etc.) atteignent leur club via chaînes de relations
- **27/36 controllers Manager** filtrent explicitement par `getCurrentClub()`
- **0/13 controllers PIRB** appellent `TenantResolver` — isolation par `Joueur.user` uniquement

### Rôles disponibles par club (UserClubRole)

```
MEMBER         → accès lecture général
COACH          → gestion séances, évaluations, convocations
STAFF          → gestion admin (trésorerie, réunions, documents)
ADMIN          → droits complets sur le club
JOUEUR         → accès PIRB uniquement
STAFF_ELARGI   → staff + accès réunions publiques
SUPER_ADMIN    → bypass tous les voters (global plateforme)
```

### Points forts de l'isolation

- Uploads stockés dans `uploads/ent/{clubId}/` — isolation physique
- Toutes les requêtes Manager passent par `$club = $this->tenantResolver->getCurrentClub()`
- ClubVoter vérifie l'appartenance avant toute action destructive

### Points faibles de l'isolation

1. **PIRB entier (13 controllers)** : 0 appel à TenantResolver → risque si joueur dans 2 clubs
2. **16 entités Sport sans ClubAwareInterface** : ClubVoter ne peut pas être invoqué directement
3. **Pas de sélecteur UI multi-clubs** : malgré le TenantResolver, aucun dropdown "Changer de club"
4. **Anti-brute-force post-hoc** : pas de `rate_limiter` Symfony configuré sur les firewalls login
5. **Pas d'index composite `(club_id, date)`** visible — risque perf en multi-club avec volume

---

## V. FEATURES MANQUANTES (TROUS IDENTIFIÉS)

### A. Multi-club / Onboarding nouveaux clubs (PRIORITÉ EXPANSION)

- ❌ Sélecteur UI "Changer de club" pour users multi-club
- ❌ Workflow inscription nouveau club (formulaire → validation super-admin → provisioning)
- ❌ Page super-admin supervision clubs (liste, statuts, métriques)
- ❌ ClubAwareInterface sur les 16 entités Sport restantes
- ❌ Isolation PIRB via TenantResolver (13 controllers à patcher)
- ❌ Rate limiter Symfony sur les firewalls login
- ❌ Index composite (club_id, ...) pour perf requêtes multi-club à volume

### B. Gestion sportive

- ❌ Entité `Saison` dédiée (actuellement string sur Equipe)
- ❌ Terrain Stats Live horizontal entier offense+défense (B-28, ~17-23h)
- ❌ Validation officielle stats live par coach — bouton UI (B-201)
- ❌ Création rapide rencontre depuis `/stats-live` (B-307)
- ❌ Filtres ENT style Kalisport GED (B-301)
- ❌ Onglet effectif ENT (licences, certificats médicaux)

### C. Stats & Performance joueuse (PIRB)

- ❌ Parser PDF résumé FFBB → stats individuelles BDD (B-22b, OCR partiel)
- ❌ Toggle FFBB/Stats Live sur fiche match (B-306)
- ❌ Shot chart agrégé par saison (heatmap zones chaudes/froides)
- ❌ Stats comparatives équipe (anonymisées)
- ❌ Visibilité 5 paliers sur le profil public (B-13)
- ❌ Saisie tirs terrain modal complète (B-302)

### D. Communication

- ❌ Mailer Brevo configuré en prod (`.env.local` OVH manquant)
- ❌ Notifications in-app PIRB (pastille rouge, B-204/B-15)
- ❌ Feedback visuel inscription bénévole (BUG B-102/B-03)
- ❌ Formulaire contact vitrine (bloqué par mailer)
- ❌ SMS/WhatsApp convocations (V2)

### E. Administration plateforme

- ❌ Interface super-admin (liste clubs, monitoring, métriques)
- ❌ Tableau de bord dirigeant KPIs (présences globales, cotisations en retard)
- ❌ Export PDF bilan trésorerie
- ❌ Élection employé/SC du mois (B-8 P3)

### F. Légal & SEO

- ❌ Mentions légales + politique RGPD sur vitrine (obligation CNIL/LCEN)
- ❌ Sitemap.xml + robots.txt
- ❌ API JWT (aucune API Platform — prévu Sprint V3)

### G. Tests (point faible structurel)

- ❌ 0 test sur ClubVoter et TenantResolver
- ❌ 0 test Controller fonctionnel
- ❌ Couverture tests visée ≥30% sur métier Sport

---

## VI. PRIORITÉS RECOMMANDÉES

| # | Feature | Impact | Effort | Pourquoi |
|---|---|---|---|---|
| **1** | Corriger bugs connus (BUG-01/02/03) | 🔴 Critique | ~3h | Bugs user-facing, cassent la démo |
| **2** | Configurer Mailer Brevo en prod | 🔴 Critique | ~1h | Infrastructure prête, juste `.env.local` OVH |
| **3** | Tests ClubVoter + TenantResolver | 🔴 Critique | 8-12h | Cœur du projet, 0 test = indéfendable jury CDA |
| **4** | Notifications in-app PIRB (B-204) | 🟠 Fort | 3-4h | Premier signal engagement joueuses |
| **5** | Sélecteur UI multi-clubs Manager | 🟠 Fort | 4-6h | TenantResolver prêt, UI manquante = user bloqué |
| **6** | Isolation PIRB via TenantResolver | 🟠 Fort | 3-5h | Bloquant avant expansion multi-club |
| **7** | Validation officielle stats live (B-201) | 🟡 Moyen | ~4h | Bouton UI manquant sur workflow complet |
| **8** | Terrain Stats Live horizontal (B-28) | 🟡 Moyen | 17-23h | Feature différenciante, gros effort |
| **9** | Parser PDF résumé FFBB (B-22b) | 🟡 Moyen | 6-8h | Stats Live vs stats FFBB côte à côte |
| **10** | Mentions légales + RGPD vitrine + sitemap | 🟡 Moyen | 2-3h | Obligation légale CNIL/LCEN |
