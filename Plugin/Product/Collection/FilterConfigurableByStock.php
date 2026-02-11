<?php
/**
 * Copyright © Hans2103. All rights reserved.
 */

declare(strict_types=1);

namespace Hans2103\OptionFilter\Plugin\Product\Collection;

use Hans2103\OptionFilter\Helper\AttributeFilter;
use Magento\Catalog\Model\Layer\Resolver;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\DB\Select;
use Psr\Log\LoggerInterface;

/**
 * Plugin to filter configurable products by in-stock variants
 * - Hides configurables when all variants are out of stock
 * - When filters are active: shows only if matching variant is in stock
 */
class FilterConfigurableByStock
{
    /**
     * @var AttributeFilter
     */
    private $attributeFilterHelper;

    /**
     * @var Resolver
     */
    private $layerResolver;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var bool
     */
    private $filterApplied = false;

    /**
     * @param AttributeFilter $attributeFilterHelper
     * @param Resolver $layerResolver
     * @param LoggerInterface $logger
     */
    public function __construct(
        AttributeFilter $attributeFilterHelper,
        Resolver $layerResolver,
        LoggerInterface $logger
    ) {
        $this->attributeFilterHelper = $attributeFilterHelper;
        $this->layerResolver = $layerResolver;
        $this->logger = $logger;
    }

    /**
     * Filter configurable products before collection loads
     *
     * @param Collection $collection
     * @return array
     */
    public function beforeLoad(Collection $collection): array
    {
        // Only apply filter once per collection
        if ($this->filterApplied || $collection->isLoaded()) {
            return [];
        }

        try {
            $layer = $this->getLayer();
            if (!$layer) {
                return [];
            }

            $filters = $this->attributeFilterHelper->getActiveAttributeFilters($layer);

            // Always apply filter to hide configurables with all variants out of stock
            // If filters exist, also check that variants match the filter criteria
            $this->applyStockFilter($collection, $filters);
            $this->filterApplied = true;
        } catch (\Exception $e) {
            $this->logger->error(
                'Error applying OptionFilter: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }

        return [];
    }

    /**
     * Get catalog layer, avoiding double initialization
     *
     * @return \Magento\Catalog\Model\Layer|null
     */
    private function getLayer(): ?\Magento\Catalog\Model\Layer
    {
        // Only apply in catalog layer context (category/search pages with filters)
        try {
            return $this->layerResolver->get();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Apply stock-based filter to collection
     *
     * @param Collection $collection
     * @param array $filters
     * @return void
     */
    private function applyStockFilter(Collection $collection, array $filters): void
    {
        $storeId = $this->attributeFilterHelper->getStoreId();
        $whereClause = $this->buildWhereClause($collection, $filters, $storeId);

        $collection->getSelect()->where($whereClause);
    }

    /**
     * Build WHERE clause for filtering configurable products
     *
     * @param Collection $collection
     * @param array $filters
     * @param int $storeId
     * @return \Zend_Db_Expr
     */
    private function buildWhereClause(Collection $collection, array $filters, int $storeId): \Zend_Db_Expr
    {
        $connection = $collection->getConnection();

        // If no filters are active, hide all out-of-stock products:
        // - Simple products: check their own stock status
        // - Configurable products: check parent stock AND if they have any in-stock variants
        if (empty($filters)) {
            return new \Zend_Db_Expr(sprintf(
                "(
                    e.type_id != 'configurable'
                    AND EXISTS (
                        SELECT 1 FROM %s AS si_simple
                        WHERE si_simple.product_id = e.entity_id
                        AND si_simple.is_in_stock = 1
                    )
                ) OR (
                    e.type_id = 'configurable'
                    AND EXISTS (
                        SELECT 1 FROM %s AS si_parent
                        WHERE si_parent.product_id = e.entity_id
                        AND si_parent.is_in_stock = 1
                    )
                    AND EXISTS (
                        SELECT 1
                        FROM %s AS cpsl
                        INNER JOIN %s AS si
                            ON cpsl.product_id = si.product_id
                            AND si.is_in_stock = 1
                        WHERE cpsl.parent_id = e.entity_id
                    )
                )",
                $connection->getTableName('cataloginventory_stock_item'),
                $connection->getTableName('cataloginventory_stock_item'),
                $connection->getTableName('catalog_product_super_link'),
                $connection->getTableName('cataloginventory_stock_item')
            ));
        }

        // Build conditions for each active filter
        $conditions = [];

        foreach ($filters as $attributeId => $value) {
            $alias = 'eav_' . $attributeId;

            // Handle both single value and array of values (OR logic within same attribute)
            if (is_array($value)) {
                $valueConditions = [];
                foreach ($value as $singleValue) {
                    $valueConditions[] = $connection->quoteInto(
                        $alias . '.value = ?',
                        $singleValue
                    );
                }
                $valueSql = '(' . implode(' OR ', $valueConditions) . ')';
            } else {
                $valueSql = $connection->quoteInto($alias . '.value = ?', $value);
            }

            // Use catalog_product_entity_int instead of catalog_product_index_eav
            // because child products are not indexed in the EAV index table
            $conditions[] = sprintf(
                "EXISTS (
                    SELECT 1 FROM %s AS %s
                    WHERE %s.entity_id = cpsl.product_id
                        AND %s.attribute_id = %d
                        AND %s
                        AND %s.store_id IN (0, %d)
                )",
                $connection->getTableName('catalog_product_entity_int'),
                $alias,
                $alias,
                $alias,
                (int)$attributeId,
                $valueSql,
                $alias,
                $storeId
            );
        }

        $combinedConditions = implode(' AND ', $conditions);

        // Build main WHERE clause:
        // - Simple products: must be in stock
        // - Configurable products: parent must be in stock AND have at least one in-stock variant matching ALL filters
        return new \Zend_Db_Expr(sprintf(
            "(
                e.type_id != 'configurable'
                AND EXISTS (
                    SELECT 1 FROM %s AS si_simple
                    WHERE si_simple.product_id = e.entity_id
                    AND si_simple.is_in_stock = 1
                )
            ) OR (
                e.type_id = 'configurable'
                AND EXISTS (
                    SELECT 1 FROM %s AS si_parent
                    WHERE si_parent.product_id = e.entity_id
                    AND si_parent.is_in_stock = 1
                )
                AND EXISTS (
                    SELECT 1
                    FROM %s AS cpsl
                    INNER JOIN %s AS si
                        ON cpsl.product_id = si.product_id
                        AND si.is_in_stock = 1
                    WHERE cpsl.parent_id = e.entity_id
                        AND %s
                )
            )",
            $connection->getTableName('cataloginventory_stock_item'),
            $connection->getTableName('cataloginventory_stock_item'),
            $connection->getTableName('catalog_product_super_link'),
            $connection->getTableName('cataloginventory_stock_item'),
            $combinedConditions
        ));
    }
}
