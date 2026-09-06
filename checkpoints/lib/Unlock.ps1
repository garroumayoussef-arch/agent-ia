# Systeme de checkpoint - deverrouillage d'une etape (locked:true -> locked:false).
# Meme modele de garde que Authorize.ps1 : ce mecanisme ne peut PAS etre
# declenche par un agent en mode non-interactif :
# - refus si l'entree standard est redirigee ;
# - challenge explicite (retaper l'identifiant de l'etape).
# Seule fonction du systeme autorisee a ecrire locked:false dans un manifeste
# d'etape (voir State.ps1::Get-StepManifest, qui rappelle que le manifeste
# doit etre cree/deverrouille manuellement par l'operateur humain - jamais
# par un agent).
# Limite assumee (voir checkpoints/README.md) : sur un poste ou l'agent et
# l'operateur partagent le meme compte/shell, ceci reste un frein, pas une
# garantie cryptographique.
#
# Cablee dans checkpoint.ps1 (sous-commande 'unlock', voir checkpoint.ps1
# lignes 13/34/57-59) depuis le commit 8c59394 (fix(checkpoint): integrate
# unlock command). Invoquee via 'checkpoint.ps1 unlock -Step <id>'.

function Invoke-CheckpointUnlock {
    param([Parameter(Mandatory = $true)][string]$Step)

    if ([Console]::IsInputRedirected) {
        throw "unlock doit etre execute de maniere interactive (entree standard non redirigee). Un appel avec entree pipee/redirigee n'est pas accepte par ce systeme."
    }

    $manifestPath = Get-StepManifestPath -Step $Step
    if (-not (Test-Path -LiteralPath $manifestPath)) {
        throw "Aucun manifeste pour l'etape '$Step'. Creez d'abord checkpoints/steps/$Step.json manuellement."
    }
    $manifest = Read-JsonFile -Path $manifestPath

    if ($manifest.locked -ne $true) {
        throw "L'etape '$Step' n'est pas verrouillee (locked:$($manifest.locked)). Rien a deverrouiller."
    }

    Write-Host ""
    Write-Host "=== DEVERROUILLAGE D'ETAPE ===" -ForegroundColor Yellow
    Write-Host "Etape a deverrouiller : $Step"
    if ($manifest.description) { Write-Host "Description           : $($manifest.description)" }
    Write-Host ""
    $typed = Read-Host "Retapez exactement l'identifiant de l'etape pour confirmer ('$Step')"

    if ($typed -ne $Step) {
        throw "Confirmation invalide (attendu '$Step', recu '$typed'). Deverrouillage annule."
    }

    $manifest.locked = $false
    $manifest.unlocked_by = $env:USERNAME
    $manifest.unlocked_at = (New-Timestamp)
    Write-JsonFile -Path $manifestPath -Object $manifest

    Write-Host "Etape '$Step' deverrouillee par $($env:USERNAME) a $($manifest.unlocked_at)." -ForegroundColor Green
    return $manifest
}
