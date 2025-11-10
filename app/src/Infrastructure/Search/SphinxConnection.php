<?php

declare(strict_types=1);

namespace App\Infrastructure\Search;

use Doctrine\DBAL\Connection;

class SphinxConnection
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function isAvailable(): bool
    {
        try {
            $this->connection->executeQuery('SHOW STATUS');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
