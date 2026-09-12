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
        // Registered unconditionally, with the observer itself checking
        // whether search is on each time it fires. Deciding here instead
        // would bake the answer in at boot, before a test has had any
        // chance to change it, which is how the test suite ended up
        // writing factory listings into the development index.
        Listing::observe(ListingSearchObserver::class);
    }
}
