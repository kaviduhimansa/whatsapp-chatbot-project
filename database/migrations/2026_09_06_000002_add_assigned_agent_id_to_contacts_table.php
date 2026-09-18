<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'assigned_agent_id')) {
                $table->foreignId('assigned_agent_id')
                    ->nullable()
                    ->after('human_handoff_assigned_user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        // Copy existing assignments from human_handoff_assigned_user_id to assigned_agent_id
        if (Schema::hasColumn('contacts', 'human_handoff_assigned_user_id') && Schema::hasColumn('contacts', 'assigned_agent_id')) {
            DB::table('contacts')
                ->whereNotNull('human_handoff_assigned_user_id')
                ->whereNull('assigned_agent_id')
                ->update([
                    'assigned_agent_id' => DB::raw('human_handoff_assigned_user_id'),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'assigned_agent_id')) {
                $table->dropConstrainedForeignId('assigned_agent_id');
            }
        });
    }
};
