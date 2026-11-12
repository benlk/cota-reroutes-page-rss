# COTA Reroute Page RSS Feed

**⚠️ Not an official COTA service or a real-time alert system.** Check [COTA's current notices](https://cota.com/reroutes/) or [the Transit App](https://www.cota.com/transitapp/) before traveling.

## Subscribe

<https://benlk.github.io/cota-reroutes-page-rss/feed.xml>

Put that in your RSS feed reader.

Three types of events appear in that feed:

- **Added:** First observed on COTA's page, or observed again after removal. Not necessarily newly published or effective that day.
- **Change:** A known notice was edited. The item includes labeled old and new content.
- **Removed:** No longer listed. This does **not** mean a stop has reopened or normal service has resumed; it just means that the thing is no longer listed.

The scrape powering this feed runs dailyish, overnight, subject to GitHub delays and failures. Notices that appear and disappear between scrapes may be missed. Published items retain their original text; edits to matched notices create separate Change items. A title edit that prevents matching may still appear as a removal and addition.

Direct bus service questions to [COTA](https://cota.com/contact/).

If you have questions about this archive, or problems with it, [submit an issue on GitHub](https://github.com/benlk/cota-reroutes-page-rss/issues).

## How this works

cota.com uses [Gatsby](https://www.gatsbyjs.com/), which publishes the reroutes page content as a full HTML page at `https://cota.com/reroutes/` and as an HTML fragment at `https://cota.com/page-data/reroutes/page-data.json`.

1. **Fetch:** A scheduled GitHub Action runs the PHP scraper, which reads the fragment at `result.data.page.edges[0].node.content`.
2. **Extract:** The headings Reroutes, Closures, and Reopenings define categories. A paragraph beginning with bold text, optionally inside a link, starts a notice; subsequent paragraphs and lists belong to it. The scraper sanitizes the HTML and resolves relative links.
3. **Compare and Save:** Extracted notices are matched against [the archive](data/notices.json) by title:
    - New notices receive a first-seen timestamp and a new GUID, and generate an "Added" feed item.
	- Notices gone missing receive a `removed_at` timestamp, and generate a "Removed" feed item if the feed is generated within 15 mintes of the item being marked in the data set as removed.
	- Reappearing notices create new records and Added feed items; previous records retain their removal timestamps.
	- Notices whose contents are edited generate a "Change" item in the RSS feed, with the before and after and `updated_at` time.
	- Unexpected markup or ambiguous titles abort the scrape rather than guess.
4. **Publish:** The scrape workflow commits changed data. After successful completion, the Pages workflow renders this README, copies the existing feed into the website build, and deploys it to GitHub Pages.

Timestamps record the time the scraper observed something, not when COTA edited its page or when service changes took effect. See [the discovery findings doc](docs/gatsby-transport.md) for source and parsing details.

## For Maintainers

### Install and Check

```sh
composer install
composer validate --strict
composer run test
composer run lint
```

### Update the Data and Site

```sh
composer run scrape
composer run build
git diff
```

**Commit all changed files.**

- Scraping requires an existing `data/notices.json` and aborts if it is missing. Restore missing or corrupt data from Git, not an empty replacement. The notice archive cannot reconstruct all published removal history.
- Generation of an RSS feed requires an existing `data/feed.xml` to add to.

### Data and Event History

| File | Purpose |
| --- | --- |
| [data/notices.json](data/notices.json) | Notice archive, including removed records. |
| [data/feed.xml](data/feed.xml) | Append-only RSS history, not disposable build output. |
| [reroutes.schema.json](reroutes.schema.json) | Archive schema. |
| [docs/gatsby-transport.md](docs/gatsby-transport.md) | Source endpoint and extraction findings. |

Active records match by case-insensitive titles with punctuation removed and whitespace normalized. Matches retain UUIDs and `first_seen_at`; content changes advance `updated_at`. Absence sets `removed_at` permanently for that occurrence. Reappearance creates a new UUID and first-seen timestamp, retaining the removed record. Historical titles may repeat; duplicate active or extracted titles fail reconciliation.

Extraction requires all three category headings in order and explicit empty-section messages. Unexpected markup fails closed; there is no HTML fallback. Review bulk removals: structurally valid but incomplete upstream content can pass these checks.

The RSS writer prepends new items without changing existing items. Feed GUIDs for additions use the notice's identity; removal GUIDs also include the observation timestamp. Change GUIDs combine notice identity, the previous version's timestamp, and the edited fields, deduplicating retries while preserving successive edits and reversions. New batches are newest-first, prepended before existing history.

### Build and Preview

1. Run `composer run build` to generate `_site/` from the README, CSS, and existing feed. Missing feed data fails the build.
2. Run `php -S localhost:8000 -t _site` to start the preview server.
3. Open <http://localhost:8000/>.

### GitHub Actions and Pages

[Scraping](.github/workflows/scrape.yml) tests relevant pushes and PRs; scheduled and default-branch manual runs also scrape, append feed events, and commit changed data.

[Pages](.github/workflows/pages.yml) deploys `_site/` after pushes to `trunk`, manual dispatch, or successful scraper completion. The completion trigger handles bot commits, which do not trigger another push workflow.

### Troubleshooting

| Problem | Next step |
| --- | --- |
| Extraction fails | Inspect upstream markup or title collisions; update tests before changing parsing. |
| RSS rejected | Repair malformed XML or ambiguous GUIDs without discarding history. |
| Removal missing | Check the fifteen-minute publication window; late retries do not backfill it. |
| Website stale | Check both workflows, the data commit, and Pages deployment status. |
| Push rejected | Check branch rules or branch advancement; rerun without force-pushing. |
