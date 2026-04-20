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
  ".kilo",
  ".agents",
  ".claude",
  ".vscode",
  ".specify",
  "node_modules",
  "tests",
  "testsprite_tests",
  "docs",
  "deploy"
)

$excludedFiles = @(
  "*.zip",
  ".env",
  ".env.local",
  "ADMIN-CREDENTIALS.md",
  "Thumbs.db",
  "desktop.ini"
)

function Get-EnvMap {
  param([string]$EnvFile)

  if (!(Test-Path $EnvFile)) {
    throw "Required file missing: $EnvFile"
  }

  $map = @{}
  foreach ($line in Get-Content -LiteralPath $EnvFile) {
    $trimmed = $line.Trim()
    if ($trimmed -eq "" -or $trimmed.StartsWith("#")) {
      continue
    }

    $parts = $line -split "=", 2
    if ($parts.Length -ne 2) {
      continue
    }

    $key = $parts[0].Trim()
    $value = $parts[1].Trim().Trim('"').Trim("'")
    if ($key -ne "") {
      $map[$key] = $value
    }
  }

  return $map
}

function Assert-RequiredEnv {
  param(
    [hashtable]$EnvMap,
    [string[]]$RequiredKeys
  )

  $missing = @()
  foreach ($key in $RequiredKeys) {
    if (-not $EnvMap.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($EnvMap[$key])) {
      $missing += $key
    }
  }

  if ($missing.Count -gt 0) {
    throw "Missing required .env.production values: $($missing -join ', ')"
  }
}

$tempRoot = Join-Path $env:TEMP ("library-system-deploy-" + [Guid]::NewGuid().ToString("N"))

try {
  $envProductionPath = Join-Path $sourceRoot ".env.production"
  $envMap = Get-EnvMap -EnvFile $envProductionPath
  Assert-RequiredEnv -EnvMap $envMap -RequiredKeys @(
    "DB_HOST",
    "DB_PORT",
    "DB_NAME",
    "DB_USER",
    "DB_PASS",
    "BASE_URL",
    "DEBUG_MODE"
  )

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

  # Keep only production environment files in deployment package.
  $tempDotEnv = Join-Path $tempRoot ".env"
  $tempDotEnvLocal = Join-Path $tempRoot ".env.local"
  if (Test-Path $tempDotEnv) {
    Remove-Item -LiteralPath $tempDotEnv -Force
  }
  if (Test-Path $tempDotEnvLocal) {
    Remove-Item -LiteralPath $tempDotEnvLocal -Force
  }

  # Force production mode in deploy artifact.
  Set-Content -LiteralPath (Join-Path $tempRoot ".env.mode") -Value "production" -NoNewline -Encoding ascii

  Compress-Archive -Path (Join-Path $tempRoot "*") -DestinationPath $destinationZip -CompressionLevel Optimal

  $zip = Get-Item -Path $destinationZip
  $zipSizeMb = [Math]::Round($zip.Length / 1MB, 2)

  Write-Host "Deploy zip created: $destinationZip"
  Write-Host "Zip size: $zipSizeMb MB"
  Write-Host "Validated: required .env.production keys are present"
  Write-Host "Prepared: .env.mode set to production in artifact"
  Write-Host "Ready to upload this zip to your hosting file manager."
}
finally {
  if (Test-Path $tempRoot) {
    Remove-Item -Path $tempRoot -Recurse -Force
  }
}
