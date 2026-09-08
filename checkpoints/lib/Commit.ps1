# Systeme de checkpoint - commit d'une etape autorisee.
# N'agit que si l'etat est exactement 'ready_to_commit' pour l'etape demandee.
# Ne fait jamais de git push (aucun code de push n'existe dans ce fichier).
#
# checkpoints/state.json -> 'committed' est normalement ecrit par le hook
# post-commit lui-meme (voir hooks/post-commit.ps1), qui compare le commit
# reellement produit au marqueur d'intention ecrit ci-dessous AVANT 'git
# commit'. Ce fichier ne reconcilie l'etat que si ce hook n'a pas pu le
# faire (absent/en erreur) - jamais un chemin normal, jamais bloquant.

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

        # Marqueur d'intention ecrit AVANT le commit : le hook post-commit
        # (synchrone, execute par 'git commit' lui-meme, avant que ce
        # script ne reprenne la main) s'en sert pour reconnaitre ce commit
        # comme legitime SANS dependre de state.json, qui n'est mis a jour
        # qu'apres coup - corrige la course entre Commit.ps1 et
        # post-commit.ps1 sur checkpoints/state.json.
        $expectedTree = (& git write-tree).Trim()
        Write-PendingCheckpointMarker -Step $Step -ExpectedTree $expectedTree

        $message = "checkpoint(step-$Step): $($manifest.description)"
        try {
            & git commit --quiet -m $message
            if ($LASTEXITCODE -ne 0) { throw "git commit a echoue (exit $LASTEXITCODE)." }
        } finally {
            # Que le commit ait reussi (marqueur deja consomme par le hook)
            # ou echoue (hook jamais execute, aucun objet commit cree),
            # aucun marqueur ne doit survivre au-dela de cet appel.
            Remove-PendingCheckpointMarker
        }

        $commitSha = (& git rev-parse HEAD).Trim()

        $previousEap = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        try {
            & git tag -f $tagName $commitSha 2>$null | Out-Null
            $tagExit = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = $previousEap
        }
        if ($tagExit -ne 0) {
            throw "Impossible de creer/deplacer le tag '$tagName' sur $commitSha (git tag exit $tagExit)."
        }
    } finally {
        Pop-Location
    }

    # Le hook post-commit vient normalement d'ecrire state.json =
    # 'committed' (sha/etape corrects, valides via le marqueur ci-dessus).
    # Filet de securite si ce hook est absent ou a echoue : ce script
    # confirme lui-meme l'etat - jamais bloquant, le commit est deja fait.
    $stateAfterCommit = Get-CheckpointState
    $hookConfirmed = $stateAfterCommit -and
        ($stateAfterCommit.status -eq 'committed') -and
        ($stateAfterCommit.last_committed_step -eq $Step) -and
        ($stateAfterCommit.last_commit_sha -eq $commitSha)

    if ($hookConfirmed) {
        $state = $stateAfterCommit
    } else {
        $state.status = 'committed'
        $state.last_committed_step = $Step
        $state.last_commit_sha = $commitSha
        $state.pending_step = $null
        Set-CheckpointState -State $state

        $logDir = Join-Path (Get-CheckpointsDir) 'log'
        if (-not (Test-Path -LiteralPath $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
        $auditFile = Join-Path $logDir 'bypass-audit.jsonl'
        $warning = [ordered]@{
            timestamp          = New-Timestamp
            commit             = $commitSha
            message_first_line = "checkpoint(step-$Step): $($manifest.description)"
            reason             = "Le hook post-commit n'a pas confirme ce commit pourtant legitime (hook absent, desinstalle, ou en erreur) - checkpoints/state.json reconcilie par Commit.ps1 lui-meme, aucun blocage du commit deja realise."
        }
        Add-Content -LiteralPath $auditFile -Value ($warning | ConvertTo-Json -Compress) -Encoding UTF8
    }

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
