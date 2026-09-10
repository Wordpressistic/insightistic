# Run PHPCS (WordPress Coding Standards) in a container.
# Usage: powershell -ExecutionPolicy Bypass -File tests\docker\run-phpcs.ps1
$ErrorActionPreference = 'Continue'

$repo = (Split-Path $PSScriptRoot -Parent | Split-Path -Parent)
$winPath = $repo -replace '\\', '/'
$dockerPath = '/' + ($winPath -replace ':', '').ToLower()

if (-not (Test-Path "$repo\vendor\bin\phpcs")) {
    Write-Host "== composer install (in composer:2 container) =="
    docker run --rm -v "${dockerPath}:/app" -w /app composer:2 composer install --no-interaction --no-progress
}

Write-Host "== PHPCS (WordPress standards) =="
docker run --rm -v "${dockerPath}:/app" -w /app composer:2 sh -c "vendor/bin/phpcs --version && vendor/bin/phpcs -p --report=summary . 2>&1 | tail -30"
Write-Host "phpcs-exit=$LASTEXITCODE"

Write-Host "== PHPCompatibilityWP (8.0+) =="
docker run --rm -v "${dockerPath}:/app" -w /app composer:2 sh -c "vendor/bin/phpcs -p --standard=PHPCompatibilityWP --runtime-set testVersion 8.0- --ignore=*/vendor/*,*/node_modules/*,*/build/*,*/dist/*,*/tests/*,*/scripts/* . 2>&1 | tail -20"
Write-Host "phpcompat-exit=$LASTEXITCODE"
