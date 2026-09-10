<?php
/**
 * Minimal, dependency-free ZIP utilities for the Insightistic release tools.
 *
 * ZipWriter: deterministic archive creation using the STORE (no compression)
 * method. Output depends only on the file set and contents — no timestamps
 * (DOS date fixed to 1980-01-01), no extra fields — so identical inputs give
 * byte-identical archives.
 *
 * ZipReader: reads entry names and stored contents from an archive's central
 * directory (enough to validate our own deterministic builds; not a general
 * extractor).
 *
 * Both avoid the ZipArchive extension so the release gate runs on any
 * PHP >= 8.0 (the extension is commonly absent from CLI builds).
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- CLI tooling, WordPress is not loaded.

/**
 * Deterministic STORE-method ZIP writer.
 */
class Insightistic_Zip_Writer {

	/** @var array[] Entry list: [name, data, crc32, size]. */
	private $entries = array();

	/** @var string Central directory bytes accumulated so far. */
	private $central = '';

	/** @var int Offset of the next local header. */
	private $offset = 0;

	/**
	 * Append a file.
	 *
	 * @param string $name Archive path (forward slashes, no leading slash).
	 * @param string $data Raw contents.
	 * @return void
	 */
	public function add_file( $name, $data ) {
		$name   = str_replace( '\\', '/', $name );
		$crc    = crc32( $data );
		$size   = strlen( $data );
		$nlen   = strlen( $name );

		// Local file header (STORE method = 0, fixed 1980-01-01 timestamp).
		$local  = 'PK' . "\x03\x04";
		$local .= pack( 'v', 20 );        // version needed.
		$local .= pack( 'v', 0 );         // flags.
		$local .= pack( 'v', 0 );         // method: store.
		$local .= pack( 'v', 0 );         // mod time.
		$local .= pack( 'v', ( 1 << 5 ) | 1 ); // mod date 1980-01-01.
		$local .= pack( 'V', $crc );
		$local .= pack( 'V', $size );     // compressed.
		$local .= pack( 'V', $size );     // uncompressed.
		$local .= pack( 'v', $nlen );
		$local .= pack( 'v', 0 );         // extra len.
		$local .= $name;

		$this->central .= 'PK' . "\x01\x02";
		$this->central .= pack( 'v', ( 3 << 8 ) | 20 ); // made by unix, v2.0.
		$this->central .= pack( 'v', 20 );       // version needed.
		$this->central .= pack( 'v', 0 );        // flags.
		$this->central .= pack( 'v', 0 );        // method: store.
		$this->central .= pack( 'v', 0 );        // mod time.
		$this->central .= pack( 'v', ( 1 << 5 ) | 1 ); // mod date.
		$this->central .= pack( 'V', $crc );
		$this->central .= pack( 'V', $size );
		$this->central .= pack( 'V', $size );
		$this->central .= pack( 'v', $nlen );
		$this->central .= pack( 'v', 0 );        // extra len.
		$this->central .= pack( 'v', 0 );        // comment len.
		$this->central .= pack( 'v', 0 );        // disk number.
		$this->central .= pack( 'v', 0 );        // internal attrs.
		$this->central .= pack( 'V', 0100644 << 16 ); // external attrs (unix -rw-r--r--).
		$this->central .= pack( 'V', $this->offset );
		$this->central .= $name;

		$this->offset += strlen( $local ) + $size;
		$this->entries[] = array( $name, $data );
		$this->local_blob[] = $local . $data;
	}

	/** @var string[] Local header+data blobs in order. */
	private $local_blob = array();

	/**
	 * Finalize the archive.
	 *
	 * @return string Complete ZIP bytes.
	 */
	public function finish() {
		$body   = implode( '', $this->local_blob );
		$count  = count( $this->entries );
		$cd_len = strlen( $this->central );

		$eocd  = 'PK' . "\x05\x06";
		$eocd .= pack( 'v', 0 );        // disk.
		$eocd .= pack( 'v', 0 );        // cd disk.
		$eocd .= pack( 'v', $count );   // entries this disk.
		$eocd .= pack( 'v', $count );   // total entries.
		$eocd .= pack( 'V', $cd_len );
		$eocd .= pack( 'V', strlen( $body ) );
		$eocd .= pack( 'v', 0 );        // comment len.

		return $body . $this->central . $eocd;
	}
}

/**
 * Central-directory ZIP reader for archives this tooling produces.
 */
class Insightistic_Zip_Reader {

	/** @var string Raw archive bytes. */
	private $bytes;

	/** @var array[] Parsed entries keyed by name with local-header offsets. */
	private $entries = array();

	/**
	 * Open and parse the central directory.
	 *
	 * @param string $path ZIP file path.
	 * @throws RuntimeException On unreadable or corrupt archives.
	 */
	public function __construct( $path ) {
		$this->bytes = file_get_contents( $path );
		if ( false === $this->bytes ) {
			throw new RuntimeException( "Cannot read {$path}" );
		}

		$eocd_at = strrpos( $this->bytes, 'PK' . "\x05\x06" );
		if ( false === $eocd_at ) {
			throw new RuntimeException( 'Not a ZIP archive (no EOCD record).' );
		}
		$count = unpack( 'v', substr( $this->bytes, $eocd_at + 10, 2 ) )[1];

		$pos = unpack( 'V', substr( $this->bytes, $eocd_at + 16, 4 ) )[1];
		for ( $i = 0; $i < $count; $i++ ) {
			if ( 'PK' . "\x01\x02" !== substr( $this->bytes, $pos, 4 ) ) {
				throw new RuntimeException( "Corrupt central directory at offset {$pos}." );
			}
			$method = unpack( 'v', substr( $this->bytes, $pos + 10, 2 ) )[1];
			$csize  = unpack( 'V', substr( $this->bytes, $pos + 20, 4 ) )[1];
			$nlen   = unpack( 'v', substr( $this->bytes, $pos + 28, 2 ) )[1];
			$elen   = unpack( 'v', substr( $this->bytes, $pos + 30, 2 ) )[1];
			$clen   = unpack( 'v', substr( $this->bytes, $pos + 32, 2 ) )[1];
			$lho    = unpack( 'V', substr( $this->bytes, $pos + 42, 4 ) )[1];
			$name   = substr( $this->bytes, $pos + 46, $nlen );

			$this->entries[ $name ] = array(
				'method'         => $method,
				'size'           => $csize,
				'local_header'   => $lho,
			);
			$pos += 46 + $nlen + $elen + $clen;
		}
	}

	/**
	 * All entry names (directory entries include trailing slashes).
	 *
	 * @return string[]
	 */
	public function names() {
		return array_keys( $this->entries );
	}

	/**
	 * Number of central-directory entries.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->entries );
	}

	/**
	 * Whether an entry exists.
	 *
	 * @param string $name Entry name.
	 * @return bool
	 */
	public function has( $name ) {
		return isset( $this->entries[ $name ] );
	}

	/**
	 * Extract a stored entry's contents.
	 *
	 * @param string $name Entry name.
	 * @return string|false
	 */
	public function contents( $name ) {
		if ( ! isset( $this->entries[ $name ] ) ) {
			return false;
		}
		$e = $this->entries[ $name ];
		if ( 0 !== $e['method'] ) {
			return false; // Compressed entries unsupported by design.
		}
		$lho  = $e['local_header'];
		$nlen = unpack( 'v', substr( $this->bytes, $lho + 26, 2 ) )[1];
		$elen = unpack( 'v', substr( $this->bytes, $lho + 28, 2 ) )[1];
		$start = $lho + 30 + $nlen + $elen;
		return substr( $this->bytes, $start, $e['size'] );
	}
}
