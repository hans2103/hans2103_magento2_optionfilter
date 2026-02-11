# Hans2103_OptionFilter

## Overview

This module filters configurable products from catalog views based on the stock status of their variants:
- **Always hides** configurable products when all variants are out of stock
- **When filters are active:** Only shows configurables with in-stock variants matching the filter criteria

## Behavior

### No Filters Active
When no attribute filters are applied, configurable products are shown only if **at least one** variant is in stock. Products with all variants out of stock are hidden from the catalog.

**Example:**
- Product has variants: Size M (out of stock), Size L (out of stock), Size XL (out of stock)
- No filters applied → Product hidden (all variants out of stock)
- Product has variants: Size M (in stock), Size L (out of stock)
- No filters applied → Product shows (at least one variant in stock)

### Single Attribute Filter
When filtering by a single attribute (e.g., Size: M), a configurable product is shown only if **at least one** variant matching that filter is in stock.

**Example:**
- Product has variants: Size M (in stock), Size L (out of stock)
- Filter: Size = M → Product shows
- Filter: Size = L → Product hidden

### Multiple Attribute Filters
When filtering by multiple attributes (e.g., Size: M AND Color: Red), a configurable product is shown only if **the exact combination** is in stock.

**Example:**
- Product has variants: Size M Red (in stock), Size M Blue (out of stock)
- Filter: Size = M → Product shows (at least one Size M is in stock)
- Filter: Size = M AND Color = Blue → Product hidden (Size M Blue is out of stock)
- Filter: Size = M AND Color = Red → Product shows (Size M Red is in stock)

### Non-Configurable Products
Simple, grouped, and bundle products are not affected by this filter and always show based on their own stock status.

## Technical Implementation

### Architecture

The module uses a `beforeLoad` plugin on `Magento\Catalog\Model\ResourceModel\Product\Collection` to modify the SQL query after all filters have been applied but before the collection executes.

### Key Components

1. **Plugin:** `Hans2103\OptionFilter\Plugin\Product\Collection\FilterConfigurableByStock`
   - Intercepts product collection before load
   - Detects layered navigation context
   - Builds and applies SQL WHERE clause

2. **Helper:** `Hans2103\OptionFilter\Helper\AttributeFilter`
   - Parses active attribute filters from Layer state
   - Validates configurable attributes
   - Provides store context

### SQL Strategy

The plugin adds a WHERE clause that:
- Always includes non-configurable products
- For configurable products, requires at least one in-stock variant matching ALL active filters

**Query pattern:**
```sql
WHERE e.type_id != 'configurable' OR EXISTS (
    SELECT 1
    FROM catalog_product_super_link cpsl
    INNER JOIN cataloginventory_stock_item si
        ON cpsl.product_id = si.product_id AND si.is_in_stock = 1
    WHERE cpsl.parent_id = e.entity_id
        AND EXISTS (SELECT 1 FROM catalog_product_entity_int ...)
        AND EXISTS (SELECT 1 FROM catalog_product_entity_int ...)
)
```

**Note:** The module uses `catalog_product_entity_int` (raw EAV table) instead of `catalog_product_index_eav` because Magento does not index configurable product child variants in the EAV index table.

### Performance Considerations

- Uses standard Magento tables (`catalog_product_entity_int`, `catalog_product_super_link`, `cataloginventory_stock_item`)
- Applies filter only in layered navigation contexts
- Single query modification (no additional queries)
- Minimal overhead (< 100ms expected)
- No reindexing required - works with real-time EAV data

## Compatibility

- **Magento Version:** 2.4.6-p13
- **Theme:** Compatible with Hyvä theme
- **Dependencies:**
  - Magento_Catalog
  - Magento_CatalogInventory
  - Magento_ConfigurableProduct
  - Magento_LayeredNavigation

## Installation

1. Place module in `src/app/code/Hans2103/OptionFilter/`
2. Enable module: `bin/magento module:enable Hans2103_OptionFilter`
3. Run deployment: `make deploy`
4. Clear cache: `bin/magento cache:flush`

## Uninstallation

1. Disable module: `bin/magento module:disable Hans2103_OptionFilter`
2. Run deployment: `make deploy`
3. Remove module directory

## Testing

### Manual Test Scenarios

1. **No filters - all variants out of stock**
   - Create configurable with Size M (out of stock), Size L (out of stock), Size XL (out of stock)
   - Navigate to category without filters
   - Expected: Product disappears

2. **No filters - at least one variant in stock**
   - Create configurable with Size M (in stock), Size L (out of stock)
   - Navigate to category without filters
   - Expected: Product shows

3. **Single filter with out-of-stock variant**
   - Product has Size M (in stock), Size L (out of stock)
   - Apply filter: Size = L
   - Expected: Product disappears

4. **Single filter with in-stock variant**
   - Apply filter: Size = M
   - Expected: Product shows

5. **Multiple filters with exact combination out of stock**
   - Create configurable: Size M Red (in stock), Size M Blue (out of stock)
   - Apply filters: Size = M AND Color = Blue
   - Expected: Product disappears

6. **Multiple filters with exact combination in stock**
   - Apply filters: Size = M AND Color = Red
   - Expected: Product shows

7. **Non-configurable products**
   - Apply any attribute filter or no filter
   - Expected: Simple products show based on own stock status

## Troubleshooting

### "Catalog Layer has been already created" Exception

**Symptom:** Exception thrown when viewing category pages with filters applied.

**Cause:** Multiple calls to `Magento\Catalog\Model\Layer\Resolver::get()` causing duplicate layer initialization.

**Solution:** This was fixed in the current version by consolidating layer retrieval to a single `getLayer()` method. If you encounter this error:

1. Ensure you're using the latest version of the module
2. Clear generated code: `rm -rf generated/code`
3. Flush cache: `bin/magento cache:flush`

**Prevention:** Never call `layerResolver->get()` multiple times in the same request flow. Always cache the result if needed in multiple places.

### Products not filtering as expected

1. Check if filters are attribute-based (not category or price)
2. Verify attributes are used in configurable products
3. Check stock status in `cataloginventory_stock_item` table
4. Review logs: `var/log/system.log`

### All products disappear when filtering

If all products disappear when applying a filter, verify that configurable product child variants have attribute values in the `catalog_product_entity_int` table:

```sql
SELECT
    cpsl.product_id,
    child.sku,
    eav_int.value as attribute_option_id
FROM catalog_product_super_link cpsl
INNER JOIN catalog_product_entity child ON cpsl.product_id = child.entity_id
LEFT JOIN catalog_product_entity_int eav_int
    ON child.entity_id = eav_int.entity_id
    AND eav_int.attribute_id = [YOUR_ATTRIBUTE_ID]
    AND eav_int.store_id IN (0, 1)
WHERE cpsl.parent_id = [PARENT_PRODUCT_ID];
```

If this query returns no results, the EAV data may be corrupted or missing. Re-save the configurable product in the admin panel.

### Performance issues

1. Check MySQL slow query log
2. Verify indexes exist on:
   - `catalog_product_entity_int` (entity_id, attribute_id, value, store_id)
   - `catalog_product_super_link` (parent_id, product_id)
   - `cataloginventory_stock_item` (product_id, is_in_stock)
3. Monitor query execution time

### Debug mode

Enable debug logging by checking `var/log/system.log` for entries containing "Error applying OptionFilter".

## Implementation Notes

### Why `catalog_product_entity_int` instead of `catalog_product_index_eav`?

**Issue:** Magento's EAV indexer (`_prepareSelectIndex`) filters out products with `visibility = 1` (Not Visible Individually). Configurable product children always have `visibility=1`, so they are **never indexed** in `catalog_product_index_eav`. The `_prepareRelationIndex` step that propagates child values to the parent also only works from data already in the index — so configurable parents never get their super attribute values (e.g. `size`) in `catalog_product_index_eav` either.

This affects both `FilterConfigurableByStock` and `AdjustAttributeCount`. OpenSearch indexes configurables with their children's attribute values correctly, but the SQL-based `catalog_product_index_eav` does not.

**Verification:** You can verify this by running:
```sql
SELECT COUNT(*) as count
FROM catalog_product_super_link cpsl
INNER JOIN catalog_product_index_eav eav ON cpsl.product_id = eav.entity_id
WHERE eav.attribute_id = [YOUR_ATTRIBUTE_ID];
```

This will return `0`, confirming that child products are not in the EAV index.

**Solution:** The module uses `catalog_product_entity_int` (the raw EAV attribute table) instead, which contains all attribute values including those for configurable product child variants. This approach:
- Works with real-time data (no reindexing required)
- Provides accurate filtering based on actual variant attributes
- Has minimal performance impact due to proper join structure

### Filter Type Compatibility

The module supports both:
- `Magento\Catalog\Model\Layer\Filter\Attribute` (standard catalog filtering)
- `Magento\CatalogSearch\Model\Layer\Filter\Attribute` (search/layered navigation)

This ensures compatibility with both standard category pages and search results pages, as well as Hyvä theme implementations.

## License

Copyright © Hans2103. All rights reserved.
