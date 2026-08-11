# 36 — Toggle Stats Live / FFBB sur la fiche joueuse · 26 juillet 2026

## Le problème
La fiche joueuse mélangeait dans UNE seule moyenne des évals de trois
provenances : imports FFBB (OCR des PDF — points, minutes, LF, fautes
seulement), saisies manuelles du coach, et agrégations Stats Live. Une éval
FFBB, incomplète par nature (rebonds/passes/tirs tentés absents), tirait
l'éval FIBA vers le bas. Le bandeau « première version » avouait le flou
sans le résoudre.

## La solution (V2.4p)
Deux vues séparées, un clic pour basculer, aucun mélange :
- **⚡ Stats Live** : évals `live` + `manuel` (données riches produites par
  le club). Tuiles complètes, dropdown par équipe, tableau des derniers matchs.
- **📋 FFBB** : évals `ffbb` uniquement. Tuiles honnêtes (pts/match,
  min/match, nb matchs) et tableau Min/Pts/LF/Fautes — on n'affiche PAS une
  éval FIBA qu'on ne peut pas calculer.

Vue par défaut : Stats Live si elle a des données, sinon FFBB.

## Comment
- `EvaluationMatch.source` (`live` / `ffbb` / `manuel`, défaut `manuel`) +
  constantes et `SOURCES_CLUB = [live, manuel]`.
- Migration `Version20260726120000` : colonne + backfill (marqueur
  « OCR FFBB import » dans notes_coach → `ffbb` ; paire joueur×rencontre
  ayant des ActionMatch → `live`, qui gagne sur ffbb).
- Les 4 écrivains stampent leur source ; règle commune : on ne RÉTROGRADE
  jamais une éval `live` (l'import FFBB ou le coach ne font alors que
  compléter).
- `evaluationsSaison` / `evaluationsRecentes` / `moyennesSaison` : paramètre
  optionnel `$sources` (null = comportement historique, rien ne casse).
- `JoueurController::show` calcule les deux agrégats ; le template affiche
  le toggle (JS pur, zéro rechargement).

## Reste à faire (plus tard)
- Côté app (PirbStatsController / JoueurStatsAggregator) : toujours toutes
  sources confondues. À aligner quand on refera l'écran stats de l'app.
- Le même toggle sur la page rencontre (comparaison match par match
  EvaluationFfbb vs EvaluationMatch, prévu par B22b).

## Déploiement
`git pull` + `php bin/console doctrine:migrations:migrate` (une colonne +
2 UPDATE, rapide) + `cache:clear`. Idempotent, down() supprime la colonne.
