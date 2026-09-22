# glueful/thallo-importers

Content **format importers** for [Thallo](https://thallo.dev) — CSV, Markdown/MDX, and
WordPress (WXR) ingestion, plus CSV user provisioning — packaged as a **capability pack**. It
writes all content through Thallo's public `ContentWriter` contract and never reaches into the
application; an operator can switch it off without touching the core.

It is the reference pack of the [composable-core](../../docs/internal/superpowers/specs/2026-06-28-lemma-composable-core-design.md)
architecture: a library package that depends only on `glueful/thallo-contracts` (+ the framework
and `glueful/import-export`), declares a capability, and contributes a capability-gated admin
surface.

## What it provides

Five import adapters, registered with the `import_export.importer` container tag and discovered by
the `glueful/import-export` engine:

| Adapter key        | Label              | What it ingests |
|--------------------|--------------------|-----------------|
| `csv.content`      | CSV                | One content entry per CSV row; fields ↔ columns. |
| `markdown.content` | Markdown / MDX     | YAML front matter → fields; body → a chosen `text` field (raw vs HTML by the field's `format`). |
| `markdown.folder`  | Markdown folder (.zip) | A folder of Markdown as the pages of a content type — a documentation section. The import `thallo:import:markdown` runs, from an upload: repeatable, and it never deletes. See `docs/documentation-sites.md`. |
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
being stored. A `.zip` is unpacked by `Markdown\MarkdownZip`, which writes only Markdown files,
refuses an archive holding a name that points outside the import, and counts the bytes it really
reads against a cap. **User provisioning note:** imported accounts are stamped email-verified (bulk
provisioning by an admin who vouches for the addresses) — don't import unvetted address lists.

## The capability

The provider registers a single capability in `boot()`:

```php
new Capability('thallo.importers', label: 'Content importers', description: '…');
```

- **Follows its engine.** The capability's owning package is `glueful/import-export`. Left
  untouched, it is on whenever that extension is enabled, which it is in a new project's
  `config/extensions.php`. An operator turns it off or on in the admin under **Extensions ›
  Capabilities**; the switch is stored system-wide and overrides the deploy-time
  `thallo.capabilities` config map. Enabling is refused while `glueful/import-export` is not
  enabled and schema-ready.
- **Backend-gated, not just UI.** Every adapter calls `assertImportersEnabled()` (the
  `RequiresImportersCapability` trait) as the first line of its plan step — so a direct
  `POST /import-export/imports` for a Thallo adapter **fails closed** when the capability is disabled,
  not only the admin controls.
- **UI-gated.** The admin's format-import controls (Settings → Import / Export) and the users
  bulk-CSV-import are shown only when `thallo.importers` is enabled (via the admin capabilities store).

## Boundary

This package depends on `glueful/thallo-contracts`, `glueful/framework`, `glueful/import-export`,
`glueful/users`, `glueful/aegis`, and `league/commonmark` — and **never** on `glueful/thallo` (the
application). The repo's `composer boundaries` check enforces this at both the Composer-dependency
and the source level (no `Thallo\Core\` references in `src/` or `routes/`).

## Install

The pack ships with Thallo: `glueful/thallo-core` requires it at the same version and the project's
`config/serviceproviders.php` loads its provider, so there is nothing to install or enable per pack.

When the capability is off:

- Content delivery and the admin work unchanged.
- **Snapshot export/import still works** — the full-database NDJSON snapshot engine
  (`ContentExporter` / `ContentImporter`), its `/v1/admin/import-export/upload` and
  `/v1/admin/import-export/jobs/{uuid}/download` endpoints, and the snapshot UI are **core-owned**, not part of this pack.
- The `thallo.importers` capability drops out of `GET /v1/admin/capabilities`, so the format-import
  admin section and the users bulk-CSV import hide.

## Not included (deliberately)

Snapshot/backup **restore** (raw NDJSON of Thallo's own tables, versions, routes, publications, and
blob manifest) stays in core — it necessarily understands Thallo's internal storage model, so it is
not "import through the public content API" and is not exposed through `thallo-contracts`.

## Contributing

This repository is a read-only mirror, published from
[glueful/thallo](https://github.com/glueful/thallo) on every release; its `main` is overwritten
by the next split, so nothing can land here. Issues and pull requests belong in glueful/thallo,
where this code lives at `packages/thallo-importers/`.
