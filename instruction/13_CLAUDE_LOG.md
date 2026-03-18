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
