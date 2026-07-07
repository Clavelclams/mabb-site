# Prompt — Contexte développeur collaborateur MABB Manager + PIRB

> À lire intégralement avant de toucher quoi que ce soit.
> Mis à jour : 2026-07-07

---

## Qui je suis et ce qu'on construit

Je m'appelle Clavel. Je suis en alternance CDA (Concepteur Développeur d'Applications, Titre Pro Niveau 6, AFPA Amiens — jury avril 2027). Je développe seul depuis plusieurs mois un outil de gestion de club de basket-ball en production réelle.

Le projet sert le club MABB (Amiens). Il est live sur :
- `mabb.fr` — site vitrine public
- `manager.mabb.fr` — application métier pour le staff
- `pirb.mabb.fr` — espace joueur/parent (PIRB = nom interne de l'app joueur)

Ce n'est pas un projet de formation. C'est un vrai outil utilisé par de vraies personnes. Chaque bug en prod a un impact.

---

## Stack technique

| Couche | Technologie |
|--------|-------------|
| Backend | Symfony 7.4 / PHP 8.3 |
| Base de données | MySQL 8.4 via Doctrine ORM |
| Frontend | Twig + Symfony UX (Stimulus + Turbo) |
| API | API Platform (installé, usage partiel) + JWT (LexikJWT) |
| Hébergement | OVH mutualisé (contraintes : pas de Node, PHP-CLI limité) |
| Déploiement | git push → SSH OVH → git pull + cache:clear |
| Tests | PHPUnit 12 (tests unitaires + fonctionnels) |
| Emails | Brevo (MAILER_DSN, à configurer en prod) |

---

## Architecture — Décisions structurantes à respecter

### Monolithe modulaire (ADR-0001)
Un seul projet Symfony servant les 3 espaces. Le découpage se fait par dossier :
- `src/Controller/{Vitrine,Manager,Pirb,Api}/`
- `src/Entity/{Core,Sport,Vitrine,Pirb}/`
- `templates/{vitrine,manager,pirb}/`

### Séparation par host + firewalls (ADR-0002)
Chaque espace a son fichier de routes (`config/routes/{vitrine,manager,pirb}.yaml`) et son firewall dans `security.yaml`. En dev local, il faut configurer `/etc/hosts` :
```
127.0.0.1  manager.localhost  pirb.localhost
```

### Multi-tenant par club_id (ADR-0003) — CRITIQUE
Une seule base de données, isolation logique par `club_id`. **Chaque requête métier doit être filtrée par club côté serveur.** Jamais uniquement côté front. Implémentation :
- `TenantResolver` → résout le club actif depuis la session
- `ClubVoter` (6 attributs) → vérifie les accès côté Manager
- Côté PIRB : isolation par `Joueur.user` + vérifications club/équipe par route

Violer cette règle = fuite de données inter-club. C'est la règle la plus importante du projet.

### Rôles par club (ADR-0006)
Les rôles sont attribués par club (un utilisateur peut être Coach dans le club A et Parent dans le club B). Table `Role` (catalogue) + pivot `ClubUserRole` (audit natif).

---

## État du projet — Ce qui est en prod (juillet 2026)

| Module | Statut |
|--------|--------|
| Auth multi-host, TenantResolver, firewalls | ✅ prod |
| Gestion équipes / joueurs / staff | ✅ prod |
| Séances, présences, missions | ✅ prod |
| Rencontres + imports FFBB (PDFs tirs, résumé, feuille) | ✅ prod |
| Stats Live match (actions temps réel + match interne A/B) | ✅ prod |
| Shot chart (terrain interactif + tirs FFBB parsés) | ✅ prod |
| ENT (documents club — upload manager, lecture PIRB) | ✅ prod |
| Gamification (XP, niveaux, badges) par saison | ✅ prod |
| Classement XP | ✅ prod |
| Espace PIRB (dashboard, stats, équipe, bilans, documents) | ✅ prod |
| Vitrine + CMS blocs + mentions légales | ✅ prod |
| Gestion des saisons (bascule automatique au 1er juillet) | ✅ prod |
| Tests unitaires entités + services | ✅ partiellement en place |
| API PIRB (Bearer JWT, stats, niveau, shot chart) | ✅ en place |

---

## Ce qui reste à faire (priorités)

### P1 — Urgent

| ID | Feature |
|----|---------|
| B-102 | Feedback inscription bénévole rencontre (flash PIRB + badge) |
| B-304 | MAILER_DSN Brevo configuré en prod sur OVH |

### P2 — Prochaine session

| ID | Feature |
|----|---------|
| B-201 | Workflow validation officielle stats live par le coach |
| B-204 | Pastille rouge notifications non lues côté PIRB |
| B-206 | Fix 404 `manager.mabb.fr/signup` depuis lien PIRB |

### P3 — Backlog

| ID | Feature |
|----|---------|
| B-302 | Shot chart — terrain cliquable PIRB (saisie tirs à finaliser) |
| B-305 | Parse PDF FFBB automatique à l'upload |
| B-306 | Toggle FFBB/Live sur la shot map PIRB |
| B-301 | Refonte ENT style Kalisport (filtres, vignettes, prévisualisation) |

---

## Conventions de code

### Nommage
- Controllers : `NomController.php` dans le bon namespace (`Manager/`, `Pirb/`, etc.)
- Routes : préfixe `manager_`, `pirb_`, `vitrine_`, `api_`
- Templates : `templates/{espace}/module/action.html.twig`
- Services : un service = une responsabilité, dans `src/Service/` ou sous-dossier thématique

### Symfony
- `autoconfigure: true` dans `services.yaml` → les services et extensions Twig s'auto-wirent
- Routes déclarées via attribut PHP `#[Route(...)]` — pas en YAML sauf cas exceptionnels documentés (exemple : `saison_changer` déclaré explicitement dans `config/routes.yaml` pour éviter un bug de chargement avec un fichier unique)
- CSRF sur tous les formulaires POST

### Migrations
Une migration par changement logique. Jamais d'édition manuelle de la DB. Toujours `doctrine:migrations:diff` puis vérifier avant de committer. Convention de nommage : `VersionYYYYMMDDHHMMSS.php`.

### Templates Twig
- `saison_active()` et `saisons_disponibles()` sont des fonctions Twig globales (`SaisonExtension`)
- `|u.truncate()` **non disponible sur OVH** → utiliser `|slice(0, N) ~ '…'`
- Pas d'apostrophes dans les strings `COMMENT` SQL (cassent le parsing MySQL)

---

## Points critiques à ne pas rater

### Sur OVH mutualisé
- Pas de Node.js, pas de Redis, pas de worker Messenger
- `APP_ENV=prod` sur OVH → toujours vider le cache après un déploiement
- Les fichiers uploadés sont dans `public/uploads/` (pas de `var/uploads/` côté OVH mutualisé)

### Sur le multi-tenant
- **Toujours** injecter `TenantResolver` et récupérer `getCurrentClub()` avant toute requête métier
- **Toujours** utiliser `$this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club)` dans les controllers Manager
- Côté PIRB : vérifier que `$joueur->getUser() === $this->getUser()` et que le club de la ressource correspond au club du joueur

### Sur la gamification
- `XpCalculator::xpSaison($joueur, $saison)` retourne 0 automatiquement si aucun événement dans la saison — pas besoin de vérification spéciale
- Le reset saison est automatique : on ne supprime rien, on filtre par saison

### Sur les saisons
- `SaisonService::getSaisonCourante()` : bascule au **1er juillet** (convention administrative FFBB)
- `SaisonService::getSaisonActive()` : retourne le choix manuel en session si valide, sinon la courante
- Ne jamais hardcoder une saison (`'2026-2027'`) → toujours passer par `SaisonService`

---

## Structure des fichiers de référence

Tout le contexte technique est dans le dossier `instruction/` à la racine du projet :

| Fichier | Contenu |
|---------|---------|
| `01_LIRE_AVANT_TOUT.md` | Règles fondamentales, périmètre, structure |
| `02_ROADMAP_GLOBALE.md` | Vision, état d'avancement par module, évolutions |
| `06_REGISTRE_TECHNIQUE.md` | Points critiques (DB, sécurité, perfs, dette) |
| `07_REGISTRE_SECURITE_RGPD.md` | Obligations sécurité et conformité RGPD |
| `08_ADR.md` | Toutes les décisions d'architecture documentées |
| `09_BACKLOG.md` | Backlog actif + bugs connus |
| `13_CLAUDE_LOG.md` | Journal de toutes les sessions de dev (quoi, pourquoi, impact) |
| `shemas/dictionnaire_db.md` | Dictionnaire complet de la base de données |

---

## Règles de collaboration

### Git — NON NÉGOCIABLE
- **Je committe moi-même.** Ne jamais lancer `git commit` à ma place, même si je ne le demande pas explicitement.
- Me donner les commandes PowerShell à copier-coller, pas les exécuter.
- Ne jamais `git push --force` sur `main`.

### Déploiement
Le déploiement = `git push origin main` depuis PowerShell local, puis SSH OVH :
```bash
ssh clavelclams12@mabb.fr
cd ~/www
git pull origin main
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
# Si nouvelles migrations :
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

### Communication
- Je veux comprendre **pourquoi** tu fais un choix, pas juste le code
- Si une idée est mauvaise, dis-le clairement avec les raisons — pas de oui-ouisme
- Tâche par tâche : finir ce qui est commencé avant d'ouvrir un nouveau chantier
- Chaque décision structurante → entrée dans `08_ADR.md`
- Après chaque session → log dans `13_CLAUDE_LOG.md`

### Sécurité
- Jamais de credentials dans le code ou dans le chat
- `.env.local` sur OVH n'est pas versionné — les variables sensibles y sont

---

## Pour démarrer

1. Clone du repo : `git clone https://github.com/Clavelclams/mabb-site.git`
2. `composer install`
3. Copier `.env` en `.env.local`, configurer `DATABASE_URL` et `APP_SECRET`
4. `php bin/console doctrine:migrations:migrate`
5. Lire `instruction/01_LIRE_AVANT_TOUT.md` puis `instruction/02_ROADMAP_GLOBALE.md`
6. Regarder le backlog dans `instruction/09_BACKLOG.md`

Pour tester les espaces Manager et PIRB en local, ajouter dans `/etc/hosts` (ou `C:\Windows\System32\drivers\etc\hosts` sur Windows) :
```
127.0.0.1  manager.localhost  pirb.localhost
```
Puis accéder à `http://manager.localhost:8000` et `http://pirb.localhost:8000`.

---

## Ce que j'attends du collaborateur

Pas un exécutant qui pond du code sans expliquer. Un vrai dev capable de :
- Lire le code existant avant d'écrire
- Identifier les impacts d'un changement sur le reste du projet
- Signaler quand une approche est bancale, même si elle vient de moi
- Documenter ce qui est fait (ADR si structurant, log si important)
- Respecter les conventions existantes au lieu d'imposer les siennes
