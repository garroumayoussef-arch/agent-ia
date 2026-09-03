# Systeme de checkpoint - hook pre-commit (protection LOCALE, contournable par --no-verify).
# Toujours applique : diff --check + scan marqueurs de conflit sur le stage.
# Applique en plus l'allowlist de l'etape SI checkpoints/state.json indique
# un statut 'ready_to_commit' avec une etape en attente.

$ErrorActionPreference = 'Stop'
$hookDir = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $hookDir '..\lib\Common.ps1')
. (Join-Path $hookDir '..\lib\State.ps1')
. (Join-Path $hookDir '..\lib\Validate.ps1')

try {
    $repoRoot = Get-RepoRoot

    Push-Location $repoRoot
    try {
        $diffOut = @(& git diff --cached --check)
        $diffExit = $LASTEXITCODE
    } finally { Pop-Location }
    if ($diffExit -ne 0) {
        Write-Host "pre-commit: git diff --check a echoue sur les fichiers stages." -ForegroundColor Red
        $diffOut | ForEach-Object { Write-Host $_ }
        exit 1
    }

    $conflicts = @(Test-ConflictMarkers -RepoRoot $repoRoot)
    if ($conflicts.Count -gt 0) {
        Write-Host "pre-commit: marqueurs de conflit non resolus detectes dans le depot." -ForegroundColor Red
        $conflicts | ForEach-Object { Write-Host $_ }
        exit 1
    }

    $state = Get-CheckpointState
    if ($state -and $state.status -eq 'ready_to_commit' -and $state.pending_step) {
        $manifest = Get-StepManifest -Step $state.pending_step
        Push-Location $repoRoot
        try {
            $staged = @(& git diff --cached --name-only)
        } finally { Pop-Location }
        $disallowed = @($staged | Where-Object { -not (Test-PathAllowed -Path $_ -AllowedPatterns @($manifest.allowed_paths)) })
        if ($disallowed.Count -gt 0) {
            Write-Host "pre-commit: fichiers stages hors de l'allowlist de l'etape '$($state.pending_step)':" -ForegroundColor Red
            $disallowed | ForEach-Object { Write-Host " - $_" }
            exit 1
        }
    }

    exit 0
} catch {
    Write-Host "pre-commit: erreur - $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
