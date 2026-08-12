# 39 — API Venaball Club (app staff/famille) · démarré le 4 août 2026

> L'app **Venaball Club** a besoin d'une surface API qui n'existait pas :
> `/api/pirb/*` est entièrement pensée pour la joueuse (chaque endpoint
> commence par « retrouve la fiche Joueur, sinon 404 »). Un coach n'a pas de
> fiche joueuse.
>
> Ce document décrit `/api/club/*`, construite en parallèle du dépôt
> `venaball club-store`. **Le backend est le chemin critique** : sans lui,
> l'app n'affiche rien.

## Décisions structurantes

**1. Une famille d'API séparée, pas une extension de `/api/pirb`.**
Les deux raisonnent à l'envers l'une de l'autre : `pirb` part de la fiche
joueuse, `club` part du couple (compte, club, rôle). Les mélanger produirait
des endpoints à double comportement, impossibles à sécuriser proprement.

**2. Le club voyage dans l'en-tête `X-Club-Id`.**
Un compte peut être coach au club A et parent au club B. L'app doit donc
toujours dire sur quel club elle travaille. L'identifiant envoyé par le client
est **systématiquement vérifié** contre les rôles réels du compte — jamais de
confiance. Si le compte n'a qu'un seul club, l'en-tête est facultatif.

**3. Les « vues » sont calculées côté serveur.**
Neuf rôles métier existent, mais l'app n'a que quatre écrans d'accueil :
`coach`, `benevole`, `parent`, `joueuse` (le switch « à la GTA » du cadrage
doc 32). La projection rôles → vues est faite par le serveur, et une vue n'est
proposée que si elle a du **contenu** : pas de vue coach pour un dirigeant qui
n'entraîne aucune équipe, pas de vue parent sans lien enfant validé.
L'app ne déduit jamais un droit toute seule.

**4. Vue par défaut : la plus « engageante » disponible** (coach > bénévole >
parent > joueuse). Le cadrage envisageait d'ouvrir selon le jour de la semaine
(samedi = coach) ; écarté pour l'instant — ouvrir un écran différent selon le
jour désoriente plus qu'il n'aide, et rien n'a encore été testé sur le terrain.
À revoir après les premiers retours.

**5. On répond 404, pas 403,** pour une ressource d'un autre club ou d'une
équipe qu'on n'encadre pas : on ne confirme jamais l'existence de ce à quoi on
n'a pas droit.

## Périmètre V1, volontairement étroit

> **« Le coach voit ses équipes et ses prochains matchs, convoque et pointe,
> depuis son téléphone. »** Rien d'autre.

Tout faire (parent, bénévole, stats live, renfort en cascade) c'est six mois.
Ce périmètre-là est utile immédiatement et testable au gymnase.

## Ce qui est livré (VC-1 et VC-2)

| Fichier | Rôle |
|---|---|
| `Controller/Api/Club/ApiClubController.php` | Socle : résolution du club, contrôle des rôles, réponses normalisées |
| `Controller/Api/Club/ApiClubException.php` | Erreur métier portant son code HTTP |
| `EventListener/ApiClubExceptionListener.php` | La convertit en JSON (portée limitée à `/api/club`) |
| `Controller/Api/Club/ApiClubMoiController.php` | `GET /api/club/moi` |
| `Controller/Api/Club/ApiClubCoachController.php` | `GET /api/club/equipes`, `GET /api/club/rencontres` |

### `GET /api/club/moi`
Premier appel après connexion. Renvoie l'utilisateur, la saison active, la
liste des clubs avec pour chacun ses rôles et ses vues disponibles, le club
courant et la vue à ouvrir par défaut.

### `GET /api/club/equipes`
Les équipes encadrées cette saison : `{id, nom, categorie, saison, roleCoach,
nbJoueuses}`. `roleCoach` vaut `PRINCIPAL`, `ASSISTANT` ou `DIRIGEANT`.

### `GET /api/club/rencontres`
Paramètres : `?equipe=<id>`, `?periode=avenir|passees|toutes` (défaut
`avenir`), `?limite=<n>` (20 par défaut, 50 max).

## Règle d'isolation : elle est DOUBLE

1. Le **club**, résolu et vérifié par le socle.
2. Les **équipes réellement encadrées** (`CoachEquipe`), et non « toutes les
   équipes du club ».

Un coach de U13 n'a rien à faire dans l'effectif des Séniors : ce sont des
données personnelles de mineures, le besoin d'en connaître s'arrête à ses
propres équipes. **Exception assumée** : un DIRIGEANT voit toutes les équipes
du club — c'est son rôle, et il l'a déjà sur le web.

⚠️ Piège rencontré : `CoachEquipeRepository::findByCoach()` **ne filtre pas par
club**. Un coach de deux clubs verrait les équipes de l'autre. Le filtre est
appliqué explicitement dans `equipesAccessibles()`. Ne pas l'oublier ailleurs.

## VC-3 — Convocation depuis le téléphone ✅

**Le cas d'usage qui justifie l'app à lui seul.** Aujourd'hui un coach doit
ouvrir un ordinateur pour convoquer ; beaucoup n'en ont pas sous la main, donc
la convocation se fait par messages, hors de l'outil — sans trace, sans
réponses centralisées, et sans rien qui remonte à la joueuse.

| Fichier | Rôle |
|---|---|
| `Service/ConvocationManager.php` | **Les règles métier, à un seul endroit** |
| `Controller/Manager/ConvocationController.php` | Refactoré : ne fait plus que le web (CSRF, flash, redirection) |
| `Controller/Api/Club/ApiClubConvocationController.php` | `GET`/`POST /api/club/rencontres/{id}/convocation` |

### Pourquoi un service partagé plutôt qu'un copier-coller

Les règles vivaient dans le contrôleur web, mêlées au formulaire et aux
messages flash. L'app a besoin des **mêmes** règles sans rien de tout ça. Les
recopier aurait garanti qu'elles divergent — le projet en a déjà fait les
frais avec les deux agrégateurs de stats. Le comportement du web est
strictement inchangé.

Règles centralisées : filtre systématique sur l'effectif **réel** (on ne
convoque jamais depuis une liste envoyée par le client), pas de
re-notification d'une joueuse déjà convoquée, journalisation quand on efface
une réponse existante, push envoyé **après** l'enregistrement.

### Contrôle d'accès : double, et les deux sont nécessaires

1. la rencontre appartient au club courant ;
2. le compte encadre **réellement** cette équipe (`CoachEquipe`), ou est
   dirigeant du club.

Sans le second, un coach de U13 convoquerait chez les Séniors et verrait
l'effectif nominatif d'une équipe qui n'est pas la sienne.

### Pas de CSRF sur ces routes, et c'est normal

Le firewall `api` est `stateless: true` et authentifie par jeton Bearer. Sans
cookie de session, il n'y a pas de falsification de requête intersites
possible. Ne pas « ajouter du CSRF pour faire pareil que le web » : ce serait
du bruit sans effet.

### Comportement à connaître côté app

`POST` **remplace** la liste, il ne la complète pas : ce qui n'est pas envoyé
est retiré. L'app doit donc toujours transmettre la liste complète, jamais un
delta. C'est le même comportement que les cases à cocher du web.

## VC-5 — Stats Live : la validation depuis le téléphone ✅

Le constat : en production, **aucune session Stats Live n'avait jamais été
promue officielle** — tout le travail des bénévoles dormait, faute d'un
ordinateur sous la main pour le geste de validation.

- `GET /api/club/stats-live/a-valider` : les rencontres de mes équipes ayant
  des sessions non archivées et **aucune officielle**, avec pour chaque
  session le nombre d'actions (une requête groupée, pas de N+1), le
  saisisseur et le statut. Sert l'écran phare de l'app et le compteur
  d'alerte de l'accueil.
- `POST /api/club/sessions/{id}/promouvoir` : promeut en OFFICIELLE via
  `SessionStatsLivePromoteur` — le MÊME service que le web (rétrogradation
  de l'ancienne officielle, clôture des présences terrain, génération des
  EvaluationMatch). Zéro logique dupliquée. Un état incompatible (déjà
  officielle, archivée) renvoie 409 ; un manque de droits renvoie 404.

Côté app : écran `stats-live.tsx` (sessions triées par nombre d'actions, la
plus complète marquée ★, garde-fou quand on valide une session moins remplie
qu'une autre) + alerte jaune sur l'accueil coach tant que `total > 0`.

**Décision produit actée avec Clavel** : la SAISIE native sur téléphone
(style Easy Stats — flux 2 taps, grosses cibles, paysage) est un chantier à
part entière, à maquetter avant de coder. La validation, elle, est le
chaînon qui manquait : elle est livrée.

## Landings publiques (même session, hors API)

`manager.mabb.fr/` et `pirb.mabb.fr/` montrent désormais une **page de
présentation** aux visiteurs anonymes (`templates/manager/decouvrir.html.twig`,
`templates/pirb/decouvrir.html.twig`) au lieu de rediriger vers un login sec.
Route `^/$` passée en PUBLIC_ACCESS sur les deux hosts ; les connectés vont
tout droit à leur dashboard, rien ne change pour eux.

## Suite prévue

- **VCA-5** : saisie Stats Live NATIVE sur téléphone (le chantier phare) —
  maquette du flux d'abord, puis mode hors-ligne à évaluer (les gymnases
  captent mal).
- **VC-4** : pointage de séance.
- **VC-5** : rappel au coach des sessions Stats Live non validées (voir doc 38
  point 11 : aucune session n'a jamais été promue en production, donc aucune
  statistique n'est jamais remontée aux joueuses).

Non décidé, à trancher avant d'aller plus loin : le rôle **TECHNICIEN** du
cadrage doc 32. Il n'est **pas nécessaire** au périmètre V1 — inutile de
toucher au `ClubVoter` d'une application en production tant qu'aucun écran
n'en dépend.

## Tests

Cette API est le bon endroit pour commencer les tests attendus au jury CDA
(doc 38, point 6) : elle est neuve, isolée, et son enjeu — l'isolation
multi-club — est précisément ce qu'un jury demande à voir tester.
