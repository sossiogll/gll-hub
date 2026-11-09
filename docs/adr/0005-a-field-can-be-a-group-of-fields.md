# 5. A field can be a group of fields

Date: 2026-11-09

Status: accepted (2026-11-09)

## Context

The site's CV draws a graph like a git log. The site's ADR 0003 (sossiogll.com, accepted on 2026-11-09) decided that the
graph is declared in the JSON Resume file, as one key holding two lists:

```json
"timeline": {
  "branches": [{ "name": "high-school", "from": "master", "into": "master", "start": "2010-09", "end": "2015-07" }],
  "rows": [{ "date": "2015-07", "message": "Merge remote branch 'origin/master'" }]
}
```

and that gllHub serves it with the same shape, as `fields.timeline`.

A content type cannot declare that today. `FieldType` has scalars, `List` (a list of small objects shaped by
`Field::$of`), `Reference` and `Media`, but nothing for **one** object made of named fields. Asked on 2026-11-09 how
gllHub should serve the graph, Sossio chose a new field type in the core over two separate lists.

Constraints that are not negotiable:

- **The core does not know packages or types.** The new type is generic: validated, stored, served and drawn in the
  admin from what a package declares, as `List` is.
- **The contract only grows within a major.** A package built on 1.0 or 1.1 keeps running unchanged.
- **Declared, never inferred.** Every key of the object is a declared field, checked at every depth like the items of a
  `List`. No free-form JSON.
- **One implementation per concept.** What a `List` checks on each item is what a group checks on its one value.

## Options considered

1. **`FieldType::Group` (recommended).** One object whose keys are the fields in `Field::$of`, the same property a
   `List` uses for its items. It can hold any field, a `List` or another `Group` included.
2. **Two lists on the type** (`timeline_branches`, `timeline_rows`). No core change. Rejected by Sossio on 2026-11-09:
   the file and the API would no longer say the same thing.
3. **A `List` that must hold one item.** No new type, but the schema lies about the shape, the API serves an array of
   one, and the admin shows a list where items can be added. Rejected.
4. **A free-form JSON type.** Nothing would be checked, and the shape would be inferred from what arrives, not declared.
   Rejected.

## The shape

In the contract:

```php
new Field('timeline', 'Graph', FieldType::Group, of: [
    new Field('branches', 'Branches', FieldType::List, of: [/* name, from, into, start, end */]),
    new Field('rows', 'Rows', FieldType::List, of: [/* date, branch, role, message */]),
]);
```

- **Value:** an object with named values (`array<string, mixed>`), or `null` for no value. An empty object counts as
  no value.
- **Rules (`FieldRules`):** a key not in `of` is a violation, and each value is checked against its own field. A required
  inner field that is missing is a violation, as for the items of a list. The check of one item of a list and the check
  of a group are one function.
- **Required:** a required group with no value is "missing", as any other field.
- **Conformance:** a group with no inner fields fails, and so does a group marked `multiple`, because a list of groups
  is a `List`. Its inner fields are checked like a list's: keys, duplicates, enums without options.
- **Served:** `FieldValue::shape` shapes each inner value by its own field, so dates, Markdown and lists inside a group
  behave as they do anywhere. Empty values are left out.
- **Sorting:** a group cannot sort a list, as a `List` cannot.
- **Admin:** a set of fields under the group's label, built from `of` with the same factory as the other fields. When
  a source owns the group, every inner field is read-only.
- **Version:** `gllhub/contracts` 1.2.0. A package that declares a group declares `contracts: '1.2'`, and a core that
  speaks 1.1 refuses it, as it already does for a newer minor.

## Trade-offs

- One more type for the core to handle, in validation, values, admin and sorting. The whole cost is in the places that
  already handle `List`.
- A new case of the `FieldType` enum. Packages declare types and never match on them, so none breaks. The core is
  updated in the same pull request as the contract.
- No index or filter on a value inside a group. None exists inside a `List` either, and the site does not ask for one.

## Recommendation

Option 1.

## Decision

Option 1, `FieldType::Group`, with the shape above: accepted by Sossio on 2026-11-09 ("Yes, I accept"). The name
stays `Group`.

## Consequences

- `gllhub/contracts` 1.2.0: `FieldType::Group`, rules in `FieldRules`, checks in `Conformance` with a good and a bad
  sample in its fixtures.
- Core: values, sorting, admin form and form values handle `Group`. Tests for each.
- `gllhub/portfolio`: the `resume` type declares `id` on `work` and `education` items and the `timeline` group (the
  site's ADR 0003). `gllhub/json-resume` reads them and checks the references, refusing the file with its reason.

## Plan

1. Sossio accepts this ADR (done, 2026-11-09).
2. gll-hub, one pull request to `dev`: contract 1.2.0 and the core.
3. gllhub-packages, one pull request after it: the `resume` type and `json-resume`, declaring contracts 1.2. Merged
   after the core, since a core on 1.1 would refuse it.
4. Check on the local gllHub: the CV syncs, the graph is served, the admin shows it read-only.

## Open questions

None. The name was the only one: accepted as `Group`, the name it was proposed with.
