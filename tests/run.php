<?php

declare(strict_types=1);

use Cota\Scraper;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Assert a test condition and stop the test run on failure.
 *
 * @param bool $condition True when the assertion passes.
 * @param string $message Failure message used when the condition is false.
 * @return void Returns normally when the condition is true.
 * @throws RuntimeException If the condition is false.
 */
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Assert that an operation throws any Throwable; its return value is ignored.
 *
 * @param callable(): mixed $operation Zero-argument operation expected to throw.
 * @param string $message Failure message used when the operation completes normally.
 * @return void Returns normally only when the operation throws.
 * @throws RuntimeException If the operation does not throw.
 */
function rejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

$scraper = new Scraper();
$schema = dirname(__DIR__) . '/reroutes.schema.json';
$html = Scraper::read(__DIR__ . '/notices.html');
$response = json_encode([
    'path' => '/reroutes/',
    'result' => ['data' => ['page' => ['edges' => [['node' => ['title' => 'Reroutes', 'content' => $html]]]]]],
], JSON_THROW_ON_ERROR);
$notices = $scraper->extractResponse($response);
check(count($notices) === 3, 'Expected three notices, not bold list labels.');
check(str_contains($notices[0]['html'], '<ul>'), 'Lists must be retained.');
check(str_contains($notices[0]['html'], 'Use neighboring stops.'), 'Body paragraphs must be retained.');
check(!str_contains($notices[0]['html'], 'onclick'), 'Event handlers must be removed.');
check(str_contains($notices[1]['html'], 'https://cota.com/reroutes/map.jpg'), 'Resolve relative URLs.');
check(!str_contains($notices[1]['html'], 'javascript:'), 'Unsafe URL schemes must be removed.');
check(!str_contains($notices[2]['html'], 'Sign up'), 'Exclude footer content.');
rejects(fn () => $scraper->extractResponse('{}'), 'Reject changed Gatsby structure.');
rejects(fn () => $scraper->extract(str_replace('<h3>Closures</h3>', '', $html)), 'Reject missing categories.');
rejects(fn () => $scraper->extract(str_replace('There are no reopenings at this time.', '', $html)), 'Reject unexplained empty sections.');
rejects(fn () => $scraper->extract(str_replace('Nov. 9: Relocation', 'Nov. 16: Improvements', $html)), 'Reject duplicate titles.');
rejects(fn () => $scraper->extract(str_replace('<ul>', '<div>', $html)), 'Reject unfamiliar markup.');
rejects(fn () => $scraper->extract(str_replace('There are no reopenings at this time.', 'There are no closures at this time.', $html)), 'Reject mismatched empty message.');

$empty = ['schema_version' => 1, 'notices' => []];
$first = '2026-11-11T12:00:00Z';
$later = '2026-11-12T12:00:00Z';
$latest = '2026-11-13T12:00:00Z';
$data = $scraper->reconcile($empty, $notices, $first);
$scraper->validate($data, $schema);
check($scraper->reconcile($data, $notices, $later) === $data, 'Unchanged scrapes must not change records.');
$changed = $notices;
$changed[0]['html'] .= '<p>Updated.</p>';
$updated = $scraper->reconcile($data, $changed, $later);
check($updated['notices'][0]['id'] === $data['notices'][0]['id'], 'Edits must preserve IDs.');
check($updated['notices'][0]['first_seen_at'] === $first, 'Edits must preserve first-seen time.');
check($updated['notices'][0]['updated_at'] === $later, 'Edits must advance updated time.');
$scraper->validate($updated, $schema);
$removed = $scraper->reconcile($data, array_slice($notices, 1), $later);
check($removed['notices'][0]['removed_at'] === $later, 'Retain missing notices with removal timestamps.');
check($scraper->reconcile($removed, array_slice($notices, 1), $latest) === $removed, 'Do not advance removal timestamps.');
check($scraper->reconcile($removed, $notices, $latest) === $data, 'Reappearance must clear removal without changing identity.');
$scraper->validate($removed, $schema);
$allEmpty = $scraper->extract('<h3>Reroutes</h3><p>There are no reroutes at this time.</p><h3>Closures</h3><p>There are no closures at this time.</p><h3>Reopenings</h3><p>There are no reopenings at this time.</p>');
check($allEmpty === [], 'Explicitly empty categories are valid.');
$bad = $data;
$bad['notices'][0]['category'] = 'other';
rejects(fn () => $scraper->validate($bad, $schema), 'Reject invalid category.');
$bad = $data;
$bad['notices'][0]['first_seen_at'] = 'not-a-date';
rejects(fn () => $scraper->validate($bad, $schema), 'Enforce date-time format.');
$bad = $data;
$bad['notices'][0]['id'] = 'not-a-uuid';
rejects(fn () => $scraper->validate($bad, $schema), 'Enforce UUID format.');
$bad = $data;
$bad['notices'][0]['content_hash'] = str_repeat('0', 64);
rejects(fn () => $scraper->validate($bad, $schema), 'Check stored content hashes.');
$bad = $data;
$bad['notices'][1]['id'] = $bad['notices'][0]['id'];
rejects(fn () => $scraper->validate($bad, $schema), 'Reject duplicate IDs.');
$bad = $data;
$bad['notices'][0]['extra'] = true;
rejects(fn () => $scraper->validate($bad, $schema), 'Reject unknown fields.');

$path = sys_get_temp_dir() . '/cota-notices-' . bin2hex(random_bytes(8)) . '.json';
try {
    check($scraper->save($data, $path), 'First save should write data.');
    check(!$scraper->save($data, $path), 'Unchanged save should not rewrite data.');
    check(json_decode(Scraper::read($path), true, 512, JSON_THROW_ON_ERROR) === $data, 'Stored data should round-trip.');
} finally {
    if (is_file($path)) {
        unlink($path);
    }
}

echo "All scraper tests passed.\n";