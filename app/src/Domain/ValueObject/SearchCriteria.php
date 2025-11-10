<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

final readonly class SearchCriteria
{
    public function __construct(
        public string $query,
        public ?array $filters = null,
        public ?int $limit = 100,
        public ?int $offset = 0,
        public ?array $orderBy = null,
        public ?string $matchMode = 'NATURAL', // NATURAL, BOOLEAN, QUERY_EXPANSION
        public ?string $aggregateBy = null, // category, brand, price_range, rating, etc
        public ?array $joinTables = null, // ['customers', 'reviews', 'orders']
        public ?bool $useUnion = false,
        public ?array $jsonFilters = null, // ['tags' => 'premium', 'specs.weight' => '<2']
        public ?array $dateFilters = null, // ['from' => '2024-01-01', 'to' => '2024-12-31']
        public ?bool $inStockOnly = false,
    ) {
    }
}
