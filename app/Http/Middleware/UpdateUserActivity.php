<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UpdateUserActivity
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user) {
            $now = now();
            $updates = [];

            // Update last_seen_at if never set or if older than 1 minute
            if (!$user->last_seen_at || $user->last_seen_at->diffInSeconds($now) >= 60) {
                $updates['last_seen_at'] = $now;
            }

            // Distinguish active interactions (mutations or primary page views) from passive polling
            $isPassivePolling = $request->isMethod('GET') && (
                $request->is('chats/*/messages') ||
                $request->is('chats/list') ||
                $request->is('chats/*/lock')
            );

            if (!$isPassivePolling) {
                if (!$user->last_activity_at || $user->last_activity_at->diffInSeconds($now) >= 60) {
                    $updates['last_activity_at'] = $now;
                }
            }

            if (!empty($updates)) {
                $user->timestamps = false;
                $user->update($updates);
                $user->timestamps = true;
            }
        }

        return $response;
    }
}
