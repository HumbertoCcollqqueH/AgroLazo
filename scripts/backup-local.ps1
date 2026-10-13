[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$backupRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.local/backups'))
$runId = [Guid]::NewGuid().ToString('N')
$destination = [IO.Path]::GetFullPath((Join-Path $backupRoot ((Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + $runId)))
if (-not $destination.StartsWith($backupRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'El destino de respaldo no pertenece al proyecto.'
}
$restoreDb = 'agroconecta_restore_' + $runId.Substring(0, 12)
$containerPrefix = '/tmp/agroconecta-backup-' + $runId
$restoreFiles = $containerPrefix + '-restored'
$databaseCreated = $false
$maintenanceEntered = $false
$workersStopped = $false
$runningWorkers = @()
$appContainer = ''
$postgresContainer = ''
$utf8 = [Text.UTF8Encoding]::new($false)
$compose = @('compose', '-f', 'docker-compose.yml', '--profile', 'workers')

function Invoke-Docker {
    param([Parameter(Mandatory = $true)][string[]]$Arguments)
    if ($PSVersionTable.PSVersion.Major -lt 7) {
        $Arguments = @($Arguments | ForEach-Object { $_ -replace '"', '\"' })
    }
    $result = & docker @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw 'Fallo una operacion de respaldo/restauracion. El respaldo incompleto se conserva para revisar.'
    }
    return $result
}

function Write-Manifest {
    param([string]$Name, [string[]]$Arguments)
    $lines = @(Invoke-Docker -Arguments $Arguments)
    $json = $lines -join [Environment]::NewLine
    $manifest = $json | ConvertFrom-Json
    if ($manifest.schema_version -ne 1) { throw 'Manifiesto no reconocido.' }
    [IO.File]::WriteAllText((Join-Path $destination $Name), $json, $utf8)
    return $manifest
}

Push-Location -LiteralPath $projectRoot
try {
    $cliConfigCached = @(Invoke-Docker -Arguments ($compose + @('exec', '-T', 'app', 'sh', '-ec', 'if test -f bootstrap/cache/config.php; then echo yes; else echo no; fi')))
    if (($cliConfigCached -join '').Trim() -eq 'yes') {
        throw 'Este ensayo local requiere configuracion sin cache para aislar DB_DATABASE. Ejecuta php artisan config:clear en app y repite.'
    }
    $running = @(Invoke-Docker -Arguments ($compose + @('ps', '--status', 'running', '--services')))
    if ($running -notcontains 'app' -or $running -notcontains 'postgres') { throw 'Inicia app y postgres antes del respaldo.' }
    $runningWorkers = @($running | Where-Object { $_ -in @('queue', 'scheduler') })
    $appContainer = (@(Invoke-Docker -Arguments ($compose + @('ps', '-q', 'app'))) -join '').Trim()
    $postgresContainer = (@(Invoke-Docker -Arguments ($compose + @('ps', '-q', 'postgres'))) -join '').Trim()
    $sourceDb = (@(Invoke-Docker -Arguments @('exec', $postgresContainer, 'sh', '-ec', 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "SELECT current_database()"')) -join '').Trim()
    if ($sourceDb -notmatch '\A[a-zA-Z0-9_]+\z' -or $restoreDb -notmatch '\Aagroconecta_restore_[a-f0-9]{12}\z' -or $sourceDb -eq $restoreDb) {
        throw 'Nombres de base de datos no validos para una restauracion aislada.'
    }
    [IO.Directory]::CreateDirectory($destination) | Out-Null
    Write-Host '[1/5] Pausa breve de escrituras para respaldar base y archivos del mismo estado...'
    $alreadyDown = @(Invoke-Docker -Arguments ($compose + @('exec', '-T', 'app', 'sh', '-ec', 'if test -f storage/framework/down; then echo yes; else echo no; fi')))
    if (($alreadyDown -join '').Trim() -ne 'yes') {
        Invoke-Docker -Arguments ($compose + @('exec', '-T', 'app', 'php', 'artisan', 'down', '--retry=60')) | Out-Host
        $maintenanceEntered = $true
    }
    if ($runningWorkers.Count -gt 0) {
        $workersStopped = $true
        Invoke-Docker -Arguments ($compose + @('stop', '--timeout', '100') + $runningWorkers) | Out-Host
    }
    $before = Write-Manifest -Name 'source-manifest.json' -Arguments ($compose + @('exec', '-T', 'app', 'php', 'artisan', 'agroconecta:verify-restore', ('--expected-database=' + $sourceDb)))
    Write-Host '[2/5] Copiando PostgreSQL y almacenamiento privado...'
    Invoke-Docker -Arguments @('exec', $postgresContainer, 'sh', '-ec', 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom --no-owner --no-acl --file="$1"', '--', ($containerPrefix + '.dump')) | Out-Host
    Invoke-Docker -Arguments @('exec', '--user', 'root', $appContainer, 'tar', '-C', '/var/www/html/storage/app/private', '-cf', ($containerPrefix + '.tar'), '.') | Out-Host
    Invoke-Docker -Arguments @('cp', ($postgresContainer + ':' + $containerPrefix + '.dump'), (Join-Path $destination 'database.dump')) | Out-Host
    Invoke-Docker -Arguments @('cp', ($appContainer + ':' + $containerPrefix + '.tar'), (Join-Path $destination 'private-files.tar')) | Out-Host
    $after = Write-Manifest -Name 'source-after-manifest.json' -Arguments ($compose + @('exec', '-T', 'app', 'php', 'artisan', 'agroconecta:verify-restore', ('--expected-database=' + $sourceDb)))
    foreach ($field in @('database_sha256', 'sequences_sha256', 'files_sha256', 'decrypted_fields', 'verified_file_references')) {
        if ($before.$field -ne $after.$field) { throw 'El origen cambio durante el respaldo. Repite cuando no haya escrituras externas.' }
    }
    # Reopen the application as soon as the consistent copy has been captured.
    if ($maintenanceEntered) {
        Invoke-Docker -Arguments ($compose + @('exec', '-T', 'app', 'php', 'artisan', 'up')) | Out-Host
        $maintenanceEntered = $false
    }
    if ($workersStopped) {
        Invoke-Docker -Arguments ($compose + @('start') + $runningWorkers) | Out-Host
        $workersStopped = $false
    }

    Write-Host '[3/5] Restaurando las copias guardadas en una base y una carpeta temporales...'
    Invoke-Docker -Arguments @('cp', (Join-Path $destination 'database.dump'), ($postgresContainer + ':' + $containerPrefix + '-from-disk.dump')) | Out-Host
    Invoke-Docker -Arguments @('cp', (Join-Path $destination 'private-files.tar'), ($appContainer + ':' + $containerPrefix + '-from-disk.tar')) | Out-Host
    Invoke-Docker -Arguments @('exec', $postgresContainer, 'sh', '-ec', 'createdb -U "$POSTGRES_USER" --template=template0 "$1"', '--', $restoreDb) | Out-Host
    $databaseCreated = $true
    Invoke-Docker -Arguments @('exec', $postgresContainer, 'sh', '-ec', 'pg_restore -U "$POSTGRES_USER" --no-owner --no-acl --exit-on-error --dbname="$1" "$2"', '--', $restoreDb, ($containerPrefix + '-from-disk.dump')) | Out-Host
    Invoke-Docker -Arguments @('exec', '--user', 'root', $appContainer, 'mkdir', '-p', $restoreFiles) | Out-Host
    Invoke-Docker -Arguments @('exec', '--user', 'root', $appContainer, 'tar', '-xf', ($containerPrefix + '-from-disk.tar'), '-C', $restoreFiles) | Out-Host
    Write-Host '[4/5] Comparando tablas, secuencias, descifrado y huellas de todos los archivos...'
    $restored = Write-Manifest -Name 'restored-manifest.json' -Arguments ($compose + @('exec', '-T', '-e', ('DB_DATABASE=' + $restoreDb), '-e', 'DB_URL=', 'app', 'php', 'artisan', 'agroconecta:verify-restore', ('--expected-database=' + $restoreDb), ('--files-root=' + $restoreFiles)))
    foreach ($field in @('database_sha256', 'sequences_sha256', 'files_sha256', 'decrypted_fields', 'verified_file_references', 'file_count')) {
        if ($before.$field -ne $restored.$field) { throw ('La copia restaurada no coincide: ' + $field) }
    }
    $result = [ordered]@{
        completed_at_utc = [DateTime]::UtcNow.ToString('o')
        restored_database = $restoreDb
        result = 'passed'
        database_sha256 = (Get-FileHash -LiteralPath (Join-Path $destination 'database.dump') -Algorithm SHA256).Hash.ToLowerInvariant()
        archive_sha256 = (Get-FileHash -LiteralPath (Join-Path $destination 'private-files.tar') -Algorithm SHA256).Hash.ToLowerInvariant()
        manifest_database_sha256 = $restored.database_sha256
        manifest_files_sha256 = $restored.files_sha256
        file_count = $restored.file_count
        decrypted_fields = $restored.decrypted_fields
    }
    [IO.File]::WriteAllText((Join-Path $destination 'result.json'), ($result | ConvertTo-Json), $utf8)
    Write-Host ('[5/5] Restauracion verificada. Respaldo conservado en ' + $destination)
    Write-Host 'Conserva APP_KEY y PRODUCER_LOOKUP_KEY originales por separado. No se incluyen en este respaldo.'
}
finally {
    try {
        if ($databaseCreated -and $restoreDb -match '\Aagroconecta_restore_[a-f0-9]{12}\z') {
            Invoke-Docker -Arguments @('exec', $postgresContainer, 'sh', '-ec', 'dropdb -U "$POSTGRES_USER" "$1"', '--', $restoreDb) | Out-Host
        }
    }
    finally {
        try {
            if ($maintenanceEntered) {
                Invoke-Docker -Arguments ($compose + @('exec', '-T', 'app', 'php', 'artisan', 'up')) | Out-Host
            }
        }
        finally {
            try {
                if ($workersStopped -and $runningWorkers.Count -gt 0) {
                    Invoke-Docker -Arguments ($compose + @('start') + $runningWorkers) | Out-Host
                }
            }
            finally {
                # Only newly generated /tmp paths in the containers are removed.
                if ($containerPrefix -match '\A/tmp/agroconecta-backup-[a-f0-9]{32}\z') {
                    if ($appContainer) {
                        & docker exec --user root $appContainer rm -f -- ($containerPrefix + '.tar') ($containerPrefix + '-from-disk.tar')
                        & docker exec --user root $appContainer rm -rf -- $restoreFiles
                    }
                    if ($postgresContainer) {
                        & docker exec $postgresContainer rm -f -- ($containerPrefix + '.dump') ($containerPrefix + '-from-disk.dump')
                    }
                }
                Pop-Location
            }
        }
    }
}

