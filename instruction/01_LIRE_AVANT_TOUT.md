# À lire avant toute action

## Périmètre projet (rappel)
- mabb.fr : site vitrine du club MABB uniquement
- manager.mabb.fr : web app métier multi-clubs
- pirb.mabb.fr : web app joueur multi-clubs
Aucun mélange fonctionnel n’est autorisé entre vitrine et apps. (Règle structurante)

## Stack V1 (rappel)
Symfony 6.4 LTS, PHP 8.2+, Doctrine ORM + Migrations, MySQL 8,
Twig + Symfony UX (Stimulus/Turbo), API Platform, JWT.

## Règle multi-tenant
Toute donnée métier est filtrée par club_id côté serveur.

## Avant de coder
1) Lire 02_ROADMAP_GLOBALE.md + la roadmap de version en cours
2) Vérifier 06_REGISTRE_TECHNIQUE.md (points critiques)
3) Si décision structurante : ajouter une entrée dans 08_ADR.md
4) Après : loguer dans 13_CLAUDE_LOG.md
