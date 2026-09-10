<?php
// Verify the release ZIP with real extractors inside the container.
$zip = '/dist/insightistic.4.4.2.zip';
echo 'ZipArchive: ' . ( class_exists( 'ZipArchive' ) ? 'available' : 'MISSING' ) . "\n";
if ( class_exists( 'ZipArchive' ) ) {
	$z = new ZipArchive();
	$r = $z->open( $zip );
	echo 'open: ' . ( true === $r ? 'OK' : ( is_int( $r ) ? "code {$r}" : 'fail' ) ) . "\n";
	if ( true === $r ) {
		echo 'entries: ' . $z->numFiles . "\n";
		echo 'first: ' . $z->getNameIndex( 0 ) . "\n";
		$z->extractTo( '/tmp/zx' );
		echo 'extractTo: ' . ( is_dir( '/tmp/zx/insightistic' ) ? 'OK insightistic/ created' : 'FAIL' ) . "\n";
		echo 'header: ' . ( strpos( (string) file_get_contents( '/tmp/zx/insightistic/insightistic.php' ), 'Plugin Name:' ) !== false ? 'OK' : 'MISSING' ) . "\n";
		$z->close();
	}
}
echo 'unzip bin: ' . ( shell_exec( 'command -v unzip || echo none' ) ) . "\n";
