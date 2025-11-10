<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Repository;

use App\Domain\Entity\OrderItem;
use App\Domain\Repository\OrderItemRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OrderItemRepository extends ServiceEntityRepository implements OrderItemRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderItem::class);
    }

    public function save(OrderItem $orderItem): void
    {
        $this->getEntityManager()->persist($orderItem);
        $this->getEntityManager()->flush();
    }

    public function saveAll(array $orderItems): void
    {
        $em = $this->getEntityManager();
        foreach ($orderItems as $orderItem) {
            $em->persist($orderItem);
        }
        $em->flush();
    }

    public function findById(int $id): ?OrderItem
    {
        return $this->find($id);
    }

    public function count(array $criteria = []): int
    {
        return (int) $this->createQueryBuilder('oi')
            ->select('COUNT(oi.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
