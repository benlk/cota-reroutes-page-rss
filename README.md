# COTA Reroute Page RSS Feed

**⚠️ Not an official COTA service or a real-time alert system.** Check [COTA's current notices](https://cota.com/reroutes/) or [the Transit App](https://www.cota.com/transitapp/) before traveling.

## Subscribe

<https://benlk.github.io/cota-reroutes-page-rss/feed.xml>

Put that in your RSS feed reader.

Two types of events appear in that feed:

- **Added:** First observed on COTA's page, not necessarily newly published or effective that day.
- **Removed:** No longer listed. This does **not** mean a stop has reopened or normal service has resumed; it just means that the thing is no longer listed.

The scrape powering this feed run dailyish, overnight, subject to GitHub delays and failures. Notices that appear and disappear between scrapes may be missed. Published items retain their original text in the feed; edit to text do not create update events in the feed. A changed title on a section may appear as a removal and addition.

Direct bus service questions to [COTA](https://cota.com/contact/).

If you have questions about this archive, or problems with it, [submit an issue on GitHub](https://github.com/benlk/cota-reroutes-page-rss/issues).

## How this works

cota.com uses [Gatsby](https://www.gatsbyjs.com/), which publishes the reroutes page content as a full HTML page at `https://cota.com/reroutes/` and as an HTML fragment at `https://cota.com/page-data/reroutes/page-data.json`.

1. **Fetch:** A scheduled GitHub Action runs the PHP scraper, which reads the fragment at `result.data.page.edges[0].node.content`.
2. **Extract:** The headings Reroutes, Closures, and Reopenings define categories. A paragraph beginning with bold text, optionally inside a link, starts a notice; subsequent paragraphs and lists belong to it. The scraper sanitizes the HTML and resolves relative links.
3. **Compare:** Extracted notices are matched against [the archive](data/notices.json) by title:
    - New notices receive a first-seen timestamp and a new GUID.
	- Notices gone missing receive a `removed_at` timestamp
	- Notices gone missing that then reappear have that removal marker cleared
	- Unexpected markup or ambiguous titles abort the scrape rather than guess.
4. **Append:** The RSS writer adds events whose GUIDs are not already in [the feed](data/feed.xml). Additions' feed items are dated with the first-seen timestamp. Feed items for removals are generated when the feed is generated within fifteen minutes of `removed_at`. Existing items are never rewritten, and content edits or reappearances do not create separate events.
5. **Publish:** The scrape workflow commits changed data. After successful completion, the Pages workflow renders this README, copies the existing feed into the website build, and deploys it to GitHub Pages. Feed readers then discover new items by GUID.

These timestamps record what the scraper observed, not when COTA edited its page or when service changes took effect. See [the transport findings](docs/gatsby-transport.md) for source and parsing details.

## For Maintainers

### Install and Check

```sh
composer install
composer validate --strict
composer run test
composer run lint
```

### Update the Data and Feed

```sh
composer run scrape
composer run feed
composer run build
git diff
```

Generate the feed immediately after scraping: "removal" events are only generated within fifteen minutes of `removed_at`.

Changes to a known notice's title, category, or sanitized HTML produce a GitHub Actions warning identifying the notice and changed fields. The archive is updated normally; previously published RSS items remain unchanged.

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

Identity uses case-insensitive titles with punctuation removed and whitespace normalized. Matches retain UUIDs and `first_seen_at`; content changes advance `updated_at`. Absence sets `removed_at`; reappearance clears it. Duplicate normalized titles fail reconciliation.

Extraction requires all three category headings in order and explicit empty-section messages. Unexpected markup fails closed; there is no HTML fallback. Review bulk removals: structurally valid but incomplete upstream content can pass these checks.

The RSS writer appends unseen GUIDs without changing existing items. Addition GUIDs use notice identity; removal GUIDs also include the observation timestamp. New batches are newest-first, appended after existing history. There are no content-edit or reappearance events. Atomic saves prevent partial files, not concurrent-update conflicts.

### Build and Preview

1. Run `composer run build` to generate `_site/` from the README, CSS, and existing feed. Missing feed data fails the build.
2. Run `php -S localhost:8000 -t _site` to start the preview server.
3. Open <http://localhost:8000/>.
4. Press `Ctrl+C` in the terminal to stop the server.

Only `_site/.gitkeep` is tracked.

For forks or custom domains, update the subscription URL above and the repository URL in [index.php](index.php).

### GitHub Actions and Pages

[Scraping](.github/workflows/scrape.yml) tests relevant pushes and PRs; scheduled and default-branch manual runs also scrape, append feed events, and commit changed data. [Pages](.github/workflows/pages.yml) deploys `_site/` after pushes to `trunk`, manual dispatch, or successful scraper completion. The completion trigger handles bot commits, which do not trigger another push workflow.

Select **GitHub Actions** as the Pages source, allow bot data commits under branch rules, and keep branch filters aligned with the default branch (currently `trunk`). GitHub may disable schedules in inactive public repositories.

### Troubleshooting

| Problem | Next step |
| --- | --- |
| Extraction fails | Inspect upstream markup or title collisions; update tests before changing parsing. |
| RSS rejected | Repair malformed XML or ambiguous GUIDs without discarding history. |
| Removal missing | Check the fifteen-minute publication window; late retries do not backfill it. |
| Website stale | Check both workflows, the data commit, and Pages deployment. |
| Push rejected | Check branch rules or branch advancement; rerun without force-pushing. |
