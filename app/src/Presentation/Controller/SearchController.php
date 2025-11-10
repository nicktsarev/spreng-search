<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\Service\BenchmarkService;
use App\Domain\ValueObject\SearchCriteria;
use App\Infrastructure\Search\MariaDbSearchService;
use App\Infrastructure\Search\SphinxSearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api')]
class SearchController extends AbstractController
{
    private const array TEST_QUERIES = [
        // Basic queries
        'simple' => ['query' => 'laptop computer', 'mode' => 'NATURAL'],
        'complex' => ['query' => 'wireless bluetooth headphones premium quality', 'mode' => 'NATURAL'],
        'partial' => ['query' => 'elect', 'mode' => 'NATURAL'],

        // Boolean mode queries
        'boolean_required' => ['query' => '+wireless +headphones', 'mode' => 'BOOLEAN'],
        'boolean_exclude' => ['query' => 'phone -samsung', 'mode' => 'BOOLEAN'],
        'boolean_complex' => ['query' => '(wireless OR bluetooth) +headphones', 'mode' => 'BOOLEAN'],
        'phrase_match' => ['query' => '"gaming laptop"', 'mode' => 'BOOLEAN'],
        'wildcard' => ['query' => 'electron*', 'mode' => 'BOOLEAN'],

        // Query expansion
        'query_expansion' => ['query' => 'laptop', 'mode' => 'QUERY_EXPANSION'],

        // Filtering
        'with_category' => ['query' => 'laptop', 'mode' => 'NATURAL', 'filters' => ['category' => 'Electronics']],
        'with_price_range' => ['query' => 'phone', 'mode' => 'NATURAL', 'filters' => ['min_price' => 100, 'max_price' => 500]],
        'with_json_filter' => ['query' => 'laptop', 'mode' => 'NATURAL', 'filters' => ['color' => 'black']],

        // Aggregations
        'aggregate_category' => ['query' => 'wireless', 'mode' => 'NATURAL', 'aggregate' => 'category'],
        'aggregate_brand' => ['query' => 'phone', 'mode' => 'NATURAL', 'aggregate' => 'brand'],
        'aggregate_price_range' => ['query' => 'electronics', 'mode' => 'NATURAL', 'aggregate' => 'price_range'],

        // Multi-table
        'join_customers' => ['query' => 'laptop', 'mode' => 'NATURAL', 'join' => ['customers', 'orders']],
        'union_search' => ['query' => 'john', 'mode' => 'NATURAL', 'union' => ['products', 'customers']],

        // Sorting
        'sort_price_asc' => ['query' => 'laptop', 'mode' => 'NATURAL', 'sort' => ['price' => 'ASC']],
        'sort_price_desc' => ['query' => 'phone', 'mode' => 'NATURAL', 'sort' => ['price' => 'DESC']],

        // Pagination
        'deep_pagination' => ['query' => 'product', 'mode' => 'NATURAL', 'offset' => 1000, 'limit' => 20],

        // Edge cases
        'single_char' => ['query' => 'a', 'mode' => 'NATURAL'],
        'common_words' => ['query' => 'the best product', 'mode' => 'NATURAL'],
    ];

    public function __construct(
        private readonly MariaDbSearchService $mariaDbSearch,
        private readonly SphinxSearchService $sphinxSearch,
        private readonly BenchmarkService $benchmarkService
    ) {
    }

    #[Route('/search/mariadb', methods: ['GET'])]
    public function searchMariaDb(Request $request): JsonResponse
    {
        $criteria = $this->buildCriteriaFromRequest($request);

        $startTime = microtime(true);
        $results = $this->mariaDbSearch->search($criteria);
        $executionTime = microtime(true) - $startTime;

        return $this->json([
            'engine' => 'MariaDB',
            'query' => $criteria->query,
            'execution_time' => $executionTime,
            'result_count' => count($results),
            'results' => $results,
        ]);
    }

    #[Route('/search/sphinx', methods: ['GET'])]
    public function searchSphinx(Request $request): JsonResponse
    {
        $criteria = $this->buildCriteriaFromRequest($request);

        $startTime = microtime(true);
        $results = $this->sphinxSearch->search($criteria);
        $executionTime = microtime(true) - $startTime;

        return $this->json([
            'engine' => 'Sphinx',
            'query' => $criteria->query,
            'execution_time' => $executionTime,
            'result_count' => count($results),
            'results' => $results,
        ]);
    }

    #[Route('/benchmark', methods: ['GET'])]
    public function benchmark(Request $request): Response
    {
        $iterations = (int) ($request->query->get('iterations', 3));
        $limit = (int) ($request->query->get('limit', 20));
        $allResults = [];

        foreach (self::TEST_QUERIES as $type => $config) {
            $criteria = $this->buildCriteriaFromConfig($config);

            // Limit result sets to reduce memory usage
            $criteria = new SearchCriteria(
                query: $criteria->query,
                filters: $criteria->filters,
                limit: min($criteria->limit ?? $limit, $limit),
                offset: $criteria->offset ?? 0,
                orderBy: $criteria->orderBy,
                matchMode: $criteria->matchMode,
                aggregateBy: $criteria->aggregateBy,
                joinTables: $criteria->joinTables,
                useUnion: $criteria->useUnion
            );

            $benchmarks = $this->benchmarkService->runBenchmark($criteria, $iterations);
            $comparison = $this->benchmarkService->compareResults($benchmarks);

            $allResults[] = [
                'type' => $type,
                'category' => $this->getCategoryFromType($type),
                'query' => $config['query'],
                'comparison' => $comparison,
            ];

            gc_collect_cycles();
        }

        return $this->renderBenchmarkHtml($allResults, $iterations);
    }

    private function buildCriteriaFromRequest(Request $request): SearchCriteria
    {
        $filters = [];

        if ($request->query->has('category')) {
            $filters['category'] = $request->query->get('category');
        }
        if ($request->query->has('min_price')) {
            $filters['min_price'] = $request->query->get('min_price');
        }
        if ($request->query->has('max_price')) {
            $filters['max_price'] = $request->query->get('max_price');
        }
        if ($request->query->has('color')) {
            $filters['color'] = $request->query->get('color');
        }
        if ($request->query->has('brand')) {
            $filters['brand'] = $request->query->get('brand');
        }

        $matchMode = strtoupper($request->query->get('mode', 'NATURAL'));
        if (!in_array($matchMode, ['NATURAL', 'BOOLEAN', 'QUERY_EXPANSION'])) {
            $matchMode = 'NATURAL';
        }

        return new SearchCriteria(
            query: $request->query->get('q', ''),
            filters: !empty($filters) ? $filters : null,
            limit: (int)$request->query->get('limit', 100),
            offset: (int)$request->query->get('offset', 0),
            matchMode: $matchMode
        );
    }

    private function buildCriteriaFromConfig(array $config): SearchCriteria
    {
        return new SearchCriteria(
            query: $config['query'],
            filters: $config['filters'] ?? null,
            limit: $config['limit'] ?? 100,
            offset: $config['offset'] ?? 0,
            orderBy: $config['sort'] ?? null,
            matchMode: $config['mode'] ?? 'NATURAL',
            aggregateBy: $config['aggregate'] ?? null,
            joinTables: $config['join'] ?? ($config['union'] ?? null),
            useUnion: isset($config['union'])
        );
    }

    private function getCategoryFromType(string $type): string
    {
        return match(true) {
            str_contains($type, 'boolean') || str_contains($type, 'phrase') || str_contains($type, 'wildcard') || str_contains($type, 'expansion') => 'Search Modes',
            str_contains($type, 'filter') || str_contains($type, 'with_') => 'Filtering',
            str_contains($type, 'aggregate') => 'Aggregations',
            str_contains($type, 'join') || str_contains($type, 'union') => 'Multi-Table',
            str_contains($type, 'sort') => 'Sorting',
            str_contains($type, 'pagination') => 'Pagination',
            default => 'Basic'
        };
    }

    private function renderBenchmarkHtml(array $allResults, int $iterations): Response
    {
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Performance Benchmark Results</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
        }
        .meta {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .category-group {
            margin-bottom: 40px;
        }
        .category-title {
            font-size: 20px;
            font-weight: 600;
            color: #444;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e0e0e0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
            font-size: 13px;
            color: #495057;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .test-name {
            font-weight: 500;
            color: #212529;
        }
        .query {
            color: #6c757d;
            font-family: "Courier New", monospace;
            font-size: 12px;
        }
        .winner {
            font-weight: 600;
            color: #28a745;
        }
        .result-count {
            color: #6c757d;
        }
        .summary {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin-top: 30px;
        }
        .summary h2 {
            margin-top: 0;
            color: #333;
            font-size: 18px;
        }
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .stat {
            background: white;
            padding: 15px;
            border-radius: 4px;
            border-left: 3px solid #007bff;
        }
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-value {
            font-size: 24px;
            font-weight: 600;
            color: #212529;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Search Performance Benchmark Results</h1>
        <div class="meta">Iterations per test: ' . $iterations . ' | Total tests: ' . count($allResults) . '</div>';

        // Group by category
        $grouped = [];
        foreach ($allResults as $result) {
            $grouped[$result['category']][] = $result;
        }

        // Calculate overall stats
        $mariadbWins = 0;
        $sphinxWins = 0;

        foreach ($allResults as $result) {
            $comparison = $result['comparison'];
            if (count($comparison) >= 2) {
                $mariadb = $comparison[0]['engine'] === 'MariaDB' ? $comparison[0] : $comparison[1];
                $sphinx = $comparison[0]['engine'] === 'Sphinx' ? $comparison[0] : $comparison[1];

                if ($mariadb['execution_time'] < $sphinx['execution_time']) {
                    $mariadbWins++;
                } else {
                    $sphinxWins++;
                }
            }
        }

        // Render each category
        foreach ($grouped as $category => $tests) {
            $html .= '<div class="category-group">
                <h2 class="category-title">' . htmlspecialchars($category) . '</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Test</th>
                            <th>Query</th>
                            <th>Engine</th>
                            <th>Avg Time</th>
                            <th>Results</th>
                        </tr>
                    </thead>
                    <tbody>';

            foreach ($tests as $test) {
                $comparison = $test['comparison'];
                $rowspan = count($comparison);

                // Find winner (lowest execution time)
                $winnerEngine = null;
                $minTime = PHP_FLOAT_MAX;
                foreach ($comparison as $engine) {
                    if ($engine['execution_time'] < $minTime) {
                        $minTime = $engine['execution_time'];
                        $winnerEngine = $engine['engine'];
                    }
                }

                foreach ($comparison as $idx => $engine) {
                    $html .= '<tr>';

                    if ($idx === 0) {
                        $html .= '<td rowspan="' . $rowspan . '" class="test-name">' . htmlspecialchars($test['type']) . '</td>';
                        $html .= '<td rowspan="' . $rowspan . '" class="query">' . htmlspecialchars($test['query']) . '</td>';
                    }

                    $winner = $engine['engine'] === $winnerEngine ? ' winner' : '';

                    $html .= '<td class="' . $winner . '">' . htmlspecialchars($engine['engine']) . '</td>';
                    $html .= '<td>' . number_format($engine['execution_time'] * 1000, 2) . ' ms</td>';
                    $html .= '<td class="result-count">' . number_format($engine['result_count']) . '</td>';
                    $html .= '</tr>';
                }
            }

            $html .= '</tbody></table></div>';
        }

        // Overall summary
        $html .= '<div class="summary">
            <h2>Overall Summary</h2>
            <div class="summary-stats">
                <div class="stat">
                    <div class="stat-label">MariaDB Wins</div>
                    <div class="stat-value">' . $mariadbWins . '</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Sphinx Wins</div>
                    <div class="stat-value">' . $sphinxWins . '</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';

        return new Response($html);
    }
}
