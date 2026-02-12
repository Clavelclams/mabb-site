# Arborescence du projet MABB-Site

Monolithe modulaire Symfony — 3 espaces (vitrine / manager / pirb) + API

```
mabb-site/
│
├── assets/                              # Assets frontend
│   ├── app.js                           # Point d'entree JavaScript
│   ├── controllers.json                 # Config des controllers Stimulus
│   ├── stimulus_bootstrap.js            # Bootstrap Stimulus
│   ├── controllers/                     # Controllers JavaScript (Stimulus)
│   │   ├── csrf_protection_controller.js
│   │   └── hello_controller.js
│   ├── images/                          # Images assets
│   │   ├── bg.jpg
│   │   ├── image01.jpg
│   │   └── logo.jpg
│   └── styles/                          # Feuilles de styles
│       └── app.css
│
├── bin/                                 # Binaires CLI
│   ├── console                          # Console Symfony
│   └── phpunit                          # Lanceur de tests
│
├── config/                              # Configuration Symfony
│   ├── bundles.php
│   ├── preload.php
│   ├── reference.php
│   ├── services.yaml                    # Services et autowiring
│   ├── routes.yaml                      # Routage principal (multi-host)
│   ├── packages/                        # Configuration par package
│   │   ├── asset_mapper.yaml
│   │   ├── cache.yaml
│   │   ├── csrf.yaml
│   │   ├── debug.yaml
│   │   ├── doctrine.yaml
│   │   ├── doctrine_migrations.yaml
│   │   ├── framework.yaml
│   │   ├── mailer.yaml
│   │   ├── messenger.yaml
│   │   ├── monolog.yaml
│   │   ├── notifier.yaml
│   │   ├── property_info.yaml
│   │   ├── routing.yaml
│   │   ├── security.yaml                # Firewalls multi-host + RBAC
│   │   ├── translation.yaml
│   │   ├── twig.yaml
│   │   ├── ux_turbo.yaml
│   │   ├── validator.yaml
│   │   └── web_profiler.yaml
│   └── routes/                          # Routes par espace + bundle
│       ├── vitrine.yaml                 # Routes mabb.fr
│       ├── manager.yaml                 # Routes manager.mabb.fr
│       ├── pirb.yaml                    # Routes pirb.mabb.fr
│       ├── framework.yaml
│       ├── security.yaml
│       └── web_profiler.yaml
│
├── instruction/                         # Documentation / Instructions
│   ├── CDC/                             # Cahiers des charges (PDF)
│   │   ├── Cahier des charges – Site web MABB.fr.pdf
│   │   └── CDC_MABB_PIRB_V1_Definitif.pdf
│   ├── 00_GOUVERNANCE_DOC.md
│   ├── 01_LIRE_AVANT_TOUT.md
│   ├── 02_ROADMAP_GLOBALE.md
│   ├── 03_ROADMAP_V1.md
│   ├── 04_ROADMAP_V2.md
│   ├── 05_ROADMAP_V3.md
│   ├── 06_REGISTRE_TECHNIQUE.md
│   ├── 07_REGISTRE_SECURITE_RGPD.md
│   ├── 08_ADR.md
│   ├── 09_BACKLOG.md
│   ├── 10_DEFINITION_OF_DONE.md
│   ├── 11_CHECKLIST_RELEASE.md
│   ├── 12_TEMPLATE_PROMPTS_IA.md
│   ├── 13_CLAUDE_LOG.md
│   └── arborescence.md                  # (ce fichier)
│
├── migrations/                          # Migrations Doctrine
│
├── public/                              # Racine du serveur web
│   ├── index.php                        # Point d'entree
│   └── images/
│       ├── bg.jpg
│       └── manar.jpg
│
├── puml/                                # Diagrammes PlantUML
│   ├── index.png
│   └── index.puml
│
├── shemas/                              # Schemas et dictionnaires
│   └── dictionnaire_db.md              # Dictionnaire de la base de donnees
│
├── src/                                 # Code source PHP
│   ├── Kernel.php
│   ├── Command/                         # Commandes console
│   │   └── PumlCommand.php
│   │
│   ├── Controller/                      # Controllers HTTP (par espace)
│   │   ├── Vitrine/                     # mabb.fr
│   │   │   └── AccueilController.php
│   │   ├── Manager/                     # manager.mabb.fr
│   │   ├── Pirb/                        # pirb.mabb.fr
│   │   └── Api/                         # /api (REST, stateless)
│   │
│   ├── Entity/                          # Entites Doctrine (par module)
│   │   ├── Core/                        # User, Role, Club, ClubUser
│   │   ├── Sport/                       # Team, Player, Match, Event, Stats...
│   │   ├── Vitrine/                     # Article, Page, Media
│   │   └── Pirb/                        # PlayerProfile, ShotRecord...
│   │
│   ├── Repository/                      # Repositories Doctrine (par module)
│   │   ├── Core/
│   │   ├── Sport/
│   │   ├── Vitrine/
│   │   └── Pirb/
│   │
│   ├── Security/                        # Securite
│   │   ├── Voter/                       # ClubScopeVoter, OwnershipVoter...
│   │   └── Tenant/                      # Filtrage multi-tenant par club_id
│   │
│   └── Service/                         # Services metier
│
├── templates/                           # Templates Twig (par espace)
│   ├── vitrine/                         # mabb.fr
│   │   ├── base.html.twig              # Layout vitrine
│   │   ├── navbar.html.twig            # Navbar vitrine
│   │   └── accueil/                    # Pages vitrine
│   │       ├── index.html.twig
│   │       ├── calendrier.html.twig
│   │       ├── club.html.twig
│   │       ├── contact.html.twig
│   │       ├── equipes.html.twig
│   │       ├── galerie.html.twig
│   │       ├── news.html.twig
│   │       └── numerique.html.twig
│   ├── manager/                         # manager.mabb.fr
│   └── pirb/                            # pirb.mabb.fr
│
├── tests/                               # Tests
│   └── bootstrap.php
│
├── translations/                        # Fichiers de traduction
│
├── .editorconfig
├── .env                                 # Variables d'environnement
├── .env.dev
├── .env.test
├── .gitignore
├── compose.yaml                         # Docker Compose
├── compose.override.yaml
├── composer.json                        # Dependances PHP
├── composer.lock
├── importmap.php                        # Import map (assets JS)
├── phpunit.dist.xml
└── symfony.lock
```

## Architecture multi-host

| Sous-domaine         | Espace    | Controllers             | Templates         | Securite       |
|----------------------|-----------|-------------------------|--------------------|----------------|
| `mabb.fr`            | Vitrine   | `Controller/Vitrine/`   | `templates/vitrine/` | Public        |
| `manager.mabb.fr`    | Manager   | `Controller/Manager/`   | `templates/manager/` | Auth requise  |
| `pirb.mabb.fr`       | PIRB      | `Controller/Pirb/`      | `templates/pirb/`    | Auth requise  |
| `*/api`              | API REST  | `Controller/Api/`       | —                    | JWT stateless |

## Modules Entity

| Module    | Entites prevues                                         |
|-----------|---------------------------------------------------------|
| `Core/`   | User, Role, Club, ClubUser, Season                      |
| `Sport/`  | Team, Player, Event, Match, Presence, Convocation, PlayerStat |
| `Vitrine/`| Article, Page, Media                                    |
| `Pirb/`   | PlayerProfile, ShotRecord, MatchEvent, TrainingFeedback |

## Resume des dossiers

| Dossier          | Role                                          |
|------------------|-----------------------------------------------|
| `assets/`        | Frontend : JS (Stimulus), CSS, images         |
| `bin/`           | Commandes executables (console, phpunit)       |
| `config/`        | Configuration Symfony, routes multi-host       |
| `instruction/`   | Documentation, CDC, roadmaps, gouvernance     |
| `migrations/`    | Migrations de base de donnees (Doctrine)       |
| `public/`        | Racine web (index.php + fichiers publics)      |
| `puml/`          | Diagrammes UML du projet                       |
| `shemas/`        | Schemas et dictionnaire de la base de donnees  |
| `src/`           | Code source PHP (monolithe modulaire)          |
| `templates/`     | Templates Twig par espace (vitrine/manager/pirb) |
| `tests/`         | Tests automatises                              |
| `translations/`  | Fichiers de traduction i18n                    |
