# ADR — Architecture Decision Records

## Format ADR
Chaque ADR suit le format : Date / Contexte / Options / Decision / Consequences.

---

### ADR-0001 — Monolithe modulaire Symfony
- Date : 2026-02-12
- Contexte : Le projet sert 3 espaces (vitrine, manager, pirb) + API. Faut-il un repo par espace ou un monolithe ?
- Options : (A) Microservices separes (B) Monolithe modulaire unique (C) Monorepo multi-apps
- Decision : (B) Monolithe modulaire unique. Un seul projet Symfony, decoupage par domaines (Core, Sport, Pirb, Vitrine) dans src/Entity, src/Controller, etc.
- Consequences : Deploiement simplifie. Partage facile des entites Core. Necessite une discipline de decoupage par dossier.

---

### ADR-0002 — Separation par host + firewalls
- Date : 2026-02-12
- Contexte : Les 3 espaces doivent etre isoles. Comment ?
- Options : (A) Prefixes d'URL (/vitrine, /manager, /pirb) (B) Sous-domaines + firewalls Symfony
- Decision : (B) Sous-domaines. mabb.fr = vitrine, manager.mabb.fr = manager, pirb.mabb.fr = pirb. Chaque espace a son fichier de routes (config/routes/{vitrine,manager,pirb}.yaml) et son firewall dans security.yaml.
- Consequences : Isolation forte. En dev local, necesssite config hosts ou proxy. Implemente dans config/routes/*.yaml et config/packages/security.yaml.

---

### ADR-0003 — Multi-tenant par club_id (une DB, isolation logique)
- Date : 2026-02-12
- Contexte : Manager et PIRB sont multi-clubs. Comment isoler les donnees ?
- Options : (A) Une DB par club (B) Une DB unique avec filtrage par club_id
- Decision : (B) Une DB unique. Chaque table metier porte un club_id. Filtrage systematique cote serveur via Voters Symfony (ClubScopeVoter). Jamais de filtrage uniquement cote front.
- Consequences : Plus simple a deployer. Necessite vigilance absolue : chaque requete metier doit etre filtree par club_id. Tests anti-fuite inter-club obligatoires.

---

### ADR-0004 — API Platform + Twig/UX (hybride web + API)
- Date : 2026-02-12
- Contexte : Faut-il une SPA (React/Vue) ou du rendu serveur ?
- Options : (A) SPA full JS (B) Twig + Symfony UX (Stimulus/Turbo) pour le web, API Platform pour le mobile (C) SSR Next.js
- Decision : (B) Hybride. La V1 web utilise Twig + Stimulus/Turbo. API Platform expose des endpoints REST/JSON pour le futur mobile (V3). La vitrine est en MVC classique (Twig).
- Consequences : UX rapide sans complexite SPA. Le mobile V3 consommera l'API Platform sans refonte backend. Les modules critiques (stats, PIRB) sont API-first.
- Note : Cette decision supersede la recommandation Node.js/React du CDC "Site web MABB.fr" pour la vitrine.

---

### ADR-0005 — Symfony 7.4 (au lieu de 6.4 LTS du CDC)
- Date : 2026-02-12
- Contexte : Le CDC MABB/PIRB V1 mentionne "Symfony 6.4 LTS" comme stack. Or le projet a ete initialise avec Symfony 7.4 (derniere version stable au moment du demarrage).
- Options : (A) Rester sur 6.4 LTS (support long terme garanti) (B) Utiliser 7.4 (fonctionnalites recentes, performances ameliorees)
- Decision : (B) Symfony 7.4. Le projet est en phase de developpement initial (pas de contrainte de stabilite LTS en production). Symfony 7.4 apporte des ameliorations de performance et des fonctionnalites recentes. Le passage en LTS (8.4 ou suivant) sera evalue avant la mise en production.
- Consequences : composer.json configure sur "7.4.*". Les dependances Symfony sont toutes en 7.4. Le CDC reste valide comme reference fonctionnelle mais la version Symfony y est obsolete.

---

### ADR-0006 — Roles par club via Role + ClubUserRole (enterprise)
- Date : 2026-02-13
- Contexte : Les roles doivent etre attribues par club (un utilisateur peut etre Coach dans le club A et Parent dans le club B). Comment modeliser cette relation ?
- Options : (A) Champ JSON `roles` sur ClubUser (simple, pas d'audit, pas de catalogue) (B) Entite Role (catalogue) + pivot ClubUserRole (club_user_id, role_id, created_at, created_by) avec contraintes uniques
- Decision : (B) Modele "enterprise". Table `Role` = catalogue des roles disponibles (code unique : ROLE_COACH, ROLE_PLAYER, etc.). Table `ClubUserRole` = pivot M:N entre ClubUser et Role, avec horodatage et auteur de l'attribution.
- Consequences :
  - Audit natif : on sait qui a attribue quel role et quand
  - Scalabilite : ajout de roles sans migration (insert dans Role)
  - Complexite : necessite un RoleResolver/TenantContext pour fournir les roles du club courant au SecurityBundle Symfony
  - La `role_hierarchy` de security.yaml reste utilisee pour la hierarchie globale, mais les roles effectifs sont resolus depuis ClubUserRole selon le club courant
  - Contraintes DB : UNIQUE(user_id, club_id) sur ClubUser, UNIQUE(code) sur Role, UNIQUE(club_user_id, role_id) sur ClubUserRole
- Alternative rejetee : (A) JSON — plus simple mais pas d'audit, pas de catalogue centralise, difficulte a lister "tous les coachs du club X" efficacement

---

> **Note numérotation** : ADR-0007 est RÉSERVÉ au brouillon "architecture PIRB Mobile" (cf. `20_ANALYSE_ARCHI_PIRB_MOBILE_2026-07-04.md`), à coller ici après validation. Ne pas réutiliser ce numéro.

---

### ADR-0008 — Match interne à deux équipes : composition A/B en JSON, type de match par jointure
- Date : 2026-07-05
- Contexte : Le module Stats Live doit permettre de statter SIMULTANÉMENT deux équipes composées de joueuses du même club (entraînement interne / amical intra-club), avec conservation des stats des deux côtés et possibilité de filtrer les moyennes de saison pour ne pas les gonfler avec des matchs internes. Le type de rencontre (`Rencontre.typeRencontre` : OFFICIEL/AMICAL/ENTRAINEMENT_INTERNE/EXHIBITION) existe déjà depuis B23/V2.2 — la décision porte sur (1) le stockage de la répartition A/B et (2) le rattachement des stats au type de match.
- Options :
  - **(1) Stockage composition A/B** :
    - (A) Colonne JSON nullable `composition_interne` sur `rencontre` (`{"equipeA": {"nom", "joueurs": [ids]}, "equipeB": {...}}`)
    - (B) Table pivot `rencontre_composition` (rencontre_id, joueur_id, cote ENUM A/B, UNIQUE(rencontre, joueur))
  - **(2) Rattachement des stats au type de match** :
    - (C) Champ `type_match` dénormalisé sur `action_match` / `evaluation_match`
    - (D) Jointure sur `rencontre.typeRencontre` (pas de nouveau champ)
- Décision : **(A) + (D)**.
  - (A) JSON : précédent établi sur la même entité (`joueursNonConvoques`, `joueursExternes` sont déjà des JSON) ; donnée strictement scoped à UNE rencontre, jamais requêtée en SQL cross-rencontres (rendu 2 colonnes et validations faits en PHP) ; une seule colonne ALTER au lieu d'une table + FK + repository. L'exclusivité A/B est garantie par `Rencontre::setCompositionInterne()` (une joueuse dans A est retirée de B).
  - (D) Jointure : le type vit sur `rencontre`, UNE SEULE source de vérité. Si le staff corrige le type après coup (ex : amical requalifié officiel), toutes les stats suivent automatiquement — un champ dénormalisé aurait divergé. Coût : un `JOIN rencontre` dans les requêtes d'agrégation, négligeable (index PK).
- Conséquences :
  - Migration `Version20260705100000` : `ALTER TABLE rencontre ADD composition_interne JSON DEFAULT NULL`. NULL = comportement historique → zéro régression sur les rencontres existantes.
  - `JoueurStatsAggregator::statsSaison()` filtre désormais par défaut sur `typeRencontre = OFFICIEL` (convention FFBB : moyennes de saison = compétition). **Changement de comportement assumé** : avant, TOUT comptait (y compris internes — moyennes gonflées, bug corrigé) ; après, les amicaux/internes/exhibitions sont consultables via le paramètre `$typesRencontre` mais exclus des moyennes par défaut.
  - Anti-IDOR adapté : en mode interne A/B, le critère "joueuse ∈ équipe de la rencontre" devient "joueuse ∈ composition A∪B" (plus strict que club-scoped).
  - L'écran live bascule en 2 colonnes si `isInterneDeuxEquipes()` (type non officiel + au moins 1 joueuse de chaque côté) — le moteur de saisie (ActionMatch, sessions, terrain) est inchangé.
- Alternatives rejetées : (B) table pivot — sur-modélisation pour une donnée d'organisation d'écran sans besoin SQL ; (C) dénormalisation — risque de désynchronisation type rencontre / type stat, sans gain mesurable.

---

### ADR-0009 — Shot chart FFBB : coordonnées brutes + terrain fidèle au doc e-Marque
- Date : 2026-07-05
- Contexte : Les points des tirs FFBB étaient visiblement décalés sur la shot map PIRB. Cause : le parser transformait les coordonnées du PDF (repère portrait 15×14 m, panier en haut) vers le terrain paysage de l'app via un mapping affine approximatif (`zoneX = normY*0.46 + 0.04`) puis un arrondi entier 0-100, et le terrain paysage n'a pas les mêmes proportions que le dessin FFBB.
- Options : (A) Corriger/calibrer la transformation vers le terrain paysage (B) Stocker les coordonnées BRUTES du repère PDF (pour-mille) et les afficher sur un terrain SVG aux proportions IDENTIQUES au doc FFBB, séparé de la shot map d'entraînement.
- Décision : **(B)**. Colonnes `tir_ffbb.ffbb_x/ffbb_y` (SMALLINT 0-1000, migration Version20260705110000), parser inchangé pour les champs legacy + remplissage des bruts, nouveau terrain "FFBB officiel" (portrait, cotes FIBA exactes : raquette 4,9×5,8 m, LF r=1,8 m, panier à 1,575 m, 3 pts r=6,75 m + coins 0,9 m) dans PIRB. Zéro transformation à l'affichage = zéro décalage. La shot map paysage reste dédiée aux séances d'entraînement/stats live (sources séparées, demande utilisateur).
- Conséquences : anciennes lignes sans ffbb_x → fallback par transformation inverse (précision dégradée) jusqu'au re-parse (`app:process-positions-tirs`). Sélecteur de match et badges filtrent les DEUX terrains.
- Alternative rejetée : (A) — même calibrée, la projection vers un terrain aux proportions différentes reste une source d'erreur permanente ; et mélanger sources FFBB/entraînement sur une seule carte nuit à la lecture.
