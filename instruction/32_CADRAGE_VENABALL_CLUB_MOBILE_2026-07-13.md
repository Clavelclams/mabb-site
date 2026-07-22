# 32 — Cadrage Venaball Club mobile (brainstorm)

> Date : 2026-07-13 (soir). Capture d'un brainstorm avec Clavel sur l'app Venaball Club.
> Statut : VISION + décisions cadrées + questions ouvertes. Rien n'est encore codé.
> Rappel : l'app joueuse (Venaball / PIRB) sort EN PREMIER. Venaball Club vient après.
> Ce doc sert à ne pas perdre la réflexion d'ici là.

---

## Le modèle produit (décidé)

Deux applications, deux logiques, comme Uber et Uber Eats :

- **Venaball** (app joueuse) = l'« Uber Eats » : une seule chose, faite à fond, pour un
  seul public. Sort en premier. Traitée dans une autre conversation.
- **Venaball Club** (app staff/famille) = l'« Uber » : elle contient tout. Parent,
  bénévole, coach, et même la joueuse. On switche de vue **à la GTA** (comme changer de
  perso : Michael / Franklin / Trevor) → mode parent, mode bénévole, mode coach.

**Le web (`manager.mabb.fr`) reste le filet complet.** Celui qui n'a pas l'app s'en sort
quand même sur le web, surtout les rôles de bureau (secrétaire, trésorier, dirigeant).
Mais l'app est *mieux* pour le coach (notifs, dans la poche), pour les employés (savoir
qui fait quoi), pour les parents (rester informés vite).

Une joueuse peut installer **les deux** apps si elle veut : sur Venaball Club elle verra
aussi ses stats, juste pas la même expérience.

---

## Les vues (mode GTA)

- L'app **devine le rôle** depuis la base (en prod, les users sont déjà tous attribués).
  Elle peut **switcher** si la personne a plusieurs rôles.
- **Mode parent** : sa fille, son équipe, ses convocations.
- **Mode bénévole** : les événements à venir, la petite communauté, les posts réseaux,
  ses missions OTM. → **réutiliser le côté web existant, ne pas réinventer.**
- **Mode coach** : voir **équipe par équipe**, une page par équipe, il bascule de l'une
  à l'autre (comme un compte Instagram pro qui change de page).

Question ouverte : l'app ouvre-t-elle sur la vue la plus utile du moment (samedi = coach,
semaine = parent) puis laisse switcher, ou l'utilisateur choisit toujours à la main ?
→ Instinct : ouvrir sur la plus pertinente, switch d'un tap.

---

## Le coach (décidé)

- **Le coach gère à 100 %.** Il pointe, gère tout son effectif, valide les stats.
  **Aucune validation au-dessus de lui.**
- Nouveau rôle à ajouter : **TECHNICIEN** — celui qui gère le côté sportif de l'asso.
  Il est au-dessus du coach sur le sportif, parce que certains dirigeants ne sont pas
  habilités à toucher au sportif. Chacun son domaine : le Technicien le sportif, le
  Dirigeant l'administratif, le coach son équipe sur le terrain.
- À trancher (matrice de droits, touche le ClubVoter) : le Technicien touche au sportif
  (équipes, séances, compositions, stats, validation) mais PAS trésorerie ni secrétariat ?
  Le Dirigeant l'inverse ? Deux « chefs », deux domaines séparés, coach en dessous.

---

## Les stats (décidé, et ça colle au code existant)

- **N'importe quel user du club peut saisir** les stats live. Plusieurs peuvent saisir
  le même match.
- **Le coach valide** la bonne (ou refuse si erronée / s'il y en a plusieurs).
- **La validation pousse chez les joueuses** et alimente leur suivi.
- **Pas de stats live ?** Le coach récupère les **3 fichiers FFBB** et les met sur l'app
  ou le site → alimente pareil.
- Confirmé dans le code : cette validation existe déjà (SessionStatsLive → OFFICIELLE),
  et elle est **manuelle**. Donc le rappel (ci-dessous) n'est pas un confort, c'est
  nécessaire : un coach qui oublie de valider = des joueuses sans stats.

Points de vigilance techniques (non tranchés) :
- **Saisie live sur téléphone de 6 pouces** : doute réel sur la densité en plein match.
  Cible réaliste = iPad pour saisir, téléphone pour consulter/valider. À vérifier :
  est-ce qu'Easy Stats est vraiment utilisable sur un vrai téléphone, ou seulement tablette ?
- **Hors-ligne** : les gymnases captent mal. Le hors-ligne est une force du **natif**,
  très lourd sur le web. Donc si le hors-ligne compte, ça penche pour que la saisie soit
  une vraie fonction native de Venaball Club.
- **Les 3 fichiers FFBB vivent sur l'extranet FFBB (site web).** Le coach n'a pas d'ordi.
  Comment récupère-t-il ces PDF sur son téléphone ? Workflow à cadrer (peut-être un
  bénévole s'en charge, pas le coach).

---

## Le renfort en cascade (NOUVELLE idée, à construire)

L'idée la plus forte du brainstorm. N'existe pas dans le code (le « renfort » actuel ne
concerne que les postes de table OTM, pas les joueuses).

Principe : le coach de la A convoque 10 sur 14. **Les non-retenues tombent dans un vivier
« disponibles ce week-end »**, visible par le coach de la réserve, qui y pioche ses
renforts. Personne n'outille ce casse-tête hebdomadaire de club — c'est différenciant.

Questions ouvertes :
1. Le vivier se remplit **automatiquement** (non-convoquées apparaissent seules) ou le
   coach de la A doit **cocher** « je la libère » ? → instinct : auto, avec statut
   « libre / déjà reprise ailleurs ».
2. La renfort piochée reçoit une **nouvelle convocation** de la B (peut dire non) ou elle
   est d'office dedans ?
3. Double-booking : le système **bloque** (impossible deux convocations le même jour) ou
   **avertit** et laisse le coach décider ? Attention : dans le basket amateur, une
   joueuse enchaîne parfois deux matchs le même jour volontairement.
4. Rappel : le surclassement FFBB a des règles (limites de nombre). À intégrer.
5. La donnée est à moitié là : `JoueurEquipe` a déjà les types principale / surclassement
   / doublage / réserve.

---

## Le centre « mes tâches » (structurant)

Chacun, en ouvrant l'app OU en se connectant au web, voit ce qui l'attend selon son rôle :
mission OTM, convocation à valider, appel à faire, etc. **Un seul mécanisme, décliné par
vue.** C'est probablement la fonction qui fait revenir les gens.

Question ouverte : écran d'accueil (« voici tes 3 trucs à régler ») ou pastille de
notification ? → instinct : plein accueil, par rôle. Même patron que le bandeau des
appels d'entraînement oubliés déjà en place.

---

## Ce qui existe déjà (ne pas recoder)

- Convocation joueuse : le coach crée la liste (`POST /rencontres/{id}/convocations`), la
  joueuse répond présent/absent (`pirb_convocations_repondre`).
- Convocation staff OTM : affectation aux postes + renfort « assisté de » + kanban.
- Type « réserve » sur `JoueurEquipe`.
- Validation des stats (promotion session → officielle), manuelle.
- Semaine du coach, appel iPad, bandeau appels oubliés, lien coach-équipe.

---

## Priorités et garde-fous

- **App joueuse (Venaball) d'abord.** Venaball Club après. En attendant, on va sur le
  site depuis le téléphone, donc pas de blocage.
- Personne n'a réclamé l'app coach : c'est une anticipation assumée (« je révolutionne »),
  pas une demande terrain. À garder en tête pour ne pas la prioriser trop tôt.
- Clavel est à 35 h/semaine sur le projet, avance étape par étape, roadmap précise.
- ⚠️ **Rappel indépendant de tout ça : aucune sauvegarde de la base de prod n'existe.**
  Priorité absolue avant tout le reste.
