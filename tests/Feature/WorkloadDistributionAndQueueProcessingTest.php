<?php

namespace Tests\Feature;

use App\Models\ChatQueue;
use App\Models\Contact;
use App\Models\User;
use App\Services\RoundRobinAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkloadDistributionAndQueueProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_capacity_weighted_selection_routes_to_agent_with_lowest_workload(): void
    {
        // Agent 1 has 3 active chats
        $agentHeavy = User::factory()->create([
            'name' => 'Agent Heavy',
            'is_admin' => true,
            'last_seen_at' => now()->subMinutes(2),
            'last_activity_at' => now()->subMinutes(2),
        ]);

        for ($i = 1; $i <= 3; $i++) {
            Contact::create([
                'mobile' => "9477001000{$i}",
                'name' => "Client {$i}",
                'assigned_agent_id' => $agentHeavy->id,
                'human_handoff_status' => 'assigned_to_agent',
            ]);
        }

        // Agent 2 has 1 active chat
        $agentLight = User::factory()->create([
            'name' => 'Agent Light',
            'is_admin' => true,
            'last_seen_at' => now()->subMinutes(1),
            'last_activity_at' => now()->subMinutes(1),
        ]);

        Contact::create([
            'mobile' => '94770020001',
            'name' => 'Light Client 1',
            'assigned_agent_id' => $agentLight->id,
            'human_handoff_status' => 'assigned_to_agent',
        ]);

        $service = app(RoundRobinAssignmentService::class);

        $newContact = Contact::create([
            'mobile' => '94779991111',
            'name' => 'New Incoming Client',
            'human_handoff_status' => 'needs_human',
        ]);

        // Should route to Agent Light (lowest current workload: 1 vs 3)
        $assigned = $service->assignNextAgent($newContact);

        $this->assertNotNull($assigned);
        $this->assertSame($agentLight->id, $assigned->id);
        $this->assertSame($agentLight->id, $newContact->fresh()->assigned_agent_id);
    }

    public function test_capacity_weighted_selection_breaks_ties_by_last_seen_at_ascending(): void
    {
        // Both agents have 1 active chat
        // Agent A was last seen 4 minutes ago (waiting longer)
        $agentA = User::factory()->create([
            'name' => 'Agent Waiting Longer',
            'is_admin' => true,
            'last_seen_at' => now()->subMinutes(4),
            'last_activity_at' => now()->subMinutes(4),
        ]);

        Contact::create([
            'mobile' => '94770030001',
            'name' => 'Client A',
            'assigned_agent_id' => $agentA->id,
            'human_handoff_status' => 'assigned_to_agent',
        ]);

        // Agent B was last seen 1 minute ago
        $agentB = User::factory()->create([
            'name' => 'Agent Recent',
            'is_admin' => true,
            'last_seen_at' => now()->subMinutes(1),
            'last_activity_at' => now()->subMinutes(1),
        ]);

        Contact::create([
            'mobile' => '94770040001',
            'name' => 'Client B',
            'assigned_agent_id' => $agentB->id,
            'human_handoff_status' => 'assigned_to_agent',
        ]);

        $service = app(RoundRobinAssignmentService::class);

        $incoming = Contact::create([
            'mobile' => '94779992222',
            'name' => 'Tie Break Client',
            'human_handoff_status' => 'needs_human',
        ]);

        // Tied workload (1 vs 1): Agent A has older last_seen_at, so selected first
        $assigned = $service->assignNextAgent($incoming);

        $this->assertNotNull($assigned);
        $this->assertSame($agentA->id, $assigned->id);
    }

    public function test_lock_release_pulls_oldest_queued_chat_and_assigns_to_agent(): void
    {
        $agent = User::factory()->create([
            'is_admin' => true,
            'last_seen_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Currently locked contact
        $lockedContact = Contact::create([
            'mobile' => '94770050001',
            'name' => 'Locked Contact',
            'locked_by_user_id' => $agent->id,
            'locked_at' => now(),
        ]);

        // Queued contacts in FIFO order
        $queuedContactOld = Contact::create([
            'mobile' => '94770050002',
            'name' => 'Old Queued Contact',
            'human_handoff_status' => 'needs_human',
        ]);

        $queuedContactNew = Contact::create([
            'mobile' => '94770050003',
            'name' => 'New Queued Contact',
            'human_handoff_status' => 'needs_human',
        ]);

        ChatQueue::create([
            'contact_id' => $queuedContactOld->id,
            'priority' => 0,
            'queued_at' => now()->subMinutes(10),
        ]);

        ChatQueue::create([
            'contact_id' => $queuedContactNew->id,
            'priority' => 0,
            'queued_at' => now()->subMinutes(2),
        ]);

        // Agent releases lock on first contact
        $response = $this->actingAs($agent)->post("/chats/{$lockedContact->id}/lock/release");
        $response->assertOk();

        // Lock released
        $this->assertNull($lockedContact->fresh()->locked_by_user_id);

        // Oldest queued contact should now be assigned to this agent
        $queuedContactOld->refresh();
        $this->assertSame($agent->id, $queuedContactOld->assigned_agent_id);
        $this->assertSame('assigned_to_agent', $queuedContactOld->human_handoff_status);

        // Old contact removed from chat_queues
        $this->assertDatabaseMissing('chat_queues', ['contact_id' => $queuedContactOld->id]);

        // New contact still waiting in queue
        $this->assertDatabaseHas('chat_queues', ['contact_id' => $queuedContactNew->id]);
    }

    public function test_finishing_chat_pulls_oldest_queued_chat_and_assigns_to_agent(): void
    {
        $agent = User::factory()->create([
            'is_admin' => true,
            'last_seen_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Active contact being handled
        $activeContact = Contact::create([
            'mobile' => '94770060001',
            'name' => 'Finishing Contact',
            'assigned_agent_id' => $agent->id,
            'human_handoff_assigned_user_id' => $agent->id,
            'human_handoff_status' => 'assigned_to_agent',
            'human_handoff_active' => true,
        ]);

        // Contact waiting in queue
        $waitingContact = Contact::create([
            'mobile' => '94770060002',
            'name' => 'Waiting In Queue',
            'human_handoff_status' => 'needs_human',
        ]);

        ChatQueue::create([
            'contact_id' => $waitingContact->id,
            'priority' => 0,
            'queued_at' => now()->subMinutes(5),
        ]);

        // Agent resolves active contact
        $response = $this->actingAs($agent)->post("/chats/{$activeContact->id}/handoff/resolve");
        $response->assertOk();

        // Active contact is now resolved
        $this->assertSame('resolved', $activeContact->fresh()->human_handoff_status);

        // Waiting contact was dequeued and assigned to agent
        $waitingContact->refresh();
        $this->assertSame($agent->id, $waitingContact->assigned_agent_id);
        $this->assertSame('assigned_to_agent', $waitingContact->human_handoff_status);
        $this->assertDatabaseMissing('chat_queues', ['contact_id' => $waitingContact->id]);
    }
}
