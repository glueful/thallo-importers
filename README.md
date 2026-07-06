# glueful/lemma-importers

Content **format importers** for [Lemma](https://getlemma.dev) — CSV, Markdown/MDX, and
WordPress (WXR) ingestion, plus CSV user provisioning — packaged as a **removable capability
pack**. It writes all content through Lemma's public `ContentWriter` contract and never reaches
into the application; install it, disable it, or `composer remove` it without touching the core.

It is the reference pack of the [composable-core](../../docs/superpowers/specs/2026-06-28-lemma-composable-core-design.md)
architecture: a real `glueful-extension` that depends only on `glueful/lemma-contracts` (+ the
framework and `glueful/import-export`), declares a capability, and contributes a capability-gated
admin surface.

## What it provides

Four import adapters, registered with the `import_export.importer` container tag and discovered by
the `glueful/import-export` engine:

| Adapter key        | Label              | What it ingests |
|--------------------|--------------------|-----------------|
| `csv.content`      | CSV                | One content entry per CSV row; fields ↔ columns. |
| `markdown.content` | Markdown / MDX     | YAML front matter → fields; body → a chosen `text` field (raw vs HTML by the field's `format`). |
| `wordpress.content`| WordPress (WXR)    | Posts/pages from a WXR export; title/excerpt/slug/date/status/author + content. |
| `csv.users`        | Users (CSV)        | Bulk user provisioning (profile + roles) via `glueful/users` + `glueful/aegis`. |

The content adapters resolve the target content type and its schema through `ContentTypeReader`,
map and coerce each row, then write via `ContentWriter` (`validate()` for dry-run previews,
`createDraft()` + optional `publish()` on commit). Validation failures surface as the contract
`ValidationFailed` exception — so the pack carries **no** reference to the engine. Mappings and
`body_field` are validated against the target schema at **plan** time, so a typo'd field fails
fast instead of silently importing entries with missing data.

**Imported files are treated as untrusted.** Markdown bodies are rendered with raw HTML stripped
and unsafe link schemes dropped; WordPress HTML bodies are run through `symfony/html-sanitizer`
(safe elements only — scripts, iframes, event handlers, and `javascript:` URLs are removed) before
being stored. **User provisioning note:** imported accounts are stamped email-verified (bulk
provisioning by an admin who vouches for the addresses) — don't import unvetted address lists.

## The capability

The provider registers a single capability in `boot()`:

```php
new Capability('lemma.importers', label: 'Content importers', description: '…');
```

- **Enabled by default.** Disable it by setting `'lemma.importers' => false` in `config/lemma.php`'s
  `capabilities` switchboard.
- **Backend-gated, not just UI.** Every adapter calls `assertImportersEnabled()` (the
  `RequiresImportersCapability` trait) as the first line of its plan step — so a direct
  `POST /import-export/imports` for a Lemma adapter **fails closed** when the capability is disabled,
  not only the admin controls.
- **UI-gated.** The admin's format-import controls (Settings → Import / Export) and the users
  bulk-CSV-import are shown only when `lemma.importers` is enabled (via the admin capabilities store).

## Boundary

This package depends on `glueful/lemma-contracts`, `glueful/framework`, `glueful/import-export`,
`glueful/users`, `glueful/aegis`, and `league/commonmark` — and **never** on `glueful/lemma` (the
application). The repo's `composer boundaries` check enforces this at both the Composer-dependency
and the source level (no `App\` references in `src/`).

## Install

The pack is **bundled by default** in the Lemma create-project template, so a fresh app has it
already. To add it to an existing app (it lives as a path package in this monorepo):

1. `composer require glueful/lemma-importers`
2. `./lemma extensions:enable lemma-importers` (writes the provider into the
   `config/extensions.php` allow-list and recompiles the extension cache)

## Remove

`./lemma extensions:disable lemma-importers`, then `composer remove glueful/lemma-importers`. After
removal:

- The headless CMS core boots; content delivery and the admin work unchanged.
- **Snapshot export/import still works** — the full-database NDJSON snapshot engine
  (`LemmaContentExporter` / `LemmaContentImporter`), its `/v1/admin/import-export/upload|download`
  endpoints, and the snapshot UI are **core-owned**, not part of this pack.
- The `lemma.importers` capability disappears from `GET /v1/admin/capabilities`, so the format-import
  admin section and the users bulk-CSV import hide automatically.


## Not included (deliberately)

Snapshot/backup **restore** (raw NDJSON of Lemma's own tables, versions, routes, publications, and
blob manifest) stays in core — it necessarily understands Lemma's internal storage model, so it is
not "import through the public content API" and is not exposed through `lemma-contracts`.
