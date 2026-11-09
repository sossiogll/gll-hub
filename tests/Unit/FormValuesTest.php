<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Fields\FormValues;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Book;

final class FormValuesTest extends TestCase
{
    public function test_a_group_is_its_inner_values_coerced_like_any_field_and_null_when_empty(): void
    {
        $fields = Book::definition()->fields;

        $values = FormValues::fields($fields, ['timeline' => [
            'since' => '',
            'place' => 'Arrakis',
            'note' => '',
            'rows' => ['k1' => ['message' => 'One', 'at' => ''], 'k2' => ['message' => '', 'at' => '']],
        ]]);

        $this->assertSame(['timeline' => ['rows' => [['message' => 'One']], 'place' => 'Arrakis']], $values);
        $this->assertSame([], FormValues::fields($fields, ['timeline' => ['since' => '', 'place' => '', 'rows' => []]]));
        $this->assertSame([], FormValues::fields($fields, []));
    }
}
