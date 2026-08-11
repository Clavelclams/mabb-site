# 16 — Inspiration VEA → MABB Manager

> Créé le 2026-05-23. Doc de capture d'idées : ce qu'on transpose du back-office VEA
> (`vea.velito.fr/admin`, en exploitation réelle) vers `manager.mabb.fr` (à construire).
> **Ce n'est PAS un plan de dev immédiat.** Priorité actuelle = finir mabb.fr (retours Willy).
> Ce fichier sert à ne rien perdre de la réflexion en attendant.

---

## 0. La règle d'or à ne jamais oublier

VEA est en **Next.js / React / TypeScript / Prisma / Supabase**.
MABB est en **Symfony / PHP / Doctrine / Twig / MySQL**.

➡️ **On ne copie pas une ligne de code.** Aucun fichier VEA n'est exécutable dans MABB.
Ce qui se transpose, c'est : le **modèle de données**, les **parcours utilisateur**, et la **logique métier**.
On les **ré-implémente** en PHP/Symfony.

**Gain réel = temps de conception, pas temps de codage.** Et la conception, c'est précisément
ce que le jury CDA note dans le Bloc 2 (« concevoir une application organisée en couches »).
Donc cette démarche a une vraie valeur jury — à condition de savoir l'expliquer.

---

## 1. Ce que fait le dashboard admin VEA (observé en live)

**Structure de la page** (`/admin`) :
1. Un **header** : badge « Admin VEA » + titre + sous-titre.
2. **4 cartes KPI** cliquables, calculées côté serveur (comptages base) :
   Membres actifs · Old VEA · Pré-inscrits · Events à venir.
3. Une **grille de cartes-modules** (chaque carte = un raccourci vers un écran) :
   Bilan & Suivi · Espace Président · Évènements · Heures/XP · Récompenses ·
   Tournois online · Dépôt documents · Rapports/Réunions · Compta/Trésorerie · Demandes de devis.

**Point technique important** (à reproduire côté Symfony) : la page serveur (`page.tsx`)
fait **2 choses avant d'afficher quoi que ce soit** :
- elle **vérifie la permission** (`hasPermission("vea", "editor")`) → sinon écran « accès restreint » ;
- elle **calcule les KPI** par des requêtes de comptage, puis les passe au composant d'affichage.

➡️ En Symfony, ça donne : un **contrôleur** qui (a) `denyAccessUnlessGranted()` via un Voter,
(b) interroge les **Repositories** pour les comptages, (c) passe les chiffres au **template Twig**.
C'est exactement le découpage en couches Contrôleur → Repository → Vue que le jury veut voir.

---

## 2. Mapping module par module (VEA → MABB Manager)

| Module VEA | Équivalent MABB Manager | Entités MABB nécessaires | Réutilisable ? |
|---|---|---|---|
| Tableau de bord (KPI + cartes) | Dashboard Manager (KPI club + raccourcis) | — (lecture seule) | ✅ structure directe |
| Évènements + check-in QR | **Séances d'entraînement + Matchs + Présence** | `Seance`, `Match`, `Presence` | ✅ concept, ⚠️ adapté (voir §3) |
| Participants / annuaire joueurs | **Effectif / fiches joueurs** | `Joueur`, `Equipe` | ✅ fort |
| Heures / XP (attribution manuelle) | Suivi présence + gamification PIRB (V2) | `Presence`, plus tard XP | 🟡 partiel, V2 |
| Bilan & Suivi (stats subvention) | **Bilan club** (taux présence, effectifs) | lecture `Presence`/`Joueur` | ✅ fort (Bloc 2/3) |
| Espace Président | **Espace Dirigeant** (pilotage club) | rôle `ROLE_DIRIGEANT` | ✅ direct |
| Dépôt documents | **ENT documents club** (licences, certificats) | `Document` | ✅ (déjà au backlog V2) |
| Rapports / Réunions (PV) | **Suivi réunion hebdo MABB + diffusion résumé** | `Reunion`, `Rapport` | ✅ MABB flagship (cf. §8) |
| Compta / Trésorerie | **Conservé pour MABB** (club phare) | `OperationCompta` | ✅ MABB flagship (cf. §8) |
| Tournois / Récompenses / Demandes devis | Pas d'équivalent direct | — | ❌ spécifique esport/asso |

---

## 3. La brique reine : la présence (et pourquoi on N'y va PAS façon VEA brute)

C'est l'idée que tu as eue : « le scan VEA pour valider une présence à un event » →
« le coach qui pointe l'entraînement, et ajoute ceux qui n'ont pas scanné ».

**Transposition honnête (pas de copie aveugle) :**

VEA scanne parce que son public, c'est des **inconnus en drop-in** dans les quartiers, souvent
**sans compte ni téléphone** → le QR + la pré-inscription invité résolvent un vrai problème :
compter des gens qu'on ne connaît pas d'avance.

MABB, c'est l'inverse : une **équipe = un effectif connu de 12-15 licenciés**. Le coach connaît
tout le monde. Donc :

- **Mécanique principale MABB = le coach coche présent/absent** sur une liste pré-remplie de
  l'effectif. Rapide, fiable, c'est le geste naturel d'un entraîneur.
- **Le scan QR devient une commodité optionnelle** (le joueur scanne en arrivant pour
  se pointer lui-même), pas le cœur du système.
- **L'« ajout manuel de ceux qui n'ont pas scanné »** que tu décris = en réalité le mode normal
  côté MABB : on part de l'effectif, le coach ajuste.

➡️ On garde le **concept** VEA (présence horodatée, archivage, jamais de perte de donnée,
pas de double comptage) mais on **inverse le défaut** : check manuel d'abord, scan en bonus.

**Bonus conceptuel à voler à VEA — le meilleur :**
le model `Participant` de VEA est **découplé du compte utilisateur** (un participant peut exister
sans `User`, et être rattaché plus tard). C'est exactement ce qu'il te faut pour MABB :
**un `Joueur` ne doit PAS dépendre d'un compte `User`.** Beaucoup de jeunes licenciés n'auront
jamais de compte sur l'app. Donc `Joueur` = entité autonome, avec un lien *optionnel* vers `User`.
Ça t'évite l'erreur classique « pas de compte = pas de joueur ».

**Convocation = la version basket, plus riche que VEA :**
le backlog V2 MABB prévoit déjà « convocations avec réponses (présent/absent/incertain) ».
C'est mieux que la participation VEA : c'est un **RSVP avant le match**, pas juste un pointage
après coup. À traiter comme une entité à part (`Convocation`) liée au `Match`.

---

## 4. Le mapping des rôles est DÉJÀ câblé dans MABB

Bonne nouvelle : ta `security.yaml` anticipe exactement les espaces de VEA.

| Espace VEA | Rôle MABB existant | Zone `access_control` déjà prévue |
|---|---|---|
| Espace Président | `ROLE_DIRIGEANT` | `^/club` (host manager) |
| Admin / bureau | `ROLE_SUPER_ADMIN` | `^/admin` (host manager) |
| Coach (pointe présence) | `ROLE_COACH` | `^/coach` (host manager) |
| Membre connecté | `ROLE_USER` | `^/` (host manager) |

➡️ Les portes (routes `/club`, `/coach`) sont déjà déclarées dans `access_control` —
il manque juste les contrôleurs derrière. Quand le jury demandera « pourquoi des règles
d'accès vers des routes sans contrôleur ? », la réponse est : **architecture multi-rôles
anticipée, on a posé la serrure avant la porte**. Sache le formuler comme une décision, pas un oubli.

---

## 5. Entités Manager à créer (dérivées de cette analyse)

Toutes portent un **`club_id`** (contrainte multi-tenant V1, cf. ADR-0003) et passent par un **Voter**.

- **`Equipe`** : club_id, nom, catégorie (U13, séniors…), saison.
- **`Joueur`** : club_id, equipe_id, prénom, nom, date de naissance, poste, numéro,
  `user_id` **(nullable — lien optionnel vers un compte)**.
- **`Seance`** (entraînement) : club_id, equipe_id, date, lieu.
- **`Match`** : club_id, equipe_id, adversaire, date, lieu, domicile/extérieur,
  score équipe, score adverse, statut (`brouillon` → `validé` → `verrouillé`).
- **`Presence`** : lien vers `Seance` (ou `Match`), joueur_id, présent (bool),
  source (`manuel` / `scan`), motif d'absence éventuel.
- **`Convocation`** : match_id, joueur_id, réponse (`present`/`absent`/`incertain`), motif.
- *(plus tard, Bloc 2 fort + différenciateur)* **`StatMatch`** : match_id, joueur_id,
  points, rebonds, passes… → c'est le socle du futur shot chart PIRB.
  **Aucun équivalent VEA — à construire de zéro.**

---

## 6. À NE PAS transposer (garde-fou anti-yesman)

- **La gamification XP de VEA** est calibrée pour fidéliser des jeunes en inclusion sociale.
  En basket licencié, plaquer des XP « +10 par match » peut sonner gadget. À réserver à PIRB,
  en V2, et seulement si ça sert un usage réel (assiduité), pas pour faire joli.
- **Les motifs « Jouer / Aider / Regarder »** sont propres à l'esport de quartier. En basket,
  la présence est binaire (présent/absent) + un statut de convocation. Ne pas copier les motifs.
- **Tournois / Récompenses / Demandes de devis** : modules VEA hors sujet pour MABB.
  *(Compta et Rapports/Réunions sont au contraire CONSERVÉS pour MABB — club phare, cf. §8.)*
- **Le cœur sportif (Match, Stat, shot chart) n'existe pas dans VEA.** Ne pas croire que VEA
  fait gagner du temps là-dessus : c'est ~100 % à concevoir et coder soi-même.

---

## 7. Verdict

VEA fait gagner du temps de **conception** sur ~30-40 % du Manager :
présence, fiches joueurs, dashboard, bilan, espaces par rôle, dépôt documents.
Le **cœur basket** (matchs, stats, shot chart) reste à faire intégralement.

L'idée de Clavel est juste — à condition de transposer les **concepts** et d'**inverser**
la mécanique de présence (check manuel d'abord, scan en bonus) pour coller à un effectif connu.

---

## 8. Raffinements Clavel — 2026-05-23

### 8.1 MABB = club phare → modules « premium » (tiering multi-tenant)

Décision : MABB a **plus de modules** que les autres clubs (Compta, Rapports/Réunions
conservés). Les autres clubs (futurs tenants) ont une version plus légère.

**Mon avis (à appliquer absolument) :** ne JAMAIS coder `if (club == MABB)`. Ce serait du
code en dur, impossible à défendre devant le jury et invendable à d'autres clubs.
La bonne façon = un **système d'abonnement / formule par club** :

- Ajouter sur l'entité `Club` un champ `plan` (ex. `essentiel` / `pro` / `phare`)
  **ou** une liste `modulesActifs` (JSON : `["compta","reunions","stats"]`).
- Chaque module vérifie « ce club a-t-il ce module activé ? » via un **Voter** dédié
  (`ModuleVoter`) en plus du contrôle de rôle.
- MABB = plan `phare` avec tous les modules. Un futur club = plan `essentiel`.

➡️ Avantage jury : tu démontres une **vraie pensée SaaS multi-tenant** (formules,
feature-flags), pas un favoritisme codé en dur. C'est exactement le genre de décision
d'architecture qui fait la différence au Bloc 2. À documenter dans un ADR.

### 8.2 Suivi des réunions hebdo + diffusion de résumé

Contexte : réunion **tous les vendredis matin** au club. Besoin = garder une trace
+ envoyer un résumé aux personnes concernées.

**Mon avis :**
- Entité `Reunion` : club_id, date, ordre du jour, compte-rendu (Markdown ou WYSIWYG),
  liste de présents, liste de destinataires du résumé.
- **V1 = envoi MANUEL** : le dirigeant rédige le CR, clique « Envoyer le résumé » →
  Symfony Mailer (déjà installé) envoie aux destinataires. **Pas d'automatisation au début.**
- L'auto-envoi récurrent (tous les vendredis) = V2, et seulement si le besoin est réel.
- ⚠️ Garde-fou : l'envoi d'emails à des destinataires = action sensible. Toujours une
  **confirmation explicite** avant envoi, jamais d'envoi silencieux. RGPD : destinataires
  internes au club uniquement, consentement implicite OK car membres du bureau.

### 8.3 Chatbot V1 — sans IA, réponses pré-écrites

Décision : un chatbot, mais **V1 sans IA** — réponses pré-faites (FAQ / arbre de décision).

**Mon avis (honnête) :**
- **Bon choix de commencer sans IA.** Un bot à réponses scriptées est : plus simple à coder,
  100 % maîtrisé (zéro hallucination), RGPD-propre (aucune donnée envoyée à un tiers),
  et **plus facile à défendre au jury** qu'un bot IA qu'on ne contrôle pas.
- Implémentation V1 = arbre de questions/réponses (boutons « Comment m'inscrire ? »,
  « Horaires d'entraînement ? »…) → réponses stockées en base (`FaqEntree`) ou en config.
  Pas de NLP, pas de modèle.
- **MAIS — priorité honnête :** le chatbot est un **« nice to have »**, pas un élément que
  le jury CDA réclame. Il ne doit PAS passer avant le cœur Manager (entités, présence)
  ni avant les tests. À placer en fin de file, une fois le socle solide.
  Risque réel pour toi : multiplier les features sympas et arriver au jury avec 10 trucs
  à 50 % au lieu de 5 trucs finis. **Reste carré : on finit avant d'élargir.**

### Entités ajoutées par ces raffinements
- `Reunion` (club_id, date, ordreDuJour, compteRendu, présents, destinataires)
- `OperationCompta` (club_id, type recette/dépense, montant, catégorie, date, justificatif)
- `FaqEntree` (club_id, question, réponse, ordre) — pour le chatbot scripté
- Champ `plan` (ou `modulesActifs`) sur `Club` + `ModuleVoter`
