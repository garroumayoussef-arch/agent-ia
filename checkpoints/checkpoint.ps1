<#
.SYNOPSIS
    Point d'entree unique du systeme de checkpoint (independant de tout agent IA).
.DESCRIPTION
    Sous-commandes : status, validate, authorize, commit, rollback, db
    Voir checkpoints/README.md pour le detail de chaque sous-commande, le
    format des manifestes et les limites connues.
    Ce fichier ne fait jamais de 'git push'.
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0, Mandatory = $true)]
    [ValidateSet('status', 'validate', 'authorize', 'commit', 'rollback', 'db')]
    [string]$Command,

    [Parameter(Position = 1)]
    [string]$Step,

    [string]$To,
    [string]$File,
    [string]$Target,

    [ValidateSet('backup', 'verify', 'restore')]
    [string]$DbAction
)

$ErrorActionPreference = 'Stop'

$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $ScriptRoot 'lib\Common.ps1')
. (Join-Path $ScriptRoot 'lib\State.ps1')
. (Join-Path $ScriptRoot 'lib\Validate.ps1')
. (Join-Path $ScriptRoot 'lib\Authorize.ps1')
. (Join-Path $ScriptRoot 'lib\Commit.ps1')
. (Join-Path $ScriptRoot 'lib\Rollback.ps1')

try {
switch ($Command) {
    'status' {
        $state = Get-CheckpointState
        $state | ConvertTo-Json -Depth 10
    }

    'validate' {
        if (-not $Step) { throw "validate requiert -Step <id>" }
        $result = Invoke-CheckpointValidate -Step $Step
        $result | ConvertTo-Json -Depth 10
        if ($result.verdict -ne 'validated') { exit 1 }
    }

    'authorize' {
        if (-not $Step) { throw "authorize requiert -Step <id>" }
        Invoke-CheckpointAuthorize -Step $Step | Out-Null
    }

    'commit' {
        if (-not $Step) { throw "commit requiert -Step <id>" }
        $result = Invoke-CheckpointCommit -Step $Step
        $result | ConvertTo-Json -Depth 10
    }

    'rollback' {
        if (-not $To) { throw "rollback requiert -To <tag|sha>" }
        Invoke-CheckpointRollback -To $To
    }

    'db' {
        if (-not $DbAction) { throw "db requiert -DbAction backup|verify|restore" }
        $repoRoot = Get-RepoRoot
        $phpArgs = @('artisan', 'checkpoint:db', $DbAction)
        if ($File) { $phpArgs += "--file=$File" }
        if ($Target) { $phpArgs += "--target=$Target" }
        if ($Step) { $phpArgs += "--step=$Step" }

        Push-Location $repoRoot
        try {
            $output = @(& php @phpArgs)
            $exit = $LASTEXITCODE
        } finally {
            Pop-Location
        }
        $output | ForEach-Object { Write-Host $_ }
        if ($exit -ne 0) { throw "checkpoint:db $DbAction a echoue (exit $exit)." }

        if ($DbAction -eq 'backup' -and $Step) {
            $jsonLine = @($output | Where-Object { $_ -match '^\{.*\}$' }) | Select-Object -Last 1
            if ($jsonLine) {
                $parsed = $jsonLine | ConvertFrom-Json
                $backupFile = $parsed.target
                $hash = (Get-FileHash -LiteralPath $backupFile -Algorithm SHA256).Hash
                $commitSha = $null
                Push-Location $repoRoot
                try { $commitSha = (& git rev-parse HEAD 2>$null) } finally { Pop-Location }
                if ($commitSha) { $commitSha = $commitSha.Trim() }

                $manifestPath = Join-Path (Get-CheckpointsDir) 'backups\manifest.json'
                $backupsManifest = Read-JsonFile -Path $manifestPath
                $entry = [ordered]@{
                    step              = $Step
                    file              = $backupFile
                    sha256            = $hash
                    source_commit_sha = $commitSha
                    created_at        = (New-Timestamp)
                }
                $backups = @($backupsManifest.backups) + $entry
                $backupsManifest.backups = $backups
                Write-JsonFile -Path $manifestPath -Object $backupsManifest
                Write-Host "Backup enregistre dans checkpoints/backups/manifest.json (etape $Step)."
            }
        }
    }
}
} catch {
    Write-Host "checkpoint: erreur - $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
