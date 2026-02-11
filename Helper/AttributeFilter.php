<?php
/**
 * Copyright © Hans2103. All rights reserved.
 */

namespace Hans2103\OptionFilter\Helper;

use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Filter\Item as FilterItem;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Helper for detecting and processing active attribute filters
 */
class AttributeFilter
{
    /**
     * @var EavConfig
     */
    private $eavConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Configurable
     */
    private $configurableType;

    /**
     * @var array|null
     */
    private $configurableAttributeIds = null;

    /**
     * @param EavConfig $eavConfig
     * @param StoreManagerInterface $storeManager
     * @param Configurable $configurableType
     */
    public function __construct(
        EavConfig $eavConfig,
        StoreManagerInterface $storeManager,
        Configurable $configurableType
    ) {
        $this->eavConfig = $eavConfig;
        $this->storeManager = $storeManager;
        $this->configurableType = $configurableType;
    }

    /**
     * Get active attribute filters from the layer state
     *
     * @param Layer $layer
     * @return array Array of attribute_id => value pairs
     */
    public function getActiveAttributeFilters(Layer $layer): array
    {
        $filters = [];
        $state = $layer->getState();

        if (!$state) {
            return $filters;
        }

        /** @var FilterItem $filterItem */
        foreach ($state->getFilters() as $filterItem) {
            $filter = $filterItem->getFilter();

            // Skip non-attribute filters (category, price, etc.)
            // Accept both Catalog and CatalogSearch attribute filters
            if (!$filter instanceof \Magento\Catalog\Model\Layer\Filter\Attribute
                && !$filter instanceof \Magento\CatalogSearch\Model\Layer\Filter\Attribute) {
                continue;
            }

            $attribute = $filter->getAttributeModel();
            $attributeId = (int)$attribute->getAttributeId();

            // Only include configurable attributes
            if (!$this->isConfigurableAttribute($attributeId)) {
                continue;
            }

            $value = $filterItem->getValue();

            // Handle multiple values for the same attribute (OR logic within same attribute)
            if (isset($filters[$attributeId])) {
                // If we already have a value for this attribute, convert to array
                if (!is_array($filters[$attributeId])) {
                    $filters[$attributeId] = [$filters[$attributeId]];
                }
                $filters[$attributeId][] = $value;
            } else {
                $filters[$attributeId] = $value;
            }
        }

        return $filters;
    }

    /**
     * Check if attribute is used in configurable products
     *
     * @param int $attributeId
     * @return bool
     */
    public function isConfigurableAttribute(int $attributeId): bool
    {
        if ($this->configurableAttributeIds === null) {
            $this->configurableAttributeIds = $this->loadConfigurableAttributeIds();
        }

        return in_array($attributeId, $this->configurableAttributeIds);
    }

    /**
     * Get current store ID
     *
     * @return int
     */
    public function getStoreId(): int
    {
        try {
            return (int)$this->storeManager->getStore()->getId();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Load all attribute IDs used in configurable products
     *
     * @return array
     */
    private function loadConfigurableAttributeIds(): array
    {
        $connection = $this->configurableType->getConnection();
        $select = $connection->select()
            ->from(
                $this->configurableType->getTable('catalog_product_super_attribute'),
                'attribute_id'
            )
            ->distinct();

        return $connection->fetchCol($select);
    }
}
