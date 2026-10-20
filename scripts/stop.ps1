[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$restartCommand = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path $PSScriptRoot 'encender.ps1') + '"'
$composeArguments = @('compose', '-f', 'docker-compose.yml')
if (Test-Path -LiteralPath (Join-Path $projectRoot 'docker-compose.evolution.yml')) {
    $composeArguments += @('-f', 'docker-compose.evolution.yml')
}

Push-Location -LiteralPath $projectRoot
try {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'No se encuentra Docker. Abre una terminal con Docker Desktop disponible.'
    }

    $probe = New-Object System.Diagnostics.Process
    $probe.StartInfo.FileName = (Get-Command docker -ErrorAction Stop).Source
    $probe.StartInfo.Arguments = 'info --format "{{.OSType}}"'
    $probe.StartInfo.UseShellExecute = $false
    $probe.StartInfo.CreateNoWindow = $true
    $probe.StartInfo.RedirectStandardOutput = $true
    $probe.StartInfo.RedirectStandardError = $true
    $engineAvailable = $false
    try {
        [void]$probe.Start()
        $output = $probe.StandardOutput.ReadToEndAsync()
        $errors = $probe.StandardError.ReadToEndAsync()
        if ($probe.WaitForExit(5000)) {
            $engineAvailable = $probe.ExitCode -eq 0
        } else {
            $probe.Kill()
        }
    } finally {
        $probe.Dispose()
    }
    if (-not $engineAvailable) {
        Write-Host 'Docker no esta disponible. No hay un motor activo al que enviar la orden de apagado; tus datos se conservan.'
        Write-Host "Para encender AgroLazo: $restartCommand"
        return
    }

    Write-Host 'Cerrando primero el acceso publico de AgroLazo...'
    $tunnel = & docker ps -a --filter 'name=^/agrolazo-tunnel$' --format '{{.Names}}'
    if ($LASTEXITCODE -ne 0) {
        throw 'No se pudo comprobar el estado del tunel de AgroLazo. Revisa Docker Desktop.'
    }
    if ($tunnel) {
        & docker stop --time 30 agrolazo-tunnel
        if ($LASTEXITCODE -ne 0) {
            throw 'No se pudo detener el tunel de AgroLazo. Revisa Docker Desktop antes de volver a intentarlo.'
        }
    }
    if (-not (Test-Path -LiteralPath '.env')) {
        throw 'El acceso publico se ha detenido, pero falta el archivo .env local para detener los servicios internos con Compose.'
    }

    Write-Host 'Deteniendo los servicios de AgroLazo; se conservan contenedores, imagenes y volumenes de datos...'
    & docker @composeArguments --profile '*' stop --timeout 90
    if ($LASTEXITCODE -ne 0) {
        throw "No se pudieron detener todos los servicios (codigo $LASTEXITCODE). Revisa Docker Desktop y el error anterior."
    }
    Write-Host 'AgroLazo esta apagado. Docker Desktop y los demas proyectos permanecen disponibles.'
    Write-Host "Para encender AgroLazo: $restartCommand"
} finally {
    Pop-Location
}
