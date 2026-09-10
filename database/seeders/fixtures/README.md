# GitHub repos snapshot fixture

`GitHubReposSnapshotSeeder` reads `github-repos-snapshot.json` from this
directory (not committed by default — see below) and feeds it into
`SaveReposDataService`, as a stand-in for a live GitHub API sync in
environments that can't reach it (e.g. a Claude Code Remote sandbox, whose
network policy only allows GitHub API calls scoped to repos already
attached to that session) or before `GITHUB_TOKEN`/GCP is set up.

## Generating it

Run this somewhere with real GitHub API access (your own machine, or the
deployed app), then commit the resulting file:

```
php artisan github:snapshot-repos
```

## Shape

See `github-repos-snapshot.example.json` in this directory for the expected
structure — `captured_at` (ISO 8601) plus a `repos` array shaped exactly
like `GitService::get_repos()`'s return value.

## Staleness

This is a point-in-time snapshot, not live data. It will drift from your
actual GitHub repos (new repos, changed topics, etc.) until someone reruns
`github:snapshot-repos`. Fine for previews/demos; never treat it as the
source of truth for "what does the site currently show."
