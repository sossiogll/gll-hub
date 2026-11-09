<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Tests;

use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\EntryRules;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldRules;
use Gll\Hub\Contracts\Content\FieldType;
use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Source\ExternalRef;
use Gll\Hub\Contracts\Source\RemoteMedia;
use Gll\Hub\Contracts\Testing\Conformance;
use Gll\Hub\Contracts\Testing\ConformanceError;
use Gll\Hub\Contracts\Tests\Fixtures\Article;
use Gll\Hub\Contracts\Tests\Fixtures\FeedSource;
use PHPUnit\Framework\TestCase;

final class EntryRulesTest extends TestCase
{
    /** @return list<string> */
    private function violations(ExternalEntry $entry): array
    {
        return EntryRules::violations(FeedSource::definition(), [Article::definition()], $entry);
    }

    private function entry(array $fields = ['origin_url' => 'https://example.com/a'], string $title = 'T', string $language = 'en'): ExternalEntry
    {
        return new ExternalEntry(externalId: 'x', type: 'article', title: $title, language: $language, fields: $fields);
    }

    public function test_a_language_is_a_short_tag(): void
    {
        foreach (['en', 'it', 'pt-BR', 'zh-Hant-TW'] as $good) {
            $this->assertTrue(EntryRules::isLanguage($good), $good);
        }
        foreach (['', 'e', 'EN', 'english', 'en_US', 'en-', 'en-abcdefghij'] as $bad) {
            $this->assertFalse(EntryRules::isLanguage($bad), $bad);
        }
    }

    public function test_a_good_entry_has_no_violations(): void
    {
        $this->assertSame([], $this->violations(FeedSource::sample('x')));
    }

    public function test_the_conformance_check_reports_what_the_rules_find(): void
    {
        $entry = $this->entry(title: ' ');

        try {
            Conformance::checkEntries(FeedSource::definition(), [Article::definition()], [$entry]);
            $this->fail('expected a ConformanceError');
        } catch (ConformanceError $e) {
            $this->assertSame($this->violations($entry), $e->violations);
        }
    }

    public function test_identity_and_type(): void
    {
        $this->assertContains('an entry has an empty externalId', $this->violations(new ExternalEntry('', 'article', 'T', fields: ['origin_url' => 'https://a.test'])));
        $this->assertStringContainsString('longer than 255', implode(' ', $this->violations(new ExternalEntry(str_repeat('a', 256), 'article', 'T', fields: ['origin_url' => 'https://a.test']))));
        $this->assertStringContainsString('not in the source\'s produces', implode(' ', $this->violations(new ExternalEntry('x', 'video', 'T'))));
        $this->assertStringContainsString('no definition given for type "article"', implode(' ', EntryRules::violations(FeedSource::definition(), [], $this->entry())));
    }

    public function test_a_refused_entry_is_judged_on_its_identity_and_its_reason_only(): void
    {
        $this->assertSame([], $this->violations(FeedSource::refused('x')));
        $this->assertSame([], $this->violations(new ExternalEntry('x', 'article', '', fields: ['unknown' => 1], refusal: 'unreadable')));
    }

    public function test_a_refusal_needs_a_reason(): void
    {
        foreach (['', '  '] as $blank) {
            $this->assertSame(['entry x: the refusal has no reason'], $this->violations(new ExternalEntry('x', 'article', '', refusal: $blank)));
        }
    }

    public function test_a_refused_entry_keeps_its_identity_rules(): void
    {
        $all = implode("\n", [
            ...$this->violations(new ExternalEntry('x', 'video', '', refusal: 'unreadable')),
            ...$this->violations(new ExternalEntry('', 'article', '', refusal: 'unreadable')),
            ...$this->violations(new ExternalEntry('x', 'article', '', language: 'English', refusal: 'unreadable')),
        ]);

        $this->assertStringContainsString('not in the source\'s produces', $all);
        $this->assertStringContainsString('empty externalId', $all);
        $this->assertStringContainsString('language "English"', $all);
    }

    public function test_title_and_language(): void
    {
        $this->assertStringContainsString('empty title', implode(' ', $this->violations($this->entry(title: ''))));
        $this->assertStringContainsString('title is longer than 500', implode(' ', $this->violations($this->entry(title: str_repeat('é', 501)))));
        $this->assertStringContainsString('language "English"', implode(' ', $this->violations($this->entry(language: 'English'))));
        $this->assertSame([], $this->violations($this->entry(language: 'pt-BR')));
    }

    public function test_fields_are_checked_by_the_field_rules(): void
    {
        $all = implode("\n", $this->violations($this->entry([
            'origin_url' => 'not a url',
            'reading_minutes' => '4',
            'cover' => 1,
            'related' => 2,
        ])));

        $this->assertStringContainsString('field "origin_url" must be an http(s) URL', $all);
        $this->assertStringContainsString('field "reading_minutes" must be an integer', $all);
        $this->assertStringContainsString('"cover" is a Media field, send it as media', $all);
        $this->assertStringContainsString('"related" is a Reference field, send it as references', $all);
    }

    public function test_media_and_references_must_point_at_matching_fields(): void
    {
        $entry = new ExternalEntry(
            externalId: 'x',
            type: 'article',
            title: 'T',
            fields: ['origin_url' => 'https://a.test'],
            media: [new RemoteMedia('origin_url', 'http://a.test/i.png'), new RemoteMedia('thumbnail', 'https://a.test/i.png')],
            references: [new ExternalRef('cover', externalId: 'y'), new ExternalRef('related', type: 'article')],
        );
        $all = implode("\n", $this->violations($entry));

        $this->assertStringContainsString('media for "origin_url", which is not a Media field', $all);
        $this->assertStringContainsString('media url must be https', $all);
        $this->assertStringContainsString('reference for "cover", which is not a Reference field', $all);
        $this->assertStringContainsString('a reference needs an externalId or a type and a slug', $all);
    }

    public function test_field_values(): void
    {
        $type = new ContentTypeDefinition('t', 'T', 'Ts', [
            new Field('name', 'Name', FieldType::String, required: true, maxLength: 3),
            new Field('tags', 'Tags', FieldType::String, multiple: true),
            new Field('stage', 'Stage', FieldType::Enum, options: ['a', 'b']),
            new Field('day', 'Day', FieldType::Date),
            new Field('on', 'On', FieldType::Boolean),
            new Field('steps', 'Steps', FieldType::List, of: [new Field('what', 'What', FieldType::String, required: true)]),
        ]);
        $problems = static fn (string $key, mixed $value): string => implode('|', FieldRules::violations($type->field($key), $value));

        $this->assertSame('', $problems('name', 'abc'));
        $this->assertStringContainsString('longer than 3', $problems('name', 'abcd'));
        $this->assertSame('', $problems('name', null));
        $this->assertSame('', $problems('tags', ['a', 'b']));
        $this->assertStringContainsString('must be a list', $problems('tags', 'a'));
        $this->assertStringContainsString('"tags[1]" must be a string', $problems('tags', ['a', 2]));
        $this->assertStringContainsString('not one of its options', $problems('stage', 'c'));
        $this->assertSame('', $problems('day', '2026-01-31'));
        $this->assertStringContainsString('ISO 8601', $problems('day', 'tomorrow'));
        $this->assertStringContainsString('must be a boolean', $problems('on', 'yes'));
        $this->assertSame('', $problems('steps', [['what' => 'x']]));
        $this->assertStringContainsString('required field "what" is missing', $problems('steps', [[]]));
        $this->assertStringContainsString('"why", which is not in its item fields', $problems('steps', [['what' => 'x', 'why' => 'y']]));
        $this->assertStringContainsString('must be a list of items', $problems('steps', 'x'));

        $this->assertSame(['required field "name" is missing'], FieldRules::missing($type->fields, ['name' => '']));
        $this->assertSame([], FieldRules::missing($type->fields, ['name' => 'a']));
    }
}
