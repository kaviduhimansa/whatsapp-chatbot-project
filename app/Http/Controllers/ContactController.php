<?php

namespace App\Http\Controllers;

use App\Models\ChatQueue;
use App\Models\Contact;
use App\Services\RoundRobinAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    private const SYNC_RECENT_FALLBACK_LIMIT = 40;
    private const SYNC_RECENT_MAX_LIMIT = 200;

    public function store(Request $request, RoundRobinAssignmentService $assignment)
    {
        $data = $request->validate([
            'name' => ['nullable','string','max:80'],
            'mobile' => ['required','string','max:20'],
        ]);

        $data['mobile'] = preg_replace('/\D+/', '', $data['mobile']);

        // normalize to 94xxxxxxxxx if user enters 07xxxxxxxx
        if (str_starts_with($data['mobile'], '0')) {
            $data['mobile'] = '94' . ltrim($data['mobile'], '0');
        }

        $contact = Contact::updateOrCreate(
            ['mobile' => $data['mobile']],
            ['name' => $data['name'] ?: $data['mobile']]
        );

<<<<<<< HEAD
        // If contact is not yet assigned, attempt assignment or push to chat queue
        if (!$contact->assigned_agent_id) {
            $assignmentService = app(RoundRobinAssignmentService::class);
            $assigned = $assignmentService->assignNextAgent($contact);

            if (!$assigned) {
                ChatQueue::firstOrCreate(
                    ['contact_id' => $contact->id],
                    [
                        'priority' => 0,
                        'queued_at' => now(),
                    ]
                );
            }
=======
        if ($contact->wasRecentlyCreated) {
            $assignment->assignIfUnassigned($contact);
>>>>>>> 266c7ae6e676e57dab7f1f2bf7b346745e5a1e4c
        }

        return redirect()->route('chats.index')->with('status', 'Contact saved.');
    }

    public function update(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'name' => ['nullable','string','max:80'],
        ]);

        $contact->update([
            'name' => $data['name'] ?: $contact->mobile
        ]);

        return redirect()->route('chats.show', $contact)->with('status', 'Contact updated.');
    }

    /**
     * Pull the last active mobiles from SLT API and upsert them into contacts.
     */
    public function syncRecent(Request $request)
    {
        $defaultLimit = (int) config('chat.sync_recent_limit', self::SYNC_RECENT_FALLBACK_LIMIT);
        $maxLimit = (int) config('chat.sync_recent_max_limit', self::SYNC_RECENT_MAX_LIMIT);
        $maxLimit = max(1, $maxLimit);

        $limit = (int) ($request->input('limit') ?? $defaultLimit);
        $limit = max(1, min($maxLimit, $limit));

        try {
            $exitCode = Artisan::call('whatsapp:sync-contacts', [
                '--limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            Log::error('Recent contacts sync failed', [
                'limit' => $limit,
                'user_id' => auth()->id(),
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
            ]);

            if ($this->wantsJson($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Recent contacts sync failed. Check Laravel logs for details.',
                ], 500);
            }

            return redirect()
                ->route('chats.index')
                ->with('error', 'Recent contacts sync failed. Please try again in a moment.');
        }

        $output = trim(Artisan::output());
        Log::info('Recent contacts sync completed', [
            'limit' => $limit,
            'user_id' => auth()->id(),
            'exit_code' => $exitCode,
            'output' => $output,
        ]);

        $outputLower = strtolower($output);
        $hadApiIssue = str_contains($outputLower, 'skipped') || str_contains($outputLower, 'failed');
        $message = $hadApiIssue
            ? 'Sync Inbox checked for recent contacts. No new chats were returned.'
            : ($output !== '' ? $output : "Synced last {$limit} recent mobiles.");

        if ($this->wantsJson($request)) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'limit' => $limit,
                    'output' => $output,
                    'api_issue_logged' => $hadApiIssue,
                ],
            ]);
        }

        if ($hadApiIssue) {
            return redirect()
                ->route('chats.index')
                ->with('status', 'Sync Inbox checked for recent contacts.');
        }

        return redirect()
            ->route('chats.index')
            ->with('status', $message);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson()
            || str_contains((string) $request->header('Accept'), 'application/json')
            || $request->ajax();
    }
}
