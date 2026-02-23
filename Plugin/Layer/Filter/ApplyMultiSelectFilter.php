<?php
/**
 * Copyright © Hans2103. All rights reserved.
 */

declare(strict_types=1);

namespace Hans2103\OptionFilter\Plugin\Layer\Filter;

use Hans2103\OptionFilter\Model\Attribute\MultiSelectConfig;
use Hans2103\OptionFilter\Model\Layer\AvailableOptionIds;
use Magento\Catalog\Model\Layer\Filter\AbstractFilter;
use Magento\Catalog\Model\Layer\Filter\ItemFactory;
use Magento\CatalogSearch\Model\Layer\Filter\Attribute as CatalogSearchAttribute;
use Magento\Framework\App\RequestInterface;

/**
 * Around plugin on Catalog and CatalogSearch attribute filter apply() to support multiselect
 * layered navigation (both single and comma-separated values).
 *
 * For multiselect-enabled attributes we always handle apply() ourselves, even for a single
 * selected value. This prevents the native apply() from calling setItems([]), which would
 * clear the filter's item list and hide the block from the sidebar.
 *
 * URL pattern: ?size=5       → one active value
 *              ?size=5,6     → two active values (OR logic)
 *
 * Skip logic: if the selected values cover ALL in-stock options for this attribute in the current
 * category, the OpenSearch filter is skipped entirely (equivalent to "no filter"). This prevents
 * the case where selecting all visible options returns fewer products than having no filter (because
 * an OpenSearch terms query excludes products whose children have no value for the attribute, or
 * have values not visible in the filter such as combined sizes like "XS/S").
 */
class ApplyMultiSelectFilter
{
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
     * @param MultiSelectConfig $multiSelectConfig
     * @param AvailableOptionIds $availableOptionIds
     * @param ItemFactory $filterItemFactory
     */
    public function __construct(
        MultiSelectConfig $multiSelectConfig,
        AvailableOptionIds $availableOptionIds,
        ItemFactory $filterItemFactory
    ) {
        $this->multiSelectConfig = $multiSelectConfig;
        $this->availableOptionIds = $availableOptionIds;
        $this->filterItemFactory = $filterItemFactory;
    }

    /**
     * Intercept apply() to handle multiselect attribute filters (single or multiple values).
     *
     * @param AbstractFilter $subject
     * @param callable $proceed
     * @param RequestInterface $request
     * @return AbstractFilter
     */
    public function aroundApply(
        AbstractFilter $subject,
        callable $proceed,
        RequestInterface $request
    ): AbstractFilter {
        $attributeValue = $request->getParam($subject->getRequestVar());

        // No value → use normal apply
        if (empty($attributeValue) && !is_numeric($attributeValue)) {
            return $proceed($request);
        }

        $attribute = $subject->getAttributeModel();
        if (!$attribute || !$attribute->getAttributeId()) {
            return $proceed($request);
        }

        $attributeCode = $attribute->getAttributeCode();
        $attributeId   = (int)$attribute->getAttributeId();
        $isMulti       = $this->multiSelectConfig->isMultiSelect($attributeId);

        // Only intercept multiselect-enabled attributes.
        if (!$isMulti) {
            return $proceed($request);
        }

        // Parse value(s). Comma-separated = multiple active values (OR logic).
        if (is_string($attributeValue) && str_contains($attributeValue, ',')) {
            $values = array_values(array_filter(array_map('trim', explode(',', $attributeValue))));
        } else {
            $values = [$attributeValue];
        }

        if (empty($values)) {
            return $proceed($request);
        }

        $layer          = $subject->getLayer();
        $productCollection = $layer->getProductCollection();

        // If the selected values cover ALL in-stock options for this attribute in the current
        // category, skip the OpenSearch filter. Applying a terms query when all visible options
        // are selected would still exclude products whose children have no value for this attribute
        // or use a different size system (e.g. XS/S vs XS), resulting in fewer products than
        // having no filter at all.
        $skipFilter = $this->shouldSkipFilter($layer, $attributeId, $values);

        if (!$skipFilter) {
            // Apply collection filter (OR across all selected values).
            // Pass a plain array of values — Magento's OpenSearch adapter translates this to a
            // "should" (OR) query. ['in' => $values] and [['eq'=>v1],['eq'=>v2]] both produce
            // AND (must) conditions and return 0 results for multiple values.
            if ($subject instanceof CatalogSearchAttribute) {
                $productCollection->addFieldToFilter($attributeCode, $values);
            } else {
                $productCollection->addAttributeToFilter($attributeCode, $values);
            }
        }

        // Add each value as a separate active-filter state item (shown in "active filters" bar
        // and used by ToggleUrl to build add/remove URLs).
        $state = $layer->getState();
        foreach ($values as $value) {
            $label = $attribute->getSource()->getOptionText($value);
            if ($label === false || $label === null) {
                $label = $value;
            }
            $filterItem = $this->filterItemFactory->create()
                ->setFilter($subject)
                ->setLabel($label)
                ->setValue($value);
            $state->addFilter($filterItem);
        }

        // Do NOT call setItems([]).
        // The native apply() calls setItems([]) to hide the filter block after a single-select
        // choice; for multiselect we must keep the block visible so users can add or remove
        // further values. Omitting setItems([]) leaves _items as null, so getItems() will
        // call _initItems() normally and fetch fresh facet data from the search engine.

        return $subject;
    }

    /**
     * Return true if the selected values cover all in-stock options for this attribute in the
     * current category — i.e. applying the filter would be equivalent to having no filter.
     *
     * @param \Magento\Catalog\Model\Layer $layer
     * @param int $attributeId
     * @param array $values
     * @return bool
     */
    private function shouldSkipFilter($layer, int $attributeId, array $values): bool
    {
        try {
            $category   = $layer->getCurrentCategory();
            $categoryId = $category ? (int)$category->getId() : 0;
        } catch (\Exception $e) {
            return false;
        }

        if ($categoryId <= 0) {
            return false;
        }

        $availableIds = $this->availableOptionIds->getForCategory($attributeId, $categoryId);

        if (empty($availableIds)) {
            return false;
        }

        // Skip if every available option is already in the selected values.
        return empty(array_diff($availableIds, array_map('strval', $values)));
    }
}
