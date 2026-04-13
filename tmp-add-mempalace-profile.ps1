$profilePath = $PROFILE.CurrentUserAllHosts
$dir = Split-Path -Parent $profilePath

if (-not (Test-Path $dir)) {
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
}
if (-not (Test-Path $profilePath)) {
    New-Item -ItemType File -Path $profilePath -Force | Out-Null
}

$startMarker = '# mempalace-project-context-start'
$endMarker = '# mempalace-project-context-end'
$block = @"
$startMarker
function mempalace {
    $projectWrapper = Join-Path (Get-Location) "mempalace.ps1"
    if (Test-Path $projectWrapper) {
        & $projectWrapper @args
    }
    else {
        python -X utf8 -m mempalace @args
    }
}
$endMarker
"@

$content = Get-Content -Raw $profilePath
if ($content -notmatch [regex]::Escape($startMarker)) {
    Add-Content -Path $profilePath -Value "`r`n$block`r`n"
    Write-Output "PROFILE_UPDATED:$profilePath"
}
else {
    Write-Output "PROFILE_ALREADY_SET:$profilePath"
}
