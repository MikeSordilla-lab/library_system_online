param(
  [string]$Command = "context",
  [Parameter(ValueFromRemainingArguments = $true)]
  [string[]]$RemainingArgs
)

Set-Location -Path $PSScriptRoot

$env:PYTHONUTF8 = "1"
$env:PYTHONIOENCODING = "utf-8"
$env:PYTHONLEGACYWINDOWSSTDIO = "1"

[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new()
$OutputEncoding = [System.Text.UTF8Encoding]::new()

$wing = "library_system_online"

function Invoke-RealMemPalace {
  param(
    [Parameter(Mandatory = $true)]
    [string[]]$Args
  )

  # Always go through Python module to avoid wrapper recursion.
  & python -X utf8 -m mempalace @Args
  return $LASTEXITCODE
}

function Write-Step {
  param(
    [string]$Title,
    [scriptblock]$Block
  )

  Write-Host "`n==> $Title" -ForegroundColor Cyan
  & $Block
}

function Show-ContextSnapshot {
  Write-Host "AI context snapshot for wing '$wing'" -ForegroundColor Green

  Write-Step -Title "MemPalace status" -Block {
    Invoke-RealMemPalace -Args @("status") | Out-Host
  }

  Write-Step -Title "Architecture memory" -Block {
    Invoke-RealMemPalace -Args @("search", "current architecture", "--wing", $wing) | Out-Host
  }

  Write-Step -Title "Pitfalls memory" -Block {
    Invoke-RealMemPalace -Args @("search", "known pitfalls", "--wing", $wing) | Out-Host
  }

  Write-Step -Title "Recent decisions memory" -Block {
    Invoke-RealMemPalace -Args @("search", "recent changes decisions", "--wing", $wing) | Out-Host
  }

  Write-Step -Title "Git branch and working tree" -Block {
    git status --short --branch 2>$null
    if ($LASTEXITCODE -ne 0) {
      Write-Host "Not a git repository or git unavailable."
    }
  }

  Write-Step -Title "Recent commits" -Block {
    git --no-pager log -n 8 --date=short --pretty="format:%h %ad %an %s" 2>$null
    if ($LASTEXITCODE -ne 0) {
      Write-Host "No git log available."
    }
  }
}

switch ($Command.ToLowerInvariant()) {
  "context" {
    Show-ContextSnapshot
    exit 0
  }
  default {
    $argsToForward = @($Command) + $RemainingArgs
    $code = Invoke-RealMemPalace -Args $argsToForward
    exit $code
  }
}
