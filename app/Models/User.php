<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'role',
        'email_verified_at',
        'remember_token',
        'last_seen_at',
        'last_activity_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Scope a query to only include online and active users.
     */
    public function scopeOnlineAndActive($query)
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes(5))
                     ->where('last_activity_at', '>=', now()->subHour());
    }

    /**
     * Relationship to contacts assigned to this agent.
     */
    public function assignedContacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'assigned_agent_id');
    }

    /**
     * Alias relationship to contacts for query scopes and withCount.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'assigned_agent_id');
    }

    /**
     * Count the number of active chats currently assigned to this user.
     */
    public function activeChatsCount(): int
    {
        return $this->hasMany(Contact::class, 'assigned_agent_id')
                    ->where(function ($query) {
                        $query->where('human_handoff_status', 'active')
                              ->orWhere('human_handoff_status', 'assigned_to_agent');
                    })
                    ->count();
    }

    /**
     * Check if the user has available capacity for more concurrent chats.
     */
    public function hasAvailableCapacity(int $maxCapacity = 5): bool
    {
        return $this->activeChatsCount() < $maxCapacity;
    }
}
