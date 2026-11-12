<?php
/**
 * Validate data/notices.json and append unseen notice events to data/feed.xml.
 *
 * Preserves published items and inserts only events with previously unseen GUIDs.
 * Maintenance command for additions and removals; use scrape.php to publish changes
 * while the previous and current notice snapshots are both available.
 * New removals must have been observed during the last fifteen minutes.
 * Run immediately after scraping so current removals are eligible. Failures leave
 * any existing feed intact, print to STDERR, and exit with status 1.
 */

declare(strict_types=1);

use Cota\Feed;
use Cota\Scraper;

require __DIR__ . '/vendor/autoload.php';

try {
	$data = json_decode( Scraper::read( __DIR__ . '/data/notices.json' ), true, 512, JSON_THROW_ON_ERROR );
	(new Scraper())->validate( $data, __DIR__ . '/reroutes.schema.json' );
	$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	$changed = (new Feed())->save( $data, $now, __DIR__ . '/data/feed.xml' );
	if ( $changed ) {
		fwrite( STDOUT, "RSS feed updated: data/feed.xml\n" );
	} else {
		fwrite( STDOUT, "RSS feed unchanged: data/feed.xml\n" );
	}
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Feed generation failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}