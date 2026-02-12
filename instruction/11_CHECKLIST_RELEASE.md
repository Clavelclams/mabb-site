# Checklist Release — MABB / PIRB

> Dernière mise à jour : 2026-02-12
> À vérifier avant toute mise en production ou livraison de phase.

## Pré-release (avant déploiement)

### Code
- [ ] Toutes les migrations Doctrine appliquées (`doctrine:migrations:migrate`)
- [ ] Cache vidé et reconstruit (`cache:clear --env=prod`)
- [ ] Aucune erreur dans les logs Symfony
- [ ] Assets compilés (`importmap:install` ou build si nécessaire)

### Sécurité
- [ ] HTTPS actif et certificat SSL valide
- [ ] Clés JWT générées et non exposées (`config/jwt/`)
- [ ] `.env` de production ne contient pas de secrets en clair (utiliser `secrets:`)
- [ ] Rate limiting actif sur `/api/auth/login`
- [ ] CSRF protection active sur tous les formulaires
- [ ] Firewalls correctement configurés (security.yaml)
- [ ] CORS configuré (domaines autorisés uniquement)

### Multi-tenant
- [ ] Test anti-fuite inter-club passé sur tous les endpoints métier
- [ ] ClubScopeVoter actif et testé
- [ ] Aucune requête métier sans filtre club_id

### RGPD
- [ ] Consentement CGU/RGPD enregistré à l'inscription
- [ ] Logs de connexion actifs (succès/échec)
- [ ] Politique de conservation documentée et implémentée
- [ ] Données mineurs protégées (Voter vérifiant parenté/coaching)

### Tests
- [ ] Tests unitaires passés (`php bin/phpunit`)
- [ ] Tests fonctionnels API passés
- [ ] Tests anti-fuite inter-club passés
- [ ] Test responsive (mobile, tablette, desktop) effectué

### Documentation
- [ ] 13_CLAUDE_LOG.md à jour
- [ ] 02_ROADMAP_GLOBALE.md reflète l'état réel
- [ ] 09_BACKLOG.md items concernés marqués "fait"

### Infrastructure
- [ ] DNS configuré (mabb.fr, manager.mabb.fr, pirb.mabb.fr)
- [ ] Base de données MySQL 8 provisionnée
- [ ] Sauvegardes automatiques configurées
- [ ] Monitoring minimal (logs, erreurs)

## Post-release
- [ ] Vérification manuelle des pages principales
- [ ] Test de connexion (login/logout)
- [ ] Test formulaire de contact (envoi email)
- [ ] Vérification SEO (sitemap accessible, robots.txt correct)
