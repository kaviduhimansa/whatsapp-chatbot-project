<?php

namespace App\Console\Commands;

use App\Models\ChatQueue;
use App\Models\Contact;
use App\Services\ChatConversationService;
use App\Services\RoundRobinAssignmentService;
use App\Services\SltWhatsappClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WhatsappSyncContacts extends Command
{
    protected $signature = 'whatsapp:sync-contacts {--limit=40}';
    protected $description = 'Sync the most-recent active mobile numbers into contacts table';

    public function handle(
        SltWhatsappClient $client,
        ChatConversationService $conversation,
        RoundRobinAssignmentService $assignment
    ): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $messageLimit = max(1, (int) config('chat.state_sync_message_limit', 15));
        $cooldownSeconds = max(0, (int) config('chat.state_sync_cooldown_seconds', 20));

        try {
            $mobiles = $client->getRecentActiveMobiles($limit);
        } catch (\Throwable $e) {
            Log::warning('Recent contacts API sync skipped', [
                'limit' => $limit,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
            ]);

            $this->warn('Recent contacts sync skipped: ' . $this->shortError($e));
            return self::SUCCESS;
        }

        if (empty($mobiles)) {
            Log::info('Recent contacts API returned no mobiles', [
                'limit' => $limit,
            ]);

            $this->warn('No recent mobiles returned by API.');
            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;

        foreach ($mobiles as $mobile) {
            $contact = Contact::where('mobile', $mobile)->first();

            if (!$contact) {
                $contact = Contact::create([
                    'mobile' => $mobile,
                    'name' => $mobile,
                ]);
                $created++;
            } else {
                if (!$contact->name || $contact->name === $contact->mobile) {
                    $contact->name = $mobile;
                    $contact->save();
                }
                $updated++;
            }

            // Call Round-Robin assignment after if-else for all contacts
            app(\App\Services\RoundRobinAssignmentService::class)->assignIfUnassigned($contact);

            $this->syncConversationState($contact, $client, $conversation, $messageLimit, $cooldownSeconds);

            // If contact requires human handoff and has no assigned agent, attempt assignment or push to chat queue
            $contact->refresh();
            if (($contact->needs_human || $contact->human_handoff_active) && !$contact->assigned_agent_id) {
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
            }
        }

        Log::info('Recent contacts synced', [
            'created' => $created,
            'updated' => $updated,
            'total' => count($mobiles),
        ]);

        $this->info("Synced contacts. created={$created}, updated={$updated}, total=" . count($mobiles));
        return self::SUCCESS;
    }

    private function syncConversationState(
        Contact $contact,
        SltWhatsappClient $client,
        ChatConversationService $conversation,
        int $messageLimit,
        int $cooldownSeconds
    ): void {
        $cacheKey = "chat-state-sync:contact:{$contact->id}";

        if ($cooldownSeconds > 0 && !Cache::add($cacheKey, true, now()->addSeconds($cooldownSeconds))) {
            return;
        }

        try {
            $payload = $client->getMessages($contact->mobile, $messageLimit);
            $apiMessages = $conversation->normalizeApiMessages($payload);
            $localOutgoing = $conversation->mapLocalOutgoingMessages($contact->messages()
                ->where('direction', 'out')
                ->whereNotNull('sent_at')
                ->orderBy('sent_at')
                ->limit(50)
                ->get());

            $conversation->syncContactState($contact, $conversation->mergeMessages($apiMessages, $localOutgoing));
        } catch (\Throwable $e) {
            $this->warn("Skipped message state sync for {$contact->mobile}: " . $this->shortError($e));
        }
    }

    private function shortError(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message !== '') {
            return $message;
        }

        return class_basename($e);
    }
}
