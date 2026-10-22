# Developing RichYet

This is the main application repository. Hosted personal installations and new self-hosted installations use the same application source, with their own configuration, databases, provider accounts and deployment procedures. Implement a feature once here; no second application repository needs synchronizing.

## Local development

Use Node.js 20+ and PHP with PDO SQLite for tests. Production still uses MySQL/MariaDB as described in INSTALL.md. No npm dependencies or paid service are needed.

```sh
git switch main
git pull --ff-only
git switch -c codex/short-description
npm run check
npm test
npm start
```

The preview at `http://127.0.0.1:4017/?demo=1` uses fictional data. It does not connect to your installation. If PHP is outside PATH, run `PHP_BINARY=/absolute/path/to/php npm test`. The runner refuses to report success without PHP/SQLite and runs the cross-language retention checks as well as JavaScript and backend fixtures. No provider requests are needed.

## Review and publish

Review staged filenames and the complete diff before committing. Only reusable code, instructions and fictional fixtures belong here. Keep passwords, API keys, configuration, databases, backups and deployment evidence outside this repository. Ignoring a file does not make it safe if it was already tracked.

Run the relevant checks, then push the feature branch and review it before merging to main. Small owner-reviewed changes can be fast-forwarded locally. Verify that the remote commit matches. Main is the development source; use a reviewed release tag for an installation. Do not automatically deploy after a push.

For a new release, update the version and instructions as needed, test a clean checkout and tag the exact reviewed commit. A source archive can be made with `git archive --format=zip --output=richyet-VERSION.zip TAG`; write it outside the checkout, inspect it and test a fresh extraction before attaching it to a release. Never zip the whole working folder or include `.git` or untracked local files.

The original v0.1.0 release retains its initial export manifest and download unchanged. That one-time `SELF_HOST_MANIFEST.json` is no longer maintained in the development tree: Git commits and release tags identify subsequent source versions.

## Deployment is a separate step

Follow INSTALL.md for a fresh installation. An existing installation needs its own reviewed update procedure: preserve its configuration, database, provider budgets and schedules; record the old/new source commits and deployed-file hashes; keep source-only rollback available. Never rerun initial setup over an existing portfolio. Take and verify a recovery backup before changes to storage. Validate the candidate first, deploy only when requested, then check actual delivery.

The application tests do not establish hosting compatibility, permanent provider availability or rights to redistribute market data. A source-only workflow change does not require contacting providers or changing a live app.
