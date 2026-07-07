# Backlog — MABB Manager + PIRB

> Dernière mise à jour : 2026-06-26
> Le projet est en production sur OVH (manager.mabb.fr + pirb.mabb.fr).
> Ce backlog reflète l'état RÉEL du code, pas les intentions initiales.

---

## État actuel — Fonctionnel en prod

| Module | Feature | Statut |
|--------|---------|--------|
| Auth | Login/logout multi-host (manager/pirb), TenantResolver | ✅ prod |
| Équipes | CRUD équipes + joueuses + staff | ✅ prod |
| Rencontres | CRUD rencontre + feuille FFBB (résumé/feuille/positions PDF) | ✅ prod |
| Stats Live | Saisie live match (actions, tirs, présences) + bénévole | ✅ prod |
| Shot chart | V2.3 — SeanceTir/ZoneTir + TirFfbb fusionnés sur la map PIRB | ✅ prod |
| ENT | Upload docs (Manager) + lecture PIRB + PDFs FFBB dans ENT | ✅ prod |
| PIRB | Dashboard, shot chart, documents, profil joueuse | ✅ prod |
| Nav | Stats Live + ENT ajoutés dans la navbar Manager | ✅ prod (ce commit) |

---

## Backlog actif — À faire

### P1 — Urgent / Cette semaine

| ID | Feature | Description | Effort |
|----|---------|-------------|--------|
| B-101 | Stats Live page dédiée (navbar) | ✅ **FAIT CE JOUR** — `/stats-live` liste toutes les rencontres avec statut session | fait |
| B-102 | Feedback inscription bénévole | Quand une joueuse s'inscrit sur une rencontre pour aider (bénévole table/marquage), elle voyait rien changer. Ajouter flash "Inscription enregistrée, en attente de validation staff" côté PIRB + badge visible sur la rencontre | 30 min |
| B-103 | Push commit ENT + Shot chart + Stats Live | `git add` + `git push` + `git pull OVH` + `cache:clear` | 10 min |

### P2 — Important / Prochaine session

| ID | Feature | Description | Effort |
|----|---------|-------------|--------|
| B-201 | Coach valide stats live | Workflow : après un match, le coach ouvre la session COMPLETE → vérifie les stats → clic "Valider officielle". Passe la SessionStatsLive en STATUT_OFFICIELLE. Bloque l'édition. UI côté Manager : bouton "Valider" sur la page `/stats-live` pour chaque session COMPLETE. | 4h |
| B-202 | Card "Prendre les stats" dashboard | Raccourci rapide depuis le dashboard Manager/PIRB pour aller directement sur `/stats-live`. Simple card avec lien + icône ⚡. | 30 min |
| B-203 | Ajouter joueuse manuellement stats live | Si joueuse absente de l'effectif dans la page stats live — déjà partiellement géré via "joueur éphémère" mais à vérifier si bug d'affichage. Tester avec une rencontre réelle. | à clarifier |
| B-204 | Pastille rouge notifications PIRB | Badge rouge sur l'avatar PIRB quand il y a des nouvelles non lues (validation coach, document, convocation). | 2h |
| B-205 | Fix 500 `/joueuses/{id}/missions/nouvelle` | Bug connu — à investiguer | 1h |
| B-206 | Fix 404 `manager.mabb.fr/signup` depuis PIRB | Bug connu — à investiguer | 30 min |

### P3 — Plus tard / Quand priorités P1+P2 sont terminées

| ID | Feature | Description | Effort |
|----|---------|-------------|--------|
| B-301 | ENT style Kalisport GED | Refonte de l'interface `/ent` avec filtres catégorie/saison, vignettes de documents, prévisualisation inline. S'inspirer de : https://hautsdefrancebasketball.kalisport.com/private/ged (connexion requise). Clavel doit partager une capture d'écran de la GED. | 3h |
| B-302 | Shot chart — terrain cliquable saisie | Tâches #9/#10 du backlog original — permettre à une joueuse de saisir ses tirs depuis PIRB sur un terrain interactif cliquable. Controller `pirb_shot_chart_sauvegarder` existe, terrain modal dans le template. À finaliser. | 4h |
| B-303 | Équipes 2026-27 | Créer les équipes de la saison 2026-27 et re-run `app:seed-plannings-2026-27` | 1h |
| B-304 | MAILER_DSN Brevo sur OVH | Configurer l'envoi d'emails transactionnels (confirmation inscription, convocations). MAILER_DSN dans `.env.local` OVH. | 1h |
| B-305 | Stats FFBB auto — parse PDF à l'upload | Quand un admin upload le PDF "positions de tirs" FFBB, le parser `FfbbPositionTirParser` tourne automatiquement et stocke dans `TirFfbb`. Actuellement c'est une commande manuelle. | 3h |
| B-306 | Toggle FFBB/Live shot chart | Sur la shot map PIRB, pouvoir filtrer : "uniquement matchs FFBB" / "uniquement entraînements" / "tout". Le filtre `source` existe côté controller, à wirer côté UI. | 1h |
| B-307 | Stats Live — créer rencontre depuis `/stats-live` | Modal "Créer rencontre rapide" directement depuis la page Stats Live (sans passer par `/rencontres/nouvelle`). Formulaire minimal : date + adversaire + équipe. | 2h |
| B-308 | Validation inscription bénévole par staff | Côté Manager : liste des inscrits bénévoles par rencontre, bouton "Valider" ou "Refuser" avec email automatique. Actuellement l'inscription existe mais la validation n'a pas de feedback. | 2h |

---

## Ideas brutes — Pas encore qualifiées

Ces idées viennent de l'utilisateur, pas encore priorisées ni estimées :

- **Shot chart agrégé saison** : vue "toute la saison" qui agrège tous les matchs de la joueuse sur un seul terrain. Idéal pour voir les zones chaudes et froides. Besoin : agréger TirFfbb par type sur un terrain unique côté PIRB.
- **ENT — onglet "Effectif"** : section dédiée dans l'ENT pour les documents liés à l'effectif (licences, certificats médicaux, autorisations).
- **ENT — onglet "Rencontre"** : section dédiée dans l'ENT pour les documents liés aux matchs (feuilles, résumés, comptes-rendus). **Déjà partiellement fait** via la section "PDFs officiels FFBB".
- **ENT — filtres Kalisport** : filtres par catégorie, saison, équipe. Voir capture Kalisport GED.
- **Notification staff inscription bénévole** : quand une joueuse s'inscrit → le staff reçoit une notification (email ou pastille).

---

## Bugs connus en prod

| ID | Bug | Impact | Statut |
|----|-----|--------|--------|
| BUG-01 | 500 sur `/joueuses/{id}/missions/nouvelle` | Moyen | ✅ corrigé (B-205, try/catch gamification) + testé (`MissionAccessTest`, 07/07) |
| BUG-02 | 404 sur `manager.mabb.fr/signup` depuis lien PIRB | Faible | à corriger |
| BUG-03 | Bénévole inscrite sur rencontre → pas de feedback visuel | UX | en cours (B-102) |

---

## Architecture de référence (rappel)

- **Stack** : Symfony 7.4 / PHP 8.3 / MySQL 8.4 / OVH hébergement mutualisé
- **Hosts** : `manager.mabb.fr` (staff) + `pirb.mabb.fr` (joueuses/parents)
- **Multi-tenant** : `TenantResolver` → `ClubVoter` → `ClubAwareInterface`
- **Namespaces critiques** :
  - `App\Security\Tenant\TenantResolver`
  - `App\Security\Voter\ClubVoter`
- **PDFs FFBB** : stockés dans `public/uploads/rencontres/{id}/` — servis via `manager_rencontre_pdf_serve`
- **Uploads ENT** : stockés dans `public/uploads/ent/{clubId}/` — servis via `manager_ent_voir`
- **Filtre Twig OVH** : `|u.truncate()` NON disponible → utiliser `|slice(0, N) ~ '…'`
- **MySQL COMMENT** : pas d'apostrophes dans les strings COMMENT SQL (cassent le parsing)
