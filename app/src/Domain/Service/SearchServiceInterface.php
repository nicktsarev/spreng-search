<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\ValueObject\SearchCriteria;

interface SearchServiceInterface
{
    public function search(SearchCriteria $criteria): array;

    public function getName(): string;

    public function warmup(): void;

    /**
     * Search with aggregation (group by category, brand, price range, etc)
     */
    public function searchWithAggregation(SearchCriteria $criteria): array;

    /**
     * Search across customers table
     */
    public function searchAcrossCustomers(SearchCriteria $criteria): array;

    /**
     * Search across product reviews table
     */
    public function searchAcrossReviews(SearchCriteria $criteria): array;

    /**
     * Search across orders table
     */
    public function searchAcrossOrders(SearchCriteria $criteria): array;

    /**
     * Multi-table join search (products with customers/orders/reviews)
     */
    public function searchWithJoin(SearchCriteria $criteria): array;

    /**
     * UNION search across multiple tables
     */
    public function searchUnion(SearchCriteria $criteria): array;

    /**
     * Get supported boolean operators for this search engine
     * @return array ['AND', 'OR', 'NOT', '+', '-', '*', '"', '()']
     */
    public function getSupportedBooleanOperators(): array;

    /**
     * Check if this engine supports JSON search
     */
    public function supportsJsonSearch(): bool;

    /**
     * Check if this engine supports query expansion
     */
    public function supportsQueryExpansion(): bool;

    /**
     * Check if this engine supports proximity search
     */
    public function supportsProximitySearch(): bool;

    /**
     * Get all supported capabilities with their status
     * @return array ['capability_name' => true/false]
     */
    public function getCapabilities(): array;
}
