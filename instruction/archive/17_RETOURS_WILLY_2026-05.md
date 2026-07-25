# 17 — Backlog retours Willy + idées (mabb.fr & manager.mabb.fr)

> Créé le 2026-05-24. Source : retours de Willy (président MABB) sur mabb.fr + idées Clavel.
> **Objectif : finir/peaufiner mabb.fr (déjà en ligne) AVANT de bâtir le Manager.**
> Statut : `[ ]` à faire · `[~]` en cours · `[x]` fait · `[?]` bloqué (décision/donnée manquante)
> Convention : chaque point pointe vers le(s) fichier(s) concerné(s).

---

## A. Corrections design / CSS — *rapides, aucune donnée externe, gros impact visuel*

- [x] **A1. Unifier les fonds non-blancs.** ✅ FAIT le 2026-05-25.
  Palette de marque pipettée depuis `instruction/bg/` :
  - `--mabb-dark` = `#0e2a54` (navy, 2.png) · `--mabb-blue` = `#0062a8` (royal, 2.png) · `--mabb-light` = `#1c7ec4`
  - `--mabb-orange` = `#fc702a` (1.png, choix Clavel — remplace le `#ff8c00` de Willy)
  - **Rouge `#ee1c38` = ERREUR (autre projet)** → écarté, `bg-red.png` supprimé, `--mabb-red` inchangé (`#ff3b2f`).
  Source de vérité dédupliquée (bug `--mabb-orange` `#ff7a00`/`#ff8c00` résolu).
  Fond global du site = image `bg-blue.png`. **Header gardé en teal** (choix Clavel/Willy).
  Fichiers : `base.html.twig`, `app.css`, `vitrine.css`, ~9 templates vitrine. Accents conservés volontairement :
  bouton aide-devoir (A3), variante teal cartes formation, couleur catégorie « Loisir ».
- [x] **A2. Page Réseaux : fond plus foncé que le site mais UNIFIÉ** (pas plusieurs bleus), à éclaircir/clarifier.
  → `templates/vitrine/nos_reseaux/index.html.twig`
- [x] **A3. Bouton « Score'N'Co »** : bordure bleu foncé sur fond bleu = moche.
  → blanc par défaut, bleu au hover (état de base actuel inversé).
  → fichier à localiser (`index.html.twig` ou `equipes`) + `vitrine.css`
- [x] **A4. Navbar header déborde** et mange le bouton « Déconnexion » (desktop ET mobile).
  → `templates/vitrine/navbar.html.twig`
- [x] **A5. Mobile — page Équipe 3x3** : boutons « Toutes les équipes » et « Nous rejoindre » se chevauchent.
  → `templates/vitrine/accueil/equipes_3x3.html.twig`
- [x] **A6. Home — animation compteur sur TOUTES les cards** (pas que « licenciés ») :
  étendre aux cards label « quartier » et « engagement ».
  → `templates/vitrine/accueil/index.html.twig`

---

## B. Mises à jour de contenu — *données fournies par Clavel, faisable maintenant*

- [ ] **B1. Page Club — remplacer « bureau des dirigeants » par l'ORGANIGRAMME MABB :**
  → `templates/vitrine/accueil/club.html.twig`
  - Willy — Président / Directeur Général
  - Moussa — Coordinateur
  - Romy — Responsable Social
  - Ugo — Responsable Sportif
  - Clavel — Communication & Développement Numérique
  - Larissa — BPJEPS · Leny — BPJEPS
  - Tony — Alternance Communication
  - Services civiques · Stagiaires · Bénévoles
- [ ] **B2. Page Accueil — « Club Formateur ★★★ / Citoyenneté ★★★ / Mini Basket ★★★ »**
  devient un lien cliquable vers les avis Google.
  → `templates/vitrine/accueil/index.html.twig` (lien Google avis MABB fourni)
- [ ] **B3. Équipes — U15 = « Régional / Pré-National »** (pas seulement Régional).
  → `templates/vitrine/accueil/equipes.html.twig`
- [ ] **B4. Équipe 3x3 — compléter joueurs Lenny & Tony** + ajouter les équipes 3x3 saison **25/26** :
  → `templates/vitrine/accueil/equipes_3x3.html.twig`
  - **U13** — A : Laya, Sorena, Soumeya, Wendy · B : Mathilde, Princia, Charlie, Fever
  - **U15** — A : Emeline, Léa, Manon, Bernice · B : Lola, Fatou, Mahawa, Marianne
  - **U18** — A : Genaba, Oumayma, Cler-mirice, Chloé · B : Anna, Nola, Yana, Djaya
  - **Senior F** — Sounkamba, Rec-prefie, Jody, Laryssa
  - **Senior H** — A : Ugo, Leny, Tony, Clavel · B : Albert, Berstelien, Emeryc, Alexis
  ⚠️ Vérifier l'orthographe des prénoms avec Willy (ex. « Rec-prefie », « Cler-mirice »).
- [ ] **B5. Équipe 3x3 — navigation entre équipes** : boutons Précédent/Suivant qui changent
  la « card bleue » ; afficher **catégorie + équipe + saison + joueurs** (pas juste les joueurs).
  → `templates/vitrine/accueil/equipes_3x3.html.twig` *(touche aussi à du JS Stimulus → mi-design mi-dev)*
- [ ] **B6. Cards catégories — retirer « À suivre prochainement »** → mettre les **résultats** :
  - Finalistes 3x3 Oignies 2026 : U13, U15, Senior Garçon
  - Demi-finalistes : U18, Senior Fille
  → `templates/vitrine/accueil/equipes_3x3.html.twig`
- [ ] **B7. Calendrier — card événement récurrent** : plus de « CGB » → « Centre sportif Open Gym ».
  → `templates/vitrine/accueil/calendrier.html.twig`

---

## C. Navigation & structure

- [ ] **C1. Déplacer « Nos Victoires »** : retirer du menu Accueil/Équipes, le mettre **sous Le Club**.
  → `templates/vitrine/navbar.html.twig`
- [ ] **C2. Fil d'Ariane (breadcrumb)** sur la page Nos Victoires : `Accueil / Le Club / Nos Victoires`,
  page courante en **orange** pour pouvoir revenir en arrière. Idem logique sur les autres pages profondes.
  → `templates/vitrine/accueil/victoires.html.twig` (+ base si breadcrumb global)
- [ ] **C3. Page Numérique — liens vers `manager.mabb.fr` et `pirb.mabb.fr`.**
  Si pas encore actifs → page « Site en construction » dédiée pour chacun.
  → `templates/vitrine/accueil/numerique.html.twig` (+ nouvelles routes/pages)

---

## D. Logique rôles : Employé vs Bénévole *(demande forte de Willy)*

Willy veut **montrer la charge salariale** : les employés (alternants ou sous contrat)
doivent afficher « Employé », pas « Bénévole ».

- [ ] **D1. Card membre** : afficher la mention « Employé » pour les salariés/alternants.
  → `templates/vitrine/membres/index.html.twig`
- [ ] **D2. Admin — éditeur de rôles** : seul l'admin peut cocher « Employeur/Employé » ;
  cocher « Employé » décoche « Bénévole » et reste par défaut pour les personnes sélectionnées.
  → `src/Controller/Admin/AdminRolesController.php` + template admin
  ⚠️ Implique un **champ/statut « employé »** sur `User` ou `UserClubRole` → mini-migration BDD.
  Ce n'est pas qu'un fix CSS : à traiter comme une petite évol de modèle (bien pour le Bloc 2).

---

## E. Bloqués — décision ou donnée manquante *(NE PAS commencer sans réponse)*

- [?] **E1. Article de presse** : remplacer la page article interne par un **lien direct** vers
  l'article de presse externe (si on en a un) ? Sinon laisser. → **à trancher avec Willy.**
  → `accueil/news.html.twig` + `accueil/article.html.twig`
- [?] **E2. Calendrier saison 26/27** : « mettre celui de l'an prochain, plus simple ».
  → **besoin du planning réel 26/27** (dates, créneaux). Sans ça je ne peux pas l'écrire.
- [?] **E3. Formation — parcours de chaque personne** : actuellement il n'y a que celui de Clavel.
  → **besoin des bios/parcours** de chaque membre. Sans données, rien à afficher.
- [?] **E4. Cité éducative — card « En savoir plus » vide** : la remplir comme la card Lycée.
  → **besoin du contenu réel** de la Cité éducative (texte). Sinon je copie la structure de la card Lycée et tu remplis.
  → `templates/vitrine/club/cite_educative.html.twig`
- [?] **E5. Card membre → badge max + clic vers profil PIRB joueur** (style FB/Insta, highlights/stats).
  ⚠️ **Dépend de 2 systèmes qui n'existent PAS encore sur MABB** : la gamification (badges) et
  PIRB déployé. → **Ce n'est pas un fix mabb.fr, c'est une feature liée au futur PIRB.** À sortir
  de la passe « peaufinage » et à rattacher à la roadmap Manager/PIRB.

---

## F. Idées Manager / PIRB — *PAS dans la passe mabb.fr, à garder pour plus tard*

- [~] **F1. Chatbot** bas-droite, au survol — ✅ site PUBLIC fait (2026-05-25, `_chatbot.html.twig`), reste : version admin + IA : V1 = questions/réponses fréquentes scriptées (pas d'IA), IA plus tard.
  **Sur le site PUBLIC mabb.fr ET dans l'admin** (demande Clavel 2026-05-25), pas que l'admin.
  *(cf. doc 16 §8.3 — « nice to have », après le socle).*
- [ ] **F2. « Recap discussion d'aujourd'hui »** : récap automatique des échanges du jour. *(à cadrer : où ? pour qui ?)*
- [ ] **F3. Fiche d'évaluation joueuse** : aujourd'hui sur Excel (évaluations au CEC) → upload sur MABB,
  chaque joueuse voit sa fiche dans son profil. *(feature PIRB : upload doc + visibilité profil joueur).*
- [ ] **F4. Profil joueur public PIRB** (style réseau social, highlights/stats, public/privé). *(cf. E5).*

---

## Lecture rapide / priorisation conseillée

1. **Passe 1 — Design quick wins (A1→A6)** : très visibles pour Willy, zéro donnée requise, faible risque.
2. **Passe 2 — Contenu (B1→B7)** : données déjà fournies, juste à intégrer proprement.
3. **Passe 3 — Navigation (C1→C3)** + **rôles (D1→D2, mini-migration)**.
4. **Bloqués (E)** : on traite dès que tu m'as donné les réponses/données.
5. **Manager/PIRB (F, + E5)** : hors passe mabb.fr, déjà tracé pour la suite.

**Décisions/données qu'il me faut de toi ou Willy :** E1 (article presse), E2 (planning 26/27),
E3 (parcours formation), E4 (contenu Cité éducative), orthographe prénoms B4.
