# 📓 Journal de bord — À ne pas oublier

> **But** : éviter de perdre les décisions reportées et les "TODO hors session".
> À synchroniser périodiquement sur Notion (page Journal de bord MABB).
> Convention : on **N'ENLÈVE PAS** les entrées résolues — on les passe en `[x] DONE`.

---

## 🔧 Configuration externe à faire

### [ ] 2026-06-07 — Mailer Brevo : créer un email dédié `@mabb.fr`

**Statut** : ⏸️ EN PAUSE
**Bloque** : Bureau Phase E envoi automatique de convocations
**Action** :
1. Créer une boîte mail `secretariat@mabb.fr` côté hébergeur OVH (zone DNS + boîte)
2. Créer un compte Brevo gratuit (https://www.brevo.com)
3. Vérifier le domaine `mabb.fr` sur Brevo (SPF + DKIM)
4. Récupérer USERNAME + PASSWORD SMTP Brevo
5. Renseigner dans `.env.local` :
   ```
   MAILER_DSN=brevo+smtp://USERNAME:PASSWORD@default
   EMAIL_EXPEDITEUR=secretariat@mabb.fr
   EMAIL_NOM_EXPEDITEUR=MABB - Secrétariat
   ```
6. Tester l'envoi en allant sur une réunion → bouton "Envoyer convocations"

**Note importante** : tant que ces 3 lignes ne sont pas remplies dans `.env.local`,
le bouton "Envoyer convocations" plantera proprement avec un message d'erreur visible.
Le bouton "PDF" (téléchargement) fonctionne **indépendamment** du mailer ✓.

---

## 💡 Idées V2/V3 (à creuser)

### [ ] V2 — Envoi de convocations par WhatsApp ou SMS (alternative au mail)

**Pourquoi** : le mail est lu en moyenne par 40% des destinataires ; WhatsApp = 95%.
Public cible (parents, jeunes joueuses) = plus actif sur messagerie.

**Options techniques** :
- **WhatsApp Business Cloud API (Meta)** — gratuit jusqu'à 1000 conversations/mois, mais
  - Demande validation Meta (création compte WhatsApp Business)
  - Templates pré-approuvés obligatoires (pas de message libre)
  - Compliqué à mettre en place (~1-2 semaines de set-up)
- **Twilio SMS** — payant ~0.06€/SMS en France
  - Très simple : 1 ligne de code pour envoyer un SMS
  - API mature, doc en français
  - 100 convocations CA/an × 10 membres = 1000 SMS = ~60€/an. Abordable.
- **OVH SMS Pro** — ~0.045€/SMS, intégré à l'hébergeur
  - Avantage : déjà en relation OVH
  - SDK PHP officiel disponible
- **Wallaby SMS / Free Mobile API** — gratuit mais pour des SMS perso (l'API n'envoie qu'à ton propre numéro)

**Reco V2 honnête** : **Twilio ou OVH SMS** d'abord (rapide, fiable), WhatsApp Business
seulement si vraiment besoin. Pour MVP V2, c'est 1-2 jours de dev.

**Architecture** : ajouter un service `ConvocationSmsService` au même niveau que
`ConvocationMailerService`, et un **sélecteur** sur la fiche réunion :
"Envoyer par : ☐ Mail  ☐ SMS  ☐ WhatsApp". Le user choisit ses préférences
de canal de réception dans son profil.

---

## 📚 Décisions architecturales reportées

### [ ] PIRB — Relation Parent ↔ Enfant en BDD
Tâche #105 : pour l'instant pas de lien explicite User parent → Joueur enfant.
À créer quand on attaquera PIRB. Implication : la fonctionnalité "Mon enfant"
côté PIRB et l'envoi de mail aux parents en cas d'absence sont bloqués jusque-là.

### [ ] PIRB — Lien Coach ↔ Équipe (table de jointure)
Tâche #106 : prérequis pour le palier de visibilité "Mon coach".

### [ ] V2 — Tarif licence configurable par CLUB (override Equipe)
Tâche #101 : actuellement géré par équipe + catégorie. À étendre si on veut
un tarif global par club.

---

## 📊 PIRB — Choix source stats sur le profil joueuse

### [ ] Toggle source stats sur PIRB

**Spécification** (validée 13/06/2026) :
La joueuse choisit dans son profil PIRB quelles stats afficher publiquement :
- ☐ Stats Live (saisie tablette banc, session officielle)
- ☐ Stats FFBB (saisie manuelle depuis PDF officiel)
- ☐ Les deux (côte à côte ou agrégés)

Implication technique :
- Champ JSON `pirb_sources_stats` sur Joueur (ex: `["STATS_LIVE", "FFBB"]`)
- ActionMatchAggregator garde une notion de "source" pour distinguer
- Vue PIRB filtre selon préférence

---

## 🎮 Gamification Stats Live — reporter après PIRB

### [ ] XP saisie de stats live

**Statut** : ⏸️ EN ATTENTE PIRB
**Pourquoi reporté** : sans PIRB qui affiche les badges, on construit dans le vide.

**Spécifications cadrées avec le user (07/06/2026)** :
- Un user qui termine une session de saisie Stats Live → +10 XP
- Si sa session est promue OFFICIELLE → +50 XP bonus
- L'XP s'ajoute à l'Axe D (Performance employé/bénévole)

### [ ] Badges PIRB "Statisticien" / "Pro des Stats" / "Maître des Stats"

**Spécifications** :
- **Statisticien** : 1 session officielle (entrée)
- **Pro des Stats** : 3 sessions officielles
- **Maître des Stats** : 10 sessions officielles
- **Légende des Stats** : 25 sessions officielles

Design : trophée doré progressif (bronze → argent → or → diamant).
Affichage sur le profil PIRB (section dédiée).
Architecture : étendre `BadgeCatalog` + nouveau trigger côté `SessionStatsLive::promouvoirOfficielle()`.

---

## 🗓 Synchros à faire

- [ ] Synchroniser ce fichier vers la page Notion "📓 Journal de bord MABB" (manuel pour l'instant)
- [ ] Une fois sur Notion, créer une vue Kanban par statut (À faire / En pause / Idée V2 / Done)
