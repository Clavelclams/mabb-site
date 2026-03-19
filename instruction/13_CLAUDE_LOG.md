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
