<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\ProductReview;

interface ProductReviewRepositoryInterface
{
    public function save(ProductReview $review): void;

    public function saveAll(array $reviews): void;

    public function findById(int $id): ?ProductReview;

    public function findAll(int $limit = 100, int $offset = 0): array;

    public function count(): int;
}
