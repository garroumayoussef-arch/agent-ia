# Systeme de checkpoint - logique de validation d'une etape.
# Verifie : allowlist, git diff --check, marqueurs de conflit, tests de l'etape.

function Get-ChangedPaths {
    param([Parameter(Mandatory = $true)][string]$RepoRoot)
    Push-Location $RepoRoot
    try {
        $lines = @(& git status --porcelain=v1)
        $paths = @()
        foreach ($line in $lines) {
            if ([string]::IsNullOrWhiteSpace($line)) { continue }
            $p = $line.Substring(3).Trim()
            if ($p -match '^"(.*)"$') { $p = $Matches[1] }
            if ($p -match ' -> ') { $p = ($p -split ' -> ')[1] }
            $paths += $p
        }
        return $paths
    } finally {
        Pop-Location
    }
}

function Test-PathAllowed {
    param([string]$Path, [string[]]$AllowedPatterns)
    foreach ($pattern in $AllowedPatterns) {
        if ($Path -like $pattern) { return $true }
    }
    return $false
}

function Test-ConflictMarkers {
    param([Parameter(Mandatory = $true)][string]$RepoRoot)
    Push-Location $RepoRoot
    try {
        $pattern = '^(<<<<<<< |>>>>>>> |=======$)'
        $found = @(& git grep -n -E $pattern -- . 2>$null)
        return $found
    } finally {
        Pop-Location
    }
}

function Invoke-CheckpointValidate {
    param([Parameter(Mandatory = $true)][string]$Step)

    $repoRoot = Get-RepoRoot
    $manifest = Get-StepManifest -Step $Step
    if ($manifest.locked -eq $true) {
        throw "L'etape '$Step' est verrouillee (locked:true). Elle doit etre deverrouillee explicitement par l'operateur humain avant validation."
    }

    $checks = [ordered]@{}
    $allPass = $true

    # 1. Allowlist (fichiers modifies/non commites vs allowed_paths du manifeste)
    $changed = @(Get-ChangedPaths -RepoRoot $repoRoot)
    $allowedPatterns = @($manifest.allowed_paths)
    $disallowed = @($changed | Where-Object { -not (Test-PathAllowed -Path $_ -AllowedPatterns $allowedPatterns) })
    if ($disallowed.Count -eq 0) {
        $checks.allowlist = @{ status = 'pass' }
    } else {
        $checks.allowlist = @{ status = 'fail'; disallowed = $disallowed }
        $allPass = $false
    }

    # 2. git diff --check (erreurs d'espaces)
    Push-Location $repoRoot
    try {
        $diffOut = @(& git diff --check)
        $diffExit = $LASTEXITCODE
    } finally { Pop-Location }
    if ($diffExit -eq 0) {
        $checks.diff_check = @{ status = 'pass' }
    } else {
        $checks.diff_check = @{ status = 'fail'; output = $diffOut }
        $allPass = $false
    }

    # 3. Marqueurs de conflit non resolus (sur tout l'arbre, pas seulement le diff)
    $conflicts = @(Test-ConflictMarkers -RepoRoot $repoRoot)
    if ($conflicts.Count -eq 0) {
        $checks.conflict_markers = @{ status = 'pass' }
    } else {
        $checks.conflict_markers = @{ status = 'fail'; matches = $conflicts }
        $allPass = $false
    }

    # 4. Tests requis pour l'etape
    if ($manifest.test_command) {
        Push-Location $repoRoot
        try {
            $testOutput = @(Invoke-Expression $manifest.test_command 2>&1 | ForEach-Object { "$_" })
            $testExit = $LASTEXITCODE
        } finally { Pop-Location }
        if ($testExit -eq 0) {
            $checks.tests = @{ status = 'pass'; command = $manifest.test_command; output = ($testOutput -join "`n") }
        } else {
            $checks.tests = @{ status = 'fail'; command = $manifest.test_command; output = ($testOutput -join "`n") }
            $allPass = $false
        }
    } else {
        $checks.tests = @{ status = 'skipped'; reason = 'aucun test_command dans le manifeste' }
    }

    $verdict = 'fail'
    if ($allPass) { $verdict = 'validated' }

    $result = [ordered]@{
        step          = $Step
        substep       = $manifest.substep
        timestamp     = New-Timestamp
        files_changed = $changed
        checks        = $checks
        verdict       = $verdict
    }

    $logFile = Write-CheckpointLog -LogEntry $result

    $state = Get-CheckpointState
    if ($allPass) {
        $state.status = 'validated'
    } else {
        $state.status = 'validation_failed'
    }
    $state.pending_step = $Step
    $state.last_validation_log = (Split-Path -Leaf $logFile)
    Set-CheckpointState -State $state

    return $result
}
