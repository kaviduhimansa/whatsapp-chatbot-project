<?php

namespace Tests\Feature;

use App\Models\ChatQueue;
use App\Models\Contact;
use App\Models\User;
use App\Services\RoundRobinAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatQueueManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_queue_model_and_relationships(): void
    {
        $contact = Contact::create([
            'mobile' => '94771234567',
            'name' => 'Queue Test Contact',
        ]);

        $queueItem = ChatQueue::create([
            'contact_id' => $contact->id,
            'priority' => 1,
            'queued_at' => now(),
        ]);

        $this->assertDatabaseHas('chat_queues', [
            'id' => $queueItem->id,
            'contact_id' => $contact->id,
            'priority' => 1,
        ]);

        $this->assertSame($contact->id, $queueItem->contact->id);
        $this->assertSame($queueItem->id, $contact->fresh()->chatQueue->id);
    }

    public function test_contact_is_pushed_to_queue_when_agents_are_at_capacity(): void
    {
        // Create an agent who is already at maximum capacity (5 chats)
        $agent = User::factory()->create([
            'is_admin' => true,
            'last_seen_at' => now(),
            'last_activity_at' => now(),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            Contact::create([
                'mobile' => "9477000001{$i}",
                'name' => "Active Customer {$i}",
                'assigned_agent_id' => $agent->id,
                'human_handoff_status' => 'assigned_to_agent',
            ]);
        }

        $this->assertSame(5, $agent->activeChatsCount());
        $this->assertFalse($agent->hasAvailableCapacity(5));

        // Create contact via ContactController::store
        $response = $this->actingAs($agent)->post('/contacts', [
            'mobile' => '0779998888',
            'name' => 'Overflow Customer',
        ]);

        $response->assertRedirect(route('chats.index'));

        $contact = Contact::where('mobile', '94779998888')->first();
        $this->assertNotNull($contact);
        $this->assertNull($contact->assigned_agent_id);

        // Contact should be pushed to chat_queues because no agents have capacity
        $this->assertDatabaseHas('chat_queues', [
            'contact_id' => $contact->id,
            'priority' => 0,
        ]);

        $this->assertNotNull($contact->chatQueue);
    }

    public function test_chat_queue_fifo_order(): void
    {
        $contact1 = Contact::create(['mobile' => '94770000001', 'name' => 'First In Normal']);
        $contact2 = Contact::create(['mobile' => '94770000002', 'name' => 'Second In Normal']);
        $contact3 = Contact::create(['mobile' => '94770000003', 'name' => 'VIP High Priority']);

        // First normal contact queued 10 mins ago (priority 0)
        ChatQueue::create([
            'contact_id' => $contact1->id,
            'priority' => 0,
            'queued_at' => now()->subMinutes(10),
        ]);

        // Second normal contact queued 5 mins ago (priority 0)
        ChatQueue::create([
            'contact_id' => $contact2->id,
            'priority' => 0,
            'queued_at' => now()->subMinutes(5),
        ]);

        // VIP contact queued 2 mins ago (priority 10)
        ChatQueue::create([
            'contact_id' => $contact3->id,
            'priority' => 10,
            'queued_at' => now()->subMinutes(2),
        ]);

        $orderedQueue = ChatQueue::fifo()->pluck('contact_id')->all();

        // VIP contact should be first, followed by oldest normal contact (FIFO)
        $this->assertSame([$contact3->id, $contact1->id, $contact2->id], $orderedQueue);
    }

    public function test_contact_is_removed_from_queue_when_assigned(): void
    {
        // Contact currently in queue
        $contact = Contact::create([
            'mobile' => '94775555555',
            'name' => 'Queued Contact',
        ]);

        ChatQueue::create([
            'contact_id' => $contact->id,
            'priority' => 0,
            'queued_at' => now()->subMinutes(5),
        ]);

        $this->assertDatabaseHas('chat_queues', ['contact_id' => $contact->id]);

        // Available agent
        $agent = User::factory()->create([
            'is_admin' => true,
            'last_seen_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(RoundRobinAssignmentService::class);
        $assigned = $service->assignNextAgent($contact);

        $this->assertNotNull($assigned);
        $this->assertSame($agent->id, $assigned->id);

        // Queue record should be deleted upon assignment
        $this->assertDatabaseMissing('chat_queues', ['contact_id' => $contact->id]);
    }
}
