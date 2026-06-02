# Tests MABB — Stratégie

Tests unitaires (sans BDD ni serveur), exécutables hors ligne via PHPUnit.

## Structure

- `Unit/Entity/Core/` — Tests des entités centrales (User, UserClubRole, Club)
- `Unit/Entity/Vitrine/` — Tests des entités vitrine (Article, Media, PageContenu)

## Lancer les tests

```bash
php bin/phpunit
```

Pour un fichier précis :

```bash
php bin/phpunit tests/Unit/Entity/Core/UserTest.php
```

## Couverture actuelle (pour le jury CDA)

| Classe | Test | Lignes couvertes |
|---|---|---|
| `User::setRolesMembre()` | Mutex Employé/Bénévole | logique métier critique |
| `User::removeRoleMembre()` | Protection de Bénévole | logique métier |
| `UserClubRole::isValidRole()` | Whitelist rôles | sécurité données |
| `UserClubRole::setRole()` | Exception sur invalide | sécurité données |
| `Article::onPrePersist()` | Génération slug | slug auto + unicité |

## À étendre ensuite

- Tests fonctionnels (Symfony WebTestCase) sur les contrôleurs admin
- Tests d'anti-fuite multi-tenant (cf. ADR-0003)
- Tests des Voters de sécurité (ClubVoter)
