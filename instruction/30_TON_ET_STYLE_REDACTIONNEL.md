# 30 — Ton & style rédactionnel (anti « ressenti IA »)

> **Règle prioritaire. À respecter dans TOUT texte visible par un utilisateur** :
> titres, sous-titres, boutons, messages flash, états vides, emails, app.
> Ne s'applique PAS aux commentaires de code (on s'en fiche, personne ne les lit).

---

## Le problème

Le projet « sentait l'IA ». Les gens le remarquent sans savoir le nommer, et
ça décrédibilise le produit devant un président de club ou une joueuse de 14 ans.

## Les 6 tics à bannir

### 1. Le tiret cadratin (—)
C'est LA signature. Un humain écrit rarement « Venaball — MABB ».

- ❌ `Mon Espace Joueur — Venaball`
- ✅ `Venaball`
- ❌ `Saison 2026-2027 — 42 lignes affichées`
- ✅ `Saison 2026-2027, 42 lignes`

**Exception tolérée** : séparateur de données réel (`12/05 · 14h00`) → préférer `·` ou une virgule.

### 2. Le verbe pompeux
- ❌ « Accède à tes stats, ton profil et tes performances »
- ✅ « Tes stats, ton profil, tes progrès. Tout est là. »

### 3. La phrase parfaitement équilibrée
La symétrie rythmique (« ni trop court, ni trop long, toujours en trois temps »)
est un tic de machine. Casse le rythme. Fais des phrases courtes. Puis une longue.

### 4. Le « Ce n'est pas X, c'est Y »
- ❌ « Ce n'est pas une remise, c'est un partenariat. »
- ✅ « C'est un partenariat, pas une remise. »  (ou simplement : dis le truc)

### 5. Emoji + gras systématiques
Un emoji par écran suffit. Le gras sert à souligner UN mot, pas trois par phrase.

### 6. Le vocabulaire corporate
- ❌ « optimiser », « au cœur de », « à 360° », « expérience utilisateur »,
  « solution », « accompagner », « écosystème » (dans l'UI — en interne OK)
- ✅ le mot simple. Un club dit « la secrétaire gagne du temps », pas
  « optimisation du parcours administratif ».

---

## Le test

Relis la phrase à voix haute. **Est-ce qu'un dirigeant de club la dirait à un
parent, au bord du terrain, un samedi matin ?**
Si non → réécris.

## Le public

- **Les joueuses** : 10 à 20 ans. Tutoiement. Phrases courtes. Direct.
- **Le staff / les dirigeants** : bénévoles, souvent peu à l'aise avec l'informatique.
  Zéro jargon. On leur parle de leur métier (licences, table de marque), pas du nôtre
  (« entités », « synchronisation »).
- **Les présidents de club (vente)** : sérieux, concret, chiffré. Pas de superlatifs.

---

## État du chantier (12/07/2026)

- ✅ Page de connexion joueuse réécrite (le modèle à suivre)
- ✅ 36 titres de pages nettoyés (« X — Venaball » → « X »)
- ⏳ **622 tirets encore visibles** dans les templates. Passe rédactionnelle à
  faire par lots, par ordre de visibilité :
  1. Espace joueuse (`templates/pirb/`) — c'est ce que voient les gamines
  2. Vitrine (`templates/vitrine/`) — c'est ce que voient les parents et les prospects
  3. Manager (`templates/manager/`) — c'est ce que voit le staff

> ⚠️ **Ne JAMAIS faire de `sed` global sur le `—`** : il sert aussi de séparateur
> de données légitime (Stats Live, listes). Ça se fait à la main, page par page.
