# Systeme de checkpoint - cloture d'une etape "validation-only".
#
# Reservee aux etapes dont le manifeste declare allowed_paths: [] (aucun
# fichier de production/test a committer - ex. une etape d'analyse/
# validation de contrat, sans modification metier). 'commit' (Commit.ps1)
# continue de refuser ces etapes par construction (rien a 'git add'),
# comportement volontairement CONSERVE : 'close' ne remplace 'commit' que
# pour ce cas precis, jamais pour une etape qui a reellement du code a
# committer.
#
# Ne cree JAMAIS de commit Git ni de tag : contrairement a 'commit',
# aucun objet Git verifiable a posteriori n'existe pour une cloture
# validation-only. checkpoints/state.json distingue explicitement :
#   - last_committed_step / last_commit_sha : reserves EXCLUSIVEMENT au
#     dernier VRAI commit Git (ecrits par Commit.ps1/le hook post-commit,
#     jamais par close) ;
#   - last_closed_step / last_closed_step_type ('commit' | 'validation_only') :
#     derniere etape cloturee par le systeme, par n'importe quel moyen -
#     mis a jour a la fois par 'commit' (type 'commit') et par 'close'
#     (type 'validation_only'), pour qu'aucun des deux champs ne devienne
#     jamais perime/ambigu au fil des etapes suivantes.
#
# Deux fonctions :
# - Close-CheckpointStep : mutation d'etat pure (state.json + log), sans
#   aucune verification d'interactivite - reutilisable telle quelle par
#   les tests automatises (meme principe que la separation
#   validate/authorize/commit : Commit.ps1 lui-meme n'est pas interactif,
#   toute l'interactivite vit dans Authorize.ps1).
# - Invoke-CheckpointClose : point d'entree CLI ('checkpoint.ps1 close'),
#   verifie les preconditions metier PUIS exige une confirmation
#   interactive (meme garde-fou de re-saisie de l'identifiant de l'etape
#   qu'Authorize.ps1) avant d'appeler Close-CheckpointStep. Les
#   verifications metier passent volontairement AVANT le controle
#   d'interactivite : cela les rend exercables par un harnais de test
#   automatise, alors que le succes complet de cette fonction (comme celui
#   d'Authorize.ps1) exige une vraie session interactive et ne peut, par
#   nature, pas etre reproduit par un tel harnais.

function Close-CheckpointStep {
    param(
        [Parameter(Mandatory = $true)][string]$Step,
        [Parameter(Mandatory = $true)]$Manifest,
        [Parameter(Mandatory = $true)]$State
    )

    $repoRoot = Get-RepoRoot
    Push-Location $repoRoot
    try {
        $currentSha = (& git rev-parse HEAD).Trim()
    } finally { Pop-Location }

    # last_committed_step / last_commit_sha : JAMAIS touches ici. Ils
    # restent strictement reserves au dernier VRAI commit Git - close() ne
    # cree aucun commit, donc ne doit jamais leur substituer une valeur,
    # meme le SHA courant de HEAD (qui appartient a l'etape PRECEDENTE,
    # deja reellement commitee, pas a celle-ci).
    $State.status = 'closed_validation_only'
    $State.pending_step = $null
    $State | Add-Member -NotePropertyName 'last_closed_step' -NotePropertyValue $Step -Force
    $State | Add-Member -NotePropertyName 'last_closed_step_type' -NotePropertyValue 'validation_only' -Force
    Set-CheckpointState -State $State

    $historyEntry = [ordered]@{
        step                = $Step
        substep             = $Manifest.substep
        commit              = $null
        tag                 = $null
        closure_type        = 'validation_only'
        head_sha_at_closure = $currentSha
        timestamp           = New-Timestamp
        status              = 'closed_validation_only'
    }
    Write-CheckpointLog -LogEntry $historyEntry | Out-Null

    return [ordered]@{ step = $Step; closure_type = 'validation_only'; head_sha_at_closure = $currentSha }
}

function Invoke-CheckpointClose {
    param([Parameter(Mandatory = $true)][string]$Step)

    $manifest = Get-StepManifest -Step $Step
    $state = Get-CheckpointState

    $allowedPaths = @($manifest.allowed_paths)
    if ($allowedPaths.Count -gt 0) {
        throw "L'etape '$Step' declare des allowed_paths non vides ($($allowedPaths.Count) chemin(s)) : ce n'est pas une etape validation-only. Utilisez 'checkpoint.ps1 commit -Step $Step', jamais 'close'."
    }

    if ($state.pending_step -ne $Step) {
        throw "L'etape '$Step' n'est pas l'etape en attente actuelle (pending_step actuel: '$($state.pending_step)'). Executez d'abord 'validate' puis 'authorize' pour '$Step'."
    }

    if ($state.status -eq 'validated') {
        throw "L'etape '$Step' est validee mais pas encore autorisee. Executez 'authorize -Step $Step' avant 'close'."
    }

    if ($state.status -ne 'ready_to_commit') {
        throw "L'etape '$Step' n'est pas au statut 'ready_to_commit' (statut actuel: '$($state.status)'). Executez 'validate' puis 'authorize' avant 'close'."
    }

    if ([Console]::IsInputRedirected) {
        throw "close doit etre execute de maniere interactive (entree standard non redirigee). Un appel avec entree pipee/redirigee n'est pas accepte par ce systeme."
    }

    Write-Host ""
    Write-Host "=== CLOTURE VALIDATION-ONLY (aucun commit Git ne sera cree) ===" -ForegroundColor Yellow
    Write-Host "Etape a cloturer : $Step"
    if ($manifest.description) { Write-Host "Description       : $($manifest.description)" }
    Write-Host ""
    $typed = Read-Host "Retapez exactement l'identifiant de l'etape pour confirmer la cloture SANS commit ('$Step')"

    if ($typed -ne $Step) {
        throw "Confirmation invalide (attendu '$Step', recu '$typed'). Cloture annulee."
    }

    $result = Close-CheckpointStep -Step $Step -Manifest $manifest -State $state

    Write-Host "Etape '$Step' cloturee (validation-only, aucun commit Git cree)." -ForegroundColor Green
    return $result
}
