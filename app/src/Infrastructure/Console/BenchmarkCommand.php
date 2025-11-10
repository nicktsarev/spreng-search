<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Domain\Service\BenchmarkService;
use App\Domain\ValueObject\SearchCriteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:benchmark',
    description: 'Run search benchmark tests'
)]
class BenchmarkCommand extends Command
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
        private readonly BenchmarkService $benchmarkService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('query', null, InputOption::VALUE_REQUIRED, 'Custom search query')
            ->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'Number of iterations per test', 10)
            ->addOption('all', null, InputOption::VALUE_NONE, 'Run all predefined test queries')
            ->addOption('extended', null, InputOption::VALUE_NONE, 'Run extended test suite (all capabilities)')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter tests by category (e.g., boolean, filtering, aggregations)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Search Performance Benchmark');

        $iterations = (int) $input->getOption('iterations');
        $customQuery = $input->getOption('query');
        $runAll = $input->getOption('all');
        $extended = $input->getOption('extended');
        $categoryFilter = $input->getOption('category');

        if ($customQuery) {
            $this->runSingleBenchmark($io, $customQuery, $iterations);
        } elseif ($runAll || $extended) {
            $this->runAllBenchmarks($io, $iterations, $categoryFilter);
        } else {
            $io->note('Running default benchmark query. Use --all to run all tests, --extended for comprehensive suite, or --query to specify custom query.');
            $testConfig = self::TEST_QUERIES['simple'];
            $this->runSingleBenchmark($io, $testConfig['query'], $iterations);
        }

        return Command::SUCCESS;
    }

    private function runSingleBenchmark(SymfonyStyle $io, string $query, int $iterations): void
    {
        $io->section(sprintf('Running benchmark for query: "%s"', $query));

        $criteria = new SearchCriteria(
            query: $query,
            limit: 100,
            offset: 0
        );

        $results = $this->benchmarkService->runBenchmark($criteria, $iterations);
        $comparison = $this->benchmarkService->compareResults($results);

        $this->displayResults($io, $comparison);
    }

    private function runAllBenchmarks(SymfonyStyle $io, int $iterations, ?string $categoryFilter = null): void
    {
        $allResults = [];

        foreach (self::TEST_QUERIES as $type => $config) {
            // Category filtering
            $category = $this->getCategoryFromType($type);
            if ($categoryFilter && !str_contains(strtolower($category), strtolower($categoryFilter))) {
                continue;
            }

            $io->section(sprintf('Testing "%s" (%s): "%s"', $type, $category, $config['query']));

            $criteria = $this->buildCriteriaFromConfig($config);

            $results = $this->benchmarkService->runBenchmark($criteria, $iterations);
            $comparison = $this->benchmarkService->compareResults($results);

            $this->displayResults($io, $comparison);
            $allResults[$type] = $comparison;
        }

        $this->displaySummary($io, $allResults);
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

    private function displayResults(SymfonyStyle $io, array $comparison): void
    {
        $tableData = [];

        foreach ($comparison as $result) {
            $tableData[] = [
                $result['engine'],
                sprintf('%.2f ms', $result['execution_time'] * 1000),
                number_format($result['result_count']),
            ];
        }

        $io->table(
            ['Engine', 'Avg Time', 'Results'],
            $tableData
        );

        // Winner is the one with lowest execution time
        $winner = 'N/A';
        if (count($comparison) >= 2) {
            $winner = $comparison[0]['execution_time'] < $comparison[1]['execution_time']
                ? $comparison[0]['engine']
                : $comparison[1]['engine'];
        }
        $io->success(sprintf('Winner: %s', $winner));
    }

    private function displaySummary(SymfonyStyle $io, array $allResults): void
    {
        $io->section('Overall Summary');

        $mariadbWins = 0;
        $sphinxWins = 0;

        foreach ($allResults as $type => $comparison) {
            if (isset($comparison[0], $comparison[1])) {
                if ($comparison[0]['execution_time'] < $comparison[1]['execution_time']) {
                    if ($comparison[0]['engine'] === 'MariaDB') {
                        $mariadbWins++;
                    } else {
                        $sphinxWins++;
                    }
                } else {
                    if ($comparison[1]['engine'] === 'MariaDB') {
                        $mariadbWins++;
                    } else {
                        $sphinxWins++;
                    }
                }
            }
        }

        $io->writeln([
            sprintf('MariaDB wins: %d', $mariadbWins),
            sprintf('Sphinx wins: %d', $sphinxWins),
        ]);

        if ($mariadbWins > $sphinxWins) {
            $io->success('Overall winner: MariaDB');
        } elseif ($sphinxWins > $mariadbWins) {
            $io->success('Overall winner: Sphinx');
        } else {
            $io->info('Result: Tie');
        }
    }
}
