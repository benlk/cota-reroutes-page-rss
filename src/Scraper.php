<?php

declare(strict_types=1);

namespace Cota;

use DateTimeImmutable;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Opis\JsonSchema\Validator;
use RuntimeException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class Scraper
{
    public const SOURCE_URL = 'https://cota.com/reroutes/';
    public const ENDPOINT = 'https://cota.com/page-data/reroutes/page-data.json';

    private const CATEGORIES = [
        'Reroutes' => 'reroute',
        'Closures' => 'closure',
        'Reopenings' => 'reopening',
    ];

    /**
     * Fetch Gatsby page data and extract sanitized, currently visible notices.
     *
     * @return list<array{category: string, title: string, html: string}> Notices in source order.
     * @throws \GuzzleHttp\Exception\GuzzleException If the HTTP request fails.
     * @throws \JsonException If the response is not valid JSON.
     * @throws RuntimeException If the response or notice structure is unexpected.
     */
    public function fetch(): array
    {
        $client = new Client([
            'connect_timeout' => 10,
            'timeout' => 60,
            'allow_redirects' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'CotaReroutesScraper/1.0 (daily public-page archive)',
            ],
        ]);
        $response = $client->get(self::ENDPOINT);
        if ($response->getStatusCode() !== 200
            || !preg_match('~^application/json(?:\s*;|$)~i', $response->getHeaderLine('Content-Type'))) {
            throw new RuntimeException('Unexpected Gatsby HTTP status or content type.');
        }

        return $this->extractResponse((string) $response->getBody());
    }

    /**
     * Validate the Gatsby response identity and extract its editorial HTML.
     *
     * @param string $response Raw JSON response body from the Gatsby endpoint.
     * @return list<array{category: string, title: string, html: string}> Sanitized notices in source order.
     * @throws \JsonException If the response is not valid JSON.
     * @throws RuntimeException If page identity, content, or notice structure is unexpected.
     */
    public function extractResponse(string $response): array
    {
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        $edges = $data['result']['data']['page']['edges'] ?? null;
        if (($data['path'] ?? null) !== '/reroutes/'
            || !is_array($edges) || count($edges) !== 1
            || ($edges[0]['node']['title'] ?? null) !== 'Reroutes') {
            throw new RuntimeException('Unexpected Gatsby reroutes response structure.');
        }
        $html = $edges[0]['node']['content'] ?? null;
        if (!is_string($html) || trim($html) === '') {
            throw new RuntimeException('Missing Gatsby reroutes HTML content.');
        }

        return $this->extract($html);
    }

    /**
     * Split editorial HTML into notices under the three required category headings.
     *
     * @param string $html UTF-8 HTML fragment containing the publisher's notice sections.
     * @return list<array{category: string, title: string, html: string}> Sanitized notices, or an empty list when all sections explicitly declare no notices.
     * @throws RuntimeException If markup, category order, empty messages, or titles are ambiguous or invalid.
     */
    public function extract(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $body = $document->querySelector('body');
        if ($body === null) {
            throw new RuntimeException('Missing HTML body.');
        }
        $notices = [];
        $seen = [];
        $counts = [];
        $empty = [];
        $category = null;
        $current = null;
        $finished = false;

        foreach ($body->childNodes as $block) {
            if ($block instanceof Text && trim($block->textContent) !== '' && $category !== null) {
                throw new RuntimeException('Unexpected unwrapped text in notice section.');
            }
            if (!$block instanceof Element) {
                continue;
            }
            $tag = strtolower($block->tagName);
            if ($tag === 'h3') {
                $this->finishNotice($current, $notices);
                $heading = self::text($block->textContent);
                $expected = array_keys(self::CATEGORIES)[count($seen)] ?? null;
                if ($heading !== $expected) {
                    throw new RuntimeException('Unexpected category heading or order: ' . $heading);
                }
                $category = self::CATEGORIES[$heading];
                $seen[] = $heading;
                $counts[$category] = 0;
                $empty[$category] = false;
                continue;
            }
            if ($tag === 'hr') {
                $this->finishNotice($current, $notices);
                if ($category === 'reopening') {
                    $finished = true;
                }
                $category = null;
                continue;
            }
            if ($category === null) {
                if ($seen !== [] && !$finished) {
                    throw new RuntimeException('Unexpected content between category sections.');
                }
                if ($finished && ($tag !== 'p'
                    || !str_starts_with(self::text($block->textContent), 'Sign up for alerts'))) {
                    throw new RuntimeException('Unexpected content after notice sections.');
                }
                continue;
            }
            if (!in_array($tag, ['p', 'ul', 'ol'], true)) {
                throw new RuntimeException('Unexpected notice block: ' . $tag);
            }
            $title = $tag === 'p' ? $this->title($block) : null;
            if ($title !== null) {
                if ($empty[$category]) {
                    throw new RuntimeException('Notice found in a section marked empty.');
                }
                $this->finishNotice($current, $notices);
                $current = ['category' => $category, 'title' => $title, 'html' => ''];
                $counts[$category]++;
            } elseif (preg_match('/^There are no (reroutes|closures|reopenings) at this time\.$/i', self::text($block->textContent), $matches)) {
                if ($tag !== 'p' || $counts[$category] !== 0 || $empty[$category]
                    || self::CATEGORIES[ucfirst(strtolower($matches[1]))] !== $category) {
                    throw new RuntimeException('Conflicting empty-category message.');
                }
                $empty[$category] = true;
                continue;
            } elseif ($current === null) {
                throw new RuntimeException('Notice content without a recognized title.');
            }
            $current['html'] .= $document->saveHtml($block) . "\n";
        }
        $this->finishNotice($current, $notices);

        if ($seen !== array_keys(self::CATEGORIES)) {
            throw new RuntimeException('Missing required category headings.');
        }
        foreach ($counts as $name => $count) {
            if ($count === 0 && !$empty[$name]) {
                throw new RuntimeException('Empty section without an explicit empty message: ' . $name);
            }
        }
        $titles = array_map(fn (array $notice): string => self::key($notice['title']), $notices);
        if (count($titles) !== count(array_unique($titles))) {
            throw new RuntimeException('Duplicate notice titles require manual reconciliation.');
        }

        return $notices;
    }

    /**
     * Recognize leading bold title text, optionally wrapped in a link.
     *
     * @param Element $paragraph Top-level paragraph to inspect for a notice heading.
     * @return string|null Whitespace-normalized title, or null when no heading is recognized.
     * @throws RuntimeException If a recognized heading contains no title text.
     */
    private function title(Element $paragraph): ?string
    {
        $first = $paragraph->firstChild;
        while ($first instanceof Text && trim($first->textContent) === '') {
            $first = $first->nextSibling;
        }
        if (!$first instanceof Element) {
            return null;
        }
        if (strtolower($first->tagName) === 'a') {
            $first = $first->firstElementChild;
        }
        if (!$first instanceof Element || !in_array(strtolower($first->tagName), ['strong', 'b'], true)) {
            return null;
        }
        $title = self::text($first->textContent);
        if ($title === '') {
            throw new RuntimeException('Empty notice title.');
        }

        return $title;
    }

    /**
     * Resolve links, sanitize the pending notice, append it, and clear the pending value.
     *
     * @param array{category: string, title: string, html: string}|null $current Pending notice, passed by reference; set to null after appending. Null is a no-op.
     * @param list<array{category: string, title: string, html: string}> $notices Output list, passed by reference; receives the sanitized notice.
     * @return void No value is returned; the arguments are updated in place.
     * @throws \InvalidArgumentException If a link cannot be parsed as a URI.
     * @throws RuntimeException If sanitization leaves no notice text.
     */
    private function finishNotice(?array &$current, array &$notices): void
    {
        if ($current === null) {
            return;
        }
        $document = HTMLDocument::createFromString($current['html'], LIBXML_NOERROR, 'UTF-8');
        foreach ($document->querySelectorAll('a[href]') as $link) {
            $uri = UriResolver::resolve(new Uri(self::SOURCE_URL), new Uri($link->getAttribute('href')));
            $link->setAttribute('href', (string) $uri);
        }
        $config = (new HtmlSanitizerConfig())->allowLinkSchemes(['http', 'https']);
        foreach (['p', 'br', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i'] as $tag) {
            $config = $config->allowElement($tag);
        }
        $config = $config->allowElement('a', ['href', 'title']);
        $sanitizer = new HtmlSanitizer($config);
        $body = $document->querySelector('body');
        $fragment = '';
        foreach ($body->childNodes as $child) {
            $fragment .= $document->saveHtml($child);
        }
        $current['html'] = trim($sanitizer->sanitize($fragment));
        if (trim(strip_tags($current['html'])) === '') {
            throw new RuntimeException('Sanitization produced an empty notice.');
        }
        $notices[] = $current;
        $current = null;
    }

    /**
     * Merge a successful extraction into retained state using normalized titles as identity keys.
     *
     * Matching notices retain IDs and first-seen times; changed fields advance updated_at.
     * Missing notices receive removed_at once, and reappearing notices lose removed_at.
     * A title that no longer matches creates a new record. Inputs are not modified.
     *
     * @param array{schema_version: int, notices: list<array<string, string>>} $previous Previously validated state, including removed notices.
     * @param list<array{category: string, title: string, html: string}> $extracted Complete, successfully extracted set of visible notices.
     * @param string $now Observation time as a schema-compatible date-time string, normally UTC.
     * @return array{schema_version: int, notices: list<array<string, string>>} Merged state with IDs, source URLs, hashes, timestamps, and retained removals.
     * @throws RuntimeException If persisted or extracted titles have duplicate normalized keys.
     * @throws \Random\RandomException If generating a new notice UUID fails.
     */
    public function reconcile(array $previous, array $extracted, string $now): array
    {
        $notices = $previous['notices'];
        $byTitle = [];
        foreach ($notices as $index => $notice) {
            $key = self::key($notice['title']);
            if (isset($byTitle[$key])) {
                throw new RuntimeException('Ambiguous persisted notice titles.');
            }
            $byTitle[$key] = $index;
        }
        $visible = [];
        foreach ($extracted as $notice) {
            $key = self::key($notice['title']);
            if (isset($visible[$key])) {
                throw new RuntimeException('Duplicate extracted notice title.');
            }
            $visible[$key] = true;
            $notice['source_url'] = self::SOURCE_URL;
            $notice['content_hash'] = hash('sha256', $notice['html']);
            if (isset($byTitle[$key])) {
                $index = $byTitle[$key];
                $stored = $notices[$index];
                $changed = false;
                foreach ($notice as $field => $value) {
                    $changed = $changed || $stored[$field] !== $value;
                }
                $notice['id'] = $stored['id'];
                $notice['first_seen_at'] = $stored['first_seen_at'];
                $notice['updated_at'] = $changed ? $now : $stored['updated_at'];
                $notices[$index] = $notice;
            } else {
                $notice['id'] = self::uuid();
                $notice['first_seen_at'] = $now;
                $notice['updated_at'] = $now;
                $notices[] = $notice;
            }
        }
        foreach ($notices as &$notice) {
            if (!isset($visible[self::key($notice['title'])]) && !isset($notice['removed_at'])) {
                $notice['removed_at'] = $now;
            }
        }
        unset($notice);

        return ['schema_version' => 1, 'notices' => $notices];
    }

    /**
     * Check JSON Schema conformance, unique IDs, HTML hashes, and timestamp ordering.
     *
     * @param array{schema_version: int, notices: list<array<string, string>>} $data Candidate persisted state to validate; malformed values are rejected.
     * @param string $schemaPath Filesystem path to the JSON Schema document.
     * @return void Returns normally only when all checks pass; does not modify the data.
     * @throws \JsonException If schema decoding or data JSON conversion fails.
     * @throws RuntimeException If reading the schema or validating the state fails.
     * @throws \DateMalformedStringException If a timestamp cannot be parsed after schema validation.
     */
    public function validate(array $data, string $schemaPath): void
    {
        $schema = json_decode(self::read($schemaPath), false, 512, JSON_THROW_ON_ERROR);
        $object = json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $result = (new Validator())->validate($object, $schema);
        if (!$result->isValid()) {
            throw new RuntimeException('Notice data failed JSON Schema validation.');
        }
        $ids = [];
        foreach ($data['notices'] as $notice) {
            if (isset($ids[$notice['id']]) || hash('sha256', $notice['html']) !== $notice['content_hash']) {
                throw new RuntimeException('Duplicate notice ID or incorrect content hash.');
            }
            $ids[$notice['id']] = true;
            $firstSeen = new DateTimeImmutable($notice['first_seen_at']);
            if (new DateTimeImmutable($notice['updated_at']) < $firstSeen
                || (isset($notice['removed_at']) && new DateTimeImmutable($notice['removed_at']) < $firstSeen)) {
                throw new RuntimeException('Notice timestamps are out of order.');
            }
        }
    }

    /**
     * Atomically persist pretty-printed JSON, skipping writes when bytes are unchanged.
     *
     * @param array{schema_version: int, notices: list<array<string, string>>} $data Validated notice state; this method does not perform validation.
     * @param string $path Destination JSON file path; parent directories are created if needed.
     * @return bool True when data was written, or false when the existing file was identical.
     * @throws \JsonException If the data cannot be encoded as JSON.
     * @throws RuntimeException If reading, directory creation, or atomic persistence fails.
     */
    public function save(array $data, string $path): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (is_file($path) && self::read($path) === $json) {
            return false;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create data directory.');
        }
        $temporary = tempnam($directory, '.notices-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create temporary data file.');
        }
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $path)) {
                throw new RuntimeException('Cannot atomically save notice data.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return true;
    }

    /**
     * Read the complete contents of a file without interpreting its format.
     *
     * @param string $path Filesystem path to read.
     * @return string Raw file contents, which may be empty.
     * @throws RuntimeException If the file cannot be read.
     */
    public static function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read ' . $path);
        }

        return $contents;
    }

    /**
     * Collapse whitespace and nonbreaking spaces, then trim the result.
     *
     * @param string $text Valid UTF-8 text to normalize.
     * @return string Normalized text, or an empty string for whitespace-only input.
     */
    private static function text(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00a0}", ' ', $text)));
    }

    /**
     * Build the case-insensitive, whitespace-normalized notice identity key.
     *
     * @param string $title Valid UTF-8 notice title.
     * @return string Lowercase normalized title used for matching and duplicate detection.
     */
    private static function key(string $title): string
    {
        return mb_strtolower(self::text($title), 'UTF-8');
    }

    /**
     * Generate a random version 4 UUID for a new notice.
	 *
	 * Not using https://www.php.net/manual/en/function.uniqid.php because that can return the same value if called multiple times per microsecond.
     *
     * @return string Lowercase UUID in canonical 8-4-4-4-12 hexadecimal form.
     * @throws \Random\RandomException If secure random bytes cannot be generated.
     */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
