<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Search\ListingSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Times the two search paths against each other over whatever is currently
 * in the database.
 *
 * Measures only, and writes nothing, so it is safe to point at any
 * environment. The interesting number is not the average: it is what
 * happens to the SQL path as the table grows, because LIKE '%term%' cannot
 * use an index and has to read every row.
 */
class BenchmarkSearch extends Command
{
    protected $signature = 'search:benchmark
        {--terms=strat,telecaster,microphone,delay pedal,fender : Comma separated search terms}
        {--runs=20 : Timed runs per term, after a warm up}';

    protected $description = 'Compare Elasticsearch and SQL LIKE listing search timings';

    public function handle(ListingSearch $search): int
    {
        $terms = array_filter(array_map('trim', explode(',', (string) $this->option('terms'))));
        $runs = max(1, (int) $this->option('runs'));
        $rows = Listing::count();

        if (! config('elasticsearch.enabled')) {
            $this->error('Search is disabled, so there is nothing to compare against.');

            return self::FAILURE;
        }

        $this->line("Listings in the table: <info>{$rows}</info>");
        $this->line("Timed runs per term: <info>{$runs}</info>");
        $this->newLine();

        $results = [];

        foreach ($terms as $term) {
            // One untimed run each, so neither side is charged for a cold
            // connection or an empty query cache.
            $this->timeSql($term, 1);
            $this->timeElasticsearch($search, $term, 1);

            $sql = $this->timeSql($term, $runs);
            $es = $this->timeElasticsearch($search, $term, $runs);

            $results[] = [
                $term,
                $this->ms($sql['median']),
                $this->ms($es['median']),
                $sql['median'] > 0
                    ? round($sql['median'] / max($es['median'], 0.000001), 1).'x'
                    : 'n/a',
                $sql['hits'].' / '.$es['hits'],
            ];
        }

        $this->table(
            ['Term', 'SQL LIKE', 'Elasticsearch', 'Speedup', 'Hits (SQL / ES)'],
            $results,
        );

        $this->line('Medians, not means: a single slow run says more about the machine than the query.');
        $this->line('Hit counts differ on purpose. SQL matches substrings, Elasticsearch matches words,');
        $this->line('handles typos and slang, and returns results in relevance order.');

        return self::SUCCESS;
    }

    /**
     * @return array{median: float, hits: int}
     */
    private function timeSql(string $term, int $runs): array
    {
        $times = [];
        $hits = 0;

        for ($i = 0; $i < $runs; $i++) {
            $start = hrtime(true);
            $hits = DB::table('listings')
                ->where(fn ($q) => $q
                    ->where('title', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%"))
                ->count();
            $times[] = (hrtime(true) - $start) / 1_000_000;
        }

        return ['median' => $this->median($times), 'hits' => $hits];
    }

    /**
     * @return array{median: float, hits: int}
     */
    private function timeElasticsearch(ListingSearch $search, string $term, int $runs): array
    {
        $times = [];
        $hits = 0;

        for ($i = 0; $i < $runs; $i++) {
            $start = hrtime(true);
            $hits = $search->search(['search' => $term], 1, 20)['total'];
            $times[] = (hrtime(true) - $start) / 1_000_000;
        }

        return ['median' => $this->median($times), 'hits' => $hits];
    }

    private function median(array $times): float
    {
        sort($times);
        $count = count($times);
        $middle = intdiv($count, 2);

        return $count % 2 === 0
            ? ($times[$middle - 1] + $times[$middle]) / 2
            : $times[$middle];
    }

    private function ms(float $value): string
    {
        return number_format($value, 1).' ms';
    }
}
