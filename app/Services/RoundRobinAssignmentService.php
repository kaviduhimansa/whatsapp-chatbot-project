<?php

namespace App\Services;

<<<<<<< HEAD
use App\Models\ChatQueue;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RoundRobinAssignmentService
{
    public const DEFAULT_MAX_CAPACITY = 5;

    /**
     * Get online agents who have available capacity (< maxCapacity active chats)
     * ordered by workload (lowest active count first, then oldest last_seen_at).
     *
     * @param int $maxCapacity
     * @return Collection<int, User>
     */
    public function getEligibleAgents(int $maxCapacity = self::DEFAULT_MAX_CAPACITY): Collection
    {
        return User::onlineAndActive()
            ->withCount(['contacts as active_count' => function ($q) {
                $q->where('human_handoff_status', '!=', 'resolved')
                  ->where(function ($sub) {
                      $sub->whereNull('last_read_at')
                          ->orWhereIn('human_handoff_status', ['active', 'assigned_to_agent']);
                  });
            }])
            ->groupBy('users.id')
            ->having('active_count', '<', $maxCapacity)
            ->orderBy('active_count', 'asc')
            ->orderBy('last_seen_at', 'asc')
            ->get();
    }

    /**
     * Capacity-weighted selection: Find the online agent with the lowest current workload.
     * Ties are broken by last_seen_at ascending (agent waiting longest).
     *
     * @param int $maxCapacity
     * @return User|null
     */
    public function getNextAvailableAgent(int $maxCapacity = self::DEFAULT_MAX_CAPACITY): ?User
    {
        return User::onlineAndActive()
            ->withCount(['contacts as active_count' => function ($q) {
                $q->where('human_handoff_status', '!=', 'resolved')
                  ->where(function ($sub) {
                      $sub->whereNull('last_read_at')
                          ->orWhereIn('human_handoff_status', ['active', 'assigned_to_agent']);
                  });
            }])
            ->groupBy('users.id')
            ->having('active_count', '<', $maxCapacity)
            ->orderBy('active_count', 'asc')
            ->orderBy('last_seen_at', 'asc')
            ->first();
    }

    /**
     * Assign a contact to the agent with lowest current workload if one has available capacity.
     *
     * @param Contact $contact
     * @param int $maxCapacity
     * @return User|null
     */
    public function assignNextAgent(Contact $contact, int $maxCapacity = self::DEFAULT_MAX_CAPACITY): ?User
    {
        $agent = $this->getNextAvailableAgent($maxCapacity);

        if (!$agent) {
            Log::info("No eligible agent with available capacity (< {$maxCapacity}) found for contact #{$contact->id}");
            return null;
        }

        $now = now();
        $contact->assigned_agent_id = $agent->id;
        $contact->human_handoff_assigned_user_id = $agent->id;
        $contact->human_handoff_assigned_at = $now;
        $contact->human_handoff_active = true;
        $contact->human_handoff_status = 'assigned_to_agent';
        $contact->bot_paused = true;
        $contact->save();

        // Remove from queue once successfully assigned to an active agent
        ChatQueue::where('contact_id', $contact->id)->delete();

        Log::info("Assigned contact #{$contact->id} to agent #{$agent->id} (workload: {$agent->active_count})");

        return $agent;
    }

    /**
     * Assign a contact using capacity-weighted routing (alias for assignNextAgent).
     *
     * @param Contact $contact
     * @param int $maxCapacity
     * @return User|null
     */
    public function assign(Contact $contact, int $maxCapacity = self::DEFAULT_MAX_CAPACITY): ?User
    {
        return $this->assignNextAgent($contact, $maxCapacity);
    }

    /**
     * Alias for assignNextAgent — used by the inactivity reassignment command
     * and any caller that prefers this more explicit name.
     *
     * @param Contact $contact
     * @param int $maxCapacity
     * @return User|null
     */
    public function assignAgent(Contact $contact, int $maxCapacity = self::DEFAULT_MAX_CAPACITY): ?User
    {
        return $this->assignNextAgent($contact, $maxCapacity);
    }

    /**
     * Pull the oldest item from chat_queues (FIFO) and assign it to an agent
     * when they finish a chat or release a lock.
     *
     * @param User|int $agent
     * @param int $maxCapacity
     * @return Contact|null
     */
    public function processNextQueueItemForAgent(User|int $agent, int $maxCapacity = self::DEFAULT_MAX_CAPACITY): ?Contact
    {
        $agentModel = $agent instanceof User ? $agent : User::find($agent);
        if (!$agentModel) {
            return null;
        }

        if (!$agentModel->hasAvailableCapacity($maxCapacity)) {
            Log::info("Agent #{$agentModel->id} is at capacity ({$agentModel->activeChatsCount()}/{$maxCapacity}), cannot dequeue next chat.");
            return null;
        }

        // Pull oldest item from chat_queues (FIFO)
        $queueItem = ChatQueue::fifo()->first();
        if (!$queueItem) {
            return null;
        }

        $contact = $queueItem->contact;
        if (!$contact) {
            $queueItem->delete();
            return null;
        }

        $now = now();
        $contact->assigned_agent_id = $agentModel->id;
        $contact->human_handoff_assigned_user_id = $agentModel->id;
        $contact->human_handoff_assigned_at = $now;
        $contact->human_handoff_active = true;
        $contact->human_handoff_status = 'assigned_to_agent';
        $contact->bot_paused = true;
        $contact->save();

        // Delete from queue upon assignment
        $queueItem->delete();

        Log::info("Dequeued contact #{$contact->id} and assigned to agent #{$agentModel->id} upon release (FIFO)");

        return $contact;
=======
use App\Models\Contact;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RoundRobinAssignmentService
{
    public function assignIfUnassigned(Contact $contact): ?User
    {
        return DB::transaction(function () use ($contact): ?User {
            // Lock the contact row within the transaction to prevent race conditions
            $contact = Contact::query()
                ->whereKey($contact->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // If an agent is already assigned, do not assign again
            if ($contact->assigned_agent_id !== null) {
                return $contact->assignedAgent;
            }

            $now = Carbon::now();
            $startTime = Carbon::createFromTimeString('08:00:00');
            $endTime = Carbon::createFromTimeString('17:00:00');

            // Office hours check (08:00 AM - 05:00 PM)
            if ($now->between($startTime, $endTime)) {

                // Select the oldest or least recently assigned admin who is online via heartbeat
                // and active within the last 5 minutes
                $nextAdmin = User::where('is_admin', true)
                    ->whereNotNull('last_seen_at')
                    ->where('last_seen_at', '>=', $now->copy()->subMinutes(5)) // Heartbeat active window
                    ->orderByRaw('last_activity_at IS NULL DESC')
                    ->orderBy('last_activity_at', 'asc')
                    ->first();

                if ($nextAdmin) {
                    $contact->update([
                        'assigned_agent_id' => $nextAdmin->id,
                        'locked_by_user_id' => $nextAdmin->id, // Existing chat lock mechanism
                        'locked_at' => $now,
                    ]);

                    $nextAdmin->update([
                        'last_activity_at' => $now,
                    ]);

                    return $nextAdmin;
                }
            }

            return null;
        });
>>>>>>> 266c7ae6e676e57dab7f1f2bf7b346745e5a1e4c
    }
}
