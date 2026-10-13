Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$rootEnvPath = Join-Path $projectRoot '.env'
$applicationEnvPath = Join-Path $projectRoot 'application/.env'
$utf8 = New-Object System.Text.UTF8Encoding($false)

function New-LocalSecret {
    $bytes = New-Object byte[] 32
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [System.BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
}

if (-not (Test-Path -LiteralPath $rootEnvPath)) {
    $templatePath = Join-Path $projectRoot '.env.example'
    [System.IO.File]::WriteAllText($rootEnvPath, [System.IO.File]::ReadAllText($templatePath), $utf8)
}

$rootText = [System.IO.File]::ReadAllText($rootEnvPath)
$settings = @{}
foreach ($line in ($rootText -split '\r?\n')) {
    if ($line -match '^([A-Z][A-Z0-9_]*)=(.*)$') {
        $settings[$Matches[1]] = $Matches[2].Trim('"')
    }
}

foreach ($key in @('DB_PASSWORD', 'REDIS_PASSWORD', 'EVOLUTION_API_KEY', 'EVOLUTION_WEBHOOK_SECRET', 'EVOLUTION_DB_PASSWORD')) {
    if (-not $settings.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($settings[$key])) {
        $settings[$key] = New-LocalSecret
        if ($rootText -match ('(?m)^' + $key + '=')) {
            $rootText = [regex]::Replace($rootText, '(?m)^' + $key + '=[^\r\n]*', $key + '=' + $settings[$key])
        } else {
            $rootText = $rootText.TrimEnd() + "`n" + $key + '=' + $settings[$key] + "`n"
        }
    }
}

[System.IO.File]::WriteAllText($rootEnvPath, $rootText, $utf8)

if (-not (Test-Path -LiteralPath $applicationEnvPath)) {
    $applicationText = @"
APP_NAME=AgroConecta
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:$($settings['APP_PORT'])
APP_LOCALE=es
APP_FALLBACK_LOCALE=es
APP_FAKER_LOCALE=es_PE
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12
LOG_CHANNEL=stderr
LOG_LEVEL=info
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=$($settings['DB_DATABASE'])
DB_USERNAME=$($settings['DB_USERNAME'])
DB_PASSWORD=$($settings['DB_PASSWORD'])
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=false
QUEUE_CONNECTION=redis
REDIS_QUEUE_RETRY_AFTER=120
CACHE_STORE=redis
CACHE_PREFIX=agroconecta_cache_
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=$($settings['REDIS_PASSWORD'])
REDIS_PORT=6379
REDIS_PREFIX=agroconecta_
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS=no-reply@agroconecta.test
MAIL_FROM_NAME=AgroConecta
AUTO_VERIFICATION_ENABLED=false
PRODUCER_LOOKUP_KEY=
MESSAGING_PROVIDER=fake
EVOLUTION_URL=http://evolution:8080
EVOLUTION_API_KEY=$($settings['EVOLUTION_API_KEY'])
EVOLUTION_INSTANCE=agroconecta
EVOLUTION_WEBHOOK_SECRET=$($settings['EVOLUTION_WEBHOOK_SECRET'])
EVOLUTION_INTEGRATION=WHATSAPP-BUSINESS
META_APP_SECRET=
META_VERIFY_TOKEN=
META_ACCESS_TOKEN=
META_PHONE_NUMBER_ID=
META_BUSINESS_ACCOUNT_ID=
MESSAGING_LIVE_ENABLED=false
MESSAGING_SIMULATOR_ENABLED=true
MESSAGING_NOTICE_VERSION=local-test-v1
MESSAGING_NOTICE_APPROVED=false
WHATSAPP_ENABLED=false
WHATSAPP_PUBLIC_WEBHOOK_URL=
WHATSAPP_APPROVED_TEMPLATES='{}'
SMS_ENABLED=false
SMS_TWO_WAY_VERIFIED=false
SMS_MAX_SEGMENTS=3
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_FROM_NUMBER=
TWILIO_INBOUND_URL=
TWILIO_STATUS_URL=
VITE_APP_NAME=AgroConecta
"@
    [System.IO.File]::WriteAllText($applicationEnvPath, $applicationText + "`n", $utf8)
    Write-Output 'Entorno local creado. Las claves se guardaron fuera de Git y no se muestran.'
} else {
    Write-Output 'El entorno de Laravel ya existe y se ha conservado sin sobrescribirlo.'
}

Write-Output 'Se conserva la configuracion existente. Consulta el estado de los canales en Administracion o con messaging:check.'

$applicationText = [System.IO.File]::ReadAllText($applicationEnvPath)
if ($applicationText -match '(?m)^APP_ENV=local\s*$') {
    $demoMatch = [regex]::Match($applicationText, '(?m)^DEMO_PASSWORD=([^\r\n]*)')
    $demoPassword = $demoMatch.Groups[1].Value.Trim().Trim('"').Trim("'")
    if ([string]::IsNullOrWhiteSpace($demoPassword)) {
        $demoPassword = 'Agro9' + (New-LocalSecret).Substring(0, 20)
        if ($demoMatch.Success) {
            $applicationText = [regex]::Replace($applicationText, '(?m)^DEMO_PASSWORD=[^\r\n]*', 'DEMO_PASSWORD=' + $demoPassword)
        } else {
            $applicationText = $applicationText.TrimEnd() + "`nDEMO_PASSWORD=$demoPassword`n"
        }
    }
    if ($applicationText -match '(?m)^MAIL_HOST=mailpit\s*$') {
        $applicationText = [regex]::Replace($applicationText, '(?m)^MAIL_MAILER=log\s*$', 'MAIL_MAILER=smtp')
    }
    [System.IO.File]::WriteAllText($applicationEnvPath, $applicationText, $utf8)
    $localDirectory = Join-Path $projectRoot '.local'
    [System.IO.Directory]::CreateDirectory($localDirectory) | Out-Null
    $accessText = "AgroConecta - acceso local de prueba`nhttp://localhost:$($settings['APP_PORT'])/login`n`nAdministrador: admin@agroconecta.test`nCoordinador: coordinador@agroconecta.test`nComprador: comprador@agroconecta.test`nClave inicial de las tres cuentas: $demoPassword`n`nSe crean al iniciar. Reiniciar no cambia sus contrasenas.`nSi cambiaste una clave, usa la nueva o recuperala en /forgot-password.`nCorreos locales: http://localhost:8025`n"
    [System.IO.File]::WriteAllText((Join-Path $localDirectory 'demo-access.txt'), $accessText, $utf8)
    Write-Output 'Accesos de prueba guardados en .local/demo-access.txt (excluido de Git).'
}
