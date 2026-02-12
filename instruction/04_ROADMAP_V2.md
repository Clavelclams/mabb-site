# Roadmap V2 — Enrichissement (post-V1)

## Objectif V2
Consolider l’usage club au quotidien avec plus de communication, d’ENT, de suivi et d’automatisations.
V2 ne doit pas casser les fondations V1 (multi-tenant, rôles, sécurité, stats).

## Périmètre V2 (fonctionnel)
### 1) Communication & notifications
- Notifications in-app (événements, convocations, changements d’horaires, validation match)
- Notifications push (mobile plus tard) + emails (fallback)
- Préférences de notifications par profil (coach/parent/joueur/bénévole)
- Rappels automatiques avant match/entraînement
- Centre de notifications (historique + statut lu/non lu)

### 2) Messagerie (simple et cadrée)
- Conversations 1:1 et groupe (équipe / staff / parents)
- Règles d’accès strictes (par club + par équipe + par rôle)
- Modération / signalement simple (si mineurs)
- Pièces jointes limitées (taille + types)
- Anti-spam basique (rate limit)

### 3) ENT / Documents (version “club”)
- Dossiers par équipe + dossiers club
- Partage contrôlé par rôles (voters)
- Upload / preview / téléchargement
- Historique minimal (qui a ajouté / supprimé)
- Gestion des documents “obligatoires” (ex: licence, certificat) : statut OK / manquant

### 4) Planning & suivi avancé
- Calendrier filtrable (club / équipe / catégorie / type)
- Gestion indisponibilités joueurs
- Convocations avec réponses (présent/absent/incertain) + motif
- Export calendrier (ICS)
- Statistiques de présence (par joueur / par période)

### 5) Stats match — amélioration UX + analytics
- Workflows plus solides : brouillon → validation → verrouillage
- Export PDF/CSV feuille de match + stats
- Indicateurs d’équipe (tendance tirs, réussite zones, 5 majeurs)
- Comparaison match vs moyenne saison

### 6) PIRB — gamification “soft”
- Badges simples (assiduité, progression, objectifs)
- Objectifs personnels (coach ou joueur) avec suivi
- Feed perso (récap entraînements/matchs)
- Paramètres de confidentialité (visibilité stats)

## Périmètre V2 (technique)
- Système notifications (table + queue plus tard)
- Stockage fichiers sécurisé (ACL + liens temporaires si possible)
- Ajout de tests (API + voters + anti-fuite inter-club)
- Observabilité minimale (logs structurés + erreurs)

## Hors V2 (réservé V3)
- App mobile complète iOS/Android
- Module “Comité” / gouvernance avancée
- Vidéo (upload/annot) & scouting avancé
- Mode SaaS complet (facturation, onboarding multi-clubs industrialisé)

## Jalons (indicatifs)
- V2.1 Notifications + préférences
- V2.2 Messagerie simple
- V2.3 ENT documents + statuts
- V2.4 Planning avancé + exports
- V2.5 PIRB gamification soft + confidentialité
