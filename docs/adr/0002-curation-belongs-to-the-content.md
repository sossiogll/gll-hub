# 2. Curation belongs to the content, not to a language

Date: 2026-11-05

Status: accepted

## Context

Each language of a piece of content is its own row, with its own `curated` values. So a project could be featured in
English and not in Italian, and a list would change order between two languages of the same site. Only a text such
as a short claim for cards really differs by language. `Field::$translatable` was there to say so, and nothing in
the core read it: ADR 0001 says the contract does not carry options the core does not use.

## Decision

A curated value is shared by the rows with the same type and `translation_key`, unless its field is `translatable`.

- Each row keeps its copy, and `SharedCuration` keeps the copies equal: on every edit by a person, and when a
  translation arrives after the others. Entries read alone, with no join and no special case in a query that sorts.
- A curated Reference is shared in the same way.
- Files of a curated Media field are not copied: they live in each entry's media library, and a copy of a file is
  not worth the storage until a type needs it.

Moving shared values to a table of their own was the other option. It says the same thing with one more table and a
join in every read, for a site with a few hundred rows.

## Consequences

- `translation_key` is global within a type, as the contract says: a source must make it unique beyond itself.
- Writing `curated` directly, past `EntryEditor`, breaks the rule. `EntryEditor` is the only way a person writes.
- `translatable` now means something, for fields a source owns too: a package may refuse a translation that changes
  a field that is not translatable.
