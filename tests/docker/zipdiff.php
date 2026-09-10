<?php
// Compare a reference ZipArchive-created zip against ours.
$ref = '/tmp/ref.zip';
$z = new ZipArchive();
$z->open( $ref, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$z->addFromString( 'insightistic/hello.txt', 'hello' );
$z->close();

hexdump( $ref, 'REFERENCE zip (ZipArchive)' );
hexdump( '/dist/insightistic.4.4.2.zip', 'OUR zip' );

function hexdump( $path, $label ) {
	$b = file_get_contents( $path );
	echo "== {$label} (len " . strlen( $b ) . ") ==\n";
	$tail = substr( $b, -64 );
	echo "tail: " . bin2hex( $tail ) . "\n";
	echo "head: " . bin2hex( substr( $b, 0, 48 ) ) . "\n";
	$p = strpos( $b, "PK\x01\x02" );
	if ( false !== $p ) {
		echo "cd1 : " . bin2hex( substr( $b, $p, 64 ) ) . "\n";
	}
	echo "\n";
}
