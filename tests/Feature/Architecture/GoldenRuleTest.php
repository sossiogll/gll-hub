<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The core and the contract never name a package: no integration, no package, no
 * content type of one, no site they serve. What the core knows comes from the
 * registry at run time.
 */
final class GoldenRuleTest extends TestCase
{
    /**
     * A word of an integration (whole words only, so a column type such as "mediumText" is fine), a package
     * of the gllhub vendor other than the contract, a namespace of one, or a content type a package defines,
     * alone or inside a class name such as "ProjectController" ("page" is one too, but too common a word to forbid).
     */
    private const FORBIDDEN = '/\b(?:medium|github|gitlab|bitbucket|portfolio|substack|wordpress|hashnode|linkedin|twitter|youtube|rss|sossiogll|showcase|projects?|articles?|ideas?)\b'
        . '|(?-i:(?:Project|Article|Idea)s?(?:[A-Z]|\b))'
        . '|gllhub\/(?!contracts\b)[a-z0-9-]+'
        . '|Gll\\\\Hub\\\\(?!Contracts\b)\w+/i';

    private const SCANNED = ['app', 'config', 'routes', 'database', 'contracts/src'];

    public function test_no_integration_or_package_is_named_in_the_core(): void
    {
        $offences = [];

        foreach (Source::files(self::SCANNED) as $path => $code) {
            foreach (explode("\n", $code) as $number => $line) {
                if (preg_match(self::FORBIDDEN, $line, $match) === 1) {
                    $offences[] = $path . ':' . ($number + 1) . ' names "' . $match[0] . '"';
                }
            }
        }

        $this->assertSame([], $offences, "The core must not name a package:\n" . implode("\n", $offences));
    }

    public function test_the_scan_covers_the_core(): void
    {
        $files = array_keys(Source::files(self::SCANNED));

        foreach (['app/Packages/PackageRegistry.php', 'app/Sync/SourceSynchronizer.php', 'config/gllhub.php', 'routes/webhooks.php', 'database/migrations/2026_10_17_190100_create_entries_table.php', 'contracts/src/Content/ContentTypeDefinition.php'] as $expected) {
            $this->assertContains($expected, $files);
        }
    }

    public function test_the_rule_catches_what_it_is_meant_to_catch(): void
    {
        foreach ([
            "if (\$source === 'github') {",
            'Medium::class',
            "'gllhub/medium'",
            'use Gll\Hub\Portfolio\Types\Project;',
            'composer require gllhub/anything',
            '// the Portfolio package',
            'an RSS feed',
            'final class ProjectController',
            'new ListArticles()',
            "Route::get('/articles', \$list);",
            "\$type === 'idea'",
            '// our showcase',
            'https://hub.sossiogll.com',
        ] as $offending) {
            $this->assertSame(1, preg_match(self::FORBIDDEN, $offending), $offending);
        }
    }

    public function test_the_rule_leaves_the_contract_and_ordinary_words_alone(): void
    {
        foreach ([
            'use Gll\Hub\Contracts\Package\Package;',
            "'gllhub/contracts'",
            "\$table->mediumText('body');",
            'Schedule::command(\'gllhub:sync-due\')',
            "config('gllhub.sync.backoff')",
            'MediaLibrary media',
            'a mediumly sized thing',
            'a projected total',
            'an articulate message',
            'the page of a paginated list',
        ] as $fine) {
            $this->assertSame(0, preg_match(self::FORBIDDEN, $fine), $fine);
        }
    }
}
