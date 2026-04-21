<?php

namespace App\Services;

use App\Models\HubException;
use App\Models\HubHealthCheck;
use App\Models\HubJob;
use App\Models\HubRequest;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardMetricsService
{
    private const ERROR_SEVERITIES = ['error', 'critical'];

    private const WARNING_SEVERITIES = ['warning', 'info', 'debug'];

    public const CACHE_KEY = 'dashboard:overview';

    private const CACHE_TTL_SECONDS = 5;

    /**
     * @return array{
     *     stats: array<string, int|float>,
     *     recent_projects: list<array<string, mixed>>,
     *     incident_flow: list<array<string, int|string>>,
     *     incident_volume: list<array<string, int|string>>,
     *     throughput_chart: list<array{value: int}>,
     *     bugs_chart: list<array{value: int}>,
     *     running_checks_chart: list<array{value: int}>
     * }
     */
    public function overview(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn () => $this->compute());
    }

    private function compute(): array
    {
        $since24h = CarbonImmutable::now()->subHours(24);
        $since7d = CarbonImmutable::now()->subDays(7)->startOfDay();

        $requestStats = HubRequest::query()
            ->where('sent_at', '>=', $since24h)
            ->selectRaw('COUNT(*) as total, AVG(duration_ms) as avg_duration')
            ->first();

        $stats = [
            'total_projects' => Project::count(),
            'active_projects' => Project::query()
                ->whereNotNull('last_heartbeat_at')
                ->where('last_heartbeat_at', '>=', $since24h)
                ->count(),
            'critical_projects' => Project::where('status', 'critical')->count(),
            'total_exceptions_24h' => HubException::where('sent_at', '>=', $since24h)->count(),
            'total_requests_24h' => (int) ($requestStats->total ?? 0),
            'avg_response_time_24h' => (int) round((float) ($requestStats->avg_duration ?? 0)),
            'failed_jobs_24h' => HubJob::where('sent_at', '>=', $since24h)
                ->where('status', 'failed')
                ->count(),
            'health_check_failures' => HubHealthCheck::where('sent_at', '>=', $since24h)
                ->whereIn('status', ['critical', 'error', 'failed'])
                ->count(),
        ];

        $recent_projects = Project::query()
            ->withCount([
                'exceptions as exceptions_24h' => fn ($q) => $q->where('sent_at', '>=', $since24h),
                'requests as requests_24h' => fn ($q) => $q->where('sent_at', '>=', $since24h),
            ])
            ->orderByDesc('last_heartbeat_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'status' => $p->status,
                'environment' => $p->environment,
                'last_heartbeat_at' => $p->last_heartbeat_at?->toIso8601String(),
                'exceptions_24h' => (int) $p->exceptions_24h,
                'requests_24h' => (int) $p->requests_24h,
            ])
            ->values()
            ->all();

        $incidentFlow = $this->hourlyExceptionSeries($since24h);
        $incidentVolume = array_map(fn (array $row) => [
            'time' => $row['time'],
            'errors' => $row['exceptions'],
            'warnings' => $row['warnings'],
        ], $incidentFlow);

        $throughputChart = $this->hourlyRequestCountSeries($since24h);
        $runningChecksChart = $this->hourlyDistinctProjectsWithRequests($since24h);
        $bugsChart = $this->dailyExceptionCountSeries(7, $since7d);

        return [
            'stats' => $stats,
            'recent_projects' => $recent_projects,
            'incident_flow' => $incidentFlow,
            'incident_volume' => $incidentVolume,
            'throughput_chart' => $throughputChart,
            'bugs_chart' => $bugsChart,
            'running_checks_chart' => $runningChecksChart,
        ];
    }

    /**
     * @return list<array{time: string, exceptions: int, warnings: int}>
     */
    private function hourlyExceptionSeries(CarbonImmutable $since24h): array
    {
        $start = CarbonImmutable::now()->subHours(23)->startOfHour();

        $rows = HubException::query()
            ->where('sent_at', '>=', $since24h)
            ->selectRaw($this->hourlyBucketExpression('sent_at').' as bucket, severity, COUNT(*) as total')
            ->groupBy('bucket', 'severity')
            ->get();

        /** @var array<string, array{errors: int, warnings: int}> $counts */
        $counts = [];
        foreach ($rows as $row) {
            $key = (string) $row->bucket;
            $sev = strtolower((string) $row->severity);
            $total = (int) $row->total;
            $counts[$key] ??= ['errors' => 0, 'warnings' => 0];

            if (in_array($sev, self::WARNING_SEVERITIES, true)) {
                $counts[$key]['warnings'] += $total;
            } else {
                $counts[$key]['errors'] += $total;
            }
        }

        $buckets = [];
        for ($i = 0; $i < 24; $i++) {
            $bucketStart = $start->addHours($i);
            $key = $bucketStart->format('Y-m-d H');
            $row = $counts[$key] ?? ['errors' => 0, 'warnings' => 0];
            $buckets[] = [
                'time' => $bucketStart->format('H:00'),
                'exceptions' => $row['errors'],
                'warnings' => $row['warnings'],
            ];
        }

        return $buckets;
    }

    /**
     * @return list<array{value: int}>
     */
    private function hourlyRequestCountSeries(CarbonImmutable $since24h): array
    {
        $start = CarbonImmutable::now()->subHours(23)->startOfHour();

        $rows = HubRequest::query()
            ->where('sent_at', '>=', $since24h)
            ->selectRaw($this->hourlyBucketExpression('sent_at').' as bucket, COUNT(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return $this->fillHourlyBuckets($start, 24, fn (string $key) => (int) ($rows[$key] ?? 0));
    }

    /**
     * @return list<array{value: int}>
     */
    private function hourlyDistinctProjectsWithRequests(CarbonImmutable $since24h): array
    {
        $start = CarbonImmutable::now()->subHours(23)->startOfHour();

        $rows = HubRequest::query()
            ->where('sent_at', '>=', $since24h)
            ->selectRaw($this->hourlyBucketExpression('sent_at').' as bucket, COUNT(DISTINCT project_id) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return $this->fillHourlyBuckets($start, 24, fn (string $key) => (int) ($rows[$key] ?? 0));
    }

    /**
     * @return list<array{value: int}>
     */
    private function dailyExceptionCountSeries(int $days, CarbonImmutable $since): array
    {
        $rows = HubException::query()
            ->where('sent_at', '>=', $since)
            ->selectRaw($this->dailyBucketExpression('sent_at').' as bucket, COUNT(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = CarbonImmutable::now()->subDays($i)->startOfDay();
            $key = $day->format('Y-m-d');
            $out[] = ['value' => (int) ($rows[$key] ?? 0)];
        }

        return $out;
    }

    /**
     * @param  callable(string): int  $valueFor
     * @return list<array{value: int}>
     */
    private function fillHourlyBuckets(CarbonImmutable $start, int $hours, callable $valueFor): array
    {
        $out = [];
        for ($i = 0; $i < $hours; $i++) {
            $bucketStart = $start->addHours($i);
            $out[] = ['value' => $valueFor($bucketStart->format('Y-m-d H'))];
        }

        return $out;
    }

    private function hourlyBucketExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d %H', {$column})",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m-%d %H')",
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD HH24')",
            'sqlsrv' => "FORMAT({$column}, 'yyyy-MM-dd HH')",
            default => "strftime('%Y-%m-%d %H', {$column})",
        };
    }

    private function dailyBucketExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d', {$column})",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD')",
            'sqlsrv' => "FORMAT({$column}, 'yyyy-MM-dd')",
            default => "strftime('%Y-%m-%d', {$column})",
        };
    }
}
