-- ============================================================
-- Tirs MABB — match vs CHEMINOTS AMIENS SUD BB
-- Source : positiontir FFBB (tirs réussis uniquement)
-- Généré le 30/06/2026
-- ============================================================

-- 1. Identifier la rencontre :
--    SELECT id, adversaire, date FROM rencontre WHERE adversaire LIKE '%CHEMINOT%';
--    Remplacez la valeur ci-dessous par l'ID trouvé.

SET @rencontre_id = 0; -- ← REMPLACER PAR L'ID DE LA RENCONTRE

-- 2. Supprimer les anciens tirs FFBB pour cette rencontre (idempotent)
DELETE FROM tir_ffbb WHERE rencontre_id = @rencontre_id AND source = 'ffbb';

-- 3. Insérer les 29 tirs réussis
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'BEN SALAH' AND LEFT(j.prenom, 1) = 'O' LIMIT 1), 'BEN SALAH O.', 10, 8, '3pt', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'BEN SALAH' AND LEFT(j.prenom, 1) = 'O' LIMIT 1), 'BEN SALAH O.', 27, 33, '3pt', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'GUELFAT' AND LEFT(j.prenom, 1) = 'C' LIMIT 1), 'GUELFAT C.', 13, 22, '3pt', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'BOUABDALLAH' AND LEFT(j.prenom, 1) = 'A' LIMIT 1), 'BOUABDALLAH A.', 13, 36, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'BOUABDALLAH' AND LEFT(j.prenom, 1) = 'A' LIMIT 1), 'BOUABDALLAH A.', 25, 45, '2pt_ext', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'DESMIS' AND LEFT(j.prenom, 1) = 'H' LIMIT 1), 'DESMIS H.', 10, 23, '3pt', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'DESMIS' AND LEFT(j.prenom, 1) = 'H' LIMIT 1), 'DESMIS H.', 10, 20, '3pt', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'DESMIS' AND LEFT(j.prenom, 1) = 'H' LIMIT 1), 'DESMIS H.', 13, 62, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'MILAPIE' AND LEFT(j.prenom, 1) = 'R' LIMIT 1), 'MILAPIE R.', 12, 0, '3pt', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'MILAPIE' AND LEFT(j.prenom, 1) = 'R' LIMIT 1), 'MILAPIE R.', 15, 39, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'MILAPIE' AND LEFT(j.prenom, 1) = 'R' LIMIT 1), 'MILAPIE R.', 10, 57, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'MILAPIE' AND LEFT(j.prenom, 1) = 'R' LIMIT 1), 'MILAPIE R.', 14, 51, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'LEFEVRE' AND LEFT(j.prenom, 1) = 'J' LIMIT 1), 'LEFEVRE J.', 10, 36, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'LEFEVRE' AND LEFT(j.prenom, 1) = 'J' LIMIT 1), 'LEFEVRE J.', 13, 44, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'LEFEVRE' AND LEFT(j.prenom, 1) = 'J' LIMIT 1), 'LEFEVRE J.', 15, 53, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'LEFEVRE' AND LEFT(j.prenom, 1) = 'J' LIMIT 1), 'LEFEVRE J.', 27, 11, '3pt', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'LEFEVRE' AND LEFT(j.prenom, 1) = 'J' LIMIT 1), 'LEFEVRE J.', 31, 81, '3pt', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'LEFEVRE' AND LEFT(j.prenom, 1) = 'J' LIMIT 1), 'LEFEVRE J.', 9, 60, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 13, 58, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 13, 59, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 12, 58, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 12, 38, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 12, 57, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 14, 46, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 12, 49, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SANO' AND LEFT(j.prenom, 1) = 'S' LIMIT 1), 'SANO S.', 12, 34, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SBEGLARYAN' AND LEFT(j.prenom, 1) = 'Y' LIMIT 1), 'SBEGLARYAN Y.', 13, 56, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'SBEGLARYAN' AND LEFT(j.prenom, 1) = 'Y' LIMIT 1), 'SBEGLARYAN Y.', 15, 41, '2pt_int', 1, 'ffbb', NOW();
INSERT INTO tir_ffbb (rencontre_id, joueur_id, nom_joueuse, position_x, position_y, type_tir, est_reussi, source, created_at) SELECT @rencontre_id, (SELECT j.id FROM joueur j WHERE j.nom = 'DIALLO' AND LEFT(j.prenom, 1) = 'F' LIMIT 1), 'DIALLO F.', 16, 51, '2pt_int', 1, 'ffbb', NOW();

-- Vérification
SELECT nom_joueuse, type_tir, position_x, position_y FROM tir_ffbb WHERE rencontre_id = @rencontre_id AND source = 'ffbb' ORDER BY nom_joueuse;