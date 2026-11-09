<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Gll\Hub\Contracts\Content\ContentType;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;

/** One of each kind of field, with the two that matter most to a sync: curated, and reference. */
final class Book implements ContentType
{
    public static function definition(): ContentTypeDefinition
    {
        return new ContentTypeDefinition(
            key: 'book',
            label: 'Book',
            pluralLabel: 'Books',
            fields: [
                new Field('isbn', 'ISBN', FieldType::String, required: true),
                new Field('pages', 'Pages', FieldType::Integer),
                new Field('tags', 'Tags', FieldType::String, multiple: true),
                new Field('stage', 'Stage', FieldType::Enum, options: ['draft', 'published']),
                new Field('cover', 'Cover', FieldType::Media),
                new Field('gallery', 'Gallery', FieldType::Media, multiple: true),
                new Field('notes', 'Notes', FieldType::Markdown),
                new Field('released', 'Released', FieldType::Date),
                new Field('chapters', 'Chapters', FieldType::List, of: [
                    new Field('title', 'Title', FieldType::String, required: true),
                    new Field('summary', 'Summary', FieldType::Text),
                    new Field('draft', 'Draft', FieldType::Markdown),
                    new Field('finished_at', 'Finished at', FieldType::Date),
                ]),
                new Field('timeline', 'Timeline', FieldType::Group, of: [
                    new Field('since', 'Since', FieldType::Date),
                    new Field('rows', 'Rows', FieldType::List, of: [
                        new Field('message', 'Message', FieldType::String, required: true),
                        new Field('at', 'At', FieldType::Date),
                    ]),
                    new Field('note', 'Note', FieldType::Markdown),
                    new Field('place', 'Place', FieldType::String, required: true),
                ]),
                new Field('related', 'Related', FieldType::Reference, multiple: true, references: ['book']),
                new Field('sequel', 'Sequel', FieldType::Reference, curated: true, references: ['book']),
                new Field('featured', 'Featured', FieldType::Boolean, curated: true),
                new Field('position', 'Position', FieldType::Integer, curated: true),
                new Field('blurb', 'Blurb', FieldType::String, translatable: true, curated: true, maxLength: 20),
            ],
        );
    }
}
