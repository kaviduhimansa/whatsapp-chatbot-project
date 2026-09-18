<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ChatApiController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatLockController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\HeartbeatController;
use App\Http\Controllers\SuperAdminAuthController;
use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Redirect dashboard to chats
Route::get('/dashboard', function () {
    return redirect()->route('chats.index');
})->middleware(['auth', 'verified'])->name('dashboard');

/*
|--------------------------------------------------------------------------
| WhatsApp Demo Routes
|--------------------------------------------------------------------------
| Protected by auth + verified
*/
Route::middleware(['auth', 'verified'])->group(function () {

    // WhatsApp UI
    Route::get('/chats', [ChatController::class, 'index'])->name('chats.index');
    Route::get('/chats/list', [ChatController::class, 'list'])->name('chats.list');
    Route::get('/chats/{contact}', [ChatController::class, 'show'])->name('chats.show');

    // Contacts
    Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::put('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::post('/contacts/sync-recent', [ContactController::class, 'syncRecent'])->name('contacts.syncRecent');

    // JSON endpoints
    Route::get('/chats/{contact}/messages', [ChatApiController::class, 'messages'])->name('chats.messages');
    Route::post('/chats/{contact}/send', [ChatApiController::class, 'send'])->name('chats.send');
    Route::post('/chats/{contact}/sync', [ChatApiController::class, 'sync'])->name('chats.sync');
    Route::post('/chats/{contact}/read', [ChatApiController::class, 'markRead'])->name('chats.read');
    Route::post('/chats/sync-all', [ChatApiController::class, 'syncAll'])->name('chats.syncAll');

    // Chat locking
    Route::get('/chats/{contact}/lock', [ChatLockController::class, 'status'])
        ->name('chats.lock.status');

    Route::post('/chats/{contact}/lock/acquire', [ChatLockController::class, 'acquire'])
        ->name('chats.lock.acquire');

<<<<<<< HEAD
    // Queue & capacity status dashboard endpoint
    Route::get('/api/queue/status', [ChatApiController::class, 'queueStatus'])->name('api.queue.status');

    // Logs page
=======
    Route::post('/chats/{contact}/lock/release', [ChatLockController::class, 'release'])
        ->name('chats.lock.release');

    // Human handoff
    Route::post('/chats/{contact}/handoff/takeover', [ChatApiController::class, 'takeoverHandoff'])
        ->name('chats.handoff.takeover');

    Route::post('/chats/{contact}/handoff/resolve', [ChatApiController::class, 'resolveHandoff'])
        ->name('chats.handoff.resolve');

    Route::post('/chats/{contact}/handoff/reset', [ChatApiController::class, 'resetHandoff'])
        ->name('chats.handoff.reset');

    // Logs
>>>>>>> 266c7ae6e676e57dab7f1f2bf7b346745e5a1e4c
    Route::get('/logs', [LogController::class, 'index'])->name('logs.index');

    /*
    |--------------------------------------------------------------------------
    | Agent Heartbeat
    |--------------------------------------------------------------------------
    | Updates the logged-in agent's last_seen_at timestamp.
    */
    Route::post('/heartbeat', [HeartbeatController::class, 'ping'])
        ->name('heartbeat');
});

/*
|--------------------------------------------------------------------------
| Breeze Profile Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Super Admin Routes
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {

    Route::get('/superadmin/login', [SuperAdminAuthController::class, 'showLogin'])
        ->name('superadmin.login');

    Route::post('/superadmin/login', [SuperAdminAuthController::class, 'login']);
});

Route::middleware('superadmin')
    ->prefix('superadmin')
    ->name('superadmin.')
    ->group(function () {

        // Redirect root to manage admins
        Route::get('/', function () {
            return redirect()->route('superadmin.admins.index');
        })->name('home');

        Route::resource('admins', AdminController::class);
    });

require __DIR__.'/auth.php';