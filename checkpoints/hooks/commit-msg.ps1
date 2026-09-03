# Systeme de checkpoint - hook commit-msg (protection LOCALE, contournable par --no-verify).
# Formats acceptes :
#   - "checkpoint(step-<id>): <resume>"       -> exige un state.json coherent (ready_to_commit)
#   - "WIP: <resume>"                         -> travail en cours, hors checkpoint, toujours accepte
#   - "<type>(checkpoint): <resume>"          -> commit officiel de MAINTENANCE DU SYSTEME
#     lui-meme (type in feat|fix|chore|docs|refactor|test|build|ci), accepte
#     uniquement si TOUS les fichiers stages appartiennent au systeme de
#     checkpoint (voir Get-CheckpointSystemPathPatterns dans lib/Common.ps1) -
#     jamais un moyen de faire passer un changement metier/Tier sous couvert
#     de ce format.

[CmdletBinding()]
param(
    [Parameter(Position = 0, Mandatory = $true)]
    [string]$MessageFile
)

$ErrorActionPreference = 'Stop'
$hookDir = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $hookDir '..\lib\Common.ps1')
. (Join-Path $hookDir '..\lib\State.ps1')
. (Join-Path $hookDir '..\lib\Validate.ps1')

try {
    $message = Get-Content -LiteralPath $MessageFile -Raw
    $firstLine = ($message -split "`n")[0].Trim()

    if ($firstLine -match '^checkpoint\(step-(?<step>[^)]+)\):\s+.+') {
        $step = $Matches['step']
        $state = Get-CheckpointState
        $ok = $false
        if ($state -and $state.status -eq 'ready_to_commit' -and $state.pending_step -eq $step) {
            $ok = $true
        }
        if (-not $ok) {
            Write-Host "commit-msg: message au format checkpoint pour l'etape '$step', mais aucune autorisation valide (checkpoints/state.json) ne le confirme." -ForegroundColor Red
            Write-Host "commit-msg: executez 'checkpoint.ps1 validate' puis 'authorize' avant de committer cette etape." -ForegroundColor Red
            exit 1
        }
        exit 0
    }

    if ($firstLine -match '^WIP:\s+.+') {
        exit 0
    }

    if ($firstLine -match '^(feat|fix|chore|docs|refactor|test|build|ci)\(checkpoint\):\s+.+') {
        $repoRoot = Get-RepoRoot
        Push-Location $repoRoot
        try {
            $staged = @(& git diff --cached --name-only)
        } finally { Pop-Location }

        $patterns = Get-CheckpointSystemPathPatterns
        $outOfScope = @($staged | Where-Object { -not (Test-PathAllowed -Path $_ -AllowedPatterns $patterns) })
        if ($outOfScope.Count -gt 0) {
            Write-Host "commit-msg: message '<type>(checkpoint): ...' refuse le meme si l'entete est valide - des fichiers hors du systeme de checkpoint sont stages:" -ForegroundColor Red
            $outOfScope | ForEach-Object { Write-Host " - $_" }
            Write-Host "commit-msg: un commit officiel du systeme de checkpoint ne doit contenir QUE des fichiers du systeme (checkpoints/*, CheckpointDb.php, workflow CI, .gitignore)." -ForegroundColor Red
            exit 1
        }
        exit 0
    }

    Write-Host "commit-msg: message rejete. Formats acceptes:" -ForegroundColor Red
    Write-Host "  - 'checkpoint(step-<id>): <resume>'  (via checkpoint.ps1 commit)" -ForegroundColor Red
    Write-Host "  - 'WIP: <resume>'                    (travail en cours, hors checkpoint)" -ForegroundColor Red
    Write-Host "  - '<type>(checkpoint): <resume>'     (maintenance du systeme lui-meme, fichiers checkpoint uniquement)" -ForegroundColor Red
    exit 1
} catch {
    Write-Host "commit-msg: erreur - $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
