# Plan: closing gllHub, and gllHub in its own showcase

State on 2026-11-05. This file is the source of truth for the remaining work, and it is updated while working, not at
the end. Decisions are in `docs/adr/`, rules in `CLAUDE.md`, how things work in the READMEs.

The system works and can be tested end to end. **On 2026-11-05 the user stopped new refinements until this plan is
approved:** nothing below starts without their go-ahead.

- Part A closes gllHub: from "works locally" to "running in production, with its loose ends decided".
- Part B puts gllHub in the showcase of the site, as was done for Musizeer. It changes repositories on GitHub, not code,
  except for the release automation.

## Where things stand

### Done and merged

| What | Pull request | Merge commit | Date |
| --- | --- | --- | --- |
| The site out of the contract, ADR 0001 | gll-hub#1, gllhub-packages#1 | `a2df6bf`, `12c2dad` | 2026-11-04 |
| Reference slugs normalised; `gllhub/github` | gll-hub#2, gllhub-packages#2 | `fa47bb8`, `6a97383` | 2026-11-04 |
| Shared curation (ADR 0002), stale files, PHP 8.4, widgets, translation rules | gll-hub#3, gllhub-packages#3 | `485d6da`, `59caa70` | 2026-11-05 |
| Milestone items, docs, discussions, boards | gll-hub#4, gllhub-packages#4 | `ffce1d0`, `83e838c` | 2026-11-05 |
| API v2 (ADR 0003); CV type and `gllhub/json-resume` | gll-hub#5, gllhub-packages#5 | `43f0151`, `3fe693c` | 2026-11-05 |
| Already encoded relative links fixed; CI for the packages | gllhub-packages#6 | `d0a5886` | 2026-11-05 |
| Integration branch renamed from `next` to `dev`, image `:dev` | gll-hub#7 | `211c11a` | 2026-11-05 |
| This plan | gll-hub#6 | `ba8438c` | 2026-11-05 |

### Open

No pull request is open on `gll-hub` or `gllhub-packages`, except the one that carries this update.

### Verified on 2026-11-05

- Suites: core 241 tests, contract 16, packages 135.
- CI: on `dev`, `CI` and `images` passed on `211c11a`, and the image `:dev` is in the registry. Nobody has run it. CI for
  the packages runs on pull requests and on `master`.
- A real sync of `sossiogll/musizeer` at `v2.0.4` in the local stack on MariaDB: the showcase file, the three images copied
  and served, milestones with items, one board.
- Those answers validated against the site's `docs/api-v2.openapi.yaml` before the release and after it, and by the site's
  own check of contract 0.2.1. `/entries/resume/cv` read from the real `sossiogll/curriculum.json` and answering, without
  contact details.

### Never verified

- A real webhook from GitHub: the local stack cannot be reached from outside.
- Real discussions: Musizeer has none. Only tests cover them.
- A relative image in a real `docs/` page: Musizeer's docs have none, so the site's report of them not showing (item A2.4)
  is checked only by a test.
- A production deployment of this version. CI builds the images; nobody has run them.
- Rate limiting and failures on MariaDB rather than in tests, and two people editing at the same moment.

## Part A: closing gllHub

Each phase ends in one pull request or less. The phases can be reordered, except that A3 needs A1.

### A1. Say when a sync refused something (needed)

**Why:** a refused entry leaves the instance `ok` with an empty `last_error`, and `gllhub:sync` prints `ok`. The reason
reaches only the logs. So a mistake in a `showcase.md` leaves the old page online and nobody is told. With the validation
action dropped, this is the only place such a mistake can surface.

- In the core: a state for "ran, but refused some entries"; the refused entries and their reasons shown in the admin's
  existing `last_error` column; the same summary from `gllhub:sync`.
- In `gllhub/github` and `gllhub/json-resume`: an unreadable file today reaches the core as an empty title, so the admin
  would say "empty title". The reason must travel through the contract. **It touches the contract, so it starts as an
  ADR**, and it needs a decision on its shape (D2).

Done when: a broken `showcase.md` in a real sync shows its reason in the admin, and the old page stays online.

### A2. The site's open requests

From the site's `docs/backend-requests.md`, 2026-11-05.

1. **Rate limit.** The site calls from one address and the home page makes 6 requests per visit, against a limit of 300 a
   minute per address: about 50 visits a minute. Decide in A3, with real traffic in mind: raise it, or exempt the site's
   address (D3).
2. **The canonical of a project.** `ProjectEntry` sets it to the repository, which tells search engines the page is a copy
   of the repo. The site already ignores it. Recommended: send none, because `repository` has the link (D4).
3. **`/entries/page/about` answers 404.** This is missing content, not missing code: either a page written in the admin, or
   a source for the profile README (D5).
4. **Images in `docs[]`.** The site reports that relative ones do not show. The package already makes them absolute, and a
   test covers it. Check on a real page, and answer the site.
5. **The Docs tab weighs 451 KB per opening.** Do nothing yet: measure in production first. A route for one item of a list
   would be the first in the API that is not generic, so it needs a real number (D6).

### A3. Deployment

The compose file and the image exist, and CI builds the image. What is missing:

- **Decide** host, public address of the API and of the admin (D7). The contract says `https://api.sossiogll.com`, the
  site's `GLLHUB_API_URL` must end in `/api/v2`, and the site's plan mentions a home server.
- **The image to run.** `images.yml` publishes `:latest` from `master`, which is still the old application, and the compose
  file defaults to `latest`. Until A5 is settled, production must pin a version: `:dev` or a tag.
- **An instance image with the packages**, as in the README: `composer.local.json` with `github`, `medium`, `portfolio` and
  `json-resume`, which today are not published anywhere it can install them from (D8).
- **Configuration:** `APP_URL`, `TRUSTED_PROXIES` (the rate limit counts by address, so a wrong one puts every visitor in
  one bucket), `GLLHUB_LANGUAGES`, `GLLHUB_DEFAULT_LANGUAGE`, media storage.
- **Backups** before the first migration. `config/backup.php` exists; whether it runs and restores has not been checked.
- **Sources created by hand in the admin:** GitHub with a token with `read:project`, Medium, the CV.
- **The webhook** on Musizeer, with its secret. This closes "a real webhook" above.
- **Sync interval.** Project boards send no webhook, so "Right now" is as fresh as the sync: every 6 hours by default,
  about 35 calls to GitHub per sync with today's content. Decide on the interval (D9).

Done when: the site reads the public address, a push on Musizeer refreshes its page, and a restore has been tried.

### A4. Housekeeping

No decisions needed, small, can go in one pull request.

- Update the Symfony packages with advisories: 11 advisories on 6 packages at the last audit, `symfony/html-sanitizer`
  among them, which cleans bodies. Read them first and say which ones touch gllHub. It may be worth doing before A3.
- Remove what the framework left: the `/api/user` route, the `welcome` view, `tests/Unit/ExampleTest`. Check first that
  nothing relies on them.
- `gllhub-packages`: no description and no license on the repository, while each package declares MIT.
- A 403 from GitHub for its rate limit is treated as refused credentials, and the source stops instead of retrying. Unlikely
  with a token and few repositories. The core's HTTP client is generic, so the fix is a `Retry-After` check there.
- The site's request list and this plan agree: tell the site chat what A2 decided.

### A5. `master` and b5srl.eu (decide, do not start)

`master` is the old application (Laravel 8, PHP 7.4, last commit 2022-10-31) and serves b5srl.eu. The port of its functions
and of an identical API v1 is still pending from the original brief.

**Recommendation:** leave it out of closing gllHub, because nothing on sossiogll.com depends on it. Start with a read-only
inventory of what b5srl.eu really uses, because "identical API v1" would bring another site's shape into a core that ADR 0001
keeps free of it, and where it goes has to be decided first (D10). Until then, `master` stays frozen, and nothing merges into
it.

### A6. The full CV (approved 2026-11-05, in progress)

The site asked for nested fields on the `resume` type. Answer given the same day: lists inside lists work at any depth,
and `required`, enums and undeclared keys are checked at every depth during a sync. There is no type for a group of fixed
keys, so the levels of a language are four flat fields of the item.

- `resume`: `work[].employment_type`, `work[].skills[]`, `education[].skills[]`, `languages[]` with `reading`, `writing`,
  `listening` and `speaking`, `interests[]`. `skills` and `interests` share one shape, `{ name, keywords }`.
- `gllhub/json-resume` reads them, the keys outside the JSON Resume schema included (`employmentType`, `skills`, `levels`).
- Pull request: gllhub-packages#7. Checked through the real upserter and API on the local stack.
- Still to do, with the user: add the new keys to `sossiogll/curriculum.json`. Their values are the user's to give; the
  file only gains `languages` today. The site updates its contract.

### Definition of closed

gllHub is closed when A1 to A4 are done, the site runs on the public API, and D10 has an answer, even if the answer is "later".

## Part B: gllHub in the showcase

The same steps as Musizeer. What differs is that gllHub's repository is not ready to be read yet:

| | Musizeer | `sossiogll/gll-hub` today |
| --- | --- | --- |
| Default branch | `master`, the current code | `master`, the old application; the code is on `dev` |
| Releases | automatic, from labels | none, and no automation; old tags `1.1.0`, `2.0`, `2.1`, `v1.0.0` |
| Description | current | "Laravel 8.0 blog application…" |
| Issues, discussions | on | both off |
| Milestones | automatic | none |
| Project | number 3, public | none |

**The topic goes last.** The source reads the latest release, or the default branch without one. With the topic today, the
site would show the description and README of a Laravel 8 blog.

### B1. Decide what gets read (D11)

| Option | For | Against |
| --- | --- | --- |
| **`dev` as default branch, and releases from `dev` (recommended)** | No commit on `master`. The GitHub page and the site tell the same story. Reversible | Must first check that b5srl.eu's deployment names `master` rather than following the default |
| Releases from `dev`, default stays `master` | Touches nothing about b5srl.eu | Whoever opens the repository sees the old README |
| A new repository for the core | Clean, not a fork | Loses history, pull requests and links; the name was just settled |

The repository also shows as a fork, which the source accepts. Detaching it is cosmetic, and only GitHub support can do it (D12).

### B2. Releases and milestones

Bring over from Musizeer what applies: `labels.yml`, `milestones.yml`, `scripts/release_milestones.py`, and the release step
of `images.yml`. It needs adapting, not copying, because the branches differ: Musizeer releases on a merge from `dev` into
`master`, and here `master` is frozen. So:

- Create the labels `breaking`, `feature`, `fix`, `chore`. `CLAUDE.md` already says releases come from them, but they do not
  exist.
- Pick the first version (D13): the old tags stop at `2.1`, so `v3.0.0` is the natural start, and `images.yml` already builds
  on `vX.Y.Z` tags.
- `gllhub/contracts` and the packages are versioned apart, and this does not release them.
- Whether to rebuild milestones for the five merged pull requests (D14). Recommended: no, start from the first release.

Milestones need issues on: with them off, reading a milestone's items fails the sync (the listing answers an error, and the
source refuses to read part of a list). **Turn issues on before the first milestone exists.**

### B3. The repository on GitHub

Each visible change is confirmed by the user first.

- A description that says what gllHub is, and the site's page as homepage if wanted.
- Issues on. Discussions on or off (D15): with them off, the Discussion tab stays empty.
- A public Project, linked to `gll-hub`, and to `gllhub-packages` if its work belongs there too (D16). Its flows are set by
  hand, like Musizeer's: add from the repository, Done on close. `gh` needs a write permission to create it, so the user
  either creates it or grants that.

### B4. The showcase file

Written with the user: the reason for the project is theirs to tell, as it was for Musizeer.

- A draft of the front matter from the code, checked against it: `title: gllHub`, claim, summary, colour, features,
  decisions. ADRs 0001 to 0003 are the natural source for `decisions`.
- `since`: the repository dates from 2022 as a fork, and the rebuild from 2026 (D17).
- `related: [Musizeer]`, and on Musizeer's side `related: [gllHub]`, at its next release.
- Screenshots of the admin with invented data, in `.github/showcase/`. Look at each one before committing: the local admin
  holds real data and accounts.
- Optional `showcase.it.md`.

`docs/` is read too: its Markdown files become the Docs tab, **this plan included**. Decide whether a working plan belongs on
the site (D18). Recommended: move it to a folder the source does not read, or let the source skip it by a generic rule, as a
small change in `gllhub/github`.

### B5. Order

1. B1, after checking b5srl.eu's deployment.
2. B2 in a pull request into `dev`.
3. B3, issues first.
4. B4 in a pull request into `dev`.
5. A release that contains the file. Check that `v3.0.0` has `.github/showcase.md`.
6. **Only now the topic `showcase`**, on `sossiogll/gll-hub`.
7. A sync in the local stack, or in production if A3 is done. Validate the answers against the contract, and look at the
   page on the site.

Done when `/entries/project/gllhub` answers with the real page, images copied, and the site shows it. Musizeer's page then
links to it through `related`.

B does not depend on A, except for step 7 in production. It can run first, locally, if the site should show gllHub sooner.

## Decisions waiting for the user

| # | Decision | Recommendation |
| --- | --- | --- |
| D2 | A1: how a refused file's reason reaches the admin | ADR first, an optional capability in the contract |
| D3 | Rate limit for the site | Decide with real traffic, in A3 |
| D4 | Canonical of a project | Send none |
| D5 | The about page | Written in the admin for now |
| D6 | One doc at a time in the API | Not yet: measure first |
| D7 | Host and public addresses | The user's call |
| D8 | How the instance image gets the packages | A private registry or a path copy in the image: to compare in A3 |
| D9 | Sync interval | One hour, about 840 calls a day |
| D10 | When and where the b5srl.eu port goes | Inventory after the site is live |
| D11 | What of `gll-hub` gets read | `dev` as default, after checking b5srl.eu |
| D12 | Detach the fork | No, it is cosmetic |
| D13 | First version | `v3.0.0` |
| D14 | Milestones for past pull requests | No |
| D15 | Discussions on `gll-hub` | The user's call |
| D16 | Project for gllHub, and which repositories | One public Project, both repositories |
| D17 | `since` | 2026, the rebuild |
| D18 | This plan on the site's Docs tab | Keep it off |
| D19 | ADRs 0001 to 0003 say "accepted" | Confirm 0003: the user approved its contract, not the document itself |

## The user's answers so far

All on 2026-11-05 unless dated. Each covers what it says and no more.

- 2026-11-04: Musizeer first among the repositories; the others are not approved for the showcase.
- 2026-11-04: `laravel-blog` and the packages moved under `sossiogll.com/gllHub`.
- Recommended options approved for the open decisions of the site's note: widgets replace `playground`, shared curation,
  PHP 8.4, `package-lock.json` committed, CV from a JSON file in a repository, `docs/` over the wiki, milestones with their
  items, discussions imported instead of giscus, no comments or likes in API v2.
- `gll-hub` is the repository's identifier, and gllHub the name of the project.
- The integration branch of `gll-hub` renamed from `next` to `dev`, like the site's and Musizeer's. Merge commits above
  and old pull requests keep the old name.
- `sossiogll/curriculum.json` created as public, with the dates still as in the Word document. Whether they are right is
  still open: the start and the end of the Master's degree, Micron, the two roles at Atida.
- The demo source and its entries deleted from the local database.
- The action that validated `showcase.md` dropped: validation belongs to the application, not to a pipeline.
- gllhub-packages#6, gll-hub#7 and this plan (gll-hub#6) merged once their CI was green. This settles D1. The numbers of the
  other decisions stay as they are.
- No more refinements until this plan is approved.
- The full CV approved as phase A6, with the levels of a language as four flat fields, as recommended.

## Not doing

Written down so nobody starts them again without a reason.

- **A GitHub Action that validates `showcase.md`.** The sync already checks the file; A1 makes the result visible.
- **giscus.** Discussions are a read-only copy.
- **Comments, likes, newsletters in API v2.** They come back only with their implementation in the core.
- **Writing the repository's Website field.** The contract has no way for a source to write back.
- **`thumbnail` and `author` in the API.** The site said it uses neither.

## Local notes for whoever continues

- Stack: from `gll-hub`, `MAILPIT_PORT=18025 docker compose -f docker-compose.dev.yml up -d`, because another project holds
  8025. The admin is on `http://localhost:8001/admin`, the API on `http://localhost:8001/api/v2`.
- The local `.env` sets `COMPOSE_PROJECT_NAME=laravel-blog`: it keeps the database's Docker volume after the folder was
  renamed. Without it, the stack starts with an empty database.
- `composer.lock` is modified locally on purpose, because `composer.local.json` installs the packages. It stays out of commits,
  as the README says. A commit that must change the lock uses a clean checkout.
- The local database holds one real GitHub source with a token, encrypted, entered by the user.
