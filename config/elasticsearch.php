<?php

return [

    /*
    |---------------------------------------------------------------------
    | Enabled
    |---------------------------------------------------------------------
    |
    | When this is off, listing search falls back to the SQL LIKE query in
    | ListingController. Off by default so a fresh clone and the test suite
    | both run with no cluster to install, and so a deployment has to opt in
    | rather than silently depending on a service that might not be there.
    |
    */

    'enabled' => env('ELASTICSEARCH_ENABLED', false),

    /*
    |---------------------------------------------------------------------
    | Host
    |---------------------------------------------------------------------
    |
    | Inside the compose stack this is http://elasticsearch:9200. From the
    | host it is whatever port the container publishes, which defaults to
    | 9200 but is overridable when something else already holds it.
    |
    */

    'host' => env('ELASTICSEARCH_HOST', 'http://localhost:9200'),

    /*
    |---------------------------------------------------------------------
    | Index name
    |---------------------------------------------------------------------
    |
    | Configurable so parallel environments can share one cluster without
    | overwriting each other's documents.
    |
    */

    'index' => env('ELASTICSEARCH_INDEX', 'listings'),

    /*
    |---------------------------------------------------------------------
    | Request timeout, in seconds
    |---------------------------------------------------------------------
    |
    | Deliberately short. A search is on the critical path of a page load,
    | and falling back to SQL quickly beats making the user wait on a
    | cluster that is struggling.
    |
    */

    'timeout' => (float) env('ELASTICSEARCH_TIMEOUT', 2.0),

];
