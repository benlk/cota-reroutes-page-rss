<?php
/**
 * Run offline tests for notice extraction, sanitization, validation, and persistence.
 *
 * No network requests are made.
 *
 * Assertion failures throw and stop the run; success prints a summary.
 *
 * The persistence test cleans up its temporary file.
 */

declare(strict_types=1);

use Cota\Feed;
use Cota\Scraper;

require dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Fail the test run if the supplied condition is false.
 *
 * @param  bool   $condition True when the assertion passes.
 * @param  string $message   Failure message used when the condition is false.
 * @return void No return value; a true condition allows the test run to continue.
 * @throws RuntimeException If the condition is false.
 */
function check(bool $condition, string $message): void {
	if ( !$condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Require an operation to throw, without checking its exception type or message.
 *
 * Any Throwable satisfies this assertion, including an unexpected runtime error.
 * This helper checks rejection, not the precise reason for rejection.
 *
 * @param  callable(): mixed $operation Zero-argument operation expected to throw.
 * @param  string            $message   Failure message used when the operation completes normally.
 * @return void Swallows the operation's Throwable; fails if the operation returns normally.
 * @throws RuntimeException If the operation does not throw.
 */
function rejects(callable $operation, string $message): void {
	try {
		$operation();
	} catch ( Throwable ) {
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * Exercise scraper behavior with independent fixture state for each test.
 */
final class ScraperTests {

	private const FIRST = '2026-11-11T12:00:00Z';
	private const LATER = '2026-11-12T12:00:00Z';
	private const LATEST = '2026-11-13T12:00:00Z';
	private const EMPTY_STATE = ['schema_version' => 1, 'notices' => []];

	private Scraper $scraper;
	private string $schema;
	private string $html;

	/**
	 * Extracted fixture notices.
	 *
	 * @var list<array{category: string, title: string, html: string}>
	 */
	private array $notices;

	/**
	 * Initial validated archive.
	 *
	 * @var array{schema_version: int, notices: list<array<string, string>>}
	 */
	private array $data;

	/**
	 * Run every test_ method with fresh state, stopping at the first failure.
	 *
	 * @return void Prints a success summary after all tests pass.
	 * @throws RuntimeException If setup or a test fails; includes the test method name.
	 */
	public function run_all(): void {
		$count = 0;
		foreach ( get_class_methods( $this ) as $method ) {
			if ( !str_starts_with( $method, 'test_' ) ) {
				continue;
			}
			try {
				$this->set_up();
				$this->$method();
			} catch ( Throwable $error ) {
				throw new RuntimeException( $method . ': ' . $error->getMessage(), 0, $error );
			}
			$count++;
		}
		printf( "All %d scraper tests passed.\n", $count );
	}

	/**
	 * Reload the local HTML fixture and create a fresh, validated initial archive.
	 *
	 * @return void Resets the scraper, fixture, notices, schema path, and archive properties.
	 * @throws Throwable If fixture reading, extraction, reconciliation, or validation fails.
	 */
	private function set_up(): void {
		$this->scraper = new Scraper();
		$this->schema  = dirname( __DIR__ ) . '/reroutes.schema.json';
		$this->html    = Scraper::read( __DIR__ . '/notices.html' );
		$response      = json_encode(
			[
				'path' => '/reroutes/',
				'result' => [
					'data' => [
						'page' => [
							'edges' => [
								[
									'node' => [
										'title' => 'Reroutes',
										'content' => $this->html
									]
								]
							]
						]
					]
				],
			],
			JSON_THROW_ON_ERROR
		);
		$this->notices = $this->scraper->extract_response( $response );
		$this->data    = $this->scraper->reconcile( self::EMPTY_STATE, $this->notices, self::FIRST );
		$this->scraper->validate( $this->data, $this->schema );
	}

	/**
	 * Verify changed HTML on a matched notice produces one identifiable Actions warning.
	 *
	 * @return void
	 * @throws Throwable If a content edit is missed or produces incorrect annotations.
	 */
	private function test_content_change_warning(): void {
		$changed = $this->notices;
		$changed[0]['html'] .= '<p>Changed stop instructions.</p>';
		$data = $this->scraper->reconcile( $this->data, $changed, self::LATER );
		$warnings = $this->scraper->content_change_warnings( $this->data, $data );
		check( count( $warnings ) === 1, 'Warn once per changed known notice.' );
		check( str_starts_with( $warnings[0], '::warning::' ), 'Use a GitHub Actions warning annotation.' );
		check( str_contains( $warnings[0], $this->data['notices'][0]['id'] ), 'Identify the changed notice by UUID.' );
		check( str_contains( $warnings[0], $changed[0]['title'] ), 'Include the notice title.' );
		check( str_contains( $warnings[0], 'fields: html' ), 'Name the changed page field.' );
		check( $this->scraper->content_change_warnings( $data, $data ) === [], 'Do not warn again for already stored changes.' );
	}

	/**
	 * Verify title and category edits on an existing UUID produce a single combined warning.
	 *
	 * @return void
	 * @throws Throwable If page-field edits are missed or create multiple warnings per item.
	 */
	private function test_title_and_category_change_warning(): void {
		$changed = $this->notices;
		$changed[0]['title'] = 'Nov 16 Improvements';
		$changed[0]['category'] = 'closure';
		$data = $this->scraper->reconcile( $this->data, $changed, self::LATER );
		$warnings = $this->scraper->content_change_warnings( $this->data, $data );
		check( count( $warnings ) === 1, 'Combine changed fields into one warning per notice.' );
		check( str_contains( $warnings[0], 'fields: title, category' ), 'Identify title and category changes.' );
	}

	/**
	 * Verify unchanged notices, additions, and removals do not produce content-change warnings.
	 *
	 * @return void
	 * @throws Throwable If lifecycle changes are mistaken for page-content edits.
	 */
	private function test_notice_lifecycle_does_not_warn(): void {
		check( $this->scraper->content_change_warnings( $this->data, $this->data ) === [], 'Unchanged notices must not warn.' );
		check( $this->scraper->content_change_warnings( self::EMPTY_STATE, $this->data ) === [], 'New notices must not warn.' );
		$removed = $this->scraper->reconcile( $this->data, array_slice( $this->notices, 1 ), self::LATER );
		check( $this->scraper->content_change_warnings( $this->data, $removed ) === [], 'Removals must not warn.' );
		check( $this->scraper->content_change_warnings( $removed, $this->data ) === [], 'Unchanged reappearances must not warn.' );
		$metadata = $this->data;
		$metadata['notices'][0]['updated_at'] = self::LATER;
		check( $this->scraper->content_change_warnings( $this->data, $metadata ) === [], 'Timestamp-only changes must not warn.' );
	}

	/**
	 * Verify notice text cannot inject extra GitHub Actions workflow commands into warnings.
	 *
	 * @return void
	 * @throws RuntimeException If percent signs or line breaks are not command-escaped.
	 */
	private function test_content_warning_escapes_workflow_commands(): void {
		$data = $this->data;
		$data['notices'][0]['title'] = "Road 50%\r\n::error::not a command";
		$warnings = $this->scraper->content_change_warnings( $this->data, $data );
		check( count( $warnings ) === 1, 'Emit one warning for the changed title.' );
		check( str_contains( $warnings[0], '50%25%0D%0A::error::' ), 'Escape workflow-command data.' );
		check( !str_contains( $warnings[0], "\r" ) && !str_contains( $warnings[0], "\n" ), 'Keep warning annotations on one physical line.' );
	}

	/**
	 * Verify extraction recognizes three notices rather than bold list labels.
	 *
	 * @return void
	 * @throws RuntimeException If the extracted notice count is incorrect.
	 */
	private function test_notice_boundaries(): void {
		check( count( $this->notices ) === 3, 'Expected three notices, not bold list labels.' );
	}

	/**
	 * Verify sanitization preserves notice lists.
	 *
	 * @return void
	 * @throws RuntimeException If list markup is missing.
	 */
	private function test_lists_retained(): void {
		check( str_contains( $this->notices[0]['html'], '<ul>' ), 'Lists must be retained.' );
	}

	/**
	 * Verify body paragraphs remain attached to their notice.
	 *
	 * @return void
	 * @throws RuntimeException If the supporting paragraph is missing.
	 */
	private function test_body_paragraphs_retained(): void {
		check( str_contains( $this->notices[0]['html'], 'Use neighboring stops.' ), 'Body paragraphs must be retained.' );
	}

	/**
	 * Verify sanitization removes HTML event-handler attributes.
	 *
	 * @return void
	 * @throws RuntimeException If an event handler survives sanitization.
	 */
	private function test_event_handlers_removed(): void {
		check( !str_contains( $this->notices[0]['html'], 'onclick' ), 'Event handlers must be removed.' );
	}

	/**
	 * Verify relative links resolve against the public COTA page URL.
	 *
	 * @return void
	 * @throws RuntimeException If the expected absolute link is missing.
	 */
	private function test_relative_urls_resolved(): void {
		check( str_contains( $this->notices[1]['html'], 'https://cota.com/reroutes/map.jpg' ), 'Resolve relative URLs.' );
	}

	/**
	 * Verify sanitization removes javascript link schemes.
	 *
	 * @return void
	 * @throws RuntimeException If an unsafe URL scheme remains.
	 */
	private function test_unsafe_urls_removed(): void {
		check( !str_contains( $this->notices[1]['html'], 'javascript:' ), 'Unsafe URL schemes must be removed.' );
	}

	/**
	 * Verify trailing signup content is not included in the last notice.
	 *
	 * @return void
	 * @throws RuntimeException If footer text appears in a notice.
	 */
	private function test_footer_excluded(): void {
		check( !str_contains( $this->notices[2]['html'], 'Sign up' ), 'Exclude footer content.' );
	}

	/**
	 * Verify an unrecognized Gatsby JSON structure is rejected.
	 *
	 * @return void
	 * @throws RuntimeException If the invalid response is accepted.
	 */
	private function test_invalid_gatsby_structure_rejected(): void {
		rejects( fn () => $this->scraper->extract_response( '{}' ), 'Reject changed Gatsby structure.' );
	}

	/**
	 * Verify extraction rejects a missing required category heading.
	 *
	 * @return void
	 * @throws RuntimeException If a missing category is accepted.
	 */
	private function test_missing_category_rejected(): void {
		$html = str_replace( '<h3>Closures</h3>', '', $this->html );
		rejects( fn () => $this->scraper->extract( $html ), 'Reject missing categories.' );
	}

	/**
	 * Verify empty sections require an explicit no-notices message.
	 *
	 * @return void
	 * @throws RuntimeException If an unexplained empty section is accepted.
	 */
	private function test_unexplained_empty_section_rejected(): void {
		$html = str_replace( 'There are no reopenings at this time.', '', $this->html );
		rejects( fn () => $this->scraper->extract( $html ), 'Reject unexplained empty sections.' );
	}

	/**
	 * Verify extraction rejects duplicate notice titles.
	 *
	 * @return void
	 * @throws RuntimeException If duplicate titles are accepted.
	 */
	private function test_duplicate_titles_rejected(): void {
		$html = str_replace( 'Nov. 9: Relocation', 'Nov. 16: Improvements', $this->html );
		rejects( fn () => $this->scraper->extract( $html ), 'Reject duplicate titles.' );
	}

	/**
	 * Verify extraction rejects unsupported top-level notice markup.
	 *
	 * @return void
	 * @throws RuntimeException If unfamiliar markup is accepted.
	 */
	private function test_unfamiliar_markup_rejected(): void {
		$html = str_replace( '<ul>', '<div>', $this->html );
		rejects( fn () => $this->scraper->extract( $html ), 'Reject unfamiliar markup.' );
	}

	/**
	 * Verify no-notices messages must refer to their containing category.
	 *
	 * @return void
	 * @throws RuntimeException If a mismatched empty message is accepted.
	 */
	private function test_mismatched_empty_message_rejected(): void {
		$html = str_replace( 'There are no reopenings at this time.', 'There are no closures at this time.', $this->html );
		rejects( fn () => $this->scraper->extract( $html ), 'Reject mismatched empty message.' );
	}

	/**
	 * Verify identical extractions do not change records or observation timestamps.
	 *
	 * @return void
	 * @throws RuntimeException If unchanged notices alter archive state.
	 */
	private function test_unchanged_scrape_preserves_state(): void {
		$actual = $this->scraper->reconcile( $this->data, $this->notices, self::LATER );
		check( $actual === $this->data, 'Unchanged scrapes must not change records.' );
	}

	/**
	 * Verify punctuation-only title edits preserve identity but update stored content time.
	 *
	 * @return void
	 * @throws Throwable If identity, timestamps, or resulting schema validation fails.
	 */
	private function test_punctuation_edits_preserve_identity(): void {
		$changed = $this->notices;
		$changed[0]['title'] = "\u{201c}Nov 16 Improvements\u{201d}\u{2014}";
		$updated = $this->scraper->reconcile( $this->data, $changed, self::LATER );
		check( count( $updated['notices'] ) === count( $this->data['notices'] ), 'Punctuation-only title edits must not create records.' );
		check( $updated['notices'][0]['id'] === $this->data['notices'][0]['id'], 'ASCII and Unicode punctuation must not affect identity.' );
		check( $updated['notices'][0]['first_seen_at'] === self::FIRST, 'Punctuation-only edits must preserve first-seen time.' );
		check( $updated['notices'][0]['updated_at'] === self::LATER, 'Punctuation-only edits must still update the stored title timestamp.' );
		$this->scraper->validate( $updated, $this->schema );
	}

	/**
	 * Verify reconciliation rejects titles that collide after punctuation removal.
	 *
	 * @return void
	 * @throws RuntimeException If punctuation-equivalent titles are accepted.
	 */
	private function test_punctuation_duplicate_titles_rejected(): void {
		$duplicate = $this->notices[0];
		$duplicate['title'] = "\u{201c}Nov 16 Improvements\u{201d}\u{2014}";
		$notices = [$this->notices[0], $duplicate];
		rejects( fn () => $this->scraper->reconcile( self::EMPTY_STATE, $notices, self::LATER ), 'Reject titles that differ only by punctuation.' );
	}

	/**
	 * Verify body edits retain identity and first-seen time while advancing updated_at.
	 *
	 * @return void
	 * @throws Throwable If identity, timestamps, or resulting schema validation fails.
	 */
	private function test_body_edits_preserve_identity(): void {
		$changed = $this->notices;
		$changed[0]['html'] .= '<p>Updated.</p>';
		$updated = $this->scraper->reconcile( $this->data, $changed, self::LATER );
		check( $updated['notices'][0]['id'] === $this->data['notices'][0]['id'], 'Edits must preserve IDs.' );
		check( $updated['notices'][0]['first_seen_at'] === self::FIRST, 'Edits must preserve first-seen time.' );
		check( $updated['notices'][0]['updated_at'] === self::LATER, 'Edits must advance updated time.' );
		$this->scraper->validate( $updated, $this->schema );
	}

	/**
	 * Verify missing notices remain in the archive with their first removal timestamp.
	 *
	 * @return void
	 * @throws Throwable If a removal timestamp or resulting schema validation fails.
	 */
	private function test_missing_notice_retained(): void {
		$removed = $this->scraper->reconcile( $this->data, array_slice( $this->notices, 1 ), self::LATER );
		check( $removed['notices'][0]['removed_at'] === self::LATER, 'Retain missing notices with removal timestamps.' );
		$this->scraper->validate( $removed, $this->schema );
	}

	/**
	 * Verify later scrapes do not advance an existing removal timestamp.
	 *
	 * @return void
	 * @throws RuntimeException If an already removed record changes.
	 */
	private function test_removal_timestamp_preserved(): void {
		$visible = array_slice( $this->notices, 1 );
		$removed = $this->scraper->reconcile( $this->data, $visible, self::LATER );
		check( $this->scraper->reconcile( $removed, $visible, self::LATEST ) === $removed, 'Do not advance removal timestamps.' );
	}

	/**
	 * Verify reappearance clears removed_at without changing identity or unchanged content time.
	 *
	 * @return void
	 * @throws RuntimeException If a reappearing notice does not regain its original state.
	 */
	private function test_reappearance_clears_removal(): void {
		$removed = $this->scraper->reconcile( $this->data, array_slice( $this->notices, 1 ), self::LATER );
		check( $this->scraper->reconcile( $removed, $this->notices, self::LATEST ) === $this->data, 'Reappearance must clear removal without changing identity.' );
	}

	/**
	 * Verify explicit no-notices messages permit all three categories to be empty.
	 *
	 * @return void
	 * @throws Throwable If extraction fails or returns notices for explicitly empty sections.
	 */
	private function test_explicitly_empty_categories(): void {
		$html = '<h3>Reroutes</h3><p>There are no reroutes at this time.</p><h3>Closures</h3><p>There are no closures at this time.</p><h3>Reopenings</h3><p>There are no reopenings at this time.</p>';
		check( $this->scraper->extract( $html ) === [], 'Explicitly empty categories are valid.' );
	}

	/**
	 * Verify schema validation rejects unknown category values.
	 *
	 * @return void
	 * @throws RuntimeException If an invalid category is accepted.
	 */
	private function test_invalid_category_rejected(): void {
		$bad = $this->data;
		$bad['notices'][0]['category'] = 'other';
		rejects( fn () => $this->scraper->validate( $bad, $this->schema ), 'Reject invalid category.' );
	}

	/**
	 * Verify schema validation enforces date-time formatting.
	 *
	 * @return void
	 * @throws RuntimeException If an invalid timestamp is accepted.
	 */
	private function test_invalid_datetime_rejected(): void {
		$bad = $this->data;
		$bad['notices'][0]['first_seen_at'] = 'not-a-date';
		rejects( fn () => $this->scraper->validate( $bad, $this->schema ), 'Enforce date-time format.' );
	}

	/**
	 * Verify schema validation enforces UUID formatting.
	 *
	 * @return void
	 * @throws RuntimeException If a malformed UUID is accepted.
	 */
	private function test_invalid_uuid_rejected(): void {
		$bad = $this->data;
		$bad['notices'][0]['id'] = 'not-a-uuid';
		rejects( fn () => $this->scraper->validate( $bad, $this->schema ), 'Enforce UUID format.' );
	}

	/**
	 * Verify stored content hashes must match the exact HTML bytes.
	 *
	 * @return void
	 * @throws RuntimeException If an incorrect hash is accepted.
	 */
	private function test_incorrect_hash_rejected(): void {
		$bad = $this->data;
		$bad['notices'][0]['content_hash'] = str_repeat( '0', 64 );
		rejects( fn () => $this->scraper->validate( $bad, $this->schema ), 'Check stored content hashes.' );
	}

	/**
	 * Verify validation rejects duplicate persistent notice IDs.
	 *
	 * @return void
	 * @throws RuntimeException If duplicate IDs are accepted.
	 */
	private function test_duplicate_ids_rejected(): void {
		$bad = $this->data;
		$bad['notices'][1]['id'] = $bad['notices'][0]['id'];
		rejects( fn () => $this->scraper->validate( $bad, $this->schema ), 'Reject duplicate IDs.' );
	}

	/**
	 * Verify schema validation rejects undocumented notice properties.
	 *
	 * @return void
	 * @throws RuntimeException If an unknown property is accepted.
	 */
	private function test_unknown_fields_rejected(): void {
		$bad = $this->data;
		$bad['notices'][0]['extra'] = true;
		rejects( fn () => $this->scraper->validate( $bad, $this->schema ), 'Reject unknown fields.' );
	}

	/**
	 * Render a feed at a fixed time and parse it for RSS assertions.
	 *
	 * @param  array{schema_version: int, notices: list<array<string, string>>} $data Validated notice archive.
	 * @param  string                                                           $now  Current date-time for the removal window.
	 * @return DOMXPath XPath evaluator for the parsed RSS document.
	 * @throws Throwable If generation, XML parsing, or the well-formedness assertion fails.
	 */
	private function feed_document(array $data, string $now = self::LATER): DOMXPath {
		$xml = (new Feed())->generate( $data, new DateTimeImmutable( $now ) );
		$document = new DOMDocument();
		check( $document->loadXML( $xml, LIBXML_NONET ), 'RSS must be well-formed XML.' );
		return new DOMXPath( $document );
	}

	/**
	 * Verify each archived notice produces an addition with its original first-seen date.
	 *
	 * @return void
	 * @throws Throwable If RSS metadata, item counts, dates, or GUIDs are incorrect.
	 */
	private function test_feed_addition_events(): void {
		$feed = $this->feed_document( $this->data );
		check( $feed->evaluate( 'string(/rss/@version)' ) === '2.0', 'Generate RSS 2.0.' );
		check( $feed->evaluate( 'count(/rss/channel/title | /rss/channel/link | /rss/channel/description)' ) === 3.0, 'Include required channel metadata.' );
		check( $feed->evaluate( 'count(/rss/channel/item[category="added"])' ) === 3.0, 'Include additions for every notice category.' );
		check( $feed->evaluate( 'count(/rss/channel/item[category="removed"])' ) === 0.0, 'Visible notices have no removal events.' );
		check( $feed->evaluate( 'string(/rss/channel/item/pubDate)' ) === (new DateTimeImmutable( self::FIRST ))->format( DATE_RSS ), 'Date additions by first observation, not generation.' );
		check( $feed->evaluate( 'string(/rss/channel/item/guid/@isPermaLink)' ) === 'false', 'Event GUIDs are identities, not URLs.' );
	}

	/**
	 * Verify a removal observed now produces a distinct event before older additions.
	 *
	 * @return void
	 * @throws Throwable If a current removal is omitted, misdated, or described as reopening.
	 */
	private function test_feed_current_removal(): void {
		$data = $this->data;
		$data['notices'][0]['removed_at'] = self::LATER;
		$feed = $this->feed_document( $data );
		check( $feed->evaluate( 'count(/rss/channel/item)' ) === 4.0, 'Retain addition history alongside a current removal.' );
		check( $feed->evaluate( 'string(/rss/channel/item[1]/category[2])' ) === 'removed', 'Order events newest first.' );
		check( $feed->evaluate( 'string(/rss/channel/item[1]/pubDate)' ) === (new DateTimeImmutable( self::LATER ))->format( DATE_RSS ), 'Date removals by removed_at.' );
		check( str_contains( $feed->evaluate( 'string(/rss/channel/item[1]/description)' ), 'does not imply reopening' ), 'Describe removal as absence, not reopening.' );
		$guids = array_map(
			static fn (DOMNode $node): string => $node->textContent,
			iterator_to_array( $feed->query( '/rss/channel/item/guid' ) )
		);
		check( count( $guids ) === count( array_unique( $guids ) ), 'Addition and removal GUIDs must be distinct.' );
	}

	/**
	 * Verify the fifteen-minute removal boundary is inclusive.
	 *
	 * @return void
	 * @throws Throwable If a removal exactly fifteen minutes old is omitted.
	 */
	private function test_feed_removal_at_cutoff(): void {
		$data = $this->data;
		$data['notices'][0]['removed_at'] = '2026-11-12T11:45:00Z';
		$feed = $this->feed_document( $data );
		check( $feed->evaluate( 'count(/rss/channel/item[category="removed"])' ) === 1.0, 'Include removal at the fifteen-minute boundary.' );
	}

	/**
	 * Verify removals older than fifteen minutes are excluded without losing additions.
	 *
	 * @return void
	 * @throws Throwable If an expired removal remains in the feed.
	 */
	private function test_feed_old_removal_excluded(): void {
		$data = $this->data;
		$data['notices'][0]['removed_at'] = '2026-11-12T11:44:59Z';
		$feed = $this->feed_document( $data );
		check( $feed->evaluate( 'count(/rss/channel/item[category="removed"])' ) === 0.0, 'Exclude removal older than fifteen minutes.' );
		check( $feed->evaluate( 'count(/rss/channel/item[category="added"])' ) === 3.0, 'Keep historical addition events for removed notices.' );
	}

	/**
	 * Verify future removal timestamps do not produce premature events.
	 *
	 * @return void
	 * @throws Throwable If a future removal appears in the feed.
	 */
	private function test_feed_future_removal_excluded(): void {
		$data = $this->data;
		$data['notices'][0]['removed_at'] = '2026-11-12T12:00:01Z';
		$feed = $this->feed_document( $data );
		check( $feed->evaluate( 'count(/rss/channel/item[category="removed"])' ) === 0.0, 'Exclude removal timestamps in the future.' );
	}

	/**
	 * Verify removal-window comparisons honor timestamp offsets rather than clock text.
	 *
	 * @return void
	 * @throws Throwable If an equivalent offset timestamp is filtered or serialized incorrectly.
	 */
	private function test_feed_removal_timezone(): void {
		$data = $this->data;
		$data['notices'][0]['removed_at'] = '2026-11-12T07:55:00-04:00';
		$feed = $this->feed_document( $data );
		check( $feed->evaluate( 'count(/rss/channel/item[category="removed"])' ) === 1.0, 'Compare removal instants across offsets.' );
		check( $feed->evaluate( 'string(/rss/channel/item[category="removed"]/pubDate)' ) === 'Thu, 12 Nov 2026 11:55:00 +0000', 'Serialize RSS dates in UTC.' );
	}

	/**
	 * Verify XML escaping preserves HTML, special characters, and link query strings.
	 *
	 * @return void
	 * @throws Throwable If XML parsing changes or corrupts RSS text values.
	 */
	private function test_feed_xml_escaping(): void {
		$data = $this->data;
		$data['notices'] = [$data['notices'][0]];
		$data['notices'][0]['title'] = 'Road & rail <updates>';
		$data['notices'][0]['source_url'] = 'https://cota.com/reroutes/?line=1&stop=2';
		$data['notices'][0]['html'] = '<p>Road &amp; rail: ]]&gt;</p>';
		$data['notices'][0]['content_hash'] = hash( 'sha256', $data['notices'][0]['html'] );
		$this->scraper->validate( $data, $this->schema );
		$feed = $this->feed_document( $data );
		check( $feed->evaluate( 'string(/rss/channel/item/title)' ) === 'Added: Road & rail <updates>', 'Escape titles as XML text.' );
		check( $feed->evaluate( 'string(/rss/channel/item/link)' ) === $data['notices'][0]['source_url'], 'Preserve URL query parameters.' );
		check( str_ends_with( $feed->evaluate( 'string(/rss/channel/item/description)' ), $data['notices'][0]['html'] ), 'Preserve sanitized HTML inside the description text.' );
	}

	/**
	 * Verify content edits keep addition GUIDs and original publication dates stable.
	 *
	 * @return void
	 * @throws Throwable If an edit looks like a new addition to an RSS reader.
	 */
	private function test_feed_addition_identity_stable(): void {
		$before = $this->feed_document( $this->data );
		$data = $this->data;
		$data['notices'][0]['title'] .= ' updated';
		$data['notices'][0]['updated_at'] = self::LATER;
		$after = $this->feed_document( $data );
		check( $before->evaluate( 'string(/rss/channel/item/guid)' ) === $after->evaluate( 'string(/rss/channel/item/guid)' ), 'Content edits must not change addition GUIDs.' );
		check( $before->evaluate( 'string(/rss/channel/item/pubDate)' ) === $after->evaluate( 'string(/rss/channel/item/pubDate)' ), 'Content edits must not redate additions.' );
		$feed = new Feed();
		check( $feed->generate( $this->data, new DateTimeImmutable( self::LATER ) ) === $feed->generate( $this->data, new DateTimeImmutable( self::LATEST ) ), 'Generation time alone must not rewrite unchanged additions.' );
	}

	/**
	 * Verify a later removal of the same notice has a different removal-event GUID.
	 *
	 * @return void
	 * @throws Throwable If repeated removal observations cannot be distinguished.
	 */
	private function test_feed_repeated_removal_identity(): void {
		$data = $this->data;
		$data['notices'][0]['removed_at'] = self::LATER;
		$before = $this->feed_document( $data );
		$data['notices'][0]['removed_at'] = self::LATEST;
		$after = $this->feed_document( $data, self::LATEST );
		check( $before->evaluate( 'string(/rss/channel/item[category="removed"]/guid)' ) !== $after->evaluate( 'string(/rss/channel/item[category="removed"]/guid)' ), 'Distinct removal observations need distinct GUIDs.' );
	}

	/**
	 * Verify an empty archive still produces a valid RSS channel without items.
	 *
	 * @return void
	 * @throws Throwable If an empty feed cannot be parsed or contains events.
	 */
	private function test_feed_empty_archive(): void {
		$feed = $this->feed_document( self::EMPTY_STATE );
		check( $feed->evaluate( 'count(/rss/channel)' ) === 1.0, 'Keep channel metadata in empty feeds.' );
		check( $feed->evaluate( 'count(/rss/channel/item)' ) === 0.0, 'An empty archive has no events.' );
	}

	/**
	 * Verify RSS persistence writes once and skips unchanged XML.
	 *
	 * @return void Removes the temporary file even when an assertion fails.
	 * @throws Throwable If RSS persistence or an assertion fails.
	 */
	private function test_feed_persistence(): void {
		$path = sys_get_temp_dir() . '/cota-feed-' . bin2hex( random_bytes( 8 ) ) . '.xml';
		$feed = new Feed();
		$now = new DateTimeImmutable( self::LATER );
		try {
			check( $feed->save( $this->data, $now, $path ), 'First RSS save writes data.' );
			check( !$feed->save( $this->data, $now, $path ), 'Identical RSS saves do not rewrite data.' );
			check( Scraper::read( $path ) === $feed->generate( $this->data, $now ), 'RSS output must match generated XML.' );
		} finally {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	/**
	 * Supply an isolated RSS path and clean it up after a persistence test.
	 *
	 * @param  callable(string): void $operation Test receiving a nonexistent temporary file path.
	 * @return void Removes the file even when the test throws.
	 * @throws Throwable If the test or temporary path generation fails.
	 */
	private function with_feed_file(callable $operation): void {
		$path = sys_get_temp_dir() . '/cota-feed-' . bin2hex( random_bytes( 8 ) ) . '.xml';
		try {
			$operation( $path );
		} finally {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	/**
	 * Verify only new addition GUIDs are appended, preserving published item content and order.
	 *
	 * @return void
	 * @throws Throwable If additions are duplicated or existing history is rewritten.
	 */
	private function test_feed_appends_new_additions(): void {
		$this->with_feed_file(
			function (string $path): void {
				$feed = new Feed();
				$now = new DateTimeImmutable( self::LATER );
				$feed->save( $this->data, $now, $path );
				$document = new DOMDocument();
				check( $document->loadXML( Scraper::read( $path ), LIBXML_NONET ), 'Parse the initial RSS feed.' );
				$original_item = $document->saveXML( $document->getElementsByTagName( 'item' )->item( 0 ) );
				$new_notice = $this->notices[0];
				$new_notice['title'] = 'A newly observed reroute';
				$notices = [...$this->notices, $new_notice];
				$data = $this->scraper->reconcile( $this->data, $notices, self::LATER );
				$data['notices'][0]['title'] .= ' edited after publication';
				check( $feed->save( $data, $now, $path ), 'A new addition must append an RSS item.' );
				check( $document->loadXML( Scraper::read( $path ), LIBXML_NONET ), 'Parse the appended RSS feed.' );
				$xpath = new DOMXPath( $document );
				check( $xpath->evaluate( 'count(/rss/channel/item)' ) === 4.0, 'Append exactly one new item.' );
				check( $xpath->evaluate( 'string(/rss/channel/item[last()]/title)' ) === 'Added: A newly observed reroute', 'Append new events after existing items.' );
				check( $document->saveXML( $document->getElementsByTagName( 'item' )->item( 0 ) ) === $original_item, 'Published items must retain their content and order.' );
				check( !$feed->save( $data, $now, $path ), 'Do not append an already published addition again.' );
			} 
		);
	}

	/**
	 * Verify edits and an empty input archive cannot overwrite or delete published events.
	 *
	 * @return void
	 * @throws Throwable If a save without new GUIDs changes existing file bytes.
	 */
	private function test_feed_keeps_published_content(): void {
		$this->with_feed_file(
			function (string $path): void {
				$feed = new Feed();
				$now = new DateTimeImmutable( self::LATER );
				$feed->save( $this->data, $now, $path );
				$original = Scraper::read( $path );
				$data = $this->data;
				$data['notices'][0]['title'] .= ' edited';
				$data['notices'][0]['html'] .= '<p>Later details.</p>';
				$data['notices'][0]['content_hash'] = hash( 'sha256', $data['notices'][0]['html'] );
				check( !$feed->save( $data, $now, $path ), 'Content edits must not replace published items.' );
				check( !$feed->save( self::EMPTY_STATE, $now, $path ), 'An empty archive must not erase published events.' );
				check( Scraper::read( $path ) === $original, 'No new GUIDs means no file rewrite.' );
			} 
		);
	}

	/**
	 * Verify eligible removal events append once and survive expiry and reappearance.
	 *
	 * @return void
	 * @throws Throwable If a removal is duplicated or disappears from published history.
	 */
	private function test_feed_retains_removal_history(): void {
		$this->with_feed_file(
			function (string $path): void {
				$feed = new Feed();
				$now = new DateTimeImmutable( self::LATER );
				$feed->save( $this->data, $now, $path );
				$data = $this->data;
				$data['notices'][0]['removed_at'] = self::LATER;
				check( $feed->save( $data, $now, $path ), 'Append a newly observed removal.' );
				$original = Scraper::read( $path );
				$document = new DOMDocument();
				check( $document->loadXML( $original, LIBXML_NONET ), 'Parse persisted removal history.' );
				$xpath = new DOMXPath( $document );
				check( $xpath->evaluate( 'count(/rss/channel/item[category="removed"])' ) === 1.0, 'Store exactly one removal event.' );
				check( !$feed->save( $data, $now, $path ), 'Do not duplicate a removal within its window.' );
				check( !$feed->save( $data, new DateTimeImmutable( self::LATEST ), $path ), 'Expiry must not delete a published removal.' );
				check( !$feed->save( $this->data, new DateTimeImmutable( self::LATEST ), $path ), 'Reappearance must not erase the previous removal event.' );
				check( Scraper::read( $path ) === $original, 'Removal history must remain byte-for-byte unchanged.' );
			} 
		);
	}

	/**
	 * Verify a later removal of the same notice is appended alongside the earlier event.
	 *
	 * @return void
	 * @throws Throwable If removal history is replaced or distinct observations are collapsed.
	 */
	private function test_feed_appends_repeated_removals(): void {
		$this->with_feed_file(
			function (string $path): void {
				$feed = new Feed();
				$data = $this->data;
				$data['notices'][0]['removed_at'] = self::LATER;
				$feed->save( $data, new DateTimeImmutable( self::LATER ), $path );
				$data['notices'][0]['removed_at'] = self::LATEST;
				check( $feed->save( $data, new DateTimeImmutable( self::LATEST ), $path ), 'Append a later removal observation.' );
				$document = new DOMDocument();
				check( $document->loadXML( Scraper::read( $path ), LIBXML_NONET ), 'Parse repeated removal history.' );
				$xpath = new DOMXPath( $document );
				check( $xpath->evaluate( 'count(/rss/channel/item[category="removed"])' ) === 2.0, 'Retain both removal events.' );
				check( $xpath->evaluate( 'count(/rss/channel/item[category="added"])' ) === 3.0, 'Do not duplicate existing addition events.' );
			} 
		);
	}

	/**
	 * Verify malformed XML and non-RSS documents are rejected without overwriting history.
	 *
	 * @return void
	 * @throws Throwable If invalid existing content is replaced or accepted.
	 */
	private function test_feed_invalid_history_preserved(): void {
		$this->with_feed_file(
			function (string $path): void {
				$feed = new Feed();
				foreach ( ['', '<rss>', '<feed/>', '<rss version="2.0"><channel/><channel/></rss>'] as $xml ) {
					  file_put_contents( $path, $xml );
					  rejects( fn () => $feed->save( $this->data, new DateTimeImmutable( self::LATER ), $path ), 'Reject invalid RSS history.' );
					  check( Scraper::read( $path ) === $xml, 'Invalid history must not be overwritten.' );
				}
			} 
		);
	}

	/**
	 * Verify existing items require unique nonempty GUIDs before deduplication.
	 *
	 * @return void
	 * @throws Throwable If ambiguous event identity is accepted or overwritten.
	 */
	private function test_feed_invalid_history_guids_rejected(): void {
		$this->with_feed_file(
			function (string $path): void {
				$feed = new Feed();
				foreach ( ['<item/>', '<item><guid> </guid></item>', '<item><guid>x</guid><guid>y</guid></item>', '<item><guid>x</guid></item><item><guid>x</guid></item>'] as $items ) {
					  $xml = '<rss version="2.0"><channel>' . $items . '</channel></rss>';
					  file_put_contents( $path, $xml );
					  rejects( fn () => $feed->save( $this->data, new DateTimeImmutable( self::LATER ), $path ), 'Reject missing or ambiguous GUIDs.' );
					  check( Scraper::read( $path ) === $xml, 'Ambiguous history must not be overwritten.' );
				}
			} 
		);
	}

	/**
	 * Verify saving writes a new archive, skips identical data, and preserves JSON values.
	 *
	 * @return void Removes the temporary file even if an assertion fails.
	 * @throws Throwable If file creation, persistence, reading, or an assertion fails.
	 */
	private function test_persistence_round_trip(): void {
		$path = sys_get_temp_dir() . '/cota-notices-' . bin2hex( random_bytes( 8 ) ) . '.json';
		try {
			check( $this->scraper->save( $this->data, $path ), 'First save should write data.' );
			check( !$this->scraper->save( $this->data, $path ), 'Unchanged save should not rewrite data.' );
			check( json_decode( Scraper::read( $path ), true, 512, JSON_THROW_ON_ERROR ) === $this->data, 'Stored data should round-trip.' );
		} finally {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}
}

(new ScraperTests())->run_all();
