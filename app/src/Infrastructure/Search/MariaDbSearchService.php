<?php

declare(strict_types=1);

namespace App\Infrastructure\Search;

use App\Domain\Service\SearchServiceInterface;
use App\Domain\ValueObject\SearchCriteria;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

class MariaDbSearchService implements SearchServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function search(SearchCriteria $criteria): array
    {
        if ($criteria->aggregateBy !== null) {
            return $this->searchWithAggregation($criteria);
        }

        if ($criteria->useUnion && $criteria->joinTables !== null) {
            return $this->searchUnion($criteria);
        }

        if ($criteria->joinTables !== null) {
            return $this->searchWithJoin($criteria);
        }

        $conn = $this->entityManager->getConnection();

        $sql = $this->buildQuery($criteria);
        $stmt = $conn->prepare($sql);

        $params = $this->buildParams($criteria);
        return $this->executeQueryWithTypes($stmt, $params);
    }

    public function getName(): string
    {
        return 'MariaDB';
    }

    public function warmup(): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeQuery('SELECT 1');
    }

    private function buildQuery(SearchCriteria $criteria): string
    {
        // Determine match mode
        $matchMode = match($criteria->matchMode) {
            'BOOLEAN' => 'IN BOOLEAN MODE',
            'QUERY_EXPANSION' => 'WITH QUERY EXPANSION',
            default => 'IN NATURAL LANGUAGE MODE'
        };

        $sql = "SELECT p.id, p.name, p.description, p.category, p.brand, p.price,
                MATCH(p.name, p.description, p.long_description, p.category, p.brand)
                AGAINST (:query $matchMode) as relevance
                FROM products p
                WHERE MATCH(p.name, p.description, p.long_description, p.category, p.brand)
                AGAINST (:query $matchMode)";

        // Apply filters
        if (!empty($criteria->filters)) {
            if (isset($criteria->filters['category'])) {
                $sql .= ' AND p.category = :category';
            }
            if (isset($criteria->filters['min_price'])) {
                $sql .= ' AND p.price >= :min_price';
            }
            if (isset($criteria->filters['max_price'])) {
                $sql .= ' AND p.price <= :max_price';
            }
            if (isset($criteria->filters['color'])) {
                $sql .= ' AND p.attr_color = :color';
            }
            if (isset($criteria->filters['brand'])) {
                $sql .= ' AND p.brand = :brand';
            }
        }

        // Apply JSON filters
        if (!empty($criteria->jsonFilters)) {
            foreach ($criteria->jsonFilters as $key => $value) {
                if (str_contains($key, 'tags')) {
                    $sql .= " AND JSON_CONTAINS(p.tags, :json_$key)";
                } elseif (str_starts_with($key, 'specs.')) {
                    $jsonPath = str_replace('specs.', '', $key);
                    $sql .= " AND JSON_EXTRACT(p.specifications, '$.$jsonPath') = :json_$key";
                }
            }
        }

        // Apply date filters
        if (!empty($criteria->dateFilters)) {
            if (isset($criteria->dateFilters['from'])) {
                $sql .= ' AND p.created_at >= :date_from';
            }
            if (isset($criteria->dateFilters['to'])) {
                $sql .= ' AND p.created_at <= :date_to';
            }
        }

        // In stock filter
        if ($criteria->inStockOnly) {
            $sql .= ' AND p.stock_quantity > 0';
        }

        // Apply ordering
        if (!empty($criteria->orderBy)) {
            $orderClauses = [];
            foreach ($criteria->orderBy as $field => $direction) {
                if ($field === 'relevance_price_weighted') {
                    $orderClauses[] = "(MATCH(p.name, p.description, p.long_description, p.category, p.brand)
                                       AGAINST (:query $matchMode) * 10 + (1000 - p.price)) DESC";
                } else {
                    $orderClauses[] = "p.$field $direction";
                }
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderClauses);
        } else {
            $sql .= ' ORDER BY relevance DESC';
        }

        $sql .= ' LIMIT :limit OFFSET :offset';

        return $sql;
    }

    private function buildParams(SearchCriteria $criteria): array
    {
        $params = [
            'query' => $criteria->query,
            'limit' => $criteria->limit ?? 100,
            'offset' => $criteria->offset ?? 0,
        ];

        if (!empty($criteria->filters)) {
            foreach ($criteria->filters as $key => $value) {
                $params[$key] = $value;
            }
        }

        if (!empty($criteria->jsonFilters)) {
            foreach ($criteria->jsonFilters as $key => $value) {
                if (str_contains($key, 'tags')) {
                    $params["json_$key"] = json_encode($value);
                } else {
                    $params["json_$key"] = $value;
                }
            }
        }

        if (!empty($criteria->dateFilters)) {
            if (isset($criteria->dateFilters['from'])) {
                $params['date_from'] = $criteria->dateFilters['from'];
            }
            if (isset($criteria->dateFilters['to'])) {
                $params['date_to'] = $criteria->dateFilters['to'];
            }
        }

        return $params;
    }

    private function executeQueryWithTypes(\Doctrine\DBAL\Statement $stmt, array $params): array
    {
        // Bind all parameters with explicit types
        foreach ($params as $key => $value) {
            $type = match ($key) {
                'limit', 'offset', 'min_price', 'max_price', 'min_rating' => ParameterType::INTEGER,
                default => ParameterType::STRING
            };
            $stmt->bindValue($key, $value, $type);
        }

        return $stmt->executeQuery()->fetchAllAssociative();
    }

    public function searchWithJoin(SearchCriteria $criteria): array
    {
        $conn = $this->entityManager->getConnection();
        $matchMode = $criteria->matchMode === 'BOOLEAN' ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';

        // Determine which tables to join
        $joinTables = $criteria->joinTables ?? ['customers', 'orders'];

        $selectFields = ['p.id', 'p.name', 'p.description'];
        $joins = ['INNER JOIN order_items oi ON oi.product_id = p.id'];
        $whereConditions = [];
        $relevanceFields = [];

        if (in_array('orders', $joinTables)) {
            $joins[] = 'INNER JOIN orders o ON o.id = oi.order_id';
            $selectFields[] = 'o.order_number';
            // Use ft_order_notes index (notes)
            $selectFields[] = "MATCH(o.notes) AGAINST (:query $matchMode) as o_relevance";
            $whereConditions[] = "MATCH(o.notes) AGAINST (:query $matchMode)";
            $relevanceFields[] = 'o_relevance';
        }

        if (in_array('customers', $joinTables)) {
            if (!in_array('orders', $joinTables)) {
                $joins[] = 'INNER JOIN orders o ON o.id = oi.order_id';
            }
            $joins[] = 'INNER JOIN customers c ON c.id = o.customer_id';
            $selectFields[] = 'c.first_name';
            $selectFields[] = 'c.last_name';
            // Use ft_customer_search index (first_name, last_name, email, address, notes)
            $selectFields[] = "MATCH(c.first_name, c.last_name, c.email, c.address, c.notes) AGAINST (:query $matchMode) as c_relevance";
            $whereConditions[] = "MATCH(c.first_name, c.last_name, c.email, c.address, c.notes) AGAINST (:query $matchMode)";
            $relevanceFields[] = 'c_relevance';
        }

        if (in_array('reviews', $joinTables)) {
            $joins[] = 'LEFT JOIN product_reviews pr ON pr.product_id = p.id';
            $selectFields[] = 'pr.rating';
            // Use ft_review_full index (title, review_text)
            $selectFields[] = "MATCH(pr.title, pr.review_text) AGAINST (:query $matchMode) as r_relevance";
            $whereConditions[] = "MATCH(pr.title, pr.review_text) AGAINST (:query $matchMode)";
            $relevanceFields[] = 'r_relevance';
        }

        // Use ft_product_full index (name, description, long_description, category, brand)
        $selectFields[] = "MATCH(p.name, p.description, p.long_description, p.category, p.brand) AGAINST (:query $matchMode) as p_relevance";
        $whereConditions[] = "MATCH(p.name, p.description, p.long_description, p.category, p.brand) AGAINST (:query $matchMode)";
        $relevanceFields[] = 'p_relevance';

        $relevanceSum = implode(' + ', $relevanceFields);

        $sql = 'SELECT ' . implode(', ', $selectFields) . '
                FROM products p
                ' . implode(' ', $joins) . '
                WHERE (' . implode(' OR ', $whereConditions) . ')
                ORDER BY (' . $relevanceSum . ') DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $conn->prepare($sql);
        return $this->executeQueryWithTypes($stmt, [
            'query' => $criteria->query,
            'limit' => $criteria->limit ?? 100,
            'offset' => $criteria->offset ?? 0,
        ]);
    }

    public function searchWithAggregation(SearchCriteria $criteria): array
    {
        $conn = $this->entityManager->getConnection();
        $matchMode = $criteria->matchMode === 'BOOLEAN' ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';

        $aggregateField = match($criteria->aggregateBy) {
            'brand' => 'p.brand',
            'category' => 'p.category',
            'rating' => 'pr.rating',
            default => 'p.category'
        };

        if ($criteria->aggregateBy === 'price_range') {
            $sql = "SELECT
                        CASE
                            WHEN p.price < 100 THEN '0-100'
                            WHEN p.price < 500 THEN '100-500'
                            WHEN p.price < 1000 THEN '500-1000'
                            ELSE '1000+'
                        END as price_range,
                        COUNT(*) as count,
                        AVG(MATCH(p.name, p.description, p.long_description, p.category, p.brand)
                            AGAINST (:query $matchMode)) as avg_relevance
                    FROM products p
                    WHERE MATCH(p.name, p.description, p.long_description, p.category, p.brand)
                          AGAINST (:query $matchMode)
                    GROUP BY price_range
                    ORDER BY avg_relevance DESC";
        } elseif ($criteria->aggregateBy === 'rating') {
            $sql = "SELECT
                        pr.rating,
                        COUNT(*) as count,
                        AVG(MATCH(pr.title, pr.review_text) AGAINST (:query $matchMode)) as avg_relevance
                    FROM product_reviews pr
                    WHERE MATCH(pr.title, pr.review_text) AGAINST (:query $matchMode)
                    GROUP BY pr.rating
                    ORDER BY pr.rating DESC";
        } else {
            $sql = "SELECT
                        $aggregateField as group_by_field,
                        COUNT(*) as count,
                        AVG(MATCH(p.name, p.description, p.long_description, p.category, p.brand)
                            AGAINST (:query $matchMode)) as avg_relevance,
                        AVG(p.price) as avg_price
                    FROM products p
                    WHERE MATCH(p.name, p.description, p.long_description, p.category, p.brand)
                          AGAINST (:query $matchMode)
                    GROUP BY $aggregateField
                    HAVING count > 0
                    ORDER BY avg_relevance DESC
                    LIMIT :limit";
        }

        $stmt = $conn->prepare($sql);
        $params = ['query' => $criteria->query];
        if ($criteria->aggregateBy !== 'price_range' && $criteria->aggregateBy !== 'rating') {
            $params['limit'] = $criteria->limit ?? 50;
        }

        return $this->executeQueryWithTypes($stmt, $params);
    }

    public function searchAcrossCustomers(SearchCriteria $criteria): array
    {
        $conn = $this->entityManager->getConnection();
        $matchMode = $criteria->matchMode === 'BOOLEAN' ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';

        $sql = "SELECT
                    c.id,
                    c.first_name,
                    c.last_name,
                    c.email,
                    c.city,
                    c.country,
                    MATCH(c.first_name, c.last_name, c.email, c.address, c.notes)
                    AGAINST (:query $matchMode) as relevance
                FROM customers c
                WHERE MATCH(c.first_name, c.last_name, c.email, c.address, c.notes)
                      AGAINST (:query $matchMode)
                ORDER BY relevance DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $conn->prepare($sql);
        return $this->executeQueryWithTypes($stmt, [
            'query' => $criteria->query,
            'limit' => $criteria->limit ?? 100,
            'offset' => $criteria->offset ?? 0,
        ]);
    }

    public function searchAcrossReviews(SearchCriteria $criteria): array
    {
        $conn = $this->entityManager->getConnection();
        $matchMode = $criteria->matchMode === 'BOOLEAN' ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';

        $sql = "SELECT
                    pr.id,
                    pr.product_id,
                    pr.title,
                    pr.review_text,
                    pr.rating,
                    pr.verified_purchase,
                    MATCH(pr.title, pr.review_text) AGAINST (:query $matchMode) as relevance
                FROM product_reviews pr
                WHERE MATCH(pr.title, pr.review_text) AGAINST (:query $matchMode)";

        if (!empty($criteria->filters['min_rating'])) {
            $sql .= ' AND pr.rating >= :min_rating';
        }
        if (isset($criteria->filters['verified_only']) && $criteria->filters['verified_only']) {
            $sql .= ' AND pr.verified_purchase = TRUE';
        }

        $sql .= ' ORDER BY relevance DESC LIMIT :limit OFFSET :offset';

        $stmt = $conn->prepare($sql);
        $params = [
            'query' => $criteria->query,
            'limit' => $criteria->limit ?? 100,
            'offset' => $criteria->offset ?? 0,
        ];

        if (!empty($criteria->filters['min_rating'])) {
            $params['min_rating'] = $criteria->filters['min_rating'];
        }

        return $this->executeQueryWithTypes($stmt, $params);
    }

    public function searchAcrossOrders(SearchCriteria $criteria): array
    {
        $conn = $this->entityManager->getConnection();
        $matchMode = $criteria->matchMode === 'BOOLEAN' ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';

        $sql = "SELECT
                    o.id,
                    o.order_number,
                    o.status,
                    o.total_amount,
                    o.created_at,
                    MATCH(o.notes, o.shipping_address, o.billing_address)
                    AGAINST (:query $matchMode) as relevance
                FROM orders o
                WHERE MATCH(o.notes, o.shipping_address, o.billing_address)
                      AGAINST (:query $matchMode)";

        if (!empty($criteria->filters['status'])) {
            $sql .= ' AND o.status = :status';
        }

        $sql .= ' ORDER BY relevance DESC LIMIT :limit OFFSET :offset';

        $stmt = $conn->prepare($sql);
        $params = [
            'query' => $criteria->query,
            'limit' => $criteria->limit ?? 100,
            'offset' => $criteria->offset ?? 0,
        ];

        if (!empty($criteria->filters['status'])) {
            $params['status'] = $criteria->filters['status'];
        }

        return $this->executeQueryWithTypes($stmt, $params);
    }

    public function searchUnion(SearchCriteria $criteria): array
    {
        $conn = $this->entityManager->getConnection();
        $matchMode = $criteria->matchMode === 'BOOLEAN' ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';

        $unions = [];

        if (in_array('products', $criteria->joinTables ?? [])) {
            $unions[] = "(SELECT 'product' as source_type, p.id, p.name as title, p.description,
                         MATCH(p.name, p.description, p.long_description, p.category, p.brand) AGAINST (:query $matchMode) as relevance
                         FROM products p
                         WHERE MATCH(p.name, p.description, p.long_description, p.category, p.brand) AGAINST (:query $matchMode))";
        }

        if (in_array('customers', $criteria->joinTables ?? [])) {
            $unions[] = "(SELECT 'customer' as source_type, c.id,
                         CONCAT(c.first_name, ' ', c.last_name) as title, c.email as description,
                         MATCH(c.first_name, c.last_name, c.email, c.address, c.notes) AGAINST (:query $matchMode) as relevance
                         FROM customers c
                         WHERE MATCH(c.first_name, c.last_name, c.email, c.address, c.notes) AGAINST (:query $matchMode))";
        }

        if (in_array('reviews', $criteria->joinTables ?? [])) {
            $unions[] = "(SELECT 'review' as source_type, pr.id, pr.title, pr.review_text as description,
                         MATCH(pr.title, pr.review_text) AGAINST (:query $matchMode) as relevance
                         FROM product_reviews pr
                         WHERE MATCH(pr.title, pr.review_text) AGAINST (:query $matchMode))";
        }

        if (empty($unions)) {
            return [];
        }

        $sql = implode(' UNION ALL ', $unions) . ' ORDER BY relevance DESC LIMIT :limit OFFSET :offset';

        $stmt = $conn->prepare($sql);
        return $this->executeQueryWithTypes($stmt, [
            'query' => $criteria->query,
            'limit' => $criteria->limit ?? 100,
            'offset' => $criteria->offset ?? 0,
        ]);
    }

    public function getSupportedBooleanOperators(): array
    {
        return ['+', '-', '>', '<', '(', ')', '~', '*', '"', 'AND', 'OR', 'NOT'];
    }

    public function supportsJsonSearch(): bool
    {
        return true;
    }

    public function supportsQueryExpansion(): bool
    {
        return true;
    }

    public function supportsProximitySearch(): bool
    {
        return false; // MariaDB doesn't support @distance proximity
    }

    public function getCapabilities(): array
    {
        return [
            'natural_language_search' => true,
            'boolean_mode_search' => true,
            'query_expansion' => true,
            'phrase_matching' => true,
            'wildcard_search' => true,
            'proximity_search' => false,
            'multi_table_joins' => true,
            'union_search' => true,
            'json_filtering' => true,
            'json_virtual_columns' => true,
            'aggregations' => true,
            'faceted_search' => true,
            'customer_search' => true,
            'order_search' => true,
            'review_search' => true,
            'date_filtering' => true,
            'price_filtering' => true,
            'stock_filtering' => true,
            'category_filtering' => true,
            'brand_filtering' => true,
            'custom_sorting' => true,
            'relevance_weighting' => true,
            'deep_pagination' => true,
        ];
    }
}
