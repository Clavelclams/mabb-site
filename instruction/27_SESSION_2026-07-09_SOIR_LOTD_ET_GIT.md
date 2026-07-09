# Session 09/07/2026 (soir) — Lot D fini, état réel corrigé, plan git & déploiement

Complète (et corrige) les docs 25 et 26. À lire AVANT de committer quoi que ce soit.

---

## 1. Correction majeure : il n'y a JAMAIS eu de corruption

Le doc 26 signalait des fichiers « re-modifiés » suspects (`stats-live.html.twig` −74,
`practice.tsx`, etc.). Vérification faite fichier par fichier en accès disque direct :
**tous ces diffs étaient des fantômes** — la copie du dépôt montée dans le sandbox
Claude était tronquée (~20 fichiers coupés net, artefact de synchronisation), pas tes
fichiers. Conséquences pratiques :

- **Ton disque est sain.** Ne lance AUCUN `git checkout --` de « réparation ».
- Le `git status` fiable est celui de TA machine, pas celui d'une session Claude.
  Règle : avant d'agir sur la foi d'un diff annoncé par une session, demande-lui si
  elle a vérifié par lecture directe (outil Read) et pas seulement par le shell.
- Le `.git/index.lock` signalé n'existe pas chez toi (vérifié).

## 2. Ce que cette session a produit (tout est sur ton disque, à committer)

| Quoi | Fichiers |
|---|---|
| **Lot D — purge RGPD** (commande `app:sorties:purger-rgpd`, dry-run par défaut) | `src/Command/PurgerInscriptionsSortiesCommand.php` *(nouveau)* |
| **Lot D — décharge signée v2** (upload/voir/supprimer, stockage HORS public/) | `src/Service/DechargeSortieUploader.php` *(nouveau)*, `src/Controller/Manager/EvenementController.php`, `templates/manager/evenement/show.html.twig`, `config/services.yaml` |
| **Sécu** : access_control `^/super-admin` → ROLE_SUPER_ADMIN | `config/packages/security.yaml` |
| **Hygiène CRLF** | `.gitattributes` *(nouveau, racine)* |
| **Docs** : RGPD-0010, log session, commentaires SaisonService corrigés (bascule = juillet) | `instruction/07_REGISTRE_SECURITE_RGPD.md`, `instruction/13_CLAUDE_LOG.md`, `src/Service/SaisonService.php` |

→ **Le chantier Sorties est CODE-COMPLET** (Lots A à D). Le SuperAdmin cross-club
aussi (il était déjà fini avant cette session, contrairement à ce que pensait le doc 26).

## 3. Avant de committer : 2 vérifs locales (2 min)

```bat
cd C:\Users\Velito Adventure\Documents\mabb-site
php -l src/Command/PurgerInscriptionsSortiesCommand.php
php -l src/Service/DechargeSortieUploader.php
php -l src/Controller/Manager/EvenementController.php
php bin/console lint:twig templates/manager/evenement/
php bin/console lint:yaml config/
```
(Le sandbox n'a pas PHP : syntaxe vérifiée par parseur + relecture, mais un
`php -l` chez toi reste la ceinture de sécurité.)

Puis teste vite fait en local : page événement avec autorisation requise →
joindre une décharge (PDF ou photo) → « décharge » s'affiche → voir → supprimer.
Et : `php bin/console app:sorties:purger-rgpd` (dry-run, ne modifie rien).

## 4. Commits par lots (jamais `git add .`)

```bat
:: Lot 1 — hygiène CRLF (en premier : les commits suivants seront propres)
git add .gitattributes
git commit -m "chore: .gitattributes (normalisation LF, fin du bruit CRLF)"
git add --renormalize .
git commit -m "chore: renormalisation des fins de ligne"

:: Lot 2 — Sorties Lot D (RGPD complet)
git add src/Command/PurgerInscriptionsSortiesCommand.php src/Service/DechargeSortieUploader.php src/Controller/Manager/EvenementController.php templates/manager/evenement/show.html.twig config/services.yaml
git commit -m "feat(sorties): Lot D RGPD - purge fin de saison + decharge signee v2 (stockage hors public)"

:: Lot 3 — sécu super-admin + le reste du chantier super-admin s'il est encore non commité
git add config/packages/security.yaml src/Security/Tenant/TenantResolver.php src/Controller/Manager/ManagerLoginController.php src/Controller/Manager/SuperAdminController.php templates/manager/super_admin/
git commit -m "feat(manager): console super-admin cross-club + access_control /super-admin"

:: Lot 4 — dashboard sorties (si pas déjà commité)
git add templates/manager/evenement/sorties_dashboard.html.twig templates/manager/evenement/index.html.twig src/Repository/Sport/EvenementRepository.php
git commit -m "feat(sorties): dashboard global saison"

:: Lot 5 — dribble.html (ajustements post-ddce615) + docs + divers
git add public/playground/dribble.html src/Service/SaisonService.php instruction/
git commit -m "chore: dribble.html ajustements + docs (RGPD-0010, etat des lieux, log)"

git push origin main
git log origin/main..HEAD   :: doit être vide
```

Adapte les lots à TON `git status` réel (certains fichiers sont peut-être déjà
commités — le statut vu d'ici n'était pas fiable, cf. §1).

## 5. Déploiement OVH (⚠️ nouvelle étape : migration)

```bash
git pull origin main
php bin/console doctrine:migrations:migrate   # colonnes Club (Version20260709124204) — la table sorties (Version20260708021754) doit déjà y être, sinon elle passe ici
php bin/console cache:clear --env=prod
```

Checklist de validation (reprend doc 25/26) :
1. Shot chart : plus de 500, zones = Live only (vides sans Live), saison 2026-27 propre
2. Match cliquable (accès par participation)
3. Formulaire séance auto-ouvert + bouton « Envoyer au coach » visible
4. `https://pirb.mabb.fr/playground/dribble.html` sert le jeu complet
5. **Nouveau** : page événement → décharge (joindre/voir/supprimer) ; `app:sorties:purger-rgpd` en dry-run sur la prod pour voir le périmètre
6. Sécurité : `/super-admin/clubs` en étant connecté SANS ROLE_SUPER_ADMIN → refus
7. Cron annuel à poser (15 juillet) : `php bin/console app:sorties:purger-rgpd --execute`

## 6. Et après (ordre inchangé, doc 26 reste la boussole)

B (déploiement+validation) → C (dribble iPhone) → **D (TestFlight = LE chemin
critique, lance le compte Apple Developer dès demain)** → E est maintenant FAIT côté
code (il reste la migration prod + validation) → G/H. Follow et détection tir : après
le store, comme prévu.
