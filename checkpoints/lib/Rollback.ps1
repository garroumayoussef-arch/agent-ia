# Systeme de checkpoint - rollback controle du code.
# Interactif uniquement (meme logique de challenge que Authorize.ps1).
# Ne restaure PAS la base de donnees : voir checkpoints/README.md, section
# "Procedure de rollback" pour l'ordre recommande avec 'checkpoint.ps1 db -DbAction restore'.

function Invoke-CheckpointRollback {
    param([Parameter(Mandatory = $true)][string]$To)

    if ([Console]::IsInputRedirected) {
        throw "rollback doit etre execute de maniere interactive (entree standard non redirigee)."
    }

    $repoRoot = Get-RepoRoot

    Push-Location $repoRoot
    try {
        & git rev-parse --verify $To 2>$null | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "Cible introuvable: $To" }
    } finally {
        Pop-Location
    }

    Write-Host ""
    Write-Host "=== ROLLBACK ===" -ForegroundColor Yellow
    Write-Host "Cible: $To"
    Write-Host "Cette operation va executer: git reset --hard $To"
    $typed = Read-Host "Retapez exactement la cible pour confirmer ('$To')"
    if ($typed -ne $To) {
        throw "Confirmation invalide. Rollback annule."
    }

    Push-Location $repoRoot
    try {
        & git reset --hard $To
        if ($LASTEXITCODE -ne 0) { throw "git reset --hard a echoue." }
    } finally {
        Pop-Location
    }

    Write-Host "Rollback code effectue vers $To." -ForegroundColor Green
    Write-Host "Rappel: si les donnees doivent aussi etre restaurees, utilisez 'checkpoint.ps1 db -DbAction restore -File <backup>' - voir checkpoints/README.md." -ForegroundColor Yellow
}
