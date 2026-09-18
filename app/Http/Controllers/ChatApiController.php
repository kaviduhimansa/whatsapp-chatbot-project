<?php

namespace App\Http\Controllers;

use App\Models\ChatQueue;
use App\Models\Contact;
use App\Models\User;
use App\Services\ChatConversationService;
use App\Services\RoundRobinAssignmentService;
use App\Services\SltWhatsappClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatApiController extends Controller
{
    public function messages(Contact $contact, SltWhatsappClient $client, ChatConversationService $conversation)
    {
        // Each API row can now split into two bubbles (incoming + reply).
        // Keep ~5 recent conversation pairs visible in UI.
        $limit = 10;
        // Pull a larger window to keep ordering stable before slicing.
        $apiLimit = 50;

        try {
            $payload = $client->getMessages($contact->mobile, $apiLimit);
            $apiMessages = $conversation->normalizeApiMessages($payload);
        } catch (\Throwable $e) {
            $this->logChatApiError('messages.fetch_failed', $contact, $e, [
                'mobile' => $contact->mobile,
                'api_limit' => $apiLimit,
            ]);

            return response()->json([
                'messages' => [],
                'error' => 'Failed to fetch messages from SLT API.',
            ], 502);
        }

        $localOutgoing = $conversation->mapLocalOutgoingMessages($contact->messages()
            ->where('direction', 'out')
            ->whereNotNull('sent_at')
            ->orderBy('sent_at')
            ->limit(200)
            ->get());

        $messages = $conversation->mergeMessages($apiMessages, $localOutgoing);
        $conversation->syncContactState($contact, $messages);
        $contact->load('humanHandoffAssignedTo');

        return response()->json([
            'messages' => array_slice($messages, -$limit),
            'handoff' => $conversation->handoffPayload($contact),
        ]);
    }

    public function sync(Contact $contact)
    {
        // Kept for frontend compatibility. Messages are fetched live from API.
        $contact->update(['last_synced_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function syncAll(Request $request)
    {
        // Kept for frontend compatibility. No DB message sync is performed.
        return response()->json(['ok' => true]);
    }

    public function markRead(Contact $contact)
    {
        $contact->update([
            'last_read_at' => now(),
            'unread_message_count' => 0,
        ]);

        return response()->json(['ok' => true]);
    }

    public function send(
        Contact $contact,
        Request $request,
        SltWhatsappClient $client,
        ChatConversationService $conversation
    ) {
        // Enforce chat lock (prevents multiple admins replying at the same time)
        $lockOk = DB::transaction(function () use ($contact) {
            /** @var Contact $c */
            $c = Contact::whereKey($contact->id)->lockForUpdate()->firstOrFail();

            // Clear stale locks
            if ($c->locked_by_user_id && $c->isLockExpired()) {
                $c->locked_by_user_id = null;
                $c->locked_at = null;
                $c->save();
            }

            // If locked by someone else, reject
            if ($c->locked_by_user_id && $c->locked_by_user_id !== auth()->id()) {
                $c->load('lockedBy');

                return ['ok' => false, 'locked_by' => $c->lockedBy?->name];
            }

            // If unlocked or locked by me, refresh lock
            $c->locked_by_user_id = auth()->id();
            $c->locked_at = now();
            $c->save();

            return ['ok' => true];
        });

        if (!$lockOk['ok']) {
            return response()->json([
                'ok' => false,
                'error' => 'Chat is locked by ' . ($lockOk['locked_by'] ?? 'another admin') . '. Please wait.',
            ], 423);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $replyToUuid = $this->latestInboundUuid($client, $conversation, $contact->mobile);
        } catch (\Throwable $e) {
            $this->logChatApiError('messages.inbound_uuid_failed', $contact, $e, [
                'mobile' => $contact->mobile,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'Failed to load inbound messages from SLT API.',
            ], 502);
        }

        if (!$replyToUuid) {
            return response()->json([
                'ok' => false,
                'error' => 'No inbound message uuid found from API for this contact.',
            ], 422);
        }

        try {
            $replyPayload = $client->reply($replyToUuid, $contact->mobile, $data['message']);
        } catch (\Throwable $e) {
            $this->logChatApiError('messages.reply_failed', $contact, $e, [
                'mobile' => $contact->mobile,
                'reply_to_uuid' => $replyToUuid,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'Failed to send reply via SLT API.',
            ], 502);
        }

        $replyError = $this->extractReplyError($replyPayload);
        if ($replyError) {
            return response()->json([
                'ok' => false,
                'error' => $replyError,
            ], 422);
        }

        $this->storeLocalOutgoingMessage($contact, $data['message'], $replyPayload);
        $conversation->assignHumanHandoff($contact, (int) auth()->id());

        return response()->json(['ok' => true]);
    }

    private function latestInboundUuid(
        SltWhatsappClient $client,
        ChatConversationService $conversation,
        string $mobile
    ): ?string {
        $payload = $client->getMessages($mobile, 100);
        $messages = $conversation->normalizeApiMessages($payload);

        return $conversation->latestInboundUuid($messages);
    }

    private function extractReplyError(array $payload): ?string
    {
        $status = $payload['status'] ?? null;
        if (is_string($status)) {
            $text = trim($status);
            if ($text !== '' && str_starts_with(strtolower($text), 'error')) {
                return $text;
            }
        }

        $error = $payload['error'] ?? null;
        if (is_string($error) && trim($error) !== '') {
            return trim($error);
        }

        if (is_array($error)) {
            $message = trim((string) ($error['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return null;
    }

    private function storeLocalOutgoingMessage(Contact $contact, string $body, array $replyPayload): void
    {
        $uuid = $this->extractOutgoingUuid($replyPayload) ?: (string) Str::uuid();

        $contact->messages()->updateOrCreate(
            ['uuid' => $uuid],
            [
                'direction' => 'out',
                'body' => $body,
                'sent_at' => now(),
                'raw' => $replyPayload,
            ]
        );
        $contact->touch();
    }

    /**
     * Agent takes over a human handoff request.
     * This marks the chat as being handled by an agent.
     */
    public function takeoverHandoff(Contact $contact, ChatConversationService $conversation)
    {
        $conversation->assignHumanHandoff($contact, (int) auth()->id());

        return response()->json([
            'ok' => true,
            'message' => 'Chat taken over successfully.',
            'handoff' => $conversation->handoffPayload($contact),
        ]);
    }

    /**
     * Resolve/close a human handoff request.
     */
    public function resolveHandoff(Contact $contact, ChatConversationService $conversation)
    {
        $conversation->resetHumanHandoff($contact, 'resolved');

        // Whenever an agent finishes a chat, pull the oldest item from chat_queues (FIFO) and assign it to that agent
        if ($userId = auth()->id()) {
            app(\App\Services\RoundRobinAssignmentService::class)->processNextQueueItemForAgent((int) $userId);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Chat resolved.',
            'handoff' => $conversation->handoffPayload($contact),
        ]);
    }

    /**
     * GET /api/queue/status
     * Returns current chat queue depth and per-agent capacity statistics.
     */
    public function queueStatus(RoundRobinAssignmentService $router): \Illuminate\Http\JsonResponse
    {
        $queueDepth = ChatQueue::count();

        $onlineAgents = User::onlineAndActive()
            ->withCount(['contacts as active_count' => function ($q) {
                $q->whereIn('human_handoff_status', ['active', 'assigned_to_agent']);
            }])
            ->groupBy('users.id')
            ->get();

        $maxCapacity = RoundRobinAssignmentService::DEFAULT_MAX_CAPACITY;

        $agentStats = $onlineAgents->map(fn (User $u) => [
            'id'              => $u->id,
            'name'            => $u->name,
            'active_chats'    => $u->active_count,
            'capacity'        => $maxCapacity,
            'available_slots' => max(0, $maxCapacity - $u->active_count),
            'is_available'    => $u->active_count < $maxCapacity,
        ]);

        $totalOnline    = $onlineAgents->count();
        $availableCount = $agentStats->where('is_available', true)->count();
        $totalSlots     = $agentStats->sum('available_slots');

        return response()->json([
            'queue_depth'        => $queueDepth,
            'online_agents'      => $totalOnline,
            'available_agents'   => $availableCount,
            'busy_agents'        => $totalOnline - $availableCount,
            'total_open_slots'   => $totalSlots,
            'agents'             => $agentStats->values(),
        ]);
    }

    /**
     * Resume bot automation for this chat.
     */
    public function resetHandoff(Contact $contact, ChatConversationService $conversation)
    {
        $conversation->resetHumanHandoff($contact, 'resume');

        return response()->json([
            'ok' => true,
            'message' => 'Bot resumed.',
            'handoff' => $conversation->handoffPayload($contact),
        ]);
    }

    private function extractOutgoingUuid(array $payload): ?string
    {
        $candidates = [
            $payload['status']['messages'][0]['id'] ?? null,
            $payload['message_id'] ?? null,
            $payload['reply_id'] ?? null,
            $payload['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function logChatApiError(string $event, Contact $contact, \Throwable $e, array $extra = []): void
    {
        Log::error("Chat API error: {$event}", array_merge([
            'contact_id' => $contact->id,
            'contact_mobile' => $contact->mobile,
            'user_id' => auth()->id(),
            'exception_class' => get_class($e),
            'exception_message' => $e->getMessage(),
        ], $extra));
    }
}
