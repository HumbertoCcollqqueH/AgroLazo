[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$composeArguments = @('compose', '-f', 'docker-compose.yml')
if (Test-Path -LiteralPath (Join-Path $projectRoot 'docker-compose.evolution.yml')) {
    $composeArguments += @('-f', 'docker-compose.evolution.yml')
}

Push-Location -LiteralPath $projectRoot
try {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'No se encuentra Docker. Abre una terminal con Docker Desktop disponible.'
    }
    if (-not (Test-Path -LiteralPath '.env')) {
        throw 'Falta el archivo .env local y Compose no puede resolver su configuracion. No se han detenido servicios.'
    }

    Write-Host 'Deteniendo los servicios de AgroConecta; se conservan contenedores, imagenes y volumenes de datos...'
    & docker @composeArguments --profile '*' stop --timeout 90
    if ($LASTEXITCODE -ne 0) {
        throw "No se pudieron detener todos los servicios (codigo $LASTEXITCODE). Revisa Docker Desktop y el error anterior."
    }
    Write-Host 'Servicios detenidos. Para volver a iniciar: .\scripts\start.ps1 -SkipAssets'
} finally {
    Pop-Location
}
