# 3. The read API serves any declared type

Date: 2026-11-05

Status: accepted

## Context

A site needs its content over HTTP. ADR 0001 says the core does not know the shape of that site, nor the types of a
package. The contract of the API is written by its consumer, `docs/api-v2.openapi.yaml` in the site's repository, which
names each type only to give it a precise schema.

## Decision

There is one route per shape, `/api/v2/entries/{type}` and `/api/v2/entries/{type}/{slug}`, driven by what packages
declare. A list and a detail are the same presenter, and what a field looks like follows its `FieldType`, at any depth.

- **A language is declared.** `GLLHUB_LANGUAGES` and `GLLHUB_DEFAULT_LANGUAGE` are required. Without them the API
  answers 503 rather than guess from the content, because the fallback of every read depends on them.
- **One row stands for each piece of content**: the language asked for, then the main one, then a fixed order. It is
  one window function, `TranslationPreference`, for lists, details and references alike.
- **A reference is read through its translation key**, not through the row it was first resolved to. Nothing is written
  on a read, so a link resolved before a translation arrived still shows the translation.
- **Text points at the copy of an image once it is imported**, and at the source until then. It is done on the way out:
  stored text stays as the source wrote it.
- **Empty values sort last in both directions**, so `position` means "these first, the others after".
- **A refusal is a stable code.** Whatever was not foreseen is `unavailable`, with no detail of the inside, and is still
  reported.

Serving each type with its own controller was the other option. It gives nicer code to read and breaks the golden rule.

## Consequences

- A package that adds a type adds it to the API, with no change here.
- A site that wants something shaped like a page computes it from these answers. A field that a page needs goes on the
  type, and the API serves it by itself.
- A list reads each row's `fields` to shape them, so a type with heavy fields makes heavy pages even without their
  Markdown. `fields` narrows what is served, not what is read. It is fast on today's few hundred rows, and it would be
  the first thing to look at if that changes.
