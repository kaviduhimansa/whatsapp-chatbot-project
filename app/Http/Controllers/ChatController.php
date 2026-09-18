<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    private function listLimit(?int $limit = null): int
    {
        $default = (int) config('chat.list_limit', 40);
        $limit = $limit ?? $default;
        if ($limit <= 0) {
            return $default;
        }
        return min($limit, 200);
    }

    private function releaseExpiredLocks(): void
    {
        $ttl = (int) config('chat.lock_ttl_seconds', 1800); // matches CHAT_LOCK_TTL_SECONDS

        Contact::whereNotNull('locked_by_user_id')
            ->where('locked_at', '<', now()->subSeconds($ttl))
            ->update([
                'locked_by_user_id' => null,
                'locked_at' => null,
            ]);
    }

    private function contactsQuery()
    {
        $this->releaseExpiredLocks();

        // Message history is loaded live from SLT API, not from local messages table.
        return Contact::query()
            ->with([
                'lockedBy:id,name',
                'humanHandoffAssignedTo:id,name',
                'assignedAgent:id,name',
            ])
            ->where(function ($q) {
                $q->whereNull('locked_by_user_id')
                    ->orWhere('locked_by_user_id', auth()->id());
            })
            ->where(function ($q) {
                $q->where('assigned_agent_id', auth()->id())
                    ->orWhereNull('assigned_agent_id');
            })
            ->orderByRaw("CASE human_handoff_status WHEN 'needs_human' THEN 0 WHEN 'assigned_to_agent' THEN 1 ELSE 2 END")
            ->orderByRaw('COALESCE(unread_message_count, 0) DESC')
            ->orderByRaw('COALESCE(last_message_at, updated_at) DESC')
            ->latest('updated_at');
    }

    public function index()
    {
        $listLimit = $this->listLimit();
        $contacts = $this->contactsQuery()->limit($listLimit)->get();

        return view('chats.index', compact('contacts', 'listLimit'));
    }

    public function show(Contact $contact)
    {
        if (
            $contact->locked_by_user_id
            && $contact->locked_by_user_id !== auth()->id()
            && !$contact->isLockExpired()
        ) {
            abort(423, 'This chat is currently locked by another agent.');
        }

        $contact->loadMissing([
            'lockedBy:id,name',
            'humanHandoffAssignedTo:id,name',
        ]);

        $listLimit = $this->listLimit();

        $contacts = $this->contactsQuery()
            ->limit($listLimit)
            ->get();

        return view('chats.show', compact(
            'contacts',
            'contact',
            'listLimit'
        ));
    }

    public function list(Request $request)
    {
        $listLimit = $this->listLimit($request->integer('limit'));
        $contacts = $this->contactsQuery()->limit($listLimit)->get();
        $activeContactId = $request->query('active_contact_id');
        $showLock = $request->boolean('show_lock', false);
        $showActive = $request->boolean('show_active', false);
        $showPreview = $request->boolean('show_preview', false);
        return view('chats.partials.list-items', compact(
            'contacts',
            'activeContactId',
            'showLock',
            'showActive',
            'showPreview'
        ));
    }
}
