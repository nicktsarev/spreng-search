<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Repository;

use App\Domain\Entity\ProductReview;
use App\Domain\Repository\ProductReviewRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductReviewRepository extends ServiceEntityRepository implements ProductReviewRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductReview::class);
    }

    public function save(ProductReview $review): void
    {
        $this->getEntityManager()->persist($review);
        $this->getEntityManager()->flush();
    }

    public function saveAll(array $reviews): void
    {
        $em = $this->getEntityManager();
        foreach ($reviews as $review) {
            $em->persist($review);
        }
        $em->flush();
    }

    public function findById(int $id): ?ProductReview
    {
        return $this->find($id);
    }

    public function findAll(int $limit = 100, int $offset = 0): array
    {
        return $this->createQueryBuilder('pr')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function count(array $criteria = []): int
    {
        return (int) $this->createQueryBuilder('pr')
            ->select('COUNT(pr.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
