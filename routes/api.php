<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\EndorsementController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\ListingMediaController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PassportController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['ok' => true]));

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Must be registered BEFORE GET /listings/{listing} below - Laravel
    // matches routes in registration order, so {listing} would otherwise
    // swallow "saved" as if it were an id.
    Route::get('/listings/saved', [ListingController::class, 'saved']);

    Route::post('/listings', [ListingController::class, 'store']);
    Route::put('/listings/{listing}', [ListingController::class, 'update']);
    Route::post('/listings/{listing}/save', [ListingController::class, 'toggleSave']);
    Route::post('/listings/{listing}/buy', [ListingController::class, 'buy']);

    Route::post('/listings/{listing}/media', [ListingMediaController::class, 'store']);
    Route::delete('/listings/{listing}/media/{media}', [ListingMediaController::class, 'destroy']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);

    Route::post('/listings/{listing}/messages', [MessageController::class, 'startOrContinue']);
    Route::post('/messages/{message}/respond', [MessageController::class, 'respond']);

    Route::post('/users/{user}/endorse', [EndorsementController::class, 'store']);
    Route::post('/users/{user}/follow', [FollowController::class, 'toggle']);

    Route::post('/listings/{listing}/passport/entries', [PassportController::class, 'addEntry']);
    Route::put('/listings/{listing}/passport/entries/{entry}', [PassportController::class, 'updateEntry']);
});

Route::get('/listings', [ListingController::class, 'index']);
Route::get('/listings/{listing}', [ListingController::class, 'show']);
Route::get('/listings/{listing}/passport', [PassportController::class, 'show']);
Route::get('/users/{user}', [UserController::class, 'show']);
