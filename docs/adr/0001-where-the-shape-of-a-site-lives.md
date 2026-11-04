# 1. Where the shape of a site lives

Date: 2026-11-04

Status: accepted

## Context

gllHub feeds a personal site, and it is easy to let that site leak into it. The contract had grown five options on
`ContentTypeDefinition` (`routePrefix`, `commentable`, `likeable`, `inFeed`, `notifiesSubscribers`) that nothing in
the core read: one carried the URLs of the site, the others announced features the core does not have. The golden
rule test looked for integrations and packages, not for content types, so a `ProjectController` in the core would
have passed it. With an API and a frontend about to be built, this is the moment to say where each thing belongs.

## Decision

Three layers, with dependencies in one direction only.

| Layer | Holds | Example |
| --- | --- | --- |
| Core and contract | What holds for any content: entries, fields, sync, curation, media, serving them by type | an API that serves any declared type |
| Packages | What a kind of content is made of and where it comes from | the fields of a project, a source that reads repositories |
| The frontend of a site | How content is shown | URLs, home page sections, tabs, components, progress bars, design tokens |

- The core stores and serves a package's fields without interpreting them. A field a site uses only for display (a
  header colour, the choice of a demo component) may live in a package's content type, because to the core it is
  opaque data. Splitting such a type into content and presentation would be an abstraction with a single user.
- The contract carries no option for a feature the core does not have. An option is added, with a default, in the
  pull request that makes the core read it. Adding later never breaks a package; removing later would.
- The options above were removed while the contract was still unreleased, so its version did not change.
- The golden rule test also forbids the content types of packages and the name of the site, and scans the contract.
  It leaves out `page`, a type too but too common a word.

## Consequences

- The API serves entries by type, driven by what packages declare. Anything shaped like one site's page is computed
  in that site's frontend.
- Comments, likes, feeds and newsletters come back to the contract, if they come back, together with their
  implementation in the core.
- An API that must match another site's existing shape has no obvious home in the core. Where it goes has to be
  decided before it is built, not while porting it.
