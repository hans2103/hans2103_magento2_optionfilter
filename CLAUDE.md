# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Hans2103 Option Filter** is a Magento 2 module (`Hans2103_OptionFilter`) that filters configurable products from catalog/layered navigation views based on real-time stock status of their child variants. It hides products when all variants are out of stock, or when no variant matches the active layered navigation filters AND is in stock.

## Magento 2 Module Commands

This module must be installed inside a Magento 2 installation. Commands are run from the Magento root:

```bash
# Install
composer require hans2103/magento2-optionfilter
bin/magento module:enable Hans2103_OptionFilter
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush

# Uninstall
bin/magento module:disable Hans2103_OptionFilter
bin/magento setup:upgrade
bin/magento setup:di:compile
composer remove hans2103/magento2-optionfilter
bin/magento cache:flush

# After any code changes
bin/magento setup:di:compile
bin/magento cache:flush
```

There are no standalone unit tests — testing is done manually against a running Magento installation (see README.md for test scenarios).

## Architecture

The module uses Magento 2's **Plugin (Interceptor)** pattern exclusively — no rewrites, no preferences.

### Plugins (registered in `etc/di.xml`)

| Plugin | Intercepts | Sort Order | Purpose |
|--------|-----------|------------|---------|
| `FilterConfigurableByStock` | `Magento\Catalog\Model\ResourceModel\Product\Collection::load` (before) | 100 | Core filtering: adds SQL WHERE clause to exclude products/configurables with no in-stock matching variants |
| `AdjustCollectionSize` | Same collection `load` (after) | 200 | Clears cached `_totalRecords` via Reflection so pagination shows the correct filtered count |
| `AdjustAttributeCount` (×2) | CatalogSearch and Catalog `Filter\Attribute::getItems` (after) | 100 | Removes layered nav filter options where no purchasable products exist |

### Helper

`Helper/AttributeFilter.php` — Parses active layered navigation filters from the layer state, validates attributes are used in configurable products, and loads configurable super-attribute IDs from `catalog_product_super_attribute`.

### Key Technical Detail: `catalog_product_entity_int` vs `catalog_product_index_eav`

Magento does **not** index configurable child variants in `catalog_product_index_eav` — only standalone products appear there. The module queries `catalog_product_entity_int` (raw EAV table) for child variant attribute values, enabling accurate real-time filtering without reindexing.

### SQL Strategy

The `FilterConfigurableByStock` plugin modifies the collection's WHERE clause using EXISTS subqueries against:
- `cataloginventory_stock_item` — stock status
- `catalog_product_super_link` — parent↔variant relationships
- `catalog_product_entity_int` — variant attribute values (for active filters)

Without active filters: hides configurables with zero in-stock variants.
With active filters (e.g., Size=M): hides configurables with no in-stock variant matching ALL active filter criteria simultaneously.

## Module Registration

- Namespace: `Hans2103\OptionFilter\`
- Module name: `Hans2103_OptionFilter`
- PHP: 8.1, 8.2, 8.3
- Magento: 2.4.x

## Release Process

Releases are automated via `.github/workflows/release.yml`. Push a git tag matching `YY.WW.X` format (Year.WeekNumber.Increment, e.g., `26.05.1`) to trigger a GitHub release with a zip package.
