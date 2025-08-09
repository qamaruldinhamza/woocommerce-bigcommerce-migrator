# WooCommerce to BigCommerce Migration Plugin - Implementation Guide

## Overview

This plugin provides a robust solution for migrating your 5,000+ WooCommerce products to BigCommerce, handling products, variations, categories, tags, and metadata with batch processing to avoid timeouts.

## Plugin Architecture

### 1. Database Structure

The plugin creates a mapping table (`wp_wc_bc_product_mapping`) with the following structure:

```sql
- id: Primary key
- wc_product_id: WooCommerce product ID
- wc_variation_id: WooCommerce variation ID (NULL for parent products)
- bc_product_id: BigCommerce product ID (after migration)
- bc_variation_id: BigCommerce variation ID (after migration)
- status: Migration status (pending, success, error)
- message: Error/success messages
- created_at: Timestamp
- updated_at: Timestamp
```

### 2. Key Components

#### Main Plugin Class (`WC_BC_Migrator`)
- Initializes the plugin
- Registers hooks and admin menu
- Loads required classes

#### Database Class (`WC_BC_Database`)
- Creates and manages the mapping table
- Provides methods for inserting, updating, and querying migration data

#### BigCommerce API Class (`WC_BC_BigCommerce_API`)
- Handles all API communication with BigCommerce
- Manages authentication and request formatting

#### Product Migrator Class (`WC_BC_Product_Migrator`)
- Core migration logic
- Transforms WooCommerce data to BigCommerce format
- Handles product variations

#### Batch Processor Class (`WC_BC_Batch_Processor`)
- Manages batch processing
- Prevents timeouts by processing in chunks
- Tracks progress and handles retries

#### REST API Class (`WC_BC_REST_API`)
- Provides endpoints for AJAX operations
- Enables real-time progress monitoring

## Installation & Setup

### 1. File Structure

```
wp-content/plugins/wc-bc-migrator/
├── wc-bc-migrator.php (main plugin file)
├── includes/
│   ├── class-database.php
│   ├── class-bigcommerce-api.php
│   ├── class-product-migrator.php
│   ├── class-rest-api.php
│   └── class-batch-processor.php
├── admin/
│   └── admin-page.php
├── assets/
│   ├── css/
│   │   └── admin.css
│   └── js/
│       └── admin.js
└── README.md
```

### 2. BigCommerce API Setup

1. Create a BigCommerce API account
2. Generate API credentials with the following scopes:
   - Products (modify)
   - Product categories (modify)
   - Product variants (modify)
   - Brands (modify)

3. Save credentials in WordPress:
   - Store Hash
   - Access Token

## Migration Process

### Phase 1: Preparation

1. **Category Mapping**: Before starting, map WooCommerce categories to BigCommerce categories
2. **Brand Mapping**: Set up brand mappings if using brands
3. **Attribute Mapping**: Map WooCommerce attributes to BigCommerce options

### Phase 2: Product Preparation

```php
// The plugin scans all WooCommerce products and creates mapping records
// This allows tracking of migration status for each product
```

### Phase 3: Batch Migration

```javascript
// Products are processed in configurable batches (default: 10)
// This prevents timeouts and allows for progress monitoring
```

### Phase 4: Error Handling

- Failed products are marked with error status
- Error messages are stored for debugging
- Retry functionality for failed products

## Important Considerations

### 1. Product Data Mapping

#### Simple Products
- Name, SKU, Price, Description
- Weight, Dimensions
- Stock levels
- Images (main and gallery)

#### Variable Products
- Parent product created first
- Variations created as variants
- Option sets need to be created in BigCommerce

### 2. Data Transformations

#### Prices
```php
// WooCommerce stores prices as strings
// BigCommerce requires float values
'price' => (float) $product->get_regular_price()
```

#### Images
```php
// Images must be publicly accessible URLs
// Plugin converts attachment IDs to URLs
```

#### Categories
- Hierarchical structure must be maintained
- Categories should be created before products

### 3. Performance Optimization

#### Batch Size Recommendations
- Start with 5-10 products per batch
- Monitor server response times
- Adjust based on product complexity

#### Memory Management
```php
// Clear product cache after each batch
wp_cache_flush();
```

### 4. API Rate Limits

BigCommerce API limits:
- 450 requests per hour (standard)
- 7 requests per second burst

The plugin includes rate limiting considerations.

## Advanced Features to Implement

### 1. Image Migration
```php
// Consider using BigCommerce's image upload API
// Or ensure images are served from a CDN
```

### 2. SEO Data
```php
// Migrate Yoast/RankMath data to BigCommerce
// Custom URLs, meta descriptions, etc.
```

### 3. Customer Reviews
```php
// Reviews need separate migration process
// Use BigCommerce Reviews API
```

### 4. Related Products
```php
// Map product relationships
// Cross-sells, up-sells, related products
```

## Testing Strategy

### 1. Test Environment
- Set up a BigCommerce sandbox store
- Test with a subset of products first

### 2. Validation Steps
1. Create test products in WooCommerce
2. Run migration on test products
3. Verify data integrity in BigCommerce
4. Test variations and options

### 3. Rollback Strategy
- Keep original WooCommerce data intact
- Log all BigCommerce product IDs for potential cleanup

## Troubleshooting

### Common Issues

#### 1. Timeout Errors
- Reduce batch size
- Increase PHP execution time
- Use background processing

#### 2. API Errors
- Check API credentials
- Verify API scopes
- Monitor rate limits

#### 3. Data Mismatch
- Validate data formats
- Check for special characters
- Ensure proper encoding

### Debug Mode
```php
// Enable WP_DEBUG for detailed error logging
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Code Snippets for Common Tasks

### Check Migration Progress
```php
global $wpdb;
$table = $wpdb->prefix . 'wc_bc_product_mapping';
$stats = $wpdb->get_results(
    "SELECT status, COUNT(*) as count 
     FROM $table 
     GROUP BY status"
);
```

### Reset Failed Products
```php
global $wpdb;
$table = $wpdb->prefix . 'wc_bc_product_mapping';
$wpdb->update(
    $table,
    array('status' => 'pending'),
    array('status' => 'error')
);
```

### Export Migration Report
```php
// Generate CSV of migration results
$results = $wpdb->get_results(
    "SELECT * FROM $table 
     WHERE status IN ('error', 'success')"
);
```

## Best Practices

1. **Backup Everything**: Before starting migration
2. **Test Thoroughly**: Use staging environment
3. **Monitor Progress**: Watch server resources
4. **Document Mappings**: Keep records of all ID mappings
5. **Validate Data**: Spot-check migrated products

## Next Steps

1. Install and activate the plugin
2. Configure BigCommerce API credentials
3. Run preparation to scan products
4. Start small batch migrations
5. Monitor and adjust as needed

## Support & Maintenance

- Keep logs of all migrations
- Regular database cleanup
- Monitor BigCommerce API changes
- Update mappings as needed

This plugin provides a solid foundation for your migration needs. Customize as required for your specific use case.