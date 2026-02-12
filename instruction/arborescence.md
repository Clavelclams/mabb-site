# Arborescence du projet MABB-Site

Projet Symfony - Site web du MABB (club sportif)

```
mabb-site/
│
├── assets/                          # Assets frontend
│   ├── app.js                       # Point d'entree JavaScript
│   ├── controllers.json             # Config des controllers Stimulus
│   ├── stimulus_bootstrap.js        # Bootstrap Stimulus
│   ├── controllers/                 # Controllers JavaScript (Stimulus)
│   │   ├── csrf_protection_controller.js
│   │   └── hello_controller.js
│   ├── images/                      # Images assets
│   │   ├── bg.jpg
│   │   ├── image01.jpg
│   │   └── logo.jpg
│   └── styles/                      # Feuilles de styles
│       └── app.css
│
├── bin/                             # Binaires CLI
│   ├── console                      # Console Symfony
│   └── phpunit                      # Lanceur de tests
│
├── config/                          # Configuration Symfony
│   ├── bundles.php                  # Bundles enregistres
│   ├── preload.php                  # Preloading PHP
│   ├── reference.php
│   ├── services.yaml                # Configuration des services
│   ├── routes.yaml                  # Routes principales
│   ├── packages/                    # Configuration par package
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
│   │   ├── security.yaml
│   │   ├── translation.yaml
│   │   ├── twig.yaml
│   │   ├── ux_turbo.yaml
│   │   ├── validator.yaml
│   │   └── web_profiler.yaml
│   └── routes/                      # Routes par bundle
│       ├── framework.yaml
│       ├── security.yaml
│       └── web_profiler.yaml
│
├── instruction/                     # Documentation / Instructions
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
│   └── arborescence.md              # (ce fichier)
│
├── migrations/                      # Migrations Doctrine (base de donnees)
│
├── public/                          # Racine du serveur web
│   ├── index.php                    # Point d'entree de l'application
│   └── images/                      # Images publiques
│       ├── bg.jpg
│       └── manar.jpg
│
├── puml/                            # Diagrammes PlantUML
│   ├── index.png
│   └── index.puml
│
├── src/                             # Code source PHP
│   ├── Kernel.php                   # Kernel Symfony
│   ├── Command/                     # Commandes console
│   │   └── PumlCommand.php
│   ├── Controller/                  # Controllers HTTP
│   │   └── AccueilController.php    # Controller principal (pages du site)
│   ├── Entity/                      # Entites Doctrine (modeles BDD) - vide
│   └── Repository/                  # Repositories Doctrine - vide
│
├── templates/                       # Templates Twig
│   ├── base.html.twig               # Template de base (layout)
│   ├── navbar.html.twig             # Barre de navigation
│   └── accueil/                     # Pages du site
│       ├── index.html.twig          # Page d'accueil
│       ├── calendrier.html.twig     # Calendrier
│       ├── club.html.twig           # Presentation du club
│       ├── contact.html.twig        # Page de contact
│       ├── equipes.html.twig        # Equipes
│       ├── galerie.html.twig        # Galerie photos
│       ├── news.html.twig           # Actualites
│       └── numerique.html.twig      # Numerique
│
├── tests/                           # Tests
│   └── bootstrap.php
│
├── translations/                    # Fichiers de traduction
│
├── .editorconfig                    # Configuration editeur
├── .env                             # Variables d'environnement
├── .env.dev                         # Variables d'env (dev)
├── .env.test                        # Variables d'env (test)
├── .gitignore                       # Fichiers ignores par Git
├── compose.yaml                     # Docker Compose
├── compose.override.yaml            # Docker Compose (override)
├── composer.json                    # Dependances PHP
├── composer.lock                    # Lock des dependances PHP
├── importmap.php                    # Import map (assets JS)
├── phpunit.dist.xml                 # Configuration PHPUnit
└── symfony.lock                     # Lock Symfony
```

## Resume

| Dossier        | Role                                      |
|----------------|-------------------------------------------|
| `assets/`      | Frontend : JS (Stimulus), CSS, images     |
| `bin/`         | Commandes executables (console, phpunit)   |
| `config/`      | Configuration Symfony et packages          |
| `migrations/`  | Migrations de base de donnees (Doctrine)   |
| `public/`      | Racine web (index.php + fichiers publics)  |
| `puml/`        | Diagrammes UML du projet                   |
| `src/`         | Code source PHP (Controller, Entity, etc.) |
| `templates/`   | Templates Twig (vues HTML)                 |
| `tests/`       | Tests automatises                          |
| `translations/`| Fichiers de traduction i18n                |
