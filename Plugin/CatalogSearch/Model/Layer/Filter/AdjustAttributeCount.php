<?php
/**
 * Copyright © Hans2103. All rights reserved.
 */

declare(strict_types=1);

namespace Hans2103\OptionFilter\Plugin\CatalogSearch\Model\Layer\Filter;

use Hans2103\OptionFilter\Model\Attribute\MultiSelectConfig;
use Hans2103\OptionFilter\Model\Layer\AvailableOptionIds;
use Magento\Catalog\Model\Layer\Filter\AbstractFilter;
use Magento\Catalog\Model\Layer\Filter\ItemFactory;
use Magento\Framework\App\ResourceConnection;

/**
 * After plugin on Filter\Attribute::getItems().
 *
 * Two responsibilities:
 *
 * 1. Non-active filters: hide options where no in-stock products exist in the current collection
 *    (prevents showing zero-result options when no size filter is active).
 *
 * 2. Active multiselect filters: always rebuild the option list from the attribute's option source
 *    using category-restricted in-stock counts. This ensures the filter shows the SAME consistent
 *    set of options regardless of which values are already selected.
 *
 *    Without this rebuild, OpenSearch returns facets only for products matching the active filter
 *    (e.g. selecting "XS" returns facets only from XS-sized products, hiding combined sizes like
 *    "XS/S" even though those products are also in the category).
 */
class AdjustAttributeCount
{
    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var MultiSelectConfig
     */
    private MultiSelectConfig $multiSelectConfig;

    /**
     * @var AvailableOptionIds
     */
    private AvailableOptionIds $availableOptionIds;

    /**
     * @var ItemFactory
     */
    private ItemFactory $filterItemFactory;

    /**
     * @param ResourceConnection $resourceConnection
     * @param MultiSelectConfig $multiSelectConfig
     * @param AvailableOptionIds $availableOptionIds
     * @param ItemFactory $filterItemFactory
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        MultiSelectConfig $multiSelectConfig,
        AvailableOptionIds $availableOptionIds,
        ItemFactory $filterItemFactory
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->multiSelectConfig = $multiSelectConfig;
        $this->availableOptionIds = $availableOptionIds;
        $this->filterItemFactory = $filterItemFactory;
    }

    /**
     * @param AbstractFilter $subject
     * @param array $result
     * @return array
     */
    public function afterGetItems(AbstractFilter $subject, array $result): array
    {
        $attribute = $subject->getAttributeModel();
        if (!$attribute) {
            return $result;
        }

        $attributeId = (int)$attribute->getId();
        $isMulti     = $this->multiSelectConfig->isMultiSelect($attributeId);
        $isActive      = $this->isAttributeCurrentlyActive($subject, $attributeId);

        // Active multiselect: always rebuild from category data so the option list is consistent
        // regardless of what is already selected. OpenSearch facets only reflect the filtered
        // collection and would hide sizes from "other-system" products (e.g. XS/S disappears
        // when XS is selected because the XS-filtered products don't include XS/S products).
        if ($isMulti && $isActive) {
            $rebuilt = $this->rebuildItemsFromCategory($subject, $attribute);
            $subject->setItems($rebuilt);
            return $rebuilt;
        }

        if (empty($result)) {
            return $result;
        }

        $connection = $this->resourceConnection->getConnection();
        $productIds = $subject->getLayer()->getProductCollection()->getAllIds();

        if (empty($productIds)) {
            return $result;
        }

        $filteredItems = [];
        foreach ($result as $item) {
            $optionId = $item->getValue();
            if ($this->hasInStockProducts($connection, $productIds, $attributeId, (int)$optionId)) {
                $filteredItems[] = $item;
            }
        }

        return $filteredItems;
    }

    /**
     * Rebuild filter items using category-restricted in-stock counts.
     *
     * Uses AvailableOptionIds to get option_id => count for the current category. Falls back to
     * countInStockProductsGlobal if the category cannot be determined (e.g. search pages).
     * Active values are always included so the user can deselect them.
     *
     * @param AbstractFilter $subject
     * @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute
     * @return array
     */
    private function rebuildItemsFromCategory(AbstractFilter $subject, $attribute): array
    {
        $attributeId  = (int)$attribute->getId();
        $activeValues = $this->getActiveValuesForAttribute($subject, $attributeId);
        $categoryId   = $this->getCategoryId($subject);

        // Get per-option in-stock counts (category-restricted when possible).
        if ($categoryId > 0) {
            $optionCounts = $this->availableOptionIds->getCountsForCategory($attributeId, $categoryId);
        } else {
            // Fallback: global counts (search pages without a current category).
            $connection   = $this->resourceConnection->getConnection();
            $optionCounts = null; // signal to use countInStockProductsGlobal below
        }

        $options = $attribute->getSource()->getAllOptions(false);
        $items   = [];

        foreach ($options as $option) {
            $optionId = $option['value'];

            if ($optionId === '' || $optionId === null) {
                continue;
            }

            $isActive = in_array((string)$optionId, array_map('strval', $activeValues), true);

            if ($optionCounts !== null) {
                $count = $optionCounts[(string)$optionId] ?? 0;
            } else {
                $count = $this->countInStockProductsGlobal(
                    $this->resourceConnection->getConnection(),
                    $attributeId,
                    (int)$optionId
                );
            }

            if ($isActive || $count > 0) {
                $item = $this->filterItemFactory->create()
                    ->setFilter($subject)
                    ->setLabel($option['label'])
                    ->setValue($optionId)
                    ->setCount($isActive ? max(1, $count) : $count);
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Get the current category ID from the layer, or 0 if unavailable.
     *
     * @param AbstractFilter $subject
     * @return int
     */
    private function getCategoryId(AbstractFilter $subject): int
    {
        try {
            $category = $subject->getLayer()->getCurrentCategory();
            return $category ? (int)$category->getId() : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * @param AbstractFilter $subject
     * @param int $attributeId
     * @return array<string>
     */
    private function getActiveValuesForAttribute(AbstractFilter $subject, int $attributeId): array
    {
        $layer = $subject->getLayer();
        $state = $layer->getState();

        if (!$state) {
            return [];
        }

        $values = [];
        foreach ($state->getFilters() as $filterItem) {
            $filter = $filterItem->getFilter();
            if (!($filter instanceof AbstractFilter)) {
                continue;
            }
            $filterAttr = $filter->getAttributeModel();
            if ($filterAttr && (int)$filterAttr->getAttributeId() === $attributeId) {
                $values[] = (string)$filterItem->getValue();
            }
        }

        return $values;
    }

    /**
     * @param AbstractFilter $subject
     * @param int $attributeId
     * @return bool
     */
    private function isAttributeCurrentlyActive(AbstractFilter $subject, int $attributeId): bool
    {
        $layer = $subject->getLayer();
        $state = $layer->getState();

        if (!$state) {
            return false;
        }

        foreach ($state->getFilters() as $filterItem) {
            $filter = $filterItem->getFilter();
            if (!($filter instanceof AbstractFilter)) {
                continue;
            }
            $filterAttr = $filter->getAttributeModel();
            if ($filterAttr && (int)$filterAttr->getAttributeId() === $attributeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count in-stock products globally for a given option (fallback for non-category pages).
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param int $attributeId
     * @param int $optionId
     * @return int
     */
    private function countInStockProductsGlobal($connection, int $attributeId, int $optionId): int
    {
        $select = $connection->select()
            ->from(
                ['e' => $connection->getTableName('catalog_product_entity')],
                ['count' => new \Zend_Db_Expr('COUNT(DISTINCT e.entity_id)')]
            )
            ->where(
                sprintf(
                    "(
                        e.type_id != 'configurable'
                        AND EXISTS (
                            SELECT 1 FROM %s AS si_simple
                            WHERE si_simple.product_id = e.entity_id
                            AND si_simple.is_in_stock = 1
                        )
                        AND EXISTS (
                            SELECT 1 FROM %s AS attr_simple
                            WHERE attr_simple.entity_id = e.entity_id
                            AND attr_simple.attribute_id = %d
                            AND attr_simple.value = %d
                        )
                    ) OR (
                        e.type_id = 'configurable'
                        AND EXISTS (
                            SELECT 1 FROM %s AS si_parent
                            WHERE si_parent.product_id = e.entity_id
                            AND si_parent.is_in_stock = 1
                        )
                        AND EXISTS (
                            SELECT 1 FROM %s AS cpsl
                            JOIN %s AS si ON cpsl.product_id = si.product_id AND si.is_in_stock = 1
                            JOIN %s AS attr ON cpsl.product_id = attr.entity_id
                                AND attr.attribute_id = %d
                                AND attr.value = %d
                            WHERE cpsl.parent_id = e.entity_id
                        )
                    )",
                    $connection->getTableName('cataloginventory_stock_item'),
                    $connection->getTableName('catalog_product_entity_int'),
                    $attributeId,
                    $optionId,
                    $connection->getTableName('cataloginventory_stock_item'),
                    $connection->getTableName('catalog_product_super_link'),
                    $connection->getTableName('cataloginventory_stock_item'),
                    $connection->getTableName('catalog_product_entity_int'),
                    $attributeId,
                    $optionId
                )
            );

        return (int)$connection->fetchOne($select);
    }

    /**
     * Check if any products in $productIds have in-stock variants with this option (non-active path).
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param array $productIds
     * @param int $attributeId
     * @param int $optionId
     * @return bool
     */
    private function hasInStockProducts($connection, array $productIds, int $attributeId, int $optionId): bool
    {
        $select = $connection->select()
            ->from(['e' => $connection->getTableName('catalog_product_entity')], ['entity_id'])
            ->where('e.entity_id IN (?)', $productIds)
            ->where(
                sprintf(
                    "(
                        e.type_id != 'configurable'
                        AND EXISTS (
                            SELECT 1 FROM %s AS si_simple
                            WHERE si_simple.product_id = e.entity_id
                            AND si_simple.is_in_stock = 1
                        )
                        AND EXISTS (
                            SELECT 1 FROM %s AS attr_simple
                            WHERE attr_simple.entity_id = e.entity_id
                            AND attr_simple.attribute_id = %d
                            AND attr_simple.value = %d
                        )
                    ) OR (
                        e.type_id = 'configurable'
                        AND EXISTS (
                            SELECT 1 FROM %s AS si_parent
                            WHERE si_parent.product_id = e.entity_id
                            AND si_parent.is_in_stock = 1
                        )
                        AND EXISTS (
                            SELECT 1 FROM %s AS cpsl
                            JOIN %s AS si ON cpsl.product_id = si.product_id AND si.is_in_stock = 1
                            JOIN %s AS attr ON cpsl.product_id = attr.entity_id
                                AND attr.attribute_id = %d
                                AND attr.value = %d
                            WHERE cpsl.parent_id = e.entity_id
                        )
                    )",
                    $connection->getTableName('cataloginventory_stock_item'),
                    $connection->getTableName('catalog_product_entity_int'),
                    $attributeId,
                    $optionId,
                    $connection->getTableName('cataloginventory_stock_item'),
                    $connection->getTableName('catalog_product_super_link'),
                    $connection->getTableName('cataloginventory_stock_item'),
                    $connection->getTableName('catalog_product_entity_int'),
                    $attributeId,
                    $optionId
                )
            )
            ->limit(1);

        return (bool)$connection->fetchOne($select);
    }
}
