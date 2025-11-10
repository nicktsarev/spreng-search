<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\OrderItem;

interface OrderItemRepositoryInterface
{
    public function save(OrderItem $orderItem): void;

    public function saveAll(array $orderItems): void;

    public function findById(int $id): ?OrderItem;

    public function count(): int;
}
