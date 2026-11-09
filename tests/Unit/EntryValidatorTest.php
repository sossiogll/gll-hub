<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Content\Rules\EntryValidator;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Book;

final class EntryValidatorTest extends TestCase
{
    public function test_valid_curated_values_pass(): void
    {
        $this->assertSame([], EntryValidator::curated(Book::definition(), ['featured' => true, 'position' => 2, 'blurb' => 'Short one']));
        $this->assertSame([], EntryValidator::curated(Book::definition(), []));
    }

    public function test_curated_values_must_fit_their_field(): void
    {
        $violations = implode("\n", EntryValidator::curated(Book::definition(), [
            'featured' => 'yes',
            'position' => '3',
            'blurb' => str_repeat('x', 21),
        ]));

        $this->assertStringContainsString('"featured" must be a boolean', $violations);
        $this->assertStringContainsString('"position" must be an integer', $violations);
        $this->assertStringContainsString('"blurb" is longer than 20 characters', $violations);
    }

    public function test_only_curated_fields_can_be_curated(): void
    {
        $violations = implode("\n", EntryValidator::curated(Book::definition(), ['isbn' => '1', 'nope' => 1]));

        $this->assertStringContainsString('field "isbn" belongs to the source, it cannot be curated', $violations);
        $this->assertStringContainsString('field "nope" is not in type book', $violations);
    }

    public function test_a_valid_hand_written_entry_passes(): void
    {
        $this->assertSame([], EntryValidator::handWritten(
            Book::definition(),
            'Dune',
            ['isbn' => '1', 'pages' => 400, 'tags' => ['sf'], 'stage' => 'draft', 'chapters' => [['title' => 'One']]],
            ['featured' => false],
        ));
    }

    public function test_a_group_is_checked_inside_a_hand_written_entry(): void
    {
        $violations = implode("\n", EntryValidator::handWritten(Book::definition(), 'Dune', ['isbn' => '1', 'timeline' => ['rows' => [], 'x' => 1]], []));

        $this->assertStringContainsString('field "timeline" has "x", which is not in its fields', $violations);
        $this->assertStringContainsString('field "timeline": required field "place" is missing', $violations);
    }

    public function test_a_hand_written_entry_is_checked_against_every_field(): void
    {
        $violations = implode("\n", EntryValidator::handWritten(
            Book::definition(),
            '  ',
            ['pages' => 'many', 'stage' => 'lost', 'chapters' => [['summary' => 'no title']], 'featured' => true, 'cover' => 1, 'nope' => 1],
            ['position' => 'first', 'isbn' => 'x'],
        ));

        foreach ([
            'the title is empty',
            'field "pages" must be an integer',
            'field "stage" has "lost", not one of its options',
            'required field "title" is missing',
            'field "featured" is curated, put it with the curated values',
            'field "nope" is not in type book',
            'field "position" must be an integer',
            'field "isbn" belongs to the source, it cannot be curated',
        ] as $expected) {
            $this->assertStringContainsString($expected, $violations);
        }
    }

    public function test_a_hand_written_entry_needs_its_required_fields_wherever_they_are_given(): void
    {
        $this->assertContains('required field "isbn" is missing', EntryValidator::handWritten(Book::definition(), 'Dune', [], []));
    }

    public function test_the_columns_of_an_entry_keep_to_the_limits_of_the_core(): void
    {
        $this->assertSame([], EntryValidator::columns('Dune', 'pt-BR'));

        $violations = implode("\n", EntryValidator::columns(str_repeat('x', 501), 'English'));

        $this->assertStringContainsString('the title is longer than 500 characters', $violations);
        $this->assertStringContainsString('language "English" is not a language tag', $violations);
    }
}
