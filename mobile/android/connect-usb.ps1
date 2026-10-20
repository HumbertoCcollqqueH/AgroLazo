[CmdletBinding()]
param([string]$Device = '')
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$adb = Join-Path $projectRoot '.local/android-sdk/platform-tools/adb.exe'
if (!(Test-Path $adb)) { throw 'Ejecuta prepare-sdk.ps1 para instalar Android Platform Tools.' }
$target = @()
if ($Device) { $target = @('-s',$Device) }
$stateFile = Join-Path $projectRoot '.local/public-access.json'
if (!(Test-Path -LiteralPath $stateFile)) { throw 'Primero ejecuta update-public-app.ps1 para preparar el APK con acceso público.' }
$state = Get-Content -Raw -LiteralPath $stateFile | ConvertFrom-Json
$apk = Join-Path $projectRoot "artifacts/android/AgroLazo-$($state.version).apk"
& $adb @target install -r $apk
if ($LASTEXITCODE -ne 0) { throw 'No se pudo instalar el APK.' }
& $adb @target shell am start -n pe.agrolazo.app/.MainActivity
Write-Output 'AgroLazo instalado. Puedes desconectar el cable: la app utiliza internet. La laptop, Docker y Cloudflare deben permanecer activos.'
