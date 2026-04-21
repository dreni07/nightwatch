<?php

namespace App\Jobs;

use App\Events\ProjectStatusChanged;
use App\Models\HubException;
use App\Models\HubHealthCheck;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateProjectStatus implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 3;

    public function __construct(public int $projectId) {}

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(): void
    {
        $project = Project::find($this->projectId);

        if (! $project) {
            return;
        }

        $oldStatus = $project->status;

        $recentCritical = HubHealthCheck::where('project_id', $project->id)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->whereIn('status', ['critical', 'error'])
            ->exists();

        $recentExceptions = HubException::where('project_id', $project->id)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        if ($recentCritical || $recentExceptions >= 10) {
            $newStatus = 'critical';
        } elseif ($recentExceptions >= 3) {
            $newStatus = 'warning';
        } else {
            $newStatus = 'normal';
        }

        if ($oldStatus === $newStatus) {
            return;
        }

        $project->update(['status' => $newStatus]);

        broadcast(new ProjectStatusChanged([
            'project_id' => $project->id,
            'project_name' => $project->name,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]));
    }
}
