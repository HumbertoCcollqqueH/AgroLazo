[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ($PSVersionTable.PSVersion.Major -lt 7) {
    $powerShellCommand = Get-Command pwsh -ErrorAction SilentlyContinue
    $powerShellRuntime = if ($powerShellCommand) {
        $powerShellCommand.Source
    } else {
        Join-Path ([Environment]::GetFolderPath('UserProfile')) '.cache/codex-runtimes/codex-primary-runtime/dependencies/native/powershell/pwsh.exe'
    }
    if (-not (Test-Path -LiteralPath $powerShellRuntime -PathType Leaf)) {
        throw 'No se encontro PowerShell 7 para actualizar la aplicacion Android. Restaura el entorno de desarrollo de esta laptop o ejecuta este archivo desde PowerShell 7.'
    }
    Write-Host 'Usando PowerShell 7 disponible en esta laptop para iniciar y actualizar AgroLazo...'
    & $powerShellRuntime -NoProfile -ExecutionPolicy Bypass -File $PSCommandPath
    if ($LASTEXITCODE -ne 0) {
        throw "No se pudo completar el encendido de AgroLazo (codigo $LASTEXITCODE). Revisa el mensaje anterior."
    }
    return
}

$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))

function Get-AgroLazoDockerEngine {
    param([int]$TimeoutMilliseconds = 5000)

    $probe = New-Object System.Diagnostics.Process
    $probe.StartInfo.FileName = (Get-Command docker -ErrorAction Stop).Source
    $probe.StartInfo.Arguments = 'info --format "{{.OSType}}"'
    $probe.StartInfo.UseShellExecute = $false
    $probe.StartInfo.CreateNoWindow = $true
    $probe.StartInfo.RedirectStandardOutput = $true
    $probe.StartInfo.RedirectStandardError = $true
    try {
        [void]$probe.Start()
        $output = $probe.StandardOutput.ReadToEndAsync()
        $errors = $probe.StandardError.ReadToEndAsync()
        if (-not $probe.WaitForExit($TimeoutMilliseconds)) {
            $probe.Kill()
            return ''
        }
        if ($probe.ExitCode -ne 0) {
            return ''
        }
        return $output.GetAwaiter().GetResult().Trim()
    } finally {
        $probe.Dispose()
    }
}

Push-Location -LiteralPath $projectRoot
try {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'No se encuentra Docker. Instala Docker Desktop y vuelve a abrir PowerShell para cargar su comando.'
    }

    foreach ($requiredFile in @('.env', 'application/.env', 'docker-compose.yml', 'application/public/build/manifest.json', 'mobile/android/update-public-app.ps1')) {
        if (-not (Test-Path -LiteralPath (Join-Path $projectRoot $requiredFile) -PathType Leaf)) {
            throw "Falta $requiredFile. Este comando inicia la instalacion existente; restaura ese archivo antes de continuar."
        }
    }
    try {
        Get-Content -LiteralPath 'application/public/build/manifest.json' -Raw | ConvertFrom-Json | Out-Null
    } catch {
        throw 'El manifiesto de la interfaz no es valido. Es necesario volver a compilar la interfaz antes de encender el sistema.'
    }

    Write-Host '[1/3] Comprobando Docker Desktop...'
    $engine = Get-AgroLazoDockerEngine
    if (-not $engine) {
        $desktopPath = Join-Path $env:ProgramFiles 'Docker/Docker/Docker Desktop.exe'
        if (-not (Test-Path -LiteralPath $desktopPath -PathType Leaf)) {
            throw 'Docker no responde y no se encontro Docker Desktop. Abre Docker Desktop y vuelve a ejecutar este comando.'
        }
        Write-Host 'Iniciando Docker Desktop. Esperaremos hasta dos minutos a que el motor este disponible...'
        Start-Process -FilePath $desktopPath -WindowStyle Hidden | Out-Null
        $deadline = [DateTime]::UtcNow.AddSeconds(120)
        while (-not $engine -and [DateTime]::UtcNow -lt $deadline) {
            $remainingMilliseconds = [int]($deadline - [DateTime]::UtcNow).TotalMilliseconds
            if ($remainingMilliseconds -le 0) {
                break
            }
            $engine = Get-AgroLazoDockerEngine -TimeoutMilliseconds ([Math]::Min(5000, $remainingMilliseconds))
            if (-not $engine) {
                $remainingMilliseconds = [int]($deadline - [DateTime]::UtcNow).TotalMilliseconds
                if ($remainingMilliseconds -gt 0) {
                    Write-Host 'Docker sigue iniciando; conservamos los datos y la instalacion existente...'
                    Start-Sleep -Milliseconds ([Math]::Min(3000, $remainingMilliseconds))
                }
            }
        }
        if (-not $engine) {
            throw 'Docker Desktop no termino de iniciar en dos minutos. Revisa su ventana y vuelve a ejecutar el comando cuando indique que el motor esta listo.'
        }
    }
    if ($engine -ne 'linux') {
        throw 'AgroLazo necesita contenedores Linux. Cambia Docker Desktop a Linux containers y vuelve a ejecutar el comando.'
    }

    & docker compose version --short
    if ($LASTEXITCODE -ne 0) {
        throw 'Docker Compose no esta disponible. Revisa la instalacion de Docker Desktop.'
    }
    & docker compose -f docker-compose.yml config --quiet
    if ($LASTEXITCODE -ne 0) {
        throw 'La configuracion de Docker no es valida. Revisa el mensaje anterior antes de volver a iniciar.'
    }

    Write-Host '[2/3] Iniciando AgroLazo, sus procesos internos y el acceso por Internet...'
    & (Join-Path $projectRoot 'mobile/android/update-public-app.ps1')
    Write-Host '[3/3] Encendido completado. Conserva la direccion y el APK indicados arriba.'
    Write-Host 'La laptop debe permanecer encendida, conectada a Internet y sin suspenderse para atender a los usuarios.'
    Write-Host ('Para apagar AgroLazo: powershell -NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path $PSScriptRoot 'stop.ps1') + '"')
} finally {
    Pop-Location
}
