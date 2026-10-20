[CmdletBinding()]
param([string]$Destination = '')
$ErrorActionPreference = 'Stop'
if (!$Destination) { $Destination = Join-Path ([IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))) '.local/android-sdk' }
New-Item -ItemType Directory -Force $Destination | Out-Null
$packages = @(
    @('platform-36_r02.zip','2c1a80dd4d9f7d0e6dd336ec603d9b5c55a6f576'),
    @('build-tools_r36_windows.zip','f16ccffd34de8790dede813a6c7d8e2c11a27b50'),
    @('platform-tools_r37.0.1-win.zip','e03e78b1d80b396f1c3358e31251cb31740e1110')
)
foreach ($package in $packages) {
    $archive = Join-Path $Destination $package[0]
    if (!(Test-Path $archive)) { Invoke-WebRequest ('https://dl.google.com/android/repository/'+$package[0]) -OutFile $archive }
    if ((Get-FileHash $archive -Algorithm SHA1).Hash.ToLowerInvariant() -ne $package[1]) { throw 'La descarga del SDK no coincide con el catálogo oficial.' }
    Expand-Archive -LiteralPath $archive -DestinationPath $Destination -Force
}
