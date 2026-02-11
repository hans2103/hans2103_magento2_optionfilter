# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
with a custom format: `YY.WW.X` (Year.WeekNumber.Increment).

## [Unreleased]

### Fixed
- `AdjustAttributeCount`: replaced `catalog_product_index_eav` JOIN with `catalog_product_entity_int`
  lookup, fixing missing attribute filter options for configurable products whose children have
  `visibility=1` (Not Visible Individually) and are therefore never indexed in `catalog_product_index_eav`

## [26.05.1] - 2026-02-05

### Added
- Initial public release
- Filter configurable products by stock status of variants
- Hide configurables when all variants are out of stock
- Smart filtering based on active layered navigation filters
- Support for single and multiple attribute filters
- Real-time stock checks without reindexing
- Hyvä theme compatibility
- Adjust collection size plugin for accurate product counts
- Adjust attribute count plugin to hide unavailable filter options

### Technical Details
- Uses `catalog_product_entity_int` for accurate variant attribute data
- Plugin-based architecture with `beforeLoad` interception
- Support for both Catalog and CatalogSearch layer filters
- Performance optimized with single query modification

### Requirements
- Magento 2.4.x
- PHP 8.1, 8.2, or 8.3

[Unreleased]: https://github.com/hans2103/hans2103_magento2_optionfilter/compare/26.05.1...HEAD
[26.05.1]: https://github.com/hans2103/hans2103_magento2_optionfilter/releases/tag/26.05.1
