# Gatsby Transport Endpoint

> [!NOTE]
> This document was written by GitHub Copilot in a single shot. It serves as an example of the power of AI-powered discovery.

## Inspection

Verified on November 11, 2026, using VS Code's browser to inspect the reroutes page, loaded scripts, the original HTML response, and Gatsby's page-data response. These observations describe the deployed site at that time, not a guaranteed upstream API contract.

- Public page: <https://cota.com/reroutes/>
- Gatsby transport endpoint: <https://cota.com/page-data/reroutes/page-data.json>
- The endpoint returned HTTP `200` with `Content-Type: application/json`, without authentication.
- The original public page also contained the notices in its server-delivered HTML. Neither transport requires browser execution for scraping.

## Response Structure

The JSON response had the top-level keys `componentChunkName`, `path`, `result`, `staticQueryHashes`, and `slicesMap`. Its `path` was `/reroutes/`, and its component chunk name was `component---src-templates-page-page-jsx`.

The editorial HTML fragment is at `result.data.page.edges[0].node.content`. The corresponding node contained `content` and `title`, with the title `Reroutes`. The sibling `result.data.parentsRelatedLinks` field is not the notice content.

The response also included `result.pageContext`, with `content`, `date`, `excerpt`, `id`, `parentDatabaseId`, `slug`, and `title`. The content was duplicated there, but the inspected extraction path was the node's `content` field. Page-level metadata must not be treated as identifiers or publication dates for individual notices.

This is a Gatsby build artifact carrying HTML, not a structured reroute API. Individual notices do not have their own records or IDs in the inspected node. A separate public CMS or alert API was not verified during this inspection.

Decode and check the response in PHP before passing the fragment to an HTML parser:

```php
$data = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
$content = $data['result']['data']['page']['edges'][0]['node']['content'] ?? null;

if (!is_string($content) || trim($content) === '') {
    throw new UnexpectedValueException('Missing Gatsby reroutes HTML content.');
}
```

HTTP status, response content type, expected page identity, and successful extraction also need validation; a decodable JSON response alone is not sufficient.

## HTML Structure

The fragment includes introductory text, a style element, horizontal rules, notice content, and a final alerts signup paragraph. It is not exclusively a list of notices.

Three `<h3>` headings divide the publisher's categories: `Reroutes`, `Closures`, and `Reopenings`. Notice titles begin top-level paragraphs, either as direct bold text or as bold text inside a link:

```html
<p><strong>Notice title</strong><br />Impacted Stops: ...</p>
<p><a href="/reroutes/2026/example.jpg"><strong>Notice title</strong></a><br />Impacted Stop: ...</p>
```

Supporting content spans subsequent paragraphs and lists. Some lists contain bold labels such as `<li><strong>Stop 1197:</strong> ...</li>`, so splitting at every `<strong>` element would incorrectly create additional notices. Recognize titles by their position at the start of top-level paragraphs, allowing the link wrapper, then collect sibling blocks until the next notice or category boundary.

A trial of this structural rule identified five reroutes, five closures, and zero reopening notices. The Reopenings section contained `There are no reopenings at this time.` rather than a notice. These counts are an observation, not an invariant to hard-code.

Ignore introductory material, styles, separators, and the final alerts signup paragraph. Preserve notice lists and links, resolve relative link targets against the public page URL, and sanitize HTML before publication. Use a DOM-based HTML parser in PHP rather than regular expressions to split HTML.

Publisher categories are not definitive operational status: the Reroutes section included stop closures. Dates can be ranges or estimates, and some listed notices referred to past dates. Do not infer reopening or removal from date wording alone.

## HTTP Observations

The inspected JSON response included an `ETag`, `Last-Modified: Wed, 11 Nov 2026 22:15:02 GMT`, and `Cache-Control: no-cache,no-store,must-revalidate`. These headers describe the transport representation, not the update time of each notice. Conditional-request behavior was not tested; do not assume support from the presence of validators alone.

The inspected <https://cota.com/robots.txt> returned HTTP `200` and included `User-agent: *` followed by `Allow: /`. Recheck crawling guidance when implementing or operating the scraper.

## Recommended Use

Use the JSON endpoint as the primary transport because it isolates editorial content from the site's navigation and scripts. Persist the public page URL as each notice's source URL, not the Gatsby endpoint.

The original HTML provides a possible fallback: its `article.content` element contained the same three category headings and notice content. Avoid the generated CSS class names alongside `content`, which can change between builds. If fallback is implemented, log its use so a changed Gatsby response contract remains visible.

Fail safely on network errors, unexpected response shapes, missing category boundaries, or ambiguous extraction. Do not replace good persisted data or mark notices removed after a failed or incomplete scrape. Record `removed_at` only after a successful, validated comparison establishes that a previously stored notice is no longer listed; absence does not imply reopening.

Keep fixture-based PHP tests for the response path, linked and unlinked titles, bold list labels, multiline notice bodies, empty-category messages, and trailing signup content. Daily polling records observations, not exact publication or removal times, and cannot capture notices that appear and disappear between runs.
