<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bell.
 *
 * Deliberately thin. The interesting decisions about a notification - what
 * it says, where it points, whether it is worth an email - were all made
 * when it was created, and are stored on the row. This endpoint's only job
 * is handing them back in the right order and marking them read.
 */
class NotificationController extends Controller
{
    /** How many the bell shows. Older ones exist; nobody scrolls a bell. */
    private const PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $user->notifications()
                ->latest()
                ->limit(self::PAGE)
                ->get()
                ->map(fn ($notification) => [
                    'id' => $notification->id,
                    // The stored payload, written by MarketplaceNotification.
                    // Read from the row rather than rebuilt from the order,
                    // so a notification still says what it said at the time
                    // even after the thing it describes has moved on.
                    'kind' => $notification->data['kind'] ?? 'general',
                    'title' => $notification->data['title'] ?? '',
                    'body' => $notification->data['body'] ?? '',
                    'path' => $notification->data['path'] ?? '/',
                    'read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at,
                ]),

            // Counted rather than derived from the page above, which only
            // holds the most recent twenty and would under-report the moment
            // somebody let them pile up.
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark one as read, or all of them.
     *
     * One endpoint rather than two, because the interface only ever does one
     * of these at a time and an id is the whole of the difference. Scoped to
     * the signed-in user's own notifications, so an id belonging to somebody
     * else simply matches nothing.
     */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'uuid'],
        ]);

        $query = $request->user()->unreadNotifications();

        if (isset($data['id'])) {
            $query->whereKey($data['id']);
        }

        $query->update(['read_at' => now()]);

        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }
}
