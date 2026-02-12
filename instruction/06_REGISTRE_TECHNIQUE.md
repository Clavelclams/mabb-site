# Registre technique — points critiques

## Objectif
Lister les sujets qui peuvent casser le projet si mal gérés (DB, perfs, infra, dette).

## Entrées (format)
- ID:
- Date:
- Sujet:
- Risque:
- Décision / règle:
- Impact (code / DB / infra):
- Statut (à faire / en cours / ok):

## Entrées initiales (à créer)
- Multi-tenant : filtrage club_id systématique + tests anti-fuite inter-club
- Séparation par host + firewalls distincts (mabb.fr vs manager/pirb)
- Stratégie JWT + refresh (si prévu) + rate limiting login
- Migrations Doctrine : règles de versionnage DB
- Stockage fichiers (galerie/ENT) : URLs non publiques + contrôle d’accès
