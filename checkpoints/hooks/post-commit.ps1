# Systeme de checkpoint - hook post-commit (AUDIT, ne bloque jamais).
# Contrairement a pre-commit/commit-msg, ce hook n'est PAS saute par
# 'git commit --no-verify' : il journalise donc les commits qui ont
# contourne les deux autres hooks, dans checkpoints/log/bypass-audit.jsonl.
# Reconnait les 3 formats legitimes (voir commit-msg.ps1) : checkpoint(step-...),
# WIP:, et <type>(checkpoint): ... (ce dernier revalide que le commit reel ne
# contient que des fichiers du systeme, par symetrie avec commit-msg.ps1).

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
        $message = (& git log -1 --pretty=%B).Trim()
        $filesInCommit = @(& git diff-tree --no-commit-id --name-only -r $sha)
    } finally { Pop-Location }

    $firstLine = ($message -split "`n")[0].Trim()
    $state = Get-CheckpointState

    $legitimate = $false
    if ($firstLine -match '^checkpoint\(step-(?<step>[^)]+)\):\s+.+') {
        $step = $Matches['step']
        if ($state -and $state.status -eq 'committed' -and $state.last_committed_step -eq $step -and $state.last_commit_sha -eq $sha) {
            $legitimate = $true
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
