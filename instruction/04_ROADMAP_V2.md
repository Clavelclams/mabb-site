# Roadmap V2 — Enrichissement (post-V1)

> Dernière mise à jour : 2026-02-12

## Objectif V2
Consolider l'usage club au quotidien avec plus de communication, d'ENT, de suivi et d'automatisations.
V2 ne doit pas casser les fondations V1 (multi-tenant, rôles, sécurité, stats).

## Rappel contraintes structurantes (héritées de V1)
- **Multi-tenant strict** : toute nouvelle table métier DOIT porter un `club_id` et être filtrée côté serveur (cf. ADR-0003, RT-0001).
- **RBAC + Voters** : tout nouvel accès doit passer par un Voter Symfony. Pas de contrôle uniquement côté front.
- **RGPD** : tout nouveau traitement de données personnelles doit être documenté dans 07_REGISTRE_SECURITE_RGPD.md.

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

---

## CMS Vitrine V2 — Super Admin Total

**Objectif : le super admin peut modifier TOUT le site sans toucher au code.**

### Principe

Actuellement (V1) : seulement texte + image + couleur par page via PageContenu.
En V2 : chaque section de chaque page est éditable — titre, sous-titre,
textes, boutons, images, ordre des cards, couleurs de fond.

### Ce qu'il faut construire

#### Niveau 1 — Textes & images (déjà en V1, à étendre)
- [x] Éditeur Quill sur toutes les pages CMS
- [x] Image principale par page
- [x] Couleur de texte palette MABB
- [x] Sous-titre de page éditable
- [ ] Meta description éditable par page (SEO)

#### Niveau 2 — Sections éditables (V2 cible)

Chaque page aura des **blocs de section** en BDD :
- Nouveau champ `sections: json` dans PageContenu
  Structure :
  ```json
  [
    {"type": "hero",   "titre": "...", "sous_titre": "...", "image": "..."},
    {"type": "texte",  "contenu": "...", "couleur_fond": "..."},
    {"type": "cards",  "items": [...]}
  ]
  ```
- Le super admin réorganise les blocs par drag & drop
- Chaque bloc a ses propres champs éditables
- Le template Twig lit les blocs JSON et les affiche dynamiquement

#### Niveau 3 — Navigation & structure (V2 avancé)
- Ajouter/supprimer des pages depuis l'admin (sans PHP)
- Modifier les libellés de la navbar
- Gérer l'ordre des items navbar
- Activer/désactiver des pages (visible/caché)

#### Niveau 4 — Médias centralisés
- Médiathèque globale (`/admin/medias`) déjà faite
- Associer des médias à des sections de pages
- Redimensionnement automatique des images uploadées (Intervention Image)

### Entités à créer en V2
- `SectionPage` : id, page_contenu_id, type, position, data (json), visible
- `NavigationItem` : id, label, url, position, visible, parent_id

### Priorité V2
1. Sections JSON dans PageContenu (quick win, pas de nouvelle entité)
2. Interface admin drag & drop sections
3. Navigation éditable
4. Meta SEO par page

*Mise à jour : 2026-03-22*
