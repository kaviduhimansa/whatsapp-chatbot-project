<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SessionTimeoutAndOfflineHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_online_and_active_filters_users_correctly(): void
    {
        // 1. Online & active user (seen within 5m, active within 1h)
        $activeUser = User::factory()->create([
            'last_seen_at' => now()->subMinutes(2),
            'last_activity_at' => now()->subMinutes(20),
        ]);

        // 2. Inactive user (seen recently, but last activity > 1 hour ago)
        $inactiveUser = User::factory()->create([
            'last_seen_at' => now()->subMinutes(1),
            'last_activity_at' => now()->subMinutes(65),
        ]);

        // 3. Offline user (last seen > 5 minutes ago)
        $offlineUser = User::factory()->create([
            'last_seen_at' => now()->subMinutes(6),
            'last_activity_at' => now()->subMinutes(10),
        ]);

        // 4. User without activity recorded
        $newUser = User::factory()->create([
            'last_seen_at' => null,
            'last_activity_at' => null,
        ]);

        $onlineAndActive = User::onlineAndActive()->pluck('id')->all();

        $this->assertContains($activeUser->id, $onlineAndActive);
        $this->assertNotContains($inactiveUser->id, $onlineAndActive);
        $this->assertNotContains($offlineUser->id, $onlineAndActive);
        $this->assertNotContains($newUser->id, $onlineAndActive);
    }

    public function test_manual_logout_immediately_marks_admin_offline(): void
    {
        $user = User::factory()->create([
            'last_seen_at' => now(),
            'last_activity_at' => now(),
        ]);

        $this->assertTrue(User::onlineAndActive()->where('id', $user->id)->exists());

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();

        $user->refresh();

        // last_seen_at must be >= 2 hours ago
        $this->assertNotNull($user->last_seen_at);
        $this->assertTrue($user->last_seen_at->lessThanOrEqualTo(now()->subHours(2)));
        $this->assertNull($user->last_activity_at);

        // Immediately excluded from active routing scope
        $this->assertFalse(User::onlineAndActive()->where('id', $user->id)->exists());
    }

    public function test_reassign_inactive_agent_chats_command_reassigns_to_active_agent(): void
    {
        // Active agent — online and has available capacity
        $activeAgent = User::factory()->create([
            'name'             => 'Active Agent',
            'is_admin'         => true,
            'last_seen_at'     => now()->subMinutes(1),
            'last_activity_at' => now()->subMinutes(15),
        ]);

        // Contact assigned to active agent but stale (10 min no activity)
        $contact = Contact::create([
            'mobile'                         => '94770000001',
            'name'                           => 'Customer 1',
            'human_handoff_active'           => true,
            'human_handoff_status'           => 'assigned_to_agent',
            'human_handoff_assigned_user_id' => $activeAgent->id,
            'human_handoff_assigned_at'      => now()->subMinutes(15),
        ]);
        // Back-date updated_at so the command's 5-min threshold picks this up
        \Illuminate\Support\Facades\DB::table('contacts')
            ->where('id', $contact->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        // Add a second active agent to receive the reassignment
        $receivingAgent = User::factory()->create([
            'name'             => 'Receiving Agent',
            'is_admin'         => true,
            'last_seen_at'     => now(),
            'last_activity_at' => now(),
        ]);

        $this->artisan('chats:reassign-inactive-agent-chats', ['--minutes' => 5])
            ->assertSuccessful();

        $contact->refresh();

        // Chat should be reassigned to an available agent
        $this->assertNotNull($contact->human_handoff_assigned_user_id);
        $this->assertSame('assigned_to_agent', $contact->human_handoff_status);
    }

    public function test_reassign_inactive_agent_chats_reverts_to_needs_human_when_no_active_agents(): void
    {
        // Only one agent, maxed out at 5 chats — no capacity to receive the stale chat
        $maxedAgent = User::factory()->create([
            'name'             => 'Maxed Agent',
            'is_admin'         => true,
            'last_seen_at'     => now(),
            'last_activity_at' => now(),
        ]);

        for ($i = 1; $i <= 4; $i++) {
            Contact::create([
                'mobile'               => "9477111000{$i}",
                'name'                 => "Filler {$i}",
                'assigned_agent_id'    => $maxedAgent->id,
                'human_handoff_status' => 'assigned_to_agent',
            ]);
        }

        // The stale contact — will fill the last slot (5th) — agent is then at capacity
        $staleContact = Contact::create([
            'mobile'                         => '94770000002',
            'name'                           => 'Customer 2',
            'human_handoff_active'           => true,
            'human_handoff_status'           => 'assigned_to_agent',
            'human_handoff_assigned_user_id' => $maxedAgent->id,
            'assigned_agent_id'              => $maxedAgent->id,
            'human_handoff_assigned_at'      => now()->subHours(3),
        ]);
        \Illuminate\Support\Facades\DB::table('contacts')
            ->where('id', $staleContact->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('chats:reassign-inactive-agent-chats', ['--minutes' => 5])
            ->assertSuccessful();

        $staleContact->refresh();

        // Chat should revert to needs_human queue since no agents have available capacity
        $this->assertNull($staleContact->human_handoff_assigned_user_id);
        $this->assertSame('needs_human', $staleContact->human_handoff_status);
        $this->assertDatabaseHas('chat_queues', ['contact_id' => $staleContact->id]);
    }
}
