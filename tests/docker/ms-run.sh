#!/bin/sh
# Multisite debug runner (avoids host->docker quoting layers).
WP="wp --path=/var/www/html --allow-root"
echo "--- sanity ---"
$WP eval 'echo "ms=" . (is_multisite() ? "yes" : "no") . " ver=" . (defined("INSIGHTISTIC_VERSION") ? INSIGHTISTIC_VERSION : "NOT-LOADED") . "\n";'
echo "--- network plugins ---"
$WP plugin list --network
echo "--- probe ---"
$WP eval-file /multisite-probe.php
echo "probe-exit=$?"
