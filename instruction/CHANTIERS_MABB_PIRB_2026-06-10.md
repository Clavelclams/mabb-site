# 🏗️ CHANTIERS MABB / PIRB — Plan d'action par blocs

> **Périmètre strict** : mabb-site uniquement (vitrine + manager + PIRB). Ne touche **pas** à Velito-site / VEA / VENA / autres projets.
> **Daté** : 10/06/2026 · **Source** : audit complet du 09/06/2026 (Carnet de bord CDA Notion)
> **But** : préparer tous les blocs codables avant d'attaquer le code, pour avancer carré sans détour.

---

## 📊 Tableau de bord — vue d'ensemble

Tous les blocs sont indépendants sauf indication. **L'ordre de priorité est dicté par le jury CDA d'avril 2027** : ce qui pèse sur les blocs de compétences passe en premier.

| Bloc | Titre | Domaine | Effort | Priorité jury | Dépend |
|---|---|---|---|---|---|
| **B1** | Reset password + Mailer Brevo prod | Manager (sécu) | 4-6h | 🔴 P0 | — |
| **B2** | Logs connexion + RGPD droit à l'oubli | Manager (sécu) | 5-7h | 🔴 P0 | — |
| **B3** | Tests fonctionnels critiques (multi-tenant, voters, workflows) | Transverse | 12-16h | 🔴 P0 | — |
| **B4** | API Platform + LexikJWT (Sprint 7 CDC) | Manager (API) | 10-14h | 🟠 P1 | — |
| **B5** | Affectation Coach↔Équipe (#106) | Manager | 4-5h | 🟠 P1 | — |
| **B6** | Entité Saison dédiée (refacto) | Manager | 3-4h | 🟢 P3 | — |
| **B7** | Manager /utilisateurs — rolesMembre Vitrine lecture (#5) | Manager | 1-2h | 🟢 P3 | — |
| **B8** | Élection employé/SC du mois | Manager | 6-8h | 🟢 P3 | — |
| **B9** | PIRB Notation séances anonyme (#20) | PIRB | 6-8h | 🟠 P1 | — |
| **B10** | PIRB Stats perso saison (depuis EvaluationMatch) | PIRB | 4-5h | 🟠 P1 | — |
| **B11** | PIRB Stats par match + Shot chart perso | PIRB | 6-8h | 🟠 P1 | B10 |
| **B12** | PIRB Bilan 4 axes gamification visible joueuse | PIRB | 3-4h | 🟢 P2 | — |
| **B13** | PIRB Visibilité 5 paliers (#105) | PIRB | 6-8h | 🟢 P2 | B5 |
| **B14** | PIRB Réponse aux convocations rencontres (Présent/Absent/Incertain) | PIRB | 4-5h | 🟠 P1 | — |
| **B15** | PIRB Notifications in-app (point rouge avatar) | PIRB | 3-4h | 🟢 P2 | B14 |
| **B16** | Vitrine compléter pages V1 manquantes | Vitrine | 2-3h | 🟢 P3 | — |
| **B17** | Promouvoir will@mabb.fr DIRIGEANT MABB (#4) | Manager | 0.25h | 🟢 P3 | — |
| **B18** | Badges Axe B (Performance basket) — compléter BadgeCatalog | Manager | 2-3h | 🟢 P2 | — |

**Total effort estimé** : ~85-115h · réaliste sur **3-4 mois** au rythme solo + alternance.

---

## 🔴 BLOC B1 — Reset password + Mailer Brevo prod

**Pourquoi P0 jury** : `Bloc 1 Développement web sécurisé · Compétence 1.4 Authentification`. Le jury demande comment un user récupère son mot de passe. Sans reset, c'est un -2 sur la grille AFPA.

**État actuel** : `symfonycasts/reset-password` absent de composer.json. Sprint 1.5 marqué "en cours" dans Notion depuis mars 2026, **zéro progrès code**.

**Tâches**
1. `composer require symfonycasts/reset-password-bundle`
2. `php bin/console make:reset-password` (génère 4 fichiers : `ResetPasswordController`, `ResetPasswordRequestFormType`, `ChangePasswordFormType`, `ResetPasswordEmail`)
3. Adapter les templates pour le design Manager (chartes vert MABB)
4. Configurer `MAILER_DSN` Brevo dans `.env.local` (jamais commit) — relais SMTP `smtp-relay.brevo.com:587`
5. Test bout-à-bout : demande reset → mail reçu → lien 1h → nouveau mot de passe
6. Migration `reset_password_request` (auto-générée)

**Fichiers créés/modifiés**
- `src/Controller/Manager/ResetPasswordController.php` (nouveau)
- `src/Form/ResetPasswordRequestFormType.php` (nouveau)
- `src/Form/ChangePasswordFormType.php` (nouveau)
- `templates/reset_password/*.html.twig` (4 fichiers)
- `migrations/Version20260610XXXXXX.php` (table reset_password_request)
- `config/packages/reset_password.yaml` (auto)
- `.env.local` (`MAILER_DSN` Brevo)
- Lien "Mot de passe oublié ?" sur `templates/manager/security/login.html.twig`

**Tests à écrire (Bloc B3)**
- `ResetPasswordControllerTest::testRequestSendsEmail()`
- `ResetPasswordControllerTest::testTokenExpiresAfter1Hour()`

**Effort** : 4-6h.

---

## 🔴 BLOC B2 — Logs connexion + RGPD droit à l'oubli

**Pourquoi P0 jury** : `Bloc 1 · Compétence 1.5 RGPD et conformité`. Le jury demande "comment vous tracez les connexions ?" et "comment vous gérez une demande d'effacement RGPD ?". Sans réponse → -2.

### Sous-bloc B2.a — Logs connexion

**Tâches**
1. Entité `ConnexionLog` (id, user_id null, email_tente, ip, user_agent, succes bool, raison_echec string null, created_at)
2. Migration
3. EventListener `Symfony\Component\Security\Http\Event\LoginSuccessEvent` → log succès
4. EventListener `Symfony\Component\Security\Http\Event\LoginFailureEvent` → log échec
5. Page admin `/admin/logs-connexion` (paginée 50/page, filtre IP/email/date)
6. **Anti-brute-force** bonus : si 5 échecs même IP en 10 min → bloquer 15 min

**Fichiers**
- `src/Entity/Core/ConnexionLog.php`
- `src/Repository/Core/ConnexionLogRepository.php`
- `src/EventListener/LoginLogListener.php`
- `src/Controller/Admin/AdminLogsConnexionController.php`
- `templates/admin/logs_connexion/index.html.twig`
- `migrations/Version20260610YYYYYY.php`

**Effort** : 3-4h.

### Sous-bloc B2.b — RGPD droit à l'oubli

**Tâches**
1. Service `RgpdAnonymizer`
   - Méthode `anonymizeUser(User $user)` : nom→"Anonyme", prénom→"Anonyme", email→"deleted-{id}@anonyme.local", téléphone→null, adresse→null, photoPath→null
   - Ne supprime PAS le User pour préserver les FK historiques (présences, conv, stats, votes)
   - Méthode `purgeUser(User $user, Club $club)` : supprime UserClubRole pour ce club
2. Service `RgpdExporter` : export JSON de toutes les données d'un user (BLOC 1 compétence)
3. Page user `/manager/profil` → bouton "Demander la suppression de mes données"
4. Page admin `/admin/rgpd` : liste demandes + bouton "Anonymiser"
5. Log de l'action dans `ConnexionLog` (raison='rgpd_anonymization')

**Fichiers**
- `src/Service/RgpdAnonymizer.php`
- `src/Service/RgpdExporter.php`
- `src/Controller/Manager/ProfilController.php` (ajout action)
- `src/Controller/Admin/AdminRgpdController.php`
- `templates/admin/rgpd/index.html.twig`

**Effort** : 2-3h. **Total B2** : 5-7h.

---

## 🔴 BLOC B3 — Tests fonctionnels critiques

**Pourquoi P0 jury** : `Bloc 4 · Tests`. Tu as **8 fichiers tests** pour 33 entités + 44 controllers. Le jury va checker `tests/` et demander "comment vous testez le multi-tenant ?". Sans tests crédibles → catastrophe.

**Cible** : passer de 8 à **≥30 tests** avec focus sur les zones critiques.

### Tests à écrire (par priorité jury)

| Test | Fichier | Pourquoi |
|---|---|---|
| `ClubVoterTest` | `tests/Security/Voter/` | Sécurité multi-tenant = LA chose à prouver |
| `TenantResolverTest` | `tests/Security/Tenant/` | Isolation par host |
| `UserClubRoleTest` | `tests/Entity/Core/` | Multi-rôles cumulables |
| `JoueurMatcherServiceTest` | `tests/Service/` | Anti-doublon licence FFBB |
| `EvaluationCalculatorTest` | `tests/Service/` | Calcul stats officielles |
| `ShotChartCalculatorTest` | `tests/Service/Stats/` | Calcul zones de tir |
| `ParentJoueurRepositoryTest` | `tests/Repository/Sport/` | Validation parent-enfant |
| `ManagerInscriptionControllerTest` | `tests/Controller/Manager/` | RGPD à l'inscription |
| `PirbProfilControllerTest` | `tests/Controller/Pirb/` | CSRF + droit d'édition |
| `ResetPasswordControllerTest` | `tests/Controller/Manager/` | Sécu reset (vient de B1) |
| `RgpdAnonymizerTest` | `tests/Service/` | Effacement effectif (vient de B2) |
| `NoteFraisVoterTest` | `tests/Security/Voter/` | Workflow validation |

### Infrastructure tests

1. `tests/bootstrap.php` propre (DB de test isolée)
2. `phpunit.dist.xml` avec env=test
3. `.env.test` avec base sqlite ou MariaDB test
4. Fixtures `tests/Fixtures/CoreFixtures.php` (2 clubs, 4 users, 6 joueuses)
5. Trait `tests/Trait/TenantAssertionsTrait.php` pour assertions multi-tenant réutilisables

**Effort** : 12-16h. **Rentabilité jury** : massive.

---

## 🟠 BLOC B4 — API Platform + LexikJWT (Sprint 7 CDC)

**Pourquoi P1 jury** : `Bloc 2 · Compétence 2.3 API REST`. Le CDC V5 le liste comme Sprint 7. Préparation mobile V3.

**Tâches**
1. `composer require api-platform/core lexik/jwt-authentication-bundle`
2. Configurer JWT (paire de clés, lifetime 1h, refresh token 30j)
3. Exposer en API les ressources clés :
   - `Joueur` (GET only public, GET full self)
   - `Seance` (GET filtré par équipe du user connecté)
   - `Rencontre` (GET filtré)
   - `Convocation` (GET self + POST réponse)
4. Voters réutilisés (déjà OK pour multi-tenant)
5. Doc OpenAPI auto à `/api/docs` (Swagger UI)
6. Endpoint `/api/login_check` (token JWT)

**Fichiers**
- `config/packages/api_platform.yaml`
- `config/packages/lexik_jwt_authentication.yaml`
- `config/jwt/private.pem` + `public.pem` (gitignored)
- `src/Controller/Api/LoginController.php`
- Annotations `#[ApiResource]` sur entités exposées
- Tests `tests/Controller/Api/` (à inclure dans B3)

**Effort** : 10-14h.

---

## 🟠 BLOC B5 — Affectation Coach ↔ Équipe (#106)

**Pourquoi P1** : débloque BLOC B13 (palier "Mon coach" PIRB) + clarifie qui anime quoi côté staff.

**Tâches**
1. Table jointure `coach_equipe` (id, user_id, equipe_id, role enum: COACH_PRINCIPAL/ASSISTANT, saison string, created_at)
2. Migration
3. Entité `CoachEquipe` + repo
4. UI page `/equipes/{id}` : section "Coachs" avec ajout/retrait
5. Page `/staff/{userId}` (déjà existante) : afficher liste équipes coachées
6. Service `CoachEquipeService::estMonCoach(User $coach, Joueur $joueur): bool`
7. Filtre dashboard coach : ses séances = celles des équipes qu'il coache

**Fichiers**
- `src/Entity/Sport/CoachEquipe.php`
- `src/Repository/Sport/CoachEquipeRepository.php`
- `src/Service/CoachEquipeService.php`
- `src/Controller/Manager/EquipeController.php` (ajout actions ajouter/retirer coach)
- `templates/manager/equipe/show.html.twig` (section Coachs)
- `migrations/Version20260610ZZZZZZ.php`

**Effort** : 4-5h.

---

## 🟢 BLOC B6 — Entité Saison dédiée (refacto)

**Pourquoi P3** : techniquement propre mais pas bloquant. Aujourd'hui `Equipe.saison` est un string ("2025-2026"). Une entité dédiée permettrait : archiver les saisons, gérer les dates début/fin, basculer la saison courante.

**Tâches**
1. Entité `Saison` (id, label, debut DATE, fin DATE, estCourante BOOL, club_id)
2. Migration de données : créer la saison "2025-2026" auto, lier toutes les Equipe à cette saison via FK
3. Refactorer `Equipe.saison` (string) → `Equipe.saison` (Saison)
4. Page admin `/saisons` : CRUD
5. Update toutes les requêtes qui filtrent par saison (~12 endroits)

**Risque** : refacto cross-entités. Tester en local d'abord avec dump prod.

**Effort** : 3-4h. **Reporte si pas le temps.**

---

## 🟢 BLOC B7 — Manager /utilisateurs : rolesMembre Vitrine en lecture (#5)

**Pourquoi P3** : tâche pending depuis avant l'audit. Petite.

**Tâches**
1. Sur `/utilisateurs/{id}` : afficher colonne "Rôle vitrine" (lecture seule, depuis `User.rolesMembre`)
2. Pas de sync, pas de modif depuis Manager (la vitrine reste autoritaire)
3. Badge gris ou tag pour distinguer du rôle Manager

**Fichiers**
- `src/Controller/Manager/ManagerUtilisateursController.php`
- `templates/manager/utilisateurs/show.html.twig`

**Effort** : 1-2h.

---

## 🟢 BLOC B8 — Élection employé/SC du mois

**Pourquoi P3** : axe gamification métier (Bloc D des badges). Pas bloquant.

**Tâches**
1. Entité `ElectionMois` (id, club_id, mois DATE, type enum:EMPLOYE/SC, nominees JSON[user_ids], votes JSON[user_id=>candidate_id], vainqueur_id null, cloture BOOL)
2. Migration
3. UI dirigeant : créer élection → choisir nominees parmi staff/SC
4. UI staff/SC : voter (1 voix anonyme)
5. UI dirigeant : clôturer + voir vainqueur
6. Badge auto axe D : `Employé du mois` ou `SC du mois`
7. Affichage page d'accueil Manager : "🏆 Employé du mois"

**Fichiers**
- `src/Entity/Sport/ElectionMois.php`
- `src/Repository/Sport/ElectionMoisRepository.php`
- `src/Controller/Manager/ElectionController.php`
- `templates/manager/election/*.html.twig` (index, new, vote, resultat)
- Update `BadgeCatalog.php`

**Effort** : 6-8h.

---

## 🟠 BLOC B9 — PIRB Notation séances anonyme (#20)

**Pourquoi P1** : feedback structurant pour le coach, et **demande réelle terrain** Clavel. Tâche #20 pending.

**Tâches**
1. Entité `FeedbackSeance` (id, seance_id, joueur_id null si anonyme, note 0-5, commentaire text null, est_anonyme BOOL, created_at)
2. Migration + UNIQUE INDEX (seance_id, joueur_id) pour éviter double vote non-anonyme
3. UI PIRB après séance écoulée : card "Note ta séance" (5 étoiles + commentaire optionnel + checkbox "Anonyme")
4. POST `/pirb/seances/{id}/feedback`
5. UI Manager `/seances/{id}/feedbacks` (staff only) : **moyenne + distribution + commentaires anonymisés**. Jamais d'identité quand est_anonyme=true.
6. UI Manager dashboard coach : alerte "Séance X mal notée (<3/5)"
7. Badge auto axe A : `Bookworm de retex` (10 séances notées)

**Sécurité**
- CSRF sur POST
- Vérif joueur est convoqué à la séance avant de pouvoir noter
- Vérif séance date passée
- Vérif single vote par joueur si non anonyme (UNIQUE)

**Fichiers**
- `src/Entity/Sport/FeedbackSeance.php`
- `src/Repository/Sport/FeedbackSeanceRepository.php`
- `src/Controller/Pirb/PirbFeedbackController.php`
- `src/Controller/Manager/SeanceController.php` (ajout action feedbacks)
- `templates/pirb/feedback_form.html.twig` (modal ou page)
- `templates/manager/seance/feedbacks.html.twig`
- Update `templates/pirb/dashboard.html.twig` (card "Note ta dernière séance")

**Effort** : 6-8h.

---

## 🟠 BLOC B10 — PIRB Stats perso saison (depuis EvaluationMatch)

**Pourquoi P1** : valeur immédiate joueuse, agrège des données déjà calculées.

**Tâches**
1. Service `JoueurStatsAggregator` (saison) → retourne :
   - Points/match moyen
   - % tirs réussis
   - % LF (lancers francs)
   - Rebonds offensifs/défensifs/match
   - Passes décisives/match
   - Interceptions, contres, fautes
2. Route `/pirb/stats` + `templates/pirb/stats.html.twig`
3. Card dashboard "Mes stats saison" (résumé compact + lien "Voir tout")
4. Comparaison équipe (moyenne anonymisée) **si visibilité OK** — pour l'instant simple toggle global, B13 affinera
5. Source : Stats Live officielles uniquement (validées)

**Fichiers**
- `src/Service/JoueurStatsAggregator.php`
- `src/Controller/Pirb/PirbStatsController.php`
- `templates/pirb/stats.html.twig`
- Update `templates/pirb/dashboard.html.twig` (card stats)

**Effort** : 4-5h.

---

## 🟠 BLOC B11 — PIRB Stats par match + Shot chart perso (dépend B10)

**Tâches**
1. Liste derniers matchs joués sur `/pirb/stats` (cliquable)
2. Page détail `/pirb/stats/match/{rencontreId}` : mes stats du match + score équipe
3. Shot chart perso via `ShotChartCalculator` (déjà existant) filtré par joueur_id
4. Graph progression points sur les 10 derniers matchs (Chart.js)

**Fichiers**
- `src/Controller/Pirb/PirbStatsController.php` (action matchDetail)
- `templates/pirb/stats_match.html.twig`
- `templates/pirb/stats_shotchart.html.twig` (composant)
- Chart.js via Asset Mapper (déjà présent ?)

**Effort** : 6-8h.

---

## 🟢 BLOC B12 — PIRB Bilan 4 axes gamification visible joueuse

**Pourquoi P2** : valorise la gamif déjà codée côté Manager.

**Tâches**
1. Page `/pirb/badges` (déjà partielle pour épingler 3 badges → on enrichit)
2. Section "Mon bilan 4 axes" :
   - Axe A (Vie collective) : X/Y badges, XP cumulé
   - Axe B (Performance basket) : **à créer dans BadgeCatalog → voir B18**
   - Axe C (Communication) : X/Y badges
   - Axe D (Emploi/SC) : X/Y badges
3. Barre de progression visuelle par axe
4. Liste détail badges débloqués (date, axe, XP rapporté)
5. Badges non-débloqués en silhouette + condition pour les obtenir

**Fichiers**
- `src/Controller/Pirb/PirbProfilController.php` (action badges enrichie)
- `templates/pirb/badges.html.twig` (refonte)
- `src/Twig/BadgeCatalogExtension.php` (helpers pour rendu)

**Effort** : 3-4h.

---

## 🟢 BLOC B13 — PIRB Visibilité 5 paliers (#105) — dépend B5

**Pourquoi P2** : prépare le social. Bloqué par B5 (palier "Mon coach" a besoin du lien Coach↔Équipe).

**Tâches**
1. Champ JSON `Joueur.visibilite` (default `{photo:'membres', tel:'staff', stats:'public', bio:'public', highlights:'public', email:'staff'}`)
2. UI PIRB `/pirb/visibilite` : pour chaque champ profil → choix palier (radio 5 options)
3. Helper Twig `pirb_voit(joueur, champ): bool` qui regarde palier vs user connecté :
   - `public` → toujours true
   - `membres` → user a UserClubRole sur ce club
   - `staff` → user a CLUB_STAFF
   - `mon_coach` → user est coach d'une équipe contenant joueur (via B5)
   - `prive` → seulement le joueur lui-même
4. Migration du bool `profilPublic` actuel → JSON visibilite (conserve l'intention)
5. Appliquer le helper sur `templates/pirb/joueur_public.html.twig`

**Fichiers**
- `migrations/Version20260610AAAAAA.php` (refacto champ)
- `src/Entity/Sport/Joueur.php` (champ JSON)
- `src/Controller/Pirb/PirbVisibiliteController.php`
- `templates/pirb/visibilite.html.twig`
- `src/Twig/PirbVisibiliteExtension.php`
- Update `templates/pirb/joueur_public.html.twig` (conditions partout)

**Effort** : 6-8h.

---

## 🟠 BLOC B14 — PIRB Réponse aux convocations rencontres

**Pourquoi P1** : c'est le truc le plus demandé en pratique club (coach veut savoir qui vient).

**Tâches**
1. Champ `Convocation.statutJoueur` enum:PENDING/PRESENT/ABSENT/INCERTAIN (default PENDING)
2. Champ `Convocation.reponseAt` datetime null
3. Migration
4. UI PIRB dashboard ou `/pirb/convocations` : prochaines convocations avec 3 boutons P/A/I
5. POST `/pirb/convocations/{id}/repondre`
6. Affichage Manager `/rencontres/{id}/convocations` : statut + date de réponse de chaque joueuse
7. Badge auto axe A : `Soldat ponctuel` (10 convocations répondues sous 24h)

**Sécurité**
- Vérif joueur est bien le convoqué
- CSRF
- Verrou : pas de modif après la date du match

**Fichiers**
- `migrations/Version20260610BBBBBB.php`
- `src/Entity/Sport/Convocation.php` (champs)
- `src/Controller/Pirb/PirbConvocationsController.php`
- `templates/pirb/convocations.html.twig`
- Update `templates/manager/rencontre/show.html.twig` (afficher statuts)
- Update `templates/pirb/dashboard.html.twig` (card "À répondre")

**Effort** : 4-5h.

---

## 🟢 BLOC B15 — PIRB Notifications in-app (dépend B14)

**Tâches**
1. Service `PirbNotifBadgeCounter` → compte actions en attente pour user :
   - Convocations sans réponse (B14)
   - Feedbacks séances pas encore donnés (B9)
   - PV réunions publiques non lus
   - Nouveaux badges débloqués
2. Affichage : point rouge `<span class="pirb-notif-dot">` sur avatar dashboard
3. Drawer profil : compteur "3 actions en attente" + liste compacte
4. Stockage côté User des dates "vu" par catégorie (pas besoin d'entité dédiée pour V1)

**Effort** : 3-4h.

---

## 🟢 BLOC B16 — Vitrine compléter pages V1 manquantes

**Tâches**
1. Auditer vitrine actuelle vs CDC V5 vitrine
2. Identifier pages manquantes (FAQ ? Mentions légales ? RGPD ? Contact officiel ?)
3. Créer les pages via CMS (admin → AdminPagesController existe déjà → simple back-office)
4. Update footer si nav vitrine incomplète
5. Sitemap.xml + robots.txt si pas déjà OK

**Effort** : 2-3h (selon audit).

---

## 🟢 BLOC B17 — Promouvoir will@mabb.fr DIRIGEANT MABB (#4)

**Tâches**
1. Identifier `user_id` de `will@mabb.fr` dans table `user`
2. Identifier `club_id` du club MABB
3. `INSERT INTO user_club_role (user_id, club_id, role, statut) VALUES (?, ?, 'DIRIGEANT', 'active')`
4. Tester login + accès dashboard dirigeant

**Effort** : 15 minutes.

---

## 🟢 BLOC B18 — Badges Axe B (Performance basket)

**Pourquoi** : ton `BadgeCatalog` a 28 badges Axes A/C/D mais **Axe B Performance** est absent. Sans Axe B, le bilan PIRB (B12) est bancal.

**Tâches** — créer 8-10 badges Axe B
- `Premier point` (1er point inscrit)
- `Double figure` (10+ points sur un match)
- `Triple double` (10+ dans 3 catégories)
- `Maitre du rebond` (50 rebonds saison)
- `Distributeur` (30 passes décisives saison)
- `Adresse 3pts` (40%+ à 3pts, min 30 tentatives)
- `Sniper LF` (80%+ aux LF, min 20)
- `Régularité` (joué 80%+ des matchs)
- `Top scoreuse` (top scoreuse équipe sur un match)
- `Performance saison` (MVP saison équipe)

**Tâches code**
1. Update `BadgeCatalog.php` : ajouter clés `B_*`
2. Update `BadgeChecker.php` : règles déclenchement (basé sur `EvaluationMatch` ou `ActionMatch` agrégé)
3. Mettre à jour `XpCalculator` pour XP Axe B

**Effort** : 2-3h.

---

## 📋 Ordre d'exécution recommandé

**Sprint 1 (semaine 1)** — sécu jury
1. B1 Reset password (4-6h)
2. B2 Logs + RGPD (5-7h)
3. B3 Tests fonctionnels critiques (12-16h)

**Sprint 2 (semaine 2)** — features PIRB demandées
4. B14 Réponse convocations (4-5h)
5. B9 Notation séances anonyme (6-8h)
6. B10 Stats perso saison (4-5h)
7. B11 Stats match + shot chart (6-8h)

**Sprint 3 (semaine 3)** — gamif + social
8. B18 Badges Axe B (2-3h)
9. B12 Bilan 4 axes PIRB (3-4h)
10. B5 Coach↔Équipe (4-5h)
11. B15 Notif in-app (3-4h)

**Sprint 4 (semaine 4)** — API + visibilité
12. B4 API Platform + JWT (10-14h)
13. B13 Visibilité 5 paliers (6-8h)

**Sprint 5 (au fil de l'eau)** — petites tâches
14. B7 rolesMembre vitrine (1-2h)
15. B17 Promouvoir will (15 min)
16. B16 Vitrine pages V1 (2-3h)
17. B8 Élection mois (6-8h)
18. B6 Entité Saison refacto (3-4h)

---

## ⚠️ Règles à respecter pendant le code

1. **Multi-tenant strict** : chaque controller filtre par `tenantResolver->getCurrentClub()`. Aucune fuite cross-club.
2. **CSRF** sur toute route destructive (POST/PUT/DELETE).
3. **Voters Symfony** réutilisés, jamais de check de rôle en dur dans le contrôleur.
4. **MAILER_DSN** et tout secret uniquement dans `.env.local` (jamais commit).
5. **Migrations versionnées** : un fichier par changement de schéma, jamais d'`UPDATE` ad hoc.
6. **Tests** ajoutés en même temps que le code (sinon B3 grossit en dette).
7. **Commits atomiques** : 1 bloc = 1 série de commits clairement nommés.
8. **Documentation** : chaque nouveau service doit avoir un docblock expliquant son rôle + contexte CDC.

---

## 🎯 Critère de succès

À la fin de tous les blocs :
- Code couvrant **~95%** du CDC V5 pour V1
- **≥30 tests** sur les zones critiques
- **Aucun secret en clair** dans le repo
- **Sécu jury** validée (reset, logs, RGPD oubli)
- **API Platform** opérationnelle (préparation mobile V3)
- **PIRB V1.5 + V2** suffisamment livrées pour engagement réel des joueuses MABB

---

*Document généré le 10/06/2026 · À partir de l'audit du 09/06/2026 · Mise à jour à chaque bloc terminé.*
