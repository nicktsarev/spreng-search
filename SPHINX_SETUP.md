# Sphinx Setup Guide

This project uses `macbre/sphinxsearch:latest` (Sphinx 3.8.1).

## Changes Made

### 1. Docker Image
- `macbre/sphinxsearch:latest` (Sphinx 3.8.1)

### 2. Volume Paths
Here are the paths to match the macbre image:
- Config: `/opt/sphinx/conf/sphinx.conf`
- Index: `/opt/sphinx/index`
- Logs: `/opt/sphinx/log`
- Reindex script: `/opt/sphinx/conf/reindex.sh`

### 3. Sphinx Configuration
Located in `deploy/containers/sphinx/sphinx.conf`

**Important:** Sphinx 3.8.1 compatibility changes:
- Moved attribute/field declarations from `source` to `index` section
- Fields must be declared before attributes
- Replaced deprecated `sql_attr_timestamp` with `attr_uint`
- Removed deprecated `max_matches` from searchd section

### 4. Indexer Command
```bash
indexer --all --rotate --config /opt/sphinx/conf/sphinx.conf
```

### 5. Automatic Indexing
Sphinx container automatically indexes on startup via docker-compose command override.

## Setup Instructions

### 1. Start Services
```bash
docker-compose up -d

docker-compose ps
```

Expected output:
```
search_nginx     running
search_php       running
search_mariadb   healthy
search_sphinx    running
```

### 2. Generate Test Data
```bash
# Enter PHP container or run from host
docker exec -it search_php php bin/console app:generate-data

# Or with custom counts
docker exec -it search_php php bin/console app:generate-data \
  --customers=50000 \
  --products=25000 \
  --orders=250000
```

### 3. Build Sphinx Indexes

**Note:** Indexes are automatically built on Sphinx container startup. To rebuild after data changes:

```bash
# Manually rebuild from host machine (required for reindexing)
docker exec search_sphinx indexer --all --rotate --config /opt/sphinx/conf/sphinx.conf

# Or use the convenience script
docker exec search_sphinx /opt/sphinx/conf/reindex.sh

# Or restart Sphinx (triggers automatic reindexing)
docker-compose restart sphinx
```

### 4. Verify Sphinx Is Working
```bash
# Check logs
docker logs search_sphinx

# Verify index files exist
docker exec search_sphinx ls -la /opt/sphinx/index/

# Test search via MariaDB protocol (port 9306)
docker exec search_mariadb mysql -h sphinx -P 9306 -e "SELECT * FROM products WHERE MATCH('laptop') LIMIT 5"
```

## Sphinx Configuration Details

The Sphinx configuration indexes:

**Fields (searchable):**
- `name` - Product name
- `description` - Short description
- `long_description` - Detailed description

**Attributes (filterable):**
- `category` (string) - Product category
- `brand` (string) - Product brand
- `created_at` (uint) - UNIX timestamp of creation

**Search Features:**
- Morphology: stem_en (English stemming)
- Min word length: 2
- Boolean operators: AND, OR, NOT, +, -, *, "phrase"
- Proximity search: "word1 word2"~N

## API Usage
ex
### Search with Sphinx
```bash
# Basic search
curl "http://localhost:8080/api/search/sphinx?q=laptop&limit=10"

# With category filter
curl "http://localhost:8080/api/search/sphinx?q=phone&category=Electronics"

# With brand filter
curl "http://localhost:8080/api/search/sphinx?q=laptop&brand=Apple"
```

### Compare MariaDB vs Sphinx
```bash
curl "http://localhost:8080/api/search/compare?q=wireless+headphones"
```

## Troubleshooting

### Issue: Sphinx container not starting
```bash
# Check container logs
docker logs search_sphinx

# Check if config is valid
docker exec search_sphinx cat /opt/sphinx/conf/sphinx.conf
```

### Issue: "No index files found"
This is normal on first run. You need to build indexes:
```bash
docker exec search_sphinx indexer --all --rotate --config /opt/sphinx/conf/sphinx.conf
```

### Issue: "Failed to connect to MariaDB"
Make sure MariaDB is healthy before Sphinx starts:
```bash
# Check MariaDB health
docker-compose ps mariadb

# Restart Sphinx after MariaDB is ready
docker-compose restart sphinx
```

### Issue: Indexer fails with "Unknown column 'title'"
The config has been updated to use `name` instead of `title`. If you see this:
1. Verify `deploy/containers/sphinx/sphinx.conf` has `sql_query = SELECT id, name, description...`
2. Restart the Sphinx container: `docker-compose restart sphinx`
3. Rebuild indexes: `docker exec search_sphinx indexer --all --rotate --config /opt/sphinx/conf/sphinx.conf`

### Issue: Search returns no results
```bash
# Check if indexes are built
docker exec search_sphinx ls -la /opt/sphinx/index/

# Verify index contains documents
docker exec search_mariadb mysql -h sphinx -P 9306 -e "SHOW INDEX products STATUS"

# Test simple search
docker exec search_mariadb mysql -h sphinx -P 9306 -e "SELECT COUNT(*) FROM products WHERE MATCH('test')"
```

### Issue: Sphinx 3.8.1 Configuration Errors
If you see errors like:
- `ERROR: key 'sql_attr_timestamp' was permanently removed`
- `ERROR: unknown key name 'max_matches'`
- `WARNING: field declarations must happen before attribute declarations`

The configuration has been updated for Sphinx 3.8.1 compatibility. Verify:
1. `deploy/containers/sphinx/sphinx.conf` uses `attr_uint` instead of `sql_attr_timestamp`
2. Attribute/field declarations are in `index` section (not `source`)
3. Fields are declared before attributes
4. Restart: `docker-compose restart sphinx`

### Issue: Cannot reindex from PHP container
The `app:index-sphinx` command cannot execute docker commands from within a container. This is by design for container isolation. Always reindex from the host machine:
```bash
docker exec search_sphinx indexer --all --rotate --config /opt/sphinx/conf/sphinx.conf
```

## Capabilities Summary

Installed and Configured Sphinx Engine now supports:
- ✓ Natural language search
- ✓ Boolean mode search
- ✓ Query expansion (via morphology)
- ✓ Phrase matching
- ✓ Wildcard search
- ✓ Proximity search
- ✓ Category filtering
- ✓ Brand filtering (NEW)
- ✓ Date filtering
- ✓ Custom sorting
- ✓ Deep pagination

Sphinx still does NOT support (vs MariaDB):
- ✗ Multi-table JOINs
- ✗ UNION searches
- ✗ JSON filtering
- ✗ Searching across customers/orders/reviews (needs separate indexes)
- ✗ Price filtering (not configured)

See `compare.md` for detailed capability comparison.

## References

- macbre/sphinxsearch Docker Hub: https://hub.docker.com/r/macbre/sphinxsearch
- macbre/sphinxsearch GitHub: https://github.com/macbre/docker-sphinxsearch
- Sphinx Documentation: https://sphinxsearch.com/docs/
