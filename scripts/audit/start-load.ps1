$ErrorActionPreference = 'Stop'
$auditRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$auditOutput = Join-Path $auditRoot 'output/playwright/audit-2026-10-09'
New-Item -ItemType Directory -Force -Path (Join-Path $auditOutput 'logs'),(Join-Path $auditOutput 'temp') | Out-Null
foreach ($auditPort in @(18016,9124,9125,9126,9127)) {
    if (Get-NetTCPConnection -State Listen -LocalPort $auditPort -ErrorAction SilentlyContinue) {
        throw "Audit port $auditPort is already occupied"
    }
}
$auditProcesses = @()
$auditPreviousMaxExists = Test-Path Env:/PHP_FCGI_MAX_REQUESTS
$auditPreviousMax = $env:PHP_FCGI_MAX_REQUESTS
try {
    $env:PHP_FCGI_MAX_REQUESTS = '0'
    foreach ($auditPort in @(9124,9125,9126,9127)) {
        $auditProcess = Start-Process C:/php/php-cgi.exe -ArgumentList @('-b', "127.0.0.1:$auditPort", '-c', 'C:/php/php.ini') -WorkingDirectory $auditRoot -WindowStyle Hidden -PassThru -RedirectStandardError (Join-Path $auditOutput "cgi-$auditPort-error.log") -RedirectStandardOutput (Join-Path $auditOutput "cgi-$auditPort-out.log")
        $auditProcesses += $auditProcess.Id
    }
} finally {
    if ($auditPreviousMaxExists) { $env:PHP_FCGI_MAX_REQUESTS = $auditPreviousMax }
    else { Remove-Item Env:/PHP_FCGI_MAX_REQUESTS -ErrorAction SilentlyContinue }
}
$auditProcesses | ConvertTo-Json | Set-Content (Join-Path $auditOutput 'load-php-pids.json')
& C:/nginx/nginx.exe -p "$auditOutput/" -c "$auditRoot/scripts/audit/nginx.conf" -t
if ($LASTEXITCODE -ne 0) { throw 'Isolated nginx configuration failed validation' }
Start-Process C:/nginx/nginx.exe -ArgumentList @('-p', "$auditOutput/", '-c', "$auditRoot/scripts/audit/nginx.conf") -WorkingDirectory $auditRoot -WindowStyle Hidden | Out-Null
Write-Output 'Dedicated nginx + four FastCGI workers started on 127.0.0.1:18016'
