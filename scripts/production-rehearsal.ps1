[CmdletBinding()]
param([switch]$SkipBuild)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$workFolder = Join-Path $projectRoot '.local/production-rehearsal'
$secretFolder = Join-Path $workFolder 'secrets'
$tlsFolder = Join-Path $workFolder 'tls'
$envPath = Join-Path $workFolder 'compose.env'
$resultPath = Join-Path $workFolder 'result.json'
$utf8 = [Text.UTF8Encoding]::new($false)
$compose = @('compose', '--env-file', $envPath, '-p', 'agroconecta-production-rehearsal', '-f', 'docker-compose.production.yml')

function Invoke-Docker {
    param([Parameter(Mandatory = $true)][string[]]$Arguments)
    if ($PSVersionTable.PSVersion.Major -lt 7) {
        $Arguments = @($Arguments | ForEach-Object { $_ -replace '"', '\"' })
    }
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) { throw 'Fallo el ensayo de produccion. Revisa la operacion anterior; los volumenes se conservan.' }
}
function New-SecretIfMissing {
    param([string]$Name, [switch]$Base64)
    $path = Join-Path $secretFolder $Name
    if (Test-Path -LiteralPath $path) { return }
    $bytes = New-Object byte[] 32
    $random = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $random.GetBytes($bytes) } finally { $random.Dispose() }
    $value = if ($Base64) { 'base64:' + [Convert]::ToBase64String($bytes) } else { ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant() }
    [IO.File]::WriteAllText($path, $value, $utf8)
}

Push-Location -LiteralPath $projectRoot
try {
    [IO.Directory]::CreateDirectory($workFolder) | Out-Null
    [IO.File]::WriteAllText($resultPath, (@{result='running'; checked_at_utc=[DateTime]::UtcNow.ToString('o'); public_deployment=$false} | ConvertTo-Json), $utf8)
    $existingDatabase = @(Invoke-Docker -Arguments @('volume', 'ls', '--filter', 'name=^agroconecta-production-rehearsal_postgres_data$', '--format', '{{.Name}}'))
    if ($existingDatabase.Count -gt 0) {
        foreach ($name in @('app_key', 'producer_lookup_key', 'db_admin_password', 'db_app_password', 'db_migration_password', 'redis_password')) {
            if (-not (Test-Path -LiteralPath (Join-Path $secretFolder $name))) {
                throw 'Ya existe persistencia del ensayo y falta una clave. Restaura los secretos originales; no se generaran otros.'
            }
        }
    }
    [IO.Directory]::CreateDirectory($secretFolder) | Out-Null
    [IO.Directory]::CreateDirectory($tlsFolder) | Out-Null
    New-SecretIfMissing -Name app_key -Base64
    foreach ($name in @('producer_lookup_key', 'db_admin_password', 'db_app_password', 'db_migration_password', 'redis_password')) {
        New-SecretIfMissing -Name $name
    }
    $values = @(
        'PROD_APP_IMAGE=agroconecta-release:rehearsal',
        'PROD_WEB_IMAGE=agroconecta-web:rehearsal',
        'PROD_APP_URL=https://localhost:8443',
        'PROD_SERVER_NAME=localhost',
        'PROD_BIND_ADDRESS=127.0.0.1',
        'PROD_HTTP_PORT=8088',
        'PROD_HTTPS_PORT=8443',
        ('PROD_SECRETS_DIR=' + $secretFolder.Replace('\', '/')),
        ('PROD_TLS_DIR=' + $tlsFolder.Replace('\', '/')))
    [IO.File]::WriteAllText($envPath, ($values -join [Environment]::NewLine) + [Environment]::NewLine, $utf8)
    Write-Host '[1/6] Preparando certificado exclusivo del ensayo local, sin instalar confianza en Windows...'
    if (-not $SkipBuild) {
        Invoke-Docker -Arguments @('build', '-t', 'agroconecta-app:local', 'docker/php')
    }
    if (-not (Test-Path -LiteralPath (Join-Path $tlsFolder 'server.crt'))) {
        Invoke-Docker -Arguments @('run', '--rm', '--user', 'root', '--volume', ($tlsFolder + ':/out'), 'agroconecta-app:local', 'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-sha256', '-days', '7', '-nodes', '-keyout', '/out/server.key', '-out', '/out/server.crt', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1')
    }
    if (-not (Test-Path -LiteralPath (Join-Path $tlsFolder 'server.key'))) { throw 'Falta la clave TLS original del ensayo.' }
    Invoke-Docker -Arguments @('run', '--rm', '--user', 'root', '--volume', ($tlsFolder + ':/out'), 'agroconecta-app:local', 'sh', '-ec', 'chown 101:101 /out/server.key && chmod 400 /out/server.key')
    Invoke-Docker -Arguments ($compose + @('config', '--quiet'))
    Write-Host '[2/6] Construyendo imagenes inmutables de aplicacion y web...'
    if (-not $SkipBuild) {
        Invoke-Docker -Arguments ($compose + @('build', 'app', 'nginx'))
    }
    Write-Host '[3/6] Iniciando PostgreSQL y Redis aislados y aplicando migraciones con su propio usuario...'
    Invoke-Docker -Arguments ($compose + @('up', '-d', '--wait', '--wait-timeout', '120', 'postgres', 'redis'))
    Invoke-Docker -Arguments ($compose + @('run', '--rm', 'migrate'))
    Invoke-Docker -Arguments ($compose + @('run', '--rm', 'migrate', 'php', 'artisan', 'db:seed', '--force', '--no-interaction'))
    Write-Host '[4/6] Iniciando aplicacion HTTPS, cola y scheduler sin usuarios demo...'
    Invoke-Docker -Arguments ($compose + @('up', '-d', '--wait', '--wait-timeout', '120', 'app', 'queue', 'scheduler', 'nginx'))
    Invoke-Docker -Arguments ($compose + @('exec', '-T', 'app', '/usr/local/bin/agroconecta-entrypoint', 'php', 'artisan', 'agroconecta:security-check', '--production'))
    Invoke-Docker -Arguments ($compose + @('exec', '-T', 'app', '/usr/local/bin/agroconecta-entrypoint', 'php', 'artisan', 'agroconecta:ready', '--core'))
    Write-Host '[5/6] Comprobando HTTPS y cookies con el certificado del ensayo...'
    $headersPath = Join-Path $workFolder 'https-headers.txt'
    $bodyPath = Join-Path $workFolder 'login.html'
    $status = & curl.exe --silent --show-error --cacert (Join-Path $tlsFolder 'server.crt') --dump-header $headersPath --output $bodyPath --write-out '%{http_code}' 'https://localhost:8443/login'
    if ($LASTEXITCODE -ne 0 -or $status -ne '200') { throw 'HTTPS no paso la validacion del certificado y la respuesta de login.' }
    $headers = [IO.File]::ReadAllText($headersPath)
    foreach ($pattern in @('(?im)^Strict-Transport-Security:', '(?im)^Content-Security-Policy:', '(?im)^Set-Cookie:.*;\s*secure;', '(?im)^Set-Cookie:.*;\s*httponly;', '(?im)^Set-Cookie:.*samesite=lax')) {
        if ($headers -notmatch $pattern) { throw 'Falta un control de transporte o de cookie.' }
    }
    $asset = [regex]::Match([IO.File]::ReadAllText($bodyPath), 'href="(https://localhost:8443/build/[^"\s]+\.css)"')
    if (-not $asset.Success) { throw 'No se encontro la hoja de estilos compilada con URL HTTPS.' }
    $status = & curl.exe --silent --show-error --cacert (Join-Path $tlsFolder 'server.crt') --output NUL --write-out '%{http_code}' $asset.Groups[1].Value
    if ($LASTEXITCODE -ne 0 -or $status -ne '200') { throw 'La hoja de estilos de la imagen web no esta disponible.' }
    foreach ($route in @('/dev/status', '/dev/message-simulator')) {
        $status = & curl.exe --silent --show-error --cacert (Join-Path $tlsFolder 'server.crt') --output NUL --write-out '%{http_code}' ('https://localhost:8443' + $route)
        if ($LASTEXITCODE -ne 0 -or $status -ne '404') { throw 'Una ruta de desarrollo sigue expuesta.' }
    }
    $roleQuery = "SELECT rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls, has_schema_privilege(current_user, 'public', 'CREATE'), has_database_privilege(current_database(), 'TEMP') FROM pg_roles WHERE rolname = current_user;"
    $roleResult = $roleQuery | & docker @compose exec -T postgres sh -ec 'PGPASSWORD=$(cat /run/secrets/db_app_password) exec psql -h 127.0.0.1 -U agroconecta_app -d agroconecta -At'
    if ($LASTEXITCODE -ne 0 -or ($roleResult -join '').Trim() -ne 'f|f|f|f|f|f|f') { throw 'El usuario de aplicacion tiene privilegios inesperados.' }
    $imageChecks = 'test "$(id -u)" = 33 && test ! -e /var/www/html/.env && test ! -d /var/www/html/tests && test ! -d /var/www/html/vendor/phpunit && test ! -d /var/www/html/node_modules && test -z "$(find /var/www/html/storage -type f)"'
    Invoke-Docker -Arguments @('run', '--rm', '--entrypoint', 'sh', 'agroconecta-release:rehearsal', '-ec', $imageChecks)
    Write-Host '[6/6] Verificando un trabajo real y la senal del scheduler...'
    Invoke-Docker -Arguments ($compose + @('exec', '-T', 'app', '/usr/local/bin/agroconecta-entrypoint', 'php', 'artisan', 'agroconecta:health', '--wait=60'))
    [IO.File]::WriteAllText($resultPath, (@{result='passed'; checked_at_utc=[DateTime]::UtcNow.ToString('o'); url='https://localhost:8443'; app_role_restricted=$true; public_deployment=$false} | ConvertTo-Json), $utf8)
    Write-Host 'Ensayo aprobado. La demo sigue en http://localhost:8080. No se publico el sistema ni se contrataron servicios.'
    Write-Host ('Configuracion conservada en ' + $envPath)
}
catch {
    [IO.File]::WriteAllText($resultPath, (@{result='failed'; checked_at_utc=[DateTime]::UtcNow.ToString('o'); public_deployment=$false} | ConvertTo-Json), $utf8)
    throw
}
finally {
    Pop-Location
}

