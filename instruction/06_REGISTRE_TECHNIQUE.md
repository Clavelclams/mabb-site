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
