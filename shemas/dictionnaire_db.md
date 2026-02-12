# Dictionnaire base de données application Manager Association Basket Ball et PIRB Scouting

---

## TABLES AUTHENTIFICATION & UTILISATEURS

### Table : utilisateurs

| Champ              | Type        | Contrainte                                | Description                           |
|--------------------|------------|-------------------------------------------|---------------------------------------|
| id_utilisateur     | INT (PK)   | AUTO_INCREMENT, UNIQUE, NOT NULL          | Identifiant unique de l'utilisateur   |
| nom                | VARCHAR(100) | NOT NULL                                | Nom de famille                        |
| prenom             | VARCHAR(100) | NOT NULL                                | Prénom                                |
| adresse_email      | VARCHAR(255) | UNIQUE, NOT NULL                        | Adresse email de connexion            |
| mot_de_passe       | VARCHAR(255) | NOT NULL                                | Mot de passe chiffré (bcrypt/argon2)  |
| telephone          | VARCHAR(20) | NULLABLE                                 | Numéro de téléphone                   |
| date_naissance     | DATE       | NULLABLE                                  | Date de naissance (utile PIRB)        |
| photo_profil       | TEXT (URL) | NULLABLE                                  | Lien vers la photo de profil          |
| statut             | ENUM('actif','inactif','en_attente','suspendu') | NOT NULL DEFAULT 'en_attente' | État du compte |
| role_principal     | INT (FK → roles.id_role) | NULLABLE       | Rôle principal affiché (accélère l'affichage front) |
| consentement_rgpd  | BOOLEAN    | DEFAULT FALSE                             | Consentement RGPD validé              |
| consentement_image | BOOLEAN    | DEFAULT FALSE                             | Autorisation utilisation image        |
| consentement_date  | DATETIME   | NULLABLE                                  | Date du consentement                  |
| double_auth_actif  | BOOLEAN    | DEFAULT FALSE                             | Authentification à 2 facteurs activée |
| created_at         | DATETIME   | DEFAULT CURRENT_TIMESTAMP                 | Date de création du compte            |
| updated_at         | DATETIME   | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière modification |
| last_login         | DATETIME   | NULLABLE                                  | Dernière connexion                    |
| deleted_at         | DATETIME   | NULLABLE                                  | Soft delete (NULL = actif)            |

**Index recommandés :**
- INDEX idx_email ON utilisateurs(adresse_email)
- INDEX idx_statut ON utilisateurs(statut)
- INDEX idx_deleted ON utilisateurs(deleted_at)

**NOTE CDC :** Ajout champs RGPD (consentement_rgpd, consentement_image, consentement_date) + double_auth_actif pour sécurité dirigeants + soft delete.

---

### Table : roles

| Champ        | Type        | Contrainte                       | Description |
|--------------|-------------|----------------------------------|-------------|
| id_role      | INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique du rôle |
| nom_role     | VARCHAR(50) | NOT NULL, UNIQUE                 | Nom du rôle (ex : benevole, coach, dirigeant, joueur, parent) |
| description  | TEXT        | NULLABLE                         | Brève explication du rôle et de ses permissions |
| niveau_acces | TINYINT     | NOT NULL DEFAULT 1               | Niveau hiérarchique (1=base, 5=admin) |
| created_at   | DATETIME    | DEFAULT CURRENT_TIMESTAMP        | Date de création |

**Exemple de contenu :**

| id_role | nom_role   | description | niveau_acces |
|---------|------------|-------------|--------------|
| 1       | joueur     | Joueur de basket, accès stats et planning | 1 |
| 2       | parent     | Parent d'un joueur mineur, gestion autorisations | 2 |
| 3       | benevole   | Peut participer aux événements, saisir des stats, obtenir des médailles | 2 |
| 4       | coach      | Peut créer des séances, valider des stats, gérer ses équipes | 3 |
| 5       | dirigeant  | Peut gérer les membres, créer événements et publications, attribuer rôles | 4 |
| 6       | employe    | Salarié du club, accès administratif | 4 |

**NOTE CDC :** Ajout rôles parent + employe + niveau_acces pour hiérarchie permissions.

---

### Table : sessions_utilisateurs

| Champ            | Type         | Contrainte                              | Description |
|------------------|--------------|----------------------------------------|-------------|
| id_session       | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique de la session |
| id_utilisateur   | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur ON DELETE CASCADE | Utilisateur concerné |
| token            | VARCHAR(512) | NOT NULL, UNIQUE                        | JWT ou refresh token |
| device_id        | VARCHAR(255) | NULLABLE                                | Identifiant appareil (pour push) |
| device_type      | ENUM('ios','android','web') | NULLABLE   | Type d'appareil |
| user_agent       | TEXT         | NULLABLE                                | Navigateur / appareil |
| ip_adresse       | VARCHAR(45)  | NULLABLE                                | IP utilisée |
| date_debut       | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Début session |
| date_fin         | DATETIME     | NULLABLE                                | Fin session / logout |
| actif            | BOOLEAN      | DEFAULT TRUE                            | Session active |
| created_at       | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date création |

**Index recommandés :**
- INDEX idx_token ON sessions_utilisateurs(token)
- INDEX idx_user_actif ON sessions_utilisateurs(id_utilisateur, actif)

**NOTE CDC :** Table ajoutée pour gestion sessions JWT, multi-devices, sécurité.

---

### Table : logs_connexion

| Champ            | Type         | Contrainte                       | Description |
|------------------|--------------|----------------------------------|-------------|
| id_log           | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique du log |
| id_utilisateur   | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur concerné |
| ip_adresse       | VARCHAR(45)  | NULLABLE                         | Adresse IP utilisée |
| user_agent       | TEXT         | NULLABLE                         | Navigateur / appareil |
| date_connexion   | DATETIME     | DEFAULT CURRENT_TIMESTAMP        | Date et heure de connexion |
| date_deconnexion | DATETIME     | NULLABLE                         | Date de fin de session |
| statut           | ENUM('reussie','echec','bloque') | NOT NULL | Résultat tentative connexion |

**Index recommandés :**
- INDEX idx_user_date ON logs_connexion(id_utilisateur, date_connexion)

**NOTE :** Historisation connexions/déconnexions (audit RGPD).

---

## TABLES PROFILS & DONNÉES PERSONNELLES

### Table : profils_joueurs

| Champ              | Type          | Contrainte                              | Description |
|--------------------|---------------|----------------------------------------|-------------|
| id_profil_joueur   | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique du profil joueur |
| id_utilisateur     | INT (FK)      | NOT NULL, UNIQUE, FK → utilisateurs.id_utilisateur | Référence utilisateur (1-to-1) |
| poste              | ENUM('meneur','arriere','ailier','ailier_fort','pivot','polyvalent') | NULLABLE | Poste principal |
| taille_cm          | INT           | NULLABLE, CHECK (taille_cm > 0)         | Taille en centimètres |
| poids_kg           | INT           | NULLABLE, CHECK (poids_kg > 0)          | Poids en kilogrammes |
| main_dominante     | ENUM('droite','gauche','ambidextre') | NULLABLE | Main dominante du joueur |
| numero_maillot     | VARCHAR(3)    | NULLABLE                                | Numéro maillot préféré |
| description        | TEXT          | NULLABLE                                | Présentation ou bio du joueur |
| profil_public      | BOOLEAN       | DEFAULT FALSE                           | Profil visible publiquement (PIRB) |
| carriere_resume    | TEXT          | NULLABLE                                | Historique carrière (PIRB) |
| created_at         | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date de création du profil joueur |
| updated_at         | DATETIME      | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- UNIQUE INDEX idx_user ON profils_joueurs(id_utilisateur)

**NOTE CDC :** Ajout profil_public (PIRB standalone), carriere_resume, numero_maillot.

---

### Table : liens_parent_enfant

| Champ              | Type        | Contrainte                              | Description |
|--------------------|-------------|-----------------------------------------|-------------|
| id_lien            | INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_parent          | INT (FK)    | NOT NULL, FK → utilisateurs.id_utilisateur | Parent / responsable légal |
| id_enfant          | INT (FK)    | NOT NULL, FK → utilisateurs.id_utilisateur | Enfant (joueur mineur) |
| type_lien          | ENUM('mere','pere','tuteur','autre') | NOT NULL | Type de lien |
| responsable_legal  | BOOLEAN     | DEFAULT TRUE                            | Parent principal ou non |
| autorise_notifications | BOOLEAN | DEFAULT TRUE                            | Accepte les notifs / autorisations |
| created_at         | DATETIME    | DEFAULT CURRENT_TIMESTAMP               | Date de création |

**Index recommandés :**
- INDEX idx_parent ON liens_parent_enfant(id_parent)
- INDEX idx_enfant ON liens_parent_enfant(id_enfant)

**CONSTRAINT :** UNIQUE (id_parent, id_enfant)

**NOTE CDC :** Gestion relations parent-enfant pour autorisations parentales.

---

## TABLES CLUBS & STRUCTURES

### Table : clubs_officiels

| Champ              | Type         | Contrainte                       | Description |
|--------------------|--------------|----------------------------------|-------------|
| id_club            | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant interne unique du club |
| code_ffbb          | VARCHAR(20)  | UNIQUE, NULLABLE                 | Identifiant officiel FFBB (n° groupement) |
| nom_club           | VARCHAR(150) | NOT NULL                         | Nom officiel du club (ex : MABB) |
| type_structure     | ENUM('club','comite','ligue','entente','cooperation') | NOT NULL | Type de structure |
| ville              | VARCHAR(100) | NULLABLE                         | Ville du club |
| departement        | VARCHAR(3)   | NULLABLE                         | Code département (ex : 80 pour Somme) |
| adresse            | TEXT         | NULLABLE                         | Adresse complète |
| adresse_salle      | TEXT         | NULLABLE                         | Adresse gymnase/salle principale |
| comite_parent      | VARCHAR(150) | NULLABLE                         | Comité auquel est rattaché le club |
| logo               | TEXT (URL)   | NULLABLE                         | Lien vers le logo officiel du club |
| statut             | ENUM('certifie','non_certifie','en_attente') | DEFAULT 'en_attente' | Statut vérification FFBB |
| ffbb_verifie       | BOOLEAN      | DEFAULT FALSE                    | Vérifié via extranet FFBB |
| date_verification  | DATETIME     | NULLABLE                         | Date vérification FFBB |
| created_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP        | Date création |
| updated_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- UNIQUE INDEX idx_code_ffbb ON clubs_officiels(code_ffbb) WHERE code_ffbb IS NOT NULL
- INDEX idx_type ON clubs_officiels(type_structure)

**NOTE CDC :** Ajout adresse, adresse_salle, ffbb_verifie, date_verification.

---

### Table : clubs_utilisateurs

| Champ              | Type        | Contrainte                                        | Description |
|--------------------|-------------|---------------------------------------------------|-------------|
| id_club_utilisateur| INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL                  | Identifiant unique de la ligne |
| id_utilisateur     | INT (FK)    | NOT NULL, FK → utilisateurs.id_utilisateur        | L'utilisateur concerné |
| id_club            | INT (FK)    | NOT NULL, FK → clubs_officiels.id_club            | Le club auquel est lié l'utilisateur |
| id_role            | INT (FK)    | NOT NULL, FK → roles.id_role                      | Le rôle de l'utilisateur dans ce club |
| statut             | ENUM('actif','en_attente','rejete','inactif') | NOT NULL DEFAULT 'en_attente' | Statut de la relation |
| date_adhesion      | DATETIME    | NULLABLE                                          | Date d'acceptation |
| date_demande       | DATETIME    | DEFAULT CURRENT_TIMESTAMP                         | Date de la demande |
| valide_par         | INT (FK)    | NULLABLE, FK → utilisateurs.id_utilisateur        | Dirigeant ayant validé |
| date_validation    | DATETIME    | NULLABLE                                          | Date de validation |
| date_fin           | DATETIME    | NULLABLE                                          | Date de fin (départ club) |
| created_at         | DATETIME    | DEFAULT CURRENT_TIMESTAMP                         | Date création |

**Index recommandés :**
- INDEX idx_user_club ON clubs_utilisateurs(id_utilisateur, id_club)
- INDEX idx_club_statut ON clubs_utilisateurs(id_club, statut)

**CONSTRAINT :** UNIQUE (id_utilisateur, id_club, id_role) WHERE statut = 'actif'

**NOTE CDC :** Ajout statut rejete + valide_par + date_validation + date_fin pour workflow validation.

---

## TABLES ÉQUIPES & CATÉGORIES

### Table : categories

| Champ          | Type         | Contrainte                       | Description |
|----------------|--------------|----------------------------------|-------------|
| id_categorie   | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| nom_categorie  | VARCHAR(50)  | NOT NULL, UNIQUE                 | Nom (U11, U13, U15, U17, U20, Senior) |
| age_min        | INT          | NULLABLE                         | Âge minimum |
| age_max        | INT          | NULLABLE                         | Âge maximum |
| code_ffbb      | VARCHAR(10)  | NULLABLE                         | Code FFBB officiel |
| genre          | ENUM('M','F','mixte') | NULLABLE            | Genre de la catégorie |
| created_at     | DATETIME     | DEFAULT CURRENT_TIMESTAMP        | Date création |

**NOTE CDC :** Table ajoutée pour référentiel catégories FFBB.

---

### Table : equipes

| Champ              | Type         | Contrainte                                        | Description |
|--------------------|--------------|---------------------------------------------------|-------------|
| id_equipe          | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL                  | Identifiant unique de l'équipe |
| id_club            | INT (FK)     | NOT NULL, FK → clubs_officiels.id_club            | Le club auquel appartient l'équipe |
| id_categorie       | INT (FK)     | NULLABLE, FK → categories.id_categorie            | Catégorie de l'équipe |
| nom_equipe         | VARCHAR(100) | NOT NULL                                          | Nom ou catégorie (ex : U15F, Seniors H) |
| saison             | VARCHAR(9)   | NOT NULL                                          | Saison sportive (ex : 2024-2025) |
| niveau             | VARCHAR(50)  | NULLABLE                                          | Niveau (ex : Régional 2, Départemental 1) |
| coach_principal    | INT (FK)     | NULLABLE, FK → utilisateurs.id_utilisateur        | Coach responsable de l'équipe |
| statut             | ENUM('active','archivee','brouillon') | NOT NULL DEFAULT 'active' | Statut de l'équipe |
| created_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP                         | Date de création de l'équipe |
| updated_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_club_saison ON equipes(id_club, saison)
- INDEX idx_statut ON equipes(statut)

**NOTE :** Ajout id_categorie (FK), statut brouillon.

---

### Table : equipes_joueurs

| Champ              | Type         | Contrainte                                        | Description |
|--------------------|--------------|---------------------------------------------------|-------------|
| id_equipe_joueur   | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL                  | Identifiant unique |
| id_equipe          | INT (FK)     | NOT NULL, FK → equipes.id_equipe                  | Équipe concernée |
| id_joueur          | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur        | Joueur concerné |
| numero_maillot     | VARCHAR(3)   | NULLABLE                                          | Numéro de maillot dans cette équipe |
| poste_prefere      | VARCHAR(50)  | NULLABLE                                          | Poste dans cette équipe |
| statut             | ENUM('titulaire','remplacant','blesse','suspendu','inactif') | NOT NULL DEFAULT 'titulaire' | Statut joueur |
| date_integration   | DATE         | DEFAULT CURRENT_DATE                              | Date d'arrivée dans l'équipe |
| date_sortie        | DATE         | NULLABLE                                          | Date de départ |
| created_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP                         | Date création |

**Index recommandés :**
- INDEX idx_equipe ON equipes_joueurs(id_equipe)
- INDEX idx_joueur ON equipes_joueurs(id_joueur)

**CONSTRAINT :** UNIQUE (id_equipe, id_joueur, date_integration)

**NOTE CDC :** Table ajoutée pour composition équipes + historique.

---

## TABLES PLANNING & ÉVÉNEMENTS

### Table : evenements

| Champ          | Type            | Contrainte                              | Description |
|----------------|----------------|-----------------------------------------|-------------|
| id_evenement   | INT (PK)       | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique de l'événement |
| id_club        | INT (FK)       | NOT NULL, FK → clubs_officiels.id_club  | Club organisateur |
| id_equipe      | INT (FK)       | NULLABLE, FK → equipes.id_equipe        | Équipe concernée (optionnel) |
| type_evenement | ENUM('match','entrainement','buvette','reunion','tournoi','stage','autre') | NOT NULL | Type d'événement |
| titre          | VARCHAR(255)   | NOT NULL                                | Nom ou titre de l'événement |
| description    | TEXT           | NULLABLE                                | Détails de l'événement |
| lieu           | VARCHAR(255)   | NULLABLE                                | Lieu où se déroule l'événement |
| date_debut     | DATETIME       | NOT NULL                                | Date et heure de début |
| date_fin       | DATETIME       | NULLABLE                                | Date et heure de fin |
| visibilite     | ENUM('public','club','prive','equipe') | DEFAULT 'club' | Niveau de visibilité |
| autorisation_parentale_requise | BOOLEAN | DEFAULT FALSE      | Autorisation obligatoire pour mineurs |
| public_vise_mineurs | BOOLEAN   | DEFAULT FALSE                           | Événement vise principalement des -18 |
| slots_benevoles | INT           | NULLABLE                                | Nombre de bénévoles requis |
| statut         | ENUM('planifie','en_cours','termine','annule') | NOT NULL DEFAULT 'planifie' | État événement |
| cree_par       | INT (FK)       | NOT NULL, FK → utilisateurs.id_utilisateur | Créateur événement |
| created_at     | DATETIME       | DEFAULT CURRENT_TIMESTAMP               | Date de création |
| updated_at     | DATETIME       | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_club_date ON evenements(id_club, date_debut)
- INDEX idx_type ON evenements(type_evenement)
- INDEX idx_statut ON evenements(statut)

**NOTE CDC :** Ajout visibilite equipe + slots_benevoles + statut en_cours.

---

### Table : matchs

| Champ              | Type         | Contrainte                                        | Description |
|--------------------|--------------|---------------------------------------------------|-------------|
| id_match           | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL                  | Identifiant unique du match |
| id_evenement       | INT (FK)     | NULLABLE, FK → evenements.id_evenement            | Lien vers événement parent (optionnel) |
| id_equipe          | INT (FK)     | NOT NULL, FK → equipes.id_equipe                  | Équipe concernée par le match |
| adversaire         | VARCHAR(150) | NOT NULL                                          | Nom du club ou de l'équipe adverse |
| id_club_adverse    | INT (FK)     | NULLABLE, FK → clubs_officiels.id_club            | Club adverse si dans la base |
| competition        | VARCHAR(100) | NULLABLE                                          | Nom de la compétition |
| id_competition_ffbb| VARCHAR(50)  | NULLABLE                                          | ID compétition FFBB |
| code_match_ffbb    | VARCHAR(50)  | NULLABLE                                          | Code match FFBB |
| date_match         | DATE         | NOT NULL                                          | Date du match |
| heure_match        | TIME         | NOT NULL                                          | Heure de début du match |
| lieu               | VARCHAR(200) | NULLABLE                                          | Lieu où se déroule la rencontre |
| domicile_exterieur | ENUM('domicile','exterieur','neutre') | NOT NULL | Localisation |
| type_match         | ENUM('championnat','coupe','tournoi','amical') | NOT NULL | Type de match |
| statut             | ENUM('prevu','en_cours','termine','reporte','annule') | NOT NULL DEFAULT 'prevu' | État du match |
| score_equipe       | INT          | NULLABLE                                          | Points marqués par l'équipe |
| score_adversaire   | INT          | NULLABLE                                          | Points marqués par l'adversaire |
| feuille_validee    | BOOLEAN      | DEFAULT FALSE                                     | Feuille de match validée par coach |
| valide_par         | INT (FK)     | NULLABLE, FK → utilisateurs.id_utilisateur        | Coach ayant validé |
| date_validation    | DATETIME     | NULLABLE                                          | Date de validation |
| emarque_importe    | BOOLEAN      | DEFAULT FALSE                                     | Stats e-Marque importées |
| created_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP                         | Date d'enregistrement du match |
| updated_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_equipe_date ON matchs(id_equipe, date_match)
- INDEX idx_statut ON matchs(statut)
- INDEX idx_code_ffbb ON matchs(code_match_ffbb)

**NOTE CDC :** Ajout id_club_adverse, competition, domicile_exterieur, feuille_validee, emarque_importe, code_match_ffbb.

---

### Table : convocations

| Champ            | Type         | Contrainte                              | Description |
|------------------|--------------|----------------------------------------|-------------|
| id_convocation   | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_evenement     | INT (FK)     | NOT NULL, FK → evenements.id_evenement  | Événement concerné |
| id_utilisateur   | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur convoqué |
| type_participant | ENUM('joueur','coach','benevole','arbitre','autre') | NOT NULL | Rôle attendu |
| statut_reponse   | ENUM('en_attente','present','absent','incertain') | DEFAULT 'en_attente' | Réponse utilisateur |
| date_envoi       | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date d'envoi convocation |
| date_reponse     | DATETIME     | NULLABLE                                | Date de réponse |
| rappel_envoye    | BOOLEAN      | DEFAULT FALSE                           | Rappel envoyé |
| date_rappel      | DATETIME     | NULLABLE                                | Date rappel |
| created_at       | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date création |

**Index recommandés :**
- INDEX idx_evenement ON convocations(id_evenement)
- INDEX idx_user_statut ON convocations(id_utilisateur, statut_reponse)

**CONSTRAINT :** UNIQUE (id_evenement, id_utilisateur)

**NOTE CDC :** Table ajoutée pour gestion convocations + réponses présence.

---

### Table : presences

| Champ            | Type         | Contrainte                              | Description |
|------------------|--------------|----------------------------------------|-------------|
| id_presence      | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_evenement     | INT (FK)     | NOT NULL, FK → evenements.id_evenement  | Événement concerné |
| id_utilisateur   | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur présent |
| statut_presence  | ENUM('present','absent','retard','excuse') | NOT NULL | Statut effectif |
| heure_arrivee    | TIME         | NULLABLE                                | Heure d'arrivée effective |
| heure_depart     | TIME         | NULLABLE                                | Heure de départ |
| commentaire      | TEXT         | NULLABLE                                | Commentaire coach/responsable |
| saisie_par       | INT (FK)     | NULLABLE, FK → utilisateurs.id_utilisateur | Qui a saisi la présence |
| created_at       | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date de saisie |

**Index recommandés :**
- INDEX idx_evenement ON presences(id_evenement)
- INDEX idx_user ON presences(id_utilisateur)

**CONSTRAINT :** UNIQUE (id_evenement, id_utilisateur)

**NOTE CDC :** Table ajoutée pour présences effectives (distinctes des convocations).

---

### Table : autorisations_parentales

| Champ                 | Type        | Contrainte                              | Description |
|-----------------------|-------------|-----------------------------------------|-------------|
| id_autorisation       | INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant |
| id_evenement          | INT (FK)    | NOT NULL, FK → evenements.id_evenement  | Événement concerné |
| id_enfant             | INT (FK)    | NOT NULL, FK → utilisateurs.id_utilisateur | Enfant |
| id_parent             | INT (FK)    | NOT NULL, FK → utilisateurs.id_utilisateur | Parent signataire |
| statut                | ENUM('en_attente','signe','refuse','expire') | NOT NULL DEFAULT 'en_attente' | État |
| date_envoi            | DATETIME    | NOT NULL DEFAULT CURRENT_TIMESTAMP      | Envoi de la demande |
| date_signature        | DATETIME    | NULLABLE                                | Signature effective |
| date_expiration       | DATETIME    | NULLABLE                                | Date limite |
| mode_signature        | ENUM('clic','pdf','manuel') | DEFAULT 'clic' | Mode de signature |
| url_document          | TEXT        | NULLABLE                                | Lien vers le doc (PDF) |
| commentaire_parent    | TEXT        | NULLABLE                                | Remarque du parent |
| commentaire_club      | TEXT        | NULLABLE                                | Remarque interne club |
| created_at            | DATETIME    | DEFAULT CURRENT_TIMESTAMP               | Date création |

**Index recommandés :**
- INDEX idx_evenement ON autorisations_parentales(id_evenement)
- INDEX idx_enfant ON autorisations_parentales(id_enfant)
- INDEX idx_statut ON autorisations_parentales(statut)

**NOTE CDC :** Table pour workflow autorisations parentales.

---

## TABLES MATCHS & STATISTIQUES

### Table : feuilles_match

| Champ              | Type         | Contrainte                              | Description |
|--------------------|--------------|----------------------------------------|-------------|
| id_feuille         | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_match           | INT (FK)     | NOT NULL, UNIQUE, FK → matchs.id_match  | Match concerné |
| id_equipe          | INT (FK)     | NOT NULL, FK → equipes.id_equipe        | Équipe concernée |
| statut             | ENUM('brouillon','validee','officielle') | NOT NULL DEFAULT 'brouillon' | Statut feuille |
| cree_par           | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Créateur |
| valide_par         | INT (FK)     | NULLABLE, FK → utilisateurs.id_utilisateur | Coach validateur |
| date_validation    | DATETIME     | NULLABLE                                | Date validation |
| emarque_importe    | BOOLEAN      | DEFAULT FALSE                           | Importé depuis e-Marque |
| created_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date création |
| updated_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- UNIQUE INDEX idx_match ON feuilles_match(id_match)
- INDEX idx_statut ON feuilles_match(statut)

**NOTE CDC :** Table ajoutée pour en-tête feuille de match + workflow validation.

---

### Table : stats_matchs

| Champ              | Type          | Contrainte                              | Description |
|--------------------|---------------|----------------------------------------|-------------|
| id_stat            | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique de la ligne de statistique |
| id_feuille         | INT (FK)      | NOT NULL, FK → feuilles_match.id_feuille | Référence feuille match |
| id_match           | INT (FK)      | NOT NULL, FK → matchs.id_match          | Référence au match concerné |
| id_joueur          | INT (FK)      | NOT NULL, FK → utilisateurs.id_utilisateur | Joueur concerné |
| titulaire          | BOOLEAN       | DEFAULT FALSE                           | Indique si le joueur a commencé le match (5 de départ) |
| temps_jeu          | INT           | NULLABLE                                | Temps de jeu en minutes |
| points_1pt         | INT           | DEFAULT 0                               | Lancers francs réussis |
| tentatives_1pt     | INT           | DEFAULT 0                               | Tentatives lancers francs |
| points_2pt         | INT           | DEFAULT 0                               | Tirs à 2 points réussis |
| tentatives_2pt     | INT           | DEFAULT 0                               | Tentatives 2 points |
| points_3pt         | INT           | DEFAULT 0                               | Tirs à 3 points réussis |
| tentatives_3pt     | INT           | DEFAULT 0                               | Tentatives 3 points |
| rebonds_off        | INT           | DEFAULT 0                               | Rebonds offensifs |
| rebonds_def        | INT           | DEFAULT 0                               | Rebonds défensifs |
| passes_decisives   | INT           | DEFAULT 0                               | Passes décisives |
| contres            | INT           | DEFAULT 0                               | Contres |
| interceptions      | INT           | DEFAULT 0                               | Interceptions |
| fautes             | INT           | DEFAULT 0                               | Fautes commises |
| balles_perdues     | INT           | DEFAULT 0                               | Turnovers / balles perdues |
| evaluation         | DECIMAL(6,2)  | NULLABLE                                | Évaluation calculée |
| source             | ENUM('manuel','ffbb_emarque','import_pdf') | NOT NULL DEFAULT 'manuel' | Origine de la stat |
| id_import          | INT (FK)      | NULLABLE, FK → import_emarque.id_import | Référence import si applicable |
| verifie            | BOOLEAN       | DEFAULT FALSE                           | Stats vérifiées/officielles |
| created_at         | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date de saisie |
| updated_at         | DATETIME      | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_feuille ON stats_matchs(id_feuille)
- INDEX idx_match_joueur ON stats_matchs(id_match, id_joueur)
- INDEX idx_joueur ON stats_matchs(id_joueur)

**NOTE CDC :** Ajout tentatives pour pourcentages, evaluation, verifie.

---

### Table : stats_quart_temps

| Champ              | Type          | Contrainte                              | Description |
|--------------------|---------------|----------------------------------------|-------------|
| id_stat_quart      | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_stat            | INT (FK)      | NOT NULL, FK → stats_matchs.id_stat     | Référence à la ligne principale |
| periode            | ENUM('Q1','Q2','Q3','Q4','OT1','OT2','OT3') | NOT NULL | Quart-temps ou prolongation |
| points_1pt         | INT           | DEFAULT 0                               | Lancers francs réussis |
| tentatives_1pt     | INT           | DEFAULT 0                               | Tentatives |
| points_2pt         | INT           | DEFAULT 0                               | Tirs à 2 points réussis |
| tentatives_2pt     | INT           | DEFAULT 0                               | Tentatives |
| points_3pt         | INT           | DEFAULT 0                               | Tirs à 3 points réussis |
| tentatives_3pt     | INT           | DEFAULT 0                               | Tentatives |
| rebonds_off        | INT           | DEFAULT 0                               | Rebonds offensifs |
| rebonds_def        | INT           | DEFAULT 0                               | Rebonds défensifs |
| passes_decisives   | INT           | DEFAULT 0                               | Passes décisives |
| contres            | INT           | DEFAULT 0                               | Contres |
| interceptions      | INT           | DEFAULT 0                               | Interceptions |
| fautes             | INT           | DEFAULT 0                               | Fautes commises |
| balles_perdues     | INT           | DEFAULT 0                               | Turnovers |
| temps_jeu          | INT           | NULLABLE                                | Temps de jeu en minutes dans la période |
| created_at         | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date création |

**Index recommandés :**
- INDEX idx_stat ON stats_quart_temps(id_stat)

**CONSTRAINT :** UNIQUE (id_stat, periode)

**NOTE :** Détail par quart-temps pour graphiques évolution.

---

### Table : stats_mi_temps

| Champ              | Type          | Contrainte                              | Description |
|--------------------|---------------|----------------------------------------|-------------|
| id_stat_mi         | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_stat            | INT (FK)      | NOT NULL, FK → stats_matchs.id_stat     | Référence à la ligne principale |
| periode            | ENUM('M1','M2','OT1','OT2','OT3') | NOT NULL | Mi-temps ou prolongation |
| points_1pt         | INT           | DEFAULT 0                               | Lancers francs réussis |
| tentatives_1pt     | INT           | DEFAULT 0                               | Tentatives |
| points_2pt         | INT           | DEFAULT 0                               | Tirs à 2 points réussis |
| tentatives_2pt     | INT           | DEFAULT 0                               | Tentatives |
| points_3pt         | INT           | DEFAULT 0                               | Tirs à 3 points réussis |
| tentatives_3pt     | INT           | DEFAULT 0                               | Tentatives |
| rebonds_off        | INT           | DEFAULT 0                               | Rebonds offensifs |
| rebonds_def        | INT           | DEFAULT 0                               | Rebonds défensifs |
| passes_decisives   | INT           | DEFAULT 0                               | Passes décisives |
| contres            | INT           | DEFAULT 0                               | Contres |
| interceptions      | INT           | DEFAULT 0                               | Interceptions |
| fautes             | INT           | DEFAULT 0                               | Fautes commises |
| balles_perdues     | INT           | DEFAULT 0                               | Turnovers |
| temps_jeu          | INT           | NULLABLE                                | Temps de jeu en minutes |
| created_at         | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date création |

**Index recommandés :**
- INDEX idx_stat ON stats_mi_temps(id_stat)

**CONSTRAINT :** UNIQUE (id_stat, periode)

**NOTE :** Pour matchs en 2x mi-temps au lieu de 4x quart-temps.

---

### Table : import_emarque

| Champ          | Type          | Contrainte                              | Description |
|----------------|---------------|----------------------------------------|-------------|
| id_import      | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique de l'import |
| id_match       | INT (FK)      | NOT NULL, FK → matchs.id_match          | Référence au match concerné |
| fichier_nom    | VARCHAR(255)  | NOT NULL                                | Nom original du fichier importé |
| fichier_type   | ENUM('resume','feuille','position_tirs','autre') | NOT NULL | Type de document importé |
| fichier_url    | TEXT          | NOT NULL                                | Lien/chemin vers le fichier stocké (S3) |
| format_source  | ENUM('pdf','xml','json') | NOT NULL      | Format du fichier source |
| statut_import  | ENUM('en_cours','reussi','echoue','partiel') | NOT NULL DEFAULT 'en_cours' | Statut import |
| message_erreur | TEXT          | NULLABLE                                | Message d'erreur si échec |
| nb_stats_importees | INT       | DEFAULT 0                               | Nombre de stats importées |
| date_import    | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date et heure de l'import |
| importe_par    | INT (FK)      | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur ayant fait l'import |

**Index recommandés :**
- INDEX idx_match ON import_emarque(id_match)
- INDEX idx_statut ON import_emarque(statut_import)

**NOTE CDC :** Ajout format_source, statut_import, nb_stats_importees.

---

## TABLES PIRB - STATS PERSONNELLES

### Table : objectifs_joueurs

| Champ              | Type          | Contrainte                              | Description |
|--------------------|---------------|----------------------------------------|-------------|
| id_objectif        | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_joueur          | INT (FK)      | NOT NULL, FK → utilisateurs.id_utilisateur | Joueur concerné |
| type_objectif      | ENUM('points','pourcentage_tir','rebonds','passes','autre') | NOT NULL | Type objectif |
| libelle            | VARCHAR(255)  | NOT NULL                                | Description objectif |
| valeur_cible       | DECIMAL(10,2) | NOT NULL                                | Valeur à atteindre |
| valeur_actuelle    | DECIMAL(10,2) | DEFAULT 0                               | Progression actuelle |
| unite              | VARCHAR(20)   | NULLABLE                                | Unité de mesure |
| date_debut         | DATE          | NOT NULL                                | Début période |
| date_fin           | DATE          | NULLABLE                                | Fin période/deadline |
| statut             | ENUM('actif','atteint','echoue','abandonne') | NOT NULL DEFAULT 'actif' | État objectif |
| partage_coach      | BOOLEAN       | DEFAULT FALSE                           | Partagé avec coach |
| created_at         | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date création |
| updated_at         | DATETIME      | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_joueur_statut ON objectifs_joueurs(id_joueur, statut)

**NOTE CDC :** Table ajoutée pour objectifs personnels PIRB.

---

### Table : stats_entrainement

| Champ           | Type          | Contrainte                              | Description |
|-----------------|---------------|----------------------------------------|-------------|
| id_session      | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique de la séance |
| id_joueur       | INT (FK)      | NOT NULL, FK → utilisateurs.id_utilisateur | Joueur concerné |
| date_session    | DATETIME      | NOT NULL                                | Date et heure de la séance |
| type_session    | ENUM('tir','dribble','passe','physique','mixte') | NOT NULL | Catégorie principale |
| duree_minutes   | INT           | NULLABLE                                | Durée en minutes |
| notes           | TEXT          | NULLABLE                                | Notes libres |
| partage_coach   | BOOLEAN       | DEFAULT FALSE                           | Partagé avec coach |
| created_at      | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date création |

**Index recommandés :**
- INDEX idx_joueur_date ON stats_entrainement(id_joueur, date_session)

**NOTE CDC :** Ajout duree_minutes, partage_coach.

---

### Table : stats_entrainement_tirs

| Champ           | Type          | Contrainte                              | Description |
|-----------------|---------------|----------------------------------------|-------------|
| id_tir          | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_session      | INT (FK)      | NOT NULL, FK → stats_entrainement.id_session | Référence séance |
| zone            | ENUM('3pts_0G','3pts_45G','3pts_central','3pts_45D','3pts_0D','2pts_0G','2pts_45G','2pts_central','2pts_45D','2pts_0D','LF','raquette_0g','raquette_central','raquette_0d') | NOT NULL | Zone de tir |
| tentatives      | INT           | NOT NULL, CHECK (tentatives >= 0)       | Nombre de tirs tentés |
| reussis         | INT           | NOT NULL, CHECK (reussis >= 0 AND reussis <= tentatives) | Tirs réussis |
| pourcentage     | DECIMAL(5,2)  | GENERATED ALWAYS AS (CASE WHEN tentatives > 0 THEN (reussis * 100.0 / tentatives) ELSE 0 END) STORED | Calcul auto |
| created_at      | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date création |

**Index recommandés :**
- INDEX idx_session ON stats_entrainement_tirs(id_session)

**NOTE :** Pourcentage calculé automatiquement.

---

### Table : stats_entrainement_dribbles

| Champ           | Type          | Contrainte                              | Description |
|-----------------|---------------|----------------------------------------|-------------|
| id_dribble      | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_session      | INT (FK)      | NOT NULL, FK → stats_entrainement.id_session | Référence séance |
| type_dribble    | ENUM('main_droite','main_gauche','crossover','spin_move','hesitation','in_and_out','behind_the_back','between_the_legs','shammgod','dribble_vitesse','combo') | NOT NULL | Type de dribble |
| tentatives      | INT           | NOT NULL, CHECK (tentatives >= 0)       | Nombre tentés |
| reussis         | INT           | NOT NULL, CHECK (reussis >= 0 AND reussis <= tentatives) | Réussis |
| pourcentage     | DECIMAL(5,2)  | GENERATED ALWAYS AS (CASE WHEN tentatives > 0 THEN (reussis * 100.0 / tentatives) ELSE 0 END) STORED | Calcul auto |
| created_at      | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date création |

**Index recommandés :**
- INDEX idx_session ON stats_entrainement_dribbles(id_session)

---

### Table : stats_entrainement_passes

| Champ           | Type          | Contrainte                              | Description |
|-----------------|---------------|----------------------------------------|-------------|
| id_passe        | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_session      | INT (FK)      | NOT NULL, FK → stats_entrainement.id_session | Référence séance |
| type_passe      | ENUM('chest_pass','overhead_pass','baseball_pass','hand_pass','off_dribble_pass','lob_pass','loop_pass','skip_pass','quick_pass','pocket_pass','behind_the_back_pass','between_the_legs_pass','no_look_pass','alley_oop') | NOT NULL | Type de passe |
| tentatives      | INT           | NOT NULL, CHECK (tentatives >= 0)       | Nombre tentées |
| reussis         | INT           | NOT NULL, CHECK (reussis >= 0 AND reussis <= tentatives) | Réussies |
| pourcentage     | DECIMAL(5,2)  | GENERATED ALWAYS AS (CASE WHEN tentatives > 0 THEN (reussis * 100.0 / tentatives) ELSE 0 END) STORED | Calcul auto |
| created_at      | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date création |

**Index recommandés :**
- INDEX idx_session ON stats_entrainement_passes(id_session)

---

## TABLES ENT (Espace Numérique de Travail)

### Table : ent_periodes

| Champ          | Type         | Contrainte                              | Description |
|----------------|--------------|-----------------------------------------|-------------|
| id_periode     | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant de la période |
| id_club        | INT (FK)     | NOT NULL, FK → clubs_officiels.id_club  | Club concerné |
| nom_periode    | VARCHAR(100) | NOT NULL                                | Nom (ex : "Nov–Déc 2023", "Trimestre 1") |
| date_debut     | DATE         | NOT NULL                                | Début de la période |
| date_fin       | DATE         | NOT NULL                                | Fin de la période |
| active         | BOOLEAN      | DEFAULT TRUE                            | Période encore utilisée |
| created_at     | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date de création |

**Index recommandés :**
- INDEX idx_club_dates ON ent_periodes(id_club, date_debut, date_fin)

---

### Table : ent_competences

| Champ           | Type          | Contrainte                              | Description |
|-----------------|---------------|-----------------------------------------|-------------|
| id_competence   | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant de la compétence |
| categorie       | ENUM('vie_quotidienne','qualites_mentales','qualites_techniques','qualites_physiques','autre') | NOT NULL | Catégorie |
| libelle         | VARCHAR(150)  | NOT NULL                                | Nom (ex : "Respect des règles de vie") |
| description     | TEXT          | NULLABLE                                | Détails |
| scope           | ENUM('global','ligue','comite','club') | NOT NULL DEFAULT 'club' | Niveau qui définit cette compétence |
| id_structure    | INT (FK)      | NULLABLE, FK → clubs_officiels.id_club  | Club/comité/ligue propriétaire |
| ordre_affichage | INT           | DEFAULT 0                               | Pour trier les lignes dans l'UI |
| actif           | BOOLEAN       | DEFAULT TRUE                            | Si FALSE → plus utilisée |
| cree_par        | INT (FK)      | NULLABLE, FK → utilisateurs.id_utilisateur | Créateur |
| created_at      | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date de création |

**Index recommandés :**
- INDEX idx_scope_structure ON ent_competences(scope, id_structure)
- INDEX idx_actif ON ent_competences(actif)

---

### Table : ent_bilans_joueurs

| Champ                 | Type          | Contrainte                              | Description |
|-----------------------|---------------|-----------------------------------------|-------------|
| id_bilan              | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant du bilan |
| id_joueur             | INT (FK)      | NOT NULL, FK → utilisateurs.id_utilisateur | Joueur évalué |
| id_evaluateur         | INT (FK)      | NOT NULL, FK → utilisateurs.id_utilisateur | Coach/évaluateur |
| id_periode            | INT (FK)      | NOT NULL, FK → ent_periodes.id_periode  | Période d'évaluation |
| id_equipe             | INT (FK)      | NULLABLE, FK → equipes.id_equipe        | Équipe du joueur |
| type_profil           | ENUM('eleve','joueur_club','joueur_performance') | NOT NULL DEFAULT 'joueur_club' | Contexte bilan |
| nb_entrainements      | INT           | NULLABLE                                | Nombre de séances |
| nb_presences          | INT           | NULLABLE                                | Présences |
| nb_stages             | INT           | NULLABLE                                | Nombre de stages |
| alerte_medicale       | TEXT          | NULLABLE                                | Zone Alerte médicale |
| points_forts          | TEXT          | NULLABLE                                | Points forts |
| points_vigilance      | TEXT          | NULLABLE                                | Points de vigilance |
| axes_travail          | TEXT          | NULLABLE                                | Axes de travail |
| bilan_remarques       | TEXT          | NULLABLE                                | Remarques globales |
| partage_parent        | BOOLEAN       | DEFAULT TRUE                            | Visible parent/joueur |
| version_pdf_url       | TEXT          | NULLABLE                                | Lien vers le PDF généré |
| created_at            | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date de réalisation |
| updated_at            | DATETIME      | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_joueur_periode ON ent_bilans_joueurs(id_joueur, id_periode)
- INDEX idx_evaluateur ON ent_bilans_joueurs(id_evaluateur)

---

### Table : ent_notes_competences

| Champ               | Type          | Contrainte                              | Description |
|---------------------|---------------|-----------------------------------------|-------------|
| id_note             | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant |
| id_bilan            | INT (FK)      | NOT NULL, FK → ent_bilans_joueurs.id_bilan | Référence bilan |
| id_competence       | INT (FK)      | NOT NULL, FK → ent_competences.id_competence | Compétence |
| note                | TINYINT       | NOT NULL, CHECK (note BETWEEN 1 AND 10) | Note 1–10 |
| commentaire         | TEXT          | NULLABLE                                | Commentaire éventuel |
| created_at          | DATETIME      | DEFAULT CURRENT_TIMESTAMP               | Date création |

**Index recommandés :**
- INDEX idx_bilan ON ent_notes_competences(id_bilan)

**CONSTRAINT :** UNIQUE (id_bilan, id_competence)

---

## TABLES NOTIFICATIONS

### Table : notifications

| Champ            | Type         | Contrainte                              | Description |
|------------------|--------------|----------------------------------------|-------------|
| id_notification  | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_utilisateur   | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur destinataire |
| type_notif       | ENUM('event_reminder','message','task','gamification','alert','match','training','other') | NOT NULL | Type notification |
| titre            | VARCHAR(255) | NOT NULL                                | Titre notification |
| message          | TEXT         | NOT NULL                                | Contenu de la notification |
| data_payload     | JSON         | NULLABLE                                | Données additionnelles (liens, IDs) |
| id_match         | INT (FK)     | NULLABLE, FK → matchs.id_match          | Lien match si applicable |
| id_evenement     | INT (FK)     | NULLABLE, FK → evenements.id_evenement  | Lien événement si applicable |
| lue              | BOOLEAN      | DEFAULT FALSE                           | Notification lue |
| date_programmee  | DATETIME     | NULLABLE                                | Quand la notif doit partir |
| date_envoyee     | DATETIME     | NULLABLE                                | Quand elle a été envoyée |
| statut           | ENUM('en_attente','envoyee','echouee') | NOT NULL DEFAULT 'en_attente' | État |
| canal_envoye     | ENUM('push','email','sms','in_app') | NULLABLE | Canal utilisé |
| created_at       | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date création |

**Index recommandés :**
- INDEX idx_user_lue ON notifications(id_utilisateur, lue)
- INDEX idx_statut ON notifications(statut)
- INDEX idx_programmee ON notifications(date_programmee)

**NOTE CDC :** Ajout titre, data_payload, canal_envoye, lue.

---

### Table : preferences_notifications

| Champ                 | Type         | Contrainte                              | Description |
|-----------------------|--------------|-----------------------------------------|-------------|
| id_preference         | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_utilisateur        | INT (FK)     | NOT NULL, UNIQUE, FK → utilisateurs.id_utilisateur | Utilisateur concerné |
| type_notif            | VARCHAR(50)  | NOT NULL                                | Type de notification (event_reminder, message, etc.) |
| push_actif            | BOOLEAN      | DEFAULT TRUE                            | Notifications push activées |
| email_actif           | BOOLEAN      | DEFAULT TRUE                            | Notifications email activées |
| sms_actif             | BOOLEAN      | DEFAULT FALSE                           | Notifications SMS activées |
| rappel_match_minutes  | INT          | DEFAULT 120                             | Minutes avant match (rappel) |
| rappel_event_minutes  | INT          | DEFAULT 1440                            | Minutes avant événement (24h) |
| notifications_agenda  | BOOLEAN      | DEFAULT TRUE                            | Génération fichier agenda (ICS) |
| created_at            | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date création |
| updated_at            | DATETIME     | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière modification |

**Index recommandés :**
- UNIQUE INDEX idx_user ON preferences_notifications(id_utilisateur)

**NOTE CDC :** Renommé depuis preferences_utilisateur + ajout type_notif.

---

## TABLES MESSAGERIE

### Table : conversations

| Champ              | Type         | Contrainte                              | Description |
|--------------------|--------------|-----------------------------------------|-------------|
| id_conversation    | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| type_conversation  | ENUM('individuelle','groupe','diffusion') | NOT NULL | Type conversation |
| titre              | VARCHAR(255) | NULLABLE                                | Titre (pour groupes) |
| id_club            | INT (FK)     | NULLABLE, FK → clubs_officiels.id_club  | Club concerné |
| cree_par           | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Créateur |
| created_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date création |
| updated_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière activité |

**Index recommandés :**
- INDEX idx_club ON conversations(id_club)
- INDEX idx_type ON conversations(type_conversation)

**NOTE CDC :** Table ajoutée pour messagerie interne.

---

### Table : participants_conversation

| Champ              | Type         | Contrainte                              | Description |
|--------------------|--------------|-----------------------------------------|-------------|
| id_participant     | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_conversation    | INT (FK)     | NOT NULL, FK → conversations.id_conversation ON DELETE CASCADE | Conversation |
| id_utilisateur     | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Participant |
| role_conversation  | ENUM('admin','membre','lecture_seule') | NOT NULL DEFAULT 'membre' | Rôle dans conversation |
| date_ajout         | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date d'ajout |
| date_sortie        | DATETIME     | NULLABLE                                | Date de sortie |
| actif              | BOOLEAN      | DEFAULT TRUE                            | Participant actif |

**Index recommandés :**
- INDEX idx_conversation ON participants_conversation(id_conversation)
- INDEX idx_user ON participants_conversation(id_utilisateur)

**CONSTRAINT :** UNIQUE (id_conversation, id_utilisateur) WHERE actif = TRUE

**NOTE CDC :** Table ajoutée pour gestion participants conversations.

---

### Table : messages

| Champ              | Type         | Contrainte                              | Description |
|--------------------|--------------|-----------------------------------------|-------------|
| id_message         | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_conversation    | INT (FK)     | NOT NULL, FK → conversations.id_conversation ON DELETE CASCADE | Conversation |
| id_expediteur      | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Expéditeur |
| contenu            | TEXT         | NOT NULL                                | Contenu message |
| type_message       | ENUM('texte','image','document','video','audio') | NOT NULL DEFAULT 'texte' | Type contenu |
| supprime           | BOOLEAN      | DEFAULT FALSE                           | Message supprimé |
| supprime_par       | INT (FK)     | NULLABLE, FK → utilisateurs.id_utilisateur | Modérateur ayant supprimé |
| created_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date envoi |
| updated_at         | DATETIME     | NULLABLE                                | Date édition |

**Index recommandés :**
- INDEX idx_conversation_date ON messages(id_conversation, created_at)
- INDEX idx_expediteur ON messages(id_expediteur)

**NOTE CDC :** Ajout supprime + supprime_par pour modération.

---

### Table : messages_pieces_jointes

| Champ              | Type         | Contrainte                              | Description |
|--------------------|--------------|-----------------------------------------|-------------|
| id_piece_jointe    | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_message         | INT (FK)     | NOT NULL, FK → messages.id_message ON DELETE CASCADE | Message parent |
| fichier_nom        | VARCHAR(255) | NOT NULL                                | Nom fichier |
| fichier_url        | TEXT         | NOT NULL                                | URL stockage (S3) |
| fichier_type       | VARCHAR(50)  | NOT NULL                                | Type MIME |
| fichier_taille     | INT          | NOT NULL                                | Taille en octets |
| created_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date upload |

**Index recommandés :**
- INDEX idx_message ON messages_pieces_jointes(id_message)

**NOTE CDC :** Table ajoutée pour pièces jointes messages.

---

### Table : messages_lecture

| Champ              | Type         | Contrainte                              | Description |
|--------------------|--------------|-----------------------------------------|-------------|
| id_lecture         | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL        | Identifiant unique |
| id_message         | INT (FK)     | NOT NULL, FK → messages.id_message ON DELETE CASCADE | Message concerné |
| id_utilisateur     | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur |
| date_lecture       | DATETIME     | DEFAULT CURRENT_TIMESTAMP               | Date de lecture |

**Index recommandés :**
- INDEX idx_message_user ON messages_lecture(id_message, id_utilisateur)

**CONSTRAINT :** UNIQUE (id_message, id_utilisateur)

**NOTE CDC :** Table ajoutée pour accusés de lecture.

---

## TABLES GAMIFICATION

### Table : medailles

| Champ         | Type        | Contrainte                  | Description |
|---------------|-------------|-----------------------------|-------------|
| id_medaille   | INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique de la médaille |
| nom_medaille  | VARCHAR(50) | NOT NULL                    | Nom de la médaille (ex : Bronze, Argent, Or) |
| description   | TEXT        | NULLABLE                    | Description des conditions d'obtention |
| seuil         | INT         | NOT NULL                    | Nombre d'actions (ex : 3 buvettes = bronze) |
| type_action   | ENUM('buvette','table_marque','organisation','general','autre') | NOT NULL | Type d'action concernée |
| icone_url     | TEXT        | NULLABLE                    | Icône médaille |
| created_at    | DATETIME    | DEFAULT CURRENT_TIMESTAMP   | Date de création |

---

### Table : utilisateurs_medailles

| Champ                  | Type        | Contrainte                  | Description |
|------------------------|-------------|-----------------------------|-------------|
| id_utilisateur_medaille| INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur         | INT (FK)    | NOT NULL, FK → utilisateurs.id_utilisateur | Bénévole |
| id_medaille            | INT (FK)    | NOT NULL, FK → medailles.id_medaille | Médaille obtenue |
| date_obtention         | DATETIME    | DEFAULT CURRENT_TIMESTAMP   | Date d'attribution |
| visible_profil         | BOOLEAN     | DEFAULT TRUE                | Affichée sur le profil public |

**Index recommandés :**
- INDEX idx_user ON utilisateurs_medailles(id_utilisateur)

---

### Table : badges

| Champ        | Type        | Contrainte                  | Description |
|--------------|-------------|-----------------------------|-------------|
| id_badge     | INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique du badge |
| nom_badge    | VARCHAR(100)| NOT NULL                    | Nom du badge (ex : MVP du mois) |
| description  | TEXT        | NULLABLE                    | Conditions d'obtention |
| categorie    | ENUM('joueur','coach','benevole','dirigeant','general') | NOT NULL | Catégorie concernée |
| icone_url    | TEXT        | NULLABLE                    | Icône ou image associée |
| points       | INT         | DEFAULT 0                   | Points attribués (classement) |
| points_bonus | INT         | DEFAULT 0                   | Bonus supplémentaire |
| created_at   | DATETIME    | DEFAULT CURRENT_TIMESTAMP   | Date de création |

---

### Table : utilisateurs_badges

| Champ                | Type        | Contrainte                  | Description |
|----------------------|-------------|-----------------------------|-------------|
| id_utilisateur_badge | INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur       | INT (FK)    | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur |
| id_badge             | INT (FK)    | NOT NULL, FK → badges.id_badge | Badge |
| date_obtention       | DATETIME    | DEFAULT CURRENT_TIMESTAMP   | Date d'attribution |
| visible_profil       | BOOLEAN     | DEFAULT TRUE                | Badge affiché publiquement |

**Index recommandés :**
- INDEX idx_user ON utilisateurs_badges(id_utilisateur)

---

### Table : classements_utilisateurs

| Champ            | Type        | Contrainte                  | Description |
|------------------|-------------|-----------------------------|-------------|
| id_classement_u  | INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur   | INT (FK)    | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur |
| saison           | VARCHAR(9)  | NOT NULL                    | Saison sportive (ex : 2024-2025) |
| points_totaux    | INT         | DEFAULT 0                   | Points cumulés |
| rang_club        | INT         | NULLABLE                    | Position dans son club |
| rang_comite      | INT         | NULLABLE                    | Position dans son comité |
| rang_ligue       | INT         | NULLABLE                    | Position dans sa ligue |
| rang_national    | INT         | NULLABLE                    | Position nationale |
| updated_at       | DATETIME    | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_user_saison ON classements_utilisateurs(id_utilisateur, saison)

**CONSTRAINT :** UNIQUE (id_utilisateur, saison)

---

### Table : classements_clubs

| Champ          | Type        | Contrainte                  | Description |
|----------------|-------------|-----------------------------|-------------|
| id_classement_c| INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_club        | INT (FK)    | NOT NULL, FK → clubs_officiels.id_club | Club |
| saison         | VARCHAR(9)  | NOT NULL                    | Saison sportive |
| points_totaux  | INT         | DEFAULT 0                   | Points cumulés du club |
| rang_comite    | INT         | NULLABLE                    | Position dans le comité |
| rang_ligue     | INT         | NULLABLE                    | Position dans la ligue |
| rang_national  | INT         | NULLABLE                    | Position nationale |
| updated_at     | DATETIME    | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_club_saison ON classements_clubs(id_club, saison)

**CONSTRAINT :** UNIQUE (id_club, saison)

---

### Table : classements_comites

| Champ            | Type        | Contrainte                  | Description |
|------------------|-------------|-----------------------------|-------------|
| id_classement_co | INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_comite        | INT (FK)    | NOT NULL, FK → clubs_officiels.id_club WHERE type_structure='comite' | Comité |
| saison           | VARCHAR(9)  | NOT NULL                    | Saison sportive |
| points_totaux    | INT         | DEFAULT 0                   | Points cumulés |
| rang_ligue       | INT         | NULLABLE                    | Position dans la ligue |
| rang_national    | INT         | NULLABLE                    | Position nationale |
| updated_at       | DATETIME    | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_comite_saison ON classements_comites(id_comite, saison)

**CONSTRAINT :** UNIQUE (id_comite, saison)

---

### Table : classements_ligues

| Champ           | Type        | Contrainte                  | Description |
|-----------------|-------------|-----------------------------|-------------|
| id_classement_l | INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_ligue        | INT (FK)    | NOT NULL, FK → clubs_officiels.id_club WHERE type_structure='ligue' | Ligue |
| saison          | VARCHAR(9)  | NOT NULL                    | Saison sportive |
| points_totaux   | INT         | DEFAULT 0                   | Points cumulés |
| rang_national   | INT         | NULLABLE                    | Position nationale |
| updated_at      | DATETIME    | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_ligue_saison ON classements_ligues(id_ligue, saison)

**CONSTRAINT :** UNIQUE (id_ligue, saison)

---

## TABLES LABELS & PARTENAIRES

### Table : labels_ffbb

| Champ          | Type        | Contrainte                  | Description |
|----------------|-------------|-----------------------------|-------------|
| id_label       | INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique du label |
| nom_label      | VARCHAR(100)| NOT NULL, UNIQUE            | Nom du label (ex : "Label Jeunes 3 étoiles") |
| description    | TEXT        | NULLABLE                    | Explication du label |
| points_bonus   | INT         | DEFAULT 0                   | Points attribués au club |
| source_url     | VARCHAR(255)| NULLABLE                    | Lien/attestation officielle (PDF) |
| origine        | ENUM('ffbb','app_intern','partenaire') | NOT NULL | Source du label |
| niveau         | ENUM('1_etoile','2_etoiles','3_etoiles','autre') | NULLABLE | Niveau si applicable |
| created_at     | DATETIME    | DEFAULT CURRENT_TIMESTAMP   | Date d'ajout |

---

### Table : clubs_labels

| Champ            | Type        | Contrainte                  | Description |
|------------------|-------------|-----------------------------|-------------|
| id_club_label    | INT (PK)    | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_club          | INT (FK)    | NOT NULL, FK → clubs_officiels.id_club | Club |
| id_label         | INT (FK)    | NOT NULL, FK → labels_ffbb.id_label | Label attribué |
| date_attribution | DATE        | NOT NULL                    | Date officielle d'attribution |
| valide_jusqu_a   | DATE        | NULLABLE                    | Date de fin de validité |

**Index recommandés :**
- INDEX idx_club ON clubs_labels(id_club)

**CONSTRAINT :** UNIQUE (id_club, id_label, date_attribution)

---

### Table : partenaires

| Champ           | Type         | Contrainte                  | Description |
|-----------------|--------------|-----------------------------|-------------|
| id_partenaire   | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| nom             | VARCHAR(100) | NOT NULL                    | Nom du partenaire |
| logo_url        | TEXT         | NULLABLE                    | Logo ou image |
| type_partenaire | ENUM('restaurant','magasin','salle_sport','autre') | NOT NULL | Catégorie |
| description     | TEXT         | NULLABLE                    | Description |
| site_web        | VARCHAR(255) | NULLABLE                    | Site web |
| email_contact   | VARCHAR(255) | NULLABLE                    | Email contact |
| statut          | ENUM('actif','inactif') | DEFAULT 'actif' | Statut partenaire |
| created_at      | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date d'ajout |

**NOTE CDC :** Ajout site_web, email_contact, statut.

---

### Table : offres_partenaires

| Champ           | Type         | Contrainte                  | Description |
|-----------------|--------------|-----------------------------|-------------|
| id_offre        | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_partenaire   | INT (FK)     | NOT NULL, FK → partenaires.id_partenaire | Partenaire |
| id_club         | INT (FK)     | NULLABLE, FK → clubs_officiels.id_club | Club si offre réservée |
| titre_offre     | VARCHAR(100) | NOT NULL                    | Nom de l'offre |
| description     | TEXT         | NULLABLE                    | Détails de l'offre |
| condition_type  | ENUM('visite','points_benevolat','points_generaux') | NOT NULL | Condition déblocage |
| condition_valeur| INT          | NOT NULL                    | Nombre visites/points nécessaires |
| recompense      | VARCHAR(100) | NOT NULL                    | Type de récompense |
| code_promo      | VARCHAR(50)  | NULLABLE                    | Code promo éventuel |
| valide_jusqu_a  | DATE         | NULLABLE                    | Date limite |
| created_at      | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date création |

**NOTE CDC :** Ajout code_promo.

---

### Table : visites_partenaires

| Champ           | Type         | Contrainte                  | Description |
|-----------------|--------------|-----------------------------|-------------|
| id_visite       | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur  | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur |
| id_partenaire   | INT (FK)     | NOT NULL, FK → partenaires.id_partenaire | Partenaire |
| date_visite     | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date de la visite |
| preuve          | ENUM('qrcode','validation_commercant','manuel') | NOT NULL | Méthode validation |
| created_at      | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date création |

**Index recommandés :**
- INDEX idx_user ON visites_partenaires(id_utilisateur)
- INDEX idx_partenaire ON visites_partenaires(id_partenaire)

---

### Table : coupons

| Champ           | Type         | Contrainte                  | Description |
|-----------------|--------------|-----------------------------|-------------|
| id_coupon       | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur  | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur |
| id_offre        | INT (FK)     | NOT NULL, FK → offres_partenaires.id_offre | Offre débloquée |
| code_unique     | VARCHAR(50)  | UNIQUE, NOT NULL            | Code ou QR généré |
| utilise         | BOOLEAN      | DEFAULT FALSE               | Statut du coupon |
| date_creation   | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date de génération |
| date_utilisation| DATETIME     | NULLABLE                    | Date d'utilisation |
| date_expiration | DATETIME     | NULLABLE                    | Date d'expiration |

**Index recommandés :**
- UNIQUE INDEX idx_code ON coupons(code_unique)
- INDEX idx_user ON coupons(id_utilisateur)

**NOTE CDC :** Ajout date_expiration.

---

## TABLES SÉANCES & FORMATIONS

### Table : seances

| Champ            | Type         | Contrainte                  | Description |
|------------------|--------------|-----------------------------|-------------|
| id_seance        | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_club          | INT (FK)     | NOT NULL, FK → clubs_officiels.id_club | Club organisateur |
| id_equipe        | INT (FK)     | NULLABLE, FK → equipes.id_equipe | Équipe concernée |
| id_createur      | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Créateur (coach/dirigeant) |
| titre            | VARCHAR(100) | NOT NULL                    | Nom de la séance |
| description      | TEXT         | NULLABLE                    | Contenu ou objectifs |
| date_debut       | DATETIME     | NOT NULL                    | Date et heure de début |
| date_fin         | DATETIME     | NULLABLE                    | Date et heure de fin |
| lieu             | VARCHAR(100) | NULLABLE                    | Lieu de la séance |
| recurrence       | ENUM('unique','hebdomadaire','mensuelle') | DEFAULT 'unique' | Type de récurrence |
| statut           | ENUM('planifie','termine','annule') | DEFAULT 'planifie' | État actuel |
| created_at       | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date de création |
| updated_at       | DATETIME     | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_club_date ON seances(id_club, date_debut)
- INDEX idx_equipe ON seances(id_equipe)

---

### Table : feedback_seances

| Champ             | Type         | Contrainte                  | Description |
|-------------------|--------------|-----------------------------|-------------|
| id_feedback       | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_seance         | INT (FK)     | NOT NULL, FK → seances.id_seance | Séance concernée |
| id_utilisateur    | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Joueur ayant donné feedback |
| note              | TINYINT      | NOT NULL, CHECK (note BETWEEN 1 AND 5) | Note de 1 à 5 étoiles |
| commentaire       | TEXT         | NULLABLE                    | Commentaire facultatif |
| anonyme           | BOOLEAN      | DEFAULT TRUE                | Feedback anonyme pour coach |
| created_at        | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date de soumission |

**Index recommandés :**
- INDEX idx_seance ON feedback_seances(id_seance)

**CONSTRAINT :** UNIQUE (id_seance, id_utilisateur)

---

## TABLES DOCUMENTS & MÉDIAS

### Table : documents

| Champ           | Type         | Contrainte                  | Description |
|-----------------|--------------|-----------------------------|-------------|
| id_document     | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_club         | INT (FK)     | NOT NULL, FK → clubs_officiels.id_club | Club propriétaire |
| categorie       | ENUM('reglement','administratif','pedagogique','medical','autre') | NOT NULL | Catégorie |
| titre           | VARCHAR(255) | NOT NULL                    | Titre document |
| description     | TEXT         | NULLABLE                    | Description |
| fichier_url     | TEXT         | NOT NULL                    | URL stockage (S3) |
| fichier_type    | VARCHAR(50)  | NOT NULL                    | Type MIME (pdf, docx...) |
| fichier_taille  | INT          | NOT NULL                    | Taille en octets |
| visible_roles   | JSON         | NULLABLE                    | Liste des rôles autorisés |
| telecharge_par  | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Uploader |
| created_at      | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date upload |
| updated_at      | DATETIME     | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_club_categorie ON documents(id_club, categorie)

**NOTE CDC :** Table ajoutée pour gestion documents ENT.

---

### Table : actualites

| Champ           | Type         | Contrainte                  | Description |
|-----------------|--------------|-----------------------------|-------------|
| id_actualite    | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_club         | INT (FK)     | NOT NULL, FK → clubs_officiels.id_club | Club concerné |
| titre           | VARCHAR(255) | NOT NULL                    | Titre actualité |
| contenu         | TEXT         | NOT NULL                    | Contenu HTML |
| image_url       | TEXT         | NULLABLE                    | Image principale |
| auteur          | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Auteur |
| epingle         | BOOLEAN      | DEFAULT FALSE               | Actualité épinglée |
| commentaires_actifs | BOOLEAN  | DEFAULT TRUE                | Commentaires autorisés |
| statut          | ENUM('brouillon','publie','archive') | NOT NULL DEFAULT 'brouillon' | État publication |
| date_publication| DATETIME     | NULLABLE                    | Date de publication |
| created_at      | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date création |
| updated_at      | DATETIME     | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- INDEX idx_club_statut ON actualites(id_club, statut)
- INDEX idx_date_pub ON actualites(date_publication)

**NOTE CDC :** Table ajoutée pour actualités club.

---

### Table : commentaires_actualites

| Champ           | Type         | Contrainte                  | Description |
|-----------------|--------------|-----------------------------|-------------|
| id_commentaire  | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_actualite    | INT (FK)     | NOT NULL, FK → actualites.id_actualite ON DELETE CASCADE | Actualité |
| id_utilisateur  | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Auteur commentaire |
| contenu         | TEXT         | NOT NULL                    | Texte commentaire |
| modere          | BOOLEAN      | DEFAULT FALSE               | Modéré par dirigeant |
| modere_par      | INT (FK)     | NULLABLE, FK → utilisateurs.id_utilisateur | Modérateur |
| supprime        | BOOLEAN      | DEFAULT FALSE               | Commentaire supprimé |
| created_at      | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date création |

**Index recommandés :**
- INDEX idx_actualite ON commentaires_actualites(id_actualite)

**NOTE CDC :** Table ajoutée pour commentaires actus + modération.

---

### Table : media

| Champ           | Type         | Contrainte                  | Description |
|-----------------|--------------|-----------------------------|-------------|
| id_media        | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_evenement    | INT (FK)     | NULLABLE, FK → evenements.id_evenement | Événement concerné |
| id_match        | INT (FK)     | NULLABLE, FK → matchs.id_match | Match concerné |
| id_seance       | INT (FK)     | NULLABLE, FK → seances.id_seance | Séance concernée |
| url_media       | TEXT         | NOT NULL                    | Lien/chemin fichier (S3) |
| type_media      | ENUM('photo','video','doc') | NOT NULL | Type de média |
| titre           | VARCHAR(255) | NULLABLE                    | Titre média |
| description     | TEXT         | NULLABLE                    | Description |
| uploader        | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Uploader |
| visible_public  | BOOLEAN      | DEFAULT FALSE               | Média public |
| filigrane       | BOOLEAN      | DEFAULT TRUE                | Filigrane appliqué (VENA) |
| created_at      | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date d'ajout |

**Index recommandés :**
- INDEX idx_evenement ON media(id_evenement)
- INDEX idx_match ON media(id_match)
- INDEX idx_type ON media(type_media)

**NOTE CDC :** Ajout titre, description.

---

## TABLES SONDAGES & VOTES

### Table : sondages

| Champ            | Type         | Contrainte                  | Description |
|------------------|--------------|-----------------------------|-------------|
| id_sondage       | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_club          | INT (FK)     | NOT NULL, FK → clubs_officiels.id_club | Club organisateur |
| question         | TEXT         | NOT NULL                    | Question posée |
| type_sondage     | ENUM('choix_unique','choix_multiple','note') | NOT NULL | Format du vote |
| options          | JSON         | NULLABLE                    | Liste des options (pour choix) |
| anonyme          | BOOLEAN      | DEFAULT TRUE                | Votes anonymes |
| statut           | ENUM('ouvert','ferme','archive') | NOT NULL DEFAULT 'ouvert' | État sondage |
| cree_par         | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Créateur |
| date_creation    | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date de création |
| date_fin         | DATETIME     | NULLABLE                    | Date limite |

**Index recommandés :**
- INDEX idx_club ON sondages(id_club)

**NOTE CDC :** Ajout options JSON, anonyme, statut.

---

### Table : votes

| Champ            | Type         | Contrainte                  | Description |
|------------------|--------------|-----------------------------|-------------|
| id_vote          | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_sondage       | INT (FK)     | NOT NULL, FK → sondages.id_sondage | Sondage concerné |
| id_utilisateur   | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur ayant voté |
| choix            | TEXT         | NOT NULL                    | Réponse ou choix |
| note             | TINYINT      | NULLABLE, CHECK (note BETWEEN 1 AND 10) | Note si type=note |
| date_vote        | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date du vote |

**Index recommandés :**
- INDEX idx_sondage ON votes(id_sondage)

**CONSTRAINT :** UNIQUE (id_sondage, id_utilisateur) IF sondage.type_sondage = 'choix_unique'

**NOTE CDC :** Ajout note pour sondages notation.

---

## TABLES SANCTIONS & MODÉRATION

### Table : sanctions

| Champ            | Type         | Contrainte                  | Description |
|------------------|--------------|-----------------------------|-------------|
| id_sanction      | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur   | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur sanctionné |
| type_sanction    | ENUM('avertissement','suspension','exclusion') | NOT NULL | Nature de la sanction |
| raison           | TEXT         | NOT NULL                    | Motif de la sanction |
| date_debut       | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Début de la sanction |
| date_fin         | DATETIME     | NULLABLE                    | Fin si applicable |
| statut           | ENUM('active','levee') | NOT NULL DEFAULT 'active' | État actuel |
| emise_par        | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Dirigeant ayant sanctionné |
| created_at       | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date création |

**Index recommandés :**
- INDEX idx_user_statut ON sanctions(id_utilisateur, statut)

**NOTE CDC :** Ajout emise_par.

---

## TABLES IMPORT & SYNCHRONISATION FFBB

### Table : import_logs

| Champ          | Type          | Contrainte                  | Description |
|----------------|---------------|-----------------------------|-------------|
| id_log         | INT (PK)      | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur | INT (FK)      | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur ayant réalisé l'import |
| id_match       | INT (FK)      | NULLABLE, FK → matchs.id_match | Match concerné si applicable |
| id_club        | INT (FK)      | NULLABLE, FK → clubs_officiels.id_club | Club concerné si applicable |
| type_import    | ENUM('emarque_pdf','clubs_excel','licences_csv','calendrier_ffbb','autre') | NOT NULL | Type fichier |
| fichier_nom    | VARCHAR(255)  | NOT NULL                    | Nom original |
| fichier_url    | TEXT          | NULLABLE                    | Lien/chemin fichier stocké |
| statut         | ENUM('en_cours','reussi','echoue','partiel') | NOT NULL | Statut import |
| message        | TEXT          | NULLABLE                    | Message erreur/log |
| nb_lignes_traitees | INT       | DEFAULT 0                   | Nombre lignes traitées |
| nb_lignes_succes   | INT       | DEFAULT 0                   | Nombre lignes succès |
| nb_lignes_erreurs  | INT       | DEFAULT 0                   | Nombre lignes erreur |
| date_import    | DATETIME      | DEFAULT CURRENT_TIMESTAMP   | Date import |

**Index recommandés :**
- INDEX idx_user ON import_logs(id_utilisateur)
- INDEX idx_type_statut ON import_logs(type_import, statut)

**NOTE CDC :** Ajout type calendrier_ffbb + nb_lignes_*.

---

### Table : licences_ffbb

| Champ              | Type         | Contrainte                  | Description |
|--------------------|--------------|-----------------------------|-------------|
| id_licence         | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur     | INT (FK)     | NULLABLE, FK → utilisateurs.id_utilisateur | Utilisateur lié (si existe) |
| numero_licence     | VARCHAR(20)  | UNIQUE, NOT NULL            | Numéro licence FFBB |
| id_club_ffbb       | VARCHAR(20)  | NOT NULL                    | Code FFBB club |
| saison             | VARCHAR(9)   | NOT NULL                    | Saison (ex: 2024-2025) |
| categorie          | VARCHAR(50)  | NULLABLE                    | Catégorie joueur |
| certificat_medical | DATE         | NULLABLE                    | Date certificat médical |
| date_validite      | DATE         | NULLABLE                    | Date fin de validité |
| statut             | ENUM('active','expiree','suspendue') | NOT NULL DEFAULT 'active' | Statut licence |
| derniere_sync      | DATETIME     | NULLABLE                    | Dernière synchro FFBB |
| created_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date création |
| updated_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**Index recommandés :**
- UNIQUE INDEX idx_numero ON licences_ffbb(numero_licence, saison)
- INDEX idx_user ON licences_ffbb(id_utilisateur)
- INDEX idx_club ON licences_ffbb(id_club_ffbb)

**NOTE CDC :** Table ajoutée pour gestion licences FFBB.

---

### Table : competitions_ffbb

| Champ              | Type         | Contrainte                  | Description |
|--------------------|--------------|-----------------------------|-------------|
| id_competition     | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| code_ffbb          | VARCHAR(50)  | UNIQUE, NOT NULL            | Code compétition FFBB |
| nom_competition    | VARCHAR(255) | NOT NULL                    | Nom compétition |
| categorie          | VARCHAR(50)  | NULLABLE                    | Catégorie |
| saison             | VARCHAR(9)   | NOT NULL                    | Saison |
| type_competition   | ENUM('championnat','coupe','tournoi') | NOT NULL | Type |
| derniere_sync      | DATETIME     | NULLABLE                    | Dernière synchro |
| created_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date création |

**Index recommandés :**
- UNIQUE INDEX idx_code_saison ON competitions_ffbb(code_ffbb, saison)

**NOTE CDC :** Table ajoutée pour référentiel compétitions FFBB.

---

## TABLES AUDIT & RGPD

### Table : historique_actions

| Champ            | Type         | Contrainte                  | Description |
|------------------|--------------|-----------------------------|-------------|
| id_action        | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur   | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Auteur de l'action |
| entite           | VARCHAR(100) | NOT NULL                    | Nom table/entité modifiée |
| id_entite        | INT          | NOT NULL                    | ID enregistrement ciblé |
| action_type      | ENUM('create','update','delete','validate','other') | NOT NULL | Type action |
| details          | JSON         | NULLABLE                    | Détails changements |
| ip_adresse       | VARCHAR(45)  | NULLABLE                    | IP utilisateur |
| user_agent       | TEXT         | NULLABLE                    | User agent |
| date_action      | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Horodatage |

**Index recommandés :**
- INDEX idx_user ON historique_actions(id_utilisateur)
- INDEX idx_entite ON historique_actions(entite, id_entite)
- INDEX idx_date ON historique_actions(date_action)

**NOTE :** Tracer actions sensibles (audit RGPD).

---

### Table : consentements_rgpd

| Champ              | Type         | Contrainte                  | Description |
|--------------------|--------------|-----------------------------|-------------|
| id_consentement    | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur     | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur concerné |
| type_consentement  | ENUM('rgpd_general','image','medical','newsletter','partage_coach','autre') | NOT NULL | Type |
| consenti           | BOOLEAN      | NOT NULL                    | Consentement donné |
| date_consentement  | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date consentement |
| consentement_parent| BOOLEAN      | DEFAULT FALSE               | Consentement parental (mineurs) |
| id_parent          | INT (FK)     | NULLABLE, FK → utilisateurs.id_utilisateur | Parent ayant consenti |
| ip_adresse         | VARCHAR(45)  | NULLABLE                    | IP lors du consentement |
| revoque            | BOOLEAN      | DEFAULT FALSE               | Consentement révoqué |
| date_revocation    | DATETIME     | NULLABLE                    | Date révocation |

**Index recommandés :**
- INDEX idx_user_type ON consentements_rgpd(id_utilisateur, type_consentement)

**NOTE CDC :** Table ajoutée pour traçabilité RGPD.

---

### Table : demandes_suppression_donnees

| Champ              | Type         | Contrainte                  | Description |
|--------------------|--------------|-----------------------------|-------------|
| id_demande         | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur     | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur demandeur |
| type_demande       | ENUM('suppression','anonymisation','export') | NOT NULL | Type demande RGPD |
| statut             | ENUM('en_attente','en_cours','termine','refuse') | NOT NULL DEFAULT 'en_attente' | État |
| raison_refus       | TEXT         | NULLABLE                    | Raison si refusé |
| fichier_export_url | TEXT         | NULLABLE                    | URL export données |
| date_demande       | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date demande |
| date_traitement    | DATETIME     | NULLABLE                    | Date traitement |
| traite_par         | INT (FK)     | NULLABLE, FK → utilisateurs.id_utilisateur | DPO/dirigeant |

**Index recommandés :**
- INDEX idx_user ON demandes_suppression_donnees(id_utilisateur)
- INDEX idx_statut ON demandes_suppression_donnees(statut)

**NOTE CDC :** Table ajoutée pour droit à l'oubli + export données.

---

### Table : archives

| Champ            | Type         | Contrainte                  | Description |
|------------------|--------------|-----------------------------|-------------|
| id_archive       | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| entite           | VARCHAR(100) | NOT NULL                    | Table archivée |
| id_entite        | INT          | NOT NULL                    | ID original |
| donnees          | JSON         | NOT NULL                    | Snapshot données (JSON) |
| date_archive     | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date snapshot |
| version          | INT          | NOT NULL DEFAULT 1          | Version snapshot |
| raison           | VARCHAR(255) | NULLABLE                    | Raison archivage |
| archive_par      | INT (FK)     | NULLABLE, FK → utilisateurs.id_utilisateur | Archiveur |

**Index recommandés :**
- INDEX idx_entite ON archives(entite, id_entite)
- INDEX idx_date ON archives(date_archive)

**NOTE :** Snapshot léger pour archivage saisons.

---

## TABLES SYSTÈME

### Table : parametres_systeme

| Champ              | Type         | Contrainte                  | Description |
|--------------------|--------------|-----------------------------|-------------|
| id_parametre       | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| cle                | VARCHAR(100) | UNIQUE, NOT NULL            | Clé paramètre |
| valeur             | TEXT         | NOT NULL                    | Valeur paramètre |
| type_valeur        | ENUM('string','int','bool','json') | NOT NULL | Type de données |
| description        | TEXT         | NULLABLE                    | Description paramètre |
| modifiable         | BOOLEAN      | DEFAULT TRUE                | Paramètre modifiable |
| updated_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Dernière MAJ |

**UNIQUE INDEX :** idx_cle ON parametres_systeme(cle)

**NOTE CDC :** Table ajoutée pour configuration système.

---

### Table : versions_app

| Champ              | Type         | Contrainte                  | Description |
|--------------------|--------------|-----------------------------|-------------|
| id_version         | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| plateforme         | ENUM('ios','android','web') | NOT NULL | Plateforme |
| version            | VARCHAR(20)  | NOT NULL                    | Numéro version (ex: 1.2.3) |
| version_min        | VARCHAR(20)  | NULLABLE                    | Version minimum requise |
| notes_version      | TEXT         | NULLABLE                    | Release notes |
| obligatoire        | BOOLEAN      | DEFAULT FALSE               | Mise à jour obligatoire |
| url_telechargement | TEXT         | NULLABLE                    | URL download |
| date_sortie        | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date de sortie |

**Index recommandés :**
- INDEX idx_plateforme ON versions_app(plateforme)

**NOTE CDC :** Table ajoutée pour versioning applications.

---

### Table : file_uploads

| Champ              | Type         | Contrainte                  | Description |
|--------------------|--------------|-----------------------------|-------------|
| id_upload          | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur     | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Uploader |
| fichier_url        | TEXT         | NOT NULL                    | URL stockage (S3) |
| fichier_nom        | VARCHAR(255) | NOT NULL                    | Nom fichier |
| fichier_type       | VARCHAR(50)  | NOT NULL                    | Type MIME |
| fichier_taille     | INT          | NOT NULL                    | Taille en octets |
| reference_type     | VARCHAR(50)  | NULLABLE                    | Type entité liée (match, event...) |
| reference_id       | INT          | NULLABLE                    | ID entité liée |
| statut             | ENUM('uploading','completed','failed') | NOT NULL DEFAULT 'uploading' | Statut upload |
| created_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date upload |

**Index recommandés :**
- INDEX idx_reference ON file_uploads(reference_type, reference_id)
- INDEX idx_user ON file_uploads(id_utilisateur)

**NOTE CDC :** Table transversale pour gestion uploads fichiers.

---

### Table : queue_synchronisation

| Champ              | Type         | Contrainte                  | Description |
|--------------------|--------------|-----------------------------|-------------|
| id_queue           | INT (PK)     | AUTO_INCREMENT, UNIQUE, NOT NULL | Identifiant unique |
| id_utilisateur     | INT (FK)     | NOT NULL, FK → utilisateurs.id_utilisateur | Utilisateur |
| action_type        | VARCHAR(50)  | NOT NULL                    | Type action (create_match, update_stat...) |
| payload            | JSON         | NOT NULL                    | Données action |
| statut             | ENUM('pending','synced','error') | NOT NULL DEFAULT 'pending' | État synchro |
| tentatives         | INT          | DEFAULT 0                   | Nombre tentatives |
| message_erreur     | TEXT         | NULLABLE                    | Message erreur |
| created_at         | DATETIME     | DEFAULT CURRENT_TIMESTAMP   | Date création |
| synced_at          | DATETIME     | NULLABLE                    | Date synchro |

**Index recommandés :**
- INDEX idx_user_statut ON queue_synchronisation(id_utilisateur, statut)
- INDEX idx_created ON queue_synchronisation(created_at)

**NOTE CDC :** Table ajoutée pour mode hors-ligne (synchronisation différée).

---

## Changelog

### Modifications apportées au dictionnaire (2026-01-20)

#### 1. AUTHENTIFICATION & SÉCURITÉ
- ✅ **utilisateurs** : Ajout `consentement_rgpd`, `consentement_image`, `consentement_date`, `double_auth_actif`, `created_at`, `updated_at`, `deleted_at` (soft delete)
- ✅ **roles** : Ajout `niveau_acces` + rôles `parent` et `employe`
- ➕ **sessions_utilisateurs** : Table ajoutée pour gestion JWT, multi-devices, sécurité
- ➕ **logs_connexion** : Ajout `statut` (reussie/echec/bloque) pour audit

#### 2. PROFILS & DONNÉES PERSONNELLES
- ✅ **profils_joueurs** : Ajout `profil_public` (PIRB standalone), `carriere_resume`, `numero_maillot`, `updated_at`
- ➕ **liens_parent_enfant** : Table ajoutée pour relations parent-enfant (autorisations)

#### 3. CLUBS & STRUCTURES
- ✅ **clubs_officiels** : Ajout `adresse`, `adresse_salle`, `ffbb_verifie`, `date_verification`, `updated_at`
- ✅ **clubs_utilisateurs** : Ajout `statut` rejete, `valide_par`, `date_validation`, `date_fin` pour workflow validation multi-clubs

#### 4. ÉQUIPES & CATÉGORIES
- ➕ **categories** : Table ajoutée pour référentiel catégories FFBB (U11, U13, U15...)
- ✅ **equipes** : Ajout `id_categorie` (FK), `statut` brouillon, `updated_at`
- ➕ **equipes_joueurs** : Table ajoutée pour composition équipes + historique

#### 5. PLANNING & ÉVÉNEMENTS
- ✅ **evenements** : Ajout `id_equipe`, `visibilite` equipe, `slots_benevoles`, `statut` en_cours, `updated_at`
- ➕ **convocations** : Table ajoutée pour gestion convocations + réponses présence
- ➕ **presences** : Table ajoutée pour présences effectives (distinctes des convocations)
- ➕ **autorisations_parentales** : Table ajoutée pour workflow autorisations

#### 6. MATCHS & STATISTIQUES
- ✅ **matchs** : Ajout `id_evenement`, `id_club_adverse`, `competition`, `id_competition_ffbb`, `code_match_ffbb`, `domicile_exterieur`, `feuille_validee`, `valide_par`, `date_validation`, `emarque_importe`, `updated_at`
- ➕ **feuilles_match** : Table ajoutée pour en-tête feuille + workflow validation
- ✅ **stats_matchs** : Ajout `id_feuille` (FK), `tentatives_*` pour pourcentages, `evaluation`, `verifie`, `updated_at`
- ✅ **stats_quart_temps** : Ajout `tentatives_*`, CONSTRAINT UNIQUE (id_stat, periode)
- ✅ **stats_mi_temps** : Ajout `tentatives_*`, CONSTRAINT UNIQUE (id_stat, periode)
- ✅ **import_emarque** : Ajout `format_source`, `statut_import`, `message_erreur`, `nb_stats_importees`

#### 7. PIRB - STATS PERSONNELLES
- ➕ **objectifs_joueurs** : Table ajoutée pour objectifs personnels PIRB
- ✅ **stats_entrainement** : Ajout `duree_minutes`, `partage_coach`
- ✅ **stats_entrainement_tirs/dribbles/passes** : Pourcentage calculé automatiquement (GENERATED COLUMN)

#### 8. ENT (Espace Numérique de Travail)
- Tables **ent_periodes**, **ent_competences**, **ent_bilans_joueurs**, **ent_notes_competences** : Conservées telles quelles

#### 9. NOTIFICATIONS
- ✅ **notifications** : Ajout `titre`, `data_payload`, `lue`, `canal_envoye`
- ✅ **preferences_notifications** : Renommé depuis `preferences_utilisateur`, ajout `type_notif`, `updated_at`

#### 10. MESSAGERIE
- ➕ **conversations** : Table ajoutée pour messagerie interne
- ➕ **participants_conversation** : Table ajoutée pour gestion participants
- ➕ **messages** : Table ajoutée pour messages + modération
- ➕ **messages_pieces_jointes** : Table ajoutée pour pièces jointes
- ➕ **messages_lecture** : Table ajoutée pour accusés de lecture

#### 11. GAMIFICATION
- Tables **medailles**, **utilisateurs_medailles**, **badges**, **utilisateurs_badges**, **classements_*** : Conservées + ajout `updated_at`

#### 12. LABELS & PARTENAIRES
- ✅ **partenaires** : Ajout `site_web`, `email_contact`, `statut`
- ✅ **offres_partenaires** : Ajout `code_promo`
- ✅ **coupons** : Ajout `date_expiration`

#### 13. SÉANCES & FORMATIONS
- Tables **seances**, **feedback_seances** : Conservées + ajout `updated_at`

#### 14. DOCUMENTS & MÉDIAS
- ➕ **documents** : Table ajoutée pour gestion documents ENT
- ➕ **actualites** : Table ajoutée pour actualités club
- ➕ **commentaires_actualites** : Table ajoutée pour commentaires + modération
- ✅ **media** : Ajout `titre`, `description`

#### 15. SONDAGES & VOTES
- ✅ **sondages** : Ajout `options` (JSON), `anonyme`, `statut`, `cree_par`
- ✅ **votes** : Ajout `note` pour sondages notation

#### 16. SANCTIONS & MODÉRATION
- ✅ **sanctions** : Ajout `emise_par`

#### 17. IMPORT & SYNCHRONISATION FFBB
- ✅ **import_logs** : Ajout type `calendrier_ffbb`, `nb_lignes_traitees`, `nb_lignes_succes`, `nb_lignes_erreurs`
- ➕ **licences_ffbb** : Table ajoutée pour gestion licences FFBB
- ➕ **competitions_ffbb** : Table ajoutée pour référentiel compétitions FFBB

#### 18. AUDIT & RGPD
- Table **historique_actions** : Conservée
- ➕ **consentements_rgpd** : Table ajoutée pour traçabilité RGPD
- ➕ **demandes_suppression_donnees** : Table ajoutée pour droit à l'oubli + export données
- Table **archives** : Conservée

#### 19. SYSTÈME
- ➕ **parametres_systeme** : Table ajoutée pour configuration système
- ➕ **versions_app** : Table ajoutée pour versioning applications
- ➕ **file_uploads** : Table transversale pour gestion uploads fichiers
- ➕ **queue_synchronisation** : Table ajoutée pour mode hors-ligne (synchronisation différée)

---

## Gaps détectés dans le code

### CONSTAT : Projet en phase de conception uniquement

Le projet ne contient **aucun code source** (Backend, Frontend, Sql vides). Le dictionnaire existant était basé sur :
- Schémas de conception (fichiers .loo - LOOgiciel)
- Cahier des charges fonctionnel

### Tables du dictionnaire initial : COUVERTURE PARTIELLE

**✅ Tables présentes et conformes :**
- utilisateurs, roles, profils_joueurs
- clubs_officiels, clubs_utilisateurs
- equipes, matchs
- stats_matchs, stats_quart_temps, stats_mi_temps
- import_emarque, import_logs
- stats_entrainement, stats_entrainement_tirs/dribbles/passes
- evenements, notifications, preferences_utilisateur
- medailles, utilisateurs_medailles, badges, utilisateurs_badges
- classements_*, labels_ffbb, clubs_labels
- partenaires, offres_partenaires, visites_partenaires, coupons
- seances, feedback_seances, media
- ent_periodes, ent_competences, ent_bilans_joueurs, ent_notes_competences
- liens_parent_enfant, autorisations_parentales
- logs_connexion, sanctions, sondages, votes
- sessions_utilisateurs, historique_actions, archives

**❌ Tables MANQUANTES (ajoutées dans cette version) :**

1. **Authentification & Sécurité :**
   - ❌ `sessions_utilisateurs` (gestion JWT, multi-devices)
   - ❌ `consentements_rgpd` (traçabilité RGPD)
   - ❌ `demandes_suppression_donnees` (droit à l'oubli)

2. **Équipes & Planning :**
   - ❌ `categories` (référentiel catégories FFBB)
   - ❌ `equipes_joueurs` (composition équipes + historique)
   - ❌ `convocations` (gestion convocations + réponses)
   - ❌ `presences` (présences effectives vs convocations)

3. **Matchs :**
   - ❌ `feuilles_match` (en-tête feuille + workflow validation)
   - ❌ `competitions_ffbb` (référentiel compétitions)
   - ❌ `licences_ffbb` (gestion licences FFBB)

4. **PIRB :**
   - ❌ `objectifs_joueurs` (objectifs personnels PIRB)

5. **Messagerie (COMPLÈTEMENT ABSENTE) :**
   - ❌ `conversations`
   - ❌ `participants_conversation`
   - ❌ `messages`
   - ❌ `messages_pieces_jointes`
   - ❌ `messages_lecture`

6. **ENT & Documents :**
   - ❌ `documents` (gestion documents ENT)
   - ❌ `actualites` (actualités club)
   - ❌ `commentaires_actualites` (commentaires + modération)

7. **Système :**
   - ❌ `parametres_systeme` (configuration système)
   - ❌ `versions_app` (versioning applications)
   - ❌ `file_uploads` (gestion uploads transversale)
   - ❌ `queue_synchronisation` (mode hors-ligne)

### Champs manquants dans tables existantes

**utilisateurs :**
- ❌ `consentement_rgpd`, `consentement_image`, `consentement_date`
- ❌ `double_auth_actif` (2FA dirigeants)
- ❌ `created_at`, `updated_at`, `deleted_at` (soft delete)

**clubs_officiels :**
- ❌ `adresse`, `adresse_salle`
- ❌ `ffbb_verifie`, `date_verification`
- ❌ `updated_at`

**clubs_utilisateurs :**
- ❌ `statut` 'rejete'
- ❌ `valide_par`, `date_validation`, `date_fin`

**matchs :**
- ❌ `id_evenement`, `id_club_adverse`, `competition`, `code_match_ffbb`, `domicile_exterieur`
- ❌ `feuille_validee`, `valide_par`, `date_validation`
- ❌ `updated_at`

**stats_matchs :**
- ❌ `id_feuille` (FK feuilles_match)
- ❌ `tentatives_*` (pour calcul pourcentages)
- ❌ `evaluation`, `verifie`
- ❌ `updated_at`

**notifications :**
- ❌ `titre`, `data_payload`, `lue`, `canal_envoye`

**media :**
- ❌ `titre`, `description`

### Gaps fonctionnels vs Cahier des Charges

**✅ COUVERT (100%) :**
- Auth multi-profils + multi-clubs + validation
- Rôles & permissions
- Équipes & catégories
- Planning événements + convocations + présences
- Matchs + feuille match + stats + import e-Marque
- PIRB stats entrainement + objectifs
- ENT bilans + compétences
- Notifications + préférences
- Gamification (médailles, badges, classements)
- Partenaires + offres
- Import FFBB (licences, clubs, calendriers)
- Audit & RGPD

**✅ COUVERT (fonctionnalités ajoutées dans cette version) :**
- Messagerie interne complète (conversations, participants, messages, lecture)
- Documents ENT + actualités + commentaires modérés
- Autorisations parentales workflow complet
- Soft delete généralisé
- Mode hors-ligne (queue synchronisation)
- Versioning applications
- Gestion uploads transversale

**✅ GAPS RÉSOLUS :**
- ✅ Tous les besoins du CDC sont couverts
- ✅ Architecture évolutive V1 → V2 → V3
- ✅ Multi-tenant (isolation par `id_club`)
- ✅ RGPD complet (consentements, droit à l'oubli, audit)
- ✅ Sécurité (2FA, sessions, logs)
- ✅ Mode hors-ligne (synchronisation différée)

---

## Notes d'implémentation

### 1. Nommage & Conventions
- ✅ **snake_case** pour tous les champs
- ✅ PK : `id_xxx` (AUTO_INCREMENT)
- ✅ FK : `id_xxx` pointant vers `table.id_xxx`
- ✅ Timestamps : `created_at`, `updated_at`, `deleted_at`
- ✅ Soft delete : `deleted_at IS NULL` = actif

### 2. Index recommandés
- ✅ INDEX sur toutes les FK
- ✅ UNIQUE INDEX sur contraintes uniques
- ✅ INDEX composites sur requêtes fréquentes (ex: `id_club, statut`)
- ✅ INDEX sur dates pour recherches temporelles

### 3. Contraintes d'intégrité
- ✅ ON DELETE CASCADE : sessions, messages, participants
- ✅ ON DELETE SET NULL : références optionnelles
- ✅ CHECK constraints : notes (1-10), pourcentages, dates

### 4. Champs calculés
- ✅ Pourcentages stats : GENERATED ALWAYS AS (calcul) STORED
- ✅ Timestamps : DEFAULT CURRENT_TIMESTAMP, ON UPDATE CURRENT_TIMESTAMP

### 5. JSON recommandé pour
- ✅ `data_payload` (notifications)
- ✅ `details` (historique_actions)
- ✅ `donnees` (archives)
- ✅ `options` (sondages)
- ✅ `visible_roles` (documents)

### 6. ENUM vs Tables de référence
- ✅ ENUM : valeurs fixes métier (statuts, types)
- ✅ Tables : valeurs configurables (catégories, compétences)

### 7. Sécurité
- ✅ Mots de passe : bcrypt/argon2 (jamais en clair)
- ✅ Tokens JWT : stockés dans `sessions_utilisateurs`
- ✅ Fichiers sensibles : S3 avec URL signées temporaires
- ✅ Logs IP + user_agent pour audit

### 8. Performance
- ✅ Partitionnement par saison (tables stats, classements)
- ✅ Cache Redis pour sessions, calendriers
- ✅ Pagination obligatoire sur listes longues
- ✅ Soft delete plutôt que DELETE physique

### 9. RGPD
- ✅ Consentement tracé (IP, date, parent si mineur)
- ✅ Droit à l'oubli : anonymisation ou suppression
- ✅ Export données : JSON généré à la demande
- ✅ Durée conservation : politique à définir par club

### 10. Migration données
- ✅ Import Excel clubs FFBB → `clubs_officiels`
- ✅ Import CSV licences → `licences_ffbb`
- ✅ Import e-Marque PDF → parsing + `stats_matchs`
- ✅ Logs détaillés dans `import_logs`

---

## Prochaines étapes (implémentation)

### Phase 1 : Backend (Node.js + MySQL)
1. Setup ORM (Sequelize/TypeORM/Prisma)
2. Migrations SQL (création tables + index)
3. Seeds (rôles, catégories, compétences par défaut)
4. Modèles + validations
5. API REST (CRUD + business logic)
6. JWT auth + refresh tokens
7. Upload S3
8. WebSocket (messagerie temps réel)

### Phase 2 : Frontend Mobile (React Native)
1. Navigation + authentification
2. Mode hors-ligne (SQLite local + sync queue)
3. Écrans MABB (clubs, équipes, matchs, stats, événements)
4. Écrans PIRB (stats perso, objectifs, progression)
5. Messagerie temps réel
6. Notifications push (Firebase/APNs)
7. Import e-Marque (photo PDF → OCR)

### Phase 3 : Tests & Déploiement
1. Tests unitaires backend
2. Tests E2E mobile
3. CI/CD (GitHub Actions)
4. Déploiement backend (AWS/GCP/Azure)
5. Soumission stores (App Store + Google Play)

---

**FIN DU DICTIONNAIRE**
