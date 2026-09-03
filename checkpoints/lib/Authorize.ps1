# Systeme de checkpoint - autorisation d'une etape.
# Ce mecanisme ne peut PAS etre declenche par un agent en mode non-interactif :
# - refus si l'entree standard est redirigee ;
# - challenge explicite (retaper l'identifiant de l'etape).
# Limite assumee (voir checkpoints/README.md) : sur un poste ou l'agent et
# l'operateur partagent le meme compte/shell, ceci reste un frein, pas une
# garantie cryptographique.

function Invoke-CheckpointAuthorize {
    param([Parameter(Mandatory = $true)][string]$Step)

    if ([Console]::IsInputRedirected) {
        throw "authorize doit etre execute de maniere interactive (entree standard non redirigee). Un appel avec entree pipee/redirigee n'est pas accepte par ce systeme."
    }

    $manifestPath = Get-StepManifestPath -Step $Step
    if (-not (Test-Path -LiteralPath $manifestPath)) {
        throw "Aucun manifeste pour l'etape '$Step'. Creez d'abord checkpoints/steps/$Step.json manuellement."
    }
    $manifest = Read-JsonFile -Path $manifestPath

    $state = Get-CheckpointState
    if ($state.status -ne 'validated' -or $state.pending_step -ne $Step) {
        throw "L'etape '$Step' n'est pas au statut 'validated' (etat actuel: $($state.status), etape en attente: $($state.pending_step)). Executez d'abord 'validate' avec succes."
    }

    Write-Host ""
    Write-Host "=== AUTORISATION D'ETAPE ===" -ForegroundColor Yellow
    Write-Host "Etape a autoriser : $Step"
    if ($manifest.description) { Write-Host "Description       : $($manifest.description)" }
    Write-Host ""
    $typed = Read-Host "Retapez exactement l'identifiant de l'etape pour confirmer ('$Step')"

    if ($typed -ne $Step) {
        throw "Confirmation invalide (attendu '$Step', recu '$typed'). Autorisation annulee."
    }

    $state.status = 'ready_to_commit'
    $state.pending_step = $Step
    $state.authorized_by = $env:USERNAME
    $state.authorized_at = (New-Timestamp)
    Set-CheckpointState -State $state

    Write-Host "Etape '$Step' autorisee par $($env:USERNAME) a $($state.authorized_at)." -ForegroundColor Green
    return $state
}
