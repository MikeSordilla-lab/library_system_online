param(
    [string]$Wing = "library_system_online"
)

Set-Location -Path $PSScriptRoot

# Force UTF-8 output to avoid encoding errors in Windows terminals.
$env:PYTHONUTF8 = "1"
$env:PYTHONIOENCODING = "utf-8"

function Invoke-MemPalace {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Args
    )

    if (Get-Command mempalace -ErrorAction SilentlyContinue) {
        & mempalace @Args
        return $LASTEXITCODE
    }

    & python -m mempalace @Args
    return $LASTEXITCODE
}

function Run-Step {
    param(
        [string]$Title,
        [string[]]$Args
    )

    Write-Host "`n==> $Title" -ForegroundColor Cyan
    $code = Invoke-MemPalace -Args $Args
    if ($code -ne 0) {
        Write-Host "Step failed (exit $code): $Title" -ForegroundColor Yellow
    }
}

Write-Host "Starting agent bootstrap for wing '$Wing'..." -ForegroundColor Green

Run-Step -Title "Memory status" -Args @("status")
Run-Step -Title "Architecture recall" -Args @("search", "current architecture", "--wing", $Wing)
Run-Step -Title "Pitfalls recall" -Args @("search", "known pitfalls", "--wing", $Wing)
Run-Step -Title "Auth decisions recall" -Args @("search", "auth flow decisions", "--wing", $Wing)

Write-Host "`nBootstrap completed." -ForegroundColor Green
