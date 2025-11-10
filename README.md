# MariaDB vs Sphinx Search Benchmark

## Overview

This project is a comprehensive benchmark application designed to test and compare the full-text search performance of **MariaDB 10.11+** against **Sphinx Search 3.4.1**. The primary goal is to determine if MariaDB's modern full-text search capabilities (including JSON functions and improved indexing) can match or exceed Sphinx's performance in real-world e-commerce search scenarios.

### Success Criteria
MariaDB search response time is not slower (or minimally slower) than Sphinx for:
- Simple full-text searches
- Complex multi-table joins with full-text
- Hybrid searches (full-text + JSON attributes + filters)
- Boolean queries
- Aggregation queries

### Test Dataset
- **100,000 customers** with full-text indexed names and addresses
- **50,000 products** with full-text descriptions and JSON attributes
- **500,000 orders** linking customers to products
- **1,500,000+ order items** for join testing
- **200,000 product reviews** with full-text indexed content
- **Total: ~2.35 million records** across 5 tables

---

## Technology Stack

- **PHP 8.4** with Symfony 7.2
- **MariaDB 10.11+** (InnoDB with full-text indexes, JSON virtual columns)
- **Sphinx Search** (macbre/sphinxsearch:latest)
- **Docker & Docker Compose** for containerization
- **Nginx** as web server
- **Faker** for test data generation

---

## Architecture

### Hexagonal (Ports & Adapters) DDD Architecture
```
┌────────────────────────────────────────────────────────────┐
│                    Presentation Layer                      │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Controllers (REST API)                             │   │
│  │  - SearchController                                 │   │
│  │  - /api/search/mariadb                              │   │
│  │  - /api/search/sphinx                               │   │
│  │  - /api/benchmark                                   │   │
│  └─────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────┘
↓
┌────────────────────────────────────────────────────────────┐
│                    Application Layer                       │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Commands & Queries (CQRS)                          │   │
│  │  - GenerateDataCommand                              │   │
│  │  - BenchmarkCommand                                 │   │
│  └─────────────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  DTOs                                               │   │
│  │  - SearchResultDTO                                  │   │
│  │  - BenchmarkResultDTO                               │   │
│  └─────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────┘
↓
┌────────────────────────────────────────────────────────────┐
│                     Domain Layer                           │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Entities                                           │   │
│  │  - Customer, Product, Order, OrderItem, Review      │   │
│  └─────────────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Value Objects                                      │   │
│  │  - SearchCriteria, BenchmarkMetrics                 │   │
│  └─────────────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Repository Interfaces                              │   │
│  │  - CustomerRepositoryInterface                      │   │
│  │  - ProductRepositoryInterface                       │   │
│  └─────────────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Services                                           │   │
│  │  - SearchServiceInterface                           │   │
│  │  - BenchmarkService                                 │   │
│  │  - DataGeneratorService                             │   │
│  └─────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────┘
↓
┌────────────────────────────────────────────────────────────┐
│                 Infrastructure Layer                       │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Persistence (Doctrine ORM)                         │   │
│  │  - CustomerRepository                               │   │
│  │  - ProductRepository                                │   │
│  │  - OrderRepository, etc.                            │   │
│  └─────────────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Search Implementations                             │   │
│  │  - MariaDbSearchService                             │   │
│  │  - SphinxSearchService                              │   │
│  │  - SphinxConnection                                 │   │
│  └─────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────┘
```

### Project Structure
```
search-test/
├── docker-compose.yml                      # Container orchestration
├── deploy/
│   └── containers/
│       ├── nginx/
│       │   └── default.conf               # Nginx configuration
│       ├── php/
│       │   └── Dockerfile                 # PHP 8.4 with extensions
│       ├── sphinx/
│       │   └── sphinx.conf                # Sphinx indexer config
│       └── mariadb/
│           └── init.sql                   # DB initialization
├── app/
│   ├── bin/
│   │   └── console                        # Symfony console
│   ├── config/
│   │   ├── packages/
│   │   │   └── doctrine.yaml              # ORM & DBAL config
│   │   ├── routes.yaml
│   │   └── services.yaml                  # DI container
│   ├── migrations/
│   │   └── Version20240101000000.php      # Initial schema
│   ├── src/
│   │   ├── Domain/
│   │   │   ├── Entity/                    # Business entities
│   │   │   │   ├── Customer.php
│   │   │   │   ├── Product.php
│   │   │   │   ├── Order.php
│   │   │   │   ├── OrderItem.php
│   │   │   │   ├── OrderStatus.php
│   │   │   │   └── ProductReview.php
│   │   │   ├── Repository/                # Repository interfaces
│   │   │   │   ├── CustomerRepositoryInterface.php
│   │   │   │   ├── ProductRepositoryInterface.php
│   │   │   │   └── ...
│   │   │   ├── Service/                   # Domain services
│   │   │   │   ├── SearchServiceInterface.php
│   │   │   │   ├── BenchmarkService.php
│   │   │   │   └── DataGeneratorService.php
│   │   │   └── ValueObject/               # Value objects
│   │   │       ├── SearchCriteria.php
│   │   │       └── BenchmarkMetrics.php
│   │   ├── Application/
│   │   │   └── DTO/                       # Data transfer objects
│   │   │       ├── SearchResultDTO.php
│   │   │       └── BenchmarkResultDTO.php
│   │   ├── Infrastructure/
│   │   │   ├── Persistence/
│   │   │   │   └── Doctrine/
│   │   │   │       └── Repository/        # Concrete repositories
│   │   │   ├── Search/                    # Search implementations
│   │   │   │   ├── MariaDbSearchService.php
│   │   │   │   ├── SphinxSearchService.php
│   │   │   │   └── SphinxConnection.php
│   │   │   └── Console/                   # CLI commands
│   │   │       └── GenerateDataCommand.php
│   │   ├── Presentation/
│   │   │   └── Controller/
│   │   │       └── SearchController.php   # REST API endpoints
│   │   └── Kernel.php
│   ├── .env                               # Environment config
│   └── composer.json
└── README.md
```

## Installation & Setup

### Prerequisites

- **Ubuntu Linux** (20.04+ recommended)
- **Docker** 20.10+
- **Docker Compose** 2.0+
- Minimum **16GB RAM**
- Minimum **10GB free disk space**

### Clone & Start

```
# Clone repository
git clone <repository-url>
cd search-test

# Create directory structure
mkdir -p deploy/containers/{nginx,sphinx,php,mariadb}
mkdir -p app

# Start containers
docker-compose up -d

# Wait for services to be ready (30-60 seconds)
docker-compose ps

# Check logs
docker-compose logs -f
```

### Install Symfony Application

```
# Enter PHP container
docker exec -it search_php bash

# Install dependencies
composer install

# Verify database connection
php bin/console doctrine:query:sql "SELECT 1"

# Exit container
exit
```

---

## Usage

### Step 1: Generate Test Data

Generate 2.35+ million records for benchmarking:
```
docker exec -it search_php php bin/console app:generate-data
```

Options:
* --customers=N - Number of customers (default: 100,000)
* --products=N - Number of products (default: 50,000)
* --orders=N - Number of orders (default: 500,000)
* --reviews=N - Number of reviews (default: 200,000)
* --clear - Clear existing data before generation
* --skip-validation - Skip data validation

**Example with custom counts:**
```
docker exec -it search_php php bin/console app:generate-data \
  --customers=50000 \
  --products=25000 \
  --orders=250000 \
  --reviews=100000
```

**Generation time:** Approximately 15-30 minutes depending on hardware.

Validation includes:

* Record counts verification
* Full-text index functionality tests
* JSON virtual column validation
* Foreign key relationship integrity
* Database size reporting

### Step 2: Build Sphinx Indexes

Index data from MariaDB into Sphinx:

```
docker exec search_sphinx indexer --all --rotate --config /opt/sphinx/conf/sphinx.conf
```

This command:

* Verifies Sphinx searchd connectivity
* Runs indexer --all --rotate inside Sphinx container
* Validates indexed document counts
* Reports indexing duration

**Indexing time:** Approximately 2-5 minutes for 500k records.

---

## API Endpoints

Access the REST API at http://localhost:8080/api

### Search Endpoints

**Search with MariaDB**

```bash
# Basic search
GET /api/search/mariadb?q=laptop&limit=10

# With filters
GET /api/search/mariadb?q=phone&category=Electronics&min_price=100&max_price=500

# With search mode (NATURAL, BOOLEAN, QUERY_EXPANSION)
GET /api/search/mariadb?q=+wireless+headphones&mode=BOOLEAN

# With brand filter
GET /api/search/mariadb?q=laptop&brand=Apple&color=black
```

**Response:**
```json
{
  "engine": "MariaDB",
  "query": "laptop",
  "execution_time": 0.0234,
  "result_count": 42,
  "results": [
    {
      "id": 1234,
      "name": "Gaming Laptop Pro",
      "description": "High performance laptop...",
      "relevance": 2.456
    }
  ]
}
```

**Search with Sphinx:**

```
GET /api/search/sphinx?q=laptop&limit=10
```


**Run Benchmark via API**
```bash
GET /api/benchmark
```
```
Returns HTML output with results of executed requests.
```

---
