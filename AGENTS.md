# Working on RichYet

This repository is the canonical application source for personal self-hosted installations. Keep changes small, free of new paid dependencies, and easy to use on phones.

- Ideas or discussion do not authorize implementation. Preserve unrelated changes.
- Read README.md, CONTRIBUTING.md, INSTALL.md and DATA_PROVIDERS.md before relevant work. Inspect Git branch, status and remote.
- Never commit credentials, personal holdings, databases, operational receipts or private deployment history. Use fictional test fixtures; keep configuration outside the repository and public directory.
- Keep exact listing/currency/share-class identities, source dates, split/correction guards and provider limits. Retain complete previously validated price/comparison sets through ordinary retrieval failures; never invent zero, fresh timestamps or a confirmation.
- Keep fetching, persistence, calculation and UI separate. Use current quantities/costs for all calculations.
- Run `npm run check` and `npm test` for relevant code changes. Tests are offline and need Node 20+ and PHP with PDO SQLite. Do not use real provider accounts for routine tests.
- Review all About text for every behavior change; update inaccurate explanations. Keep user-facing changelog entries to major visible changes.
- Review staged names and full diff before a public push. Keep historical releases immutable.
- Git publication, release packaging and deployment are separate actions. A push never authorizes deploying to an existing installation or touching a database. Follow that installation's private deployment instructions and take a recovery backup before storage changes.
- Preserve holdings, settings and provider allowances. Do not copy another installation's configuration or broaden access.

See CONTRIBUTING.md for the development, validation and release workflow.
