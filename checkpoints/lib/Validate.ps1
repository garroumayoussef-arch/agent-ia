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
    #
    # Trois sources d'exclusion, de nature differente :
    #
    # - `baseline_dirty_paths` (optionnel, dans le manifeste) : chemins deja
    #   modifies/non suivis AVANT le debut du travail de cette etape (autre
    #   travail non commite, sans rapport). Champ STATIQUE, ecrit une fois
    #   par l'operateur humain, jamais recalcule automatiquement ici : un
    #   recalcul dynamique absorberait silencieusement n'importe quel
    #   nouveau fichier non autorise des le premier validate, annulant la
    #   protection. Jamais ajoute par 'commit' (qui ne git-add que
    #   allowed_paths - voir Commit.ps1).
    #
    # - Chemins auto-geres du systeme de checkpoint
    #   (Get-CheckpointSelfManagedPathPatterns dans Common.ps1) : ecrits
    #   EXCLUSIVEMENT par l'outil lui-meme (state.json, log/*,
    #   backups/manifest.json), jamais par un agent ou un operateur. Etre
    #   "sale" y est un effet de bord mecanique et inevitable de
    #   l'execution de validate/commit, pas un changement a auditer -
    #   liste fixe et generique, INDEPENDANTE du manifeste et de l'etape,
    #   donc jamais a declarer dans baseline_dirty_paths.
    #
    # - Le manifeste de L'ETAPE COURANTE (checkpoints/steps/<Step>.json,
    #   chemin exact derive de $Step) : configuration operateur, jamais un
    #   livrable de code de l'etape. Le manifeste d'une AUTRE etape n'est
    #   volontairement PAS couvert par cette exclusion : le modifier
    #   pendant la validation de l'etape courante doit rester detecte.
    $changed = @(Get-ChangedPaths -RepoRoot $repoRoot)
    $allowedPatterns = @($manifest.allowed_paths)
    $baselinePatterns = @($manifest.baseline_dirty_paths)
    $selfManagedPatterns = @(Get-CheckpointSelfManagedPathPatterns)
    $ownManifestPatterns = @(("checkpoints/steps/{0}.json" -f $Step))

    # Garde-fou : un meme chemin litteral ne doit jamais etre reclame a la
    # fois comme sortie de cette etape (allowed_paths) et comme bruit
    # preexistant/auto-gere/manifeste propre - configuration ambigue du
    # manifeste, a corriger manuellement plutot qu'a interpreter.
    $exclusionPatterns = @($baselinePatterns) + @($selfManagedPatterns) + @($ownManifestPatterns)
    $overlap = @($allowedPatterns | Where-Object { $exclusionPatterns -contains $_ })
    if ($overlap.Count -gt 0) {
        throw "Le manifeste de l'etape '$Step' liste le(s) meme(s) chemin(s) dans allowed_paths ET dans une source d'exclusion (baseline_dirty_paths, ou un chemin auto-gere/manifeste propre a l'etape) ($($overlap -join ', ')) - configuration ambigue a corriger manuellement avant validation."
    }

    $disallowed = @($changed | Where-Object {
        (-not (Test-PathAllowed -Path $_ -AllowedPatterns $allowedPatterns)) -and
        (-not (Test-PathAllowed -Path $_ -AllowedPatterns $baselinePatterns)) -and
        (-not (Test-PathAllowed -Path $_ -AllowedPatterns $selfManagedPatterns)) -and
        (-not (Test-PathAllowed -Path $_ -AllowedPatterns $ownManifestPatterns))
    })
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
