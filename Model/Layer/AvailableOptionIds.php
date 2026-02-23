<?php
/**
 * Copyright © Hans2103. All rights reserved.
 */

declare(strict_types=1);

namespace Hans2103\OptionFilter\Model\Layer;

use Magento\Framework\App\ResourceConnection;
use Zend_Db_Expr;

/**
 * Returns the in-stock option IDs (and their counts) for a given attribute in a given category.
 *
 * Only configurable-product child variants are considered — simple products are not filtered
 * by attribute value in FilterConfigurableByStock and therefore do not affect the facet logic.
 *
 * Results are cached per attribute+category pair for the duration of the request.
 */
class AvailableOptionIds
{
    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var array<string, array<string, int>>
     */
    private array $cache = [];

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Return option_id (string) => in-stock parent count for the given attribute in the category.
     *
     * Only child products with is_in_stock=1 AND whose configurable parent also has is_in_stock=1
     * AND whose parent is directly assigned to the category are included.
     *
     * @param int $attributeId
     * @param int $categoryId
     * @return array<string, int>
     */
    public function getCountsForCategory(int $attributeId, int $categoryId): array
    {
        $cacheKey = $attributeId . '_' . $categoryId;
        if (!isset($this->cache[$cacheKey])) {
            $this->cache[$cacheKey] = $this->fetchCountsForCategory($attributeId, $categoryId);
        }

        return $this->cache[$cacheKey];
    }

    /**
     * Return option IDs (as strings) that have at least one in-stock product in the category.
     *
     * @param int $attributeId
     * @param int $categoryId
     * @return array<string>
     */
    public function getForCategory(int $attributeId, int $categoryId): array
    {
        return array_keys($this->getCountsForCategory($attributeId, $categoryId));
    }

    /**
     * @param int $attributeId
     * @param int $categoryId
     * @return array<string, int>
     */
    private function fetchCountsForCategory(int $attributeId, int $categoryId): array
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                ['eav' => $connection->getTableName('catalog_product_entity_int')],
                [
                    'value' => 'eav.value',
                    'count' => new Zend_Db_Expr('COUNT(DISTINCT cpsl.parent_id)'),
                ]
            )
            ->join(
                ['cpsl' => $connection->getTableName('catalog_product_super_link')],
                'cpsl.product_id = eav.entity_id',
                []
            )
            ->join(
                ['ccp' => $connection->getTableName('catalog_category_product')],
                'ccp.product_id = cpsl.parent_id AND ccp.category_id = ' . (int)$categoryId,
                []
            )
            ->join(
                ['si_child' => $connection->getTableName('cataloginventory_stock_item')],
                'si_child.product_id = eav.entity_id AND si_child.is_in_stock = 1',
                []
            )
            ->join(
                ['si_parent' => $connection->getTableName('cataloginventory_stock_item')],
                'si_parent.product_id = cpsl.parent_id AND si_parent.is_in_stock = 1',
                []
            )
            ->where('eav.attribute_id = ?', $attributeId)
            ->where('eav.store_id = 0')
            ->group('eav.value');

        $rows = $connection->fetchAll($select);
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['value']] = (int)$row['count'];
        }

        return $result;
    }
}
