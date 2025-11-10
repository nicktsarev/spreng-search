<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Entity\Customer;
use App\Domain\Entity\Order;
use App\Domain\Entity\OrderItem;
use App\Domain\Entity\OrderStatus;
use App\Domain\Entity\Product;
use App\Domain\Repository\CustomerRepositoryInterface;
use App\Domain\Repository\ProductRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Faker\Factory;
use Faker\Generator;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class DataGeneratorService
{
    private const int BATCH_SIZE = 1000;
    private const int ORDER_BATCH_SIZE = 800;

    private Generator $faker;
    private ?OutputInterface $output = null;
    private Connection $connection;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ProductRepositoryInterface $productRepository
    ) {
        $this->faker = Factory::create();
        $this->connection = $entityManager->getConnection();
    }

    public function setOutput(?OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function generateCustomers(int $count): void
    {
        $progressBar = $this->createProgressBar($count);
        $sql = 'INSERT INTO customers (first_name, last_name, email, phone, address, city, country, postal_code, notes, created_at, updated_at) VALUES ';
        $params = [];
        $valueStrings = [];
        $batchCounter = 0;
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $this->faker->unique(true);

        $this->connection->beginTransaction();
        try {
            for ($i = 1; $i <= $count; $i++) {
                $progressBar?->advance();
                $createdAt = $this->faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d H:i:s');

                $valueStrings[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $params = [
                    ...$params,
                    $this->faker->firstName(),
                    $this->faker->lastName(),
                    $this->faker->unique()->email(),
                    $this->faker->phoneNumber(),
                    $this->faker->streetAddress(),
                    $this->faker->city(),
                    $this->faker->country(),
                    $this->faker->postcode(),
                    $this->faker->optional(0.3)->paragraph(),
                    $createdAt,
                    $now,
                ];

                $batchCounter++;

                if ($batchCounter === self::BATCH_SIZE) {
                    $this->executeRawInsert($sql, $valueStrings, $params);
                    $params = [];
                    $valueStrings = [];
                    $batchCounter = 0;
                }
            }

            if ($batchCounter > 0) {
                $this->executeRawInsert($sql, $valueStrings, $params);
            }
            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $progressBar?->finish();
        $this->output?->writeln('');
    }

    public function generateProducts(int $count): void
    {
        $progressBar = $this->createProgressBar($count);

        $categories = ['Electronics', 'Clothing', 'Books', 'Home & Garden', 'Sports', 'Toys', 'Food', 'Beauty', 'Automotive', 'Health'];
        $brands = ['Samsung', 'Apple', 'Sony', 'LG', 'Nike', 'Adidas', 'Canon', 'Dell', 'HP', 'Lenovo', 'Asus', 'Microsoft'];
        $colors = ['Red', 'Blue', 'Green', 'Black', 'White', 'Yellow', 'Purple', 'Orange', 'Pink', 'Brown'];
        $sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'One Size'];
        $materials = ['Cotton', 'Polyester', 'Leather', 'Wood', 'Metal', 'Plastic', 'Glass', 'Ceramic'];

        // Realistic product name templates by category
        $productTemplates = [
            'Electronics' => [
                'laptop', 'computer', 'smartphone', 'phone', 'tablet', 'monitor', 'keyboard',
                'mouse', 'headphones', 'speaker', 'camera', 'smartwatch', 'television', 'TV',
                'charger', 'cable', 'router', 'printer', 'scanner', 'webcam', 'microphone',
                'gaming console', 'controller', 'wireless headphones', 'bluetooth speaker',
                'gaming laptop', 'ultrabook', 'desktop computer', 'all-in-one PC',
                'portable charger', 'power bank', 'USB hub', 'external hard drive', 'SSD',
                'graphics card', 'processor', 'motherboard', 'RAM', 'network adapter'
            ],
            'Clothing' => [
                't-shirt', 'jeans', 'jacket', 'sweater', 'hoodie', 'dress', 'skirt', 'pants',
                'shorts', 'socks', 'shoes', 'sneakers', 'boots', 'sandals', 'cap', 'hat',
                'scarf', 'gloves', 'belt', 'tie', 'suit', 'blazer', 'coat', 'sweatpants'
            ],
            'Books' => [
                'novel', 'textbook', 'cookbook', 'biography', 'magazine', 'journal', 'comic book',
                'dictionary', 'encyclopedia', 'atlas', 'guide', 'manual', 'workbook'
            ],
            'Home & Garden' => [
                'furniture', 'chair', 'table', 'sofa', 'bed', 'lamp', 'rug', 'curtain',
                'plant pot', 'garden tool', 'lawn mower', 'hose', 'sprinkler', 'grill',
                'vacuum cleaner', 'iron', 'fan', 'heater', 'air purifier', 'humidifier'
            ],
            'Sports' => [
                'basketball', 'football', 'soccer ball', 'tennis racket', 'golf club',
                'yoga mat', 'dumbbells', 'treadmill', 'bicycle', 'helmet', 'knee pads',
                'running shoes', 'gym bag', 'water bottle', 'fitness tracker'
            ],
            'Toys' => [
                'action figure', 'doll', 'board game', 'puzzle', 'building blocks',
                'remote control car', 'stuffed animal', 'toy train', 'LEGO set', 'card game'
            ],
            'Food' => [
                'coffee', 'tea', 'chocolate', 'snacks', 'pasta', 'rice', 'cereal',
                'canned food', 'spices', 'sauce', 'oil', 'flour', 'sugar', 'cookies'
            ],
            'Beauty' => [
                'shampoo', 'conditioner', 'soap', 'lotion', 'perfume', 'lipstick',
                'nail polish', 'face cream', 'sunscreen', 'deodorant', 'makeup kit'
            ],
            'Automotive' => [
                'car battery', 'tire', 'oil filter', 'air filter', 'brake pad',
                'windshield wiper', 'car mat', 'car charger', 'dash cam', 'GPS navigator'
            ],
            'Health' => [
                'vitamins', 'supplements', 'first aid kit', 'thermometer', 'blood pressure monitor',
                'face mask', 'hand sanitizer', 'pain relief', 'bandages', 'medicine'
            ]
        ];

        $qualifiers = ['premium', 'professional', 'portable', 'wireless', 'smart', 'digital', 'analog',
            'compact', 'mini', 'ultra', 'pro', 'advanced', 'basic', 'deluxe', 'standard',
            'ergonomic', 'lightweight', 'heavy-duty', 'eco-friendly', 'rechargeable'];

        $sql = 'INSERT INTO products (sku, name, description, long_description, category, brand, price, stock_quantity, attributes, tags, specifications, created_at, updated_at) VALUES ';
        $params = [];
        $valueStrings = [];
        $batchCounter = 0;
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $this->connection->beginTransaction();
        try {
            for ($i = 1; $i <= $count; $i++) {
                $progressBar?->advance();

                $category = $this->faker->randomElement($categories);
                $brand = $this->faker->randomElement($brands);

                // Generate realistic product name
                $baseProduct = $this->faker->randomElement($productTemplates[$category]);
                $productName = $this->faker->optional(0.6)->passthrough(
                    $this->faker->randomElement($qualifiers) . ' '
                ) . $baseProduct;

                // Capitalize first letter of each word
                $productName = ucwords($productName);

                // Generate realistic description
                $description = sprintf(
                    "High-quality %s from %s. %s Perfect for everyday use. %s",
                    $baseProduct,
                    $brand,
                    $this->faker->sentence(),
                    $this->faker->optional(0.7)->sentence()
                );

                $longDescription = $this->faker->optional(0.7)->passthrough(
                    sprintf(
                        "%s\n\nKey Features:\n- %s\n- %s\n- %s\n\n%s",
                        $description,
                        $this->faker->sentence(),
                        $this->faker->sentence(),
                        $this->faker->sentence(),
                        $this->faker->paragraph()
                    )
                );

                $attributes = [
                    'color' => $this->faker->randomElement($colors),
                    'size' => $this->faker->randomElement($sizes),
                    'material' => $this->faker->randomElement($materials),
                    'weight' => $this->faker->numberBetween(100, 5000) . 'g',
                ];
                $tags = $this->faker->randomElements(
                    ['new', 'sale', 'bestseller', 'limited', 'featured', 'eco-friendly', 'premium'],
                    rand(1, 4)
                );
                $specifications = [
                    'warranty' => $this->faker->randomElement(['1 year', '2 years', '3 years', 'Lifetime']),
                    'dimensions' => sprintf('%dx%dx%d cm', rand(10, 100), rand(10, 100), rand(10, 100)),
                    'origin' => $this->faker->country(),
                ];
                $createdAt = $this->faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d H:i:s');

                $valueStrings[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $params = [
                    ...$params,
                    'SKU-' . str_pad((string)$i, 8, '0', STR_PAD_LEFT),
                    $productName,
                    $description,
                    $longDescription,
                    $category,
                    $brand,
                    (string)$this->faker->randomFloat(2, 10, 9999),
                    $this->faker->numberBetween(0, 1000),
                    json_encode($attributes),
                    json_encode($tags),
                    json_encode($specifications),
                    $createdAt,
                    $now,
                ];

                $batchCounter++;

                if ($batchCounter === self::BATCH_SIZE) {
                    $this->executeRawInsert($sql, $valueStrings, $params);
                    $params = [];
                    $valueStrings = [];
                    $batchCounter = 0;
                }
            }

            if ($batchCounter > 0) {
                $this->executeRawInsert($sql, $valueStrings, $params);
            }
            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $progressBar?->finish();
        $this->output?->writeln('');
    }

    public function generateOrders(int $count): void
    {
        $customerCount = $this->customerRepository->count();
        $productCount = $this->productRepository->count(); // We still need this for the check

        if ($customerCount === 0 || $productCount === 0) {
            throw new \RuntimeException('Generate customers and products first');
        }

        $this->output?->writeln('Fetching product data into memory...');
        $productsData = $this->connection->fetchAllAssociative(
            'SELECT id, name, sku, price FROM products'
        );

        $productsMap = [];
        foreach ($productsData as $pd) {
            $productsMap[(int)$pd['id']] = $pd;
        }
        $validProductIds = array_keys($productsMap);
        unset($productsData);

        if (empty($validProductIds)) {
            throw new \RuntimeException('No product IDs found, cannot generate orders.');
        }
        $this->output?->writeln('Product data fetched. Starting order generation...');


        $progressBar = $this->createProgressBar($count);

        $statuses = [
            OrderStatus::PENDING,
            OrderStatus::PROCESSING,
            OrderStatus::SHIPPED,
            OrderStatus::DELIVERED,
            OrderStatus::CANCELLED,
        ];

        $paymentMethods = ['Credit Card', 'PayPal', 'Bank Transfer', 'Cash on Delivery'];
        $shippingMethods = ['Standard', 'Express', 'Next Day', 'International'];

        $batch = [];

        for ($i = 1; $i <= $count; $i++) {
            $progressBar?->advance();

            $customerId = rand(1, $customerCount);
            $customerRef = $this->entityManager->getReference(Customer::class, $customerId);

            $order = new Order();
            $order->setCustomer($customerRef);
            $order->setOrderNumber('ORD-' . date('Y') . '-' . str_pad((string)$i, 8, '0', STR_PAD_LEFT));
            $order->setStatus($this->faker->randomElement($statuses));
            $order->setShippingAddress($this->faker->address());
            $order->setBillingAddress($this->faker->address());
            $order->setPaymentMethod($this->faker->randomElement($paymentMethods));
            $order->setShippingMethod($this->faker->randomElement($shippingMethods));
            $order->setTrackingNumber($this->faker->optional(0.7)->bothify('TRACK-##??##??####'));
            $order->setNotes($this->faker->optional(0.3)->sentence());

            $metadata = [
                'gift_message' => $this->faker->optional(0.2)->sentence(),
                'special_instructions' => $this->faker->optional(0.3)->sentence(),
            ];
            $order->setMetadata($metadata);

            $createdAt = $this->faker->dateTimeBetween('-1 year', 'now');
            $order->setCreatedAt($createdAt);
            $order->setUpdatedAt($createdAt);

            if ($order->getStatus() === OrderStatus::SHIPPED || $order->getStatus() === OrderStatus::DELIVERED) {
                $shippedAt = (clone $createdAt)->modify('+' . rand(1, 5) . ' days');
                $order->setShippedAt($shippedAt);

                if ($order->getStatus() === OrderStatus::DELIVERED) {
                    $deliveredAt = (clone $shippedAt)->modify('+' . rand(1, 7) . ' days');
                    $order->setDeliveredAt($deliveredAt);
                }
            }

            $itemsCount = rand(1, 5);
            $totalAmount = 0;
            $seenProductIds = [];

            for ($j = 0; $j < $itemsCount; $j++) {
                $productId = $validProductIds[array_rand($validProductIds)];

                if (in_array($productId, $seenProductIds, true)) {
                    continue;
                }
                $seenProductIds[] = $productId;

                $productData = $productsMap[$productId];
                $productRef = $this->entityManager->getReference(Product::class, $productId);

                $realPrice = $productData['price'];
                $quantity = rand(1, 3);
                $itemTotal = (float)$realPrice * $quantity;

                $orderItem = new OrderItem();
                $orderItem->setOrder($order);
                $orderItem->setProduct($productRef);
                $orderItem->setQuantity($quantity);
                $orderItem->setUnitPrice($realPrice);
                $orderItem->setTotalPrice((string)$itemTotal);
                $orderItem->setDiscountAmount((string)$this->faker->randomFloat(2, 0, $itemTotal * 0.2));

                $snapshot = [
                    'name' => $productData['name'],
                    'sku' => $productData['sku'],
                    'price' => $productData['price'],
                ];
                $orderItem->setProductSnapshot($snapshot);

                $orderItem->setCreatedAt($createdAt);

                $order->addItem($orderItem);
                $totalAmount += $itemTotal;
            }

            $order->setTotalAmount((string)$totalAmount);
            $batch[] = $order;

            if ($i % self::ORDER_BATCH_SIZE === 0) {
                $this->flushBatch($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->flushBatch($batch);
        }

        $progressBar?->finish();
        $this->output?->writeln('');
    }

    public function generateReviews(int $count): void
    {
        $customerCount = $this->customerRepository->count();
        $productCount = $this->productRepository->count();

        if ($customerCount === 0 || $productCount === 0) {
            throw new \RuntimeException('Generate customers and products first');
        }

        $this->output?->writeln('Fetching product and customer counts...');
        $productIds = $this->connection->fetchFirstColumn('SELECT id FROM products');
        $customerIds = $this->connection->fetchFirstColumn('SELECT id FROM customers');

        if (empty($productIds) || empty($customerIds)) {
            throw new \RuntimeException('No customers or products found to review.');
        }
        $this->output?->writeln('Data fetched. Starting review generation...');

        $progressBar = $this->createProgressBar($count);

        $sql = 'INSERT INTO product_reviews (customer_id, product_id, rating, title, review_text, helpful_count, verified_purchase, created_at, updated_at) VALUES ';
        $params = [];
        $valueStrings = [];
        $batchCounter = 0;
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $this->connection->beginTransaction();
        try {
            for ($i = 1; $i <= $count; $i++) {
                $progressBar?->advance();

                $customerId = $customerIds[array_rand($customerIds)];
                $productId = $productIds[array_rand($productIds)];

                $createdAt = $this->faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d H:i:s');

                $valueStrings[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $params = [
                    ...$params,
                    $customerId,
                    $productId,
                    rand(1, 5),
                    $this->faker->optional(0.8)->sentence(),
                    $this->faker->paragraphs(rand(1, 3), true),
                    rand(0, 50),
                    $this->faker->boolean(70) ? 1 : 0,
                    $createdAt,
                    $now,
                ];

                $batchCounter++;

                if ($batchCounter === self::BATCH_SIZE) {
                    $this->executeRawInsert($sql, $valueStrings, $params);
                    $params = [];
                    $valueStrings = [];
                    $batchCounter = 0;
                }
            }

            if ($batchCounter > 0) {
                $this->executeRawInsert($sql, $valueStrings, $params);
            }
            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $progressBar?->finish();
        $this->output?->writeln('');
    }

    private function createProgressBar(int $max): ?ProgressBar
    {
        if ($this->output === null) {
            return null;
        }

        $progressBar = new ProgressBar($this->output, $max);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %elapsed:6s%/%estimated:-6s% - %memory:6s%');
        $progressBar->start();

        return $progressBar;
    }

    private function flushBatch(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();
        gc_collect_cycles(); // Force garbage collection
    }

    private function executeRawInsert(string $sql, array $valueStrings, array $params): void
    {
        if (empty($valueStrings)) {
            return;
        }

        $fullSql = $sql . implode(', ', $valueStrings);
        $this->connection->executeStatement($fullSql, $params);
    }

    public function clearAllData(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $this->connection->executeStatement('TRUNCATE TABLE product_reviews');
        $this->connection->executeStatement('TRUNCATE TABLE order_items');
        $this->connection->executeStatement('TRUNCATE TABLE orders');
        $this->connection->executeStatement('TRUNCATE TABLE products');
        $this->connection->executeStatement('TRUNCATE TABLE customers');
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
