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
