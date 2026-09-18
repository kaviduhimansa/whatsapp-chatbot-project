<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Disabled: chat messages are fetched live from SLT API (no local messages table sync).
// Schedule::command('whatsapp:sync --limit=30')->everyMinute();

// Keep contacts list up to date based on the last active mobile numbers
Schedule::command('whatsapp:sync-contacts', [
    '--limit' => (int) config('chat.sync_recent_limit', 40),
])
    ->withoutOverlapping(15)
    ->everyMinute();

<<<<<<< HEAD
// Reassign chats with 5+ minutes of customer/agent inactivity, every minute
Schedule::command('chats:reassign-inactive-agent-chats')
    ->withoutOverlapping(15)
    ->everyMinute();

=======
Schedule::command('whatsapp:reassign-inactive-chats')->everyFiveMinutes();
>>>>>>> 266c7ae6e676e57dab7f1f2bf7b346745e5a1e4c
