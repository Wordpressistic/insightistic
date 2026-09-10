# Minimum-environment gate launcher: WordPress 5.6 + PHP 8.0.
# Usage: powershell -ExecutionPolicy Bypass -File tests\docker\run-min-env.ps1 <up|gate|down>
param([string]$Action = "gate")

$env:WP_IMAGE = 'wordpress:5.6-php8.0-apache'
$env:WPCLI_IMAGE = 'wordpress:cli-php8.0'
$env:WP_PORT = '8897'

switch ($Action) {
    'up'   { docker compose -f tests\docker\compose.yaml -p insightistic-min up -d }
    'gate' {
        docker compose -f tests\docker\compose.yaml -p insightistic-min run --rm wpcli sh /gate.sh fresh
    }
    'down' { docker compose -f tests\docker\compose.yaml -p insightistic-min down -v }
}
