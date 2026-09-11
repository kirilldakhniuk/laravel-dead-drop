# Release Notes

## [Unreleased](https://github.com/kirilldakhniuk/dead-drop/compare/v0.1.0...1.x)

- Added `dead-drop:init` to introspect one or more database connections, infer their relationships (foreign keys, Eloquent `belongsTo` relations, and column-naming guesses), classify and scan their tables, and write a reviewed, human-editable `<connection>.php` config file per connection — merging a re-run into the existing file so a human's decisions are never discarded and a vanished table is marked `removed` rather than deleted.
- Added the per-connection config format: `class` (`data`/`lookup`/`skip`), `removed`, `window`, `exclude`, `morph`, `columns`, `references` (with `descend` and a `source` of `fk`/`eloquent`/`guessed`/`manual`), and `redact` (with `hash`/`mask`/`null`/`scramble`/`bcrypt:secret`/`fixed:redacted` suggestions and a `review` placeholder for columns that need a human decision).
- Added `dead-drop:check` to detect drift between a connection's live schema and its reviewed config — new or removed tables and columns, and sensitive columns with no redaction decision — for use as a non-interactive CI gate.
- Added a traversal planner (`dead-drop:dump --dry-run`) that expands a root row into its full referential closure — descending along configured references and polymorphic columns, then ascending to pull in every row a collected row points at — across multiple database connections and through Eloquent-style polymorphic pairs, and reports the resulting row counts, estimated size, and any references it could not resolve.
- Extraction (writing the planned rows out), `--full` dumps, redaction, and loading are not implemented yet.

## [v0.1.0](https://github.com/kirilldakhniuk/dead-drop/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
