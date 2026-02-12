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
