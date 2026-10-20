[CmdletBinding()]
param(
    [switch]$Build,
    [switch]$SkipAssets
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$composeArguments = @('compose', '-f', 'docker-compose.yml')
if (Test-Path -LiteralPath (Join-Path $projectRoot 'docker-compose.evolution.yml')) {
    $composeArguments += @('-f', 'docker-compose.evolution.yml')
}

function Invoke-LocalCommand {
    param(
        [Parameter(Mandatory = $true)][string]$Executable,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [Parameter(Mandatory = $true)][string]$Step
    )

    & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Step fallo (codigo $LASTEXITCODE). Revisa el error anterior y vuelve a ejecutar el script cuando este resuelto."
    }
}

Push-Location -LiteralPath $projectRoot
try {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'No se encuentra Docker. Instala y abre Docker Desktop con contenedores Linux antes de continuar.'
    }
    if ($SkipAssets -and -not (Test-Path -LiteralPath 'application/public/build/manifest.json')) {
        throw 'Todavia no existen assets compilados. Ejecuta el script sin -SkipAssets para preparar la interfaz.'
    }

    Write-Host '[1/8] Comprobando Docker y preparando el entorno local...'
    Invoke-LocalCommand -Executable 'docker' -Arguments @('info', '--format', '{{.OSType}}') -Step 'La conexion con Docker Desktop'
    Invoke-LocalCommand -Executable 'docker' -Arguments @('compose', 'version') -Step 'La comprobacion de Docker Compose'
    & (Join-Path $PSScriptRoot 'initialize-environment.ps1')
    Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('config', '--quiet')) -Step 'La validacion de Compose'

    Write-Host '[2/8] Preparando la imagen y las dependencias PHP...'
    $appImage = & docker image ls --quiet agroconecta-app:local
    if ($LASTEXITCODE -ne 0) {
        throw 'No se pudo consultar la imagen local de AgroConecta.'
    }
    if ($Build -or [string]::IsNullOrWhiteSpace(($appImage -join ''))) {
        Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('build', 'app')) -Step 'La construccion de la imagen PHP'
    }
    Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('run', '--rm', '--no-deps', '--user', 'root', 'app', 'mkdir', '-p', 'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/framework/testing', 'bootstrap/cache')) -Step 'La preparacion de directorios de cache Linux'
    Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('run', '--rm', '--no-deps', '--user', 'root', '--env', 'COMPOSER_ALLOW_SUPERUSER=1', 'app', 'composer', 'install', '--no-interaction', '--prefer-dist')) -Step 'La instalacion de Composer'

    Write-Host '[3/8] Comprobando la clave y los permisos de Laravel...'
    $applicationEnv = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'application/.env'))
    $keyMatch = [regex]::Match($applicationEnv, '(?m)^APP_KEY=([^\r\n]*)')
    $applicationKey = $keyMatch.Groups[1].Value.Trim().Trim('"').Trim("'")
    if ([string]::IsNullOrWhiteSpace($applicationKey)) {
        Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('run', '--rm', '--no-deps', '--user', 'root', 'app', 'php', 'artisan', 'key:generate', '--no-interaction')) -Step 'La generacion inicial de APP_KEY'
    }
    Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('run', '--rm', '--no-deps', '--user', 'root', 'app', 'chown', '-R', 'www-data:www-data', 'storage', 'bootstrap/cache')) -Step 'La preparacion de permisos de Laravel'

    Write-Host '[4/8] Iniciando PostgreSQL y Redis y aplicando migraciones...'
    Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('up', '-d', '--wait', '--wait-timeout', '120', 'postgres', 'redis')) -Step 'El inicio de PostgreSQL y Redis'
    Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('run', '--rm', '--no-deps', 'app', 'php', 'artisan', 'migrate', '--no-interaction')) -Step 'La aplicacion de migraciones locales'
    if (Test-Path -LiteralPath 'application/app/Console/Commands/PrepareProducerKey.php') {
        Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('run', '--rm', '--no-deps', '--user', 'root', 'app', 'php', 'artisan', 'agroconecta:prepare-producer-key', '--no-interaction')) -Step 'La comprobacion de la clave de identidad de productores'
    }
    if (Test-Path -LiteralPath 'application/database/seeders/RolesAndPermissionsSeeder.php') {
        Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('run', '--rm', '--no-deps', 'app', 'php', 'artisan', 'db:seed', '--no-interaction')) -Step 'La preparacion de roles y permisos'
    }
    if ($applicationEnv -match '(?m)^APP_ENV=local\s*$' -and (Test-Path -LiteralPath 'application/database/seeders/DemoUsersSeeder.php')) {
        Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('run', '--rm', '--no-deps', 'app', 'php', 'artisan', 'db:seed', '--class=DemoUsersSeeder', '--no-interaction')) -Step 'La creacion inicial de los accesos locales'
    }

    Write-Host '[5/8] Preparando la base aislada para pruebas...'
    $rootEnv = [System.IO.File]::ReadAllText((Join-Path $projectRoot '.env'))
    $databaseUser = [regex]::Match($rootEnv, '(?m)^DB_USERNAME=([^\r\n]*)').Groups[1].Value.Trim().Trim('"').Trim("'")
    $databaseName = [regex]::Match($rootEnv, '(?m)^DB_DATABASE=([^\r\n]*)').Groups[1].Value.Trim().Trim('"').Trim("'")
    if ([string]::IsNullOrWhiteSpace($databaseUser) -or [string]::IsNullOrWhiteSpace($databaseName)) {
        throw 'Define DB_USERNAME y DB_DATABASE en el archivo .env de la raiz antes de preparar la base de pruebas.'
    }
    $databaseQuery = "SELECT 1 FROM pg_database WHERE datname = 'agroconecta_testing'"
    $testDatabaseExists = & docker @composeArguments exec -T postgres psql --username $databaseUser --dbname $databaseName --no-password --set=ON_ERROR_STOP=1 --tuples-only --no-align --command $databaseQuery
    if ($LASTEXITCODE -ne 0) {
        throw 'No se pudo comprobar la base de datos de pruebas.'
    }
    if (($testDatabaseExists -join '').Trim() -ne '1') {
        Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('exec', '-T', 'postgres', 'createdb', '--username', $databaseUser, '--maintenance-db', $databaseName, '--no-password', 'agroconecta_testing')) -Step 'La creacion de la base de datos de pruebas'
    }

    Write-Host '[6/8] Preparando los recursos de la interfaz...'
    if (-not $SkipAssets) {
        Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('--profile', 'tools', 'run', '--rm', '--no-deps', 'assets')) -Step 'La instalacion y compilacion de interfaz en Linux'
    } else {
        Write-Host 'Se conservan los assets compilados existentes (-SkipAssets).'
    }

    Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('run', '--rm', '--no-deps', 'app', 'php', 'artisan', 'view:cache', '--no-interaction')) -Step 'La compilacion de vistas para evitar demoras en la primera visita'
    Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('run', '--rm', '--no-deps', 'app', 'env', 'APP_CONFIG_CACHE=/var/www/html/bootstrap/cache/http-config.php', 'php', 'artisan', 'config:cache', '--no-interaction')) -Step 'La cache de configuracion exclusiva de HTTP'
    Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('run', '--rm', '--no-deps', 'app', 'env', 'APP_ROUTES_CACHE=/var/www/html/bootstrap/cache/http-routes.php', 'php', 'artisan', 'route:cache', '--no-interaction')) -Step 'La cache de rutas exclusiva de HTTP'

    Write-Host '[7/8] Iniciando la web, el worker y las tareas programadas...'
    if ($applicationEnv -match '(?m)^APP_ENV=local\s*$' -and $applicationEnv -match '(?m)^MAIL_HOST=mailpit\s*$') {
        Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('--profile', 'mail', 'up', '-d', 'mailpit')) -Step 'El inicio del buzon de correo local'
    }
    Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('up', '-d', '--wait', '--wait-timeout', '120', 'app', 'nginx', 'reverb', 'realtime-worker')) -Step 'El inicio de la aplicacion web y las conversaciones'
    Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('--profile', 'workers', 'up', '-d', '--no-deps', '--force-recreate', 'queue', 'scheduler')) -Step 'El inicio de los procesos de fondo'
    if (Test-Path -LiteralPath (Join-Path $projectRoot 'docker-compose.evolution.yml')) {
        Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('--profile', 'messaging', 'up', '-d', '--wait', '--wait-timeout', '180', 'evolution')) -Step 'El inicio de Evolution y sus servicios privados'
    }

    Write-Host '[8/8] Verificando el funcionamiento del entorno...'
    Invoke-LocalCommand -Executable 'docker' -Arguments ($composeArguments + @('exec', '-T', 'app', 'php', 'artisan', 'agroconecta:health', '--wait=75')) -Step 'La comprobacion de salud de AgroConecta'
    $portMatch = [regex]::Match($rootEnv, '(?m)^APP_PORT=([0-9]+)\s*$')
    $applicationPort = if ($portMatch.Success) { $portMatch.Groups[1].Value } else { '8080' }
    Write-Host "AgroConecta esta disponible en http://localhost:$applicationPort"
    Write-Host 'Para detenerlo conservando los datos: .\scripts\stop.ps1'
} finally {
    Pop-Location
}
