# gllhub/contracts

The only thing a gllHub package depends on. It holds the interfaces and value objects between the gllHub core and
its packages, the exceptions a package may throw on purpose, and the conformance check every package runs.

## The rules

1. **The core never names a package.** No `if ($package === 'github')`, no list of known integrations. The core
   iterates over what packages declare.
2. **Declared, never inferred.** Names, roles, settings, credentials and capabilities come from the manifest and the
   static `definition()` of each class. A capability is declared AND implemented as its interface; the conformance
   check fails when the two disagree.
3. **Packages return data, the core decides.** A source returns entries; the core matches, creates, updates, keeps
   curated fields, downloads media, links references and removes what is gone. A webhook says what changed; the
   core decides when to sync.
4. **A package reaches the core only through its `Context`.** Settings, decrypted credentials, the core's HTTP
   client, a logger, the clock, the public URL. Never the database, the queues, the cache or the environment.
5. **The contract grows, it does not break.** Within a major, only new optional interfaces (capabilities) and new
   fields with defaults. Anything else is a new major. A package declares the version it was built on
   (`Manifest::$contracts`); the core refuses another major or a newer minor.

## The pieces

| Role | Interface | Declares | Does |
| --- | --- | --- | --- |
| Package | `Package\Package` | `Manifest`: name, version, contracts, classes per role, settings, credentials | — |
| Content type | `Content\ContentType` | `ContentTypeDefinition`: key, labels, fields | nothing: pure schema |
| Source | `Source\Source` | `SourceDefinition`: key, produced types, capabilities, per-instance settings and credentials, interval | `pull()` every entry |
| ↳ incremental | `Source\SyncsIncrementally` | `Capability::Incremental` | `pullSince(cursor)` → `SyncBatch` |
| ↳ single fetch | `Source\FetchesSingleEntry` | `Capability::SingleFetch` | `fetch(externalId)`, null = gone |
| Webhook | `Webhook\WebhookReceiver` | `WebhookDefinition`: key, sources it refreshes, secret | `verify()`, `handle()` → `SyncRequest`s |
| Login | `Auth\AuthProvider` | `AuthDefinition`: key, settings, client credentials | `redirectUrl()`, `resolve()` → `ExternalIdentity` |

**Fields.** The core owns title, slug, summary, body, language, author, dates and thumbnail. A
content type declares only its own fields. A field is owned by the source (read-only in the admin, overwritten by
every sync) unless it is `curated: true`, in which case the person owns it and a sync never touches it.

**Instances.** A source can be configured several times (one per repository, one per feed). The context of a call
merges package-wide settings and credentials with those of the instance being called.

**Complete or partial listings.** `pull()` returns everything, and the core removes what is missing; or, with
`listsEverything: false` (a feed that shows only the latest items), the core never removes on absence.

**Bodies.** A source passes the body as its service gives it (`BodyFormat::Markdown` or `Html`); the core
sanitizes and converts it in one place.

**HTTP.** `Context::http()` is the core's client. It owns timeouts, user agent and logging, and throws the
contract's exceptions for network errors and 5xx (`Unavailable`), 429 (`RateLimited`), 401 and 403
(`InvalidCredentials`). Everything else is returned for the package to read.

**Errors.** `Unavailable` (retry with backoff), `RateLimited` (wait `retryAfter`), `InvalidCredentials` (stop the
instance, ask the admin), `InvalidConfiguration` (show it next to the setting), `AuthenticationFailed`. Anything
else is a bug of the package.

## Discovery

A package names its entry point in its `composer.json`:

```json
"extra": { "gllhub": { "package": "Gll\\Hub\\Medium\\MediumPackage" } }
```

The core reads Composer's installed packages, instantiates each entry point and reads its manifest.

## Conformance

Every package runs, in its own test suite:

```php
Conformance::check(new MediumPackage());
Conformance::checkEntries(FeedSource::definition(), [Article::definition()], $sampleEntries);
```

Both throw one `ConformanceError` listing every violation. A new generic rule goes in `Testing\Conformance`, with a
good and a bad sample in `tests/Fixtures`.

**One set of rules for entries.** `Content\EntryRules::violations($source, $types, $entry)` decides whether an
`ExternalEntry` is acceptable for its content type: identity and size limits (the core's columns), title, language,
field values (`Content\FieldRules`), required fields, media and references. `checkEntries()` is that function over
your samples, and the core calls the very same one on every entry a source returns: an entry that passes your tests
is never refused at sync time. `FieldRules` is also what the core uses to check the values a person curates.
Media and Reference fields hold ids of the core's own records, so a source never sends them in `$fields`: it sends
`$media` and `$references`.

Packages test against `Testing\StaticContext` (fixed settings, credentials, clock) and `Testing\FakeHttpClient`
(each URL answers a response or a contract exception), never against the core.

## A source, end to end

```php
final class FeedSource implements Source
{
    public static function definition(): SourceDefinition
    {
        return new SourceDefinition(
            key: 'feed',
            label: 'RSS feed',
            produces: ['article'],
            instanceSettings: [new Setting('feed_url', 'Feed URL', SettingType::Url, required: true)],
            defaultIntervalMinutes: 30,
        );
    }

    public function pull(Context $context): iterable
    {
        $response = $context->http()->get($context->setting('feed_url'));

        foreach (FeedParser::parse($response->body) as $item) {
            yield new ExternalEntry(
                externalId: $item->guid,
                type: 'article',
                title: $item->title,
                body: $item->markdown,
                fields: ['origin_url' => $item->link],
                media: [new RemoteMedia('cover', $item->image)],
                publishedAt: $item->date,
                canonicalUrl: $item->link,
                fingerprint: sha1($item->markdown),
            );
        }
    }
}
```
