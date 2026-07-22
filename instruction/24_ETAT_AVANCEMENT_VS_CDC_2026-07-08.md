# 24 — État d'avancement vs CDC fonctionnel V1

> Créé le 2026-07-08. **Maintenu — dernière mise à jour : 2026-07-13** (après lecture
> intégrale du code, cf. `31_ETAT_REEL_2026-07-13.md`).
> Sources : CDC `CDC_FONCTIONNEL_MABB_ECOSYSTEME_v1.pdf` (26 pages) + scan du code
> réel (`mabb-site` : 88 contrôleurs, 67 entités ; `Pirb store` : app Expo).
> **Confiance** : ✅ confirmé par le code · 🟡 estimé · ❓ à vérifier.

---

## 0-ter. Ce qui a avancé du 09 au 13/07 (semaine)

Quatre gros blocs du CDC ont basculé, plus du polish. Détail par bloc plus bas ;
récap chiffré ici :

- **Onboarding multi-club (§2)** : 5 % → **~85 %**. Création publique de club
  (`ManagerCreerClubController`) + super-admin. C'était « le gros trou », comblé.
- **Convocations (§4.4)** : 55 % → **~85 %**. PDF (`ConvocationPdfGenerator`) +
  mail (`ConvocationMailerService`) + push + écran natif dans l'app.
- **Notifications / push (§7)** : 35 % → **~70 %**. `push_token` + `ExpoPushService` +
  notifs natives. S'active au 1er dev build de l'app.
- **FFBB officiel (§8)** : 10 % → **~65 %**. Référentiel `OrganismeFfbb` (~7100
  organismes) + `ClubOfficialisation` ; MABB officialisée (HDF0080036).
- **App mobile** : 52 % → **~85 %**. Convocations, notifs et bilans natifs, push,
  vision playground v5.

Polish qui consolide (ne compte pas comme neuf) : feedback de séance à anonymat réel
(RGPD), lien coach↔équipe (`CoachEquipe`), semaine du coach + appel iPad, passage de
saison réécrit (garde chaque joueuse dans son équipe).

⚠️ **Confirmé absents** (vérifiés au grep le 13/07, ne PAS croire un doc plus ancien) :
messagerie interne, boutique, plans/abonnements, carte membre + QR, QR présence, export
iCal, recherche globale. Le chatbot FAQ, lui, EXISTE (`_chatbot.html.twig`).

---

## 0-bis. Ce qui avait avancé le 08/07 (session précédente)

- **Manager — filtre saison** : Stats Live (`/stats-live`) ✅ et ENT PDF FFBB (`/ent`) ✅
  filtrés par saison active + dropdown. Zéro migration (filtrage par date). Bonus : fix
  d'une **fuite multi-tenant** latente (`OR` non parenthésé dans `findWithPdfsByClub`).
- **Module Sorties (doc 23)** : Lots **A/B/C faits** (entité `InscriptionSortie`, inscriptions
  licenciées/libres, autorisations, suivi paiements, dashboard événement + dashboard global
  saison). Reste **Lot D** (RGPD : registre + purge fin de saison + upload décharge v2).
- **Vitrine** : 404 stylisée ✅, bandeau cookies RGPD ✅, CGU + plan du site ✅, boutons de
  partage articles ✅, compteurs d'accueil éditables CMS ✅. Contact → **admin@mabb.fr**
  (épargne la boîte de Willy). Responsive mobile corrigé (navbar admin, tableaux scrollables,
  overflow-x). **Boutique descopée** pour MABB (backlog SaaS).
- **App PIRB** : sélecteur de saison (lot 2) ✅ + shot chart par saison.
- **Mailer (B-304)** : **EN COURS** — compte Brevo (Free 300/j) créé, `MAILER_DSN` posé sur
  OVH, `APP_DEBUG=0`, destinataire = admin@mabb.fr. **Reste : authentifier le domaine
  `mabb.fr` (DKIM/SPF dans la zone DNS OVH)** puis tester. Le seul point « qui attend ».

---

## 0. Lecture rapide

Le CDC est une **carte du territoire** (il le dit lui-même page 26 : « n'engage pas à
tout livrer en V1 »). Donc deux chiffres différents, et il faut les distinguer :

- **Cœur métier utilisé au quotidien par MABB** (effectif, calendrier, présences, stats,
  bilans, rencontres, convocations, gamification, secrétariat, trésorerie) :
  **~90 % — solide, en prod, réel.**
- **Vision CDC complète de l'écosystème SaaS multi-club** (plans payants, boutique,
  messagerie, compte Velito) : **~60 %** — le multi-club est désormais là, restent
  surtout les pans commerciaux volontairement pas commencés.

Autrement dit : ce qui sert VRAIMENT le club est quasi mûr ; ce qui manque, c'est la
messagerie (seul vrai trou métier) et le volet « produit à vendre à d'autres clubs ».

| Application | Avancement vs CDC | Note (13/07) |
|---|---|---|
| **mabb.fr (vitrine)** | ~82 % 🟡 | 404/CGU/cookies/partage/chatbot faits ; manque calendrier dynamique, fiches équipe, iCal |
| **manager.mabb.fr** | ~82 % 🟡 | +multi-club, convocations PDF/mail/push, FFBB officiel, coach-équipe ; manque messagerie, plans |
| **pirb.mabb.fr (web)** | ~68 % 🟡 | Espace joueuse riche ; manque messagerie, carte membre + QR, iCal |
| **Pirb store (mobile)** | ~85 % 🟡 | Convocations/notifs/bilans natifs, push, vision v5 ; reste build + comptes stores |
| **Tronc commun + transverse** | ~75 % 🟡 | Auth, multi-club, push posés ; manque messagerie, compte Velito |

---

## 1. Tronc commun (CDC §2) — ~55 %

| Item CDC | État | % |
|---|---|---|
| Auth par club (email/mdp, multi-host, firewalls) | ✅ | 100 |
| Multi-tenant strict (club_id, TenantResolver, ClubVoter) | ✅ | 100 |
| Rôles par club (catalogue + ClubUserRole, cumul) | ✅ | 95 |
| Profil perso (édition, mdp, photo, historique connexions) | ✅ 🟡 | 80 |
| Inscription membre + cas mineur (email parent, cases parentales) | 🟡 (ManagerInscription, ParentInvitation) | 60 |
| **Onboarding multi-club** (chercher/rejoindre/créer un club) | ✅ (ManagerCreerClubController + super-admin) | 85 |
| Mot de passe oublié / reset | ✅ (ResetPassword) | 90 |
| RGPD : télécharger / supprimer ses données | 🟡 (AdminRgpd + RgpdExporter/Anonymizer) | 55 |
| Compte unique Velito | ❌ (futur) | 0 |

**Résolu (13/07)** : le parcours « je crée mon club » existe désormais
(`/creer-un-club`, création publique, le créateur devient dirigeant, officialisation
FFBB auto si le numéro est dans le référentiel). Le super-admin bascule entre clubs.
Reste le « rejoindre un club existant » (demande de rattachement) à polir.

---

## 2. Site mabb.fr (CDC §3) — ~70 %

| Bloc | État | % |
|---|---|---|
| 3.1 Accueil (hero, actus, événements, équipes 3x3, sponsors) | ✅ 🟡 | 80 |
| 3.2 Le club (présentation, organigramme, formation, salle) | ✅ 🟡 | 75 |
| 3.3 Équipes (liste, fiches, résultats, galerie) | 🟡 | 65 |
| 3.4 Actualités (articles, filtres, partage) | ✅ (partage ✅, CMS) | 85 |
| 3.5 Vie sportive (calendrier, résultats, victoires) | 🟡 | 60 |
| 3.6 Inscription / adhésion (tarifs, docs, pré-inscription) | 🟡 | 50 |
| 3.7 **Boutique** (catalogue, panier, paiement) | ⏸️ **descopée MABB** | 0 |
| 3.8 Contact (formulaire, coordonnées, carte) | ✅ (mailer en cours, → admin@) | 75 |
| 3.9 Pages utilitaires (mentions, RGPD, CGU, 404, plan du site) | ✅ | 95 |
| 3.10 Header/footer, cookies RGPD ✅, **chatbot FAQ** scripté | 🟡 (Espace membre à refaire) | 70 |

---

## 3. manager.mabb.fr (CDC §4) — ~70 %

| Bloc CDC | État | % | Preuve code |
|---|---|---|---|
| 4.1 Tableau de bord (cards, alertes, agenda) | 🟡 | 70 | ManagerCoachDashboard + « Ma semaine » + bandeau appels oubliés |
| 4.2 Effectif (équipes + joueuses, fiches, import Excel) | ✅ | 90 | Equipe/Joueur/Import controllers |
| 4.3 Calendrier (séances, rencontres, séries, **iCal**) | 🟡 | 65 | Planning/Seance/Rencontre ; iCal ❓ ; série ❓ |
| 4.4 Convocations (sélection, réponse, **relance J-3, PDF**) | ✅ 🟡 | 85 | ConvocationController + PdfGenerator + MailerService + push + app native ; reste relance J-3 |
| 4.5 Présences (pointage, heatmap, alerte 3 abs, **QR code**) | 🟡 | 72 | Presence/PresenceTerrain, pointage iPad + appel depuis la semaine ; QR ❌ |
| 4.6 Feuille de match (5 majeur, live, validation, verrou, PDF FFBB) | ✅ | 80 | StatsLive/ActionMatch/SessionStatsLive |
| 4.7 Statistiques (cumul, par match, shot chart, top, export) | ✅ | 80 | Stats + ShotChart + saison filtrée |
| 4.8 Bilan saison (4 axes, radar, profil de jeu) | ✅ | 85 | BilanCompetence |
| 4.9 Documents/ENT (statut, upload, preview, **expiration**) | ✅ 🟡 | 80 | Document + **filtre saison PDF FFBB ✅** ; alerte expiration ❓ |
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
| 5.4 Convocations (répondre, transport, rappel J-1, historique) | ✅ 🟡 | 75 | (web + API + app native) |
| 5.5 Présences (compteur, taux, heatmap, comparaison) | ✅ 🟡 | 70 |
| 5.6 Stats (saison, par match, shot chart, records) | ✅ | 80 |
| 5.7 Bilan saison (radar, commentaires, signature, PDF) | ✅ 🟡 | 75 |
| 5.8 **Carte de membre numérique + QR** | ❌ | 5 |
| 5.9 Documents perso (upload, statut, expiration) | 🟡 | 60 |
| 5.10 **Messagerie** | ❌ | 5 |
| 5.11 Communauté (annuaire, coéquipières, coachs) | 🟡 | 45 |
| 5.12 Espace parent (accès enfants, paiement cotis) | 🟡 | 45 |
| 5.13 Notifications (centre, push, email, catégories) | 🟡 | 65 | in-app ✅ + push codé (s'active au dev build) |
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
| Social (commu, follow, recherche, carte, attributs) | ✅ 🟡 | 65 | follow backend livré, moins de mock |
| Convocations / notifs / bilans natifs sur l'accueil | ✅ | 85 | blocs D-G faits |
| Push notifications | ✅ codé | 70 | s'active au 1er dev build |
| Prêt build stores (EAS, version, comptes dev) | 🟡 | 40 | config faite, D-U-N-S obtenu ; reste comptes + build |

**Détail à jour dans `Pirb store/instruction/12_ROADMAP_V1.md`** (blocs 0 à L, tous ✅
sauf L = build, bloqué par les comptes développeur). L'immersion n'est plus bloquée par
le backend : elle l'est par la sortie stores (comptes + build + test terrain).

---

## 6. Transverse (CDC §6-10)

| Bloc | État | % |
|---|---|---|
| §6 Passerelles Manager↔PIRB (convoc, bilan, profil) | 🟡 | 55 |
| §6 Site↔Manager (article→actus, score→vitrine) | 🟡 | 45 |
| §6 Compte unique Velito | ❌ | 0 |
| §7 Notifications (in-app ✅ ; push ✅ codé ; email/SMS) | 🟡 | 65 |
| §8 FFBB officiel/non officiel + réservation de nom | 🟡 | 65 | référentiel OrganismeFfbb + officialisation ; réservation de nom ❌ |
| §9 **Plans & abonnements** (Découverte/Club/Premium, billing) | ❌ | 5 |
| §10 Règles métier (multi-tenant ✅, mineures, RGPD, verrou feuilles, archives) | 🟡 | 55 |

---

## 7. Ce qui reste — priorisé

### 🔴 Bloquants / dette prioritaire (mise à jour 13/07)
1. **Dette RGPD, avant tout le reste** (cf. `31_ETAT_REEL` §5 et RT-0011) : fichiers
   uploadés (justificatifs financiers, photos de mineures) servis en clair dans
   `public/` ; cron de purge RGPD non déclaré, à vérifier qu'il tourne sur OVH. Plus
   grave qu'un % de CDC manquant.
2. **Aucune sauvegarde de la base de prod** n'existe. À mettre en place immédiatement.
3. **MAILER Brevo (B-304)** — reste à confirmer l'authentification du domaine (DKIM/SPF)
   pour que les mails (convocations, invitations, reset) partent vraiment.
4. **Sortie stores de l'app** — comptes développeur (D-U-N-S obtenu) + build + test terrain.

### 🟠 Gros manques structurels (chantiers dédiés)
5. **Messagerie interne** (Manager §4.14 + PIRB §5.10) — **le seul vrai trou métier
   restant**, entièrement à faire.
6. ~~Onboarding multi-club~~ **FAIT ✅** (création publique de club + super-admin).
7. ~~Notifications push~~ **codé ✅** (s'active au 1er dev build).
8. **Module Sorties** — Lots A/B/C faits. Reste **Lot D** (RGPD : registre + purge +
   décharge v2 — recoupe la dette RGPD du point 1).
9. **Stats live à fiabiliser** (RT-0012/0013) : minutes jouées fausses, titulaires faux,
   promotion des sessions manuelle, doublon d'agrégateurs.

### 🟡 Complétions / polish
10. Recherche globale (§4.19), **export iCal** (confirmé absent), QR présence + carte
    membre, relance convocation J-3, alertes expiration docs, indicateurs d'impact
    auto-PDF, calendrier vitrine dynamique, fiches par équipe.

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
