# 36 — Inventaire EXHAUSTIF des fonctionnalités Venaball · 26 juillet 2026

> Compilé par Claude depuis le code des deux dépôts (audits vérifiés docs
> 17/34, sessions 18/28/35). Marqueurs d'état :
> ✅ réel et branché · 🔵 codé, non testé terrain · 🟡 mock ou partiel · ⏸️ construit, non déployé

---

## 1. APP MOBILE VENABALL (joueuse) — 26 écrans

### Compte & session
- ✅ Connexion (compte unique écosystème), jeton sécurisé (Keychain), déconnexion auto sur 401
- ✅ Déconnexion, suppression de compte (RGPD), lien création de compte
- ✅ SSO app→web : connexion UNIQUE (les WebViews arrivent déjà connectées)
- ✅ Réglages conformes stores : confidentialité, CGU, suppression compte
- ✅ Error boundary global + retry réseau avec backoff sur les GET

### Accueil
- ✅ Bannière « phase de test » (tap → Aide & contact)
- ✅ « Ton prochain match » (convocation à venir)
- ✅ Rail commu (vraies joueuses du club) avec bouton Suivre
- ✅ Progression : niveau, XP, barre vers le palier suivant
- ✅ Badges épinglés, accès Mon club, menu vers les fonctions web

### Stats (« Ta saison »)
- ✅ Sélecteur de saison (courante + passées, repli local si réseau muet)
- ✅ Moyennes : points (FFBB+Live), rebonds/passes/réussites (Live only)
- ✅ Détail points par source (FFBB vs Live) au tap
- ✅ Zone chaude mise en avant · zone par zone (STRICTEMENT Stats Live)
- ✅ Terrain Stats Live (marqué/raté) · shot chart web complet (SSO)
- ✅ Créateur de séance de shoot manuelle (web, validée coach)

### Social
- ✅ Follow intra-club : suivre/ne plus suivre (optimiste, serveur tranche)
- ✅ Compteurs abonnés/suivis cliquables → listes réelles (états vides assumés)
- ✅ Écran Équipe (joueuses d'une équipe + Suivre)
- ✅ Recherche : joueuses de la vraie commu (pseudo/club)
- ✅ Feed highlights Scouting RÉEL (liens des joueuses du club, visibles si
  profil public OU réglage highlightsPublics, domaines en liste blanche
  YouTube/Instagram/TikTok, gestion via la page web /profil/highlights en SSO)
- ✅ Écran Scouting (hommage PIRB Scouting, liens réseaux)

### Playground (app)
- ✅ Choix de mode TIR / DRIBBLE, lancement des jeux auto
- ✅ Paliers Rookie→Légende par mode (cumul réussis, écran échelle complète)
- ✅ Classement du club 7 jours glissants (prénom+club, podium, moi surligné)
- ✅ Historique des séances (local + sync serveur offline-first)
- ✅ Séances des jeux auto-enregistrées (pont WebView validé champ par champ)
- ✅ Modes manuels en secours (practice-tir, practice-dribble)
- ✅ Orientation pilotée par le jeu (portrait accueil, paysage en jeu)

### Profil & progression
- ✅ Vitrine : avatar, compteurs, bio, badges débloqués
- ✅ Attributs RPG RÉELS (bilan coach /10 ×2 + bonus playground, non trichables)
- ✅ Édition : bio (serveur), confidentialité fine (6 réglages, défaut tout privé)
- ✅ Explorateur badges (4 axes, verrouillés visibles, manifeste « les points ne font pas tout »)
- ✅ Niveau/XP calculés serveur (7 paliers Recrue→Légende)

### Club & carte
- ✅ Mon club RÉEL : XP par équipe (même calculateur que le niveau), total club
- 🟡 Carte Explorer (26 clubs Somme, 1 allumé) — mock, EN ATTENTE du brainstorm inter-clubs
- ✅ Carte adaptée iPad (hauteur bornée)

### Vie de club (natif)
- 🔵 Convocations : liste, détail, réponse présent/absent (IDOR gardé)
- 🔵 Notifications : liste, marquage lu
- 🔵 Push : enregistrement appareil, tap → bon écran (convocations)
- 🔵 Bilan de compétences natif (liste blanche RGPD — santé exclue)
- ✅ WebViews SSO : convocations web, équipe, documents, parents, bilans

### Divers
- ✅ Arcade « Panier plein » : easter egg 100 % hors-ligne (~950 lignes)
- ✅ Aide & contact (FAQ + feedback beta mailto)

---

## 2. PLAYGROUND — LES JEUX VISION (v6)

### Socle vision commun (vision.js / pose.js / tracker.js)
- ✅ Détection ballon : modèle lite2 (repli lite0) + **vérif couleur orange** (anti tête/genou/plot)
- ✅ Suiveur de mouvement maison (différence d'images en ROI) → trajectoire continue
- ✅ Détection adaptative (1 frame sur N selon vitesse mesurée de l'appareil)
- ✅ **Arceau détecté TOUT SEUL** (orange + immobile, ~1,5 s) — 2 taps = correction
- ✅ **Parabole ajustée** (moindres carrés) : courbe lisse, prédiction, occlusions comblées
- ✅ **Pose 33 points** : poignets, coudes, épaules, hanches, genoux, chevilles ;
  échelle corporelle (épaules = 38 cm) ; auto-coupure si l'appareil rame
- ✅ Ballon « à elle » (rejet des ballons d'autres terrains), lâcher détecté au geste

### Jeu TIR (tracker de shoot)
- ✅ Comptage marqué/raté auto (croisement du segment d'arceau en descente)
- ✅ **Angle d'entrée** par tir (~45° idéal) à chaud + conseil sur raté
- ✅ **Coude au lâcher** + **flexion genoux** (CDC P1 : le geste du corps) + conseils
- ✅ Fantôme du dernier tir (2,5 s, vert/rouge), trajectoire prédite en pointillés
- ✅ HUD réussis/tirs/adresse/série, callouts variés, jalons 5/10/20 tirs, sons
- ✅ Aide auto « je te perds » si tirs annulés, plein écran + hamburger
- ✅ **Débrief avant validation** : trajectoires superposées, demi-terrain,
  zone estimée (corps = règle) AJUSTABLE, réussite VERROUILLÉE, envoi au Valider
- ✅ Records perso (adresse ≥5 tirs, série), temps de vol moyen
- ✅ **Défi du jour** (rotation par date : séries, volume, adresse, angle 40-55°)
- ✅ Wake lock (l'écran ne s'éteint jamais en séance)

### Jeu DRIBBLE (cibles à toucher)
- ✅ Cibles à toucher au ballon, niveaux progressifs (vitesse/nombre), 90 s
- ✅ **Cibles ancrées au CORPS** (0,45-1,05 m du buste, sous les épaules, repli aléatoire)
- ✅ Contact par SEGMENT (un ballon rapide ne « saute » plus une cible)
- ✅ Comptage des VRAIS dribbles (minima locaux) + cadence /s en direct
- ✅ **Main G / main D** par rebond + répartition en direct + conseil main faible
- ✅ **Dribbles hauts** (sommet vs hanche) + conseil « dribble plus bas »
- ✅ Décompte 3-2-1, sons (hit, niveau, fin), record perso, squelette affiché
- ✅ **Défi du jour** (niveau 4, main faible 40 %, 80 dribbles, 20 cibles, dribble bas)
- ✅ Wake lock, plein écran, miroir caméra frontale géré

---

## 3. WEB PIRB (espace joueuse — pirb.mabb.fr)
- ✅ Dashboard, stats saison, stats par match (drawer), shot chart (filtre saison)
- ✅ Accès match par participation, créateur de séances de tir (validation coach)
- ✅ Bilan de compétences, convocations (réponse), documents, mes parents /
  mes enfants (recherches BORNÉES au club — fix sécu 26/07)
- ✅ Login/reset password, profil public scouting opt-in (/joueuse/{id})

## 4. MANAGER (staff — manager.mabb.fr)
- ✅ Multi-club : création de club publique, référentiel FFBB (OrganismeFfbb),
  officialisation (n° FFBB), rôles par club (UserClubRole), ClubVoter
- ✅ Équipes, joueuses (photos, licences, joueuses éphémères), liens parents
  (invitations, validations)
- ✅ Rencontres : import web fichiers FFBB (upload/aperçu/confirm), PDF,
  évaluations FFBB (stats complètes), tirs FFBB (positions)
- ✅ **Stats Live** : sessions de saisie multi-utilisateurs (ActionMatch,
  présences terrain), 5 par équipe, demi-terrains teintés, **validation
  coach** (promotion → officielle) qui alimente les joueuses
- ✅ Convocations joueuses (création depuis la fiche rencontre → notif + push)
- ✅ OTM/bénévoles : affectations postes, renforts, kanban, missions
- ✅ Séances : planning, appel/présences (iPad), bandeau appels oubliés,
  contenus de séance (médias, thèmes), notes de séance, feedbacks joueuses,
  semaine du coach, générateur de séances
- ✅ Séances solo déclarées (validation), bilans de compétences (éditeur /10)
- ✅ Scolaire : bulletins, notes (Pronote-ready)
- ✅ Trésorerie : opérations, notes de frais (justificatifs, validation),
  cotisations (tarifs, paiements), subventions
- ✅ Réunions : convocations, documents, versions de PV
- ✅ ENT documents (visibilité staff/membres/parents), demandes d'accès PDF
- ⏸️ Sorties/événements (inscriptions, décharges) — construit, migration prod à passer
- ✅ Console super-admin cross-club (support), admin des connexions

## 5. VITRINE (mabb.fr)
- ✅ Site public responsive, articles/actualités (admin dédié), pages de
  contenu par blocs, médias, comptes visiteurs, contact

## 6. API MOBILE (24 endpoints /api/pirb + auth)
- ✅ Auth : login (throttlé 10/IP·5/email/15min, loggé), logout, Bearer opaque
  hashé 30 j
- ✅ Profil · stats saison (?saison=) · saisons · shot-chart (zones Live-only)
  · badges · niveau · commu · attributs · bio (PATCH) · confidentialité
  (GET/PUT) · club/overview
- ✅ Social : counts, abonnés, abonnements, follow toggle
- ✅ Playground : POST séance (+détail tirs par zone JSON), classement hebdo
- 🔵 Convocations (liste + réponse), notifications (liste + lues), push token
  (POST/DELETE), compte (GET + suppression)
- ✅ SSO ticket

## 7. SOCLE TRANSVERSE
- ✅ Gamification serveur : XP (présences, matchs, missions), 7 niveaux,
  catalogue de badges 4 axes (l'axe « employé » filtré côté app)
- ✅ Push serveur : PushToken, ExpoPushService, branché convocations
- ✅ Notifications en base (NotificationService)
- ✅ RGPD : registre de demandes, purge logs (365 j), liste blanche bilan,
  données santé jamais exposées, mineures intra-club partout
- ✅ Sécurité : audits 13+26/07, throttling, IDOR gardés, uploads validés,
  en-têtes/fixes web du 26/07, SSO signé
- ✅ Sauvegarde BDD : script mysqldump + rotation + procédure (cron À POSER)
- ✅ Logs de connexion (succès/échecs web+api) + alerte brute force
- ✅ Emails transactionnels (convocations…), génération PDF (convocations)
- ✅ Docs d'ingénierie : 36 docs mabb-site + 18 docs app + CDC + ADR

---

## Le DERNIER mock (1 sur 7) et l'attente
1. 🟡 `getCarteClubs` — attend le brainstorm carte inter-clubs (décision produit)

Highlights dé-mockés le 26/07 (`Api\PirbHighlightsController`,
GET /api/pirb/highlights) : source = Joueur.highlights (V1.2c, existait
déjà !), périmètre club, visibilité = profil public OU highlightsPublics
(mes propres highlights toujours visibles pour moi), liste blanche de
domaines avec test « se termine par » sur l'hôte (anti
youtube.com.evil.fr), plateforme dérivée de l'URL, tri date desc, limite
50. App : feed branché (échec → vide), bouton « Ajouter / gérer mes
highlights » → page web existante en SSO (une seule source de vérité,
zéro CRUD natif à maintenir), fix « Invalid Date » sur date vide.
Déploiement : contrôleur → pull + cache:clear ; app : 2 fichiers, tsc ✓.

Et le chemin critique inchangé : comptes développeur → dev build → test
gymnase (tout le 🔵 ci-dessus) → TestFlight.
