<?php

namespace Tests\Feature;

use App\Models\ChatQueue;
use App\Models\Contact;
use App\Models\User;
use App\Services\RoundRobinAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Integration tests for:
 *   Test 1 — Agent capacity cap at 5 chats
 *   Test 2 — Chat moves to chat_queues when all agents are at capacity
 *   Test 3 — 5-minute inactive chat reassignment
 *   Test 4 — /api/queue/status endpoint
 */
class ChatQueueAndAssignmentTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Create an online, active agent ready to receive chats. */
    private function makeOnlineAgent(string $name = 'Agent'): User
    {
        return User::factory()->create([
            'name'             => $name,
            'is_admin'         => true,
            'last_seen_at'     => now(),
            'last_activity_at' => now(),
        ]);
    }

    /** Assign N active contacts to an agent (fills workload). */
    private function fillAgent(User $agent, int $count = 5): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Contact::create([
                'mobile'                         => "94770{$agent->id}0{$i}000",
                'name'                           => "Client {$i} of {$agent->name}",
                'assigned_agent_id'              => $agent->id,
                'human_handoff_assigned_user_id' => $agent->id,
                'human_handoff_status'           => 'assigned_to_agent',
                'human_handoff_active'           => true,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // TEST 1 — Agent capacity cap at 5 chats
    // ------------------------------------------------------------------

    public function test_capacity_is_zero_for_fresh_agent(): void
    {
        $agent = $this->makeOnlineAgent('Fresh');
        $this->assertSame(0, $agent->activeChatsCount());
        $this->assertTrue($agent->hasAvailableCapacity(5));
    }

    public function test_capacity_cap_enforced_at_5(): void
    {
        $agent = $this->makeOnlineAgent('Cap Agent');
        $this->fillAgent($agent, 4);

        $this->assertSame(4, $agent->activeChatsCount());
        $this->assertTrue($agent->hasAvailableCapacity(5));

        // 5th chat hits the cap exactly
        Contact::create([
            'mobile'               => '94770055555',
            'name'                 => 'Fifth Client',
            'assigned_agent_id'    => $agent->id,
            'human_handoff_status' => 'assigned_to_agent',
            'human_handoff_active' => true,
        ]);

        $this->assertSame(5, $agent->activeChatsCount());
        $this->assertFalse($agent->hasAvailableCapacity(5));
    }

    public function test_round_robin_excludes_agents_at_capacity(): void
    {
        $busy      = $this->makeOnlineAgent('Busy Agent');
        $available = $this->makeOnlineAgent('Available Agent');

        $this->fillAgent($busy, 5);   // maxed out
        $this->fillAgent($available, 2); // still has room

        $service   = new RoundRobinAssignmentService();
        $eligible  = $service->getEligibleAgents(5);

        $this->assertCount(1, $eligible);
        $this->assertSame($available->id, $eligible->first()->id);

        // Incoming chat must go to the available agent
        $contact = Contact::create([
            'mobile' => '94779999999', 'name' => 'Incoming', 'human_handoff_status' => 'needs_human',
        ]);
        $assigned = $service->assignNextAgent($contact, 5);

        $this->assertNotNull($assigned);
        $this->assertSame($available->id, $assigned->id);
        $contact->refresh();
        $this->assertSame($available->id, $contact->assigned_agent_id);
        $this->assertSame('assigned_to_agent', $contact->human_handoff_status);
    }

    public function test_assignment_returns_null_when_all_agents_full(): void
    {
        $agent = $this->makeOnlineAgent('Full Agent');
        $this->fillAgent($agent, 5);

        $service = new RoundRobinAssignmentService();
        $this->assertTrue($service->getEligibleAgents(5)->isEmpty());

        $contact  = Contact::create(['mobile' => '94778888888', 'name' => 'Waiting', 'human_handoff_status' => 'needs_human']);
        $assigned = $service->assignNextAgent($contact, 5);

        $this->assertNull($assigned);
        $contact->refresh();
        $this->assertNull($contact->assigned_agent_id);
        $this->assertSame('needs_human', $contact->human_handoff_status);
    }

    // ------------------------------------------------------------------
    // TEST 2 — Chat moves to chat_queues when all agents are at capacity
    // ------------------------------------------------------------------

    public function test_chat_pushed_to_queue_when_all_agents_at_capacity(): void
    {
        $a1 = $this->makeOnlineAgent('Alpha');
        $a2 = $this->makeOnlineAgent('Beta');
        $this->fillAgent($a1, 5);
        $this->fillAgent($a2, 5);

        $incoming = Contact::create(['mobile' => '94771111111', 'name' => 'Queued Customer', 'human_handoff_status' => 'needs_human']);

        $service = new RoundRobinAssignmentService();
        $result  = $service->assignNextAgent($incoming, 5);
        $this->assertNull($result);

        // ContactController/WhatsappSyncContacts would enqueue at this point
        ChatQueue::enqueue($incoming->id);

        $this->assertDatabaseHas('chat_queues', ['contact_id' => $incoming->id]);
        $this->assertSame(1, ChatQueue::count());

        // Idempotency — second enqueue must not create a duplicate
        ChatQueue::enqueue($incoming->id);
        $this->assertSame(1, ChatQueue::count());
    }

    public function test_queue_drained_fifo_when_agent_releases_chat(): void
    {
        $agent = $this->makeOnlineAgent('Drain Agent');
        $this->fillAgent($agent, 5);

        // Enqueue 3 contacts in chronological order
        $contacts = [];
        foreach (['First', 'Second', 'Third'] as $idx => $label) {
            $c = Contact::create(['mobile' => "9477888000{$idx}", 'name' => $label, 'human_handoff_status' => 'needs_human']);
            // Stagger queued_at to guarantee FIFO ordering
            ChatQueue::create(['contact_id' => $c->id, 'priority' => 0, 'queued_at' => now()->addSeconds($idx)]);
            $contacts[] = $c;
        }

        $this->assertSame(3, ChatQueue::count());

        // Free one slot
        Contact::where('assigned_agent_id', $agent->id)->first()->update([
            'human_handoff_status' => 'resolved',
            'assigned_agent_id'    => null,
        ]);

        $service  = new RoundRobinAssignmentService();
        $dequeued = $service->processNextQueueItemForAgent($agent, 5);

        $this->assertNotNull($dequeued);
        $this->assertSame($contacts[0]->id, $dequeued->id, 'FIFO: first-queued contact should be served first');

        $dequeued->refresh();
        $this->assertSame($agent->id, $dequeued->assigned_agent_id);
        $this->assertSame('assigned_to_agent', $dequeued->human_handoff_status);
        $this->assertSame(2, ChatQueue::count());
        $this->assertDatabaseMissing('chat_queues', ['contact_id' => $contacts[0]->id]);
    }

    // ------------------------------------------------------------------
    // TEST 3 — 5-minute inactive chat reassignment
    // ------------------------------------------------------------------

    public function test_stale_chat_reassigned_to_free_agent(): void
    {
        $busy = $this->makeOnlineAgent('Busy');
        $free = $this->makeOnlineAgent('Free');

        // Create a contact normally then back-date updated_at via raw SQL
        // (Eloquent auto-sets updated_at on save, so we must bypass it)
        $contact = Contact::create([
            'mobile'                         => '94772222222',
            'name'                           => 'Stale Customer',
            'assigned_agent_id'              => $busy->id,
            'human_handoff_assigned_user_id' => $busy->id,
            'human_handoff_status'           => 'assigned_to_agent',
            'human_handoff_active'           => true,
        ]);
        \Illuminate\Support\Facades\DB::table('contacts')
            ->where('id', $contact->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $exitCode = Artisan::call('chats:reassign-inactive-agent-chats', ['--minutes' => 5]);
        $this->assertSame(0, $exitCode);

        $contact->refresh();
        $this->assertSame($free->id, $contact->assigned_agent_id,
            'Stale chat should be reassigned to the free agent');
        $this->assertSame('assigned_to_agent', $contact->human_handoff_status);
    }

    public function test_stale_chat_queued_when_no_agents_available(): void
    {
        $agent = $this->makeOnlineAgent('Full');
        // Fill to exactly 4 (not 5) then add the stale contact as the 5th,
        // so the agent stays at capacity even after the stale chat is unassigned in-memory.
        $this->fillAgent($agent, 4);

        $contact = Contact::create([
            'mobile'                         => '94773333333',
            'name'                           => 'Overflow Stale',
            'assigned_agent_id'              => $agent->id,
            'human_handoff_assigned_user_id' => $agent->id,
            'human_handoff_status'           => 'assigned_to_agent',
            'human_handoff_active'           => true,
        ]);
        // Back-date so the command picks it up as stale
        \Illuminate\Support\Facades\DB::table('contacts')
            ->where('id', $contact->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        Artisan::call('chats:reassign-inactive-agent-chats', ['--minutes' => 5]);

        $contact->refresh();
        $this->assertNull($contact->assigned_agent_id);
        $this->assertSame('needs_human', $contact->human_handoff_status);
        $this->assertDatabaseHas('chat_queues', ['contact_id' => $contact->id]);
    }

    public function test_recent_chat_is_not_touched_before_5_minutes(): void
    {
        $agent = $this->makeOnlineAgent('Active Agent');

        // Use Contact::withoutTimestamps to prevent Eloquent overwriting updated_at
        $contact = Contact::withoutTimestamps(function () use ($agent) {
            return Contact::create([
                'mobile'                         => '94774444444',
                'name'                           => 'Fresh Customer',
                'assigned_agent_id'              => $agent->id,
                'human_handoff_assigned_user_id' => $agent->id,
                'human_handoff_status'           => 'assigned_to_agent',
                'human_handoff_active'           => true,
                'updated_at'                     => now()->subMinutes(2),
                'created_at'                     => now()->subMinutes(2),
            ]);
        });

        Artisan::call('chats:reassign-inactive-agent-chats', ['--minutes' => 5]);

        $contact->refresh();
        $this->assertSame($agent->id, $contact->assigned_agent_id);
        $this->assertSame('assigned_to_agent', $contact->human_handoff_status);
        $this->assertDatabaseMissing('chat_queues', ['contact_id' => $contact->id]);
    }

    public function test_stale_lock_released_after_inactivity_threshold(): void
    {
        $agent = $this->makeOnlineAgent('Locking Agent');

        // locked_at is a separate column (not updated_at), so this works directly
        $contact = Contact::create([
            'mobile'               => '94775555555',
            'name'                 => 'Locked Customer',
            'locked_by_user_id'    => $agent->id,
            'locked_at'            => now()->subMinutes(10),
            'human_handoff_status' => 'active',
        ]);

        Artisan::call('chats:reassign-inactive-agent-chats', ['--minutes' => 5]);

        $contact->refresh();
        $this->assertNull($contact->locked_by_user_id,
            'Stale lock should be cleared after 5 minutes');
        $this->assertNull($contact->locked_at);
    }

    // ------------------------------------------------------------------
    // TEST 4 — /api/queue/status endpoint
    // ------------------------------------------------------------------

    public function test_queue_status_returns_correct_payload(): void
    {
        $user = $this->makeOnlineAgent('API Agent');
        $this->fillAgent($user, 3); // 3 active, 2 slots remaining

        // 2 contacts in queue
        foreach (['Q1', 'Q2'] as $idx => $label) {
            $c = Contact::create(['mobile' => "9477600000{$idx}", 'name' => $label, 'human_handoff_status' => 'needs_human']);
            ChatQueue::enqueue($c->id);
        }

        $this->actingAs($user)
            ->getJson(route('api.queue.status'))
            ->assertOk()
            ->assertJsonStructure([
                'queue_depth', 'online_agents', 'available_agents',
                'busy_agents', 'total_open_slots',
                'agents' => [['id', 'name', 'active_chats', 'capacity', 'available_slots', 'is_available']],
            ])
            ->assertJson([
                'queue_depth'      => 2,
                'online_agents'    => 1,
                'available_agents' => 1,
                'busy_agents'      => 0,
                'total_open_slots' => 2,
            ]);
    }

    public function test_queue_status_endpoint_requires_auth(): void
    {
        $response = $this->getJson(route('api.queue.status'));
        $this->assertContains($response->status(), [302, 401, 403],
            'Unauthenticated access should be rejected');
    }

    public function test_queue_status_shows_zero_depth_when_queue_empty(): void
    {
        $user = $this->makeOnlineAgent('Empty Queue Agent');

        $this->actingAs($user)
            ->getJson(route('api.queue.status'))
            ->assertOk()
            ->assertJson(['queue_depth' => 0, 'online_agents' => 1]);
    }
}
