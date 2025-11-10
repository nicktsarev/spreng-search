<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Customer;

interface CustomerRepositoryInterface
{
    public function save(Customer $customer): void;

    public function saveAll(array $customers): void;

    public function findById(int $id): ?Customer;

    public function findAll(int $limit = 100, int $offset = 0): array;

    public function count(): int;
}
