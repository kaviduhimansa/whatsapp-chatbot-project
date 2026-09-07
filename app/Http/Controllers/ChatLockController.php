<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\ChatConversationService;
use Illuminate\Support\Facades\DB;

class ChatLockController extends Controller
{
    public function status(Contact $contact, ChatConversationService $conversation)
    {
        $current = $this->loadFreshContactWithLockCleanup($contact->id);
        return response()->json($this->lockPayload($current, $conversation));
    }

    /**
     * Acquire or refresh a lock for this contact.
     * - If unlocked or stale: assigns lock to current user
     * - If locked by me: refreshes timestamp
     * - If locked by another (and not stale): returns locked info
     */
    public function acquire(Contact $contact, ChatConversationService $conversation)
    {
        $userId = auth()->id();

        $result = DB::transaction(function () use ($contact, $userId) {
            /** @var Contact $c */
            $c = Contact::whereKey($contact->id)->lockForUpdate()->firstOrFail();
            $this->clearStaleLock($c);

            if ($c->locked_by_user_id && $c->locked_by_user_id !== $userId) {
                $c->load('lockedBy');
                return ['state' => 'blocked', 'contact' => $c];
            }

            $c->locked_by_user_id = $userId;
            $c->locked_at = now();
            $c->human_handoff_active = true;
            $c->human_handoff_status = 'assigned_to_agent';
            $c->bot_paused = true;
            $c->human_handoff_requested_at = $c->human_handoff_requested_at ?? now();
            $c->human_handoff_assigned_user_id = $userId;
            $c->human_handoff_assigned_at = now();
            $c->save();
            $c->load(['lockedBy', 'humanHandoffAssignedTo']);

            return ['state' => 'acquired', 'contact' => $c];
        });

        $payload = $this->lockPayload($result['contact'], $conversation);
        if ($result['state'] === 'blocked') {
            return response()->json(array_merge($payload, [
                'ok' => false,
                'error' => 'Chat is locked by ' . ($payload['locked_by'] ?? 'another admin') . '.',
            ]), 423);
        }

        return response()->json(array_merge($payload, ['ok' => true]));
    }

    public function release(Contact $contact, ChatConversationService $conversation)
    {
        $userId = auth()->id();

        $result = DB::transaction(function () use ($contact, $userId) {
            /** @var Contact $c */
            $c = Contact::whereKey($contact->id)->lockForUpdate()->firstOrFail();
            $this->clearStaleLock($c);

            if (!$c->locked_by_user_id) {
                $c->load('lockedBy');
                return ['state' => 'already-released', 'contact' => $c];
            }

            if ($c->locked_by_user_id !== $userId) {
                $c->load('lockedBy');
                return ['state' => 'blocked', 'contact' => $c];
            }

            $c->locked_by_user_id = null;
            $c->locked_at = null;
            $c->save();
            $c->load('lockedBy');

            return ['state' => 'released', 'contact' => $c];
        });

        $payload = $this->lockPayload($result['contact'], $conversation);
        if ($result['state'] === 'blocked') {
            return response()->json(array_merge($payload, [
                'ok' => false,
                'error' => 'Chat is locked by ' . ($payload['locked_by'] ?? 'another admin') . '. You cannot release it.',
            ]), 423);
        }

        if ($result['state'] === 'released') {
            app(\App\Services\RoundRobinAssignmentService::class)->processNextQueueItemForAgent($userId);
        }

        return response()->json(array_merge($payload, [
            'ok' => true,
            'released' => $result['state'] === 'released',
        ]));
    }

    private function loadFreshContactWithLockCleanup(int $contactId): Contact
    {
        return DB::transaction(function () use ($contactId) {
            /** @var Contact $c */
            $c = Contact::whereKey($contactId)->lockForUpdate()->firstOrFail();
            $this->clearStaleLock($c);
            $c->load('lockedBy');
            return $c;
        });
    }

    private function clearStaleLock(Contact $contact): void
    {
        if ($contact->locked_by_user_id && $contact->isLockExpired()) {
            $contact->locked_by_user_id = null;
            $contact->locked_at = null;
            $contact->save();
        }
    }

    private function lockPayload(Contact $contact, ChatConversationService $conversation): array
    {
        $lockedById = $contact->locked_by_user_id;
        $contact->loadMissing('humanHandoffAssignedTo');

        return [
            'locked' => (bool) $lockedById,
            'locked_by' => $contact->lockedBy?->name,
            'locked_by_id' => $lockedById,
            'locked_by_me' => $lockedById ? (int) $lockedById === (int) auth()->id() : false,
            'ttl_seconds' => (int) config('chat.lock_ttl_seconds', 120),
            'locked_at' => optional($contact->locked_at)->toIso8601String(),
            'handoff' => $conversation->handoffPayload($contact),
        ];
    }
}
