# Suivi CDC — État d'avancement au 2026-03-13

> Référence : `instruction/CDC/CDC_MABB_PIRB_V1_Definitif.pdf` + `instruction/CDC/Cahier des charges – Site web MABB.fr.pdf`
> Mise à jour : 2026-03-13
> Légende : ✅ Fait · 🔄 En cours · ⬜ À faire · ❌ Hors périmètre V1

---

## GOUVERNANCE & DOCUMENTATION

| #    | Item                                   | Statut | Notes                             |
| ---- | -------------------------------------- | ------ | --------------------------------- |
| G-01 | Fichiers de gouvernance (00 à 14)      | ✅     | Créés en sessions 1-5 (fév.-mars 2026) |
| G-02 | Backlog 67 items priorisés             | ✅     | 09_BACKLOG.md                     |
| G-03 | ADR 0001-0006 documentées              | ✅     | 08_ADR.md                         |
| G-04 | Registre technique + sécurité RGPD     | ✅     | 06, 07                            |
| G-05 | Definition of Done + Checklist release | ✅     | 10, 11                            |
| G-06 | Arborescence projet                    | ✅     | instruction/arborescence.md       |

---

## PHASE 1 — CORE (Auth, multi-tenant, rôles)

| BL       | Item                                                             | Statut | Notes                                                                                            |
| -------- | ---------------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------ |
| BL-0000  | Résolution blocage composer SSL / php.ini                        | ⬜     | P0 bloquant — à traiter en premier                                                               |
| BL-0001  | Entité `User` (email, password, nom, prénom, statut, timestamps) | ⬜     |                                                                                                  |
| BL-0002  | Entité `Club` (nom, logo, couleurs, statut, club_id_ffbb)        | ⬜     |                                                                                                  |
| BL-0003  | Entité `ClubUser` (pivot user-club)                              | ⬜     |                                                                                                  |
| BL-0004  | Entité `Role`                                                    | ⬜     |                                                                                                  |
| BL-0004b | Entité `ClubUserRole` + contraintes unicité DB                   | ⬜     |                                                                                                  |
| BL-0005  | `ClubScopeVoter` (filtrage multi-tenant)                         | ⬜     |                                                                                                  |
| BL-0006  | `OwnershipVoter`                                                 | ⬜     |                                                                                                  |
| BL-0007  | `TeamCoachVoter`                                                 | ⬜     |                                                                                                  |
| BL-0008  | JWT (LexikJWTAuthenticationBundle) + `security.yaml`             | ⬜     |                                                                                                  |
| BL-0009  | Formulaire inscription (opt-in bénévole + consentement CGU/RGPD) | 🔄     | Template `s_inscrire.html.twig` créé — logique métier (entité User, validation, hachage) à faire |
| BL-0010  | `password_hashers` bcrypt/argon2 dans `security.yaml`            | ⬜     |                                                                                                  |
| BL-0011  | Première migration Doctrine                                      | ⬜     | Dépend BL-0001 à 0004b                                                                           |
| BL-0012  | Rate limiting login (5 tentatives/min)                           | ⬜     |                                                                                                  |
| BL-0013  | Récupération mot de passe (lien sécurisé)                        | ⬜     |                                                                                                  |
| BL-0014  | Endpoints API auth + contexte club (Controller Symfony natif)    | ⬜     |                                                                                                  |

---

## PHASE 2 — SPORT (Saisons, équipes, joueurs, événements)

| BL      | Item                                       | Statut | Notes |
| ------- | ------------------------------------------ | ------ | ----- |
| BL-0020 | Entité `Season`                            | ⬜     |       |
| BL-0021 | Entité `Team`                              | ⬜     |       |
| BL-0022 | Entité `Player`                            | ⬜     |       |
| BL-0023 | Entité `Event`                             | ⬜     |       |
| BL-0024 | Entité `Match`                             | ⬜     |       |
| BL-0025 | Entité `Presence`                          | ⬜     |       |
| BL-0026 | Entité `Convocation`                       | ⬜     |       |
| BL-0027 | CRUD saisons / équipes / joueurs (Manager) | ⬜     |       |
| BL-0028 | Gestion des présences (interface coach)    | ⬜     |       |

---

## PHASE 3 — STATS (Saisie match, shot tracking, timeline)

| BL      | Item                                                        | Statut | Notes |
| ------- | ----------------------------------------------------------- | ------ | ----- |
| BL-0030 | Installer API Platform                                      | ⬜     |       |
| BL-0031 | Entité `PlayerStat`                                         | ⬜     |       |
| BL-0032 | Entité `ShotRecord`                                         | ⬜     |       |
| BL-0033 | Entité `MatchEvent` (timeline)                              | ⬜     |       |
| BL-0034 | Interface terrain interactif (shot tracking, tablette)      | ⬜     |       |
| BL-0035 | Cinq majeur + remplacements                                 | ⬜     |       |
| BL-0036 | Workflow validation match (brouillon → validé → verrouillé) | ⬜     |       |
| BL-0037 | Calculs automatiques (moyennes, taux réussite)              | ⬜     |       |

---

## PHASE 4 — PIRB (Dashboard joueur, shot chart, feedback)

| BL      | Item                                              | Statut | Notes |
| ------- | ------------------------------------------------- | ------ | ----- |
| BL-0040 | Entité `PlayerProfile` (visibilité, description)  | ⬜     |       |
| BL-0041 | Dashboard joueur (stats clés, résumé saison)      | ⬜     |       |
| BL-0042 | Shot chart interactif (visualisation tirs)        | ⬜     |       |
| BL-0043 | Timeline personnelle (actions par match)          | ⬜     |       |
| BL-0044 | Entité `TrainingFeedback` (feedback anonyme)      | ⬜     |       |
| BL-0045 | Gestion visibilité profil (Public / Club / Privé) | ⬜     |       |

---

## PHASE 5 — VITRINE (mabb.fr)

| BL      | Item                                                         | Statut | Notes                                     |
| ------- | ------------------------------------------------------------ | ------ | ----------------------------------------- |
| —       | Structure Symfony + routing multi-host vitrine               | ✅     | `AccueilController` opérationnel          |
| —       | `base.html.twig` (navbar, footer, design system Bootstrap 5) | ✅     | Fonts : Inter + Montserrat                |
| —       | Page Accueil (`/`)                                           | ✅     | Statique                                  |
| —       | Page Le Club (`/club`)                                       | ✅     | Statique                                  |
| —       | Page Équipes (`/equipes`)                                    | ✅     | Statique                                  |
| —       | Page Actualités (`/news`)                                    | ✅     | Statique (CMS à brancher)                 |
| —       | Page Galerie (`/galerie`)                                    | ✅     | Statique (upload à brancher)              |
| —       | Page Calendrier (`/calendrier`)                              | ✅     | Statique (Score'n'co à brancher)          |
| —       | Page Numérique (`/numerique`)                                | ✅     | Statique                                  |
| —       | Page Contact (`/contact`)                                    | ✅     | Formulaire HTML (envoi email à brancher)  |
| —       | Navbar : boutons Connexion + S'inscrire                      | ✅     | Pointe vers `CompteController`            |
| —       | Page Connexion (`/compte/se-connecter`)                      | 🔄     | Template HTML fonctionnel (formulaire + CSRF) — SecurityBundle à brancher |
| —       | Page Inscription (`/compte/s-inscrire`)                      | 🔄     | Template HTML fonctionnel (formulaire + consentement RGPD) — entité User + validation à faire |
| BL-0050 | Back-office CMS (édition pages, articles, médias)            | ⬜     |                                           |
| BL-0051 | Entités `Article`, `Page`, `Media` (Vitrine)                 | ⬜     |                                           |
| BL-0052 | Éditeur WYSIWYG                                              | ⬜     |                                           |
| BL-0053 | Actualités dynamiques en page d'accueil                      | ⬜     | Actuellement statiques                    |
| BL-0054 | Galerie photos par saison / événement                        | ⬜     |                                           |
| BL-0055 | Calendrier public (lien Score'n'co)                          | ⬜     |                                           |
| BL-0056 | Formulaire contact + captcha + envoi email                   | ⬜     | HTML présent, mailer non configuré        |
| BL-0057 | SEO : sitemap XML, robots.txt, métadonnées éditables         | ⬜     |                                           |

---

## PHASE 6 — SYSTÈME (Sécurité, tests, RGPD)

| BL      | Item                                                | Statut | Notes |
| ------- | --------------------------------------------------- | ------ | ----- |
| BL-0060 | Logs de connexion (table + EventSubscriber)         | ⬜     |       |
| BL-0061 | Audit actions sensibles (table audit + AuditLogger) | ⬜     |       |
| BL-0062 | Commande purge logs > 12 mois                       | ⬜     |       |
| BL-0063 | Commande anonymisation comptes inactifs > 24 mois   | ⬜     |       |
| BL-0064 | Export données personnelles (RGPD)                  | ⬜     |       |
| BL-0065 | Tests anti-fuite inter-club (Voters + API)          | ⬜     |       |
| BL-0066 | Tests unitaires + fonctionnels                      | ⬜     |       |
| BL-0067 | Documentation technique                             | ⬜     |       |

---

## RÉSUMÉ

| Phase             | Total items | ✅ Fait | 🔄 En cours | ⬜ À faire |
| ----------------- | ----------- | ------- | ----------- | ---------- |
| Gouvernance       | 6           | 6       | 0           | 0          |
| Phase 1 — Core    | 15          | 0       | 1           | 14         |
| Phase 2 — Sport   | 9           | 0       | 0           | 9          |
| Phase 3 — Stats   | 8           | 0       | 0           | 8          |
| Phase 4 — PIRB    | 6           | 0       | 0           | 6          |
| Phase 5 — Vitrine | 18          | 11      | 2           | 5          |
| Phase 6 — Système | 8           | 0       | 0           | 8          |
| **TOTAL**         | **70**      | **17**  | **3**       | **50**     |

**Avancement global : ~24 %** (documentation + vitrine statique posée)

---

## PROCHAINES PRIORITÉS (ordre recommandé)

1. **BL-0000** — Débloquer composer SSL (P0, tout est bloqué sans ça)
2. **BL-0001 à BL-0011** — Entités Core + migration + security.yaml
3. **BL-0008 + BL-0009** — Brancher les pages Connexion/Inscription sur le vrai SecurityBundle
4. **BL-0056** — Configurer le mailer (formulaire contact déjà en place)
5. **BL-0051 + BL-0053** — Entités Article + actus dynamiques vitrine
