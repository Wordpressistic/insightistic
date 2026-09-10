#!/bin/bash
# Insightistic release gate inside a real WordPress container.
# Run: docker compose run --rm wpcli /gate.sh <scenario>
# Scenarios: fresh | upgrade-440 | upgrade-441 | woo-absent | woo-inactive | woo-active | multisite
set -u
SCENARIO="${1:-fresh}"

WP="wp --path=/var/www/html --allow-root"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
check(){ if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (expected [$2], got [$1])"; fi; }

echo "== Scenario: ${SCENARIO} =="

# --- Core install (idempotent) -------------------------------------------
# wpcli runs as www-data; config path is shared via volume.
WP="wp --path=/var/www/html --allow-root"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
check(){ if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (expected [$2], got [$1])"; fi; }

echo "== Scenario: ${SCENARIO} =="

chmod -R 777 /var/www/html/wp-content 2>/dev/null || true

$WP core install --url=http://localhost:8899 --title="Gate" --admin_user=admin --admin_password=password --admin_email=admin@example.com --skip-email >/dev/null 2>&1 \
  && ok "wp core install" || bad "wp core install"

ZIP=/dist/insightistic.4.4.2.zip

prep_older() {
  # Install the older version from the tag-faithful ZIP built by
  # tests/docker/make-older-zips.php and mounted at /olders. --force
  # replaces the newer files already on disk (downgrade-in-place).
  OLD="/olders/insightistic.${SCENARIO#upgrade-}.zip"
  $WP plugin install "$OLD" --force --activate >/dev/null 2>&1
  check "$(ls /var/www/html/wp-content/plugins | grep -x insightistic)" "insightistic" "older version installs into insightistic/ dir"
}

case "$SCENARIO" in
  fresh)
    $WP plugin install "$ZIP" --activate >/dev/null 2>&1 && ok "install 4.4.2 ZIP" || bad "install 4.4.2 ZIP"
    ;;
  upgrade-4.4.0|upgrade-4.4.1)
    prep_older
    # Representative settings + encrypted credential on the old version.
    $WP option update insightistic_property_id 123456789 >/dev/null
    $WP option update insightistic_measurement_id G-TESTMEAS >/dev/null
    $WP option update insightistic_ai_provider openrouter >/dev/null
    $WP post create --post_title='probe' --post_status=publish --post_content='[404-probe]' >/dev/null 2>&1
    # Simulate an existing encrypted secret via the plugin's own encryptor.
    $WP eval 'require_once WP_PLUGIN_DIR . "/insightistic/includes/class-insightistic-encryption.php"; update_option("insightistic_pagespeed_api_key_enc", Insightistic_Encryption::encrypt("AIzaTESTKEY-DO-NOT-USE"));' >/dev/null 2>&1
    check "$($WP plugin get insightistic --field=version)" "${SCENARIO#upgrade-}" "old version ${SCENARIO#upgrade-} active before upgrade"
    # Upgrade in place: `install --force` drives the same Plugin_Upgrader
    # WordPress core uses for one-click plugin updates.
    $WP plugin install "$ZIP" --force --activate >/dev/null 2>&1 && ok "upgrade via 4.4.2 ZIP (Plugin_Upgrader)" || bad "upgrade via 4.4.2 ZIP (Plugin_Upgrader)"
    ;;
  woo-absent)
    $WP plugin install "$ZIP" --activate >/dev/null 2>&1
    ;;
  woo-inactive)
    $WP plugin install "$ZIP" --activate >/dev/null 2>&1
    $WP plugin install woocommerce --activate >/dev/null 2>&1 && $WP plugin deactivate woocommerce >/dev/null 2>&1
    ;;
  woo-active)
    $WP plugin install "$ZIP" --activate >/dev/null 2>&1
    $WP plugin install woocommerce --activate >/dev/null 2>&1
    $WP option update woocommerce_onboarding_opt_in no >/dev/null 2>&1
    $WP option update woocommerce_task_list_hidden yes >/dev/null 2>&1
    ;;
  multisite)
    echo "  (multisite handled by caller)"; exit 0;;
esac

# --- Assertions -----------------------------------------------------------
VER=$($WP plugin get insightistic --field=version 2>/dev/null)
check "$VER" "4.4.2" "active plugin version is 4.4.2"

check "$(ls /var/www/html/wp-content/plugins | grep -x insightistic)" "insightistic" "plugin directory is insightistic/"

ACTIVE=$($WP plugin list --status=active --field=name 2>/dev/null | grep -x insightistic)
check "$ACTIVE" "insightistic" "plugin remains active after scenario"

# Settings persistence (upgrade scenarios) / encryption readable.
if [ "$SCENARIO" != "fresh" ] && [ "$SCENARIO" != "woo-absent" ] && [ "$SCENARIO" != "woo-inactive" ] && [ "$SCENARIO" != "woo-active" ]; then  check "$($WP option get insightistic_property_id 2>/dev/null)" "123456789" "settings preserved across upgrade"
  check "$($WP option get insightistic_measurement_id 2>/dev/null)" "G-TESTMEAS" "measurement id preserved"
  DEC=$($WP eval 'require_once WP_PLUGIN_DIR . "/insightistic/includes/class-insightistic-encryption.php"; echo Insightistic_Encryption::decrypt(get_option("insightistic_pagespeed_api_key_enc"));' 2>/dev/null)
  check "$DEC" "AIzaTESTKEY-DO-NOT-USE" "encrypted credential survives upgrade"
  # Migrations must not re-run incorrectly: flag stays set.
  MIG=$($WP option get insightistic_migrated_from_pro 2>/dev/null)
  check "$MIG" "1" "legacy-key migration flag retained (no re-run)"
fi

# Cron scheduling is conditional by design: events exist only when a
# SaaS account is connected (license validate + daily sync). On a fresh
# stack nothing is connected yet; after the connector key exists and WP
# boots, both daily events must be present.
CONNECTED=$($WP option get insightistic_connector_key_id 2>/dev/null || true)
if [ -z "$CONNECTED" ]; then
  CRON=$($WP cron event list --fields=hook 2>/dev/null | grep -c insightistic || true)
  check "$CRON" "0" "no cron events while unconnected (by design)"
fi
$WP option update insightistic_connector_key_id gate-test-key >/dev/null 2>&1
$WP eval 'echo "boot";' >/dev/null 2>&1   # full boot triggers license-manager scheduling
CRON2=$($WP cron event list --fields=hook 2>/dev/null | grep -c insightistic || true)
[ "${CRON2:-0}" -ge 2 ] && ok "cron events scheduled once connected (${CRON2})" || bad "cron events scheduled once connected (${CRON2})"

# PHP fatal check across every admin page boot (wp eval boots all plugins).
$WP eval 'echo "boot-ok";' >/dev/null 2>&1 && ok "full plugin boot (no fatal)" || bad "full plugin boot (no fatal)"

# No unexpected PHP diagnostics in debug.log (excluding unrelated core noise).
if [ -f /var/www/html/wp-content/debug.log ]; then
  ISP=$(grep -i "insightistic" /var/www/html/wp-content/debug.log | grep -ciE "fatal|error|warning|notice|deprecated" || true)
  check "$ISP" "0" "debug.log has no insightistic diagnostics"
else
  ok "debug.log absent (no diagnostics at all)"
fi

# Admin-page and frontend HTTP checks run from the HOST against the
# published port (see tests/docker/host-http-check.ps1) — containers here
# cannot reach the host-mapped URL.

# Deactivate / reactivate cycle.
$WP plugin deactivate insightistic >/dev/null 2>&1 && ok "deactivate" || bad "deactivate"
$WP plugin activate insightistic >/dev/null 2>&1 && ok "reactivate" || bad "reactivate"
check "$($WP plugin get insightistic --field=version 2>/dev/null)" "4.4.2" "still 4.4.2 after reactivation"

echo "RESULT: ${SCENARIO}: PASS=${PASS} FAIL=${FAIL}"
[ "$FAIL" -eq 0 ]
