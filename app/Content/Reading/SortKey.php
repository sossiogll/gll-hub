<?php

declare(strict_types=1);

namespace App\Content\Reading;

final readonly class SortKey
{
    public function __construct(public string $field, public bool $descending)
    {
    }
}
