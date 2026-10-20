[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$ServerUrl,
    [ValidatePattern('^[0-9]+\.[0-9]+\.[0-9]+$')][string]$VersionName = '1.3.3',
    [ValidateRange(5,2100000000)][int]$VersionCode = 8,
    [string]$SdkRoot = '',
    [string]$SigningDirectory = '',
    [string]$OutputDirectory = ''
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$endpoint = [Uri]$ServerUrl
if (!$endpoint.IsAbsoluteUri -or $endpoint.Scheme -ne 'https' -or $endpoint.UserInfo -or $endpoint.Query -or $endpoint.Fragment -or $endpoint.AbsolutePath -ne '/') { throw 'ServerUrl debe ser un origen HTTPS, sin rutas ni credenciales.' }
$ServerUrl = $endpoint.GetLeftPart([UriPartial]::Authority)
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if (!$SdkRoot) { $SdkRoot = Join-Path $projectRoot '.local/android-sdk' }
if (!$SigningDirectory) { $SigningDirectory = Join-Path $projectRoot '.local/android-signing' }
if (!$OutputDirectory) { $OutputDirectory = Join-Path $projectRoot 'artifacts/android' }
$buildRoot = Join-Path $projectRoot ('.local/android-build/' + [Guid]::NewGuid().ToString('N'))
$buildTools = Join-Path $SdkRoot 'android-16'
$androidJar = Join-Path $SdkRoot 'android-36/android.jar'
if (!(Test-Path $androidJar) -or !(Test-Path (Join-Path $buildTools 'aapt2.exe'))) { throw 'Falta el SDK Android 36 y build-tools 36.0.0. Ejecuta prepare-sdk.ps1.' }
New-Item -ItemType Directory -Force $buildRoot, "$buildRoot/generated", "$buildRoot/classes", "$buildRoot/dex", $OutputDirectory, $SigningDirectory | Out-Null
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'res') -Destination "$buildRoot/res" -Recurse
$resourcePath = Join-Path $buildRoot 'res/values/styles.xml'
[xml]$resources = Get-Content -Raw -LiteralPath $resourcePath
$resources.SelectSingleNode('/resources/string[@name="default_server"]').InnerText = $ServerUrl
$association = ConvertTo-Json -InputObject @(@{ relation = @('delegate_permission/common.get_login_creds'); target = @{ namespace = 'web'; site = $ServerUrl } }) -Depth 5 -Compress
$resources.SelectSingleNode('/resources/string[@name="asset_statements"]').InnerText = $association.Replace('"', '\"')
$resources.Save($resourcePath)
[xml]$manifest = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'AndroidManifest.xml')
[void]$manifest.manifest.SetAttribute('versionName', 'http://schemas.android.com/apk/res/android', $VersionName)
[void]$manifest.manifest.SetAttribute('versionCode', 'http://schemas.android.com/apk/res/android', [string]$VersionCode)
$manifest.Save("$buildRoot/AndroidManifest.xml")
function Invoke-Checked { param([string]$Program, [string[]]$Arguments) & $Program @Arguments; if ($LASTEXITCODE -ne 0) { throw "Falló $Program" } }
$keystore = Join-Path $SigningDirectory 'agrolazo-release.jks'
$secretFile = Join-Path $SigningDirectory 'signing.json'
if (!(Test-Path $keystore)) {
    if (Test-Path $secretFile) { throw 'Existe configuración de firma sin la llave. Restaura el respaldo antes de continuar.' }
    $secret = [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(24))
    @{alias='agrolazo'; password=$secret; package='pe.agrolazo.app'; created=(Get-Date -Format 'yyyy-MM-dd')} | ConvertTo-Json | Set-Content -LiteralPath $secretFile -Encoding utf8
    $env:AGROLAZO_SIGNING_PASSWORD=$secret
    Invoke-Checked 'keytool' @('-genkeypair','-noprompt','-keystore',$keystore,'-storetype','JKS','-storepass:env','AGROLAZO_SIGNING_PASSWORD','-keypass:env','AGROLAZO_SIGNING_PASSWORD','-alias','agrolazo','-keyalg','RSA','-keysize','3072','-validity','10000','-dname','CN=AgroLazo, OU=Android, O=AgroLazo, C=PE')
}
$signing = Get-Content -Raw -LiteralPath $secretFile | ConvertFrom-Json
$env:AGROLAZO_SIGNING_PASSWORD=$signing.password
try {
    Invoke-Checked (Join-Path $buildTools 'aapt2.exe') @('compile','--dir',"$buildRoot/res",'-o',"$buildRoot/resources.zip")
    Invoke-Checked (Join-Path $buildTools 'aapt2.exe') @('link','-o',"$buildRoot/unsigned.apk",'-I',$androidJar,'--manifest',"$buildRoot/AndroidManifest.xml",'--java',"$buildRoot/generated",'--auto-add-overlay',"$buildRoot/resources.zip")
    $sources = @(Get-ChildItem (Join-Path $PSScriptRoot 'src'),"$buildRoot/generated" -Filter '*.java' -Recurse | ForEach-Object FullName)
    Invoke-Checked 'javac' (@('-encoding','UTF-8','--release','8','-classpath',$androidJar,'-d',"$buildRoot/classes") + $sources)
    Invoke-Checked 'jar' @('cf',"$buildRoot/classes.jar",'-C',"$buildRoot/classes",'.')
    Invoke-Checked (Join-Path $buildTools 'd8.bat') @('--release','--lib',$androidJar,'--min-api','26','--output',"$buildRoot/dex","$buildRoot/classes.jar")
    Invoke-Checked 'jar' @('uf',"$buildRoot/unsigned.apk",'-C',"$buildRoot/dex",'classes.dex')
    Invoke-Checked (Join-Path $buildTools 'zipalign.exe') @('-f','-p','4',"$buildRoot/unsigned.apk","$buildRoot/aligned.apk")
    $apk = Join-Path $OutputDirectory "AgroLazo-$VersionName.apk"
    Invoke-Checked (Join-Path $buildTools 'apksigner.bat') @('sign','--ks',$keystore,'--ks-key-alias',$signing.alias,'--ks-pass','env:AGROLAZO_SIGNING_PASSWORD','--key-pass','env:AGROLAZO_SIGNING_PASSWORD','--out',$apk,"$buildRoot/aligned.apk")
    Invoke-Checked (Join-Path $buildTools 'apksigner.bat') @('verify','--verbose','--print-certs',$apk)
    Get-FileHash -LiteralPath $apk -Algorithm SHA256 | Select-Object Hash,Path
} finally { Remove-Item Env:AGROLAZO_SIGNING_PASSWORD -ErrorAction SilentlyContinue }

