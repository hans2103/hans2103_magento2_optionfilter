<?php
/**
 * Copyright © Hans2103. All rights reserved.
 */

declare(strict_types=1);

namespace Hans2103\OptionFilter\Plugin\CatalogSearch\Model\Layer\Filter;

use Magento\CatalogSearch\Model\Layer\Filter\Attribute;
use Magento\Framework\App\ResourceConnection;

/**
 * Plugin to hide filter options where all products have no in-stock variants
 */
class AdjustAttributeCount
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ResourceConnection $resourceConnection
    ) {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Remove filter items where all matching products have no in-stock variants
     *
     * @param Attribute $subject
     * @param array $result
     * @return array
     */
    public function afterGetItems(Attribute $subject, array $result): array
    {
        if (empty($result)) {
            return $result;
        }

        $attribute = $subject->getAttributeModel();
        $connection = $this->resourceConnection->getConnection();

        // Get all product IDs from the current layer's collection
        $productIds = $subject->getLayer()->getProductCollection()->getAllIds();

        if (empty($productIds)) {
            return $result;
        }

        $filteredItems = [];

        foreach ($result as $item) {
            $optionId = $item->getValue();

            // Check if there are any products with in-stock variants for this option
            if ($this->hasInStockProducts($connection, $productIds, (int)$attribute->getId(), (int)$optionId)) {
                $filteredItems[] = $item;
            }
        }

        return $filteredItems;
    }

    /**
     * Check if any products with this attribute value have in-stock variants
     *
     * Uses catalog_product_entity_int (raw EAV) instead of catalog_product_index_eav because
     * Magento's EAV indexer skips visibility=1 products (configurable children), so super
     * attributes are never propagated to the parent in catalog_product_index_eav.
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
