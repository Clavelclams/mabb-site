# Roadmap globale — MABB / PIRB

> Dernière mise à jour : 2026-07-06 (remise à niveau complète depuis 19_AUDIT_FEATURES_2026-06-29 + 09_BACKLOG — la dérive signalée les 04-05/07 est résolue)

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

## État d'avancement (réel, constaté en prod — 06/07/2026)

> Le produit est EN PRODUCTION sur OVH : mabb.fr, manager.mabb.fr, pirb.mabb.fr.
> Détail feature par feature : `19_AUDIT_FEATURES_2026-06-29.md` ; priorités : `09_BACKLOG.md`.

| Module | Statut | Détail |
|--------|--------|--------|
| Core (auth, multi-tenant, rôles) | ✅ PROD | 7 firewalls par host, ClubVoter (6 attributs), TenantResolver, RGPD (export + oubli), logs connexion, anti-brute-force. Reste : rate limiter Symfony, sélecteur UI multi-club |
| Sport (équipes, joueuses, séances, rencontres) | ✅ PROD | CRUD complets, imports FFBB (trombinoscope, rencontres, PDFs), convocations, présences, missions. Saisons dynamiques + passage de saison auto par catégorie d'âge (V2.4). Reste : entité Saison dédiée |
| Stats (live, shot chart, évaluations) | ✅ PROD | Stats Live multi-sessions (validation officielle V2.1d + bouton direct B-201), match interne A/B (V2.3, ADR-0008), shot chart FFBB précis (V2.4, ADR-0009), agrégation saison filtrée par type et saison |
| PIRB (espace joueuse) | ✅ PROD | Dashboard, équipe par saison, stats filtrées, mes tirs, badges/XP, documents avec workflow d'accès, bilans. ⚠️ Isolation par Joueur.user (IDOR bénévole corrigé 06/07) — refonte TenantResolver structurelle via JWT (ADR-0007/B4) |
| Vitrine (site public) | ✅ PROD | Pages publiques, articles, galerie, admin (articles/pages/médias/rôles), CMS bloc par bloc (V2 06-07/07), mentions légales + politique de confidentialité (06/07). Reste : sitemap.xml, fil d'Ariane |
| System (tests, CI, infra) | 🟡 PARTIEL | Tests unitaires entités + services saison/catégorie/composition (06/07). GitHub Action parse PDFs FFBB. Reste : tests ClubVoter/TenantResolver, MAILER_DSN prod, chantier B4 (API Platform + JWT) |

**Rappel** : Tout module manipulant des données métier doit respecter le filtrage multi-tenant par `club_id` (cf. ADR-0003, RT-0001).

## Évolutions transverses actées (post-V1)

| Date | Évolution | Modules impactés | Références |
|------|-----------|------------------|------------|
| 2026-07-05 | Stats Live V2.3 — match interne à deux équipes A/B : composition de l'effectif club en 2 équipes, écran live 2 colonnes, moyennes de saison filtrées par type de rencontre (OFFICIEL par défaut) | Stats + PIRB (fiche joueuse) | ADR-0008, RT-0010, 13_CLAUDE_LOG (2026-07-05) |
