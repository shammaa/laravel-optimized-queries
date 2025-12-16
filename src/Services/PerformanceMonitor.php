<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PerformanceMonitor
{
    protected array $queries = [];
    protected float $startTime;
    protected float $endTime;
    protected int $queryCount = 0;
    protected bool $enabled = false;

    public function __construct()
    {
        $this->enabled = config('optimized-queries.enable_performance_monitoring', true);
    }

    /**
     * Start monitoring.
     */
    public function start(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->queries = [];
        $this->queryCount = 0;
        $this->startTime = microtime(true);

        // Enable query logging
        DB::enableQueryLog();
    }

    /**
     * Stop monitoring and get results.
     *
     * @return array
     */
    public function stop(): array
    {
        if (!$this->enabled) {
            return $this->getEmptyResults();
        }

        $this->endTime = microtime(true);
        $this->queries = DB::getQueryLog();
        $this->queryCount = count($this->queries);

        DB::disableQueryLog();

        return $this->getResults();
    }

    /**
     * Get performance results.
     *
     * @return array
     */
    protected function getResults(): array
    {
        $executionTime = ($this->endTime - $this->startTime) * 1000; // Convert to milliseconds
        $totalQueries = $this->queryCount;
        $totalTime = array_sum(array_column($this->queries, 'time'));

        return [
            'query_count' => $totalQueries,
            'execution_time_ms' => round($executionTime, 2),
            'total_query_time_ms' => round($totalTime, 2),
            'queries' => $this->queries,
        ];
    }

    /**
     * Get empty results when monitoring is disabled.
     *
     * @return array
     */
    protected function getEmptyResults(): array
    {
        return [
            'query_count' => 0,
            'execution_time_ms' => 0,
            'total_query_time_ms' => 0,
            'queries' => [],
        ];
    }

    /**
     * Compare two performance results and calculate improvement.
     *
     * @param array $before Results from traditional query
     * @param array $after Results from optimized query
     * @return array
     */
    public static function compare(array $before, array $after): array
    {
        $queryReduction = $before['query_count'] - $after['query_count'];
        $queryReductionPercent = $before['query_count'] > 0
            ? round(($queryReduction / $before['query_count']) * 100, 2)
            : 0;

        $timeReduction = $before['execution_time_ms'] - $after['execution_time_ms'];
        $timeReductionPercent = $before['execution_time_ms'] > 0
            ? round(($timeReduction / $before['execution_time_ms']) * 100, 2)
            : 0;

        $speedup = $before['execution_time_ms'] > 0 && $after['execution_time_ms'] > 0
            ? round($before['execution_time_ms'] / $after['execution_time_ms'], 2)
            : 0;

        return [
            'before' => $before,
            'after' => $after,
            'improvement' => [
                'queries_reduced' => $queryReduction,
                'queries_reduction_percent' => $queryReductionPercent,
                'time_reduced_ms' => round($timeReduction, 2),
                'time_reduction_percent' => $timeReductionPercent,
                'speedup' => $speedup . 'x',
                'speedup_percent' => $timeReductionPercent,
            ],
            'summary' => [
                'queries' => "{$before['query_count']} → {$after['query_count']} ({$queryReductionPercent}% reduction)",
                'time' => round($before['execution_time_ms'], 2) . "ms → " . round($after['execution_time_ms'], 2) . "ms ({$timeReductionPercent}% faster)",
                'speedup' => "{$speedup}x faster",
            ],
        ];
    }
}

