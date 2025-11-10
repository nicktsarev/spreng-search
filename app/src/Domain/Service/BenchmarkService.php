<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\ValueObject\BenchmarkMetrics;
use App\Domain\ValueObject\SearchCriteria;

readonly class BenchmarkService
{
    /**
     * @param SearchServiceInterface[] $searchServices
     */
    public function __construct(
        private iterable $searchServices
    ) {
    }

    /**
     * @return BenchmarkMetrics[]
     */
    public function runBenchmark(SearchCriteria $criteria, int $iterations = 10): array
    {
        $results = [];

        foreach ($this->searchServices as $service) {
            $service->warmup();

            $times = [];
            $memoryPeaks = [];
            $resultCount = 0;

            for ($i = 0; $i < $iterations; $i++) {
                $startMemory = memory_get_usage(true);
                $startTime = microtime(true);

                $searchResults = $service->search($criteria);

                $endTime = microtime(true);
                $endMemory = memory_get_peak_usage(true);

                $times[] = $endTime - $startTime;
                $memoryPeaks[] = $endMemory - $startMemory;
                $resultCount = count($searchResults);

                unset($searchResults);
            }

            $avgTime = array_sum($times) / count($times);
            $avgMemory = array_sum($memoryPeaks) / count($memoryPeaks);

            $results[] = new BenchmarkMetrics(
                searchEngine: $service->getName(),
                queryType: $this->detectQueryType($criteria),
                executionTime: $avgTime,
                resultCount: $resultCount,
                memoryUsage: $avgMemory,
                query: $criteria->query,
                executedAt: new \DateTime()
            );
        }

        return $results;
    }

    private function detectQueryType(SearchCriteria $criteria): string
    {
        if (!empty($criteria->filters)) {
            return 'hybrid_with_filters';
        }

        if (str_contains($criteria->query, ' AND ') || str_contains($criteria->query, ' OR ')) {
            return 'complex_boolean';
        }

        return 'simple_fulltext';
    }

    public function compareResults(array $benchmarks): array
    {
        if (empty($benchmarks)) {
            return [];
        }

        $baseline = $benchmarks[0];
        $comparisons = [];

        foreach ($benchmarks as $benchmark) {
            $speedupFactor = $benchmark->executionTime > 0
                ? $baseline->executionTime / $benchmark->executionTime
                : 0;
            $memoryRatio = $baseline->memoryUsage > 0
                ? $benchmark->memoryUsage / $baseline->memoryUsage
                : 0;

            $comparisons[] = [
                'engine' => $benchmark->searchEngine,
                'execution_time' => $benchmark->executionTime,
                'speedup_factor' => $speedupFactor,
                'memory_usage_mb' => $benchmark->memoryUsage / 1024 / 1024,
                'memory_ratio' => $memoryRatio,
                'result_count' => $benchmark->resultCount,
            ];
        }

        return $comparisons;
    }
}
