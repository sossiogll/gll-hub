# 4. A refused entry carries its reason, and the run says it refused

Date: 2026-11-09

Status: proposed

## Context

A sync that refuses an entry ends as if nothing happened. `EntryUpserter` skips an entry that breaks the contract's
rules and writes the reason in `SyncReport::$invalid`, but the report only reaches a log line. `InstanceHealth`
then sets the instance to `ok` with an empty `last_error`, and `gllhub:sync` prints `ok` and exits with success.

The packages make it worse. When `gllhub/github` cannot read a `showcase.md`, or `gllhub/json-resume` cannot read the
CV, they log the reason as a warning and return an entry with an empty title (`ProjectEntry::refused()`,
`ResumeEntry::refused()`). They do it on purpose: leaving the entry out would read as the project being gone, and
the core would remove it. The core then refuses that entry for its empty title. So the only reason the core could
show is "empty title", and the real one ("the front matter is not valid YAML") is in a log nobody reads.

With the GitHub Action that validated `showcase.md` dropped (plan, "Not doing"), the sync is the only place a mistake
in that file can surface. Today it surfaces nowhere: the old page stays online, which is right, and nobody is told,
which is not.

Constraints that are not negotiable:

- **The core does not know packages.** No reason is parsed or recognised by package; the core shows what it is given.
- **The contract only grows within a major.** A new field on a value object has a default; a new ability is a new
  optional interface with a `Capability` case. A package built on 1.0 keeps running unchanged.
- **Failures are typed and have stable codes.** The state of an instance is an enum, never inferred from a message.
- **Never "ok" with a hidden problem.** A run that refused something must not end in the same state as a clean one.
- **A refused entry keeps what is stored.** It is seen, so it is not removed, and it is not written.

## Options considered

### How the reason travels from the package

1. **A `refusal` field on `ExternalEntry` (recommended).** `public ?string $refusal = null`, the last constructor
   parameter. A source that cannot read an item returns its identity (`externalId`, `type`, `language`) and the
   reason. The core skips it with that reason and does not run the contract's rules on it, so "empty title" never
   appears. It is a field with a default: contract 1.1.0, nothing breaks.
2. **A new contract exception thrown from `pull()`.** Rejected: an exception ends the generator. One broken file
   would stop the sync of every other repository, and a listing that does not run to its end cannot prove absence,
   so removals would stop too.
3. **An optional capability, `ReportsRefusals`, read after the pull.** What D2 suggested. Rejected on reading the
   code: the source would have to keep state between `pull()` and a second call, while today a source is created
   per run and keeps none, and the refusals would be matched to entries by id after the fact. A capability is the
   rule for a new *ability*; a reason that belongs to one entry is data of that entry.
4. **A separate value object, `RefusedEntry`, yielded beside `ExternalEntry`.** Cleaner to read, but it widens the
   return type of `Source::pull()`, `SyncBatch::$entries` and `FetchesSingleEntry::fetch()`, which every consumer of
   the contract must then handle. That is a change to existing interfaces, not an addition.

### How the run says it

1. **A new status, `partial`: "ran, but refused some entries" (recommended).** `last_success_at` is set, because
   the run did finish; `last_error` holds a summary of the refused entries and their reasons; no retry is scheduled,
   because a mistake in content does not fix itself, and the next scheduled run looks again. The dashboard widget
   lists `partial` with the other states that need attention. `gllhub:sync` prints the summary and exits with
   a code of its own for "finished with refusals", distinct from success and from failure.
2. **Keep `ok`, and store refusals in a table of their own.** Rejected for now: a table, a migration and a screen,
   for what is a handful of lines on a personal site. The column can become a table later without changing the
   contract.
3. **Logs only, as today.** Rejected: it is the problem.

## Trade-offs

- A field on `ExternalEntry` means a refused entry is still an `ExternalEntry` with an empty title. The core must
  check `refusal` before the rules, in the one place entries enter the store (`EntryUpserter`), and the
  conformance check must accept such an entry in a package's samples.
- `last_error` is one text column. A summary that is too long is cut with an explicit count ("and 3 more"), never
  silently, and the full list stays in the log line of the run.
- `partial` is a new value of a stored enum: the admin, the widget and the scheduler must each be checked for how
  they treat an unknown state. The scheduler only excludes `failing`, so `partial` keeps being synced, which is
  what we want.

## Recommendation

Option 1 for the reason (a `refusal` field, contract 1.1.0) and option 1 for the run (status `partial`).

## Decision

To be taken by the user.

## Consequences

- A broken `showcase.md` shows, in the admin's `last_error`, the repository, the language and the parser's reason,
  and the old page stays online.
- `gllhub:sync` no longer says `ok` when it refused something. A script that runs it sees a code of its own,
  and can tell "finished with refusals" from a run that failed.
- The rules' own refusals (an entry a package built wrongly) and a package's refusals (a file it could not read)
  are told apart: the summary prefixes them with stable codes, `refused_by_source` and `refused_by_rules`.
- A package built on contract 1.0 keeps working and keeps sending empty titles. Its refusals still surface, as
  `refused_by_rules: empty title`, which is less precise but no longer hidden.

## Phased plan

1. **Core and contract**, one pull request into `dev`: `ExternalEntry::$refusal`, `Contracts::VERSION` 1.1.0, the
   conformance rule and its fixtures, `SyncStatus::Partial`, `SyncReport` summary with codes and a declared cap,
   `InstanceHealth::partial()`, the job choosing it, the widget, `gllhub:sync`'s output and exit code. Tests: a run
   with one refused entry ends `partial` with its reason; a clean run ends `ok`; a refused entry is neither written
   nor removed.
2. **`gllhub/github`**, a pull request in `gllhub-packages`: `ProjectEntry::refused()` takes the reason from
   `InvalidShowcase`; the package declares contract 1.1. Test: a broken front matter yields an entry whose
   `refusal` is the parser's message.
3. **`gllhub/json-resume`**, the same pull request: `ResumeEntry::refused()` takes the reason from `InvalidResume`.
4. **A real check** in the local stack: break `showcase.md` on a branch the source reads, sync, and see the reason
   in the admin while the page still answers.

The core's pull request is merged first; the packages' one declares the new minor and cannot run on an older core.

## Answers of the user (2026-11-09)

They answer the questions this proposal left open. They do not accept the ADR: the Decision above is still to be
taken.

- **Exit code of `gllhub:sync` on `partial`:** a code of its own for "finished with refusals", not the generic
  failure that was recommended.
- **The cap of the summary in `last_error`:** the first 5 refusals, then "and N more", as recommended.
- **Notifying on `partial`:** the admin's dashboard is enough for now.

## Open questions

- **`gllhub/medium`.** Its feed parser skips, without a word, an item with no `guid` or no `link`. That is a list
  cut in silence. With a `guid` the item has an identity, so it could be returned as a refused entry; without one it
  cannot be an entry at all, and only a log line can name it. Whether `medium` joins phase 2 is the user's call.
- **`gllhub/portfolio`** declares content types and reads nothing, so it has nothing to refuse.
