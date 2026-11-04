# gllHub — Architecture & Development Guide

## Philosophy

gllHub is a content hub: it keeps what has no other home, brings in what lives elsewhere (GitHub, Medium…), curates
it and serves it. Everything it talks to, and every kind of content it holds, comes from a **package**. The core
knows none of them by name.

## Where things live

- **This repository** is the core (being rebuilt on the `next` branch; `master` still runs laravel-blog for
  b5srl.eu) and **`contracts/`**, the `gllhub/contracts` package: the only thing a package depends on. Core and
  contract change together, in the same pull request.
- **[`sossiogll/gllhub-packages`](https://github.com/sossiogll/gllhub-packages)** holds the packages (types,
  sources, webhooks, logins). No package code ever lives here.

## The Golden Rule: the core never names a package

No package lives in the core, and no package name, content type key or integration appears in the core's code.

- No `if ($source === 'github')`, no list of known integrations or types in defaults or constants.
- No package-specific screens, labels or instructions in the admin: it is drawn from what packages declare.
- A package depends on `gllhub/contracts` (and, for the types it produces, on the package that defines them). It
  never depends on the core.

**A site is not a package either.** Its URLs, sections, components and design live in its frontend; the core and the
contract never carry them ([ADR 0001](docs/adr/0001-where-the-shape-of-a-site-lives.md)).

**A new ability** is a new optional interface in `gllhub/contracts` plus a `Capability` case, declared by the classes
that have it. Never a method added to an existing interface, never an `instanceof` on a concrete package class.

## The boundary: packages return data, the core decides

- A package reaches the core ONLY through its `Context`: settings, credentials, `http()`, logger, clock, public URL.
  It never touches the database, queues, cache, filesystem or environment.
- **Policy lives in the core.** A source returns entries; the core matches, creates, updates, keeps curated fields,
  downloads media, links references, removes what is gone. A webhook says what changed; the core decides when to
  sync. A login provider returns an identity; the core decides whether an account may be created.
- **Declared, never inferred.** What a class is and can do is read from its static `definition()`. The conformance
  check fails when a declaration and an implementation disagree.
- **Failures have a class, not a message.** A package throws the exceptions of the contract; the core's HTTP client
  already throws them for network errors, 5xx, 429, 401 and 403. Anything else is a bug of the package.

## Writing code

- **One implementation per concept.** Before writing a function, look for it. A rule two packages need goes in
  `gllhub/contracts` (`Support\`, `Testing\`) when it is pure, in the core when it touches infrastructure. Two
  pieces of code that do the same thing are a bug, even when they are short.
- **Small objects with one job, reusable as they are.** A parser parses, a mapper maps, a source orchestrates
  them. Value objects are `final readonly`; behaviour that needs no state is a static method on a `final` class.
- **Names say what a thing is in the domain**, not how it is built (`ArticleEntry::from()`, not `Helper::convert()`).
- **Moving or renaming** a class or function: migrate every caller (code, tests, docs) and delete the old one.
  Never leave an alias or a re-export at the old path.
- **Layering is one-way and tested.** In the core: HTTP and console → application services → domain → persistence.
  The domain never imports persistence or HTTP. An architecture test fails on a violation.
- **Reading never writes.** No GET touches the database. Syncs and other writes run as jobs.
- **Writers race.** A rule a unique index can express is an upsert, never "look, then insert".
- **Time is UTC** everywhere it is stored or exchanged, with an explicit offset.
- **No comment says what the code says.** A comment says why, or what a reader cannot see.

## Tests

- Every package runs `Conformance::check()` on itself and `Conformance::checkEntries()` on what its sources produce.
- Packages are tested with `Testing\StaticContext` and `Testing\FakeHttpClient`, never by reaching into the core.
- A new generic rule goes in `Testing\Conformance`, with a good and a bad sample in its fixtures.
- A bug fixed is a test added.

## Versions

- `gllhub/contracts` follows semver strictly: within a major only new optional interfaces and new fields with
  defaults. A package declares the version it was built on; the core refuses another major or a newer minor.
- Releases come from pull request labels (`breaking`, `feature`, `fix`, `chore`); the git tag is the only place a
  version is written.
- Decisions that shape the code are recorded as ADRs in `docs/adr/`.
