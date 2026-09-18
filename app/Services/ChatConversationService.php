<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ChatConversationService
{
    private const STATUS_OPEN = 'open';
    private const STATUS_NEEDS_HUMAN = 'needs_human';
    private const STATUS_ASSIGNED = 'assigned_to_agent';
    private const STATUS_RESOLVED = 'resolved';

    public function normalizeApiMessages(array $payload): array
    {
        $items = $payload['messages']
            ?? $payload['data']
            ?? $payload['result']
            ?? $payload;

        if (!is_array($items)) {
            return [];
        }

        if ($this->looksLikeMessage($items)) {
            $items = [$items];
        }

        $messages = [];
        $seq = 0;

        $appendMessage = function (
            string $id,
            string $uuid,
            string $body,
            string $direction,
            ?string $sentAt,
            ?string $timeHint
        ) use (&$messages, &$seq): void {
            $seq++;
            $messages[] = [
                'id' => $id,
                'uuid' => $uuid,
                'direction' => $direction,
                'body' => trim($body),
                'sent_at' => $sentAt,
                'time_hint' => $timeHint,
                '_seq' => $seq,
            ];
        };

        $itemIndex = 0;
        foreach ($items as $m) {
            if (!is_array($m)) {
                continue;
            }

            $itemIndex++;
            $uuid = (string) ($m['uuid'] ?? $m['message_id'] ?? $m['id'] ?? '');
            $idSource = $m['id'] ?? ($uuid !== '' ? $uuid : "api_{$itemIndex}");
            $baseId = (string) $idSource;
            $primaryBody = trim((string) ($m['message'] ?? $m['text'] ?? $m['body'] ?? ''));
            $replyBody = trim((string) ($m['reply'] ?? $m['replymessage'] ?? ''));
            $sentAt = $this->extractIsoTimestamp($m);
            $timeHint = $this->extractTimeHint($m);
            $direction = $this->normalizeDirection($m);
            $hasDirectionalField = $this->hasDirectionalField($m);
            $messageUuid = $uuid !== '' ? $uuid : $baseId;

            if ($primaryBody !== '' && $replyBody !== '' && !$hasDirectionalField) {
                $appendMessage("{$baseId}_in", $messageUuid, $primaryBody, 'in', $sentAt, $timeHint);
                $appendMessage("{$baseId}_out", $messageUuid, $replyBody, 'out', $sentAt, $timeHint);
                continue;
            }

            if ($primaryBody !== '') {
                $primaryDirection = $hasDirectionalField ? $direction : 'in';
                $primaryId = $replyBody !== '' ? "{$baseId}_in" : $baseId;
                $appendMessage($primaryId, $messageUuid, $primaryBody, $primaryDirection, $sentAt, $timeHint);
            }

            if ($replyBody !== '') {
                $replyDirection = ($hasDirectionalField && $primaryBody === '') ? $direction : 'out';
                $replyId = $primaryBody !== '' ? "{$baseId}_out" : $baseId;
                $appendMessage($replyId, $messageUuid, $replyBody, $replyDirection, $sentAt, $timeHint);
            }
        }

        return $this->sortMessages($messages);
    }

    public function mapLocalOutgoingMessages(Collection $messages): array
    {
        return $messages
            ->map(function ($message) {
                return [
                    'id' => 'local_' . $message->id,
                    'uuid' => (string) ($message->uuid ?: ('local_' . $message->id)),
                    'direction' => 'out',
                    'body' => (string) ($message->body ?? ''),
                    'sent_at' => $message->sent_at
                        ? $message->sent_at->setTimezone((string) config('app.timezone', 'Asia/Colombo'))->toIso8601String()
                        : null,
                    'time_hint' => null,
                ];
            })
            ->filter(fn (array $message) => trim((string) ($message['body'] ?? '')) !== '')
            ->values()
            ->all();
    }

    public function mergeMessages(array ...$groups): array
    {
        $messages = [];
        $seq = 0;

        foreach ($groups as $group) {
            foreach ($group as $message) {
                if (!is_array($message)) {
                    continue;
                }

                $seq++;
                $message['_seq'] = $seq;
                $messages[] = $message;
            }
        }

        return $this->sortMessages($messages);
    }

    public function latestInboundUuid(array $messages): ?string
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            if (($message['direction'] ?? 'in') === 'in' && !empty($message['uuid'])) {
                return (string) $message['uuid'];
            }
        }

        return null;
    }

    public function syncContactState(Contact $contact, array $messages): void
    {
        $state = $this->buildContactState($contact, $messages);
        $contact->fill($state);

        if (!$contact->isDirty(array_keys($state))) {
            return;
        }

        $contact->save();
    }

    public function assignHumanHandoff(Contact $contact, ?int $userId = null, ?Carbon $at = null): void
    {
        $timestamp = $at ?? now();
        $updates = [
            'human_handoff_active' => true,
            'human_handoff_status' => self::STATUS_ASSIGNED,
            'bot_paused' => true,
            'human_handoff_requested_at' => $contact->human_handoff_requested_at ?? $timestamp,
        ];

        if ($userId) {
            $updates['human_handoff_assigned_user_id'] = $userId;
            $updates['human_handoff_assigned_at'] = $timestamp;
        }

        $contact->fill($updates);

        if (!$contact->isDirty(array_keys($updates))) {
            return;
        }

        $contact->save();
    }

    public function resetHumanHandoff(Contact $contact, ?string $action = 'resolved'): void
    {
        $status = $action === 'resume' ? self::STATUS_OPEN : self::STATUS_RESOLVED;

        $updates = [
            'human_handoff_active' => false,
            'human_handoff_status' => $status,
            'bot_paused' => false,
            'human_handoff_assigned_user_id' => null,
            'human_handoff_assigned_at' => null,
        ];

        $contact->fill($updates);
        $contact->save();
    }

    /**
     * Check if human handoff can be triggered again for this contact.
     * Returns true if customer requests human agent again after previous request was handled.
     */
    public function canTriggerHumanHandoff(Contact $contact, array $messages): bool
    {
        // If no existing handoff request, can always trigger
        if (!$contact->human_handoff_active && !$contact->human_handoff_requested_at) {
            return true;
        }

        // If handoff is active, check if there's a new trigger message
        $latestTrigger = $this->latestHumanHandoffTrigger($messages);
        if ($latestTrigger) {
            $triggerKey = $this->messageKey($latestTrigger);
            $existingKey = $contact->human_handoff_message_key;

            // If new trigger message is different from stored key, allow re-trigger
            if ($triggerKey && $triggerKey !== $existingKey) {
                return true;
            }
        }

        return false;
    }

    public function handoffPayload(Contact $contact): array
    {
        $contact->loadMissing('humanHandoffAssignedTo');
        $assignedUserId = $contact->human_handoff_assigned_user_id;

        return [
            'active' => (bool) $contact->is_bot_paused,
            'status' => $this->handoffStatus($contact),
            'needs_human' => (bool) $contact->needs_human,
            'bot_paused' => (bool) $contact->is_bot_paused,
            'requested_at' => optional($contact->human_handoff_requested_at)->toIso8601String(),
            'message_preview' => $contact->human_handoff_message_preview,
            'assigned_to' => $contact->humanHandoffAssignedTo?->name,
            'assigned_to_id' => $assignedUserId,
            'assigned_to_me' => $assignedUserId ? (int) $assignedUserId === (int) auth()->id() : false,
            'unread_count' => $contact->unread_count,
        ];
    }

    public function messageKey(array $message): string
    {
        $uuid = trim((string) ($message['uuid'] ?? ''));
        if ($uuid !== '') {
            return $uuid;
        }

        $fallback = implode('|', [
            (string) ($message['id'] ?? ''),
            (string) ($message['sent_at'] ?? ''),
            (string) ($message['direction'] ?? ''),
            trim((string) ($message['body'] ?? '')),
        ]);

        return sha1($fallback);
    }

    private function buildContactState(Contact $contact, array $messages): array
    {
        $latestMessage = null;
        $latestInboundMessage = null;
        $latestHumanHandoffTrigger = $this->latestHumanHandoffTrigger($messages);
        $unreadMessageCount = 0;

        foreach ($messages as $message) {
            $body = trim((string) ($message['body'] ?? ''));
            if ($body === '') {
                continue;
            }

            if (($message['direction'] ?? 'in') === 'in') {
                if ($this->isUnreadInboundMessage($contact, $message)) {
                    $unreadMessageCount++;
                }
            }
        }

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            $body = trim((string) ($message['body'] ?? ''));
            if ($body === '') {
                continue;
            }

            if ($latestMessage === null) {
                $latestMessage = $message;
            }

            if (($message['direction'] ?? 'in') === 'in') {
                $latestInboundMessage = $message;
                break;
            }
        }

        $humanHandoffState = $this->buildHumanHandoffState($contact, $latestHumanHandoffTrigger);

        return array_merge([
            'last_message_at' => $this->parseTimestamp($latestMessage['sent_at'] ?? null),
            'last_message_direction' => $latestMessage['direction'] ?? null,
            'last_message_preview' => $this->preview($latestMessage['body'] ?? null),
            'last_inbound_message_at' => $this->parseTimestamp($latestInboundMessage['sent_at'] ?? null),
            'last_inbound_message_key' => $latestInboundMessage ? $this->messageKey($latestInboundMessage) : null,
            'last_inbound_message_preview' => $this->preview($latestInboundMessage['body'] ?? null),
            'unread_message_count' => $unreadMessageCount,
        ], $humanHandoffState);
    }

    private function preview(?string $body): ?string
    {
        $text = trim((string) $body);
        if ($text === '') {
            return null;
        }

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return Str::limit($text, 140);
    }

    private function parseTimestamp(?string $value): ?Carbon
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildHumanHandoffState(Contact $contact, ?array $triggerMessage): array
    {
        $existingKey = trim((string) ($contact->human_handoff_message_key ?? ''));
        $triggerKey = $triggerMessage ? $this->messageKey($triggerMessage) : null;
        $triggeredNow = $triggerKey !== null && $triggerKey !== '' && $triggerKey !== $existingKey;
        $status = $this->handoffStatus($contact);

        if ($triggerMessage !== null && $triggeredNow) {
            return [
                'human_handoff_active' => true,
                'human_handoff_status' => self::STATUS_NEEDS_HUMAN,
                'bot_paused' => true,
                'human_handoff_requested_at' => $this->parseTimestamp($triggerMessage['sent_at'] ?? null) ?? now(),
                'human_handoff_message_key' => $triggerKey,
                'human_handoff_message_preview' => $this->preview($triggerMessage['body'] ?? null),
                'human_handoff_assigned_user_id' => null,
                'human_handoff_assigned_at' => null,
            ];
        }

        return [
            'human_handoff_active' => in_array($status, [self::STATUS_NEEDS_HUMAN, self::STATUS_ASSIGNED], true),
            'human_handoff_status' => $status,
            'bot_paused' => in_array($status, [self::STATUS_NEEDS_HUMAN, self::STATUS_ASSIGNED], true),
            'human_handoff_requested_at' => $contact->human_handoff_requested_at,
            'human_handoff_message_key' => $contact->human_handoff_message_key,
            'human_handoff_message_preview' => $contact->human_handoff_message_preview,
            'human_handoff_assigned_user_id' => $status === self::STATUS_ASSIGNED ? $contact->human_handoff_assigned_user_id : null,
            'human_handoff_assigned_at' => $status === self::STATUS_ASSIGNED ? $contact->human_handoff_assigned_at : null,
        ];
    }

    private function handoffStatus(Contact $contact): string
    {
        $status = trim((string) ($contact->human_handoff_status ?? ''));
        if (in_array($status, [self::STATUS_OPEN, self::STATUS_NEEDS_HUMAN, self::STATUS_ASSIGNED, self::STATUS_RESOLVED], true)) {
            return $status;
        }

        if ($contact->human_handoff_assigned_user_id) {
            return self::STATUS_ASSIGNED;
        }

        if ($contact->human_handoff_active || $contact->human_handoff_requested_at) {
            return self::STATUS_NEEDS_HUMAN;
        }

        return self::STATUS_OPEN;
    }

    private function latestHumanHandoffTrigger(array $messages): ?array
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            $body = trim((string) ($message['body'] ?? ''));
            if ($body === '' || ($message['direction'] ?? 'in') !== 'in') {
                continue;
            }

            if ($this->isHumanHandoffTrigger($body)) {
                return $message;
            }
        }

        return null;
    }

    private function isHumanHandoffTrigger(string $body): bool
    {
        $text = Str::lower(trim($body));
        if ($text === '') {
            return false;
        }

        foreach ((array) config('chat.human_handoff_keywords', []) as $keyword) {
            $needle = Str::lower(trim((string) $keyword));
            if ($needle !== '' && Str::contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isUnreadInboundMessage(Contact $contact, array $message): bool
    {
        if (($message['direction'] ?? 'in') !== 'in') {
            return false;
        }

        $body = trim((string) ($message['body'] ?? ''));
        if ($body === '') {
            return false;
        }

        if (!$contact->last_read_at) {
            return true;
        }

        $sentAt = $this->parseTimestamp($message['sent_at'] ?? null);
        if (!$sentAt) {
            return false;
        }

        return $sentAt->gt($contact->last_read_at);
    }

    private function sortMessages(array $messages): array
    {
        usort($messages, function (array $a, array $b) {
            $at = $a['sent_at'] ?? null;
            $bt = $b['sent_at'] ?? null;
            if (!$at && !$bt) {
                return ($a['_seq'] ?? 0) <=> ($b['_seq'] ?? 0);
            }
            if (!$at) {
                return -1;
            }
            if (!$bt) {
                return 1;
            }

            $cmp = strcmp((string) $at, (string) $bt);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($a['_seq'] ?? 0) <=> ($b['_seq'] ?? 0);
        });

        return array_map(function (array $message) {
            unset($message['_seq']);
            return $message;
        }, $messages);
    }

    private function normalizeDirection(array $message): string
    {
        if (isset($message['from_me'])) {
            return filter_var($message['from_me'], FILTER_VALIDATE_BOOLEAN) ? 'out' : 'in';
        }

        if (isset($message['fromMe'])) {
            return filter_var($message['fromMe'], FILTER_VALIDATE_BOOLEAN) ? 'out' : 'in';
        }

        $raw = strtolower((string) ($message['direction'] ?? $message['type'] ?? ''));
        if (in_array($raw, ['out', 'outgoing', 'sent', 'agent', 'reply'], true)) {
            return 'out';
        }
        if (in_array($raw, ['in', 'incoming', 'received', 'customer'], true)) {
            return 'in';
        }

        return 'in';
    }

    private function hasDirectionalField(array $message): bool
    {
        if (array_key_exists('from_me', $message) || array_key_exists('fromMe', $message)) {
            return true;
        }

        $raw = strtolower((string) ($message['direction'] ?? $message['type'] ?? ''));
        if ($raw === '') {
            return false;
        }

        return in_array($raw, [
            'out',
            'outgoing',
            'sent',
            'agent',
            'reply',
            'in',
            'incoming',
            'received',
            'customer',
        ], true);
    }

    private function extractIsoTimestamp(array $message): ?string
    {
        $tz = (string) config('app.timezone', 'Asia/Colombo');

        $ts = $message['timestamp']
            ?? $message['time']
            ?? $message['sent_at']
            ?? $message['created_at']
            ?? null;

        if (!$ts) {
            return null;
        }

        try {
            if (is_numeric($ts)) {
                $n = (int) $ts;

                if ($n > 9999999999) {
                    return Carbon::createFromTimestampMs($n, 'UTC')
                        ->setTimezone($tz)
                        ->toIso8601String();
                }

                return Carbon::createFromTimestamp($n, 'UTC')
                    ->setTimezone($tz)
                    ->toIso8601String();
            }

            $raw = trim((string) $ts);

            if ($this->isTimeOnlyTimestamp($raw)) {
                return null;
            }

            $hasOffset = (bool) preg_match(
                '/(Z|[+\-]\d{2}:?\d{2})$/i',
                $raw
            );

            $parsed = $hasOffset
                ? Carbon::parse($raw) // If an offset is present, respect it
                : Carbon::parse($raw, $tz); // If no offset is present, assume the current timezone ($tz / Asia/Colombo)

            return $parsed
                ->setTimezone($tz)
                ->toIso8601String();

        } catch (\Throwable $e) {
            return null;
        }
    }

    private function extractTimeHint(array $message): ?string
    {
        $ts = $message['timestamp']
            ?? $message['time']
            ?? $message['sent_at']
            ?? $message['created_at']
            ?? null;

        if (!$ts || is_numeric($ts)) {
            return null;
        }

        $raw = trim((string) $ts);
        if (!$this->isTimeOnlyTimestamp($raw)) {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('H:i');
        } catch (\Throwable $e) {
            return $raw;
        }
    }

    private function isTimeOnlyTimestamp(string $value): bool
    {
        $raw = trim($value);
        if ($raw === '') {
            return false;
        }

        return (bool) preg_match('/^\d{1,2}:\d{2}(:\d{2})?\s?(am|pm)?$/i', $raw);
    }

    private function looksLikeMessage(array $item): bool
    {
        return array_key_exists('uuid', $item)
            || array_key_exists('message', $item)
            || array_key_exists('text', $item)
            || array_key_exists('body', $item);
    }
}
