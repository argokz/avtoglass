$ErrorActionPreference = 'Stop'
$auditOutput = 'output/playwright/audit-2026-10-09'
# Invoke only after browser navigation and Lighthouse have finished.
$env:PREVIEW_URL = 'http://127.0.0.1:18015'
npx playwright test tests/Browser/baseline.spec.js tests/Browser/preferences-preview.spec.js --grep 'switching modes preserves cart and dialogue|editing resumes after reload' --project desktop --output "$auditOutput/browser-targeted" --reporter json *> "$auditOutput/browser-targeted.json"
Write-Output "Targeted browser exit: $LASTEXITCODE"
try {
    $env:DB_PORT = '5441'
    $env:DB_USERNAME = 'audit'
    $env:DB_PASSWORD = ''
    ./scripts/test.ps1 --filter LocalizationTest *> "$auditOutput/localization-isolated.log"
    Write-Output "Isolated LocalizationTest exit: $LASTEXITCODE"
} finally {
    Remove-Item Env:/DB_PORT,Env:/DB_USERNAME -ErrorAction SilentlyContinue
    Remove-Item Env:/DB_PASSWORD -ErrorAction SilentlyContinue
}
node scripts/audit/interactions.mjs *> "$auditOutput/interactions.log"
Write-Output "Interaction audit exit: $LASTEXITCODE"
node scripts/audit/checkout.mjs *> "$auditOutput/checkout.log"
Write-Output "Concurrent checkout audit exit: $LASTEXITCODE"
& C:/php/php.exe scripts/audit/check-order.php --env=preview --isolated *> "$auditOutput/checkout-check.log"
node scripts/audit/inspect-photos.mjs *> "$auditOutput/photos.log"
Write-Output "Photo experiment exit: $LASTEXITCODE"
node scripts/audit/prototype.mjs *> "$auditOutput/prototype.log"
Write-Output "Prototype verification exit: $LASTEXITCODE"
