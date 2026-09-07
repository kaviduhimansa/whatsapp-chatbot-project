<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AutoRedistributeOfflineChats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:auto-redistribute-offline-chats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
       // පැයකට වඩා Inactive වූ Agents ලා සෙවීම
    $offlineAgents = User::where('is_online', false)
        ->where('updated_at', '<=', now()->subHour())
        ->get();

    foreach ($offlineAgents as $agent) {
        // එම Agent ගේ Active Chats ලබා ගැනීම
        $activeConversations = Conversation::where('agent_id', $agent->id)
            ->where('status', 'ACTIVE')
            ->get();

        foreach ($activeConversations as $conversation) {
            // Online සිටින, Active Chat Count එක අඩුම Agent තෝරා ගැනීම
            $nextAgent = User::where('is_online', true)
                ->orderBy('active_chat_count', 'asc')
                ->first();

            if ($nextAgent) {
                // Chat එක අලුත් Agent ට Assign කිරීම
                $conversation->update(['agent_id' => $nextAgent->id]);
                
                $conversation->contact->update([
                    'last_agent_id' => $nextAgent->id,
                    'locked_by_user_id' => $nextAgent->id,
                    'locked_at' => now(),
                ]);

                // Active Workload Adjustment
                $agent->decrement('active_chat_count');
                $nextAgent->increment('active_chat_count');
            }
        }
    }
    }
}
