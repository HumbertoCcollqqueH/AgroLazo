[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
Push-Location -LiteralPath $projectRoot
try {
function Invoke-Checked { param([string[]]$Arguments) & docker @Arguments; if ($LASTEXITCODE -ne 0) { throw "Falló Docker: $($Arguments -join ' ')" } }
Write-Host 'Iniciando web, base de datos, chat y procesos de fondo...'
Invoke-Checked @('compose','--profile','workers','up','-d','--wait','--wait-timeout','120','app','nginx','postgres','redis','queue','scheduler','reverb','realtime-worker')
Invoke-Checked @('compose','exec','-T','nginx','nginx','-s','reload')
$container = 'agrolazo-tunnel'
$existing = & docker ps -a --filter "name=^/$container$" --format '{{.Names}}'
if (!$existing) {
    Invoke-Checked @('compose','exec','-T','-u','root','app','php','artisan','down')
    Invoke-Checked @('run','-d','--name',$container,'--network','agroconecta_application','cloudflare/cloudflared@sha256:a15a3b50b8dabd57ac2090e9484c0247dba9dfa1f0df4d298d4317f9d69eb2d5','tunnel','--no-autoupdate','--url','http://nginx:80')
} elseif ((& docker inspect --format '{{.State.Running}}' $container) -ne 'true') {
    Invoke-Checked @('compose','exec','-T','-u','root','app','php','artisan','down')
    Invoke-Checked @('start',$container)
} else {
    $recentLog = (& docker logs --since 2m $container 2>&1 | Out-String)
    if ($recentLog -match 'Unauthorized: Tunnel not found') {
        Write-Host 'Cloudflare retiró el túnel anterior. Creando una conexión nueva...'
        Invoke-Checked @('restart',$container)
    }
}
$startedAt = & docker inspect --format '{{.State.StartedAt}}' $container
$endpoint = ''
for ($attempt = 0; $attempt -lt 30; $attempt++) {
    $tunnelLog = (& docker logs --since $startedAt $container 2>&1 | Out-String)
    $matchesFound = [regex]::Matches($tunnelLog,'https://[a-z0-9-]+\.trycloudflare\.com')
    if ($matchesFound.Count -gt 0) { $endpoint = $matchesFound[$matchesFound.Count - 1].Value; break }
    Start-Sleep -Seconds 2
}
if (!$endpoint) { throw 'Cloudflare aún no entregó una dirección. Revisa docker logs agrolazo-tunnel y vuelve a ejecutar este script.' }
$statePath = Join-Path $projectRoot '.local/public-access.json'
$previous = if (Test-Path -LiteralPath $statePath) { Get-Content -Raw -LiteralPath $statePath | ConvertFrom-Json } else { $null }
if ($previous -and $previous.url -eq $endpoint -and (Test-Path -LiteralPath "artifacts/android/AgroLazo-$($previous.version).apk")) {
    Invoke-Checked @('compose','exec','-T','-u','root','app','php','artisan','up')
    $healthy = $false
    for ($attempt = 0; $attempt -lt 3; $attempt++) {
        try { $healthy = (Invoke-WebRequest -Uri "$endpoint/app" -TimeoutSec 12).StatusCode -eq 200; if ($healthy) { break } } catch { Start-Sleep -Seconds 2 }
    }
    if ($healthy) {
        Write-Output "AgroLazo está disponible: $endpoint"
        Write-Output "El APK $($previous.version) sigue vigente. No hace falta reinstalarlo."
        return
    }
    Write-Host 'El acceso temporal anterior no responde. Renovando Cloudflare...'
    Invoke-Checked @('restart',$container)
    $startedAt = & docker inspect --format '{{.State.StartedAt}}' $container
    $endpoint = ''
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        $tunnelLog = (& docker logs --since $startedAt $container 2>&1 | Out-String)
        $matchesFound = [regex]::Matches($tunnelLog,'https://[a-z0-9-]+\.trycloudflare\.com')
        if ($matchesFound.Count -gt 0) { $endpoint = $matchesFound[$matchesFound.Count - 1].Value; break }
        Start-Sleep -Seconds 2
    }
    if (!$endpoint) { throw 'Cloudflare no entregó una nueva dirección. Comprueba Internet y vuelve a encender AgroLazo.' }
}
Invoke-Checked @('compose','exec','-T','-u','root','app','php','artisan','down')
$envPath = Join-Path $projectRoot 'application/.env'
$backupDirectory = Join-Path $projectRoot ('.local/public-access-backup/' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Force -Path $backupDirectory | Out-Null
Copy-Item -LiteralPath $envPath -Destination (Join-Path $backupDirectory 'application.env')
$network = (& docker network inspect agroconecta_application | ConvertFrom-Json)[0]
$subnet = $network.IPAM.Config[0].Subnet
$envText = [IO.File]::ReadAllText($envPath)
$settings = @{ APP_ENV='production'; APP_DEBUG='false'; APP_URL=$endpoint; SESSION_SECURE_COOKIE='true'; SESSION_DOMAIN='null'; TRUSTED_PROXY_CIDRS=$subnet; MESSAGING_SIMULATOR_ENABLED='false'; AUTO_VERIFICATION_ENABLED='false'; PLATFORM_INTERNAL_COMMUNICATION='true'; DEMO_PASSWORD='' }
foreach ($item in $settings.GetEnumerator()) {
    $pattern = '(?m)^' + [regex]::Escape($item.Key) + '=.*$'
    $line = $item.Key + '=' + $item.Value
    if ([regex]::IsMatch($envText,$pattern)) { $envText = [regex]::Replace($envText,$pattern,[Text.RegularExpressions.MatchEvaluator]{param($match) $line}) } else { $envText += "`n$line`n" }
}
[IO.File]::WriteAllText($envPath,$envText,(New-Object Text.UTF8Encoding($false)))
Invoke-Checked @('compose','exec','-T','-u','root','app','php','artisan','config:clear')
Invoke-Checked @('compose','exec','-T','-u','root','app','env','APP_CONFIG_CACHE=/var/www/html/bootstrap/cache/http-config.php','php','artisan','config:cache')
Invoke-Checked @('compose','exec','-T','-u','root','app','env','APP_ROUTES_CACHE=/var/www/html/bootstrap/cache/http-routes.php','php','artisan','route:cache')
Invoke-Checked @('compose','exec','-T','-u','root','app','php','artisan','agroconecta:security-check','--production')
Invoke-Checked @('compose','restart','app','queue','scheduler','reverb','realtime-worker')
Invoke-Checked @('compose','exec','-T','nginx','nginx','-s','reload')
Invoke-Checked @('compose','exec','-T','-u','root','app','php','artisan','up')
$publicReady = $false
for ($attempt = 0; $attempt -lt 12; $attempt++) {
    try { $publicReady = (Invoke-WebRequest -Uri "$endpoint/app" -TimeoutSec 12).StatusCode -eq 200; if ($publicReady) { break } } catch { Write-Host 'Esperando a que la dirección HTTPS esté disponible...'; Start-Sleep -Seconds 3 }
}
if (!$publicReady) { throw 'El inicio público no respondió correctamente. No se generó el APK. Comprueba Internet y vuelve a ejecutar encender.ps1.' }
$versionCode = if ($previous) { [Math]::Max(5, [int]$previous.versionCode + 1) } else { 5 }
$version = '1.3.' + ($versionCode - 5)
& (Join-Path $PSScriptRoot 'build.ps1') -ServerUrl $endpoint -VersionName $version -VersionCode $versionCode
$downloadDirectory = Join-Path $projectRoot 'application/public/downloads'
New-Item -ItemType Directory -Force -Path $downloadDirectory | Out-Null
Copy-Item -LiteralPath "artifacts/android/AgroLazo-$version.apk" -Destination (Join-Path $downloadDirectory "AgroLazo-$version.apk")
@{url=$endpoint;container=$container;created=(Get-Date -Format o);version=$version;versionCode=$versionCode} | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8
Write-Output "Listo: $endpoint. Distribuye artifacts/android/AgroLazo-$version.apk para actualizar la dirección de los teléfonos."
Write-Output "Descarga desde el teléfono: $endpoint/downloads/AgroLazo-$version.apk"
} finally { Pop-Location }

