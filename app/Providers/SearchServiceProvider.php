<?php

namespace App\Providers;

use App\Models\Listing;
use App\Observers\ListingSearchObserver;
use App\Search\ListingIndex;
use App\Search\ListingSearch;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Support\ServiceProvider;

class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shared: one HTTP client and connection pool for the request,
        // rather than one per resolved search class.
        $this->app->singleton(Client::class, function () {
            return ClientBuilder::create()
                ->setHosts([config('elasticsearch.host')])
                ->setHttpClientOptions(['timeout' => config('elasticsearch.timeout')])
                ->build();
        });

        $this->app->singleton(ListingIndex::class, fn ($app) => new ListingIndex(
            $app->make(Client::class),
            config('elasticsearch.index'),
        ));

        $this->app->singleton(ListingSearch::class, fn ($app) => new ListingSearch(
            $app->make(Client::class),
            config('elasticsearch.index'),
        ));
    }

    public function boot(): void
    {
        // Only observe when search is on. With it off nothing reads the
        // index, so writing to it would be pure latency on every save.
        if (config('elasticsearch.enabled')) {
            Listing::observe(ListingSearchObserver::class);
        }
    }
}
