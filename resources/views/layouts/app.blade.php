<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'SLT WhatsApp') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased" x-data="appNotifications()" x-init="init()">
        <div class="min-h-screen bg-slt-ink">
            @include('layouts.navigation')

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>

        <div class="pointer-events-none fixed right-4 top-4 z-[100] flex w-[22rem] max-w-[calc(100vw-2rem)] flex-col gap-2"
             aria-live="assertive"
             aria-atomic="true">
            <template x-for="notice in notices" :key="notice.key">
                <div x-transition
                     class="pointer-events-auto rounded-xl border border-slt-info/30 bg-slt-info px-4 py-3 text-sm text-white shadow-xl">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-2">
                            <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-white/90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V4a2 2 0 10-4 0v1.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0a3 3 0 11-6 0m6 0H9" />
                            </svg>
                            <div class="space-y-0.5">
                                <p class="font-semibold leading-5" x-text="notice.title"></p>
                                <p class="leading-5 text-white/90" x-text="notice.message"></p>
                            </div>
                        </div>
                        <button type="button"
                                class="rounded-lg p-1 text-white/80 hover:bg-black/20"
                                @click="dismissNotice(notice.key)"
                                aria-label="Dismiss notification">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </template>

            <template x-for="error in errors" :key="error.key">
                <div x-transition
                     class="pointer-events-auto rounded-xl border border-red-300/30 bg-red-600 px-4 py-3 text-sm text-white shadow-xl">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-2">
                            <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-red-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-7.938 4h15.876c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L2.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <p class="font-medium leading-5" x-text="error.message"></p>
                        </div>
                        <button type="button"
                                class="rounded-lg p-1 text-red-100 hover:bg-black/20"
                                @click="dismissError(error.key)"
                                aria-label="Dismiss error">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <script>
            function appNotifications() {
                return {
                    errors: [],
                    notices: [],
                    noticeTimers: {},

                    init() {
                        @auth
                        setInterval(() => {
                            fetch('/heartbeat', {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                },
                            });
                        }, 30000);
                        @endauth

                        window.addEventListener('app:error', (event) => {
                            const key = String(event?.detail?.key || '').trim();
                            const message = String(event?.detail?.message || 'Something went wrong.');
                            this.upsertError(key, message);
                        });

                        window.addEventListener('app:error:clear', (event) => {
                            const key = String(event?.detail?.key || '').trim();
                            this.clearError(key);
                        });

                        window.addEventListener('app:notify', (event) => {
                            const key = String(event?.detail?.key || '').trim();
                            const title = String(event?.detail?.title || 'New customer message').trim();
                            const message = String(event?.detail?.message || '').trim() || 'Open the chat to reply.';
                            this.upsertNotice(key, title, message);
                        });

                        @if (session('error'))
                            this.upsertError('session-error', @js(session('error')));
                        @endif
                    },

                    upsertError(key, message) {
                        const text = String(message || '').trim();
                        if (!text) return;

                        const normalizedKey = key || `message:${text.toLowerCase()}`;
                        const existing = this.errors.find((item) => item.key === normalizedKey);

                        if (existing) {
                            existing.message = text;
                            return;
                        }

                        this.errors.push({ key: normalizedKey, message: text });
                    },

                    clearError(key) {
                        if (!key) {
                            this.errors = [];
                            return;
                        }
                        this.errors = this.errors.filter((item) => item.key !== key);
                    },

                    dismissError(key) {
                        this.clearError(key);
                    },

                    upsertNotice(key, title, message) {
                        const noticeTitle = String(title || '').trim() || 'New customer message';
                        const noticeMessage = String(message || '').trim() || 'Open the chat to reply.';
                        const normalizedKey = key || `notice:${noticeTitle.toLowerCase()}:${noticeMessage.toLowerCase()}`;
                        const existing = this.notices.find((item) => item.key === normalizedKey);

                        if (existing) {
                            existing.title = noticeTitle;
                            existing.message = noticeMessage;
                        } else {
                            this.notices.push({
                                key: normalizedKey,
                                title: noticeTitle,
                                message: noticeMessage,
                            });
                        }

                        this.resetNoticeTimer(normalizedKey);
                    },

                    resetNoticeTimer(key) {
                        if (this.noticeTimers[key]) {
                            clearTimeout(this.noticeTimers[key]);
                        }

                        this.noticeTimers[key] = setTimeout(() => {
                            this.clearNotice(key);
                        }, 6000);
                    },

                    clearNotice(key) {
                        if (this.noticeTimers[key]) {
                            clearTimeout(this.noticeTimers[key]);
                            delete this.noticeTimers[key];
                        }

                        if (!key) {
                            this.notices = [];
                            return;
                        }

                        this.notices = this.notices.filter((item) => item.key !== key);
                    },

                    dismissNotice(key) {
                        this.clearNotice(key);
                    },
                };
            }

            window.chatListNotifications = (() => {
                const baseTitle = document.title;
                let inboundKeysByContact = new Map();

                const showDesktopNotification = (title, body) => {
                    if (!('Notification' in window)) return;
                    if (Notification.permission !== 'granted') return;
                    if (document.visibilityState === 'visible' && document.hasFocus()) return;

                    try {
                        new Notification(title, { body });
                    } catch (e) {}
                };

                return {
                    syncFromList(listEl, options = {}) {
                        if (!listEl) return;

                        const activeContactId = String(options.activeContactId || '');
                        const initial = !!options.initial;
                        const nextKeys = new Map();
                        let unreadChatCount = 0;
                        let unreadMessageCount = 0;
                        let humanHandoffChatCount = 0;
                        let humanHandoffUnreadMessageCount = 0;

                        listEl.querySelectorAll('[data-chat-contact-id]').forEach((item) => {
                            const contactId = String(item.dataset.chatContactId || '');
                            const latestInboundKey = String(item.dataset.latestInboundKey || '');
                            const hasUnread = item.dataset.hasUnread === '1';
                            const unreadCount = Math.max(0, Number.parseInt(item.dataset.unreadCount || '0', 10) || 0);
                            const hasHumanHandoff = item.dataset.humanHandoff === '1';

                            if (hasUnread) {
                                unreadChatCount += 1;
                                unreadMessageCount += unreadCount > 0 ? unreadCount : 1;
                            }

                            if (hasHumanHandoff) {
                                humanHandoffChatCount += 1;
                                humanHandoffUnreadMessageCount += unreadCount;
                            }

                            const previousKey = inboundKeysByContact.get(contactId);
                            nextKeys.set(contactId, latestInboundKey);

                            if (initial || !hasUnread || !latestInboundKey || contactId === activeContactId) {
                                return;
                            }

                            if (previousKey === latestInboundKey) {
                                return;
                            }

                            const title = String(item.dataset.contactName || 'New customer message');
                            const message = String(item.dataset.notificationBody || 'Open the chat to reply.');

                            window.dispatchEvent(new CustomEvent('app:notify', {
                                detail: {
                                    key: `chat:${contactId}:${latestInboundKey}`,
                                    title,
                                    message,
                                }
                            }));

                            showDesktopNotification(title, message);
                        });

                        inboundKeysByContact = nextKeys;
                        document.title = unreadMessageCount > 0 ? `(${unreadMessageCount}) ${baseTitle}` : baseTitle;
                        window.dispatchEvent(new CustomEvent('chat:list:stats', {
                            detail: {
                                unread_chat_count: unreadChatCount,
                                unread_message_count: unreadMessageCount,
                                human_handoff_chat_count: humanHandoffChatCount,
                                human_handoff_unread_message_count: humanHandoffUnreadMessageCount,
                            }
                        }));
                    },

                    notifyIncomingMessage(key, title, message) {
                        const normalizedKey = String(key || '').trim();
                        const normalizedTitle = String(title || 'New customer message').trim() || 'New customer message';
                        const normalizedMessage = String(message || 'Open the chat to reply.').trim() || 'Open the chat to reply.';

                        window.dispatchEvent(new CustomEvent('app:notify', {
                            detail: {
                                key: normalizedKey || `chat:active:${Date.now()}`,
                                title: normalizedTitle,
                                message: normalizedMessage,
                            }
                        }));

                        showDesktopNotification(normalizedTitle, normalizedMessage);
                    },
                };
            })();

            window.addEventListener('chat:list:stats', (event) => {
                const stats = event?.detail || {};
                document.querySelectorAll('[data-chat-stat]').forEach((element) => {
                    const statKey = String(element.dataset.chatStat || '').trim();
                    const value = Number.parseInt(stats[statKey] ?? '0', 10) || 0;
                    element.textContent = String(value);
                });
            });
        </script>
    </body>
</html>
