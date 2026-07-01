-- ============================================================
-- Tirs MABB — match vs ESC TERGNIER
-- Source : positiontir FFBB (tirs réussis uniquement)
-- Généré le 30/06/2026
-- ============================================================

-- 1. Identifier la rencontre :
--    SELECT id, adversaire, date FROM rencontre WHERE adversaire LIKE '%TERGNIER%';
--    Remplacez la valeur ci-dessous par l'ID trouvé.

SET @rencontre_id = 0; -- ← REMPLACER PAR L'ID DE LA RENCONTRE

-- 2. Supprimer les anciens tirs FFBB pour cette rencontre (idempotent)
DELETE FROM tir_ffbb WHERE rencontre_id = @rencontre_id AND source = 'ffbb';

-- 3. Insérer les 17 tirs réussis
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'BOUABDALLAH' AND LEFT(j.prenom, 1) = 'A' LIMIT 1), 'BOUABDALLAH A.', 8, 41, '2pt_ext', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'MILAPIE' AND LEFT(j.prenom, 1) = 'R' LIMIT 1), 'MILAPIE R.', 25, 48, '2pt_ext', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'MILAPIE' AND LEFT(j.prenom, 1) = 'R' LIMIT 1), 'MILAPIE R.', 10, 55, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'MILAPIE' AND LEFT(j.prenom, 1) = 'R' LIMIT 1), 'MILAPIE R.', 9, 40, '2pt_ext', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'LEFEVRE' AND LEFT(j.prenom, 1) = 'J' LIMIT 1), 'LEFEVRE J.', 9, 41, '2pt_ext', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'LEFEVRE' AND LEFT(j.prenom, 1) = 'J' LIMIT 1), 'LEFEVRE J.', 28, 83, '3pt', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'LEFEVRE' AND LEFT(j.prenom, 1) = 'J' LIMIT 1), 'LEFEVRE J.', 21, 92, '3pt', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 9, 41, '2pt_ext', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 16, 48, '2pt_ext', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 9, 52, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 16, 50, '2pt_ext', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 9, 55, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 17, 69, '2pt_ext', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'EZIORAH' AND LEFT(j.prenom, 1) = 'P' LIMIT 1), 'EZIORAH P.', 9, 36, '2pt_ext', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'EZIORAH' AND LEFT(j.prenom, 1) = 'P' LIMIT 1), 'EZIORAH P.', 9, 40, '2pt_ext', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'DIALLO' AND LEFT(j.prenom, 1) = 'F' LIMIT 1), 'DIALLO F.', 15, 48, '2pt_ext', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'DIALLO' AND LEFT(j.prenom, 1) = 'F' LIMIT 1), 'DIALLO F.', 9, 44, '2pt_int', 1, 'ffbb', NOW();

-- Vérification
SELECT nom_joueuse, type_tir, position_x, position_y FROM tir_ffbb WHERE rencontre_id = @rencontre_id AND source = 'ffbb' ORDER BY nom_joueuse;