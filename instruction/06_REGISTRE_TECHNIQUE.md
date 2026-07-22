# Registre technique — points critiques

## Objectif
Lister les sujets qui peuvent casser le projet si mal geres (DB, perfs, infra, dette).

## Format
| Champ | Description |
|-------|-------------|
| ID | RT-XXXX |
| Date | YYYY-MM-DD |
| Sujet | Description courte |
| Risque | Ce qui peut casser |
| Decision / regle | Ce qu'on fait |
| Impact | code / DB / infra |
| Statut | a faire / en cours / ok |

---

### RT-0001 — Filtrage multi-tenant par club_id
- Date : 2026-02-12
- Risque : Fuite de donnees inter-club si une requete oublie le filtre club_id
- Decision : Chaque requete metier DOIT etre filtree par club_id cote serveur. Implementation via ClubScopeVoter. Tests anti-fuite inter-club obligatoires.
- Impact : code (Voters, Repositories) + DB (index club_id sur chaque table metier)
- Statut : a faire (Voter a creer en Phase 1)

---

### RT-0002 — Separation par host + firewalls
- Date : 2026-02-12
- Risque : Acces non autorise entre espaces (vitrine / manager / pirb)
- Decision : Routage par host (ADR-0002). Firewalls distincts dans security.yaml. Chaque espace a son fichier de routes.
- Impact : infra (config DNS / hosts) + code (config/routes/*.yaml, security.yaml)
- Statut : ok (implemente)

---

### RT-0003 — Strategie JWT + refresh + rate limiting
- Date : 2026-02-12
- Risque : Vol de token, brute force login, expiration mal geree
- Decision : LexikJWTAuthenticationBundle. Access token courte duree (1h). Refresh token en cookie httpOnly (si retenu). Rate limiting sur /api/login (max 5 tentatives/min).
- Impact : code (auth) + infra (cles RSA)
- Statut : a faire (Phase 1)

---

### RT-0004 — Migrations Doctrine et versionnage DB
- Date : 2026-02-12
- Risque : DB desynchronisee entre envs, migrations conflictuelles
- Decision : Une migration par changement logique. Jamais d'edition manuelle de la DB. `doctrine:migrations:diff` uniquement. Toujours verifier avant merge.
- Impact : DB + workflow dev
- Statut : a faire (Phase 1, premiere migration a la creation de User)

---

### RT-0005 — Stockage fichiers (galerie, ENT, documents)
- Date : 2026-02-12
- Risque : Fichiers accessibles sans authentification via URL directe
- Decision : Stockage hors du dossier public/. Acces via controller avec verification Voter. URLs signees ou temporaires si S3 (V3). Limites : taille max 10 Mo, types autorises (images, PDF, docs).
- Impact : code (FileController + Voter) + infra (dossier var/uploads/ ou S3)
- Statut : a faire (V1 galerie vitrine, V2 ENT)

---

### RT-0006 — Suppression logique (soft delete via deleted_at)
- Date : 2026-02-12
- Risque : Perte de donnees irreversible si suppression physique. Rupture d'integrite referentielle.
- Decision : Privilegier la suppression logique (champ `deleted_at` nullable) sur les entites metier (User, Player, Team, Club, Event, Match, Article). La suppression physique est reservee aux donnees ephemeres (sessions, tokens expires). Cf. CDC section 4.6.
- Impact : code (trait SoftDeletable ou champ deleted_at sur chaque entite) + DB (index sur deleted_at) + repositories (filtrage automatique)
- Statut : a faire (Phase 1, a poser des la creation des entites)

---

### RT-0007 — Hachage des mots de passe (bcrypt/argon2)
- Date : 2026-02-12
- Risque : Fuite de mots de passe en clair en cas de compromission de la DB
- Decision : Hachage obligatoire via le PasswordHasher Symfony. Algorithme : auto (Symfony choisit le meilleur disponible, generalement argon2id si extension installee, sinon bcrypt). Jamais de stockage en clair. Cf. CDC section 8.1.
- Impact : code (security.yaml password_hashers) + infra (extension sodium recommandee)
- Statut : a faire (Phase 1, configuration User entity)

---

### RT-0008 — API Platform : CORS, serialization, securite
- Date : 2026-02-12
- Risque : Exposition de donnees sensibles via l'API. Acces cross-origin non controle. Fuite de champs internes dans les reponses JSON.
- Decision : Groupes de serialisation stricts (read:public, read:club, read:player, write:coach, write:admin — cf. CDC section 9.3). CORS configure pour n'accepter que les domaines autorises (mabb.fr, manager.mabb.fr, pirb.mabb.fr). Filtrage club_id + Voters sur chaque endpoint. Rate limiting sur /api/auth/login.
- Impact : code (API Platform config, serialization groups, NelmioCorsBundle) + infra (headers CORS)
- Statut : a faire (Phase 3, a l'installation d'API Platform)

---

### RT-0009 — Gestion roles par club (pivot ClubUserRole)
- Date : 2026-02-13
- Risque : Incoherence securite si les roles ne sont pas resolus selon le club courant. Un utilisateur Coach dans le club A pourrait avoir des droits Coach dans le club B si le resolver est mal implemente.
- Decision : Implementer un TenantContext (ou equivalent) qui identifie le club courant (depuis l'URL, le token JWT, ou la session). Creer un RoleResolver qui interroge ClubUserRole pour le club courant et fournit les roles effectifs au SecurityBundle. Les Voters doivent utiliser les roles resolus, pas les roles globaux du token. Cf. ADR-0006.
- Impact : code (TenantContext, RoleResolver, adaptation Voters + authenticators) + DB (tables Role, ClubUserRole avec index)
- Statut : a faire (Phase 1, apres creation des entites User/Club/ClubUser)

---

### RT-0010 — Stats de saison filtrées par type de rencontre + composition interne A/B
- Date : 2026-07-05
- Risque : (1) Moyennes de saison FAUSSÉES si les matchs non officiels (entraînements internes, exhibitions, amicaux) alimentent la fiche joueuse — c'était le cas avant V2.3 : `statsSaison()` agrégeait toutes les `EvaluationMatch` sans filtre. (2) Fuite inter-clubs si la composition A/B accepte des IDs de joueuses d'un autre club.
- Décision : (1) `JoueurStatsAggregator::statsSaison()` filtre par `rencontre.typeRencontre` (défaut : OFFICIEL uniquement, cf. ADR-0008). Toute NOUVELLE requête d'agrégation multi-rencontres DOIT décider explicitement quels types elle inclut. (2) Double barrière : whitelist serveur à l'enregistrement de la composition (`findEffectifClubPourComposition` = joueuses actives non temporaires du club courant) + re-filtrage au chargement de l'écran live (un ID étranger dans le JSON serait ignoré). Anti-IDOR sur `createAction`/`entrerSurTerrain` : joueuse ∈ composition A∪B en mode interne.
- Impact : code (`JoueurStatsAggregator`, `StatsLiveController`, `JoueurRepository`) + DB (colonne `rencontre.composition_interne` JSON, migration Version20260705100000)
- Statut : fait (V2.3, 05/07/2026). Point ouvert : `ShotChartCalculator::positionsTirs()` agrège encore tous types confondus (choix à acter — un shot chart d'entraînement a une valeur pédagogique).

---

### RT-0011 — Fichiers uploadés servis en clair dans `public/`
- Date : 2026-07-13 (constaté à l'audit, cf. 31_ETAT_REEL)
- Risque : 7 des 8 uploaders écrivent sous `public/uploads/` (trésorerie, photos joueuses, documents ENT, PDF de rencontres, docs de réunion, contenus de séance). Ces fichiers sont servis en accès direct par Apache, protégés uniquement à l'upload par le Voter, jamais à la lecture. Une URL devinée expose des justificatifs financiers nominatifs et des photos de mineures. Seul `DechargeSortieUploader` fait bien : stockage dans `var/decharges/`, lecture via `BinaryFileResponse` derrière `CLUB_STAFF`.
- Décision : à corriger. Déplacer les uploads sensibles hors `public/` et les servir via un contrôleur qui vérifie le Voter, sur le modèle des décharges.
- Statut : OUVERT, non corrigé.

### RT-0012 — Calcul des minutes jouées et des titulaires faux (Stats Live)
- Date : 2026-07-13
- Risque : `ActionMatchAggregator::calculerMinutesJouees()` compte les quart-temps où la joueuse a une action × 10 min, alors que les entrées/sorties sont stockées (`PresenceTerrain`, actions ENTREE/SORTIE). Résultat : minutes fausses, donc tous les ratios par minute faux. `detecterTitulaire()` = « présente au QT1 », faux dès qu'une remplaçante agit en QT1.
- Décision : recalculer depuis les paires ENTREE/SORTIE (la donnée existe). Titulaire = entrée à 0:00 QT1.
- Statut : OUVERT.

### RT-0013 — Promotion des stats manuelle + doublon d'agrégateurs + cron non déclaré
- Date : 2026-07-13
- Risque : (1) une `EvaluationMatch` n'est créée qu'à la promotion d'une `SessionStatsLive` en officielle → un match saisi mais non promu ne remonte pas chez la joueuse. (2) `EvaluationCalculator` et `JoueurStatsAggregator` calculent la même chose (moyennes/totaux saison). (3) Aucune config Symfony Scheduler dans le dépôt : `app:otm:cloturer` et `app:sorties:purger-rgpd` dépendent d'un cron OVH non versionné — **à vérifier que la purge RGPD tourne vraiment**.
- Décision : (1) bandeau dashboard ou promotion auto au « marquer complète ». (2) consolider en un seul agrégateur. (3) documenter et vérifier le cron OVH.
- Statut : OUVERT.
