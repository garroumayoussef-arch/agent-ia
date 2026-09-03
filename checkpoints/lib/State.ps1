# Systeme de checkpoint - lecture/ecriture de l'etat et de l'historique.
# Ne depend d'aucun agent IA : simple lecture/ecriture de fichiers JSON.

function Get-StateFilePath {
    return (Join-Path (Get-CheckpointsDir) 'state.json')
}

function Get-CheckpointState {
    return Read-JsonFile -Path (Get-StateFilePath)
}

function Set-CheckpointState {
    param([Parameter(Mandatory = $true)]$State)
    Write-JsonFile -Path (Get-StateFilePath) -Object $State
}

function Get-StepManifestPath {
    param([Parameter(Mandatory = $true)][string]$Step)
    return (Join-Path (Get-CheckpointsDir) ("steps\{0}.json" -f $Step))
}

function Get-StepManifest {
    param([Parameter(Mandatory = $true)][string]$Step)
    $path = Get-StepManifestPath -Step $Step
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Aucun manifeste pour l'etape '$Step' ($path). Il doit etre cree/deverrouille manuellement par l'operateur humain - jamais par un agent."
    }
    return Read-JsonFile -Path $path
}

function Write-CheckpointLog {
    param([Parameter(Mandatory = $true)]$LogEntry)
    $logDir = Join-Path (Get-CheckpointsDir) 'log'
    if (-not (Test-Path -LiteralPath $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    }
    $safeStep = ($LogEntry.step -replace '[^a-zA-Z0-9_.-]', '_')
    $file = Join-Path $logDir ("{0}-{1}.json" -f $safeStep, (New-FileSafeTimestamp))
    Write-JsonFile -Path $file -Object $LogEntry
    return $file
}
