<?php

declare(strict_types=1);

use Cota\Scraper;

require __DIR__ . '/vendor/autoload.php';

try {
    $root = __DIR__;
    $path = $root . '/data/notices.json';
    $scraper = new Scraper();
    $previous = is_file($path)
        ? json_decode(Scraper::read($path), true, 512, JSON_THROW_ON_ERROR)
        : ['schema_version' => 1, 'notices' => []];
    $scraper->validate($previous, $root . '/reroutes.schema.json');
    $extracted = $scraper->fetch();
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $data = $scraper->reconcile($previous, $extracted, $now);
    $scraper->validate($data, $root . '/reroutes.schema.json');
    $changed = $scraper->save($data, $path);
    printf("%d visible notices; %d retained records; %s.\n", count($extracted), count($data['notices']), $changed ? 'data updated' : 'no changes');
} catch (Throwable $error) {
    fwrite(STDERR, 'Scrape failed: ' . $error->getMessage() . "\n");
    exit(1);
}