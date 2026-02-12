# Gouvernance documentaire — MABB / PIRB

## Objectif
Assurer une documentation non redondante, maintenable et traçable.
Le dossier /instruction/ est la source de vérité du projet. il y a également le dossier CDC dans instruction avec les deux cahier des charges en pdf pour plus de contexte au projet

## Règles
1. Ne pas renommer / déplacer des fichiers sans le documenter dans 13_CLAUDE_LOG.md.
2. Toute décision d’architecture = une entrée dans 08_ADR.md.
3. Toute évolution fonctionnelle = mise à jour roadmap :
   - 02_ROADMAP_GLOBALE.md si impact transverse
   - + la roadmap V1/V2/V3 concernée (03/04/05)
4. Toute contrainte critique (perf/sécu/DB) = entrée dans 06_REGISTRE_TECHNIQUE.md.
5. Toute contrainte sécurité/RGPD = entrée dans 07_REGISTRE_SECURITE_RGPD.md.
6. Zéro duplication : si une info existe déjà, on référence le fichier au lieu de recopier.

## Format des mises à jour (obligatoire)
À chaque session de travail significative :
- compléter 13_CLAUDE_LOG.md (date, objectif, actions, fichiers modifiés)
- mettre à jour la roadmap si le périmètre a bougé
