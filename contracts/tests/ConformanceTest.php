<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Tests;

use Gll\Hub\Contracts\Contracts;
use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Testing\Conformance;
use Gll\Hub\Contracts\Testing\ConformanceError;
use Gll\Hub\Contracts\Tests\Fixtures\Article;
use Gll\Hub\Contracts\Tests\Fixtures\BrokenPackage;
use Gll\Hub\Contracts\Tests\Fixtures\FeedSource;
use Gll\Hub\Contracts\Tests\Fixtures\SamplePackage;
use PHPUnit\Framework\TestCase;

final class ConformanceTest extends TestCase
{
    public function test_a_good_package_conforms(): void
    {
        Conformance::check(new SamplePackage());
        $this->addToAssertionCount(1);
    }

    public function test_good_entries_conform(): void
    {
        Conformance::checkEntries(FeedSource::definition(), [Article::definition()], [FeedSource::sample('x')]);
        $this->addToAssertionCount(1);
    }

    public function test_a_broken_package_reports_every_violation(): void
    {
        try {
            Conformance::check(new BrokenPackage());
            $this->fail('expected a ConformanceError');
        } catch (ConformanceError $e) {
            $all = implode("\n", $e->violations);
            foreach ([
                'is not a vendor/package name',
                'declares contracts 2.0',
                'unknown role "widget"',
                'key "Project" must match',
                '"title" is a core field',
                'enum field "stage" has no options',
                'list field "features" has no item fields',
                'source repo produces no content type',
                'declares incremental but does not implement',
                'enum setting "mode" has no options',
                'the default of "limit" is not a valid integer',
                'refreshes "nothing", which is not a source',
            ] as $expected) {
                $this->assertStringContainsString($expected, $all);
            }
        }
    }

    public function test_a_refused_entry_conforms_without_a_title(): void
    {
        Conformance::checkEntries(FeedSource::definition(), [Article::definition()], [FeedSource::refused('x')]);
        $this->addToAssertionCount(1);
    }

    public function test_bad_entries_are_reported(): void
    {
        $bad = new ExternalEntry(
            externalId: 'x',
            type: 'article',
            title: 'T',
            fields: ['featured' => true, 'unknown' => 1],
        );

        try {
            Conformance::checkEntries(FeedSource::definition(), [Article::definition()], [$bad]);
            $this->fail('expected a ConformanceError');
        } catch (ConformanceError $e) {
            $all = implode("\n", $e->violations);
            $this->assertStringContainsString('"featured" is curated', $all);
            $this->assertStringContainsString('"unknown" is not in type article', $all);
            $this->assertStringContainsString('required field "origin_url" is missing', $all);
        }
    }

    public function test_contract_versions(): void
    {
        $this->assertTrue(Contracts::accepts('1.0'));
        $this->assertTrue(Contracts::accepts('1.0.3'));
        $this->assertTrue(Contracts::accepts('1.1'));
        $this->assertFalse(Contracts::accepts('1.2'));
        $this->assertFalse(Contracts::accepts('2.0'));
        $this->assertFalse(Contracts::accepts('one'));
    }
}
