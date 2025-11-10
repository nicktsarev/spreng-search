<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Domain\Repository\CustomerRepositoryInterface;
use App\Domain\Repository\OrderRepositoryInterface;
use App\Domain\Repository\ProductRepositoryInterface;
use App\Domain\Repository\ProductReviewRepositoryInterface;
use App\Domain\Service\DataGeneratorService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-data',
    description: 'Generate fake test data for benchmark testing'
)]
class GenerateDataCommand extends Command
{
    private const int DEFAULT_CUSTOMERS = 100000;
    private const int DEFAULT_PRODUCTS = 50000;
    private const int DEFAULT_ORDERS = 200000;
    private const int DEFAULT_REVIEWS = 200000;

    public function __construct(
        private readonly DataGeneratorService $dataGenerator,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ProductReviewRepositoryInterface $reviewRepository,
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('customers', null, InputOption::VALUE_REQUIRED, 'Number of customers', self::DEFAULT_CUSTOMERS)
            ->addOption('products', null, InputOption::VALUE_REQUIRED, 'Number of products', self::DEFAULT_PRODUCTS)
            ->addOption('orders', null, InputOption::VALUE_REQUIRED, 'Number of orders', self::DEFAULT_ORDERS)
            ->addOption('reviews', null, InputOption::VALUE_REQUIRED, 'Number of reviews', self::DEFAULT_REVIEWS)
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear existing data before generation')
            ->addOption('skip-validation', null, InputOption::VALUE_NONE, 'Skip data validation after generation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->connection->getConfiguration()->setSQLLogger(null);

        $customersCount = (int) $input->getOption('customers');
        $productsCount = (int) $input->getOption('products');
        $ordersCount = (int) $input->getOption('orders');
        $reviewsCount = (int) $input->getOption('reviews');
        $shouldClear = $input->getOption('clear');
        $skipValidation = $input->getOption('skip-validation');

        $io->title('Benchmark Data Generator');

        $io->section('Generation Plan');
        $io->table(
            ['Entity', 'Count'],
            [
                ['Customers', number_format($customersCount)],
                ['Products', number_format($productsCount)],
                ['Orders', number_format($ordersCount)],
                ['Reviews', number_format($reviewsCount)],
            ]
        );

        if (!$io->confirm('Proceed with data generation?', true)) {
            $io->warning('Data generation cancelled');
            return Command::SUCCESS;
        }

        $totalStartTime = microtime(true);

        try {
            if ($shouldClear) {
                $io->section('Clearing existing data...');
                $this->dataGenerator->clearAllData();
                $io->success('Existing data cleared');
            }

            $io->section('Validating database connection...');
            if (!$this->validateDatabaseConnection($io)) {
                return Command::FAILURE;
            }

            $io->section('Generating Customers');
            $startTime = microtime(true);
            $this->dataGenerator->setOutput($output);
            $this->dataGenerator->generateCustomers($customersCount);
            $duration = microtime(true) - $startTime;
            $io->success(sprintf(
                'Generated %s customers in %.2f seconds (%.0f records/sec)',
                number_format($customersCount),
                $duration,
                $duration > 0 ? $customersCount / $duration : 0
            ));

            $io->section('Generating Products');
            $startTime = microtime(true);
            $this->dataGenerator->generateProducts($productsCount);
            $duration = microtime(true) - $startTime;
            $io->success(sprintf(
                'Generated %s products in %.2f seconds (%.0f records/sec)',
                number_format($productsCount),
                $duration,
                $duration > 0 ? $productsCount / $duration : 0
            ));

            $io->section('Generating Orders (with items)');
            $startTime = microtime(true);
            $this->dataGenerator->generateOrders($ordersCount);
            $duration = microtime(true) - $startTime;
            $io->success(sprintf(
                'Generated %s orders in %.2f seconds (%.0f records/sec)',
                number_format($ordersCount),
                $duration,
                $duration > 0 ? $ordersCount / $duration : 0
            ));

            $io->section('Generating Product Reviews');
            $startTime = microtime(true);
            $this->dataGenerator->generateReviews($reviewsCount);
            $duration = microtime(true) - $startTime;
            $io->success(sprintf(
                'Generated %s reviews in %.2f seconds (%.0f records/sec)',
                number_format($reviewsCount),
                $duration,
                $duration > 0 ? $reviewsCount / $duration : 0
            ));

            if (!$skipValidation) {
                $io->section('Validating generated data...');
                if (!$this->validateGeneratedData($io)) {
                    return Command::FAILURE;
                }
            }

            $totalDuration = microtime(true) - $totalStartTime;
            $io->section('Generation Summary');
            $io->success(sprintf(
                'All data generated successfully in %.2f minutes',
                $totalDuration / 60
            ));

            $this->displayDataSummary($io);

        } catch (\Exception $e) {
            $io->error('Data generation failed: ' . $e->getMessage());
            $io->note('Stack trace: ' . $e->getTraceAsString());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function validateDatabaseConnection(SymfonyStyle $io): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1');
            $io->success('MariaDB connection: OK');
            return true;
        } catch (\Exception $e) {
            $io->error('MariaDB connection failed: ' . $e->getMessage());
            return false;
        }
    }

    private function validateGeneratedData(SymfonyStyle $io): bool
    {
        $validationErrors = [];

        $customerCount = $this->customerRepository->count();
        if ($customerCount === 0) {
            $validationErrors[] = 'No customers found in database';
        }

        $productCount = $this->productRepository->count();
        if ($productCount === 0) {
            $validationErrors[] = 'No products found in database';
        }

        $orderCount = $this->orderRepository->count();
        if ($orderCount === 0) {
            $validationErrors[] = 'No orders found in database';
        }

        $reviewCount = $this->reviewRepository->count();
        if ($reviewCount === 0) {
            $validationErrors[] = 'No reviews found in database';
        }

        if (!$this->validateFulltextIndexes($io)) {
            $validationErrors[] = 'Fulltext indexes validation failed';
        }

        if (!$this->validateJsonAttributes($io)) {
            $validationErrors[] = 'JSON attributes validation failed';
        }

        if (!$this->validateRelationships($io)) {
            $validationErrors[] = 'Foreign key relationships validation failed';
        }

        if (!empty($validationErrors)) {
            $io->error('Validation failed:');
            $io->listing($validationErrors);
            return false;
        }

        $io->success('All validations passed');
        return true;
    }

    private function validateFulltextIndexes(SymfonyStyle $io): bool
    {
        try {
            // Test products fulltext index
            $result = $this->connection->executeQuery(
                "SELECT COUNT(*) as cnt FROM products 
                WHERE MATCH(name, description, long_description, category, brand) 
                AGAINST ('test' IN NATURAL LANGUAGE MODE)"
            )->fetchAssociative();

            $io->writeln(sprintf('  - Products fulltext index: OK (test query returned %d results)', $result['cnt']));

            // Test customers fulltext index
            $result = $this->connection->executeQuery(
                "SELECT COUNT(*) as cnt FROM customers 
                WHERE MATCH(first_name, last_name) 
                AGAINST ('test' IN NATURAL LANGUAGE MODE)"
            )->fetchAssociative();

            $io->writeln(sprintf('  - Customers fulltext index: OK (test query returned %d results)', $result['cnt']));

            // Test reviews fulltext index
            $result = $this->connection->executeQuery(
                "SELECT COUNT(*) as cnt FROM product_reviews 
                WHERE MATCH(review_text) 
                AGAINST ('test' IN NATURAL LANGUAGE MODE)"
            )->fetchAssociative();

            $io->writeln(sprintf('  - Reviews fulltext index: OK (test query returned %d results)', $result['cnt']));

            return true;
        } catch (\Exception $e) {
            $io->error('Fulltext index validation failed: ' . $e->getMessage());
            return false;
        }
    }

    private function validateJsonAttributes(SymfonyStyle $io): bool
    {
        try {
            // Validate JSON virtual columns
            $result = $this->connection->executeQuery(
                "SELECT COUNT(*) as cnt FROM products WHERE attr_color IS NOT NULL"
            )->fetchAssociative();

            if ($result['cnt'] === 0) {
                $io->warning('No products with color attribute found');
                return false;
            }

            $io->writeln(sprintf('  - JSON virtual columns: OK (%d products with color)', $result['cnt']));

            // Test JSON extraction
            $result = $this->connection->executeQuery(
                "SELECT COUNT(*) as cnt FROM products 
                WHERE JSON_EXTRACT(attributes, '$.color') IS NOT NULL"
            )->fetchAssociative();

            $io->writeln(sprintf('  - JSON extraction: OK (%d products)', $result['cnt']));

            return true;
        } catch (\Exception $e) {
            $io->error('JSON attributes validation failed: ' . $e->getMessage());
            return false;
        }
    }

    private function validateRelationships(SymfonyStyle $io): bool
    {
        try {
            // Validate order-customer relationship
            $result = $this->connection->executeQuery(
                "SELECT COUNT(*) as cnt FROM orders o 
                LEFT JOIN customers c ON c.id = o.customer_id 
                WHERE c.id IS NULL"
            )->fetchAssociative();

            if ($result['cnt'] > 0) {
                $io->error(sprintf('Found %d orders with invalid customer references', $result['cnt']));
                return false;
            }

            $io->writeln('  - Order-Customer relationships: OK');

            // Validate order_items-product relationship
            $result = $this->connection->executeQuery(
                "SELECT COUNT(*) as cnt FROM order_items oi 
                LEFT JOIN products p ON p.id = oi.product_id 
                WHERE p.id IS NULL"
            )->fetchAssociative();

            if ($result['cnt'] > 0) {
                $io->error(sprintf('Found %d order items with invalid product references', $result['cnt']));
                return false;
            }

            $io->writeln('  - OrderItem-Product relationships: OK');

            // Validate reviews relationships
            $result = $this->connection->executeQuery(
                "SELECT COUNT(*) as cnt FROM product_reviews pr 
                LEFT JOIN products p ON p.id = pr.product_id 
                LEFT JOIN customers c ON c.id = pr.customer_id 
                WHERE p.id IS NULL OR c.id IS NULL"
            )->fetchAssociative();

            if ($result['cnt'] > 0) {
                $io->error(sprintf('Found %d reviews with invalid references', $result['cnt']));
                return false;
            }

            $io->writeln('  - Review relationships: OK');

            return true;
        } catch (\Exception $e) {
            $io->error('Relationship validation failed: ' . $e->getMessage());
            return false;
        }
    }

    private function displayDataSummary(SymfonyStyle $io): void
    {
        $customerCount = $this->customerRepository->count();
        $productCount = $this->productRepository->count();
        $orderCount = $this->orderRepository->count();
        $reviewCount = $this->reviewRepository->count();

        // Get order items count
        $orderItemsCount = $this->connection->executeQuery(
            'SELECT COUNT(*) as cnt FROM order_items'
        )->fetchAssociative()['cnt'];

        // Get database size
        $dbSize = $this->connection->executeQuery(
            "SELECT 
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb 
            FROM information_schema.TABLES 
            WHERE table_schema = DATABASE()"
        )->fetchAssociative()['size_mb'];

        $io->table(
            ['Entity', 'Count'],
            [
                ['Customers', number_format($customerCount)],
                ['Products', number_format($productCount)],
                ['Orders', number_format($orderCount)],
                ['Order Items', number_format($orderItemsCount)],
                ['Reviews', number_format($reviewCount)],
                ['Total Records', number_format($customerCount + $productCount + $orderCount + $orderItemsCount + $reviewCount)],
                ['Database Size', $dbSize . ' MB'],
            ]
        );

        $io->note([
            'Data generation complete!',
            'Next steps:',
            '1. Run: docker exec search_sphinx indexer --all --rotate --config /opt/sphinx/conf/sphinx.conf',
            '2. Access API: http://localhost:8080/api/benchmark?iterations=8',
        ]);
    }
}
