# Systeme de checkpoint - installateur de hooks Git LOCAUX.
# .git/hooks/ n'est jamais suivi par Git : ce script copie/genere les hooks
# actifs a partir des sources versionnees de ce dossier. A relancer sur
# chaque nouveau clone/checkout ou apres modification des scripts *.ps1
# de ce dossier.
# Rappel : ces hooks restent contournables via 'git commit --no-verify'.
# Le backstop non-contournable par l'agent est la CI
# (.github/workflows/checkpoint-validate.yml).
#
# -Verify : ne modifie rien, controle que les 3 hooks sont installes et
# correspondent au contenu attendu (source versionnee de ce dossier),
# retourne un exit code non nul si un hook est manquant ou perime.

[CmdletBinding()]
param([switch]$Verify)

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
    $expectedContent = ($lines -join "`n") + "`n"

    if ($Verify) {
        if (-not (Test-Path -LiteralPath $destPath)) {
            Write-Host "MANQUANT : $destPath" -ForegroundColor Red
            return $false
        }
        $actualContent = Get-Content -LiteralPath $destPath -Raw
        if ($actualContent -ne $expectedContent) {
            Write-Host "PERIME   : $destPath (ne correspond plus a checkpoints/hooks/$TargetPs1)" -ForegroundColor Red
            return $false
        }
        Write-Host "OK       : $destPath" -ForegroundColor Green
        return $true
    }

    [System.IO.File]::WriteAllText($destPath, $expectedContent, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host "Installe: $destPath -> checkpoints/hooks/$TargetPs1"
    return $true
}

$results = @(
    (New-HookShim -HookName 'pre-commit'  -TargetPs1 'pre-commit.ps1'),
    (New-HookShim -HookName 'commit-msg'  -TargetPs1 'commit-msg.ps1' -PassArgs),
    (New-HookShim -HookName 'post-commit' -TargetPs1 'post-commit.ps1')
)

Write-Host ""
if ($Verify) {
    if ($results -contains $false) {
        Write-Host "Verification: au moins un hook est manquant ou perime. Executez 'install-hooks.ps1' (sans -Verify) pour corriger." -ForegroundColor Red
        exit 1
    }
    Write-Host "Verification: les 3 hooks sont installes et a jour." -ForegroundColor Green
    exit 0
}

Write-Host "Hooks installes dans $gitHooksDir" -ForegroundColor Green
Write-Host "Rappel: ces hooks sont locaux (non versionnes par Git) et restent contournables via 'git commit --no-verify'." -ForegroundColor Yellow
Write-Host "Le backstop non-contournable par l'agent est la CI (.github/workflows/checkpoint-validate.yml)." -ForegroundColor Yellow
