# Prompt — Reprise du projet Venaball Club (ex MABB Manager) + Venaball (ex PIRB)

> À lire intégralement avant de toucher quoi que ce soit.
> Mis à jour : 2026-07-13.
>
> Ce document sert à reprendre le projet après une pause, ou à onboarder un dev (humain
> ou assistant IA). Beaucoup a changé depuis la dernière fois : lis d'abord la section
> « Ce qui a changé récemment », puis le doc maître `31_ETAT_REEL_2026-07-13.md`.

---

## Par où commencer, dans l'ordre

1. **`instruction/31_ETAT_REEL_2026-07-13.md`** — l'état réel du code, écrit après lecture
   intégrale. **Il fait foi.** Si un autre doc le contredit, c'est lui qui a raison.
2. **`instruction/24_ETAT_AVANCEMENT_VS_CDC`** — où on en est face au cahier des charges.
3. **`instruction/08_ADR.md`** — les décisions d'architecture, à respecter.
4. **`instruction/06_REGISTRE_TECHNIQUE.md`** + **`07_REGISTRE_SECURITE_RGPD.md`** — la dette
   et les obligations, y compris les trois points ouverts critiques (voir plus bas).
5. **`instruction/13_CLAUDE_LOG.md`** — le journal chronologique de ce qui a été fait.
6. **`instruction/30_TON_ET_STYLE_REDACTIONNEL.md`** — les règles de ton pour tout texte
   visible par un utilisateur (le projet a longtemps « senti l'IA », on corrige ça).

Les instantanés datés (vieux audits, sessions) sont rangés dans `instruction/archive/`,
ils ne pilotent plus rien.

---

## Ce que c'est

Un outil de gestion de club de basket, **en production réelle**, utilisé par de vraies
personnes. Ce n'est pas un projet d'école. Chaque bug en prod a un impact.

Trois espaces web + une app mobile :
- `mabb.fr` — site vitrine public
- `manager.mabb.fr` — l'appli du club, rebaptisée **Venaball Club** (le code garde `Manager`)
- `pirb.mabb.fr` — l'espace joueuse, rebaptisé **Venaball** (le code garde `Pirb`)
- une app mobile Expo / React Native (dépôt séparé `Pirb store`)

**Renommage produit** : PIRB → Venaball, MABB Manager → Venaball Club. Seule l'interface
change, pas le code ni le domaine `pirb.mabb.fr`. Le nom PIRB Scouting existe encore, à part.

---

## Stack

| Couche | Techno |
|---|---|
| Backend | Symfony 7.4 / PHP 8.3 |
| Base | MySQL 8.4 via Doctrine ORM |
| Front | Twig + Symfony UX (Stimulus/Turbo) |
| API mobile | token Bearer maison (`ApiToken`), pas de JWT Lexik |
| Hébergement | OVH mutualisé — **pas de Node, pas de Redis, pas de worker Messenger, pas de cron déclaré dans le dépôt** |
| App mobile | Expo SDK 54 / React Native / TypeScript (dépôt `Pirb store`) |

---

## Architecture — les règles à ne pas violer

**Monolithe modulaire** : un seul projet Symfony pour les 3 espaces web. Découpage par
dossier (`src/Controller/{Vitrine,Manager,Pirb,Api}/`, idem entités et templates).

**Séparation par host + firewalls** : 7 firewalls dans `security.yaml`, un par host. En
dev, configurer `/etc/hosts` (`127.0.0.1 manager.localhost pirb.localhost`).

**Multi-tenant par `club_id` — LA règle la plus importante.** Une seule base, isolation
logique par club. Toute requête métier est filtrée par club **côté serveur**, jamais
seulement côté front. Passer par `TenantResolver::getCurrentClub()` et le `ClubVoter`
(`$this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $entite)`). Violer ça = fuite de
données entre clubs.
⚠️ Attention : l'isolation n'est PAS homogène (certaines entités ont `club_id` sans passer
par le Voter, d'autres n'ont ni l'un ni l'autre). C'est une dette connue, voir doc 31 §1.

**Rôles par club** via `UserClubRole` : un user peut être coach au club A et parent au
club B. Rôles actuels : DIRIGEANT, COACH, STAFF, JOUEUR, PARENT, BENEVOLE, EMPLOYE,
TRESORIER, SECRETAIRE. (Un rôle TECHNICIEN est prévu, voir cadrage `32`.)

---

## Ce qui a changé récemment (depuis le 07/07)

- **Multi-club** : création publique de club (`/creer-un-club`), super-admin cross-club,
  officialisation FFBB via le référentiel `OrganismeFfbb`.
- **Convocations** bout en bout : le coach convoque, PDF + mail + push, la joueuse répond.
- **Push mobile** : `push_token` + `ExpoPushService` (s'active au 1er dev build de l'app).
- **Lien coach ↔ équipe** (`CoachEquipe`, avec saison + rôle principal/assistant), semaine
  du coach (`/planning/semaine`), pointage iPad, bandeau des appels oubliés sur le dashboard.
- **Feedback de séance à anonymat réel** : `FeedbackSeance` (contenu, `joueur_id` NULL si
  anonyme) + `FeedbackParticipation` (qui a répondu, sans le contenu). Ne jamais relier les
  deux. Ne jamais promettre « anonymat garanti » dans l'UI.
- **Passage de saison** (`app:passage-saison`) réécrit : garde chaque joueuse dans son
  équipe, ne réarbitre que les montées de catégorie.
- Doublons supprimés : `NoteSeance` (→ `FeedbackSeance`), `equipe_coach` (→ `CoachEquipe`).

En cadrage, pas encore codé : l'app **Venaball Club mobile** (vues à la GTA parent/bénévole/
coach, renfort en cascade, centre « mes tâches »). Voir `32_CADRAGE_VENABALL_CLUB_MOBILE`.

---

## Les 3 points critiques ouverts (à traiter en priorité)

1. **Aucune sauvegarde de la base de prod n'existe.** À mettre en place avant tout.
2. **Fichiers sensibles servis en clair dans `public/`** : justificatifs financiers,
   photos de mineures accessibles par URL. Seules les décharges sont bien protégées
   (dans `var/decharges/`, servies derrière un contrôleur). À généraliser. Voir RT-0011.
3. **Cron RGPD non déclaré** : la purge des données de mineures (`app:sorties:purger-rgpd`)
   dépend d'un cron OVH non versionné. Vérifier qu'il tourne vraiment.

Autres dettes : minutes jouées mal calculées (Stats Live), promotion des stats manuelle,
doublon d'agrégateurs. Détail dans doc 31 §5 et RT-0012/0013.

---

## Conventions

**Migrations — LIRE ATTENTIVEMENT.** La base de dev locale a **dérivé** de la prod. Donc :
**ne jamais faire confiance à `doctrine:migrations:diff`**, il génère du bruit ou du faux.
Les migrations se **écrivent à la main**, une par changement logique, nom
`VersionYYYYMMDDHHMMSS.php`. Jamais d'édition manuelle de la base.

**Twig** :
- `saison_active()` et `saisons_disponibles()` sont des fonctions Twig globales.
- `|u.truncate()` **n'existe pas** (twig/string-extra absent sur OVH) → utiliser
  `|slice(0, N) ~ '…'`. Ça a déjà cassé la prod une fois.
- Le test `defined` ne marche que sur des variables simples, pas sur `a.b ?? c`.

**Avant de committer** : `php bin/console lint:twig templates/` et `lint:container`
**doivent être verts**. Un template Twig cassé passe `php -l` mais tombe en 500 en prod.

**Ton** : tout texte visible par un utilisateur suit `30_TON_ET_STYLE`. Pas de tiret
cadratin, pas de jargon, on parle au dirigeant de club, pas au dev.

---

## Git & déploiement

**Git — non négociable :** le propriétaire (Clavel) committe et push **lui-même**. On lui
donne les commandes PowerShell à copier-coller, on ne les exécute pas. Jamais de
`git push --force` sur `main`.

**Ne jamais faire confiance à un `git status` qui ne vient pas de la machine de Clavel.**
Un environnement distant (CI, sandbox) peut afficher des fichiers fantômes modifiés ou des
erreurs qui n'existent pas. Le `git status` local de Clavel est la seule vérité.

**Déploiement sur OVH** (SSH) :
```bash
ssh mabbzzyo@ssh.cluster102.hosting.ovh.net
cd ~/mabb-site
git fetch origin && git reset --hard origin/main   # PAS git pull : reference.php modifié en local le bloque
php bin/console doctrine:migrations:migrate --no-interaction
rm -rf var/cache/prod                                # avant le clear : évite l'erreur "directory not empty" du mutualisé
php bin/console cache:clear --env=prod
```
Note : `.env.local`, `var/`, `public/uploads/` sont ignorés/non versionnés → le reset --hard
est sûr, il n'y touche pas.

**Secrets** : jamais de credentials dans le code ni dans le chat. `APP_SECRET`,
`DATABASE_URL`, la clé Brevo, `MAILER_DSN` vivent uniquement dans `.env.local` sur OVH.

---

## Ce qu'on attend d'un dev ici

Pas un exécutant qui pond du code. Quelqu'un qui :
- **lit le code existant avant d'écrire** — le projet est grand (67 entités, 88 contrôleurs),
  et beaucoup de choses existent déjà. Deux doublons ont été créés cette semaine faute
  d'avoir cherché avant. Réflexe : `grep`/`ls` l'entité ou le service avant de le recréer.
- mesure l'impact d'un changement sur le multi-tenant et les autres espaces ;
- dit franchement quand une approche est bancale, même si elle vient de Clavel (pas de
  oui-ouisme) ;
- documente : une décision structurante → `08_ADR.md` ; une session → `13_CLAUDE_LOG.md` ;
- finit une tâche avant d'en ouvrir une autre.

---

## Démarrage local

```bash
git clone https://github.com/Clavelclams/mabb-site.git
composer install
cp .env .env.local          # puis renseigner DATABASE_URL et APP_SECRET
php bin/console doctrine:migrations:migrate
```
Ajouter dans `/etc/hosts` (ou `C:\Windows\System32\drivers\etc\hosts`) :
```
127.0.0.1  manager.localhost  pirb.localhost
```
Puis `http://manager.localhost:8000` et `http://pirb.localhost:8000`.
