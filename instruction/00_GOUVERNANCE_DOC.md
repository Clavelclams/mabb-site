# Gouvernance documentaire — MABB / PIRB

> Dernière mise à jour : 2026-02-12

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
| 12 | 12_TEMPLATE_PROMPTS_IA.md | Templates de prompts pour assistants IA |
| 13 | 13_CLAUDE_LOG.md | Journal d'exécution (log de chaque session) |
| 14 | 14_SUIVI_CDC_MARS.md | Suivi CDC — état d'avancement fait / en cours / à faire |
| — | arborescence.md | Structure complète du projet |
| — | CDC/CDC_MABB_PIRB_V1_Definitif.pdf | Cahier des charges Manager & PIRB (référence initiale) |
| — | CDC/Cahier des charges – Site web MABB.fr.pdf | Cahier des charges vitrine (référence initiale, stack supersédé par ADR-0001/0004) |

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
