# Systeme de checkpoint - hook post-commit (AUDIT, ne bloque jamais).
# Contrairement a pre-commit/commit-msg, ce hook n'est PAS saute par
# 'git commit --no-verify' : il journalise donc les commits qui ont
# contourne les deux autres hooks, dans checkpoints/log/bypass-audit.jsonl.
# Reconnait les 3 formats legitimes (voir commit-msg.ps1) : checkpoint(step-...),
# WIP:, et <type>(checkpoint): ... (ce dernier revalide que le commit reel ne
# contient que des fichiers du systeme, par symetrie avec commit-msg.ps1).
#
# Format "checkpoint(step-...)" : la legitimite se verifie via le marqueur
# d'intention ecrit par Commit.ps1 AVANT 'git commit' (voir
# Get-PendingCheckpointMarkerPath dans lib/Common.ps1), jamais via
# checkpoints/state.json - ce fichier n'est mis a jour par Commit.ps1
# qu'APRES 'git commit', donc APRES l'execution de ce hook (synchrone,
# declenche par 'git commit' lui-meme) : le comparer aurait toujours ete
# faux, quelle que soit la legitimite reelle du commit. Ce hook devient
# l'ecrivain principal de state.json pour ce format ; Commit.ps1 ne
# reconcilie qu'en filet de securite si ce hook n'a pas pu s'executer.

$ErrorActionPreference = 'Stop'
$hookDir = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $hookDir '..\lib\Common.ps1')
. (Join-Path $hookDir '..\lib\State.ps1')
. (Join-Path $hookDir '..\lib\Validate.ps1')

try {
    $repoRoot = Get-RepoRoot
    Push-Location $repoRoot
    try {
        $sha = (& git rev-parse HEAD).Trim()
        $actualTree = (& git rev-parse 'HEAD^{tree}').Trim()
        $message = (& git log -1 --pretty=%B).Trim()
        $filesInCommit = @(& git diff-tree --no-commit-id --name-only -r $sha)
    } finally { Pop-Location }

    $firstLine = ($message -split "`n")[0].Trim()
    $state = Get-CheckpointState

    $legitimate = $false
    if ($firstLine -match '^checkpoint\(step-(?<step>[^)]+)\):\s+.+') {
        $step = $Matches['step']
        $marker = Read-PendingCheckpointMarker
        if ($marker -and $marker.step -eq $step -and $marker.expectedTree -eq $actualTree) {
            $legitimate = $true
            if ($state) {
                $state.status = 'committed'
                $state.last_committed_step = $step
                $state.last_commit_sha = $sha
                $state.pending_step = $null
                Set-CheckpointState -State $state
            }
            Remove-PendingCheckpointMarker
        }
    } elseif ($firstLine -match '^WIP:\s+.+') {
        $legitimate = $true
    } elseif ($firstLine -match '^(feat|fix|chore|docs|refactor|test|build|ci)\(checkpoint\):\s+.+') {
        $patterns = Get-CheckpointSystemPathPatterns
        $outOfScope = @($filesInCommit | Where-Object { -not (Test-PathAllowed -Path $_ -AllowedPatterns $patterns) })
        if ($outOfScope.Count -eq 0) {
            $legitimate = $true
        }
    }

    if (-not $legitimate) {
        $entry = [ordered]@{
            timestamp          = New-Timestamp
            commit             = $sha
            message_first_line = $firstLine
            reason             = 'Commit ne correspondant a aucun flux checkpoint reconnu (format invalide, etat non confirme dans state.json, ou fichiers hors perimetre pour un commit "<type>(checkpoint): ...") - probable usage de --no-verify ou commit manuel hors procedure.'
        }
        $logDir = Join-Path (Get-CheckpointsDir) 'log'
        if (-not (Test-Path -LiteralPath $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
        $auditFile = Join-Path $logDir 'bypass-audit.jsonl'
        $line = $entry | ConvertTo-Json -Compress
        Add-Content -LiteralPath $auditFile -Value $line -Encoding UTF8
        Write-Host "post-commit: commit $sha journalise comme hors-procedure dans checkpoints/log/bypass-audit.jsonl" -ForegroundColor Yellow
    }
} catch {
    Write-Host "post-commit: erreur non bloquante - $($_.Exception.Message)" -ForegroundColor Yellow
}
exit 0
