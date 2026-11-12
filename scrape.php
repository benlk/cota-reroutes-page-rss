<?php
/**
 * Update the COTA notice archive and append RSS addition, change, and removal events.
 *
 * Validates existing state before fetching and merged state before saving.
 * A missing, unreadable, or invalid archive aborts the run.
 * Missing notices are retained with removal timestamps, not treated as reopenings.
 * Success prints visible and retained counts and whether file contents changed.
 * Changed page content emits Actions warnings and RSS items with both snapshots.
 * Publish the feed before advancing archive state so failed publication is retryable.
 * Failures print to STDERR and exit with status 1. Concurrent runs must be serialized.
 */

declare(strict_types=1);

use Cota\Feed;
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
	$observed_at = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	$now       = $observed_at->format( 'Y-m-d\TH:i:s\Z' );
	$data      = $scraper->reconcile( $previous, $extracted, $now );

	$scraper->validate( $data, $root . '/reroutes.schema.json' );

	$feed_changed = (new Feed())->save( $data, $observed_at, $root . '/data/feed.xml', $previous );
	$changed = $scraper->save( $data, $path );

	foreach ( $scraper->content_change_warnings( $previous, $data ) as $warning ) {
		fwrite( STDOUT, $warning . "\n" );
	}

	printf( "%d visible notices; %d retained records; %s.\n", count( $extracted ), count( $data['notices'] ), $changed ? 'data updated' : 'no changes' );
	if ( $feed_changed ) {
		fwrite( STDOUT, "RSS feed updated: data/feed.xml\n" );
	} else {
		fwrite( STDOUT, "RSS feed unchanged: data/feed.xml\n" );
	}
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Scrape failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
