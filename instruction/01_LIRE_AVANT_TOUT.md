# À lire avant toute action

## Périmètre projet (rappel)
- mabb.fr : site vitrine du club MABB uniquement
- manager.mabb.fr : web app métier multi-clubs
- pirb.mabb.fr : web app joueur multi-clubs
Aucun mélange fonctionnel n’est autorisé entre vitrine et apps. (Règle structurante)

## Stack V1 (rappel)
Symfony 7.4, PHP 8.2+, Doctrine ORM + Migrations, MySQL 8,
Twig + Symfony UX (Stimulus/Turbo), API Platform, JWT (LexikJWTAuthenticationBundle).

## Architecture
Monolithe modulaire Symfony unique servant 3 espaces via routage par host + firewalls distincts.
Voir 08_ADR.md (ADR-0001 a ADR-0004) pour les decisions structurantes.

## Regle multi-tenant
Toute donnee metier est filtree par club_id cote serveur.
Aucune requete ne doit retourner de donnees d'un autre club.
Implementation : Voters Symfony (ClubScopeVoter, OwnershipVoter, TeamCoachVoter).

## Structure code
- Controllers : src/Controller/{Vitrine,Manager,Pirb,Api}/
- Entites : src/Entity/{Core,Sport,Vitrine,Pirb}/
- Security : src/Security/{Voter,Tenant}/
- Templates : templates/{vitrine,manager,pirb}/
Voir arborescence.md pour le detail complet.

## Documents de reference
- shemas/dictionnaire_db.md : dictionnaire complet de la base de donnees

## Avant de coder
1) Lire 02_ROADMAP_GLOBALE.md + la roadmap de version en cours
2) Vérifier 06_REGISTRE_TECHNIQUE.md (points critiques)
3) Si décision structurante : ajouter une entrée dans 08_ADR.md
4) Après : loguer dans 13_CLAUDE_LOG.md
