# 24 — État d'avancement vs CDC fonctionnel V1

> Date : 2026-07-08
> Sources : CDC `CDC_FONCTIONNEL_MABB_ECOSYSTEME_v1.pdf` (26 pages) + scan du code
> réel (`mabb-site` : 76 contrôleurs, 48 entités ; `Pirb store` : app Expo).
> Méthode : présence contrôleur/entité + audits précédents (docs 19, 22).
> **Confiance** : ✅ confirmé par le code · 🟡 estimé · ❓ à vérifier.

---

## 0. Lecture rapide

Le CDC est une **carte du territoire** (il le dit lui-même page 26 : « n'engage pas à
tout livrer en V1 »). Donc deux chiffres différents, et il faut les distinguer :

- **Cœur métier utilisé au quotidien par MABB** (effectif, calendrier, présences, stats,
  bilans, rencontres, gamification) : **~80 % — solide, en prod, réel.**
- **Vision CDC complète de l'écosystème SaaS multi-club** (onboarding multi-club, plans
  payants, boutique, messagerie, compte Velito…) : **~50 % — beaucoup de briques avancées,
  mais des pans entiers volontairement pas commencés.**

Autrement dit : ce qui sert VRAIMENT le club est mûr ; ce qui manque, c'est surtout le
volet « produit SaaS revendable à d'autres clubs » (hors périmètre jury immédiat).

| Application | Avancement vs CDC | Note |
|---|---|---|
| **mabb.fr (vitrine)** | ~70 % 🟡 | Solide, manque boutique + chatbot |
| **manager.mabb.fr** | ~70 % 🟡 | Cœur très complet, manque messagerie/plans/multi-club |
| **pirb.mabb.fr (web)** | ~65 % 🟡 | Espace joueuse riche, manque messagerie/carte membre |
| **Pirb store (mobile)** | ~50 % 🟡 | Cœur réel, social mock, pas prêt stores |
| **Tronc commun + transverse** | ~55 % 🟡 | Auth OK, manque onboarding multi-club/notifs push |

---

## 1. Tronc commun (CDC §2) — ~55 %

| Item CDC | État | % |
|---|---|---|
| Auth par club (email/mdp, multi-host, firewalls) | ✅ | 100 |
| Multi-tenant strict (club_id, TenantResolver, ClubVoter) | ✅ | 100 |
| Rôles par club (catalogue + ClubUserRole, cumul) | ✅ | 95 |
| Profil perso (édition, mdp, photo, historique connexions) | ✅ 🟡 | 80 |
| Inscription membre + cas mineur (email parent, cases parentales) | 🟡 (ManagerInscription, ParentInvitation) | 60 |
| **Onboarding multi-club** (chercher/rejoindre/créer un club) | ❌ **absent** (grep = 0) | 5 |
| Mot de passe oublié / reset | ✅ (ResetPassword) | 90 |
| RGPD : télécharger / supprimer ses données | 🟡 (AdminRgpd) | 50 |
| Compte unique Velito | ❌ (futur) | 0 |

**Le gros trou** : le parcours « je crée mon club / je rejoins un club » n'existe pas.
Aujourd'hui la plateforme est mono-club (MABB) en pratique, même si l'archi multi-tenant
le permet. C'est LE chantier « SaaS » manquant — mais hors besoin quotidien MABB.

---

## 2. Site mabb.fr (CDC §3) — ~70 %

| Bloc | État | % |
|---|---|---|
| 3.1 Accueil (hero, actus, événements, équipes 3x3, sponsors) | ✅ 🟡 | 80 |
| 3.2 Le club (présentation, organigramme, formation, salle) | ✅ 🟡 | 75 |
| 3.3 Équipes (liste, fiches, résultats, galerie) | 🟡 | 65 |
| 3.4 Actualités (articles, filtres, partage) | ✅ (CMS + AdminArticles) | 80 |
| 3.5 Vie sportive (calendrier, résultats, victoires) | 🟡 | 60 |
| 3.6 Inscription / adhésion (tarifs, docs, pré-inscription) | 🟡 | 50 |
| 3.7 **Boutique** (catalogue, panier, paiement) | ❌ **absent** | 0 |
| 3.8 Contact (formulaire, coordonnées, carte) | ✅ (mailer à finir) | 70 |
| 3.9 Pages utilitaires (mentions, RGPD, CGU, 404) | ✅ 🟡 | 80 |
| 3.10 Header/footer, **chatbot FAQ**, bandeau cookies | 🟡 (chatbot ❌) | 55 |

---

## 3. manager.mabb.fr (CDC §4) — ~70 %

| Bloc CDC | État | % | Preuve code |
|---|---|---|---|
| 4.1 Tableau de bord (cards, alertes, agenda) | 🟡 | 60 | ManagerCoachDashboard |
| 4.2 Effectif (équipes + joueuses, fiches, import Excel) | ✅ | 90 | Equipe/Joueur/Import controllers |
| 4.3 Calendrier (séances, rencontres, séries, **iCal**) | 🟡 | 65 | Planning/Seance/Rencontre ; iCal ❓ ; série ❓ |
| 4.4 Convocations (sélection, réponse, **relance J-3, PDF**) | 🟡 | 55 | Convocation entité ; email off ; relance ❓ |
| 4.5 Présences (pointage, heatmap, alerte 3 abs, **QR code**) | 🟡 | 70 | Presence/PresenceTerrain ; QR ❌ |
| 4.6 Feuille de match (5 majeur, live, validation, verrou, PDF FFBB) | ✅ | 80 | StatsLive/ActionMatch/SessionStatsLive |
| 4.7 Statistiques (cumul, par match, shot chart, top, export) | ✅ | 80 | Stats + ShotChart + saison filtrée |
| 4.8 Bilan saison (4 axes, radar, profil de jeu) | ✅ | 85 | BilanCompetence |
| 4.9 Documents/ENT (statut, upload, preview, **expiration**) | 🟡 | 70 | Document ; filtre saison à faire ; alerte expiration ❓ |
| 4.10 Réunions / PV (planif, convoc, notes, PV, archive) | ✅ | 85 | Reunion + Convocation + Document + PvVersion |
| 4.11 Compta / Trésorerie (recettes/dépenses, cotis, note frais, TdB) | 🟡 | 70 | OperationTresorerie/Cotisation/TarifCotisation/NoteFrais |
| 4.12 Dossiers de subvention (statuts, échéancier, docs) | 🟡 | 50 | Subvention entité + controller |
| 4.13 Indicateurs d'impact (compteurs, bilan auto PDF) | 🟡 | 40 | quelques fichiers ; bilan auto ❓ |
| 4.14 **Communication interne (messagerie)** | ❌ **absent** | 5 | pas de module (le « message » du grep = flash) |
| 4.15 Communication externe (article→site, visuels, réseaux) | 🟡 | 50 | AdminArticles/CMS ; visuels/réseaux ❌ |
| 4.16 Gestion utilisateurs & rôles (liste, suspension, invitation, logs) | ✅ 🟡 | 75 | ManagerUtilisateurs + AdminLogs + ParentInvitation |
| 4.17 Paramètres club (saisons, catégories, plan comptes, abonnement) | 🟡 | 50 | Saisons ✅ ; abonnement ❌ |
| 4.18 Cité éducative / aide aux devoirs (créneaux, notes, bilan) | 🟡 | 45 | BulletinScolaire/NoteScolaire/SectionSportive |
| 4.19 **Recherche globale** | 🟡 ❓ | 30 | à confirmer |

---

## 4. pirb.mabb.fr — espace web (CDC §5) — ~65 %

| Bloc CDC | État | % |
|---|---|---|
| 5.1 Accueil « mon mur » (convocations, séances, stats, notifs) | 🟡 | 60 |
| 5.2 Profil public (couverture, bio, highlights, confidentialité) | 🟡 | 65 |
| 5.3 Agenda perso (séances, matchs, convocs, **iCal**) | 🟡 | 55 |
| 5.4 Convocations (répondre, transport, rappel J-1, historique) | 🟡 | 60 |
| 5.5 Présences (compteur, taux, heatmap, comparaison) | ✅ 🟡 | 70 |
| 5.6 Stats (saison, par match, shot chart, records) | ✅ | 80 |
| 5.7 Bilan saison (radar, commentaires, signature, PDF) | ✅ 🟡 | 75 |
| 5.8 **Carte de membre numérique + QR** | ❌ | 5 |
| 5.9 Documents perso (upload, statut, expiration) | 🟡 | 60 |
| 5.10 **Messagerie** | ❌ | 5 |
| 5.11 Communauté (annuaire, coéquipières, coachs) | 🟡 | 45 |
| 5.12 Espace parent (accès enfants, paiement cotis) | 🟡 | 45 |
| 5.13 Notifications (centre, push, email, catégories) | 🟡 | 40 |
| 5.14 Mon historique club (saisons, équipes, frise) | 🟡 | 45 |

---

## 5. Pirb store — app mobile (track séparé) — ~50 %

Détail complet dans `Pirb store/instruction/07_AUDIT_APP_2026-07-07.md`. Synthèse :

| Domaine | État | % |
|---|---|---|
| Ossature (nav, auth JWT, thème, couche données) | ✅ | 90 |
| Profil / niveau / badges (données réelles API) | ✅ | 80 |
| Stats + shot chart + **sélecteur de saison** (lot 2 fait) | ✅ | 80 |
| Practice (dribble/tir, persistant) | ✅ | 85 |
| Social (commu, follow, recherche, carte, attributs) | 🟡 mock | 40 |
| Convocations natives sur l'accueil | ❌ | 10 |
| Prêt build stores (EAS, version, comptes dev) | ❌ | 15 |

**Bloqueur d'immersion** = données social encore mock + convocations pas natives (dépend
d'endpoints backend B4 phase 2, cf. `DEMANDES_APP_PIRB_B4_PHASE2`).

---

## 6. Transverse (CDC §6-10)

| Bloc | État | % |
|---|---|---|
| §6 Passerelles Manager↔PIRB (convoc, bilan, profil) | 🟡 | 55 |
| §6 Site↔Manager (article→actus, score→vitrine) | 🟡 | 45 |
| §6 Compte unique Velito | ❌ | 0 |
| §7 Notifications (in-app ✅ ; **push/email/SMS**) | 🟡 (mailer off) | 35 |
| §8 FFBB officiel/non officiel + réservation de nom | ❌ | 10 |
| §9 **Plans & abonnements** (Découverte/Club/Premium, billing) | ❌ | 5 |
| §10 Règles métier (multi-tenant ✅, mineures, RGPD, verrou feuilles, archives) | 🟡 | 55 |

---

## 7. Ce qui reste — priorisé

### 🔴 Bloquants / à forte valeur immédiate
1. **MAILER Brevo** (B-304) — sans lui : convocations, invitations, reset, contact ne partent pas. Débloque une dizaine de features du CDC d'un coup.
2. **APP_DEBUG=0** en prod (sécurité).
3. **Convocations bout-en-bout** (email + relance J-3 + PDF) — module central du CDC (§4.4).
4. **Endpoints B4 phase 2** (commu, follow, convocations mobiles) — débloquent l'immersion app.

### 🟠 Gros manques structurels (chantiers dédiés)
5. **Messagerie interne** (Manager §4.14 + PIRB §5.10) — entièrement à faire.
6. **Onboarding multi-club** (créer/rejoindre un club) — le volet SaaS (§2).
7. **Notifications push** (§7) — infra à poser.
8. **Module Sorties/paiements** — cadré (doc 23), entité `InscriptionSortie` **déjà présente** → Lot A partiellement amorcé, à finir.

### 🟡 Complétions / polish
9. Recherche globale (§4.19), iCal export, QR présence + carte membre, alertes expiration docs.
10. Filtre saison ENT (planifié), dashboard TdB Manager, indicateurs d'impact auto-PDF.

### ⏸️ Hors périmètre jury (plus tard)
- **Boutique e-commerce (§3.7) — DÉCISION 08/07 : descopée pour MABB** (club QPV,
  peu de moyens côté joueuses). Conservée comme **feature SaaS pour d'autres clubs**
  (à la demande de Willy), donc PAS dans l'objectif « vitrine 100 % » de MABB. Une
  éventuelle page « catalogue vitrine » (photos + prix, sans panier ni paiement)
  pourrait venir plus tard si besoin.
- Plans/abonnements + billing (§9), compte Velito (§6.4), FFBB officiel (§8), encaissement CB en ligne.

---

## 8. Note jury CDA (avis franc)

Pour la soutenance, ce qui compte n'est pas le % du CDC (une carte volontairement large),
mais un **périmètre livré cohérent, en prod, défendable ligne par ligne**. Le cœur métier
(multi-tenant, effectif, calendrier, stats live, shot chart, gamification, bilans, API mobile)
est **largement suffisant et impressionnant** pour un dev seul. Les priorités jury ne sont
pas « tout finir » mais : **(1) mailer configuré** (sinon des démos cassent), **(2) tests
étoffés** (bloc attendu, aujourd'hui minces sur Manager/API), **(3) un discours clair sur
ce qui est V1 vs backlog assumé** — ce document sert exactement à ça.
