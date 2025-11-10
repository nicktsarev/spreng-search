<?php

declare(strict_types=1);

namespace App\Infrastructure\Search;

use App\Domain\Service\SearchServiceInterface;
use App\Domain\ValueObject\SearchCriteria;
use Doctrine\DBAL\ParameterType;

class SphinxSearchService implements SearchServiceInterface
{
    public function __construct(
        private readonly SphinxConnection $connection
    ) {
    }

    public function search(SearchCriteria $criteria): array
    {
        // Route to appropriate search method based on criteria
        if ($criteria->aggregateBy !== null) {
            return $this->searchWithAggregation($criteria);
        }

        if ($criteria->useUnion && $criteria->joinTables !== null) {
            return $this->searchUnion($criteria);
        }

        if ($criteria->joinTables !== null) {
            return $this->searchWithJoin($criteria);
        }

        $conn = $this->connection->getConnection();

        $sql = $this->buildQuery($criteria);
        $stmt = $conn->prepare($sql);

        $params = $this->buildParams($criteria);
        return $this->executeQueryWithTypes($stmt, $params);
    }

    public function getName(): string
    {
        return 'Sphinx';
    }

    public function warmup(): void
    {
        $conn = $this->connection->getConnection();
        $conn->executeQuery('SELECT 1');
    }

    private function buildQuery(SearchCriteria $criteria): string
    {
        // Sphinx searches across name, description, long_description fields
        $sql = 'SELECT id, WEIGHT() as relevance
                FROM products
                WHERE MATCH(:query)';

        if (!empty($criteria->filters)) {
            if (isset($criteria->filters['category'])) {
                $sql .= ' AND category = :category';
            }
            if (isset($criteria->filters['brand'])) {
                $sql .= ' AND brand = :brand';
            }
        }

        $sql .= ' ORDER BY relevance DESC';
        $sql .= ' LIMIT :offset, :limit';

        return $sql;
    }

    private function convertQueryForSphinx(string $query, string $matchMode = 'NATURAL'): string
    {
        // Convert MariaDB boolean operators to Sphinx syntax
        if ($matchMode === 'BOOLEAN' || str_contains($query, ' OR ') || str_contains($query, ' AND ')) {
            // Replace MariaDB-style operators with Sphinx operators
            $query = str_replace(' OR ', ' | ', $query);
            $query = str_replace(' AND ', ' & ', $query);
            return $query;
        }

        // Convert query to match Sphinx's extended syntax
        // For NATURAL mode, convert space-separated words to OR (|) to match MariaDB behavior
        if ($matchMode === 'NATURAL' && !str_contains($query, '"') &&
            !str_contains($query, '+') && !str_contains($query, '-') &&
            !str_contains($query, '|') && !str_contains($query, '(') &&
            !str_contains($query, '*')) {
            // Convert multi-word queries to OR syntax for natural language behavior
            $words = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY);
            if (count($words) > 1) {
                return implode(' | ', $words);
            }
        }

        return $query;
    }

    private function buildParams(SearchCriteria $criteria): array
    {
        $params = [
            'query' => $this->convertQueryForSphinx($criteria->query, $criteria->matchMode ?? 'NATURAL'),
            'limit' => $criteria->limit ?? 100,
            'offset' => $criteria->offset ?? 0,
        ];

        // Only add filters that Sphinx supports (category, brand)
        if (!empty($criteria->filters)) {
            if (isset($criteria->filters['category'])) {
                $params['category'] = $criteria->filters['category'];
            }
            if (isset($criteria->filters['brand'])) {
                $params['brand'] = $criteria->filters['brand'];
            }
        }

        return $params;
    }

    private function executeQueryWithTypes(\Doctrine\DBAL\Statement $stmt, array $params): array
    {
        // Bind all parameters with explicit types
        foreach ($params as $key => $value) {
            $type = match ($key) {
                'limit', 'offset', 'min_rating' => ParameterType::INTEGER,
                default => ParameterType::STRING
            };
            $stmt->bindValue($key, $value, $type);
        }

        return $stmt->executeQuery()->fetchAllAssociative();
    }

    public function rebuildIndex(): void
    {
        $conn = $this->connection->getConnection();
        $conn->executeStatement('FLUSH RTINDEX products');
    }

    public function searchWithAggregation(SearchCriteria $criteria): array
    {
        $conn = $this->connection->getConnection();

        if (!$criteria->aggregateBy) {
            return [];
        }

        if ($criteria->aggregateBy === 'category' || $criteria->aggregateBy === 'brand') {
            // Use FACET for aggregation
            $sql = 'SELECT * FROM products WHERE MATCH(:query)
                    FACET ' . $criteria->aggregateBy . '
                    ORDER BY COUNT(*) DESC
                    LIMIT :limit';

            $stmt = $conn->prepare($sql);
            return $this->executeQueryWithTypes($stmt, [
                'query' => $this->convertQueryForSphinx($criteria->query, $criteria->matchMode ?? 'NATURAL'),
                'limit' => $criteria->limit ?? 50,
            ]);
        }

        return [];
    }

    public function searchAcrossCustomers(SearchCriteria $criteria): array
    {
        $conn = $this->connection->getConnection();

        $sql = 'SELECT id, WEIGHT() as relevance
                FROM customers
                WHERE MATCH(:query)
                ORDER BY relevance DESC
                LIMIT :offset, :limit';

        $stmt = $conn->prepare($sql);
        return $this->executeQueryWithTypes($stmt, [
            'query' => $this->convertQueryForSphinx($criteria->query, $criteria->matchMode ?? 'NATURAL'),
            'limit' => $criteria->limit ?? 100,
            'offset' => $criteria->offset ?? 0,
        ]);
    }

    public function searchAcrossReviews(SearchCriteria $criteria): array
    {
        $conn = $this->connection->getConnection();

        $sql = 'SELECT id, rating, WEIGHT() as relevance
                FROM product_reviews
                WHERE MATCH(:query)';

        $params = [
            'query' => $this->convertQueryForSphinx($criteria->query, $criteria->matchMode ?? 'NATURAL'),
            'limit' => $criteria->limit ?? 100,
            'offset' => $criteria->offset ?? 0,
        ];

        if (!empty($criteria->filters['min_rating'])) {
            $sql .= ' AND rating >= :min_rating';
            $params['min_rating'] = $criteria->filters['min_rating'];
        }

        $sql .= ' ORDER BY relevance DESC LIMIT :offset, :limit';

        $stmt = $conn->prepare($sql);
        return $this->executeQueryWithTypes($stmt, $params);
    }

    public function searchAcrossOrders(SearchCriteria $criteria): array
    {
        $conn = $this->connection->getConnection();

        $sql = 'SELECT id, order_number, WEIGHT() as relevance
                FROM orders
                WHERE MATCH(:query)';

        $params = [
            'query' => $this->convertQueryForSphinx($criteria->query, $criteria->matchMode ?? 'NATURAL'),
            'limit' => $criteria->limit ?? 100,
            'offset' => $criteria->offset ?? 0,
        ];

        if (!empty($criteria->filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = $criteria->filters['status'];
        }

        $sql .= ' ORDER BY relevance DESC LIMIT :offset, :limit';

        $stmt = $conn->prepare($sql);
        return $this->executeQueryWithTypes($stmt, $params);
    }

    public function searchWithJoin(SearchCriteria $criteria): array
    {
        // Sphinx doesn't support SQL-style joins, return empty
        // Multi-table search would need to be done via searchUnion
        return [];
    }

    public function searchUnion(SearchCriteria $criteria): array
    {
        // Sphinx doesn't support UNION, but we can search multiple indexes separately
        // and combine results in PHP
        $results = [];
        $limitPerIndex = (int) ceil(($criteria->limit ?? 100) / count($criteria->joinTables ?? [1]));

        // Create limited criteria for each index to reduce memory usage
        $limitedCriteria = new SearchCriteria(
            query: $criteria->query,
            filters: $criteria->filters,
            limit: $limitPerIndex,
            offset: 0,
            matchMode: $criteria->matchMode
        );

        if (in_array('products', $criteria->joinTables ?? [], true)) {
            $products = $this->search($limitedCriteria);
            foreach ($products as $product) {
                $results[] = array_merge($product, ['source_type' => 'product']);
            }
            unset($products);
        }

        if (in_array('customers', $criteria->joinTables ?? [], true)) {
            $customers = $this->searchAcrossCustomers($limitedCriteria);
            foreach ($customers as $customer) {
                $results[] = array_merge($customer, ['source_type' => 'customer']);
            }
            unset($customers);
        }

        if (in_array('reviews', $criteria->joinTables ?? [], true)) {
            $reviews = $this->searchAcrossReviews($limitedCriteria);
            foreach ($reviews as $review) {
                $results[] = array_merge($review, ['source_type' => 'review']);
            }
            unset($reviews);
        }

        // Sort by relevance
        usort($results, fn($a, $b) => ($b['relevance'] ?? 0) <=> ($a['relevance'] ?? 0));

        // Apply final limit
        return array_slice($results, 0, $criteria->limit ?? 100);
    }

    public function getSupportedBooleanOperators(): array
    {
        return ['AND', 'OR', 'NOT', '|', '!', '"', '(', ')'];
    }

    public function supportsJsonSearch(): bool
    {
        return false; // Sphinx uses attributes, not JSON
    }

    public function supportsQueryExpansion(): bool
    {
        return true; // Sphinx has morphology/stemming
    }

    public function supportsProximitySearch(): bool
    {
        return true; // Sphinx supports "word1 word2"~N syntax
    }

    public function getCapabilities(): array
    {
        return [
            'natural_language_search' => true,
            'boolean_mode_search' => true,
            'query_expansion' => true, // via morphology (stem_en)
            'phrase_matching' => true,
            'wildcard_search' => true,
            'proximity_search' => true,
            'multi_table_joins' => false, // Sphinx doesn't support SQL-style JOINs
            'union_search' => true, // Simulated via multiple index searches
            'json_filtering' => false,
            'json_virtual_columns' => false,
            'aggregations' => true, // via FACET
            'faceted_search' => true, // via FACET
            'customer_search' => true, // separate customers index
            'order_search' => true, // separate orders index
            'review_search' => true, // separate product_reviews index
            'date_filtering' => true, // via created_at timestamp attribute
            'price_filtering' => false, // not configured as attribute
            'stock_filtering' => false, // not configured as attribute
            'category_filtering' => true, // configured as attr_string
            'brand_filtering' => true, // configured as attr_string
            'custom_sorting' => true,
            'relevance_weighting' => true,
            'deep_pagination' => true,
        ];
    }
}
