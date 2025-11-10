<?php

declare(strict_types=1);

namespace App\Application\DTO;

final readonly class SearchResultDTO
{
    public function __construct(
        public int $id,
        public string $title,
        public string $description,
        public ?float $relevance = null,
        public ?array $metadata = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            title: $data['title'] ?? $data['name'] ?? '',
            description: $data['description'] ?? '',
            relevance: isset($data['relevance']) ? (float)$data['relevance'] : null,
            metadata: $data
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'relevance' => $this->relevance,
            'metadata' => $this->metadata,
        ];
    }
}
