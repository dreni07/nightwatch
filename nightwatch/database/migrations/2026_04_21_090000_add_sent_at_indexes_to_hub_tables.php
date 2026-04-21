<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'hub_exceptions',
        'hub_requests',
        'hub_jobs',
        'hub_logs',
        'hub_queries',
        'hub_outgoing_https',
        'hub_mails',
        'hub_notifications',
        'hub_caches',
        'hub_commands',
        'hub_scheduled_tasks',
        'hub_health_checks',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->index('sent_at', "{$table}_sent_at_idx");
                $t->index(['project_id', 'sent_at'], "{$table}_project_sent_at_idx");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex("{$table}_sent_at_idx");
                $t->dropIndex("{$table}_project_sent_at_idx");
            });
        }
    }
};
