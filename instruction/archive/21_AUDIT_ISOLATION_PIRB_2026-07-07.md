# Audit isolation multi-tenant PIRB — routes {id} (IDOR)

> Date : 2026-07-07 · Auteur : Claude (session Cowork) · Portée : `src/Controller/Pirb/`
> Méthode : lecture seule de chaque action recevant un paramètre `{id}` (ou une
> entité injectée par ParamConverter). Aucun code de prod modifié.
> Objectif : vérifier qu'un utilisateur ne peut pas accéder aux données d'une
> autre joueuse en passant un `{id}` qui ne lui appartient pas (faille IDOR).

## Résultat en une phrase

**Aucune faille IDOR ouverte trouvée.** Les 9 routes sensibles à `{id}` vérifient
toutes l'appartenance avant d'agir. L'isolation est réelle mais **ad hoc**
(contrôle écrit à la main dans chaque action, pas via un mécanisme central).

## Correction de dérive documentaire (important)

Les documents `01_LIRE_AVANT_TOUT.md`, `19_AUDIT_FEATURES_2026-06-29.md` et
`20_ANALYSE_ARCHI_PIRB_MOBILE_2026-07-04.md` décrivent l'isolation PIRB comme une
**« lacune critique »** au motif que « 0/13 (puis 1/15) controllers utilisent le
TenantResolver ». Cet audit nuance ce constat :

- « Ne pas utiliser le TenantResolver » **n'égale pas** « ne pas isoler ».
- Chaque action vérifie l'appartenance par `Joueur.user` + `createAccessDeniedException`,
  avec en prime CSRF, journalisation des tentatives, verrous métier et contrôle
  d'âge RGPD sur les actions sensibles.
- Le vrai défaut n'est donc **pas** une faille active, mais : (1) l'absence de
  garantie systémique (une future route pourrait oublier le contrôle) et
  (2) l'absence de tests automatisés prouvant ces contrôles.

Le niveau de risque réel est donc **MOYEN (dette de robustesse)**, pas
**CRITIQUE (faille ouverte)**. À reporter dans le backlog avec cette gravité.

## Tableau route par route

| Route | Entité par `{id}` | Contrôle d'appartenance | Extras | Verdict |
|---|---|---|---|---|
| `POST /convocations/{id}/repondre` | Convocation | `convocation.joueur === joueur` | CSRF, log IDOR, verrou date match | ✅ |
| `GET /documents/{id}/voir` | Document | accès par périmètre (staff/parent) + `createAccessDenied/NotFound` | contrôle fichier disque | ✅ |
| `GET/POST /seances/{id}/feedback` | Seance | `seance.equipe === joueur.equipe` | CSRF, anti-doublon, séance passée | ✅ |
| `GET /joueuse/{id}` | Joueur | **public par conception** (profil scout) ; privé → 404 ; staff via `ClubVoter` | 404 anti-énumération | ✅ (intentionnel) |
| `POST /mes-parents/{pjId}/revoquer` | ParentJoueur | `pj.joueur === joueur` | majeure 18+, CSRF, log | ✅ |
| `POST /rencontres/{id}/benevole/{role}` | Rencontre | « Ce match ne concerne pas ton club » (contrôle club) | rôles bénévoles bornés | ✅ |
| `POST /rencontres/{id}/benevole/desinscrire` | Rencontre | idem (contrôle club) | | ✅ |
| `GET /seances/{id}` (+ `/{id}/noter`) | Seance | « Cette séance ne concerne pas ton équipe » | | ✅ |
| `DELETE /shot-chart/{id}/supprimer` | SeanceTir | `seance.joueur === joueur` | CSRF, refuse si validé coach | ✅ |
| `GET /stats/match/{id}` (+ `/pdf/{type}`, `/demander`) | Rencontre | `joueur.equipe === rencontre.equipe` | RGPD (voit le match seulement si concernée) | ✅ |

## Ce qui reste vraiment à faire (par priorité)

1. **Tests automatisés d'isolation (le vrai manque, jury CDA).**
   Tests fonctionnels (`WebTestCase`) : un utilisateur A qui appelle une route
   avec l'`{id}` d'une entité de l'utilisateur B doit recevoir 403/404.
   Prérequis infra : `DATABASE_URL` dans `.env.test` + base de test + fixtures
   (aujourd'hui `.env.test` ne contient que `KERNEL_CLASS` et `APP_SECRET`).
   → Chantier séparé « socle de tests fonctionnels ».

2. **Garantie systémique (optionnel, moyen terme).**
   Faire porter le contrôle par le `ClubVoter` existant (il accepte déjà toute
   entité `ClubAwareInterface` via `getClub()`) plutôt que par du code inline
   répété. Bénéfice : une nouvelle route ne peut plus « oublier » le contrôle si
   elle passe par `denyAccessUnlessGranted(...)`. Refactor à faire depuis un
   poste de dev (avec PHPUnit pour non-régression), pas en aveugle.

3. **Mettre à jour les docs.**
   Aligner `19_` et `20_` sur ce constat : requalifier la « lacune critique
   d'isolation PIRB » en « isolation présente mais non testée + ad hoc »
   (gravité MOYENNE). L'API B4 (endpoints sans `{id}`, servis au user du Bearer)
   est déjà isolée par construction — à noter aussi.

## Note pour le jury

Ce type d'audit (recensement des surfaces IDOR + verdict par route) est en
lui-même un livrable défendable en soutenance : il prouve une démarche sécurité
structurée, pas seulement du code qui marche.
