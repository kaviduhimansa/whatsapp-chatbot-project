<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use App\Services\RoundRobinAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatCapacityLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_active_chats_count_calculates_correctly(): void
    {
        $agent = User::factory()->create([
            'is_admin' => true,
            'last_seen_at' => now(),
            'last_activity_at' => now(),
        ]);

        $this->assertSame(0, $agent->activeChatsCount());
        $this->assertTrue($agent->hasAvailableCapacity(5));

        // Create 3 active chats assigned to this agent
        for ($i = 1; $i <= 3; $i++) {
            Contact::create([
                'mobile' => "9477000100{$i}",
                'name' => "Customer {$i}",
                'assigned_agent_id' => $agent->id,
                'human_handoff_status' => 'assigned_to_agent',
                'human_handoff_active' => true,
            ]);
        }

        // Create 1 resolved chat (should NOT be counted in activeChatsCount)
        Contact::create([
            'mobile' => '94770001009',
            'name' => 'Resolved Customer',
            'assigned_agent_id' => $agent->id,
            'human_handoff_status' => 'resolved',
            'human_handoff_active' => false,
        ]);

        // Create 1 chat with status 'active' (supported alternate status flag)
        Contact::create([
            'mobile' => '94770001010',
            'name' => 'Active Flag Customer',
            'assigned_agent_id' => $agent->id,
            'human_handoff_status' => 'active',
            'human_handoff_active' => true,
        ]);

        // Total active: 3 ('assigned_to_agent') + 1 ('active') = 4
        $this->assertSame(4, $agent->activeChatsCount());
        $this->assertTrue($agent->hasAvailableCapacity(5));
    }

    public function test_user_has_available_capacity_enforces_limit_of_5(): void
    {
        $agent = User::factory()->create([
            'is_admin' => true,
            'last_seen_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Add 4 active chats
        for ($i = 1; $i <= 4; $i++) {
            Contact::create([
                'mobile' => "9477100000{$i}",
                'name' => "Customer {$i}",
                'assigned_agent_id' => $agent->id,
                'human_handoff_status' => 'assigned_to_agent',
            ]);
        }

        $this->assertSame(4, $agent->activeChatsCount());
        $this->assertTrue($agent->hasAvailableCapacity(5));

        // Add 5th active chat
        Contact::create([
            'mobile' => '94771000005',
            'name' => 'Customer 5',
            'assigned_agent_id' => $agent->id,
            'human_handoff_status' => 'assigned_to_agent',
        ]);

        $this->assertSame(5, $agent->activeChatsCount());
        // Capacity limit of 5 reached
        $this->assertFalse($agent->hasAvailableCapacity(5));
    }

    public function test_round_robin_filters_out_agents_with_capacity_gte_5(): void
    {
        // Agent 1: Online, but at max capacity (5 chats)
        $busyAgent = User::factory()->create([
            'name' => 'Busy Agent',
            'is_admin' => true,
            'last_seen_at' => now(),
            'last_activity_at' => now(),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            Contact::create([
                'mobile' => "9477200000{$i}",
                'name' => "Busy Client {$i}",
                'assigned_agent_id' => $busyAgent->id,
                'human_handoff_status' => 'assigned_to_agent',
            ]);
        }

        // Agent 2: Online, with available capacity (2 chats)
        $availableAgent = User::factory()->create([
            'name' => 'Available Agent',
            'is_admin' => true,
            'last_seen_at' => now(),
            'last_activity_at' => now(),
        ]);

        for ($i = 1; $i <= 2; $i++) {
            Contact::create([
                'mobile' => "9477300000{$i}",
                'name' => "Available Client {$i}",
                'assigned_agent_id' => $availableAgent->id,
                'human_handoff_status' => 'assigned_to_agent',
            ]);
        }

        $service = new RoundRobinAssignmentService();

        // Check eligible agents: Busy Agent must be filtered out by validation guard
        $eligible = $service->getEligibleAgents(5);
        $this->assertCount(1, $eligible);
        $this->assertSame($availableAgent->id, $eligible->first()->id);

        // Assign incoming unassigned contact
        $incomingContact = Contact::create([
            'mobile' => '94779999999',
            'name' => 'Incoming Customer',
            'human_handoff_status' => 'needs_human',
        ]);

        $assignedAgent = $service->assignNextAgent($incomingContact, 5);

        $this->assertNotNull($assignedAgent);
        $this->assertSame($availableAgent->id, $assignedAgent->id);

        $incomingContact->refresh();
        $this->assertSame($availableAgent->id, $incomingContact->assigned_agent_id);
        $this->assertSame($availableAgent->id, $incomingContact->human_handoff_assigned_user_id);
        $this->assertSame('assigned_to_agent', $incomingContact->human_handoff_status);
    }

    public function test_round_robin_rejects_assignment_when_all_agents_at_max_capacity(): void
    {
        // Agent: Online, but at max capacity (5 active chats)
        $agent = User::factory()->create([
            'name' => 'Maxed Agent',
            'is_admin' => true,
            'last_seen_at' => now(),
            'last_activity_at' => now(),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            Contact::create([
                'mobile' => "9477400000{$i}",
                'name' => "Client {$i}",
                'assigned_agent_id' => $agent->id,
                'human_handoff_status' => 'assigned_to_agent',
            ]);
        }

        $service = new RoundRobinAssignmentService();

        // No eligible agents with available capacity
        $this->assertTrue($service->getEligibleAgents(5)->isEmpty());

        $contact = Contact::create([
            'mobile' => '94778888888',
            'name' => 'Waiting Customer',
            'human_handoff_status' => 'needs_human',
        ]);

        $assigned = $service->assignNextAgent($contact, 5);

        // Cannot assign because agent is at capacity
        $this->assertNull($assigned);
        $contact->refresh();
        $this->assertNull($contact->assigned_agent_id);
        $this->assertSame('needs_human', $contact->human_handoff_status);
    }
}
