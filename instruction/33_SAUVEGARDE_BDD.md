# 33 — Sauvegarde de la base de prod · procédure (Bloc 0)

> 13/07/2026. Le risque n°1 du projet était : AUCUNE sauvegarde. Ce doc pose
> la procédure complète. **Rien d'autre ne compte tant que ce n'est pas actif.**

## 1. Installer (une fois, ~15 min)

1. Déployer `bin/sauvegarde-bdd.sh` (commit + pull sur OVH), puis en SSH :
   `chmod +x ~/www/bin/sauvegarde-bdd.sh`
2. **Tester à la main** : `~/www/bin/sauvegarde-bdd.sh` → doit afficher
   `OK : .../var/backups/bdd_..._.sql.gz (N octets)`.
3. **Cron** : Manager OVH → ton hébergement → « Tâches planifiées » →
   Ajouter → tous les jours à 04h00 → commande :
   `/home/<ton_login>/www/bin/sauvegarde-bdd.sh` (langue : autre/shell).

## 2. La copie HORS OVH (indispensable)

Un backup stocké sur la machine qui peut brûler n'est PAS un backup.
Choix simple retenu : **1 fois par semaine, télécharger le dernier dump en
local** (FileZilla/scp → `var/backups/`) et le poser dans un dossier Drive.
Rappel récurrent à te mettre (dimanche soir, avec le point hebdo).
Automatisation possible plus tard (rclone si dispo sur le mutualisé) — mais
le manuel hebdo suffit pour démarrer, et il est fait en 2 minutes.

## 3. Tester une RESTAURATION (obligatoire, une fois)

Un backup jamais restauré est une prière, pas une sauvegarde. En local :
```bash
gunzip -c bdd_..._.sql.gz | mysql -u root -p une_base_de_test
```
Puis ouvrir la base et VOIR les joueuses. **Fini quand tu as vu les données.**

## 4. Ce que le script fait (résumé)

`mysqldump --single-transaction` (cohérent, ne bloque pas le site), mot de
passe lu depuis `.env.local` (jamais en dur, jamais sur la ligne de commande),
compression + horodatage, alerte si dump < 10 Ko (échec silencieux), rotation
14 jours. Détails commentés dans le script lui-même.

## 5. Monitoring (même mouvement d'hygiène)

Pendant que tu y es : créer un compte **UptimeRobot** (gratuit) → monitor
HTTPS sur `https://pirb.mabb.fr/login` toutes les 5 min → alerte email.
10 minutes, et tu sauras ENFIN si la prod tombe.
