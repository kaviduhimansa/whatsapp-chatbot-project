<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    protected $fillable = [
        'name',
        'mobile',
        'last_synced_at',
        'last_read_at',
        'last_message_at',
        'last_message_direction',
        'last_message_preview',
        'last_inbound_message_at',
        'last_inbound_message_key',
        'last_inbound_message_preview',
        'unread_message_count',
        'human_handoff_active',
        'human_handoff_status',
        'bot_paused',
        'human_handoff_requested_at',
        'human_handoff_message_key',
        'human_handoff_message_preview',
        'human_handoff_assigned_user_id',
        'assigned_agent_id',
        'human_handoff_assigned_at',
        'assigned_agent_id',
        'locked_by_user_id',
        'locked_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'last_read_at' => 'datetime',
        'last_message_at' => 'datetime',
        'last_inbound_message_at' => 'datetime',
        'human_handoff_active' => 'boolean',
        'bot_paused' => 'boolean',
        'human_handoff_requested_at' => 'datetime',
        'human_handoff_assigned_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function getHasUnreadAttribute(): bool
    {
        if ($this->last_inbound_message_at && $this->last_read_at && !$this->last_inbound_message_at->gt($this->last_read_at)) {
            return false;
        }

        if ($this->unread_message_count !== null) {
            return (int) $this->unread_message_count > 0;
        }

        if (!$this->last_inbound_message_at) {
            return false;
        }

        if (!$this->last_read_at) {
            return true;
        }

        return $this->last_inbound_message_at->gt($this->last_read_at);
    }

    public function getUnreadCountAttribute(): int
    {
        if (!$this->has_unread) {
            return 0;
        }

        if ($this->unread_message_count !== null) {
            return max(0, (int) $this->unread_message_count);
        }

        return 1;
    }

    public function getNeedsHumanAttribute(): bool
    {
        if ($this->human_handoff_status) {
            return $this->human_handoff_status === 'needs_human';
        }

        return (bool) $this->human_handoff_active && !$this->human_handoff_assigned_user_id;
    }

    public function getIsBotPausedAttribute(): bool
    {
        if ($this->bot_paused !== null) {
            return (bool) $this->bot_paused;
        }

        return in_array($this->human_handoff_status, ['needs_human', 'assigned_to_agent'], true)
            || (bool) $this->human_handoff_active;
    }

    protected static function booted(): void
    {
        static::saving(function (Contact $contact) {
            if ($contact->isDirty('human_handoff_assigned_user_id') && !$contact->isDirty('assigned_agent_id')) {
                $contact->assigned_agent_id = $contact->human_handoff_assigned_user_id;
            } elseif ($contact->isDirty('assigned_agent_id') && !$contact->isDirty('human_handoff_assigned_user_id')) {
                $contact->human_handoff_assigned_user_id = $contact->assigned_agent_id;
            }
        });
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function humanHandoffAssignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'human_handoff_assigned_user_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function chatQueue(): HasOne
    {
        return $this->hasOne(ChatQueue::class);
    }

    public function chatQueues(): HasMany
    {
        return $this->hasMany(ChatQueue::class);
    }

    public function isLockExpired(): bool
    {
        if (!$this->locked_at) return true;
        $ttl = (int) config('chat.lock_ttl_seconds', 120);
        return $this->locked_at->diffInSeconds(now()) > $ttl;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany('sent_at');
    }
}
