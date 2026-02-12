# Definition of Done — MABB / PIRB

> Dernière mise à jour : 2026-02-12
> Critères à valider avant de considérer une tâche comme terminée.
> Basé sur les critères d'acceptation du CDC (sections 11.1 à 11.6).

## DoD — Code (toute tâche)
- [ ] Le code compile sans erreur (`php bin/console cache:clear` passe)
- [ ] Pas de régression sur les fonctionnalités existantes
- [ ] Filtrage multi-tenant par `club_id` respecté (si donnée métier)
- [ ] Voter Symfony utilisé pour tout contrôle d'accès contextuel
- [ ] Pas de requête Doctrine sans filtre club_id sur les données métier
- [ ] Timestamps `created_at` / `updated_at` présents sur les nouvelles entités
- [ ] Migration Doctrine créée si changement de schéma

## DoD — Sécurité
- [ ] Aucune donnée d'un autre club accessible (test anti-fuite)
- [ ] Mots de passe hashés (jamais en clair)
- [ ] Validation des entrées (anti-XSS, anti-injection via ORM)
- [ ] Protection CSRF sur les formulaires Twig
- [ ] Endpoints API protégés par JWT + Voters

## DoD — RGPD
- [ ] Données personnelles traitées conformément à 07_REGISTRE_SECURITE_RGPD.md
- [ ] Mineurs : accès restreint au coach de l'équipe et parent(s) rattaché(s)
- [ ] Feedbacks : anonymat garanti côté coach

## DoD — Tests (Phase 6, applicable rétroactivement)
- [ ] Test unitaire pour la logique métier critique
- [ ] Test fonctionnel pour les endpoints API
- [ ] Test anti-fuite inter-club pour chaque nouveau Voter/Repository

## DoD — Documentation
- [ ] 13_CLAUDE_LOG.md mis à jour
- [ ] Roadmap mise à jour si impact fonctionnel
- [ ] ADR créé si décision architecturale
- [ ] Registre technique/RGPD mis à jour si point critique

## Critères d'acceptation par module (issus du CDC section 11)

### Core
- Un utilisateur peut s'inscrire, se connecter et accéder à son espace selon ses rôles
- Un utilisateur peut cumuler plusieurs rôles (ex: Coach + Parent)
- Un admin club peut créer des utilisateurs et attribuer des rôles
- Les données du club A ne sont jamais visibles par un utilisateur du club B

### Sport
- Un coach peut créer un entraînement pour son équipe
- Un match peut être créé avec adversaire + domicile/extérieur
- Une convocation peut être enregistrée et les joueurs notifiés
- Un événement annulé reste visible comme annulé (pas supprimé)
- Un coach peut marquer présent/absent/excusé/retard pour chaque joueur
- Un parent ne voit que les présences de son enfant

### Stats
- Un coach peut saisir les stats d'un match via l'interface terrain
- Un tir enregistré contient type, résultat, coordonnées X/Y, joueur, période
- Les moyennes sont calculées automatiquement après validation
- Après validation match, modification limitée aux rôles autorisés

### PIRB
- Le shot chart affiche les tirs réussis/ratés sur le terrain
- Le joueur peut régler la visibilité de chaque bloc de son profil
- Le feedback entraînement est anonyme côté coach
- Un joueur ne peut répondre qu'une fois par séance

### Vitrine
- Un admin com peut publier un article qui apparaît automatiquement en accueil
- Les pages sont éditables via le back-office sans coder
- Le site est responsive (mobile, tablette, desktop)
- Le formulaire de contact envoie un email au club

### Sécurité
- Aucune donnée d'un autre club n'est accessible via l'API
- Les mots de passe sont stockés hachés
- Les tentatives de connexion échouées sont enregistrées et limitées
- Un utilisateur peut demander l'export ou la suppression de ses données
