# Roadmap globale — MABB / PIRB

> Dernière mise à jour : 2026-03-13

## Stack technique
Symfony 7.4 (cf. ADR-0005), PHP 8.2+, Doctrine ORM, MySQL 8, Twig + Symfony UX (Stimulus/Turbo), API Platform (à installer Phase 3), JWT via LexikJWTAuthenticationBundle (à installer Phase 1).
Voir 01_LIRE_AVANT_TOUT.md pour le contexte complet.

## Vision
V1 = socle stable utilisable en club reel
V2 = enrichissement fonctionnel (com, ENT, gamification, notifications)
V3 = extension strategique (mobile, comite, SaaS)

## Modules (macro)
- Core (auth, multi-tenant, roles cumulables, voters)
- Sport (saisons, equipes, joueurs, evenements, presences, convocations)
- Stats (shot tracking, timeline, validation match, calculs auto)
- PIRB (profil joueur, stats perso, shot chart, feedback anonyme, visibilite)
- Vitrine (CMS pages, articles, galerie, calendrier, contact, SEO)
- System (logs, audit actions sensibles, RGPD de base)

## Etat d'avancement V1

> Note : Phase 1 et Phase 5 ont ete demarrees en parallele (socle technique + pages statiques vitrine).
> Voir 03_ROADMAP_V1.md pour le detail du perimetre V1.

| Phase | Module | Statut | Detail |
|-------|--------|--------|--------|
| Phase 1 (sem 1-4) | Core | EN COURS | Entites User/Club/UserClubRole creees, ClubVoter + TenantResolver implementes. Reste : JWT, security.yaml, migration, OwnershipVoter, rate limiting |
| Phase 2 (sem 5-8) | Sport | A FAIRE | Saisons, equipes, joueurs, evenements, presences |
| Phase 3 (sem 9-14) | Stats | A FAIRE | Saisie match, shot tracking, timeline, validation (necessite API Platform) |
| Phase 4 (sem 15-18) | PIRB | A FAIRE | Dashboard, shot chart, feedback anonyme |
| Phase 5 (sem 19-22) | Vitrine | EN COURS | Pages statiques OK (8 pages + compte/connexion + compte/inscription), navbar Connexion+Inscription, CMS back-office a faire |
| Phase 6 (sem 23-26) | System | A FAIRE | Securite, tests, optimisation, documentation |

**Rappel** : Tout module manipulant des donnees metier doit respecter le filtrage multi-tenant par `club_id` (cf. ADR-0003, RT-0001).
