# Systeme de checkpoint - installateur de hooks Git LOCAUX.
# .git/hooks/ n'est jamais suivi par Git : ce script copie/genere les hooks
# actifs a partir des sources versionnees de ce dossier. A relancer sur
# chaque nouveau clone/checkout ou apres modification des scripts *.ps1
# de ce dossier.
# Rappel : ces hooks restent contournables via 'git commit --no-verify'.
# Le backstop non-contournable par l'agent est la CI
# (.github/workflows/checkpoint-validate.yml).

$ErrorActionPreference = 'Stop'
$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $ScriptRoot '..\lib\Common.ps1')

$repoRoot = Get-RepoRoot
$gitHooksDir = Join-Path $repoRoot '.git\hooks'
if (-not (Test-Path -LiteralPath $gitHooksDir)) {
    throw "Dossier .git/hooks introuvable: $gitHooksDir"
}

function New-HookShim {
    param(
        [Parameter(Mandatory = $true)][string]$HookName,
        [Parameter(Mandatory = $true)][string]$TargetPs1,
        [switch]$PassArgs
    )
    $destPath = Join-Path $gitHooksDir $HookName
    $argsLine = ''
    if ($PassArgs) { $argsLine = ' "$@"' }
    $lines = @(
        '#!/bin/sh',
        '# Genere par checkpoints/hooks/install-hooks.ps1 - ne pas editer a la main.',
        "# Delegue a la logique versionnee sous checkpoints/hooks/$TargetPs1",
        "exec powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"`$(dirname `"`$0`")/../../checkpoints/hooks/$TargetPs1`"$argsLine"
    )
    $content = ($lines -join "`n") + "`n"
    [System.IO.File]::WriteAllText($destPath, $content, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host "Installe: $destPath -> checkpoints/hooks/$TargetPs1"
}

New-HookShim -HookName 'pre-commit'  -TargetPs1 'pre-commit.ps1'
New-HookShim -HookName 'commit-msg'  -TargetPs1 'commit-msg.ps1' -PassArgs
New-HookShim -HookName 'post-commit' -TargetPs1 'post-commit.ps1'

Write-Host ""
Write-Host "Hooks installes dans $gitHooksDir" -ForegroundColor Green
Write-Host "Rappel: ces hooks sont locaux (non versionnes par Git) et restent contournables via 'git commit --no-verify'." -ForegroundColor Yellow
Write-Host "Le backstop non-contournable par l'agent est la CI (.github/workflows/checkpoint-validate.yml)." -ForegroundColor Yellow
