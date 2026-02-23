<?php
/**
 * Copyright © Hans2103. All rights reserved.
 */

declare(strict_types=1);

namespace Hans2103\OptionFilter\Model\Attribute;

use Magento\Framework\App\ResourceConnection;

/**
 * Service to check if an attribute has multiselect layered navigation enabled
 */
class MultiSelectConfig
{
    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * Per-request cache of multiselect status per attribute ID
     *
     * @var array<int, bool>
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
     * Check whether the given attribute has multiselect filter enabled
     *
     * @param int $attributeId
     * @return bool
     */
    public function isMultiSelect(int $attributeId): bool
    {
        if (!isset($this->cache[$attributeId])) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from(
                    $connection->getTableName('catalog_eav_attribute'),
                    ['is_multiselect_filter']
                )
                ->where('attribute_id = ?', $attributeId);

            $value = $connection->fetchOne($select);
            $this->cache[$attributeId] = (bool)(int)$value;
        }

        return $this->cache[$attributeId];
    }
}
