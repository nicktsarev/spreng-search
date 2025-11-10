<?php

declare(strict_types=1);

namespace App\Application\DTO;

final readonly class BenchmarkResultDTO
{
    public function __construct(
        public string $searchEngine,
        public string $queryType,
        public float $avgExecutionTime,
        public float $minExecutionTime,
        public float $maxExecutionTime,
        public int $resultCount,
        public float $avgMemoryUsage,
        public int $iterations,
        public ?\DateTimeInterface $executedAt = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'search_engine' => $this->searchEngine,
            'query_type' => $this->queryType,
            'avg_execution_time_ms' => round($this->avgExecutionTime * 1000, 2),
            'min_execution_time_ms' => round($this->minExecutionTime * 1000, 2),
            'max_execution_time_ms' => round($this->maxExecutionTime * 1000, 2),
            'result_count' => $this->resultCount,
            'avg_memory_usage_mb' => round($this->avgMemoryUsage / 1024 / 1024, 2),
            'iterations' => $this->iterations,
            'executed_at' => $this->executedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
