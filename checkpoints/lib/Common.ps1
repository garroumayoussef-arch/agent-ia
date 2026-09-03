# Systeme de checkpoint - helpers partages.
# Independant de tout agent IA : uniquement des primitives Git/JSON/horodatage.

function Get-RepoRoot {
    $root = (& git rev-parse --show-toplevel 2>$null)
    if (-not $root) {
        throw "Impossible de determiner la racine du depot Git (git rev-parse a echoue). Executez cette commande depuis un depot Git."
    }
    return ($root.Trim() -replace '/', '\')
}

function Get-CheckpointsDir {
    return (Join-Path (Get-RepoRoot) 'checkpoints')
}

function Read-JsonFile {
    param([Parameter(Mandatory = $true)][string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Fichier introuvable: $Path"
    }
    $raw = Get-Content -LiteralPath $Path -Raw -Encoding UTF8
    if ([string]::IsNullOrWhiteSpace($raw)) { return $null }
    return $raw | ConvertFrom-Json
}

function Write-JsonFile {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)]$Object,
        [int]$Depth = 10
    )
    $dir = Split-Path -Parent $Path
    if ($dir -and -not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    $json = $Object | ConvertTo-Json -Depth $Depth
    [System.IO.File]::WriteAllText($Path, $json, (New-Object System.Text.UTF8Encoding($false)))
}

function New-Timestamp {
    return (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz')
}

function New-FileSafeTimestamp {
    return (Get-Date).ToString('yyyyMMdd-HHmmss')
}

function Get-CheckpointSystemPathPatterns {
    # Chemins consideres comme appartenant au systeme de checkpoint lui-meme
    # (par opposition a une etape metier suivie via checkpoints/steps/*.json).
    # Liste fixe et generique, independante de tout agent et de toute etape -
    # utilisee par commit-msg.ps1/post-commit.ps1 pour reconnaitre un commit
    # officiel de maintenance du systeme (format "<type>(checkpoint): ...").
    # A tenir a jour manuellement si de nouveaux fichiers du systeme sont
    # ajoutes en dehors de checkpoints/ (le pattern 'checkpoints/*' couvre
    # deja tout le contenu de ce dossier, a toute profondeur).
    return @(
        'checkpoints/*',
        '.github/workflows/checkpoint-validate.yml',
        'app/Console/Commands/CheckpointDb.php',
        '.gitignore'
    )
}

function Get-CheckpointSelfManagedPathPatterns {
    # Chemins ecrits EXCLUSIVEMENT par le systeme de checkpoint lui-meme
    # (State.ps1::Set-CheckpointState -> checkpoints/state.json,
    # State.ps1::Write-CheckpointLog -> checkpoints/log/*,
    # checkpoint.ps1 'db backup' -> checkpoints/backups/manifest.json) -
    # jamais par un agent ni par un operateur humain. Etre "sale" y est un
    # effet de bord mecanique et inevitable de l'execution de
    # validate/commit/db, pas un changement metier ou systeme a auditer.
    # Utilise par Validate.ps1 pour exclure ces chemins de l'allowlist,
    # de maniere fixe et generique, INDEPENDAMMENT de tout manifeste
    # d'etape (jamais a declarer dans baseline_dirty_paths). Sous-ensemble
    # volontairement etroit de Get-CheckpointSystemPathPatterns ci-dessus :
    # tout le reste de checkpoints/ (lib/*.ps1, hooks/*.ps1, README.md,
    # steps/_template.json, ...) reste soumis au controle normal - une
    # modification de ces fichiers pendant une etape doit rester visible.
    # A tenir a jour manuellement si un nouveau fichier auto-ecrit par
    # l'outil est ajoute ailleurs dans checkpoints/.
    return @(
        'checkpoints/state.json',
        'checkpoints/log/*',
        'checkpoints/backups/manifest.json'
    )
}
