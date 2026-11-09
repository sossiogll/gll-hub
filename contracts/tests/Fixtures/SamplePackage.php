<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Tests\Fixtures;

use DateTimeImmutable;
use Gll\Hub\Contracts\Content\ContentType;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;
use Gll\Hub\Contracts\Package\Capability;
use Gll\Hub\Contracts\Package\Context;
use Gll\Hub\Contracts\Package\Credential;
use Gll\Hub\Contracts\Package\Manifest;
use Gll\Hub\Contracts\Package\Package;
use Gll\Hub\Contracts\Package\Setting;
use Gll\Hub\Contracts\Package\SettingType;
use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Source\FetchesSingleEntry;
use Gll\Hub\Contracts\Source\RemoteMedia;
use Gll\Hub\Contracts\Source\Source;
use Gll\Hub\Contracts\Source\SourceDefinition;
use Gll\Hub\Contracts\Webhook\IncomingWebhook;
use Gll\Hub\Contracts\Webhook\SyncRequest;
use Gll\Hub\Contracts\Webhook\WebhookDefinition;
use Gll\Hub\Contracts\Webhook\WebhookReceiver;

/** An invented package, shaped like gllhub/medium, used to test the conformance rules. */
final class SamplePackage implements Package
{
    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'acme/feed',
            label: 'Feed',
            version: '1.0.0',
            contracts: '1.0',
            provides: [
                'content_type' => [Article::class],
                'source' => [FeedSource::class],
                'webhook' => [FeedWebhook::class],
            ],
        );
    }
}

final class Article implements ContentType
{
    public static function definition(): ContentTypeDefinition
    {
        return new ContentTypeDefinition(
            key: 'article',
            label: 'Article',
            pluralLabel: 'Articles',
            fields: [
                new Field('origin_url', 'Original URL', FieldType::Url, required: true),
                new Field('reading_minutes', 'Reading time', FieldType::Integer),
                new Field('cover', 'Cover', FieldType::Media),
                new Field('featured', 'Featured', FieldType::Boolean, curated: true),
                new Field('related', 'Related', FieldType::Reference, references: ['article']),
                new Field('sections', 'Sections', FieldType::List, of: [new Field('title', 'Title', FieldType::String)]),
            ],
        );
    }
}

final class FeedSource implements Source, FetchesSingleEntry
{
    public static function definition(): SourceDefinition
    {
        return new SourceDefinition(
            key: 'feed',
            label: 'RSS feed',
            produces: ['article'],
            capabilities: [Capability::SingleFetch],
            instanceSettings: [
                new Setting('feed_url', 'Feed URL', SettingType::Url, required: true),
                new Setting('language', 'Language', SettingType::Enum, default: 'en', options: ['en', 'it']),
            ],
            defaultIntervalMinutes: 30,
        );
    }

    public function pull(Context $context): iterable
    {
        yield self::sample('guid-1');
    }

    public function fetch(Context $context, string $externalId): ?ExternalEntry
    {
        return self::sample($externalId);
    }

    public static function refused(string $id): ExternalEntry
    {
        return new ExternalEntry(externalId: $id, type: 'article', title: '', refusal: 'the front matter is not valid YAML');
    }

    public static function sample(string $id): ExternalEntry
    {
        return new ExternalEntry(
            externalId: $id,
            type: 'article',
            title: 'A sample article',
            body: 'Some *markdown*.',
            fields: ['origin_url' => 'https://example.com/a', 'reading_minutes' => 4],
            media: [new RemoteMedia('cover', 'https://example.com/a.png')],
            publishedAt: new DateTimeImmutable('2026-01-01'),
            canonicalUrl: 'https://example.com/a',
            fingerprint: 'abc',
        );
    }
}

final class FeedWebhook implements WebhookReceiver
{
    public static function definition(): WebhookDefinition
    {
        return new WebhookDefinition(
            key: 'ping',
            label: 'Feed ping',
            refreshes: ['feed'],
            credentials: [new Credential('secret', 'Shared secret')],
        );
    }

    public function verify(Context $context, IncomingWebhook $webhook): bool
    {
        $secret = $context->credential('secret') ?? '';

        return hash_equals(hash_hmac('sha256', $webhook->body, $secret), (string) $webhook->header('x-signature'));
    }

    public function handle(Context $context, IncomingWebhook $webhook): array
    {
        return [new SyncRequest('feed', ['feed_url' => (string) ($webhook->json()['feed'] ?? '')])];
    }
}
