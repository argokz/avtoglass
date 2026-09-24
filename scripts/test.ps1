[CmdletBinding(PositionalBinding = $false)]
param(
    [string] $PhpPath,
    [Parameter(Position = 0, ValueFromRemainingArguments = $true)]
    [string[]] $TestArguments
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot

if (-not $PhpPath) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) {
        $PhpPath = $phpCommand.Source
    } elseif (Test-Path -LiteralPath 'C:\php\php.exe') {
        $PhpPath = 'C:\php\php.exe'
    } else {
        throw 'PHP 8.4.1+ was not found. Pass -PhpPath with the path to php.exe.'
    }
}

Push-Location -LiteralPath $projectRoot
try {
    & $PhpPath -d memory_limit=512M vendor/phpunit/phpunit/phpunit @TestArguments
    $testExitCode = $LASTEXITCODE
} finally {
    Pop-Location
}

exit $testExitCode
