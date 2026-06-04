# =============================================================================
# deploy.ps1 — Déploiement mabb-site sur OVH en 1 commande
# =============================================================================
# Usage (depuis le dossier mabb-site) :
#   .\deploy.ps1 "message de commit"
#
# Ce que ça fait, dans l'ordre :
#   1. Vérifie que tu es sur la branche main.
#   2. Affiche les fichiers modifiés.
#   3. git add -A + git commit + git push origin main  (LOCAL).
#   4. SSH OVH (une seule fois, un seul mot de passe à taper) et lance
#      sur le serveur : git pull + cache:clear --env=prod + asset-map:compile.
#
# Si une étape échoue, le script s'arrête (pas de déploiement à moitié).
# =============================================================================

param(
    [Parameter(Mandatory=$true, Position=0)]
    [string]$Message
)

$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ProjectRoot

Write-Host ""
Write-Host "=== Déploiement MABB ===" -ForegroundColor Cyan
Write-Host "Dossier : $ProjectRoot" -ForegroundColor Gray
Write-Host ""

# 1. Vérif branche
$Branch = (git rev-parse --abbrev-ref HEAD).Trim()
if ($Branch -ne "main") {
    Write-Host "X Tu es sur la branche '$Branch', pas 'main'." -ForegroundColor Red
    Write-Host "   Bascule sur main avant de déployer : git checkout main" -ForegroundColor Yellow
    exit 1
}

# 2. Status
Write-Host "[1/4] Etat Git local :" -ForegroundColor Cyan
git status --short

# 3. Stage + commit
Write-Host ""
Write-Host "[2/4] Commit + push..." -ForegroundColor Cyan
git add -A
$Staged = git diff --cached --name-only
if (-not $Staged) {
    Write-Host "   Aucun changement local à committer. Je passe au push." -ForegroundColor Yellow
} else {
    git commit -m $Message
    if ($LASTEXITCODE -ne 0) {
        Write-Host "X Echec du commit." -ForegroundColor Red
        exit 1
    }
    Write-Host "   OK Commit : $Message" -ForegroundColor Green
}

# 4. Push
git push origin main
if ($LASTEXITCODE -ne 0) {
    Write-Host "X Echec du push GitHub. Verifie tes identifiants GitHub." -ForegroundColor Red
    exit 1
}
Write-Host "   OK Pousse sur origin/main" -ForegroundColor Green

# 5. SSH OVH + commandes serveur (1 seule connexion = 1 seul mot de passe à taper)
Write-Host ""
Write-Host "[3/4] Connexion SSH OVH (tape ton mot de passe OVH quand demande)..." -ForegroundColor Cyan
$RemoteCmd = @"
cd ~/mabb-site && \
( if [ -f .env ] && ! git ls-files --error-unmatch .env > /dev/null 2>&1 ; then \
    if [ ! -s .env.local ] ; then cp .env .env.local && echo 'INFO  .env (overrides serveur) sauvegarde en .env.local' ; \
    else echo 'INFO  .env.local existe deja, on touche pas' ; fi ; \
    mv .env .env.serveur-backup-`date +%Y%m%d-%H%M%S` && echo 'INFO  .env serveur archive' ; \
  fi ) && \
git pull && \
echo '=== COMPOSER INSTALL ===' && \
composer install --no-dev --optimize-autoloader --no-interaction && \
php bin/console cache:clear --env=prod --no-warmup && \
echo '=== MIGRATIONS DOCTRINE ===' && \
php bin/console doctrine:migrations:migrate --no-interaction --env=prod --allow-no-migration && \
php bin/console asset-map:compile && \
echo 'OK deploiement OVH termine'
"@

ssh mabbzzyo@ssh.cluster102.hosting.ovh.net $RemoteCmd

if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "X Echec SSH ou commande distante (code $LASTEXITCODE)." -ForegroundColor Red
    Write-Host "   Causes frequentes :" -ForegroundColor Yellow
    Write-Host "   - Mot de passe OVH faux (re-essaie)" -ForegroundColor Yellow
    Write-Host "   - Le serveur a refuse apres trop de tentatives (attends 5 min)" -ForegroundColor Yellow
    Write-Host "   - Conflits sur git pull (modifs locales sur le serveur ? git stash)" -ForegroundColor Yellow
    exit 1
}

Write-Host ""
Write-Host "[4/4] OK Deploiement termine." -ForegroundColor Green
Write-Host ""
Write-Host "==> Va sur www.mabb.fr et fais Ctrl+F5 pour voir le resultat." -ForegroundColor Cyan
Write-Host ""
