<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

// Laravel 11+'s slimmed-down default Controller no longer includes this trait
// (older scaffolds did) - added back here, once, so every controller gets
// $this->authorize() for policy checks without repeating the trait per class.
abstract class Controller
{
    use AuthorizesRequests;
}
