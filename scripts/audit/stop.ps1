$ErrorActionPreference = 'Stop'
$auditRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$auditOutput = (Resolve-Path (Join-Path $auditRoot 'output/playwright/audit-2026-10-09')).Path
$auditResult = @()

# The nginx signal must use this audit's prefix/config/pid, never the shared prefix.
$auditPidPath = Join-Path $auditOutput 'nginx.pid'
if (Test-Path -LiteralPath $auditPidPath) {
    $auditNginxId = [int](Get-Content -LiteralPath $auditPidPath -Raw).Trim()
    $auditNginx = Get-CimInstance Win32_Process -Filter "ProcessId=$auditNginxId"
    if ($auditNginx) {
        if ($auditNginx.Name -ne 'nginx.exe' -or $auditNginx.CommandLine -notmatch 'audit-2026-10-09.*nginx-load\.conf') {
            throw 'Audit nginx identity changed; refusing to signal'
        }
        & C:/nginx/nginx.exe -p "$auditOutput/" -c "$auditOutput/nginx-load.conf" -s quit
        if ($LASTEXITCODE -ne 0) { throw 'Audit nginx quit failed' }
        $auditResult += @{ service='nginx'; pid=$auditNginxId; action='quit' }
    }
}

$auditCgiIds = Get-Content -LiteralPath (Join-Path $auditOutput 'load-php-pids.json') -Raw | ConvertFrom-Json
foreach ($auditCgiId in $auditCgiIds) {
    $auditCgi = Get-CimInstance Win32_Process -Filter "ProcessId=$auditCgiId"
    if (-not $auditCgi) { continue }
    if ($auditCgi.Name -ne 'php-cgi.exe' -or $auditCgi.CommandLine -notmatch '\-b 127\.0\.0\.1:912[4-7](?:\s|$)') {
        throw "Audit CGI PID $auditCgiId identity changed; refusing to stop"
    }
    Stop-Process -Id $auditCgiId
    $auditResult += @{ service='cgi'; pid=$auditCgiId; action='stopped' }
}

$auditCluster = (Resolve-Path (Join-Path $auditOutput 'tooling/postgres')).Path
if (-not $auditCluster.StartsWith($auditOutput + '\', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Audit cluster is outside audit output'
}
& 'C:/Program Files/PostgreSQL/16/bin/pg_ctl.exe' -D $auditCluster -m fast -w stop
if ($LASTEXITCODE -ne 0) { throw 'Audit PostgreSQL stop failed' }
$auditResult += @{ service='postgres'; port=5441; action='stopped'; dataPreserved=$true }

# These three PIDs were recorded when this audit started its preview services.
# Future runs must record their own PIDs; never reuse these without identity checks.
foreach ($auditPreview in @(
    @{ id=8868; pattern='artisan serve.*--env=preview.*--port=18015' },
    @{ id=30552; pattern='-S 127\.0\.0\.1:18015 .*avtoglasskz.*server\.php' },
    @{ id=61424; pattern='artisan queue:work database .*--queue=ai.*--env=preview' }
)) {
    $auditProcess = Get-CimInstance Win32_Process -Filter "ProcessId=$($auditPreview.id)"
    if (-not $auditProcess) { continue }
    if ($auditProcess.Name -ne 'php.exe' -or $auditProcess.CommandLine -notmatch $auditPreview.pattern) {
        throw "Audit preview PID $($auditPreview.id) identity changed; refusing to stop"
    }
    Stop-Process -Id $auditPreview.id
    $auditResult += @{ service='preview'; pid=$auditPreview.id; action='stopped' }
}

$auditRemaining = @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object LocalPort -in @(18015,18016,9124,9125,9126,9127,5441))
if ($auditRemaining.Count) { throw 'An audit port is still listening; inspect its identity before further action' }
$auditResult | ConvertTo-Json -Depth 3 | Set-Content (Join-Path $auditOutput 'cleanup.json')
Write-Output 'Only recorded audit services stopped; audit data preserved.'
