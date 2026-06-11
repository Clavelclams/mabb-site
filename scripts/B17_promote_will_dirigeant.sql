-- ============================================================================
-- B17 — Promouvoir will@mabb.fr en DIRIGEANT du club MABB
-- ============================================================================
-- À lancer une seule fois en local + en prod via phpMyAdmin OVH.
-- Vérifications préalables (à exécuter chacune avant le INSERT) :
--
-- 1) Vérifie que l'user existe
SELECT id, email, prenom, nom, is_active FROM `user` WHERE email = 'will@mabb.fr';
--
-- 2) Vérifie que le club MABB existe (identifie son id)
SELECT id, nom FROM club WHERE nom LIKE '%MABB%' OR slug = 'mabb';
--
-- 3) Vérifie qu'il n'a PAS déjà ce rôle (sinon UNIQUE va planter)
SELECT * FROM user_club_role
WHERE user_id = (SELECT id FROM `user` WHERE email = 'will@mabb.fr')
  AND club_id = (SELECT id FROM club WHERE nom LIKE '%MABB%' LIMIT 1);
--
-- ============================================================================
-- INSERT effectif (à exécuter seulement après les 3 vérifs ci-dessus)
-- ============================================================================

INSERT INTO user_club_role (user_id, club_id, role, statut, created_at, updated_at)
SELECT
    u.id        AS user_id,
    c.id        AS club_id,
    'DIRIGEANT' AS role,
    'active'    AS statut,
    NOW()       AS created_at,
    NOW()       AS updated_at
FROM `user` u, club c
WHERE u.email = 'will@mabb.fr'
  AND c.nom LIKE '%MABB%'
LIMIT 1;

-- Vérification post-INSERT :
SELECT u.email, ucr.role, ucr.statut, c.nom AS club
FROM user_club_role ucr
JOIN `user` u ON u.id = ucr.user_id
JOIN club c ON c.id = ucr.club_id
WHERE u.email = 'will@mabb.fr';
