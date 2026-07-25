# 22 — Audit écosystème MABB / PIRB (Manager Symfony + App mobile + Ressources)

> Date : 2026-07-07
> Auteur : session collaborateur (lecture seule des 3 dépôts : `mabb-site`, `Pirb store`, `mabb`)
> Objet : état réel du code vs documentation, dette technique, risques, ordre de bataille.
> Note : audit factuel, pas de complaisance. Chiffres et chemins vérifiés dans le code.

---

## 0. Périmètre analysé

| Dépôt | Nature | Maturité |
|---|---|---|
| `mabb-site` | Monolithe Symfony 7.4 (Vitrine + Manager + PIRB web + API) — **en prod** | Mûr |
| `Pirb store` | App mobile Expo/React Native SDK 54, TypeScript strict — pendant natif espace joueur | Early-mid |
| `mabb` | Dépôt de ressources (pas de code) : fixtures FFBB, docs métier, captures concurrent, pitch scouting | N/A |

---

## 1. MABB Manager (Symfony) — solide, 3 trous réels

### Points forts (vérifiés)
- **Architecture en couches respectée** : thin controllers + services (~38 services, 6900 lignes). Logique métier hors des controllers.
- **Sécurité SQL** : 100 % QueryBuilder avec `setParameter()`. **Aucune injection détectée.**
- **CSRF** : systématique sur formulaires + POST/DELETE destructifs (40+ `isCsrfTokenValid`).
- **Firewalls** : 7 firewalls isolés par host (vitrine / vitrine_admin / manager / pirb / api / api_login / main). CSRF sur login form.
- **Multi-tenant Manager** : sur échantillon audité (Equipe, Joueur, Seance, StatsLive, Evenement, Rencontre), tous appellent `denyAccessUnlessGranted(ClubVoter::…)` + filtrent par `club`. Pas de fuite identifiée.
- **Migrations** : 20 migrations Doctrine cohérentes, aucune éditée à la main.
- **Documentation** : nettement au-dessus de la norme CDA.

### Inventaire
- Controllers : ~76 (Admin 9, Manager 36, Pirb 13, Vitrine 9, Api 2, racine 7)
- Entités : ~48 (Core 8, Sport ~39, Vitrine 4) — 26 implémentent `ClubAwareInterface`
- Tests : ~24 fichiers (18 unit + 5 fonctionnels + bootstrap)

### Problèmes réels, par gravité

**🔴 P0 — Mailer mort en prod.** `MAILER_DSN=null://null`. Contact, invitations parents, convocations : rien ne part et l'UI affiche « succès ». Un formulaire qui ment est pire qu'un formulaire cassé. → ticket **B-304**, à remonter en P0.

**🟠 Majeur — PIRB web sans TenantResolver.** Les 13 controllers Pirb isolent uniquement par `Joueur.user === getUser()`, jamais via le club actif. Tient aujourd'hui (1 user = 1 joueur = 1 club) et couvert par tests IDOR fonctionnels, mais dette structurelle avant tout multi-club. Cohérent avec ADR-0007 (refonte JWT) → choix documenté, à ne pas oublier.

**🟠 Majeur — Doc en retard sur le code.** `06_REGISTRE_TECHNIQUE.md` liste « à faire » des éléments déjà en prod (filtrage multi-tenant, migrations). Risque : décisions sur doc périmée. → resynchroniser.

**🟡 Dette de fond (non bloquant)**
- God-objects : `Rencontre` (~702 lignes), `Joueur` (~557 lignes). Refactor V3, pas urgent.
- Couverture tests : focalisée entités + IDOR. **Manque tests fonctionnels Manager, API, stats, gamification.** Coûtera des points au jury CDA (bloc tests attendu).
- API métier quasi vide : firewall `api` + `ApiTokenHandler` en place, mais peu d'endpoints CRUD → bloque le mobile (cf. §2).

---

## 2. PIRB mobile (Expo) — belle coquille, cœur à moitié branché

### Points forts
- Ossature très propre : couche données abstraite (mock ↔ API swappable), auth JWT réelle (token en SecureStore, gestion 401), design tokens centralisés, TypeScript strict, ~3700 lignes.
- Practice (dribble / tir) jouable et **persistant** (AsyncStorage).
- Contrat client déjà figé dans `src/types/pirb.ts` + interface `PirbDataService`.

### Maturité réelle (lucide)
- **Core (profil, stats, badges, niveau) : ~80 % branché API.**
- **Social (commu, recherche, carte, follow, attributs RPG) : ~40 %, 100 % mock.**
- **Interactions mortes** : compteurs abonnés cliquables sans écran, résultats de recherche sans page profil.
- **Pas prêt store** : version 0.1.0, pas de dev build EAS testé, spikes détection vidéo (P2/P3) sans verdict GO/NO-GO.

### Le vrai risque du mobile n'est pas le code
C'est la **dépendance à des endpoints backend absents** (commu, follow, XP équipe, saisons). Tant que Symfony ne les expose pas, la moitié de l'app reste une maquette animée. Le contrat exact est déjà écrit dans `instruction/DEMANDES_APP_PIRB_B4_PHASE2_2026-07-07.md`.

---

## 3. Dépôt `mabb` — ressources & contexte stratégique

Pas de code. Contient :
- ~100 feuilles de match FFBB réelles (feuille + position tir + résumé) → fixtures d'import.
- Docs métier : CDC fonctionnel, architecture, audits, CR réunion saison 25/26.
- Captures WhatsApp d'une app concurrente (« app à pomper »).
- Pitch **PIRB Scouting**.

### 🔴 Alerte marque (à traiter, pas cosmétique)
`@pirb_scouting` **existe déjà** : acteur réel du scouting basket (highlights LNB, joueurs draftés NBA — picks 17 & 32 en 2025), présent Instagram/TikTok/YouTube avec sous-comptes. L'app s'appelle PIRB, le feature scouting s'appelle « Pierre ». **Risque de collision de marque évident sur les stores.** Le rename (avec Willy) est de la protection, pas de l'esthétique. Vérifier dispo du nom sur stores + `.fr` + Instagram AVANT de choisir. Décision à prendre tôt, pas au dernier moment.

---

## 4. Ordre de bataille recommandé

L'instinct disait « finir l'app d'abord ». L'analyse dit l'inverse : **le mobile est bloqué par le backend.**

1. **B-304 mailer** (≈1h) — arrêter de mentir aux utilisateurs.
2. **Endpoints API manquants côté Symfony** (commu, follow, saisons, XP équipe…) — ce qui débloque *réellement* le mobile. Contrat = `DEMANDES_APP_PIRB_B4_PHASE2`.
3. **Puis** sprint mobile : brancher le social, tuer les interactions mortes, dev build EAS.
4. **Rename** décidé en parallèle avec Willy (nom store = valeur de config, ne bloque pas le code).
5. En continu : **tests fonctionnels** (jury CDA avril 2027).

### Décision prise (07/07)
Chantier suivant retenu : **endpoints API pour le mobile** (option 2), en suivant l'ordre proposé dans `DEMANDES_APP_PIRB_B4_PHASE2` (commu → sélecteur saison → follow → mon club/carte).
