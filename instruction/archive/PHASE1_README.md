# Phase 1 — Core Auth : Guide d'implémentation

## Fichiers à copier dans ton projet

```
src/Entity/Core/User.php              → src/Entity/Core/User.php
src/Entity/Core/Club.php              → src/Entity/Core/Club.php
src/Entity/Core/UserClubRole.php      → src/Entity/Core/UserClubRole.php
src/Repository/Core/UserRepository.php
src/Repository/Core/ClubRepository.php
src/Repository/Core/UserClubRoleRepository.php
src/Security/Tenant/TenantResolver.php
src/Security/Voter/ClubVoter.php
src/Controller/Vitrine/CompteController.php   → REMPLACE l'existant
src/DataFixtures/AppFixtures.php
config/packages/security.yaml                → REMPLACE l'existant
templates/vitrine/compte/se_connecter.html.twig  → REMPLACE l'existant
templates/vitrine/compte/s_inscrire.html.twig    → REMPLACE l'existant
```

---

## Commandes à exécuter dans l'ordre

### 1. Installer les fixtures (si pas encore fait)
```bash
composer require --dev doctrine/doctrine-fixtures-bundle
```

### 2. Générer la migration
```bash
php bin/console doctrine:migrations:diff
# Vérifie le fichier généré dans migrations/ avant de continuer
php bin/console doctrine:migrations:migrate
```

### 3. Charger les données de test
```bash
php bin/console doctrine:fixtures:load
# ⚠️ Efface tout et recharge. Ne faire qu'en dev.
```

### 4. Vérifier que les routes existent
```bash
php bin/console debug:router | grep compte
# Tu dois voir :
# vitrine_compte_se_connecter   /compte/se-connecter
# vitrine_compte_s_inscrire     /compte/s-inscrire
# vitrine_logout                /compte/deconnexion
```

### 5. Tester la connexion
```
URL : http://localhost/compte/se-connecter
Email : admin@mabb.fr
MDP   : Admin1234!
```

---

## Ce que chaque fichier fait

### `User.php`
- Implémente `UserInterface` et `PasswordAuthenticatedUserInterface` → requis par Symfony Security
- `getRoles()` retourne toujours `ROLE_USER` minimum
- Les rôles métier (COACH, JOUEUR...) sont dans `UserClubRole`, **pas ici**
- RGPD : `rgpdConsent` + `rgpdConsentAt` horodatés automatiquement

### `Club.php`
- Le `slug` est l'identifiant URL unique du club (`mabb`, `amiens-basket`...)
- Multi-tenant : chaque donnée sportive sera liée à un `club_id`

### `UserClubRole.php`
- Table pivot User ↔ Club ↔ Rôle métier
- Contrainte UNIQUE sur `(user_id, club_id, role)` → pas de doublon
- Un user peut avoir plusieurs rôles dans le même club (ex: COACH + BENEVOLE)

### `security.yaml`
- 3 firewalls distincts par host : `vitrine`, `manager`, `pirb`
- `form_login` avec `username_parameter: _email` (pas `_username`)
- `remember_me` configuré (7 jours)
- Les `access_control` sont commentés → à décommenter quand Manager/PIRB seront prêts

### `TenantResolver`
- Service injectable dans n'importe quel controller
- Résout le club actif depuis la session
- Si l'user n'a qu'un club → auto-sélection
- Si plusieurs clubs → retourne null (l'UI doit proposer un choix)

### `ClubVoter`
- Usage dans un controller : `$this->denyAccessUnlessGranted('CLUB_COACH', $club);`
- Attributs : `CLUB_MEMBER`, `CLUB_COACH`, `CLUB_ADMIN`, `CLUB_STAFF`, `CLUB_JOUEUR`

---

## Points importants à comprendre

### Pourquoi 2 systèmes de rôles ?

**Rôles Symfony** (dans `User::roles`) :
- `ROLE_USER` → tout utilisateur connecté
- `ROLE_SUPER_ADMIN` → toi, accès total

**Rôles métier** (dans `UserClubRole::role`) :
- `COACH`, `DIRIGEANT`, `JOUEUR`, `PARENT`, `BENEVOLE`, `STAFF`
- Dépendent d'un club spécifique
- Un user peut être COACH dans le club A et PARENT dans le club B

### Pourquoi `username_parameter: _email` dans security.yaml ?
Symfony `form_login` s'attend par défaut à un champ `_username`.
On le renomme `_email` pour que le formulaire soit clair.
Le template utilise `name="_email"`, cohérent avec security.yaml.

### Le `csrf_token` dans le formulaire de connexion
```twig
<input type="hidden" name="_csrf_token" value="{{ csrf_token('authenticate') }}">
```
Symfony vérifie ce token automatiquement → protection CSRF sans code PHP.
**Ne jamais l'enlever.**
