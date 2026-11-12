<?php
/**
 * Update data/notices.json from COTA's Gatsby reroutes endpoint.
 *
 * Validates existing state before fetching and merged state before saving.
 * A missing archive starts empty; an unreadable or invalid archive aborts the run.
 * Missing notices are retained with removal timestamps, not treated as reopenings.
 * Success prints visible and retained counts and whether file contents changed.
 * Failures print to STDERR and exit with status 1. Concurrent runs must be serialized.
 */

declare(strict_types=1);

use Cota\Scraper;

require __DIR__ . '/vendor/autoload.php';

try {
	$root = __DIR__;
	$path = $root . '/data/notices.json';
	$scraper = new Scraper();
	if ( is_file( $path ) ) {
		$previous = json_decode( Scraper::read( $path ), true, 512, JSON_THROW_ON_ERROR );
	} else {
		fwrite( STDERR, 'Missing notices.json archive.' . "\n" );
		exit( 2 );
	}
	$scraper->validate( $previous, $root . '/reroutes.schema.json' );

	$extracted = $scraper->fetch();
	$now       = (new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ))->format( 'Y-m-d\TH:i:s\Z' );
	$data      = $scraper->reconcile( $previous, $extracted, $now );

	$scraper->validate( $data, $root . '/reroutes.schema.json' );

	$changed = $scraper->save( $data, $path );

	printf( "%d visible notices; %d retained records; %s.\n", count( $extracted ), count( $data['notices'] ), $changed ? 'data updated' : 'no changes' );

} catch ( Throwable $error ) {
	fwrite( STDERR, 'Scrape failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
