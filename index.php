<?php
/**
 * Output README.md as a GitHub-flavored Markdown HTML page from the CLI.
 *
 * Does not write files. Composer handles build output and asset copying.
 */

declare(strict_types=1);

use Cota\Scraper;
use Dom\HTMLDocument;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use League\CommonMark\GithubFlavoredMarkdownConverter;

require __DIR__ . '/vendor/autoload.php';

$converter = new GithubFlavoredMarkdownConverter(
	[
		'html_input' => 'strip',
		'allow_unsafe_links' => false,
	]
);

$readme_html    = (string) $converter->convert( Scraper::read( __DIR__ . '/README.md' ) );
$document       = HTMLDocument::createFromString( $readme_html, LIBXML_NOERROR, 'UTF-8' );
$repository_url = 'https://github.com/benlk/cota-reroutes-page-rss';
$source_base    = new Uri( $repository_url . '/blob/trunk/' );
$readme_html    = '';

foreach ( $document->querySelectorAll( 'a[href]' ) as $link ) {
	$href = $link->getAttribute( 'href' );
	$uri = new Uri( $href );
	if ( $href !== '' && !str_starts_with( $href, '#' ) && $uri->getScheme() === '' && $uri->getHost() === '' ) {
		$link->setAttribute( 'href', (string) UriResolver::resolve( $source_base, $uri ) );
	}
}

foreach ( $document->querySelector( 'body' )->childNodes as $child ) {
	$readme_html .= $document->saveHtml( $child );
}

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="description" content="An independent archive and RSS feed of COTA reroutes, closures, and reopenings.">
	<title>COTA Reroute Page Scraper and RSS Feed</title>
	<link rel="stylesheet" href="style.css">
	<link rel="alternate" type="application/rss+xml" title="COTA Reroute Notices" href="feed.xml">
</head>
<body>
	<a class="skip-link" href="#content">Skip to content</a>
	<header class="site-header">
		<div class="header-inner">
			<a class="site-name" href="./">COTA Reroute Scraper</a>
			<nav aria-label="Primary navigation">
				<a href="feed.xml">RSS feed</a>
				<a href="{$repository_url}">GitHub</a>
				<a href="https://cota.com/reroutes/">COTA Reroutes</a>
			</nav>
		</div>
	</header>
	<main id="content" tabindex="-1">
		<article class="readme">{$readme_html}</article>
	</main>
	<footer class="site-footer">
		<p>Independent project. Not affiliated with the Central Ohio Transit Authority.</p>
		<p><a href="https://cota.com/reroutes/">Official COTA reroute information</a></p>
		<p><a href="https://benlk.github.io/">More projects like this, from Ben Keith</a></p>
	</footer>
</body>
</html>
HTML;
echo $html;
