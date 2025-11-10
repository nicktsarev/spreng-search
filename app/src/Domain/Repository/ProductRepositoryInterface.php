<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Product;

interface ProductRepositoryInterface
{
    public function save(Product $product): void;

    public function saveAll(array $products): void;

    public function findById(int $id): ?Product;

    public function findBySku(string $sku): ?Product;

    public function findAll(int $limit = 100, int $offset = 0): array;

    public function count(): int;

    public function findRandom(int $count = 1): array;
}
