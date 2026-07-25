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
