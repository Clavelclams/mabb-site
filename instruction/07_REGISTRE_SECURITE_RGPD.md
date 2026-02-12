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
