# Systeme de checkpoint - commit d'une etape autorisee.
# N'agit que si l'etat est exactement 'ready_to_commit' pour l'etape demandee.
# Ne fait jamais de git push (aucun code de push n'existe dans ce fichier).

function Invoke-CheckpointCommit {
    param([Parameter(Mandatory = $true)][string]$Step)

    $repoRoot = Get-RepoRoot
    $manifest = Get-StepManifest -Step $Step
    $state = Get-CheckpointState

    if ($state.status -ne 'ready_to_commit' -or $state.pending_step -ne $Step) {
        throw "L'etape '$Step' n'est pas au statut 'ready_to_commit' (etat actuel: $($state.status)). Executez 'validate' puis 'authorize' avant 'commit'."
    }

    $backupEntry = $null
    if ($manifest.requires_db_backup -eq $true) {
        $backupsManifestPath = Join-Path (Get-CheckpointsDir) 'backups\manifest.json'
        $backupsManifest = Read-JsonFile -Path $backupsManifestPath
        $linked = @($backupsManifest.backups | Where-Object { $_.step -eq $Step })
        if ($linked.Count -eq 0) {
            throw "L'etape '$Step' requiert un backup DB (requires_db_backup:true) mais aucun backup n'est enregistre pour cette etape dans checkpoints/backups/manifest.json."
        }
        $backupEntry = $linked[-1]
    }

    $commitSha = $null
    $tagName = "checkpoint/$Step"
    $staged = @()

    Push-Location $repoRoot
    try {
        foreach ($pattern in @($manifest.allowed_paths)) {
            & git add -- $pattern 2>$null | Out-Null
        }

        $staged = @(& git diff --cached --name-only)
        if ($staged.Count -eq 0) {
            throw "Aucun fichier autorise n'est modifie/stage. Rien a committer pour l'etape '$Step'."
        }

        $message = "checkpoint(step-$Step): $($manifest.description)"
        & git commit --quiet -m $message
        if ($LASTEXITCODE -ne 0) { throw "git commit a echoue (exit $LASTEXITCODE)." }

        $commitSha = (& git rev-parse HEAD).Trim()
        & git tag $tagName $commitSha 2>$null
    } finally {
        Pop-Location
    }

    $state.status = 'committed'
    $state.last_committed_step = $Step
    $state.last_commit_sha = $commitSha
    $state.pending_step = $null
    Set-CheckpointState -State $state

    $historyEntry = [ordered]@{
        step      = $Step
        substep   = $manifest.substep
        commit    = $commitSha
        tag       = $tagName
        files     = $staged
        db_backup = $backupEntry
        timestamp = New-Timestamp
        status    = 'committed'
    }
    Write-CheckpointLog -LogEntry $historyEntry | Out-Null

    return [ordered]@{ step = $Step; commit = $commitSha; tag = $tagName; files = $staged }
}
