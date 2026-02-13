# Backlog — MABB / PIRB

> Dernière mise à jour : 2026-02-13
> Items priorisés par phase. Voir 02_ROADMAP_GLOBALE.md pour la vue macro.

## Format
| Champ | Description |
|-------|-------------|
| ID | BL-XXXX |
| Phase | Phase V1 concernée (1 à 6) |
| Module | Core / Sport / Stats / PIRB / Vitrine / System |
| Description | Ce qui doit être fait |
| Priorité | P0 (bloquant) / P1 (important) / P2 (souhaitable) |
| Statut | a faire / en cours / fait |

## Règle transverse obligatoire

Toute entité métier manipulant des données club doit :
- soit contenir explicitement un champ `club_id`
- soit être filtrable de manière sûre via relation Doctrine
- être protégée par ClubScopeVoter côté serveur

### Contraintes d'unicité DB (obligatoires)
- `UNIQUE (user_id, club_id)` sur ClubUser
- `UNIQUE (code)` sur Role
- `UNIQUE (club_user_id, role_id)` sur ClubUserRole

---

## Phase 1 — Core

| ID | Description | Priorité | Statut |
|----|-------------|----------|--------|
| BL-0000 | Résoudre blocage installation dépendances (composer SSL + vendor + php.ini) | P0 | a faire |
| BL-0001 | Créer entité User (email, password, nom, prénom, statut, created_at, deleted_at) | P0 | a faire |
| BL-0002 | Créer entité Club (nom, logo, couleurs, statut, club_id_ffbb) | P0 | a faire |
| BL-0003 | Créer entité ClubUser (pivot user-club : user_id, club_id, statut, created_at, deleted_at) | P0 | a faire |
| BL-0004 | Créer entité Role + pivot ClubUserRole (rôles par club, M:N ClubUser-Role) | P0 | a faire |
| BL-0004b | Créer entité ClubUserRole (club_user_id, role_id, created_at, created_by) + contraintes uniques | P0 | a faire |
| BL-0005 | Implémenter ClubScopeVoter (filtrage multi-tenant par club_id) | P0 | a faire |
| BL-0006 | Implémenter OwnershipVoter (mes données / mon enfant) | P0 | a faire |
| BL-0007 | Implémenter TeamCoachVoter (coach affecté à l'équipe) | P1 | a faire |
| BL-0008 | Configurer JWT (LexikJWTAuthenticationBundle) | P0 | a faire |
| BL-0009 | Formulaire inscription (opt-in explicite bénévole + consentement CGU/RGPD obligatoire non pré-coché) | P0 | a faire |
| BL-0010 | Configurer password_hashers (bcrypt/argon2) dans security.yaml | P0 | a faire |
| BL-0011 | Première migration Doctrine (User, Club, ClubUser, Role, ClubUserRole) | P0 | a faire |
| BL-0012 | Rate limiting sur login (max 5 tentatives/min) | P1 | a faire |
| BL-0013 | Récupération mot de passe par lien sécurisé | P1 | a faire |
| BL-0014 | Créer endpoints API auth + contexte club sans API Platform (Controller Symfony natif) | P1 | a faire |

## Phase 2 — Sport

| ID | Description | Priorité | Statut |
|----|-------------|----------|--------|
| BL-0020 | Créer entité Season (club_id, label, dates, active) | P0 | a faire |
| BL-0021 | Créer entité Team (club_id, season_id, nom, catégorie, genre) | P0 | a faire |
| BL-0022 | Créer entité Player (user_id, club_id, date_naissance, poste, taille) | P0 | a faire |
| BL-0023 | Créer entité Event (club_id, team_id, type, date, lieu, statut) | P0 | a faire |
| BL-0024 | Créer entité Match (event_id, adversaire, domicile, scores, validé) | P0 | a faire |
| BL-0025 | Créer entité Presence (event_id, player_id, statut, commentaire) | P0 | a faire |
| BL-0026 | Créer entité Convocation (match_id, player_id, statut_réponse) | P0 | a faire |
| BL-0027 | CRUD saisons/équipes/joueurs (Manager) | P0 | a faire |
| BL-0028 | Gestion des présences (interface coach) | P0 | a faire |

## Phase 3 — Stats

| ID | Description | Priorité | Statut |
|----|-------------|----------|--------|
| BL-0030 | Installer API Platform | P0 | a faire |
| BL-0031 | Créer entité PlayerStat (match_id, player_id, minutes, points, rebonds, etc.) | P0 | a faire |
| BL-0032 | Créer entité ShotRecord (match_id, player_id, type, result, pos_x, pos_y, period) | P0 | a faire |
| BL-0033 | Créer entité MatchEvent (timeline : match_id, event_type, player_id, period, clock, payload) | P0 | a faire |
| BL-0034 | Interface terrain interactif (shot tracking, tablette-friendly) | P0 | a faire |
| BL-0035 | Cinq majeur + remplacements (entrées/sorties) | P0 | a faire |
| BL-0036 | Validation match (workflow : brouillon → validé → verrouillé) | P0 | a faire |
| BL-0037 | Calculs automatiques (moyennes, taux de réussite) | P1 | a faire |

## Phase 4 — PIRB

| ID | Description | Priorité | Statut |
|----|-------------|----------|--------|
| BL-0040 | Créer entité PlayerProfile (player_id, description, profil_public, visibility_settings) | P0 | a faire |
| BL-0041 | Dashboard joueur (stats clés, résumé saison) | P0 | a faire |
| BL-0042 | Shot chart interactif (visualisation tirs sur terrain) | P0 | a faire |
| BL-0043 | Timeline personnelle (actions du joueur par match) | P1 | a faire |
| BL-0044 | Créer entité TrainingFeedback (event_id, rating, feedback_positif/négatif, anonyme) | P0 | a faire |
| BL-0045 | Gestion visibilité profil (Public / Club / Privé par bloc) | P0 | a faire |

## Phase 5 — Vitrine

| ID | Description | Priorité | Statut |
|----|-------------|----------|--------|
| BL-0050 | Back-office CMS (édition pages, articles, médias) | P0 | a faire |
| BL-0051 | Créer entités Article, Page, Media (Vitrine) | P0 | a faire |
| BL-0052 | Éditeur WYSIWYG pour articles/pages | P1 | a faire |
| BL-0053 | Actualités dynamiques en page d'accueil | P0 | a faire |
| BL-0054 | Galerie photos par saison/événement | P1 | a faire |
| BL-0055 | Calendrier public (lien Score'n'co) | P2 | a faire |
| BL-0056 | Formulaire contact + captcha + envoi email | P0 | a faire |
| BL-0057 | SEO : sitemap XML, robots.txt, métadonnées éditables | P1 | a faire |

## Phase 6 — System

| ID | Description | Priorité | Statut |
|----|-------------|----------|--------|
| BL-0060 | Logs de connexion (table logs_connexion, EventSubscriber) | P0 | a faire |
| BL-0061 | Audit actions sensibles (table audit, AuditLogger) | P0 | a faire |
| BL-0062 | Commande purge logs > 12 mois | P1 | a faire |
| BL-0063 | Commande anonymisation comptes inactifs > 24 mois | P1 | a faire |
| BL-0064 | Endpoint/procédure export données personnelles (RGPD) | P1 | a faire |
| BL-0065 | Tests anti-fuite inter-club (Voters + API) | P0 | a faire |
| BL-0066 | Tests unitaires et fonctionnels (couverture minimale) | P0 | a faire |
| BL-0067 | Documentation technique | P1 | a faire |
