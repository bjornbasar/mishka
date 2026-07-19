# NOTES.md — mishka dev gotchas

Loose, dated notes for dev sessions. Not architecture (that's [DOCS.md](DOCS.md)) — just
things that have bitten us and how to avoid a repeat.

---

## PHPStan OOMs inside the dev container (`composer analyse`)

**TL;DR — before running `composer analyse` in `mishka-dev-app`, know that PHPStan's
memory budget (512M) is DOUBLE the container's memory cap (256m). A cold/full run will
get OOM-killed by the kernel, not by PHPStan.** Give it more room first (see fixes).

### The mismatch
- `composer analyse` → `phpstan analyse --memory-limit=512M` (see `composer.json` scripts).
- The dev container `mishka-dev-app` is capped at `mem_limit: 256m` (+ `memory-swap: 512m`)
  in the workspace compose file: `/data/personal/docker-compose.yml` (mishka-dev-app block,
  ~line 199).
- So PHPStan is *told* it may allocate up to 512M of PHP heap inside a 256 MiB box. Once its
  working set climbs past the cap, the **kernel cgroup OOM killer** kills the `php` (PHPStan)
  process. The resident `php -S` dev server (PID 1) is unaffected — the container does NOT
  restart and keeps serving — so it's easy to miss that anything died.

### How to tell which OOM you hit
- **`Killed` / exit code 137 / the command just dies with no PHPStan error** → **container
  cgroup OOM** (this note). The 256m cap is the ceiling, not `--memory-limit`. Fix = give the
  container more RAM.
- **`Fatal error: Allowed memory size of N bytes exhausted (PHPStan)`** → PHPStan's own
  `--memory-limit`. Fix = raise `--memory-limit`. (Different problem — don't confuse them.)

Worst case is hitting both: raise `--memory-limit` and the container just OOM-kills it sooner.

### Fixes (pick one)
1. **Recommended — bump the dev container cap** so 512M PHPStan actually fits:
   in `/data/personal/docker-compose.yml`, `mishka-dev-app`: `mem_limit: 640m` (was 256m).
   Ruxa has GBs free; 640m = 512M PHPStan + ~20M `php -S` + overhead. Prefer real RAM over
   leaning on the 256m swap slice — Ruxa's 4G swap is precious and swap-thrash has bitten us
   before. Then `docker compose --profile mishka up -d mishka-dev-app` to apply.
2. Run analyse **outside** the capped container (host / an ad-hoc `docker run` without the cap).
3. Keep `.phpstan-cache/` warm so incremental runs have a lower peak — but note a **cold**
   run (after bumping phpstan itself or dependencies → cache invalidated) will still spike and
   is exactly what bit us. Don't rely on this alone.

### What actually happened (2026-07-15, so this isn't hand-waved)
Single 40-second burst, `Jul 15 17:56:27–17:57:07 NZST`: 5 `php` processes OOM-killed in
`mishka-dev-app`'s cgroup (`constraint=CONSTRAINT_MEMCG` — the container's own limit, host was
fine with GBs free). It landed in the pre-push validation gap between the 15:05–15:09 dependency
bumps (which included **PHPStan 2.1.55 → 2.2.5**, invalidating its cache → full re-analysis) and
the 18:29 commit batch. Classic `composer analyse` cold run blowing past 256m. Zero service
impact — the dev server rode through it; only the interactive analyse command died.
