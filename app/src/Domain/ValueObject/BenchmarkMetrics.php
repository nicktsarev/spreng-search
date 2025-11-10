<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

final readonly class BenchmarkMetrics
{
    public function __construct(
        public string $searchEngine,
        public string $queryType,
        public float $executionTime,
        public int $resultCount,
        public float $memoryUsage,
        public ?string $query = null,
        public ?\DateTimeInterface $executedAt = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'search_engine' => $this->searchEngine,
            'query_type' => $this->queryType,
            'execution_time' => $this->executionTime,
            'result_count' => $this->resultCount,
            'memory_usage' => $this->memoryUsage,
            'query' => $this->query,
            'executed_at' => $this->executedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
