$block = @'
# mempalace-project-context-start
function mempalace {
    $projectWrapper = Join-Path (Get-Location) 'mempalace.ps1'
    if (Test-Path $projectWrapper) {
        & $projectWrapper @args
    }
    else {
        python -X utf8 -m mempalace @args
    }
}
# mempalace-project-context-end
'@

Set-Content -Path $PROFILE.CurrentUserAllHosts -Value $block
Set-Content -Path $PROFILE.CurrentUserCurrentHost -Value $block
Write-Output "REPAIRED:$($PROFILE.CurrentUserAllHosts)"
Write-Output "REPAIRED:$($PROFILE.CurrentUserCurrentHost)"
