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

use Cota\Scraper;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Fail the test run if the supplied condition is false.
 *
 * @param  bool   $condition True when the assertion passes.
 * @param  string $message   Failure message used when the condition is false.
 * @return void No return value; a true condition allows the test run to continue.
 * @throws RuntimeException If the condition is false.
 */
function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
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
	} catch (Throwable) {
		return;
	}
	throw new RuntimeException($message);
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
		foreach (get_class_methods($this) as $method) {
			if (!str_starts_with($method, 'test_')) {
				continue;
			}
			try {
				$this->set_up();
				$this->$method();
			} catch (Throwable $error) {
				throw new RuntimeException($method . ': ' . $error->getMessage(), 0, $error);
			}
			$count++;
		}
		printf("All %d scraper tests passed.\n", $count);
	}

	/**
	 * Reload the local HTML fixture and create a fresh, validated initial archive.
	 *
	 * @return void Resets the scraper, fixture, notices, schema path, and archive properties.
	 * @throws Throwable If fixture reading, extraction, reconciliation, or validation fails.
	 */
	private function set_up(): void {
		$this->scraper = new Scraper();
		$this->schema  = dirname(__DIR__) . '/reroutes.schema.json';
		$this->html    = Scraper::read(__DIR__ . '/notices.html');
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
		$this->notices = $this->scraper->extract_response($response);
		$this->data    = $this->scraper->reconcile(self::EMPTY_STATE, $this->notices, self::FIRST);
		$this->scraper->validate($this->data, $this->schema);
	}

	/**
	 * Verify extraction recognizes three notices rather than bold list labels.
	 *
	 * @return void
	 * @throws RuntimeException If the extracted notice count is incorrect.
	 */
	private function test_notice_boundaries(): void {
		check(count($this->notices) === 3, 'Expected three notices, not bold list labels.');
	}

	/**
	 * Verify sanitization preserves notice lists.
	 *
	 * @return void
	 * @throws RuntimeException If list markup is missing.
	 */
	private function test_lists_retained(): void {
		check(str_contains($this->notices[0]['html'], '<ul>'), 'Lists must be retained.');
	}

	/**
	 * Verify body paragraphs remain attached to their notice.
	 *
	 * @return void
	 * @throws RuntimeException If the supporting paragraph is missing.
	 */
	private function test_body_paragraphs_retained(): void {
		check(str_contains($this->notices[0]['html'], 'Use neighboring stops.'), 'Body paragraphs must be retained.');
	}

	/**
	 * Verify sanitization removes HTML event-handler attributes.
	 *
	 * @return void
	 * @throws RuntimeException If an event handler survives sanitization.
	 */
	private function test_event_handlers_removed(): void {
		check(!str_contains($this->notices[0]['html'], 'onclick'), 'Event handlers must be removed.');
	}

	/**
	 * Verify relative links resolve against the public COTA page URL.
	 *
	 * @return void
	 * @throws RuntimeException If the expected absolute link is missing.
	 */
	private function test_relative_urls_resolved(): void {
		check(str_contains($this->notices[1]['html'], 'https://cota.com/reroutes/map.jpg'), 'Resolve relative URLs.');
	}

	/**
	 * Verify sanitization removes javascript link schemes.
	 *
	 * @return void
	 * @throws RuntimeException If an unsafe URL scheme remains.
	 */
	private function test_unsafe_urls_removed(): void {
		check(!str_contains($this->notices[1]['html'], 'javascript:'), 'Unsafe URL schemes must be removed.');
	}

	/**
	 * Verify trailing signup content is not included in the last notice.
	 *
	 * @return void
	 * @throws RuntimeException If footer text appears in a notice.
	 */
	private function test_footer_excluded(): void {
		check(!str_contains($this->notices[2]['html'], 'Sign up'), 'Exclude footer content.');
	}

	/**
	 * Verify an unrecognized Gatsby JSON structure is rejected.
	 *
	 * @return void
	 * @throws RuntimeException If the invalid response is accepted.
	 */
	private function test_invalid_gatsby_structure_rejected(): void {
		rejects(fn () => $this->scraper->extract_response('{}'), 'Reject changed Gatsby structure.');
	}

	/**
	 * Verify extraction rejects a missing required category heading.
	 *
	 * @return void
	 * @throws RuntimeException If a missing category is accepted.
	 */
	private function test_missing_category_rejected(): void {
		$html = str_replace('<h3>Closures</h3>', '', $this->html);
		rejects(fn () => $this->scraper->extract($html), 'Reject missing categories.');
	}

	/**
	 * Verify empty sections require an explicit no-notices message.
	 *
	 * @return void
	 * @throws RuntimeException If an unexplained empty section is accepted.
	 */
	private function test_unexplained_empty_section_rejected(): void {
		$html = str_replace('There are no reopenings at this time.', '', $this->html);
		rejects(fn () => $this->scraper->extract($html), 'Reject unexplained empty sections.');
	}

	/**
	 * Verify extraction rejects duplicate notice titles.
	 *
	 * @return void
	 * @throws RuntimeException If duplicate titles are accepted.
	 */
	private function test_duplicate_titles_rejected(): void {
		$html = str_replace('Nov. 9: Relocation', 'Nov. 16: Improvements', $this->html);
		rejects(fn () => $this->scraper->extract($html), 'Reject duplicate titles.');
	}

	/**
	 * Verify extraction rejects unsupported top-level notice markup.
	 *
	 * @return void
	 * @throws RuntimeException If unfamiliar markup is accepted.
	 */
	private function test_unfamiliar_markup_rejected(): void {
		$html = str_replace('<ul>', '<div>', $this->html);
		rejects(fn () => $this->scraper->extract($html), 'Reject unfamiliar markup.');
	}

	/**
	 * Verify no-notices messages must refer to their containing category.
	 *
	 * @return void
	 * @throws RuntimeException If a mismatched empty message is accepted.
	 */
	private function test_mismatched_empty_message_rejected(): void {
		$html = str_replace('There are no reopenings at this time.', 'There are no closures at this time.', $this->html);
		rejects(fn () => $this->scraper->extract($html), 'Reject mismatched empty message.');
	}

	/**
	 * Verify identical extractions do not change records or observation timestamps.
	 *
	 * @return void
	 * @throws RuntimeException If unchanged notices alter archive state.
	 */
	private function test_unchanged_scrape_preserves_state(): void {
		$actual = $this->scraper->reconcile($this->data, $this->notices, self::LATER);
		check($actual === $this->data, 'Unchanged scrapes must not change records.');
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
		$updated = $this->scraper->reconcile($this->data, $changed, self::LATER);
		check(count($updated['notices']) === count($this->data['notices']), 'Punctuation-only title edits must not create records.');
		check($updated['notices'][0]['id'] === $this->data['notices'][0]['id'], 'ASCII and Unicode punctuation must not affect identity.');
		check($updated['notices'][0]['first_seen_at'] === self::FIRST, 'Punctuation-only edits must preserve first-seen time.');
		check($updated['notices'][0]['updated_at'] === self::LATER, 'Punctuation-only edits must still update the stored title timestamp.');
		$this->scraper->validate($updated, $this->schema);
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
		rejects(fn () => $this->scraper->reconcile(self::EMPTY_STATE, $notices, self::LATER), 'Reject titles that differ only by punctuation.');
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
		$updated = $this->scraper->reconcile($this->data, $changed, self::LATER);
		check($updated['notices'][0]['id'] === $this->data['notices'][0]['id'], 'Edits must preserve IDs.');
		check($updated['notices'][0]['first_seen_at'] === self::FIRST, 'Edits must preserve first-seen time.');
		check($updated['notices'][0]['updated_at'] === self::LATER, 'Edits must advance updated time.');
		$this->scraper->validate($updated, $this->schema);
	}

	/**
	 * Verify missing notices remain in the archive with their first removal timestamp.
	 *
	 * @return void
	 * @throws Throwable If a removal timestamp or resulting schema validation fails.
	 */
	private function test_missing_notice_retained(): void {
		$removed = $this->scraper->reconcile($this->data, array_slice($this->notices, 1), self::LATER);
		check($removed['notices'][0]['removed_at'] === self::LATER, 'Retain missing notices with removal timestamps.');
		$this->scraper->validate($removed, $this->schema);
	}

	/**
	 * Verify later scrapes do not advance an existing removal timestamp.
	 *
	 * @return void
	 * @throws RuntimeException If an already removed record changes.
	 */
	private function test_removal_timestamp_preserved(): void {
		$visible = array_slice($this->notices, 1);
		$removed = $this->scraper->reconcile($this->data, $visible, self::LATER);
		check($this->scraper->reconcile($removed, $visible, self::LATEST) === $removed, 'Do not advance removal timestamps.');
	}

	/**
	 * Verify reappearance clears removed_at without changing identity or unchanged content time.
	 *
	 * @return void
	 * @throws RuntimeException If a reappearing notice does not regain its original state.
	 */
	private function test_reappearance_clears_removal(): void {
		$removed = $this->scraper->reconcile($this->data, array_slice($this->notices, 1), self::LATER);
		check($this->scraper->reconcile($removed, $this->notices, self::LATEST) === $this->data, 'Reappearance must clear removal without changing identity.');
	}

	/**
	 * Verify explicit no-notices messages permit all three categories to be empty.
	 *
	 * @return void
	 * @throws Throwable If extraction fails or returns notices for explicitly empty sections.
	 */
	private function test_explicitly_empty_categories(): void {
		$html = '<h3>Reroutes</h3><p>There are no reroutes at this time.</p><h3>Closures</h3><p>There are no closures at this time.</p><h3>Reopenings</h3><p>There are no reopenings at this time.</p>';
		check($this->scraper->extract($html) === [], 'Explicitly empty categories are valid.');
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
		rejects(fn () => $this->scraper->validate($bad, $this->schema), 'Reject invalid category.');
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
		rejects(fn () => $this->scraper->validate($bad, $this->schema), 'Enforce date-time format.');
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
		rejects(fn () => $this->scraper->validate($bad, $this->schema), 'Enforce UUID format.');
	}

	/**
	 * Verify stored content hashes must match the exact HTML bytes.
	 *
	 * @return void
	 * @throws RuntimeException If an incorrect hash is accepted.
	 */
	private function test_incorrect_hash_rejected(): void {
		$bad = $this->data;
		$bad['notices'][0]['content_hash'] = str_repeat('0', 64);
		rejects(fn () => $this->scraper->validate($bad, $this->schema), 'Check stored content hashes.');
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
		rejects(fn () => $this->scraper->validate($bad, $this->schema), 'Reject duplicate IDs.');
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
		rejects(fn () => $this->scraper->validate($bad, $this->schema), 'Reject unknown fields.');
	}

	/**
	 * Verify saving writes a new archive, skips identical data, and preserves JSON values.
	 *
	 * @return void Removes the temporary file even if an assertion fails.
	 * @throws Throwable If file creation, persistence, reading, or an assertion fails.
	 */
	private function test_persistence_round_trip(): void {
		$path = sys_get_temp_dir() . '/cota-notices-' . bin2hex(random_bytes(8)) . '.json';
		try {
			check($this->scraper->save($this->data, $path), 'First save should write data.');
			check(!$this->scraper->save($this->data, $path), 'Unchanged save should not rewrite data.');
			check(json_decode(Scraper::read($path), true, 512, JSON_THROW_ON_ERROR) === $this->data, 'Stored data should round-trip.');
		} finally {
			if (is_file($path)) {
				unlink($path);
			}
		}
	}
}

(new ScraperTests())->run_all();
