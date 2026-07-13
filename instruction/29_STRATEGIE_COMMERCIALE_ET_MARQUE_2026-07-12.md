# 29 — Stratégie commerciale, marque & apps — 12/07/2026

> **Statut : décisions arrêtées avec Clavel (et Willy pour le prix).**
> Ce document complète le CDC. Il ne remplace rien : il **tranche** les points
> laissés ouverts (monétisation, marque, domaines, périmètre des apps).
> À lire avant toute décision produit ou commerciale.

---

## 1. Marque — décisions

| Élément | Nom | Rôle |
|---|---|---|
| **Marque / produit** | **Venaball** | L'écosystème complet. Édité par **VENA** (SASU). |
| **App store #1** | **Venaball** | **App joueuse.** Stats, progression, badges, playground caméra. |
| **App store #2** | **Venaball Club** | **App staff.** Coach, parent, bénévole/OTM. |
| **Webapp club** | **Venaball Manager** | L'administratif lourd, au navigateur. |
| **Baseline** | *« Le manager de votre association de basket-ball. »* | Idée de Clavel — excellente, gardée comme **description**. |

### Pourquoi PAS « MABB »
- **MABB est un CLUB** (1er des 330 en Hauts-de-France). Vendre un logiciel nommé
  MABB à un club rival est intenable : « pourquoi mes données seraient chez le MABB ? »
- Ça **re-scelle la confusion juridique** qu'on doit casser : aujourd'hui tout le
  juridique dit « l'association MABB est responsable de traitement », alors que le
  **vendeur doit être VENA**.
- Un nom qu'il faut expliquer (« MABB = Manager Association Basket Ball ») est un nom raté.

### RÈGLE : ne PAS renommer le code
`PirbApiController`, namespace `App\Controller\Pirb`, dossier `Pirb store`, templates
`pirb/` : **on ne touche à rien.** Personne ne le voit. Un refacto de nommage sur
70 contrôleurs = des heures de risque pour zéro valeur.
**On ne renomme que ce que l'utilisateur voit** : nom dans les stores, titres de pages,
textes, domaines.

> Note : Pierre (PIRB Scouting) ne veut plus qu'on utilise « PIRB ». L'existant en prod
> peut rester (il l'a dit). **On ne crée aucun nouveau PIRB.**

---

## 2. Architecture des domaines

**Principe : on n'a qu'UNE base de données.** Le multi-tenant est par `club_id`
(ClubVoter + TenantResolver). Un domaine n'est pas un coffre-fort, c'est **une porte**.
Deux domaines = deux portes vers la même maison. **Rien à migrer.**

| Aujourd'hui | Demain | Pour qui |
|---|---|---|
| `mabb.fr` | **inchangé** | Vitrine du **club MABB** (= premier client) |
| `manager.mabb.fr` | **inchangé** | Porte du MABB uniquement |
| `pirb.mabb.fr` | **inchangé** (existant, Pierre OK) | Espace joueuses MABB |
| — | **`venaball.fr`** | **Site commercial** (vendre aux clubs) — *n'existe pas encore* |
| — | **`manager.venaball.fr`** | Le Manager, pour **tous les autres clubs** |
| — | **`app.venaball.fr`** | Espace joueuses web (remplace le concept `pirb.`) |

### Travaux techniques (léger)
Le domaine est **déjà paramétré** (`%app.host_domain%`, firewalls en regex d'hôte).
Ajouter un domaine =
- étendre la regex : `host: '^manager\.(mabb\.fr|venaball\.fr|localhost)…'` (par firewall),
- DNS + SSL (gratuit OVH).
**≈ une demi-journée.**

### ⚠️ À corriger : création de club depuis manager.mabb.fr
La route `/creer-un-club` est sur le host manager → **un inconnu peut créer son club
depuis le domaine du MABB.** À corriger : sur `mabb.fr`, `/creer-un-club` **redirige**
vers `manager.venaball.fr`. La création publique vit sur **venaball.fr**.

---

## 3. Grille tarifaire (arrêtée)

Prix standard **validé par Willy** (un président de club, donc l'acheteur) : **79 €**.

| Offre | Places | Prix HT/mois | Règle |
|---|---|---|---|
| **Découverte** | illimité | **Gratuit** | 1 équipe. Personne n'est exclu. |
| **Club fondateur** (MABB) | **1** | **Gratuit à vie** | Partenariat de co-conception, pas une remise. |
| **Pionnier** | **5** | **19 €** | Bloqué à vie. **Contrepartie contractuelle** (charte). |
| **Précurseur** | **10** | **39 €** | Bloqué à vie. |
| **Club (standard)** | ∞ | **79 €** | **790 €/an** = 2 mois offerts. |

### Les 4 règles non négociables
1. **Les quotas sont SACRÉS** (1 / 5 / 10). Le jour où le 17ᵉ club obtient une remise,
   **le prix devient 39 € pour toujours**. Afficher un compteur de places (crée l'urgence
   ET rend le plafond crédible).
2. **La remise se MÉRITE** (cf. charte) : point mensuel, retours, témoignage, être référence.
   Si le club décroche → retour au tarif standard.
3. **Le tarif remisé n'apparaît JAMAIS en public.** Grille publique = Découverte + 79 €.
   Le 19 €/39 € vit dans un contrat privé signé.
4. **« À vie » = tant que l'abonnement est continu.** Résiliation puis retour = tarif du moment.

### Projection honnête
- Saison 1 (MABB + 3 pionniers) : ~57 €/mois. **Ce n'est pas un revenu, c'est une preuve.**
- Saison 2 : ~300-400 €/mois.
- Saison 3 (16 promo remplis + 10-15 standard) : **20-25 k€/an**.
→ **Projet à 3 ans.** Le vrai levier n'est pas le porte-à-porte : c'est **le Comité / la Ligue**
(330 clubs d'un coup). Willy est la porte d'entrée.

---

## 4. Programme bêta

- **2 à 3 clubs**, pas plus. Clavel est seul, en alternance, jury CDA avril 2027.
- **Onboarding en AOÛT**, pas en septembre (en septembre les clubs sont noyés :
  licences, équipes, plannings). Septembre devient la **preuve**, pas l'apprentissage.
- **Bêta gratuite septembre → 31 décembre 2026.** Bascule payante **au 1er janvier 2027**.
- **Annoncer le prix (79 €) dès le premier contact**, même pendant la bêta gratuite :
  ça fait la découverte de prix ET ça filtre les non-sérieux.
- **Charte signée** (document produit le 12/07) : référent unique, point mensuel de 30 min,
  bugs sur canal unique, témoignage écrit, accepter d'être référence (2×/an max).

---

## 5. 🔴 CE QUI BLOQUE LA VENTE (chemin critique réel)

**Ce n'est pas une feature. C'est le juridique.**

Aujourd'hui : politique de confidentialité et CGU disent « **l'association MABB est
responsable de traitement** ». VENA n'apparaît que dans une ligne des mentions légales.

Dès qu'un **club tiers** te confie ses données :
- **lui** = responsable de traitement,
- **VENA** = **sous-traitant** (RGPD art. 28),
- → un **DPA (contrat de sous-traitance) est OBLIGATOIRE**.

### Manquants, tous bloquants
| Document | État | Criticité |
|---|---|---|
| **DPA (art. 28 RGPD)** | ❌ inexistant | 🔴 **Vendre sans = illégal** |
| **CGS / CGV** | ❌ inexistant (on n'a que des CGU gratuites) | 🔴 |
| **AIPD / DPIA** | ❌ inexistante | 🔴 probablement obligatoire : **mineures + caméra + social + données sociales** (aides Mairie/PASS/chèques collège, cf. RGPD-0011) |
| **VENA comme éditeur** | ❌ tout est au nom de MABB | 🔴 |
| **Politique de confidentialité de l'app (URL publique)** | ❌ | 🔴 exigée par Apple/Google |

**Budget à prévoir : 500-1500 € de juriste.** C'est le meilleur investissement du projet :
il débloque **toute** la commercialisation.

---

## 6. Les deux apps — périmètre et ORDRE

**Décision de Clavel (product owner) : DEUX apps, pas une.** Argumentaire retenu :
deux audiences qui n'ont rien à voir (une joueuse de 12 ans / un président), deux
positionnements store, ne pas diluer l'app joueuse. **Pattern Uber / Uber Driver.**

### Venaball (app joueuse) — **PRIORITÉ 1**
Stats, progression, badges, calendrier, **playground caméra** (dribble + tir auto).
→ **C'est la définition de « terminé »** (doc 26 : *« la joueuse a l'app via TestFlight »*).

### Venaball Club (app staff) — **PRIORITÉ 2**
| Rôle | Ce qu'il fait |
|---|---|
| **Coach** | Effectif, convocations, présences, **Stats Live au bord du terrain** |
| **Parent** | Planning de son enfant, réponse aux convocations, infos club |
| **Bénévole / OTM** | Sa mission du week-end : table, buvette, e-Marque |

### Règles d'exécution
- **PAS de WebView du Manager dans un store** → rejet Apple garanti (Guideline 4.2,
  « web wrapper » — déjà signalé comme risque dans l'audit store).
- **Package partagé** (API, auth, types, thème, composants). La 2ᵉ app coûte alors
  **~40 %** d'une app, pas 100 %. La couche data abstraite (`useAsyncData`, service
  Mock/Api) existe déjà → réutilisable.
- **SÉQUENTIEL, jamais en parallèle.** Venaball (joueuse) d'abord → store.
  Venaball Club ensuite, construite **pendant** la bêta clubs.
  *Lancer les deux de front = ne livrer ni l'une ni l'autre.*

### Reste sur le WEB (boucle de bureau)
Secrétariat & licences · Trésorerie · ENT & documents · Imports FFBB ·
Planification du week-end · Paramètres du club · Super-admin.

---

## 7. Ce qui manque encore (hors juridique)

1. **`venaball.fr` : le site commercial.** ❌ **Il n'existe pas.** Aujourd'hui aucun
   président ne peut découvrir le produit, voir la grille, demander une démo.
   `mabb.fr` est le site d'un **club**, pas d'un **produit**. → À créer.
2. **Onboarding multi-club** : gelé (doc 26). À dégeler avant les clubs bêta.
3. **Audit d'isolation multi-tenant** : indispensable avant d'accueillir des clubs tiers.
   Une fuite entre clubs sur des données de mineures = fin de VENA.
4. **Billing** : **NE PAS construire Stripe.** Pour 3 clubs → **facturation manuelle**
   depuis la SASU. Le billing se construit à 10+ clubs.
5. Le champ `Club.plan` est une **coquille vide** (grep = 0 usage). Aucun paywall,
   aucun `ModuleVoter`. À brancher **le jour où** on limite par plan — pas avant.

---

## 8. Séquence recommandée

| Quand | Quoi |
|---|---|
| **Maintenant** | Acheter **`venaball.fr`** (~10 €). Conformité store de l'app joueuse. |
| **Juillet–août** | **Juridique** (DPA, CGS, AIPD, VENA éditeur). Dégeler l'onboarding multi-club + audit d'isolation. Site commercial `venaball.fr`. |
| **Août** | Recruter **2-3 clubs pionniers** (via Willy / comité). Charte + LOI signées. Onboarding. |
| **Septembre** | MABB + pionniers font leur rentrée sur l'outil. **App joueuse en TestFlight → store.** |
| **Sept → déc** | Bêta. Points mensuels. Mesurer : la secrétaire gagne-t-elle vraiment du temps ? |
| **Janvier 2027** | **Bascule payante** (19 € pionniers). S'ils ne convertissent pas → info la plus précieuse du projet. |
| **2027** | Venaball Club (app staff). Viser le **Comité / la Ligue**. |

---

## 9. Points encore ouverts

- [ ] Achat de `venaball.fr` (à faire **ce soir**, avant que quelqu'un le prenne)
- [ ] Choix du juriste / budget juridique
- [ ] Identifier les 2-3 clubs pionniers (liste de noms via Willy)
- [ ] Témoignage écrit de Willy + chiffres MABB (heures gagnées, licences gérées)
- [ ] Décider si un club peut avoir son propre domaine (`manager.sonclub.fr`) — **argument de vente**

---

## 10. Documents produits le 12/07/2026

- `Venaball_Grille_Tarifaire.pdf` — la page à poser devant un président.
- `Venaball_Charte_Club_Pionnier.docx` — la charte à signer (9 articles, contrepartie de la remise).

> ⚠️ Ces documents sont des **bases de travail**, pas des actes validés.
> **À faire relire par un juriste** avant toute signature.
