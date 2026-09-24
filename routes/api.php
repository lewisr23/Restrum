<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\EndorsementController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\ListingMediaController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PassportController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\StripeConnectController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['ok' => true]));

// Unauthenticated because Stripe has no session here, and safe because the
// controller verifies the request's signature before anything reads it. It
// sits outside the auth group for that reason and no other.
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [PasswordResetController::class, 'request'])
    ->middleware('throttle:password-reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset']);

// Opened from a mail client, which sends no bearer token, so the signature
// is the whole of the authentication here. Throttled as well as signed: the
// signature is unguessable but the route is public, and a public route that
// hits the database on every call is worth a limit.
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:email-verification');

    // Must be registered BEFORE GET /listings/{listing} below - Laravel
    // matches routes in registration order, so {listing} would otherwise
    // swallow "saved" as if it were an id.
    Route::get('/listings/saved', [ListingController::class, 'saved']);

    Route::post('/listings', [ListingController::class, 'store'])->middleware('verified');
    Route::put('/listings/{listing}', [ListingController::class, 'update']);
    Route::delete('/listings/{listing}', [ListingController::class, 'destroy']);
    Route::post('/listings/{listing}/save', [ListingController::class, 'toggleSave']);

    // Replaces the old POST /listings/{listing}/buy, which marked a listing
    // sold without any money changing hands. This one reserves the listing
    // and hands back a Stripe Checkout URL.
    Route::post('/listings/{listing}/checkout', [CheckoutController::class, 'store'])
        ->middleware('verified');

    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('/orders/{order}/dispatch', [OrderController::class, 'dispatch']);
    Route::post('/orders/{order}/review', [ReviewController::class, 'store']);

    // Seller payouts. GET reports where Stripe has got to, POST returns a
    // fresh link into Stripe's hosted onboarding.
    Route::get('/stripe/connect', [StripeConnectController::class, 'show']);
    Route::post('/stripe/connect', [StripeConnectController::class, 'store']);

    Route::post('/listings/{listing}/media', [ListingMediaController::class, 'store']);
    Route::delete('/listings/{listing}/media/{media}', [ListingMediaController::class, 'destroy']);

    // The bell. Polled rather than pushed: the websocket already exists for
    // chat, but a notification is not time critical in the way a message in
    // an open conversation is, and a minute's delay on "you sold something"
    // costs nothing next to a second private channel per user.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'read']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->middleware('verified');

    Route::post('/listings/{listing}/messages', [MessageController::class, 'startOrContinue'])
        ->middleware('verified');
    Route::post('/messages/{message}/respond', [MessageController::class, 'respond']);

    Route::post('/users/{user}/endorse', [EndorsementController::class, 'store']);
    Route::post('/users/{user}/follow', [FollowController::class, 'toggle']);

    Route::post('/listings/{listing}/report', [ReportController::class, 'listing']);
    Route::post('/users/{user}/report', [ReportController::class, 'user']);

    // Moderation. The middleware answers 404 rather than 403, so these do
    // not advertise themselves to anyone who is not already an admin.
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/reports', [AdminController::class, 'reports']);
        Route::post('/reports/{report}/resolve', [AdminController::class, 'resolve']);
        Route::post('/users/{user}/suspend', [AdminController::class, 'suspend']);
        Route::post('/users/{user}/reinstate', [AdminController::class, 'reinstate']);
        Route::post('/listings/{listing}/remove', [AdminController::class, 'removeListing']);
    });

    Route::post('/listings/{listing}/passport/entries', [PassportController::class, 'addEntry']);
    Route::put('/listings/{listing}/passport/entries/{entry}', [PassportController::class, 'updateEntry']);
});

// The gear adviser. Public, because someone deciding what to buy has usually
// not signed up yet, and that is the moment it is useful. Rate limited per IP
// because unlike everything else here, each request costs real money: see the
// gear-adviser limiter in AppServiceProvider.
Route::get('/recommendations/status', [RecommendationController::class, 'status']);
Route::post('/recommendations', [RecommendationController::class, 'chat'])
    ->middleware('throttle:gear-adviser');

// The category tree and brand lists. Public, cached, and read by both the
// filter panel and the sell form.
Route::get('/catalog', [CatalogController::class, 'index']);
Route::get('/catalog/categories/{category}', [CatalogController::class, 'show']);

Route::get('/listings', [ListingController::class, 'index']);
Route::get('/listings/{listing}', [ListingController::class, 'show']);
Route::get('/listings/{listing}/similar', [ListingController::class, 'similar']);
Route::get('/listings/{listing}/passport', [PassportController::class, 'show']);
Route::get('/users/{user}', [UserController::class, 'show']);
