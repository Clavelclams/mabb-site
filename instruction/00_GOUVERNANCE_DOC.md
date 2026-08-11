# Gouvernance documentaire — MABB / Venaball

> Dernière mise à jour : 2026-08-04 (ménage documentaire).
>
> ⚠️ **Le point d'entrée pour l'état réel du projet est `31_ETAT_REEL_2026-07-13.md`**
> (audit complet après lecture intégrale du code), complété par
> `36_INVENTAIRE_FONCTIONNALITES` (toutes les fonctionnalités, avec marqueurs d'état).
> Le suivi CDC vit dans `24_ETAT_AVANCEMENT_VS_CDC`. Ce fichier-ci ne fait que la
> carte des documents.
>
> **Miroir Notion** : les pages « Venaball Club — cartographie de la webapp »,
> « Venaball — cartographie de l'app » et « Démarrer sur le projet » reprennent
> ce contenu pour les échanges hors dépôt. **En cas d'écart, le dépôt gagne.**
>
> Renommage produit acté : PIRB → **Venaball**, MABB Manager → **Venaball Club** (UI
> seulement, le code garde les noms `Pirb` / `pirb.mabb.fr`).

## Objectif
Assurer une documentation non redondante, maintenable et traçable.
Le dossier `/instruction/` est la **source de vérité** du projet.

## Inventaire des fichiers de gouvernance

| # | Fichier | Rôle |
|---|---------|------|
| 00 | 00_GOUVERNANCE_DOC.md | Règles de gouvernance documentaire (ce fichier) |
| 01 | 01_LIRE_AVANT_TOUT.md | Contexte obligatoire avant toute action |
| 02 | 02_ROADMAP_GLOBALE.md | Vision macro, état d'avancement, modules |
| 03 | 03_ROADMAP_V1.md | Périmètre et planning V1 |
| 04 | 04_ROADMAP_V2.md | Périmètre V2 (enrichissement) |
| 05 | 05_ROADMAP_V3.md | Périmètre V3 (extension stratégique) |
| 06 | 06_REGISTRE_TECHNIQUE.md | Points critiques techniques (DB, perfs, infra) |
| 07 | 07_REGISTRE_SECURITE_RGPD.md | Obligations sécurité + RGPD |
| 08 | 08_ADR.md | Architecture Decision Records |
| 09 | 09_BACKLOG.md | Backlog priorisé par phase |
| 10 | 10_DEFINITION_OF_DONE.md | Critères de validation d'une tâche |
| 11 | 11_CHECKLIST_RELEASE.md | Vérifications avant mise en production |
| 13 | 13_CLAUDE_LOG.md | Journal d'exécution (log de chaque session) — **tenu à jour** |
| 18 | 18_JOURNAL_DE_BORD.md | TODO hors session, décisions reportées (config externe, points à ne pas perdre) |
| 23 | 23_CADRAGE_MODULE_EVENEMENTS_SORTIES | Cadrage du module événements et sorties |
| **24** | 24_ETAT_AVANCEMENT_VS_CDC | **Suivi CDC vivant** |
| 29 | 29_STRATEGIE_COMMERCIALE_ET_MARQUE | Marque Venaball, pricing, blocages légaux |
| 30 | 30_TON_ET_STYLE_REDACTIONNEL | Règles de ton anti « ressenti IA » |
| **31** | 31_ETAT_REEL_2026-07-13.md | **Audit maître, état réel du code — fait foi** |
| 32 | 32_CADRAGE_VENABALL_CLUB_MOBILE | Cadrage de l'app Club mobile (pas encore codée) |
| 33 | 33_SAUVEGARDE_BDD.md | ⚠️ Sauvegarde de la base — **toujours non mise en place** |
| 34 | 34_AUDIT_SECURITE_2026-07-13.md | Audit sécurité, 2 passes (API/app le 13/07, web le 26/07) |
| 35 | 35_PLAYGROUND_V6_2026-07-26.md | Vision v6 des jeux caméra |
| **36** | 36_INVENTAIRE_FONCTIONNALITES | **Inventaire exhaustif des fonctionnalités, avec marqueurs d'état** |
| 37 | 37_TOGGLE_STATS_LIVE_FFBB | Séparation des sources de stats (live / FFBB / manuel) |
| — | PROMPT_DEV_COLLABORATEUR.md | **Onboarding d'un dev (humain ou IA) — à lire en premier** |
| — | CONVENTIONS_EMAILS_MABB.md | Conventions d'adresses et d'expéditeurs |
| — | arborescence.md | Structure complète du projet |
| — | **archive/** | **Instantanés datés (audits, sessions, états des lieux figés) — ne pilotent plus rien.** Voir `archive/README.md` |
| — | CDC/CDC_MABB_PIRB_V1_Definitif.pdf | Cahier des charges Manager & PIRB (référence initiale) |
| — | CDC/Cahier des charges – Site web MABB.fr.pdf | Cahier des charges vitrine (référence initiale, stack supersédé par ADR-0001/0004) |

### Documents datant de février/mars, à considérer avec prudence

`03_ROADMAP_V1`, `04_ROADMAP_V2`, `05_ROADMAP_V3`, `10_DEFINITION_OF_DONE`,
`11_CHECKLIST_RELEASE` n'ont pas bougé depuis février-mars 2026. Le produit les a
largement dépassés (la V1 parle encore d'« Auth JWT », remplacée depuis par des
jetons maison — voir les ADR). Ils gardent une valeur de cadrage, mais **ne
décrivent pas l'état actuel** : pour ça, docs 31 et 36.

## Règles
1. Ne pas renommer / déplacer des fichiers sans le documenter dans 13_CLAUDE_LOG.md.
2. Toute décision d'architecture = une entrée dans 08_ADR.md.
3. Toute évolution fonctionnelle = mise à jour roadmap :
   - 02_ROADMAP_GLOBALE.md si impact transverse
   - + la roadmap V1/V2/V3 concernée (03/04/05)
4. Toute contrainte critique (perf/sécu/DB) = entrée dans 06_REGISTRE_TECHNIQUE.md.
5. Toute contrainte sécurité/RGPD = entrée dans 07_REGISTRE_SECURITE_RGPD.md.
6. Zéro duplication : si une info existe déjà, on référence le fichier au lieu de recopier.
7. Les CDCs PDF sont des **documents de référence initiale**. En cas d'écart avec les fichiers de gouvernance, les ADR et registres font foi (car postérieurs aux CDCs).

## Format des mises à jour (obligatoire)
À chaque session de travail significative :
- compléter 13_CLAUDE_LOG.md (date, objectif, actions, fichiers modifiés)
- mettre à jour la roadmap si le périmètre a bougé
