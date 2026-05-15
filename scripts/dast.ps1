# DAST security scan - starts dev server and runs tests
Write-Host "=== Starting DAST Security Scan ==="

# Start dev server in background
$serverJob = Start-Job -ScriptBlock {
    param($docRoot)
    php -S 127.0.0.1:8089 -t $docRoot
} -ArgumentList (Join-Path -Path $PWD -ChildPath "public")

Start-Sleep -Seconds 2

# Check server is running
try {
    $response = Invoke-WebRequest -Uri "http://127.0.0.1:8089/health" -TimeoutSec 5 -UseBasicParsing
} catch {
    Write-Host "ERROR: Dev server failed to start"
    Stop-Job $serverJob -ErrorAction SilentlyContinue
    Remove-Job $serverJob -ErrorAction SilentlyContinue
    exit 1
}

Write-Host "Dev server started"

# Run the DAST security test suite
php vendor/bin/phpunit --no-coverage tests/dast/
$exitCode = $LASTEXITCODE

# Cleanup
Stop-Job $serverJob -ErrorAction SilentlyContinue
Remove-Job $serverJob -ErrorAction SilentlyContinue

Write-Host "=== DAST Scan Complete (exit code: $exitCode) ==="
exit $exitCode
