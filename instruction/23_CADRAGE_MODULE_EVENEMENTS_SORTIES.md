# 23 — Cadrage : Événements payants, sorties & tableaux de bord

> Date : 2026-07-07
> Statut : **cadrage validé sur les 3 décisions clés, en attente de GO pour coder**
> Objet : gérer depuis le Manager les sorties (plage, structures gonflables…) avec
> inscriptions, autorisations parentales et suivi des paiements, plus les tableaux
> de bord. Remplace la gestion actuelle sur Google Sheet + Drive.

---

## 1. Ce qui existe déjà (à réutiliser)

- Entité `Evenement` (src/Entity/Sport/Evenement.php) : titre, description, `type`
  (dont `TYPE_SORTIE`), `statut` (brouillon/publié/annulé), `date`, `dateFin`, `lieu`,
  `ouvertA` (**TOUS / JOUEURS / BENEVOLES / STAFF**), `inscriptionsMax`, `club`, `createur`.
- Entité `EvenementParticipation` : participation d'un **User** (inscrit/présent/absent/
  excusé), déclenche la gamification (missions + badges).
- CRUD complet dans `EvenementController` (créer, modifier, publier, annuler, inscrire,
  marquer présent).

**On garde `EvenementParticipation` tel quel** pour les événements de membres (réunions,
AG, tournois internes) : elle est liée à un User obligatoire et gamifiée. On **n'y touche pas**.

---

## 2. Périmètre : 2 briques distinctes

Ne pas confondre deux réalités très différentes :

| Brique | Nature | Ce chantier ? |
|---|---|---|
| **Sorties payantes** (Fort-Mahon, St-Quentin, structures gonflables) | Participants identifiés, autorisation parentale, paiement | ✅ **Oui, focus** |
| **Animations city-stade** (Sud-Est, Nord, Étouvie) | Comptages **anonymes** (filles/garçons par jour/lieu) | ⏸️ Brique séparée (voir §7) |

Ce document cadre **les sorties**. Les animations = un modèle simple à part, traité après.

---

## 3. Décisions verrouillées (Clavel, 07/07)

1. **Participants pilotés par `ouvertA`** : si l'événement est *ouvert à TOUS* → l'inscription
   autorise la **saisie libre** (nom/prénom) pour les non-licenciés **ou** le rattachement à
   une fiche joueuse. Si *club (JOUEURS/BENEVOLES/STAFF)* → **licenciés uniquement** (sélection
   d'une fiche joueuse, pas de saisie libre).
2. **Autorisation v1 = case « reçue »** cochée par le coach quand il a le papier en main.
   V2 prévue : upload de la décharge signée / signature électronique (le champ fichier est
   déjà créé en base, juste non utilisé en v1).
3. **Paiement = suivi uniquement** (aucun encaissement en ligne) : statut + montant + moyen
   + date. Le paiement CB en ligne (Stripe/HelloAsso) = chantier séparé, plus tard.

---

## 4. Modèle de données

### 4.1 Champs ajoutés à `Evenement` (migration)

| Champ | Type | Défaut | Rôle |
|---|---|---|---|
| `estPayant` | bool | `false` | L'événement a-t-il un tarif ? |
| `prix` | decimal(6,2) nullable | `null` | Tarif par participant si payant |
| `autorisationRequise` | bool | `false` | Faut-il une autorisation parentale ? |

### 4.2 Nouvelle entité `InscriptionSortie`

Une ligne = un participant à une sortie (comme une ligne de ton Sheet). Porte identité +
autorisation + paiement + présence.

| Champ | Type | Notes |
|---|---|---|
| `id` | int | |
| `evenement` | FK Evenement (NOT NULL, onDelete CASCADE) | |
| `joueur` | FK Joueur **nullable** | rempli si licencié |
| `nom` | string(80) nullable | rempli si non-licencié (saisie libre) |
| `prenom` | string(80) nullable | idem |
| `dateNaissance` | date nullable | pour connaître l'âge / savoir si mineur |
| `responsableLegal` | string(120) nullable | nom du parent (obligatoire si mineur) |
| `telephoneContact` | string(30) nullable | contact parent |
| `autorisationStatut` | string(20) | `NON_REQUISE` \| `EN_ATTENTE` \| `RECUE` |
| `autorisationFichier` | string(255) nullable | **v2** : chemin de la décharge signée |
| `paiementStatut` | string(20) | `GRATUIT` \| `A_PAYER` \| `PAYE` \| `EXONERE` \| `REMBOURSE` |
| `montantPaye` | decimal(6,2) nullable | |
| `moyenPaiement` | string(20) nullable | `ESPECE` \| `CHEQUE` \| `VIREMENT` \| `AUTRE` |
| `paiementDate` | date nullable | |
| `presence` | string(20) | `INSCRIT` \| `PRESENT` \| `ABSENT` |
| `commentaire` | text nullable | |
| `createdBy` | FK User | le staff qui a inscrit |
| `createdAt` | datetime_immutable | |

**Règle d'intégrité** : soit `joueur` est renseigné, soit (`nom` + `prenom`) le sont. Un
helper `getNomAffichage()` renvoie le nom de la fiche joueuse OU la saisie libre.

**Pourquoi une entité séparée et pas `EvenementParticipation`** : cette dernière exige un
User (contrainte d'unicité + gamification). Y mêler des non-licenciés casserait les deux.
Séparation propre : `EvenementParticipation` = membres gamifiés, `InscriptionSortie` = sorties.

### 4.3 Cohérences dérivées (règles produit)

- `evenement.estPayant == false` → toutes les inscriptions ont `paiementStatut = GRATUIT`,
  section paiement masquée.
- `evenement.autorisationRequise == false` → `autorisationStatut = NON_REQUISE`, masqué.
- À l'inscription à un événement payant → `paiementStatut = A_PAYER`, montant pré-rempli au
  `prix` de l'événement.

---

## 5. Routes & écrans (Manager, CLUB_STAFF requis)

- `GET  /evenements/{id}` (existant) → enrichi : onglet **Inscriptions** avec le tableau.
- `POST /evenements/{id}/inscriptions` → ajouter un participant (licencié ou libre selon `ouvertA`).
- `POST /evenements/{id}/inscriptions/{iid}/autorisation` → basculer EN_ATTENTE ↔ RECUE.
- `POST /evenements/{id}/inscriptions/{iid}/paiement` → enregistrer paiement (montant/moyen/date/statut).
- `POST /evenements/{id}/inscriptions/{iid}/presence` → INSCRIT/PRESENT/ABSENT.
- `DELETE /evenements/{id}/inscriptions/{iid}` → retirer un inscrit.

**Sécurité** : CSRF sur tous les POST/DELETE, vérif `evenement.club == club actif`
(multi-tenant), `denyAccessUnlessGranted(CLUB_STAFF)`.

---

## 6. Tableaux de bord

### 6.1 Dashboard d'un événement (dans `/evenements/{id}`)
Agrégats en tête : `X inscrits / places max`, `Y autorisations reçues (Z manquantes)`,
`N payés = total € encaissé`, `M à payer`, `présents le jour J`. Puis le tableau détaillé.

### 6.2 Dashboard global des sorties (sur une saison)
Nombre de sorties, total participants, total encaissé, répartition par type. Filtré par la
**saison active** (même mécanique que Stats Live : `SaisonService::getSaisonActive()`).

---

## 7. Hors périmètre immédiat (à cadrer ensuite)

- **Animations city-stade** (fréquentation anonyme) : entité `AnimationJournee` (date, lieu,
  secteur, animateurs, nbFilles, nbGarcons, tranches d'âge, débrief, photo) + dashboard qui
  reproduit ton onglet « Tableau de Bord » (totaux filles/garçons par secteur). Sert surtout
  aux bilans d'impact / subventions.
- **Encaissement en ligne** (CB) : intégration prestataire, plus tard.
- **Autorisation v2** : upload décharge / signature électronique.

---

## 8. RGPD (⚠️ à ne pas négliger — données de mineurs)

Ce module stocke : nom, date de naissance, coordonnées du responsable légal de **mineurs**,
et à terme des **décharges signées**. Obligations :

- Accès **staff uniquement** (jamais exposé côté PIRB/public).
- `autorisationFichier` (v2) → stocké **hors de `public/`** (uploads privés servis par un
  contrôleur protégé), jamais en accès direct.
- **Durée de conservation** : purge des inscriptions de sortie après la saison (ex. commande
  console annuelle) — à définir.
- Entrée dédiée à créer dans `07_REGISTRE_SECURITE_RGPD.md`.

---

## 9. Découpage proposé (tâche par tâche)

1. **Lot A** — Migration + entités : champs sur `Evenement` + entité `InscriptionSortie` +
   repository. (Fondations, aucune UI.)
2. **Lot B** — Inscription : formulaire (licencié vs libre selon `ouvertA`) + liste dans
   `/evenements/{id}` + actions autorisation/paiement/présence.
3. **Lot C** — Dashboards : agrégats événement + dashboard global saison.
4. **Lot D** — RGPD : entrée registre + (v2) upload décharge sécurisé + purge saison.

→ Décision d'architecture à acter dans `08_ADR.md` une fois le GO donné (ADR : « Sorties
gérées via Evenement + entité InscriptionSortie séparée de EvenementParticipation »).
