# Registre securite & RGPD

## Objectif
Centraliser les obligations de securite + RGPD (tracabilite, conservation, droits).

## Format
| Champ | Description |
|-------|-------------|
| ID | RGPD-XXXX |
| Categorie | Securite / RGPD / Les deux |
| Regle | Description de l'obligation |
| Implementation | Comment c'est implemente |
| Statut | a faire / en cours / ok |

---

### RGPD-0001 — Logs de connexion
- Categorie : Les deux
- Regle : Chaque connexion (succes/echec) doit etre loguee avec date, utilisateur, IP.
- Implementation : Table `logs_connexion` (cf. dictionnaire_db). EventSubscriber sur `security.authentication.success` et `security.authentication.failure`.
- Statut : a faire (Phase 6)

---

### RGPD-0002 — Audit actions sensibles
- Categorie : Les deux
- Regle : Toute modification de role, validation de match, suppression de document, modification de stats doit etre tracee.
- Implementation : Table d'audit (date, user_id, action, entite, ancien/nouveau). EventSubscriber Doctrine ou service AuditLogger.
- Statut : a faire (Phase 6)

---

### RGPD-0003 — Politique de conservation des donnees
- Categorie : RGPD
- Regle : Logs de connexion conserves 12 mois max. Comptes inactifs > 24 mois : anonymisation. Donnees personnelles supprimees sur demande (droit a l'oubli).
- Implementation : Commande Symfony planifiee (cron) pour purge automatique. Endpoint ou procedure pour demande de suppression.
- Statut : a faire (Phase 6)

---

### RGPD-0004 — Protection des donnees mineurs
- Categorie : RGPD
- Regle : Les donnees des joueurs mineurs ne sont accessibles qu'au coach de l'equipe et au(x) parent(s) rattache(s). Aucune donnee mineur ne doit etre exposee publiquement.
- Implementation : Voter verifiant l'age + lien parental. Champ date_naissance sur joueur. Relation parent-joueur dans la DB.
- Statut : a faire (Phase 4 PIRB)

---

### RGPD-0005 — Anonymat feedback entrainement
- Categorie : RGPD
- Regle : Les feedbacks d'entrainement deposes par les joueurs sont anonymes vis-a-vis du coach. Le coach voit les retours mais pas l'auteur.
- Implementation : Table feedback sans FK vers joueur visible par le coach. Stockage auteur uniquement pour admin/super-admin (si besoin de moderation).
- Statut : a faire (Phase 4 PIRB)

---

### RGPD-0006 — Consentement explicite a l'inscription
- Categorie : RGPD
- Regle : L'inscription requiert un consentement explicite (case a cocher non pre-cochee) pour les CGU et la politique de confidentialite. Le consentement doit etre horodate et stocke. Cf. CDC section 5.1.1 et 8.3.
- Implementation : Champs `cgu_accepted_at` et `privacy_accepted_at` (datetime) sur User. Case checkbox obligatoire dans le formulaire d'inscription. Refus = inscription impossible.
- Statut : a faire (Phase 1)

---

### RGPD-0007 — Droit d'acces et portabilite (export)
- Categorie : RGPD
- Regle : Tout utilisateur peut demander l'export de ses donnees personnelles dans un format structure (JSON ou PDF). Cf. CDC section 8.3.
- Implementation : Endpoint ou commande Symfony generant un export JSON/PDF des donnees personnelles de l'utilisateur (profil, presences, stats, feedbacks). Accessible via le profil utilisateur.
- Statut : a faire (Phase 6)

---

### RGPD-0008 — Droit a l'effacement et anonymisation
- Categorie : RGPD
- Regle : Tout utilisateur peut demander la suppression de son compte. Les donnees personnelles sont supprimees ou anonymisees. Les donnees sportives (stats, presences) sont anonymisees plutot que supprimees pour preserver l'integrite historique. Cf. CDC section 8.3.
- Implementation : Commande ou service d'anonymisation : remplacer nom/prenom/email par des valeurs generiques, conserver les stats avec player_id anonymise. Soft delete du compte.
- Statut : a faire (Phase 6)

---

### SEC-0009 — IDOR inscription bénévole PIRB (corrigé) + état de l'isolation PIRB
- Categorie : Securite
- Date : 2026-07-06
- Constat (audit route par route des controleurs PIRB) : la plupart des routes `{id}` verifient l'appartenance (equipe/club/proprietaire). UNE faille reelle trouvee : `PirbRencontreController::sInscrire` acceptait n'importe quel id de rencontre — une joueuse du club A pouvait s'inscrire benevole sur un match du club B.
- Correction : verification `joueur.club === rencontre.club` avant toute inscription (AccessDenied sinon).
- Reste ouvert : l'isolation PIRB reste basee sur `Joueur.user` + verifs par route (pattern "V1 mono-club"). La refonte structurelle (tenant par claim JWT) est actée par ADR-0007 et arrivera avec le chantier B4. D'ici la, TOUTE nouvelle route PIRB avec un parametre d'entite DOIT verifier l'appartenance club/equipe/proprietaire.
- Statut : corrige (faille) / regle permanente (nouvelles routes)

---

### RGPD-0009 — Pages legales publiees (LCEN + RGPD)
- Categorie : RGPD
- Date : 2026-07-06
- Regle : mentions legales et politique de confidentialite accessibles depuis le footer de la vitrine (/mentions-legales, /politique-confidentialite). Contenu variable (adresse, president, contact) editable via le CMS (/admin/contenus) — cle prefixe `legal.*`.
- Points couverts : editeur/hebergeur, donnees collectees et finalites, cookies techniques uniquement (pas de bandeau requis), durees, droits RGPD (renvoi vers l'export/effacement integres au profil), mineures, recours CNIL.
- Statut : fait — a relire par le bureau (adresse siege et nom du president a completer dans Admin → Contenus)

---

### RGPD-0010 — Inscriptions sorties : donnees de mineures + purge fin de saison (Lot D)
- Categorie : RGPD
- Date : 2026-07-09
- Traitement : gestion des inscriptions aux sorties du club (entite `InscriptionSortie`, table `sport_inscription_sortie`). Donnees : identite (nom, prenom, date de naissance), responsable legal, telephone de contact, suivi autorisation parentale (+ chemin de decharge signee en v2), suivi paiement (statut, montant, moyen, date), presence, commentaire. Concerne des MINEURES, y compris des non-licenciees exterieures au club.
- Base legale : execution de la relation adherent/participant (organisation de la sortie). Finalite eteinte a la fin de la saison.
- Acces : CLUB_STAFF uniquement (Voter `ClubVoter` via `ClubAwareInterface`), jamais expose cote PIRB/public (cf. doc 23 §8, ADR-0011).
- Conservation / minimisation : ANONYMISATION en fin de saison — commande `app:sorties:purger-rgpd` (dry-run par defaut, `--execute` pour appliquer ; ne touche jamais la saison en cours). L'identite de saisie libre, le responsable legal, le telephone, le commentaire et la reference de decharge sont effaces ; la ligne est conservee (presence, paiement) pour garder des agregats exacts (dashboard, bilans). Le lien vers la fiche `Joueur` (licenciee) est conserve : cycle de vie couvert par RGPD-0008.
- A planifier : cron annuel (ex. 15 juillet) — meme mecanique que RGPD-0003.
- Decharge signee v2 (09/07/2026) : upload/consultation/suppression via `DechargeSortieUploader` — stockage `var/decharges/{clubId}/` HORS `public/` (jamais servi en direct), lecture uniquement via controleur (`BinaryFileResponse`) derriere `ClubVoter::CLUB_STAFF`, CSRF sur upload/suppression, MIME whitelist (PDF/JPG/PNG/WEBP, 10 Mo). La purge supprime aussi le fichier physique.
- Statut : fait (09/07/2026) — reste : cron annuel a poser sur OVH (ex. 15 juillet)

---

### RGPD-0011 — Secretariat : dossiers licences + contacts responsables legaux
- Categorie : RGPD
- Date : 2026-07-09
- Traitement 1 : `DossierLicence` (table `sport_dossier_licence`) — suivi administratif des licences par saison : identite (nom, date de naissance), telephone, n° licence FFBB, tarif, aides sociales (Mairie/PASS/cheques colleges — donnees potentiellement sensibles socialement), statut de paiement, suivi de relance. Source : import des Excel de la secretaire. Base legale : gestion de l'adhesion.
- Traitement 2 : `ResponsableLegal` (table `sport_responsable_legal`) — carnet d'adresses des parents/responsables (nom, telephones, email, adresse) rattache a la fiche Joueur (mineures). Source : formulaire licence (Google Form). ≠ `ParentJoueur` (comptes connectes PIRB).
- Acces : `ClubVoter::CLUB_SECRETARIAT` UNIQUEMENT (DIRIGEANT + nouveau role SECRETAIRE). Pas COACH/STAFF, jamais PIRB/public. CSRF sur toutes les ecritures. Multi-tenant via ClubAwareInterface.
- Conservation : dossiers licences conserves par saison (historique administratif legitime) ; contacts responsables suivent le cycle de vie de la fiche joueuse (purge RGPD-0008). A REVOIR au premier bilan : duree de conservation des aides sociales (minimisation possible en N+2).
- Statut : fait (09/07/2026) — migration `Version20260709210000` a passer en prod ; duree de conservation des aides a arbitrer
