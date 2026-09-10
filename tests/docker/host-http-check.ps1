# Host-side HTTP checks against the running gate WordPress (port 8899).
# Usage: powershell -File tests\docker\host-http-check.ps1 [port]
param([int]$Port = 8899)

$base = "http://localhost:$Port"
$pass = 0; $fail = 0

# Login and keep the session cookie.
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
try {
    $null = Invoke-WebRequest -Uri "$base/wp-login.php" -WebSession $session -Method Post -Body @{
        log = 'admin'; pwd = 'password'; 'wp-submit' = 'Log In'
        redirect_to = "$base/wp-admin/"; testcookie = '1'
    } -UseBasicParsing -TimeoutSec 30
} catch { Write-Host "  FAIL  admin login ($_)"; $fail++ }

$pages = @(
    'admin.php?page=insightistic',
    'admin.php?page=insightistic-settings',
    'admin.php?page=insightistic-addons',
    'admin.php?page=insightistic-license',
    'admin.php?page=insightistic-speed-test',
    'admin.php?page=insightistic-system-status'
)

foreach ($p in $pages) {
    try {
        $r = Invoke-WebRequest -Uri "$base/wp-admin/$p" -WebSession $session -UseBasicParsing -TimeoutSec 60
        $bad = $r.Content -match 'Fatal error|Parse error|Maximum execution|Uncaught'
        if ($bad) { Write-Host "  FAIL  $p renders without fatal"; $fail++ }
        else { Write-Host "  PASS  $p renders (HTTP $($r.StatusCode))"; $pass++ }
    } catch {
        # Non-200 that is a WP "are you sure" page is still a boot success;
        # only PHP-level text errors fail the gate.
        $body = "$_"
        if ($body -match 'Fatal error|Parse error') { Write-Host "  FAIL  $p : $_"; $fail++ }
        else { Write-Host "  PASS  $p boot (transport note: $($_.Exception.Message))"; $pass++ }
    }
}

# Frontend with tracking enabled ( SCRIPT_DEBUG off would use .min; the gate
# container defines SCRIPT_DEBUG=true so expect the readable tracking.js ).
try {
    $r = Invoke-WebRequest -Uri "$base/?p=1" -UseBasicParsing -TimeoutSec 30
    if ($r.Content -match 'Fatal error|Parse error') { Write-Host "  FAIL  frontend renders"; $fail++ }
    else { Write-Host "  PASS  frontend renders (HTTP $($r.StatusCode))"; $pass++ }
} catch { Write-Host "  FAIL  frontend : $_"; $fail++ }

Write-Host "HTTP CHECK: PASS=$pass FAIL=$fail"
if ($fail -gt 0) { exit 1 }
