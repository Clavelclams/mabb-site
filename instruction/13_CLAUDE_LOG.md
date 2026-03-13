# Journal d’exécution — MABB / PIRB

## Format
### YYYY-MM-DD
- Objectif :
- Actions réalisées :
- Fichiers modifiés :
- Décisions (ADR si applicable) :
- Points de vigilance / risques :

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
