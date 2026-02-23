<?php
/**
 * Copyright © Hans2103. All rights reserved.
 */

namespace Hans2103\OptionFilter\Helper;

use Hans2103\OptionFilter\Model\Attribute\MultiSelectConfig;
use Hans2103\OptionFilter\Model\Layer\AvailableOptionIds;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Filter\Item as FilterItem;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Helper for detecting and processing active attribute filters.
 *
 * Used by FilterConfigurableByStock to determine which attribute filters should be applied
 * as SQL conditions on the configurable product collection.
 *
 * Multiselect attributes where ALL in-stock options in the current category are selected are
 * excluded from the returned filter map — applying a terms query in that situation would
 * produce fewer results than having no filter (products without the attribute or with values
 * outside the visible filter range would be excluded). FilterConfigurableByStock should treat
 * this case as "no constraint on this attribute".
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
     * @var MultiSelectConfig
     */
    private $multiSelectConfig;

    /**
     * @var AvailableOptionIds
     */
    private $availableOptionIds;

    /**
     * @var array|null
     */
    private $configurableAttributeIds = null;

    /**
     * @param EavConfig $eavConfig
     * @param StoreManagerInterface $storeManager
     * @param Configurable $configurableType
     * @param MultiSelectConfig $multiSelectConfig
     * @param AvailableOptionIds $availableOptionIds
     */
    public function __construct(
        EavConfig $eavConfig,
        StoreManagerInterface $storeManager,
        Configurable $configurableType,
        MultiSelectConfig $multiSelectConfig,
        AvailableOptionIds $availableOptionIds
    ) {
        $this->eavConfig          = $eavConfig;
        $this->storeManager       = $storeManager;
        $this->configurableType   = $configurableType;
        $this->multiSelectConfig  = $multiSelectConfig;
        $this->availableOptionIds = $availableOptionIds;
    }

    /**
     * Get active attribute filters from the layer state.
     *
     * Returns attribute_id => value (scalar) or attribute_id => [values] (array for multiselect).
     *
     * Multiselect attributes where ALL in-stock category options are selected are excluded — they
     * should be treated as "no filter" by FilterConfigurableByStock.
     *
     * @param Layer $layer
     * @return array
     */
    public function getActiveAttributeFilters(Layer $layer): array
    {
        $filters = [];
        $state   = $layer->getState();

        if (!$state) {
            return $filters;
        }

        /** @var FilterItem $filterItem */
        foreach ($state->getFilters() as $filterItem) {
            $filter = $filterItem->getFilter();

            // Skip non-attribute filters (category, price, etc.)
            if (!$filter instanceof \Magento\Catalog\Model\Layer\Filter\Attribute
                && !$filter instanceof \Magento\CatalogSearch\Model\Layer\Filter\Attribute) {
                continue;
            }

            $attribute   = $filter->getAttributeModel();
            $attributeId = (int)$attribute->getAttributeId();

            // Only include configurable attributes
            if (!$this->isConfigurableAttribute($attributeId)) {
                continue;
            }

            $value = $filterItem->getValue();

            // Collect multiple values for the same attribute (multiselect OR logic)
            if (isset($filters[$attributeId])) {
                if (!is_array($filters[$attributeId])) {
                    $filters[$attributeId] = [$filters[$attributeId]];
                }
                $filters[$attributeId][] = $value;
            } else {
                $filters[$attributeId] = $value;
            }
        }

        // Remove multiselect attributes where all in-stock category options are selected.
        // Applying a SQL terms constraint in that case would exclude products without the
        // attribute or with values outside the visible filter, producing fewer results than
        // the unfiltered page. FilterConfigurableByStock should skip the constraint.
        $categoryId = $this->getCategoryId($layer);

        foreach ($filters as $attributeId => $value) {
            if (!$this->multiSelectConfig->isMultiSelect((int)$attributeId)) {
                continue;
            }

            $selectedValues = is_array($value) ? $value : [$value];

            if ($categoryId > 0) {
                $availableIds = $this->availableOptionIds->getForCategory((int)$attributeId, $categoryId);
            } else {
                continue; // cannot determine available options, keep the filter
            }

            if (!empty($availableIds)
                && empty(array_diff($availableIds, array_map('strval', $selectedValues)))
            ) {
                unset($filters[$attributeId]);
            }
        }

        return $filters;
    }

    /**
     * Check if attribute is used in configurable products.
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
     * Get current store ID.
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
     * @param Layer $layer
     * @return int
     */
    private function getCategoryId(Layer $layer): int
    {
        try {
            $category = $layer->getCurrentCategory();
            return $category ? (int)$category->getId() : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Load all attribute IDs used in configurable products.
     *
     * @return array
     */
    private function loadConfigurableAttributeIds(): array
    {
        $connection = $this->configurableType->getConnection();
        $select     = $connection->select()
            ->from(
                $this->configurableType->getTable('catalog_product_super_attribute'),
                'attribute_id'
            )
            ->distinct();

        return $connection->fetchCol($select);
    }
}
