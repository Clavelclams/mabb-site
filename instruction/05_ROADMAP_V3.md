# Roadmap V3 — Extension stratégique (mobile, comité, industrialisation)

> Dernière mise à jour : 2026-02-12

## Objectif V3
Passer d'un outil club "web solide" à un produit complet :
- expérience mobile
- extension gouvernance (comité)
- capacité d’industrialisation (SaaS / multi-clubs)

## Axes V3 (fonctionnel)
### 1) Mobile (PIRB & Manager)
- App React Native (ou PWA avancée si décision ADR) :
  - PIRB : stats perso, feed, badges, objectifs, notifications push
  - Manager (staff) : présences, convocations, saisie match simplifiée, documents
- Offline partiel (cache + sync)
- Push notifications (FCM/APNS)

### 2) Module Comité / Gouvernance
- Espace comité (président/dirigeants)
- Suivi licences / documents / conformité
- Gestion RH bénévoles / salariés (contrats si prévu)
- Tableaux de bord club (effectifs, présence, finances *si inclus*)
- Historique décisions / votes (si tu vas jusque-là)

### 3) Vidéo & performance (si confirmé)
- Upload vidéo match/entraînement
- Découpage clips (tags temps)
- Liaison clip ↔ action (timeline / joueur)
- (Option) partage privé contrôlé

### 4) SaaS / industrialisation
- Onboarding club (création club, équipes, import joueurs)
- Gestion des plans (gratuit/club/premium) si tu veux monétiser
- Facturation (Stripe) + gestion abonnement
- Admin platform (super-admin) : stats usage, support, logs

## Axes V3 (technique)
- Architecture “scalable” (sans microservices inutiles) :
  - jobs async (Messenger + workers)
  - cache (Redis)
  - stockage fichiers externalisé (S3 compatible)
- Sécurité renforcée :
  - 2FA optionnel
  - audits complets actions sensibles
  - détection anomalies (rate limit + alerting)
- Qualité :
  - test coverage plus élevé
  - monitoring (Sentry/Prometheus selon stack)

## Hors V3 (si trop ambitieux)
- IA coaching automatique / scouting IA (à cadrer séparément)
- Marketplace / recrutement joueurs inter-clubs

## Rappel contraintes structurantes (héritées de V1/V2)
- **Multi-tenant strict** : toute fonctionnalité V3 DOIT respecter le filtrage par `club_id` (cf. ADR-0003, RT-0001).
- **RBAC + Voters** : tout accès contrôlé par Voter Symfony.
- **API Platform** : les endpoints mobiles consomment l'API Platform posée en V1 Phase 3 (cf. ADR-0004).

## Jalons (indicatifs)
- V3.1 Push + app PIRB (MVP)
- V3.2 app Manager (MVP)
- V3.3 module Comité (MVP)
- V3.4 industrialisation SaaS (si monétisation confirmée)
- V3.5 vidéo (si prioritaire)
