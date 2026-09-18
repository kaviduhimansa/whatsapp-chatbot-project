@php
  $activeContactId = $activeContactId ?? null;
  $showLock = $showLock ?? false;
  $showActive = $showActive ?? false;
  $showPreview = $showPreview ?? false;
  $alignClass = $showPreview ? 'items-start' : 'items-center';
@endphp

@forelse($contacts as $c)
  @php
    $isActive = $activeContactId && (int) $c->id === (int) $activeContactId;
    $hasUnread = (bool) $c->has_unread;
    $unreadCount = (int) $c->unread_count;
    $needsHuman = (bool) $c->needs_human;
    $botPaused = (bool) $c->is_bot_paused;
    $handoffStatus = $c->human_handoff_status ?: ($needsHuman ? 'needs_human' : 'open');
    $handoffRequestedAt = $c->human_handoff_requested_at;
    $handoffRequestedAtIso = optional($handoffRequestedAt)->toIso8601String();
    $showNeedsHumanBadge = $needsHuman
      && (!$handoffRequestedAt || $handoffRequestedAt->copy()->addHour()->isFuture());
    $assignedAgentName = $c->assignedAgent?->name ?? $c->humanHandoffAssignedTo?->name;
    $lastActivityAt = $c->last_message_at ?? $c->updated_at;
    $preview = $c->last_message_preview ?: 'Waiting for customer messages';
    $previewPrefix = $c->last_message_direction === 'out' ? 'You: ' : 'Customer: ';
    $notificationBody = $c->last_inbound_message_preview ?: $preview;
  @endphp
  <a href="{{ route('chats.show', $c) }}"
     data-chat-contact-id="{{ $c->id }}"
     data-contact-name="{{ $c->name ?? $c->mobile }}"
     data-has-unread="{{ $hasUnread ? '1' : '0' }}"
     data-unread-count="{{ $unreadCount }}"
     data-human-handoff="{{ $botPaused ? '1' : '0' }}"
     data-handoff-status="{{ $handoffStatus }}"
     data-needs-human="{{ $needsHuman ? '1' : '0' }}"
     data-handoff-requested-at="{{ $handoffRequestedAtIso }}"
     data-latest-inbound-key="{{ $c->last_inbound_message_key ?? '' }}"
     data-notification-body="{{ $notificationBody }}"
     class="block p-4 transition-all {{ $isActive ? 'bg-white/10' : ($hasUnread ? 'bg-white/[0.07] hover:bg-white/10' : 'hover:bg-white/5') }}">
    <div class="flex {{ $alignClass }} gap-3">
      <div class="relative">
        <div class="w-10 h-10 rounded-full bg-slt-info flex items-center justify-center text-white font-semibold flex-shrink-0">
          {{ strtoupper(substr($c->name ?? $c->mobile, 0, 1)) }}
        </div>
        @if($hasUnread)
          <div class="absolute -bottom-1 -right-1 min-w-[1.25rem] h-5 px-1 rounded-full bg-slt-accent text-white text-[11px] font-semibold flex items-center justify-center border-2 border-slt-ink">
            {{ $unreadCount }}
          </div>
        @endif
        @if($showLock && $c->locked_by_user_id)
          <div class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-red-500 flex items-center justify-center">
            <svg class="w-2.5 h-2.5 text-white" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
            </svg>
          </div>
        @endif
      </div>
      <div class="flex-1 min-w-0">
        <div class="flex items-center justify-between gap-2">
          <div class="font-medium truncate {{ $isActive ? 'text-slt-accent' : 'text-white' }} {{ $hasUnread ? 'font-semibold' : '' }}">
            {{ $c->name ?? $c->mobile }}
          </div>
          <div class="flex items-center gap-2 flex-shrink-0">
            @if($lastActivityAt)
              <div class="hidden sm:block text-[11px] {{ $hasUnread ? 'text-slt-accent' : 'text-slt-muted' }}">
                {{ $lastActivityAt->format('H:i') }}
              </div>
            @endif
          </div>
        </div>
        @if($showPreview)
          <div class="text-sm truncate mt-0.5 {{ $hasUnread ? 'text-white' : 'text-slt-muted' }}">
            @if($c->last_message_preview)
              {{ $previewPrefix }}{{ $preview }}
            @else
              {{ $preview }}
            @endif
          </div>
          @if($botPaused)
            <div class="mt-1 flex items-center gap-2 min-w-0">
              <span class="chat-needs-human-badge inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $needsHuman ? 'bg-amber-500/15 text-amber-300' : 'bg-slt-accent/15 text-slt-accent' }} {{ $needsHuman && !$showNeedsHumanBadge ? 'hidden' : '' }}">
                {{ $needsHuman ? 'Needs Human' : 'Bot Paused' }}
              </span>
              <span class="truncate text-[11px] text-slt-muted">
                {{ $assignedAgentName ? 'Assigned to ' . $assignedAgentName : 'Customer asked for a human agent' }}
              </span>
            </div>
          @endif
          <div class="flex items-center gap-1 mt-1 text-xs text-slt-muted">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
            </svg>
            {{ $c->mobile }}
          </div>
        @else
          <div class="text-xs {{ $hasUnread ? 'text-white' : 'text-slt-muted' }}">{{ $c->mobile }}</div>
          @if($botPaused)
            <div class="chat-needs-human-badge mt-1 text-[11px] {{ $needsHuman ? 'text-amber-300' : 'text-slt-accent' }} {{ $needsHuman && !$showNeedsHumanBadge ? 'hidden' : '' }}">
              {{ $needsHuman ? 'Needs human reply' : ($assignedAgentName ? 'Assigned to ' . $assignedAgentName : 'Bot paused') }}
            </div>
          @endif
        @endif
      </div>
      @if($showActive && $isActive)
        <span class="w-2 h-2 rounded-full bg-slt-accent"></span>
      @endif
    </div>
  </a>
@empty
  <div class="p-6 text-center text-slt-muted">
    <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
    </svg>
    <p>No contacts yet</p>
    <p class="text-sm mt-1">Add a contact (9471xxxxxxx) to start.</p>
  </div>
@endforelse
