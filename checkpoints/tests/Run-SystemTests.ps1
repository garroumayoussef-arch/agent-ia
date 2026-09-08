# Systeme de checkpoint - tests du systeme lui-meme.
#
# Ce script NE TOUCHE JAMAIS au depot reel ni a database/database.sqlite :
# - les scenarios Git/allowlist/hooks s'executent dans un depot Git jetable
#   cree sous $env:TEMP (copie de checkpoints/lib, checkpoints/hooks,
#   checkpoint.ps1 et du manifeste checkpoint-selftest.json) ;
# - le scenario DB backup/verify/restore utilise la vraie commande Artisan
#   'checkpoint:db' du depot reel, mais exclusivement avec --file/--target
#   pointant vers des fichiers SQLite jetables sous $env:TEMP.
#
# Limite assumee et documentee : 'authorize' exige une session interactive
# reelle par construction (voir checkpoints/lib/Authorize.ps1). Ce script
# teste donc separement (a) qu'un appel non-interactif est bien rejete, et
# (b) que 'commit' se comporte correctement une fois un etat
# 'ready_to_commit' atteint - cet etat etant, pour ce test uniquement,
# positionne directement via Set-CheckpointState pour simuler exactement
# ce qu'une autorisation humaine reussie aurait produit. Un run automatise
# ne peut pas, par definition, simuler une interaction humaine reelle.

# 'Continue' (et non 'Stop') est deliberement choisi ici : plusieurs
# scenarios ci-dessous invoquent des commandes natives (git, powershell.exe)
# dont l'ECHEC EST LE COMPORTEMENT ATTENDU (allowlist violee, hook qui
# rejette, etc.). Sous PowerShell 5.1, une sortie sur le flux d'erreur d'un
# processus natif combinee a 'Stop' est promue en exception terminante
# meme avec une simple redirection vers $null - ce qui interromprait le
# harnais de test sur un echec pourtant normal. Les conditions reellement
# fatales (echec de mise en place du sandbox) sont verifiees explicitement
# via $LASTEXITCODE puis un 'throw' manuel.
$ErrorActionPreference = 'Continue'

$RepoRoot = (& git rev-parse --show-toplevel).Trim() -replace '/', '\'
$CheckpointsSrc = Join-Path $RepoRoot 'checkpoints'

$Results = New-Object System.Collections.Generic.List[object]

function Add-Result {
    param([string]$Name, [bool]$Passed, [string]$Detail = '')
    $Results.Add([pscustomobject]@{ Name = $Name; Passed = $Passed; Detail = $Detail })
    $status = 'FAIL'
    $color = 'Red'
    if ($Passed) { $status = 'PASS'; $color = 'Green' }
    Write-Host "[$status] $Name" -ForegroundColor $color
    if ($Detail) { Write-Host "       $Detail" }
}

# ==================================================================
# Sandbox Git jetable
# ==================================================================
$sandbox = Join-Path $env:TEMP ("checkpoint-selftest-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $sandbox -Force | Out-Null
Write-Host "Sandbox Git jetable: $sandbox"
Write-Host ""

Push-Location $sandbox
try {
    & git init --quiet .
    & git config user.email "selftest@local"
    & git config user.name "Checkpoint Selftest"
    & git config commit.gpgsign false

    New-Item -ItemType Directory -Path (Join-Path $sandbox 'checkpoints\steps') -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $sandbox 'checkpoints\log') -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $sandbox 'checkpoints\backups') -Force | Out-Null
    Copy-Item -Recurse -Path (Join-Path $CheckpointsSrc 'lib') -Destination (Join-Path $sandbox 'checkpoints\lib')
    Copy-Item -Recurse -Path (Join-Path $CheckpointsSrc 'hooks') -Destination (Join-Path $sandbox 'checkpoints\hooks')
    Copy-Item -Path (Join-Path $CheckpointsSrc 'checkpoint.ps1') -Destination (Join-Path $sandbox 'checkpoints\checkpoint.ps1')
    Copy-Item -Path (Join-Path $CheckpointsSrc 'steps\checkpoint-selftest.json') -Destination (Join-Path $sandbox 'checkpoints\steps\checkpoint-selftest.json')

    '{"backups": []}' | Set-Content -LiteralPath (Join-Path $sandbox 'checkpoints\backups\manifest.json') -Encoding UTF8
    '{"status":"idle","pending_step":null,"last_committed_step":null,"last_commit_sha":null,"authorized_by":null,"authorized_at":null,"last_validation_log":null}' |
        Set-Content -LiteralPath (Join-Path $sandbox 'checkpoints\state.json') -Encoding UTF8

    "hello" | Set-Content -LiteralPath (Join-Path $sandbox 'SELFTEST_DUMMY_FILE.txt') -Encoding UTF8
    & git add -A
    & git commit --quiet -m "WIP: init sandbox"
    if ($LASTEXITCODE -ne 0) { throw "Echec du commit initial du sandbox." }

    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $sandbox 'checkpoints\hooks\install-hooks.ps1') | Out-Null

    $ckpt = Join-Path $sandbox 'checkpoints\checkpoint.ps1'
    $manifestPath = Join-Path $sandbox 'checkpoints\steps\checkpoint-selftest.json'
    $statePath = Join-Path $sandbox 'checkpoints\state.json'
    $dummyFile = Join-Path $sandbox 'SELFTEST_DUMMY_FILE.txt'
    # Defini ici (et non dans le scenario 7) pour que les scenarios 18-25
    # ne dependent pas de l'execution prealable du scenario 7 : l'isolation
    # du sandbox exige que chaque scenario puisse s'executer independamment
    # de l'etat/des variables laisses par les scenarios precedents.
    $auditFile = Join-Path $sandbox 'checkpoints\log\bypass-audit.jsonl'

    . (Join-Path $sandbox 'checkpoints\lib\Common.ps1')
    . (Join-Path $sandbox 'checkpoints\lib\State.ps1')

    function Reset-SandboxState {
        '{"status":"idle","pending_step":null,"last_committed_step":null,"last_commit_sha":null,"authorized_by":null,"authorized_at":null,"last_validation_log":null}' |
            Set-Content -LiteralPath $statePath -Encoding UTF8
        Push-Location $sandbox
        try {
            # git checkout -- . restaure le working tree DEPUIS L'INDEX, pas depuis
            # HEAD : un fichier deja stage mais jamais commite (ex. residu d'un
            # commit volontairement rejete par un hook dans un scenario precedent,
            # comme le scenario 11) resterait sinon stage indefiniment, jamais
            # reinitialise par les appels suivants. 'git reset --quiet HEAD -- .'
            # reinitialise d'abord l'INDEX sur HEAD pour tous les chemins, avant
            # que 'git checkout -- .' ne resynchronise le working tree sur cet
            # index desormais propre.
            & git reset --quiet HEAD -- . 2>$null
            & git checkout --quiet -- . 2>$null
            & git clean -fdq -- . 2>$null
        } finally { Pop-Location }
    }

    function Set-ManifestTestCommand {
        param($Value)
        $m = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
        $m.test_command = $Value
        ($m | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $manifestPath -Encoding UTF8
        Push-Location $sandbox
        try { & git add -- checkpoints/steps/checkpoint-selftest.json; & git commit --quiet -m "WIP: adjust selftest manifest" } finally { Pop-Location }
    }

    # ==============================================================
    # Scenario 1 : violation d'allowlist -> validate doit echouer
    # ==============================================================
    Reset-SandboxState
    "modif autorisee" | Set-Content -LiteralPath $dummyFile
    "contenu interdit" | Set-Content -LiteralPath (Join-Path $sandbox 'NOT_ALLOWED.txt')
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt validate -Step checkpoint-selftest *> $null
    $stateAfter = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    Add-Result -Name "1. Violation d'allowlist detectee (validate echoue)" -Passed ($stateAfter.status -eq 'validation_failed') -Detail "state.status=$($stateAfter.status)"
    Remove-Item -LiteralPath (Join-Path $sandbox 'NOT_ALLOWED.txt') -ErrorAction SilentlyContinue

    # ==============================================================
    # Scenario 2 : test_command en echec -> validate doit echouer
    # ==============================================================
    Reset-SandboxState
    Set-ManifestTestCommand -Value 'powershell -NoProfile -Command "exit 1"'
    "modif autorisee 2" | Set-Content -LiteralPath $dummyFile
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt validate -Step checkpoint-selftest *> $null
    $stateAfter = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    Add-Result -Name "2. Test d'etape en echec bloque validate" -Passed ($stateAfter.status -eq 'validation_failed') -Detail "state.status=$($stateAfter.status)"

    # ==============================================================
    # Scenario 3 : validate reussit quand tout est conforme
    # ==============================================================
    Reset-SandboxState
    Set-ManifestTestCommand -Value 'powershell -NoProfile -Command "exit 0"'
    "modif autorisee 3" | Set-Content -LiteralPath $dummyFile
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt validate -Step checkpoint-selftest *> $null
    $stateAfter = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    Add-Result -Name "3. Validate reussit quand allowlist+diff+tests sont conformes" -Passed ($stateAfter.status -eq 'validated' -and $stateAfter.pending_step -eq 'checkpoint-selftest') -Detail "state.status=$($stateAfter.status)"

    # ==============================================================
    # Scenario 4 : commit refuse sans authorize prealable
    # ==============================================================
    $commitOutput = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt commit -Step checkpoint-selftest
    $commitExit = $LASTEXITCODE
    $stateAfter = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    Add-Result -Name "4. Commit refuse tant que authorize n'a pas ete execute" -Passed ($commitExit -ne 0 -and $stateAfter.status -ne 'committed') -Detail "exit=$commitExit state.status=$($stateAfter.status)"

    # ==============================================================
    # Scenario 5 : authorize refuse un appel non-interactif
    # ==============================================================
    $authOutput = "" | & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt authorize -Step checkpoint-selftest
    $authExit = $LASTEXITCODE
    $stateAfter = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    Add-Result -Name "5. Authorize rejette un appel non-interactif (entree redirigee)" -Passed ($authExit -ne 0 -and $stateAfter.status -ne 'ready_to_commit') -Detail "exit=$authExit state.status=$($stateAfter.status)"

    # ==============================================================
    # Scenario 6 : commit reussit une fois un etat ready_to_commit atteint
    # (etat positionne directement pour simuler une autorisation humaine
    # reussie - voir note en tete de fichier)
    # ==============================================================
    $state = Get-CheckpointState
    $state.status = 'ready_to_commit'
    $state.pending_step = 'checkpoint-selftest'
    $state.authorized_by = 'selftest-harness (simule)'
    $state.authorized_at = (New-Timestamp)
    Set-CheckpointState -State $state

    $commitResult = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt commit -Step checkpoint-selftest
    $commitExit = $LASTEXITCODE
    $stateAfter = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    Push-Location $sandbox
    try {
        $tagExists = @(& git tag -l 'checkpoint/checkpoint-selftest')
        $lastMsg = (& git log -1 --pretty=%B).Trim()
    } finally { Pop-Location }
    $passed6 = ($commitExit -eq 0) -and ($stateAfter.status -eq 'committed') -and ($tagExists.Count -gt 0) -and ($lastMsg -match '^checkpoint\(step-checkpoint-selftest\):')
    Add-Result -Name "6. Commit reussit apres autorisation valide (tag + message standardises)" -Passed $passed6 -Detail "exit=$commitExit state.status=$($stateAfter.status) tag=$($tagExists -join ',') message=$lastMsg"

    # ==============================================================
    # Scenario 7 : --no-verify contourne pre-commit/commit-msg MAIS
    # post-commit journalise quand meme le contournement
    # ==============================================================
    "modif hors procedure" | Set-Content -LiteralPath $dummyFile
    Push-Location $sandbox
    try {
        & git add -- SELFTEST_DUMMY_FILE.txt
        & git commit --no-verify --quiet -m "message non conforme sans --no-verify"
        $noVerifyExit = $LASTEXITCODE
        $noVerifySha = (& git rev-parse HEAD).Trim()
    } finally { Pop-Location }
    $auditContainsSha = $false
    if (Test-Path -LiteralPath $auditFile) {
        $auditContainsSha = (Select-String -LiteralPath $auditFile -Pattern $noVerifySha -Quiet)
    }
    Add-Result -Name "7. --no-verify reussit mais post-commit journalise le contournement" -Passed ($noVerifyExit -eq 0 -and $auditContainsSha) -Detail "commit_exit=$noVerifyExit sha=$noVerifySha audit_trouve=$auditContainsSha"

    # ==============================================================
    # Scenario 8 : sans --no-verify, un message non conforme est rejete
    # ==============================================================
    "modif hors procedure 2" | Set-Content -LiteralPath $dummyFile
    Push-Location $sandbox
    try {
        & git add -- SELFTEST_DUMMY_FILE.txt
        & git commit --quiet -m "message non conforme avec hooks actifs" 2>$null
        $rejectedExit = $LASTEXITCODE
    } finally { Pop-Location }
    Add-Result -Name "8. Sans --no-verify, un message non conforme est rejete par commit-msg" -Passed ($rejectedExit -ne 0) -Detail "exit=$rejectedExit"
    Push-Location $sandbox
    try { & git reset --quiet HEAD -- SELFTEST_DUMMY_FILE.txt; & git checkout --quiet -- SELFTEST_DUMMY_FILE.txt } finally { Pop-Location }

    # ==============================================================
    # Scenario 9 : historique complet sur les logs produits (etape 3 et 6)
    # ==============================================================
    $logDir = Join-Path $sandbox 'checkpoints\log'
    $validateLogs = @(Get-ChildItem -LiteralPath $logDir -Filter 'checkpoint-selftest-*.json' | Sort-Object LastWriteTime)
    $requiredValidateFields = @('step', 'substep', 'timestamp', 'files_changed', 'checks', 'verdict')
    $requiredCommitFields = @('step', 'substep', 'commit', 'tag', 'files', 'db_backup', 'timestamp', 'status')
    $validateOk = $false
    $commitOk = $false
    foreach ($f in $validateLogs) {
        $obj = Get-Content -LiteralPath $f.FullName -Raw | ConvertFrom-Json
        $props = @($obj.PSObject.Properties.Name)
        if ($obj.verdict -and (@($requiredValidateFields | Where-Object { $props -notcontains $_ })).Count -eq 0) { $validateOk = $true }
        if ($obj.status -eq 'committed' -and (@($requiredCommitFields | Where-Object { $props -notcontains $_ })).Count -eq 0) { $commitOk = $true }
    }
    Add-Result -Name "9. Historique log contient tous les champs requis (etape/sous-etape/commit/tests/resultat/fichiers/backup/date/statut)" -Passed ($validateOk -and $commitOk) -Detail "validate_log_ok=$validateOk commit_log_ok=$commitOk (fichiers: $($validateLogs.Count))"

    # ==============================================================
    # Scenario 10 : format officiel "<type>(checkpoint): ..." accepte
    # quand tous les fichiers stages appartiennent au systeme
    # ==============================================================
    $m = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    $m.unlocked_at = "scenario-10-marker"
    ($m | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $manifestPath -Encoding UTF8
    Push-Location $sandbox
    try {
        & git add -- checkpoints/steps/checkpoint-selftest.json
        & git commit --quiet -m "feat(checkpoint): update selftest manifest marker"
        $officialExit = $LASTEXITCODE
    } finally { Pop-Location }
    Add-Result -Name "10. Format officiel <type>(checkpoint): accepte pour des fichiers du systeme" -Passed ($officialExit -eq 0) -Detail "exit=$officialExit"

    # ==============================================================
    # Scenario 11 : format officiel refuse si un fichier hors systeme
    # est stage en meme temps (protection anti-detournement du format)
    # ==============================================================
    "modif hors perimetre" | Set-Content -LiteralPath $dummyFile
    $m2 = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    $m2.unlocked_at = "scenario-11-marker"
    ($m2 | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $manifestPath -Encoding UTF8
    Push-Location $sandbox
    try {
        & git add -- checkpoints/steps/checkpoint-selftest.json SELFTEST_DUMMY_FILE.txt
        & git commit --quiet -m "feat(checkpoint): should be rejected due to out-of-scope file" 2>$null
        $mixedExit = $LASTEXITCODE
    } finally { Pop-Location }
    Add-Result -Name "11. Format officiel refuse si un fichier hors systeme est mele au commit" -Passed ($mixedExit -ne 0) -Detail "exit=$mixedExit"
    Push-Location $sandbox
    try { & git reset --quiet HEAD -- SELFTEST_DUMMY_FILE.txt; & git checkout --quiet -- SELFTEST_DUMMY_FILE.txt } finally { Pop-Location }

    # ==============================================================
    # Scenarios 18-25 : correction de la course Commit.ps1/git commit/
    # post-commit sur checkpoints/state.json (marqueur d'intention ecrit
    # AVANT 'git commit', lu par le hook au lieu de l'etat 'committed'
    # ecrit APRES). $auditFile est defini des l'initialisation du sandbox.
    # ==============================================================

    # ==============================================================
    # Scenario 18 : commit legitime via checkpoint.ps1 commit -> AUCUNE
    # nouvelle entree dans bypass-audit.jsonl (c'est le test de
    # non-regression qui manquait : le scenario 6 ne verifiait jamais ce
    # fichier, laissant passer le faux positif d'origine).
    # ==============================================================
    Reset-SandboxState
    $auditLinesBefore18 = 0
    if (Test-Path -LiteralPath $auditFile) { $auditLinesBefore18 = @(Get-Content -LiteralPath $auditFile).Count }
    $state = Get-CheckpointState
    $state.status = 'ready_to_commit'
    $state.pending_step = 'checkpoint-selftest'
    $state.authorized_by = 'selftest-harness (simule)'
    $state.authorized_at = (New-Timestamp)
    Set-CheckpointState -State $state
    "modif autorisee 18" | Set-Content -LiteralPath $dummyFile
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt commit -Step checkpoint-selftest *> $null
    $commitExit18 = $LASTEXITCODE
    $stateAfter18 = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    $auditLinesAfter18 = 0
    if (Test-Path -LiteralPath $auditFile) { $auditLinesAfter18 = @(Get-Content -LiteralPath $auditFile).Count }
    $markerPath18 = Join-Path $sandbox 'checkpoints\log\pending-checkpoint.json'
    Add-Result -Name "18. Commit legitime via checkpoint.ps1 : aucune entree bypass-audit ajoutee, marqueur nettoye" -Passed ($commitExit18 -eq 0 -and $stateAfter18.status -eq 'committed' -and $auditLinesAfter18 -eq $auditLinesBefore18 -and -not (Test-Path -LiteralPath $markerPath18)) -Detail "exit=$commitExit18 state.status=$($stateAfter18.status) audit_avant=$auditLinesBefore18 audit_apres=$auditLinesAfter18 marker_present=$(Test-Path -LiteralPath $markerPath18)"

    # ==============================================================
    # Scenario 19 : state.json en ready_to_commit (autorisation simulee)
    # mais le commit est passe DIRECTEMENT par 'git commit', jamais via
    # checkpoint.ps1 commit -> aucun marqueur ecrit -> doit rester
    # detecte comme hors-procedure malgre un message au format valide.
    # ==============================================================
    Reset-SandboxState
    $state = Get-CheckpointState
    $state.status = 'ready_to_commit'
    $state.pending_step = 'checkpoint-selftest'
    $state.authorized_by = 'selftest-harness (simule)'
    $state.authorized_at = (New-Timestamp)
    Set-CheckpointState -State $state
    "modif hors procedure 19" | Set-Content -LiteralPath $dummyFile
    Push-Location $sandbox
    try {
        & git add -- SELFTEST_DUMMY_FILE.txt
        & git commit --quiet -m "checkpoint(step-checkpoint-selftest): commit direct sans passer par checkpoint.ps1"
        $directExit19 = $LASTEXITCODE
        $directSha19 = (& git rev-parse HEAD).Trim()
    } finally { Pop-Location }
    $auditFound19 = $false
    if (Test-Path -LiteralPath $auditFile) { $auditFound19 = (Select-String -LiteralPath $auditFile -Pattern $directSha19 -Quiet) }
    Add-Result -Name "19. Etat ready_to_commit mais commit passe directement (sans checkpoint.ps1 commit) reste detecte" -Passed ($directExit19 -eq 0 -and $auditFound19) -Detail "commit_exit=$directExit19 sha=$directSha19 audit_trouve=$auditFound19"

    # ==============================================================
    # Scenario 20 : marqueur present mais pour une AUTRE etape que celle
    # du commit reel ("mauvais checkpoint") -> doit rester detecte malgre
    # la presence d'un marqueur.
    # ==============================================================
    Reset-SandboxState
    $state = Get-CheckpointState
    $state.status = 'ready_to_commit'
    $state.pending_step = 'checkpoint-selftest'
    $state.authorized_by = 'selftest-harness (simule)'
    $state.authorized_at = (New-Timestamp)
    Set-CheckpointState -State $state
    "modif hors procedure 20" | Set-Content -LiteralPath $dummyFile
    Write-PendingCheckpointMarker -Step 'une-autre-etape' -ExpectedTree 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef'
    Push-Location $sandbox
    try {
        & git add -- SELFTEST_DUMMY_FILE.txt
        & git commit --quiet -m "checkpoint(step-checkpoint-selftest): marqueur pour une etape differente"
        $mismatchExit20 = $LASTEXITCODE
        $mismatchSha20 = (& git rev-parse HEAD).Trim()
    } finally { Pop-Location }
    $auditFound20 = $false
    if (Test-Path -LiteralPath $auditFile) { $auditFound20 = (Select-String -LiteralPath $auditFile -Pattern $mismatchSha20 -Quiet) }
    Add-Result -Name "20. Marqueur present pour une autre etape que le commit reel reste detecte (mauvais checkpoint)" -Passed ($mismatchExit20 -eq 0 -and $auditFound20) -Detail "commit_exit=$mismatchExit20 sha=$mismatchSha20 audit_trouve=$auditFound20"
    Remove-PendingCheckpointMarker

    # ==============================================================
    # Scenario 21 : 'git commit' echoue reellement APRES l'ecriture du
    # marqueur (signature GPG forcee vers un binaire inexistant - moyen
    # deterministe, independant de toute config git globale de l'hote) ->
    # aucun marqueur orphelin, state.json reste 'ready_to_commit'.
    # ==============================================================
    Reset-SandboxState
    $state = Get-CheckpointState
    $state.status = 'ready_to_commit'
    $state.pending_step = 'checkpoint-selftest'
    $state.authorized_by = 'selftest-harness (simule)'
    $state.authorized_at = (New-Timestamp)
    Set-CheckpointState -State $state
    "modif autorisee 21" | Set-Content -LiteralPath $dummyFile
    Push-Location $sandbox
    try {
        & git config commit.gpgsign true
        & git config gpg.program 'C:\selftest-inexistant-gpg.exe'
    } finally { Pop-Location }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt commit -Step checkpoint-selftest *> $null
    $commitExit21 = $LASTEXITCODE
    Push-Location $sandbox
    try { & git config commit.gpgsign false } finally { Pop-Location }
    $stateAfter21 = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    $markerPath21 = Join-Path $sandbox 'checkpoints\log\pending-checkpoint.json'
    Add-Result -Name "21. git commit en echec apres ecriture du marqueur (signature forcee a echouer) : aucun marqueur orphelin, state.json reste ready_to_commit" -Passed ($commitExit21 -ne 0 -and $stateAfter21.status -eq 'ready_to_commit' -and -not (Test-Path -LiteralPath $markerPath21)) -Detail "exit=$commitExit21 state.status=$($stateAfter21.status) marker_present=$(Test-Path -LiteralPath $markerPath21)"

    # ==============================================================
    # Scenario 22 : hook post-commit absent (desinstalle) -> le commit
    # reussit quand meme, Commit.ps1 reconcilie state.json lui-meme et
    # journalise un avertissement non bloquant dans bypass-audit.jsonl.
    # ==============================================================
    Reset-SandboxState
    $postCommitHookPath = Join-Path $sandbox '.git\hooks\post-commit'
    $postCommitHookBackup = "$postCommitHookPath.selftest-backup"
    Move-Item -LiteralPath $postCommitHookPath -Destination $postCommitHookBackup -Force
    $state = Get-CheckpointState
    $state.status = 'ready_to_commit'
    $state.pending_step = 'checkpoint-selftest'
    $state.authorized_by = 'selftest-harness (simule)'
    $state.authorized_at = (New-Timestamp)
    Set-CheckpointState -State $state
    "modif autorisee 22" | Set-Content -LiteralPath $dummyFile
    $auditLinesBefore22 = 0
    if (Test-Path -LiteralPath $auditFile) { $auditLinesBefore22 = @(Get-Content -LiteralPath $auditFile).Count }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt commit -Step checkpoint-selftest *> $null
    $commitExit22 = $LASTEXITCODE
    Move-Item -LiteralPath $postCommitHookBackup -Destination $postCommitHookPath -Force
    $stateAfter22 = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    $auditLinesAfter22 = 0
    if (Test-Path -LiteralPath $auditFile) { $auditLinesAfter22 = @(Get-Content -LiteralPath $auditFile).Count }
    Add-Result -Name "22. Hook post-commit absent : commit reussit quand meme, state.json reconcilie par Commit.ps1, avertissement journalise" -Passed ($commitExit22 -eq 0 -and $stateAfter22.status -eq 'committed' -and $auditLinesAfter22 -gt $auditLinesBefore22) -Detail "exit=$commitExit22 state.status=$($stateAfter22.status) audit_avant=$auditLinesBefore22 audit_apres=$auditLinesAfter22"

    # ==============================================================
    # Scenario 23 : marqueur present, BONNE etape, mais expectedTree
    # falsifie (ne correspond pas au contenu reellement commite) -> doit
    # rester detecte - preuve que la comparaison d'arbre est reellement
    # discriminante, pas seulement la regex du message.
    # ==============================================================
    Reset-SandboxState
    $state = Get-CheckpointState
    $state.status = 'ready_to_commit'
    $state.pending_step = 'checkpoint-selftest'
    $state.authorized_by = 'selftest-harness (simule)'
    $state.authorized_at = (New-Timestamp)
    Set-CheckpointState -State $state
    "modif hors procedure 23" | Set-Content -LiteralPath $dummyFile
    Write-PendingCheckpointMarker -Step 'checkpoint-selftest' -ExpectedTree 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef'
    Push-Location $sandbox
    try {
        & git add -- SELFTEST_DUMMY_FILE.txt
        & git commit --quiet -m "checkpoint(step-checkpoint-selftest): tree du marqueur falsifie"
        $treeMismatchExit23 = $LASTEXITCODE
        $treeMismatchSha23 = (& git rev-parse HEAD).Trim()
    } finally { Pop-Location }
    $auditFound23 = $false
    if (Test-Path -LiteralPath $auditFile) { $auditFound23 = (Select-String -LiteralPath $auditFile -Pattern $treeMismatchSha23 -Quiet) }
    Add-Result -Name "23. Marqueur avec expectedTree falsifie (bonne etape) reste detecte" -Passed ($treeMismatchExit23 -eq 0 -and $auditFound23) -Detail "commit_exit=$treeMismatchExit23 sha=$treeMismatchSha23 audit_trouve=$auditFound23"
    Remove-PendingCheckpointMarker

    # ==============================================================
    # Scenario 24 : revue structurelle - aucune dependance a un agent
    # precis (Claude Code, Codex, ou autre) dans les fichiers corriges.
    # ==============================================================
    $filesToScan24 = @(
        (Join-Path $CheckpointsSrc 'lib\Common.ps1'),
        (Join-Path $CheckpointsSrc 'lib\Commit.ps1'),
        (Join-Path $CheckpointsSrc 'hooks\post-commit.ps1')
    )
    $agentSpecificPattern24 = '(?i)claude|codex|anthropic|openai|copilot'
    $offendingMatches24 = @()
    foreach ($f in $filesToScan24) {
        $found = Select-String -LiteralPath $f -Pattern $agentSpecificPattern24
        if ($found) { $offendingMatches24 += $found }
    }
    Add-Result -Name "24. Aucune reference a un agent precis dans les fichiers corriges (mecanisme agent-agnostique)" -Passed ($offendingMatches24.Count -eq 0) -Detail "occurrences_trouvees=$($offendingMatches24.Count)"

    # ==============================================================
    # Scenario 25 : reprise propre apres un echec de commit - un premier
    # commit est force a echouer (meme technique que le scenario 21),
    # puis une nouvelle tentative immediate doit reussir sans etat
    # residuel.
    # ==============================================================
    Reset-SandboxState
    $state = Get-CheckpointState
    $state.status = 'ready_to_commit'
    $state.pending_step = 'checkpoint-selftest'
    $state.authorized_by = 'selftest-harness (simule)'
    $state.authorized_at = (New-Timestamp)
    Set-CheckpointState -State $state
    "modif autorisee 25" | Set-Content -LiteralPath $dummyFile
    Push-Location $sandbox
    try {
        & git config commit.gpgsign true
        & git config gpg.program 'C:\selftest-inexistant-gpg.exe'
    } finally { Pop-Location }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt commit -Step checkpoint-selftest *> $null
    $firstAttemptExit25 = $LASTEXITCODE
    Push-Location $sandbox
    try { & git config commit.gpgsign false } finally { Pop-Location }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt commit -Step checkpoint-selftest *> $null
    $retryExit25 = $LASTEXITCODE
    $stateAfter25 = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    Add-Result -Name "25. Reprise propre apres un echec de commit : nouvelle tentative immediate reussit" -Passed ($firstAttemptExit25 -ne 0 -and $retryExit25 -eq 0 -and $stateAfter25.status -eq 'committed') -Detail "premiere_tentative_exit=$firstAttemptExit25 reprise_exit=$retryExit25 state.status=$($stateAfter25.status)"

    # ==============================================================
    # Scenario 13 : baseline_dirty_paths exclut un fichier deja sale
    # AVANT le debut de l'etape, y compris a travers plusieurs 'validate'
    # successifs (accumulation de checkpoints/log/*), sans qu'aucun
    # chemin auto-gere (state.json/log/*) n'ait besoin d'y etre liste
    # ==============================================================
    Reset-SandboxState
    "bruit preexistant" | Set-Content -LiteralPath (Join-Path $sandbox 'PREEXISTING_NOISE.txt')
    $m13 = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    $m13 | Add-Member -NotePropertyName baseline_dirty_paths -NotePropertyValue @('PREEXISTING_NOISE.txt') -Force
    ($m13 | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $manifestPath -Encoding UTF8
    Push-Location $sandbox
    try { & git add -- checkpoints/steps/checkpoint-selftest.json; & git commit --quiet -m "WIP: add baseline_dirty_paths to selftest manifest" } finally { Pop-Location }
    "modif autorisee 13" | Set-Content -LiteralPath $dummyFile
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt validate -Step checkpoint-selftest *> $null
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt validate -Step checkpoint-selftest *> $null
    $stateAfter = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    $passed13 = ($stateAfter.status -eq 'validated') -and (Test-Path -LiteralPath (Join-Path $sandbox 'PREEXISTING_NOISE.txt'))
    Add-Result -Name "13. baseline_dirty_paths exclut un fichier preexistant meme apres plusieurs validate successifs (state.json/log/* auto-geres)" -Passed $passed13 -Detail "state.status=$($stateAfter.status)"
    Remove-Item -LiteralPath (Join-Path $sandbox 'PREEXISTING_NOISE.txt') -ErrorAction SilentlyContinue

    # ==============================================================
    # Scenario 14 : un nouveau fichier non declare (ni allowed_paths, ni
    # baseline_dirty_paths) apparu PENDANT l'etape reste detecte - la
    # correction ne doit jamais elargir le filtrage au-dela des chemins
    # auto-geres et du manifeste propre a l'etape
    # ==============================================================
    Reset-SandboxState
    "modif autorisee 14" | Set-Content -LiteralPath $dummyFile
    "fichier metier non declare" | Set-Content -LiteralPath (Join-Path $sandbox 'UNDECLARED_BUSINESS_FILE.txt')
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt validate -Step checkpoint-selftest *> $null
    $stateAfter = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    $lastLog14 = Get-ChildItem -LiteralPath (Join-Path $sandbox 'checkpoints\log') -Filter 'checkpoint-selftest-*.json' | Sort-Object LastWriteTime | Select-Object -Last 1
    $logObj14 = Get-Content -LiteralPath $lastLog14.FullName -Raw | ConvertFrom-Json
    $flagged14 = @($logObj14.checks.allowlist.disallowed) -contains 'UNDECLARED_BUSINESS_FILE.txt'
    Add-Result -Name "14. Un nouveau fichier non declare pendant l'etape reste detecte (non masque par la correction)" -Passed ($stateAfter.status -eq 'validation_failed' -and $flagged14) -Detail "state.status=$($stateAfter.status) flagged=$flagged14"
    Remove-Item -LiteralPath (Join-Path $sandbox 'UNDECLARED_BUSINESS_FILE.txt') -ErrorAction SilentlyContinue

    # ==============================================================
    # Scenario 15 : un fichier du systeme de checkpoint modifie APRES le
    # debut de l'etape (ex. lib/Validate.ps1 lui-meme), qui n'est PAS dans
    # Get-CheckpointSelfManagedPathPatterns, reste detecte - la correction
    # ne doit jamais s'etendre a checkpoints/* en bloc
    # ==============================================================
    Reset-SandboxState
    "modif autorisee 15" | Set-Content -LiteralPath $dummyFile
    Add-Content -LiteralPath (Join-Path $sandbox 'checkpoints\lib\Validate.ps1') -Value "`n# modif de test scenario 15"
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt validate -Step checkpoint-selftest *> $null
    $stateAfter = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    $lastLog15 = Get-ChildItem -LiteralPath (Join-Path $sandbox 'checkpoints\log') -Filter 'checkpoint-selftest-*.json' | Sort-Object LastWriteTime | Select-Object -Last 1
    $logObj15 = Get-Content -LiteralPath $lastLog15.FullName -Raw | ConvertFrom-Json
    $flagged15 = @($logObj15.checks.allowlist.disallowed) -contains 'checkpoints/lib/Validate.ps1'
    Add-Result -Name "15. Un fichier checkpoint modifie pendant l'etape (hors chemins auto-geres) reste detecte" -Passed ($stateAfter.status -eq 'validation_failed' -and $flagged15) -Detail "state.status=$($stateAfter.status) flagged=$flagged15"
    Push-Location $sandbox
    try { & git checkout --quiet -- checkpoints/lib/Validate.ps1 } finally { Pop-Location }

    # ==============================================================
    # Scenario 16 : le manifeste d'une AUTRE etape ne peut pas masquer une
    # modification faite pendant l'etape courante - l'exclusion du
    # "manifeste propre" est limitee au chemin exact de l'etape validee.
    # Verifie au passage le fonctionnement avec deux etapes successives.
    # ==============================================================
    Reset-SandboxState
    $manifest2Path = Join-Path $sandbox 'checkpoints\steps\checkpoint-selftest-2.json'
    $manifest2 = [ordered]@{
        step                = 'checkpoint-selftest-2'
        substep             = $null
        tier                = 0
        description         = 'Second manifeste factice pour le scenario 16 (deux etapes successives).'
        depends_on          = 'checkpoint-selftest'
        locked              = $false
        unlocked_by         = 'system-selftest'
        unlocked_at         = $null
        allowed_paths       = @('SELFTEST_DUMMY_FILE_2.txt')
        test_command        = 'powershell -NoProfile -Command "exit 0"'
        requires_db_backup  = $false
    }
    ($manifest2 | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $manifest2Path -Encoding UTF8
    "contenu initial" | Set-Content -LiteralPath (Join-Path $sandbox 'SELFTEST_DUMMY_FILE_2.txt')
    Push-Location $sandbox
    try {
        & git add -- checkpoints/steps/checkpoint-selftest-2.json SELFTEST_DUMMY_FILE_2.txt
        & git commit --quiet -m "WIP: add second selftest manifest for scenario 16"
    } finally { Pop-Location }

    # Modifie le manifeste de l'ETAPE A (checkpoint-selftest) pendant que
    # l'on valide l'ETAPE B (checkpoint-selftest-2) - ne doit jamais etre
    # exclu par l'exclusion "manifeste propre" de B.
    $mA = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    $mA.unlocked_at = "scenario-16-cross-step-marker"
    ($mA | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $manifestPath -Encoding UTF8

    "modif autorisee etape B" | Set-Content -LiteralPath (Join-Path $sandbox 'SELFTEST_DUMMY_FILE_2.txt')
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ckpt validate -Step checkpoint-selftest-2 *> $null
    $stateAfter = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    $lastLog16 = Get-ChildItem -LiteralPath (Join-Path $sandbox 'checkpoints\log') -Filter 'checkpoint-selftest-2-*.json' | Sort-Object LastWriteTime | Select-Object -Last 1
    $logObj16 = Get-Content -LiteralPath $lastLog16.FullName -Raw | ConvertFrom-Json
    $flagged16 = @($logObj16.checks.allowlist.disallowed) -contains 'checkpoints/steps/checkpoint-selftest.json'
    Add-Result -Name "16. Le manifeste d'une autre etape (A) reste detecte pendant la validation de l'etape courante (B) - deux etapes successives" -Passed ($stateAfter.status -eq 'validation_failed' -and $flagged16) -Detail "state.status=$($stateAfter.status) flagged=$flagged16"
    Push-Location $sandbox
    try { & git checkout --quiet -- checkpoints/steps/checkpoint-selftest.json } finally { Pop-Location }
    Remove-Item -LiteralPath $manifest2Path -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $sandbox 'SELFTEST_DUMMY_FILE_2.txt') -ErrorAction SilentlyContinue

} finally {
    Pop-Location
}

# ==================================================================
# Scenario 12 : cycle DB reel backup -> verify -> corruption -> verify -> restore -> verify
# Utilise la vraie commande 'php artisan checkpoint:db' du depot REEL,
# exclusivement avec --file/--target pointant vers des fichiers jetables.
# ==================================================================
$dbSandbox = Join-Path $env:TEMP ("checkpoint-dbtest-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $dbSandbox -Force | Out-Null
$sourceDb = Join-Path $dbSandbox 'source.sqlite'
$backupDb = Join-Path $dbSandbox 'backup.sqlite'
$corruptDb = Join-Path $dbSandbox 'backup-corrupt.sqlite'
$initPhp = Join-Path $dbSandbox 'init-db.php'

@"
<?php
`$pdo = new PDO('sqlite:' . `$argv[1]);
`$pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
`$pdo->exec("INSERT INTO t (v) VALUES ('a'), ('b'), ('c')");
"@ | Set-Content -LiteralPath $initPhp -Encoding UTF8

& php $initPhp $sourceDb

Push-Location $RepoRoot
try {
    $backupOut = & php artisan checkpoint:db backup --file=$sourceDb --target=$backupDb
    $backupExit = $LASTEXITCODE

    $verify1Out = & php artisan checkpoint:db verify --file=$backupDb
    $verify1Exit = $LASTEXITCODE

    Copy-Item -LiteralPath $backupDb -Destination $corruptDb -Force
    $bytes = [System.IO.File]::ReadAllBytes($corruptDb)
    $truncated = $bytes[0..([Math]::Min(50, $bytes.Length - 1))]
    [System.IO.File]::WriteAllBytes($corruptDb, $truncated)

    $verify2Out = & php artisan checkpoint:db verify --file=$corruptDb
    $verify2Exit = $LASTEXITCODE

    $restoreOut = & php artisan checkpoint:db restore --file=$backupDb --target=$sourceDb
    $restoreExit = $LASTEXITCODE

    $verify3Out = & php artisan checkpoint:db verify --file=$sourceDb
    $verify3Exit = $LASTEXITCODE
} finally {
    Pop-Location
}

$passed10 = ($backupExit -eq 0) -and ($verify1Exit -eq 0) -and ($verify2Exit -ne 0) -and ($restoreExit -eq 0) -and ($verify3Exit -eq 0)
Add-Result -Name "17. Cycle DB complet: backup -> verify(ok) -> corruption -> verify(echec detecte) -> restore -> verify(ok)" -Passed $passed10 -Detail "backup=$backupExit verify_bon=$verify1Exit verify_corrompu=$verify2Exit restore=$restoreExit verify_final=$verify3Exit"

Remove-Item -LiteralPath $dbSandbox -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item -LiteralPath $sandbox -Recurse -Force -ErrorAction SilentlyContinue

# ==================================================================
# Resume
# ==================================================================
Write-Host ""
Write-Host "==================== RESUME ====================" -ForegroundColor Cyan
$total = $Results.Count
$passedCount = @($Results | Where-Object { $_.Passed }).Count
foreach ($r in $Results) {
    $status = 'FAIL'
    if ($r.Passed) { $status = 'PASS' }
    Write-Host ("{0,-6} {1}" -f $status, $r.Name)
}
Write-Host ""
Write-Host "$passedCount / $total scenarios reussis"
if ($passedCount -ne $total) {
    exit 1
}
exit 0
