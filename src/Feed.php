<?php
/**
 * Render archived COTA notices as RSS addition and removal events.
 */

declare(strict_types=1);

namespace Cota;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Build RSS 2.0 without changing notice state or fetching upstream content.
 *
 * Existing items are immutable history; only unseen event GUIDs are appended.
 * Removal events are eligible for insertion during the fifteen minutes after
 * observation, then remain in the feed even after that window has passed.
 * Callers must validate the archive before passing it to this class.
 */
final class Feed {

	/**
	 * Render candidate addition and recent removal events, newest first.
	 *
	 * This method does not load existing history; save() merges these candidates
	 * into the persisted feed without replacing previously published items.
	 *
	 * Includes all publisher categories. Future observations are excluded. A removal
	 * exactly fifteen minutes old is included; older removals are excluded. HTML is
	 * stored as XML text so an RSS reader receives the original sanitized fragment.
	 *
	 * @param  array{schema_version: int, notices: list<array<string, string>>} $data Validated notice archive, including removed records.
	 * @param  DateTimeImmutable                                                $now  Current observation time; supplied explicitly for repeatable tests.
	 * @return string UTF-8 RSS 2.0 XML, including an XML declaration.
	 * @throws \DateMalformedStringException If an archive timestamp cannot be parsed.
	 * @throws RuntimeException If the XML document cannot be serialized.
	 */
	public function generate(array $data, DateTimeImmutable $now): string {
		$events = [];
		$cutoff = $now->modify( '-15 minutes' );
		foreach ( $data['notices'] as $notice ) {
			$added_at = new DateTimeImmutable( $notice['first_seen_at'] );
			if ( $added_at <= $now ) {
				$events[] = [
					'notice' => $notice,
					'type' => 'added',
					'date' => $added_at,
					'guid' => 'urn:cota:notice:' . $notice['id'] . ':added',
				];
			}
			if ( !isset( $notice['removed_at'] ) ) {
				continue;
			}
			$removed_at = new DateTimeImmutable( $notice['removed_at'] );
			if ( $removed_at >= $cutoff && $removed_at <= $now ) {
				$events[] = [
					'notice' => $notice,
					'type' => 'removed',
					'date' => $removed_at,
					'guid' => 'urn:cota:notice:' . $notice['id'] . ':removed:' . $removed_at->format( 'U.u' ),
				];
			}
		}
		usort(
			$events, static function (array $left, array $right): int {
				$order = $right['date'] <=> $left['date'];
				if ( $order !== 0 ) {
					return $order;
				}
				return strcmp( $left['guid'], $right['guid'] );
			}
		);

		$document = new DOMDocument( '1.0', 'UTF-8' );
		$rss = $document->createElement( 'rss' );
		$rss->setAttribute( 'version', '2.0' );
		$document->appendChild( $rss );
		$channel = $document->createElement( 'channel' );
		$rss->appendChild( $channel );
		$this->add_text( $document, $channel, 'title', 'COTA Reroute Notices' );
		$this->add_text( $document, $channel, 'link', Scraper::SOURCE_URL );
		$this->add_text( $document, $channel, 'description', 'Newly observed COTA reroutes, closures, reopenings, and notices no longer listed.' );
		$this->add_text( $document, $channel, 'language', 'en-us' );

		$utc = new DateTimeZone( 'UTC' );
		foreach ( $events as $event ) {
			$notice = $event['notice'];
			$item = $document->createElement( 'item' );
			$channel->appendChild( $item );
			$this->add_text( $document, $item, 'title', ucfirst( $event['type'] ) . ': ' . $notice['title'] );
			$this->add_text( $document, $item, 'link', $notice['source_url'] );
			$guid = $this->add_text( $document, $item, 'guid', $event['guid'] );
			$guid->setAttribute( 'isPermaLink', 'false' );
			$this->add_text( $document, $item, 'pubDate', $event['date']->setTimezone( $utc )->format( DATE_RSS ) );
			$this->add_text( $document, $item, 'category', $notice['category'] );
			$this->add_text( $document, $item, 'category', $event['type'] );
			$description = '<p>First observed on the COTA reroutes page.</p>';
			if ( $event['type'] === 'removed' ) {
				$description = '<p>No longer listed on the COTA reroutes page. This does not imply reopening.</p>';
			}
			$this->add_text( $document, $item, 'description', $description . $notice['html'] );
		}
		$xml = $document->saveXML();
		if ( $xml === false ) {
			throw new RuntimeException( 'Cannot serialize RSS feed.' );
		}
		return $xml;
	}

	/**
	 * Append unseen events to the existing feed and atomically persist the result.
	 *
	 * GUIDs deduplicate events across runs. Existing item content, ordering, and
	 * channel metadata are preserved, including removals outside the current window.
	 * New items are appended at the end of the channel. If the feed does not exist,
	 * creates it from the candidate events. Invalid history is never overwritten.
	 *
	 * The destination directory must already exist. Concurrent writers must be
	 * serialized by the caller; an atomic rename is not a lock across the pipeline.
	 *
	 * @param  array{schema_version: int, notices: list<array<string, string>>} $data Validated archive to render.
	 * @param  DateTimeImmutable                                                $now  Current time for removal-window filtering.
	 * @param  string                                                           $path Destination RSS file path.
	 * @return bool True if the feed was created or items were appended; false if no GUIDs were new.
	 * @throws RuntimeException If existing RSS is invalid or reading or writing the feed fails.
	 * @throws \DateMalformedStringException If an archive timestamp cannot be parsed.
	 */
	public function save(array $data, DateTimeImmutable $now, string $path): bool {
		$xml = $this->generate( $data, $now );
		if ( is_file( $path ) ) {
			$document = $this->load_document( Scraper::read( $path ) );
			$xpath = new DOMXPath( $document );
			$channel = $xpath->query( '/rss/channel' )->item( 0 );
			$known_guids = [];
			foreach ( $xpath->query( '/rss/channel/item' ) as $item ) {
				$guid = $xpath->evaluate( 'string(guid)', $item );
				if ( $xpath->query( 'guid', $item )->length !== 1 || trim( $guid ) === '' || isset( $known_guids[$guid] ) ) {
					throw new RuntimeException( 'Existing RSS items must have unique, nonempty GUIDs.' );
				}
				$known_guids[$guid] = true;
			}
			$candidates = $this->load_document( $xml );
			$candidate_xpath = new DOMXPath( $candidates );
			$changed = false;
			foreach ( $candidate_xpath->query( '/rss/channel/item' ) as $item ) {
				$guid = $candidate_xpath->evaluate( 'string(guid)', $item );
				if ( isset( $known_guids[$guid] ) ) {
					continue;
				}
				$channel->appendChild( $document->importNode( $item, true ) );
				$known_guids[$guid] = true;
				$changed = true;
			}
			if ( !$changed ) {
				return false;
			}
			$xml = $document->saveXML();
			if ( $xml === false ) {
				throw new RuntimeException( 'Cannot serialize updated RSS feed.' );
			}
		}
		$directory = dirname( $path );
		if ( !is_dir( $directory ) ) {
			throw new RuntimeException( 'Missing RSS output directory: ' . $directory );
		}
		$temporary = tempnam( $directory, '.feed-' );
		if ( $temporary === false ) {
			throw new RuntimeException( 'Cannot create temporary RSS file.' );
		}
		try {
			if ( file_put_contents( $temporary, $xml, LOCK_EX ) !== strlen( $xml ) || !rename( $temporary, $path ) ) {
				throw new RuntimeException( 'Cannot atomically save RSS feed.' );
			}
		} finally {
			if ( is_file( $temporary ) ) {
				unlink( $temporary );
			}
		}
		return true;
	}

	/**
	 * Parse RSS history without fetching external resources or substituting entities.
	 *
	 * @param  string $xml Existing RSS or generated candidate XML.
	 * @return DOMDocument RSS 2.0 document containing exactly one channel and no DTD.
	 * @throws RuntimeException If XML is malformed or is not the expected RSS structure.
	 */
	private function load_document(string $xml): DOMDocument {
		$document = new DOMDocument();
		$previous_errors = libxml_use_internal_errors( true );
		try {
			if ( trim( $xml ) === '' || !$document->loadXML( $xml, LIBXML_NONET ) ) {
				throw new RuntimeException( 'Cannot parse existing RSS XML.' );
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_errors );
		}
		$xpath = new DOMXPath( $document );
		if ( $document->doctype !== null || $xpath->evaluate( 'count(/rss[@version="2.0"]/channel)' ) !== 1.0 ) {
			throw new RuntimeException( 'Expected an RSS 2.0 feed with exactly one channel and no DTD.' );
		}
		return $document;
	}

	/**
	 * Append XML text without treating titles, links, or HTML as XML markup.
	 *
	 * @param  DOMDocument $document Owning XML document.
	 * @param  DOMElement  $parent   Element receiving the child.
	 * @param  string      $name     RSS element name.
	 * @param  string      $value    Text to escape, including any HTML description.
	 * @return DOMElement Appended child, allowing attributes to be set by the caller.
	 */
	private function add_text(DOMDocument $document, DOMElement $parent, string $name, string $value): DOMElement {
		$element = $document->createElement( $name );
		$element->appendChild( $document->createTextNode( $value ) );
		$parent->appendChild( $element );
		return $element;
	}
}
