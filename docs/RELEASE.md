# Mishka Den — Release Checklist

Run through this list before tagging any `vX.Y.Z`. Most items are quick boolean checks; the **SW version bump** (v0.6.7+) is the one operationally-risky item.

## Pre-tag

- [ ] **`## Status` lead paragraph in `README.md` AND `SW_VERSION` in `public/service-worker.js` both bumped to the new release tag — BEFORE running `composer test`.** The `test_sw_version_matches_release` test compares the live README to the live SW source; bumping README after the test run hides the mismatch locally and CI fails after push. (v0.6.10 was the lesson — see DOCS.md decision #51.)
- [ ] All PHPUnit tests pass: `composer test` (target: 571/571 as of v0.6.7).
- [ ] PHPStan level 6 clean: `composer analyse` (run with `-d memory_limit=512M` to avoid parallel-worker OOM).
- [ ] `## Status` lead paragraph in `README.md` updated to the new version with a one-paragraph description.
- [ ] If the release adds a new design decision, append it to the `DOCS.md` numbered list (do NOT reuse a retired number; the next free is whatever follows the current max).
- [ ] If schema changed (PG ALTER block), document it in `docs/SCHEMA.md` and confirm `tests/bootstrap.php`'s strip pattern still catches it.
- [ ] **SW version bump — required on EVERY release, regardless of whether precached assets changed.** Bump `SW_VERSION` in `public/service-worker.js` to match the new release tag. `tests/View/ServiceWorkerStructureTest::test_sw_version_matches_release` enforces SW_VERSION-equals-README always; CI fails any release that ships with the two out of sync. (Earlier drafts of this checklist said "bump if assets changed" — that wording was looser than the test; v0.6.10 corrected it after v0.6.9 shipped with mismatching versions. See DOCS.md decision #51.)

  Particularly worth re-checking on releases that DO touch one of:
    - `templates/layout.twig` (HTML shape — the cached page shell)
    - any file path in `PRECACHE_URLS` inside `public/service-worker.js`
    - the HTML form-shape of any cached page route (hidden field added/removed/renamed)
    - the `OfflineController` output shape

  …because those releases actually invalidate cached content. A doc-only or backend-only release still requires the bump (the test demands it) but no client-side cache content actually changes.

  **Why this matters:** browsers detect SW updates via byte-comparison of `service-worker.js`. Without a bump on an asset-changing release, users keep the prior cache until the SW source happens to change for an unrelated reason — meaning a layout.twig change might not reach existing PWA installs for weeks. The version bump IS the deploy signal. Bumping on every release (asset-changing or not) is the cheap, conservative discipline that prevents the v0.6.9 mistake from recurring.

- [ ] If asset URLs are being added that introduce redirects (e.g. `.htaccess` rewrite `^/icon-192.png$ /icons/v2/192.png [R=301]`), audit `isCacheable()` — currently rejects all `response.redirected === true`, so a redirected asset would NEVER cache. Either drop the redirect or precache the post-redirect URL directly.
- [ ] If `docs/TESTPLAN.md`'s `(vX.Y.Z)` header is out of date, update.

## Tag + deploy

- [ ] Confirm `pwd` is `/data/personal/mishka` AND `git remote -v` lists `bjornbasar/mishka.git` (the v0.6.3 lesson — wrong-repo tag is easy to make).
- [ ] `git tag -a vX.Y.Z -m "..."` then `git push origin main && git push origin vX.Y.Z`.
- [ ] Cloudflare auto-purges on deploy — no manual purge needed.
- [ ] `gh release create vX.Y.Z --title "..." --notes "..."` with release notes.

## Post-deploy smoke

- [ ] Load the site in a new private window. DevTools → Application → Service Workers should show the bumped `mishka-vX.Y.Z` version activated. Cache Storage should show `mishka-cache-mishka-vX.Y.Z` populated with the 7 precache entries.
- [ ] If the release changed push or notification behaviour, watch worker logs for the first push tick: `docker logs mishka-worker`.
- [ ] If a documented manual TESTPLAN section gained cases, run them on a real device (Playwright can't grant push permission per TESTPLAN § 5.2).

## Repository-mistake recovery

If you tagged on the wrong repo (e.g. `/data/personal` instead of `/data/personal/mishka`):

```bash
# Delete the bad tag locally and remote (replace REPO and vX.Y.Z as needed)
cd /path/to/wrong/repo
git tag -d vX.Y.Z
git push origin :refs/tags/vX.Y.Z

# Then re-tag from the correct repo (verify pwd first)
cd /data/personal/mishka
git tag -a vX.Y.Z -m "..."
git push origin vX.Y.Z
```

Lesson from v0.6.3: always verify `pwd` and `git remote -v` BEFORE the `git tag` command.
