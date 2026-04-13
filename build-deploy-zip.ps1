param(
  [string]$OutputPath = "deploy/library-system-deploy.zip"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$sourceRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$destinationZip = Join-Path $sourceRoot $OutputPath
$destinationDir = Split-Path -Parent $destinationZip

$excludedDirs = @(
  ".git",
  ".github",
  ".vscode",
  ".specify",
  "tests",
  "testsprite_tests",
  "docs",
  "deploy"
)

$excludedFiles = @(
  "*.zip",
  "Thumbs.db",
  "desktop.ini"
)

$tempRoot = Join-Path $env:TEMP ("library-system-deploy-" + [Guid]::NewGuid().ToString("N"))

try {
  New-Item -ItemType Directory -Path $tempRoot -Force | Out-Null
  New-Item -ItemType Directory -Path $destinationDir -Force | Out-Null

  if (Test-Path $destinationZip) {
    Remove-Item -Path $destinationZip -Force
  }

  $robocopyArgs = @(
    "`"$sourceRoot`"",
    "`"$tempRoot`"",
    "/E",
    "/R:1",
    "/W:1",
    "/NFL",
    "/NDL",
    "/NJH",
    "/NJS",
    "/NP"
  )

  foreach ($dir in $excludedDirs) {
    $robocopyArgs += "/XD"
    $robocopyArgs += "`"" + (Join-Path $sourceRoot $dir) + "`""
  }

  foreach ($file in $excludedFiles) {
    $robocopyArgs += "/XF"
    $robocopyArgs += "`"$file`""
  }

  $robocopyProcess = Start-Process -FilePath "robocopy" -ArgumentList $robocopyArgs -NoNewWindow -PassThru -Wait

  if ($robocopyProcess.ExitCode -ge 8) {
    throw "Robocopy failed with exit code $($robocopyProcess.ExitCode)."
  }

  Compress-Archive -Path (Join-Path $tempRoot "*") -DestinationPath $destinationZip -CompressionLevel Optimal

  $zip = Get-Item -Path $destinationZip
  $zipSizeMb = [Math]::Round($zip.Length / 1MB, 2)

  Write-Host "Deploy zip created: $destinationZip"
  Write-Host "Zip size: $zipSizeMb MB"
  Write-Host "Ready to upload this zip to your hosting file manager."
}
finally {
  if (Test-Path $tempRoot) {
    Remove-Item -Path $tempRoot -Recurse -Force
  }
}
