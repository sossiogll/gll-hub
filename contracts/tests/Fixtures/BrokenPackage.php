<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Tests\Fixtures;

use Gll\Hub\Contracts\Content\ContentType;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;
use Gll\Hub\Contracts\Package\Capability;
use Gll\Hub\Contracts\Package\Context;
use Gll\Hub\Contracts\Package\Manifest;
use Gll\Hub\Contracts\Package\Package;
use Gll\Hub\Contracts\Package\Setting;
use Gll\Hub\Contracts\Package\SettingType;
use Gll\Hub\Contracts\Source\Source;
use Gll\Hub\Contracts\Source\SourceDefinition;
use Gll\Hub\Contracts\Webhook\IncomingWebhook;
use Gll\Hub\Contracts\Webhook\WebhookDefinition;
use Gll\Hub\Contracts\Webhook\WebhookReceiver;

/** A package that breaks one rule of each kind. */
final class BrokenPackage implements Package
{
    public function manifest(): Manifest
    {
        return new Manifest(
            name: 'Not A Name',
            label: 'Broken',
            version: '0.1.0',
            contracts: '2.0',
            provides: [
                'content_type' => [BrokenType::class],
                'source' => [BrokenSource::class],
                'webhook' => [BrokenWebhook::class],
                'widget' => [BrokenType::class],
            ],
        );
    }
}

final class BrokenType implements ContentType
{
    public static function definition(): ContentTypeDefinition
    {
        return new ContentTypeDefinition('Project', 'Project', 'Projects', [
            new Field('title', 'Title', FieldType::String),
            new Field('stage', 'Stage', FieldType::Enum),
            new Field('features', 'Features', FieldType::List),
            new Field('graph', 'Graph', FieldType::Group),
            new Field('graphs', 'Graphs', FieldType::Group, multiple: true, of: [new Field('name', 'Name', FieldType::String)]),
        ]);
    }
}

final class BrokenSource implements Source
{
    public static function definition(): SourceDefinition
    {
        return new SourceDefinition(
            key: 'repo',
            label: 'Repo',
            produces: [],
            capabilities: [Capability::Incremental],
            instanceSettings: [
                new Setting('mode', 'Mode', SettingType::Enum),
                new Setting('limit', 'Limit', SettingType::Integer, default: 'ten'),
            ],
        );
    }

    public function pull(Context $context): iterable
    {
        return [];
    }
}

final class BrokenWebhook implements WebhookReceiver
{
    public static function definition(): WebhookDefinition
    {
        return new WebhookDefinition('push', 'Push', refreshes: ['nothing']);
    }

    public function verify(Context $context, IncomingWebhook $webhook): bool
    {
        return true;
    }

    public function handle(Context $context, IncomingWebhook $webhook): array
    {
        return [];
    }
}
