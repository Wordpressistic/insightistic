docker run --rm -v //c/users/qbits/insightistic/insightistic:/app -w /app composer:2 sh /app/tests/docker/phpcs-clean-report.sh *> phpcs-clean.txt
Get-Content phpcs-clean.txt | Select-String 'FILE:|FOUND|ERROR|WARNING'
