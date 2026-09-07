<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatQueue extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'priority',
        'queued_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'queued_at' => 'datetime',
        ];
    }

    /**
     * Relationship to the contact waiting in queue.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Scope a query to order the queue by FIFO priority rules.
     * Higher priority chats are processed first, followed by oldest queued.
     */
    public function scopeFifo($query)
    {
        return $query->orderByDesc('priority')
                     ->orderBy('queued_at')
                     ->orderBy('id');
    }

    /**
     * Push a contact into the FIFO chat queue if not already queued.
     */
    public static function enqueue(Contact|int $contact, int $priority = 0): self
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        return static::firstOrCreate(
            ['contact_id' => $contactId],
            [
                'priority' => $priority,
                'queued_at' => now(),
            ]
        );
    }

    /**
     * Alias for enqueue.
     */
    public static function pushToQueue(Contact|int $contact, int $priority = 0): self
    {
        return static::enqueue($contact, $priority);
    }
}
