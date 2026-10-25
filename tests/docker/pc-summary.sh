#!/bin/bash
# Install the currently built release ZIP and run the official Plugin Check
# against it, printing a compact error/warning summary.
#
# Run from the repo root:
#   docker compose -f tests/docker/compose.yaml run --rm wpcli /pc-summary.sh
set -u

WP="wp --path=/var/www/html --allow-root"

$WP core is-installed >/dev/null 2>&1 || {
	$WP core install --url=http://localhost:8899 --title="Gate" \
		--admin_user=admin --admin_password=password \
		--admin_email=admin@example.com --skip-email >/dev/null 2>&1
}

$WP plugin is-installed plugin-check || $WP plugin install plugin-check --activate >/dev/null 2>&1
$WP plugin activate plugin-check >/dev/null 2>&1

# Install the newest release ZIP in /dist (the current build), replacing any
# previously installed version.
ZIP=$(ls -1 /dist/insightistic.*.zip 2>/dev/null | sort -V | tail -1)
if [ -z "$ZIP" ]; then
	echo "No insightistic.*.zip found in /dist — run 'php scripts/build-release.php' first." >&2
	exit 1
fi

$WP plugin install "$ZIP" --force --activate >/dev/null 2>&1 || {
	echo "FAILED to install $ZIP" >&2
	exit 1
}

# Strict pass: include experimental checks and low-severity findings so the
# gate matches the WordPress.org review bar, not just the default profile.
$WP plugin check insightistic --include-experimental \
	--include-low-severity-errors --include-low-severity-warnings \
	--format=json --allow-root 2>/dev/null > /tmp/insightistic-plugin-check.json

php -r '
$raw = (string) file_get_contents( "/tmp/insightistic-plugin-check.json" );
$parts = preg_split( "/^FILE: /m", $raw );
$all = array();
foreach ( array_slice( $parts, 1 ) as $p ) {
	$nl = strpos( $p, "\n" );
	if ( false === $nl ) { continue; }
	$items = json_decode( trim( substr( $p, $nl + 1 ) ), true );
	if ( is_array( $items ) ) { $all[ trim( substr( $p, 0, $nl ) ) ] = $items; }
}
$errors = 0; $warnings = 0;
foreach ( $all as $items ) {
	foreach ( $items as $i ) {
		if ( isset( $i["type"] ) && "ERROR" === $i["type"] ) { ++$errors; } else { ++$warnings; }
	}
}
printf( "Plugin Check: %d errors, %d warnings (strict profile)\n", $errors, $warnings );
foreach ( $all as $file => $items ) {
	foreach ( $items as $i ) {
		printf( "  %s:%s %s [%s]\n", $file, $i["line"] ?? "?", $i["message"] ?? "", $i["code"] ?? "" );
	}
}
exit( ( $errors + $warnings ) > 0 ? 1 : 0 );
'
