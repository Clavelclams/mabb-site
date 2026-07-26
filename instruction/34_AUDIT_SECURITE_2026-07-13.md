# 34 — Audit de sécurité Venaball · 13 juillet 2026

> Revue white-box des deux dépôts par Claude (posture : attaquant qui a le
> code). Surface examinée : auth API, isolation multi-club/IDOR, SSO,
> WebViews, uploads, secrets, injections, hygiène prod.
> Verdict global : **posture SAINE pour une app de ce stade** — les
> fondamentaux sont bons, 2 vraies failles trouvées et CORRIGÉES dans la
> foulée, le reste est du durcissement recommandé.

---

## ✅ Ce qui est solide (vérifié, pas supposé)

| Domaine | Constat |
|---|---|
| Jetons API | Opaques 256 bits (`random_bytes(32)`), stockés **hashés SHA-256** en base, expiration 30 j, révocation au logout. Un dump de la table ne donne aucun jeton utilisable. |
| Stockage app | Jeton dans **expo-secure-store** (Keychain/Keystore), pas AsyncStorage. |
| Anti-énumération | Message d'erreur unique au login (compte existant ou non). |
| Isolation / IDOR | Systématique : toutes les données dérivées de la fiche Joueur du Bearer, garde-fou IDOR **explicite** sur les convocations, suppression de push token scopée au user, Follow/classement intra-club, 404 (pas 403) hors club — on ne confirme jamais l'existence d'autrui. |
| Injections SQL | Rien trouvé : QueryBuilder paramétré partout, zéro SQL concaténé hors migrations. |
| Uploads | Liste blanche MIME + extension dérivée du MIME (pas du nom client) sur photos et documents. |
| SSO app→web | HMAC-SHA256 `kernel.secret`, comparaison temps constant (`hash_equals`), expiration 90 s, anti open-redirect (cible locale stricte). |
| Secrets | `.env.local` ignoré par git ✓. `.env` commité : `APP_SECRET` vide ✓, `MAILER_DSN` null ✓, `.env` app ignoré et sans secret ✓. Aucun secret en dur dans le code. |
| WebView bridge | Messages des jeux revalidés champ par champ, bornés (confiance zéro). |
| CSRF web | `enable_csrf: true` sur les form_login. Pas de CORS ouvert sur l'API (aucune config = same-origin par défaut, l'app native n'en a pas besoin). |

---

## 🔴 Corrigé pendant l'audit

### F1 — Brute force possible sur /api/auth/login (élevé) → CORRIGÉ
La route est en `security: false` (nécessaire : c'est elle qui délivre le
jeton) → AUCUN mécanisme Symfony ne la protégeait. Un attaquant pouvait
tester des mots de passe en boucle, sans limite ni trace.
**Double trou** : le login API contourne le firewall → pas de
`LoginFailureEvent` → les échecs API n'étaient **jamais loggés** (invisibles
dans l'admin, brute force indétectable).
**Fix appliqué** (`ApiAuthController`) : throttling sur `ConnexionLog`
(l'infra de log existait déjà) — **10 échecs/IP ou 5 échecs/email en
15 min → 429**. Le seuil email protège un compte ciblé depuis plusieurs IP ;
le seuil IP coupe le scan large. Et les échecs/succès API sont maintenant
loggés comme le web (contexte `api`).

### F2 — WebViews des jeux ouvertes à toute origine (moyen) → CORRIGÉ
`originWhitelist={['*']}` sur tir-auto et dribble-auto : la WebView acceptait
de naviguer vers n'importe quelle origine — une redirection (page compromise,
lien piégé) aurait affiché un site tiers DANS l'app, avec accès postMessage
(le bridge validait, mais défense en profondeur).
**Fix appliqué** : `originWhitelist={[WEB_BASE]}` — navigation restreinte à
notre domaine. Les CDN (MediaPipe) ne sont pas affectés (la whitelist ne
gère que la navigation, pas les sous-ressources).

---

## 🟡 Recommandations (à faire, non bloquant pour TestFlight)

1. **Rotation par précaution** : la `DATABASE_URL` du `.env` commité pointe
   127.0.0.1 (identifiants de DEV local, pas la prod — vérifié). Hygiène
   quand même : placeholder dans `.env`, vraies valeurs dans `.env.local`,
   et change ce mot de passe local s'il ressemble à un mot de passe que tu
   réutilises ailleurs (il est dans l'historique git pour toujours).
2. **Ticket SSO dans l'URL** : il transite en query string → il finit dans
   les access logs OVH et l'historique de la WebView. Risque faible (90 s de
   validité, HTTPS), mais à passer en usage unique (table ou cache) si un
   jour les enjeux montent. Documenté, assumé pour la 1.0.
3. **Reset password / login web** : le brute force web est loggé + alerté
   (LoginLogListener) mais pas BLOQUÉ. Ajouter `login_throttling` Symfony
   sur les firewalls web (2 lignes de yaml) au prochain passage.
4. **En-têtes de sécurité web** : ajouter sur les réponses pirb/manager :
   `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
   `Referrer-Policy: strict-origin-when-cross-origin`. Un petit
   EventListener suffit. (CSP complète = chantier à part, les templates ont
   du style inline partout.)
5. **Purge des logs** : `purgeOlderThan(365)` existe — vérifier qu'un cron
   l'appelle (RGPD : les logs de connexion sont des données personnelles).
6. **Nettoyage** : la mention morte `EXPO_PUBLIC_API_EMAIL/PASSWORD` dans le
   commentaire d'ApiPirbDataService (les variables n'existent plus nulle
   part — vérifié —, mais le commentaire peut faire croire qu'un secret a
   vécu dans un binaire).
7. **Rappel bloc 0** : le backup (doc 33) est LE contrôle de sécurité n°1 —
   la disponibilité/intégrité des données prime sur tout le reste de cette
   liste. Cron + test de restauration.

## Déploiement des fixes
- mabb-site : `src/Controller/Api/ApiAuthController.php` → commit
  `sec(api): throttling login sur ConnexionLog + log des echecs API` → pull
  + cache:clear (pas de migration).
- Pirb store : `app/practice-tir-auto.tsx`, `app/practice-dribble-auto.tsx`
  → commit `sec(app): originWhitelist restreinte au domaine` (tsc ✓).
- Test rapide : 5 logins ratés sur le même email → le 6e doit renvoyer 429 ;
  vérifier les lignes contexte=api dans l'admin des connexions.

---
---

# Seconde passe — 26 juillet 2026 (surface WEB)

> L'audit du 13/07 portait surtout sur l'API et l'app mobile. Cette passe
> couvre les trois espaces web. Elle CONTREDIT trois lignes du tableau
> « ce qui est solide » ci-dessus : elles étaient vraies pour le périmètre
> examiné alors, fausses en dehors. Corrigées ci-dessous.

## Corrections à apporter au tableau du 13/07

| Ligne du 13/07 | Réalité constatée le 26/07 |
|---|---|
| « Isolation / IDOR : systématique » | FAUX côté web PIRB : `PirbMesEnfantsController::ajouter` et `PirbMesParentsController::declarer` faisaient des recherches **sans aucun filtre club**. |
| « Uploads : liste blanche MIME partout » | FAUX : `CompteController::updateProfil` (avatar vitrine) n'avait **aucune validation**, ni MIME, ni taille, ni extension. |
| « Secrets : `.env` commité, APP_SECRET vide ✓ » | Vrai pour `.env`, mais **`.env.dev` était versionné avec un vrai secret** (`7dca23…`), sur un dépôt **public**. |

## Ce qui a été trouvé et corrigé le 26/07

### Fuite du fichier nominatif des mineures (critique)
`PirbMesEnfantsController::ajouter` (GET) interrogeait `joueur` sans filtre
club. N'importe quel compte connecté, en bouclant `?q=a`, `?q=b`…,
reconstituait nom, prénom et équipe (donc tranche d'âge) de **toutes les
joueuses de tous les clubs**. Le POST acceptait en plus n'importe quel
`joueur_id` : on créait une demande de rattachement parental sur une mineure
d'un autre club, que le staff validait de bonne foi.
**Corrigé** : recherche bornée aux clubs de l'utilisateur (sa fiche, ses
UserClubRole actifs, ses enfants déjà liés), 2 caractères minimum, contrôle
d'appartenance au POST, refus journalisé, message identique dans tous les cas.

### Fuite des e-mails de la plateforme (critique)
`PirbMesParentsController::declarer` cherchait dans `user` par e-mail, nom ou
prénom, **sans jointure club** malgré le docblock qui annonçait le contraire.
`?q=@` renvoyait les adresses de toute la base : matière première d'une
campagne d'hameçonnage sur des familles.
**Corrigé** : membres actifs du club de la joueuse uniquement, recherche par
e-mail retirée, contrôle d'appartenance au POST (`estMembreDuClub`).

### Upload d'avatar sans contrôle (critique)
`CompteController::updateProfil` : nom de fichier issu du client, extension
issue de `guessExtension()` (donc du contenu sniffé). Un fichier HTML ou SVG
contenant du script passait et atterrissait dans `public/uploads/avatars/`,
servi sur l'origine `mabb.fr` — donc exécuté avec les sessions vitrine ET
admin. Exploitable par n'importe quel compte auto-inscrit (inscription
publique, pas de vérification d'e-mail).
**Corrigé** : liste blanche MIME → extension imposée, nom entièrement généré
(`random_bytes`), 2 Mo max.

### Fichiers sensibles dans le dépôt public (critique, hors code)
`.env.dev` (secret réel), deux PDF de réunion dont un compte rendu
d'entretien nominatif, et trois dumps SQL contenant des noms de joueuses.
**Traité** : secret tourné en prod, fichiers retirés du dépôt (commit
`16b658d`). ⚠️ **RESTE À FAIRE : l'historique git les contient toujours.**
Passer le dépôt en privé, ou réécrire l'historique.

### Autres correctifs du 26/07
- `public/uploads/.htaccess` : accès direct fermé par défaut, seules les
  images d'illustration passent. Bouche l'accès aux justificatifs de
  trésorerie, PV et feuilles de match, jusqu'ici lisibles par URL devinée
  (`uniqid()` n'est pas un aléa cryptographique : préfixe temporel déductible).
- `AdminUploadController` : SVG retiré de la liste blanche (XSS stockée), et
  `denyAccessUnlessGranted('ROLE_ADMIN')` corrigé en `ROLE_SUPER_ADMIN` — ce
  rôle n'existe nulle part dans le projet, c'était un contrôle fantôme.
- `SaisonController` : redirection sur `Referer` brut remplacée par le seul
  chemin (open redirect utilisable en hameçonnage).
- `.env` : `APP_ENV=prod` comme défaut sûr (le fichier est public).
- `security.yaml` : `enable_csrf` ajouté au firewall vitrine (les 3 autres
  l'avaient), blocs `login_throttling` **préparés mais commentés**.

## Point de vigilance : login_throttling
Les 4 blocs sont écrits et commentés dans `security.yaml`. Ne PAS les
décommenter avant : `composer require symfony/rate-limiter`, commit du
`composer.lock`, puis `composer install --no-dev` **sur le serveur**. Sans le
composant, le conteneur ne compile plus et tout le site tombe en 500.
Rappel : `vendor/` n'est pas versionné et le déploiement manuel
(`fetch + reset --hard`) ne lance PAS composer.

## Reste ouvert après le 26/07
1. Historique git à purger (ou dépôt privé). **Priorité 1.**
2. `login_throttling` (voir ci-dessus) : le web reste sans limite de tentatives.
3. Déplacer les fichiers sensibles hors de `public/` (7 uploaders sur 8), comme
   `DechargeSortieUploader` le fait déjà. Le `.htaccess` est un pansement.
4. Contenu CMS rendu en `|raw` sans nettoyage (6 templates) : ajouter
   `symfony/html-sanitizer`.
5. En-têtes de sécurité (déjà recommandé le 13/07, toujours absent).
6. `UserChecker` : un compte désactivé (`isActive=false`) peut toujours se
   connecter, et ses jetons API survivent à un effacement RGPD.
7. `uniqid()` → `bin2hex(random_bytes(16))` dans les uploaders restants.
