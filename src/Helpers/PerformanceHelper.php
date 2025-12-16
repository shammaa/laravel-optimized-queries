<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries\Helpers;

use Shammaa\LaravelOptimizedQueries\Services\PerformanceMonitor;

class PerformanceHelper
{
    /**
     * Compare traditional Eloquent query with optimized query.
     *
     * @param \Closure $traditionalQuery
     * @param \Closure $optimizedQuery
     * @return array
     */
    public static function compare(\Closure $traditionalQuery, \Closure $optimizedQuery): array
    {
        // Measure traditional query
        $beforeMonitor = new PerformanceMonitor();
        $beforeMonitor->start();
        $traditionalQuery();
        $before = $beforeMonitor->stop();

        // Measure optimized query
        $afterMonitor = new PerformanceMonitor();
        $afterMonitor->start();
        $optimizedQuery();
        $after = $afterMonitor->stop();

        return PerformanceMonitor::compare($before, $after);
    }

    /**
     * Format performance comparison for display.
     *
     * @param array $comparison
     * @return string
     */
    public static function format(array $comparison): string
    {
        $improvement = $comparison['improvement'] ?? [];
        $summary = $comparison['summary'] ?? [];

        $output = "🚀 Performance Improvement:\n";
        $output .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $output .= "📊 Queries: {$summary['queries']}\n";
        $output .= "⏱️  Time: {$summary['time']}\n";
        $output .= "⚡ Speedup: {$summary['speedup']}\n";
        $output .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        return $output;
    }

    /**
     * Display performance comparison in a nice format.
     *
     * @param array $comparison
     * @return void
     */
    public static function display(array $comparison): void
    {
        echo self::format($comparison);
    }
}

