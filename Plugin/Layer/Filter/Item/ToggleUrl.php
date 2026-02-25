<?php
/**
 * Copyright © Hans2103. All rights reserved.
 */

declare(strict_types=1);

namespace Hans2103\OptionFilter\Plugin\Layer\Filter\Item;

use Hans2103\OptionFilter\Model\Attribute\MultiSelectConfig;
use Magento\Catalog\Model\Layer\Filter\AbstractFilter;
use Magento\Catalog\Model\Layer\Filter\Item;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Theme\Block\Html\Pager;

/**
 * Plugin on FilterItem::getUrl() and getRemoveUrl() to support multiselect layered navigation.
 *
 * - getUrl(): toggles the item's value in the comma-separated URL param
 *   (adds if absent, removes if present)
 * - getRemoveUrl(): removes just this item's single value from the comma-separated URL param
 * - Sets is_selected data flag so templates can style active options
 */
class ToggleUrl
{
    /**
     * @var MultiSelectConfig
     */
    private MultiSelectConfig $multiSelectConfig;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var UrlInterface
     */
    private UrlInterface $url;

    /**
     * @var Pager
     */
    private Pager $pager;

    /**
     * @param MultiSelectConfig $multiSelectConfig
     * @param RequestInterface $request
     * @param UrlInterface $url
     * @param Pager $pager
     */
    public function __construct(
        MultiSelectConfig $multiSelectConfig,
        RequestInterface $request,
        UrlInterface $url,
        Pager $pager
    ) {
        $this->multiSelectConfig = $multiSelectConfig;
        $this->request = $request;
        $this->url = $url;
        $this->pager = $pager;
    }

    /**
     * Modify URL to toggle the item's value in the multiselect param
     *
     * @param Item $subject
     * @param string $result
     * @return string
     */
    public function afterGetUrl(Item $subject, string $result): string
    {
        $filterInfo = $this->getFilterInfo($subject);
        if ($filterInfo === null) {
            return $result;
        }

        [$requestVar, $currentValues, $itemValue] = $filterInfo;

        // Mark whether this option is currently active
        $isSelected = in_array($itemValue, $currentValues, true);
        $subject->setData('is_selected', $isSelected);

        if ($isSelected) {
            // Remove this value from the selection
            $newValues = array_values(
                array_filter($currentValues, static fn($v) => $v !== $itemValue)
            );
        } else {
            // Add this value to the selection
            $newValues = array_merge($currentValues, [$itemValue]);
        }

        $query = [
            $requestVar => empty($newValues) ? null : implode(',', $newValues),
            $this->pager->getPageVarName() => null,
        ];

        return $this->url->getUrl('*/*/*', [
            '_current'     => true,
            '_use_rewrite' => true,
            '_query'       => $query,
        ]);
    }

    /**
     * Modify remove URL to remove just this item's value from the multiselect param
     *
     * @param Item $subject
     * @param string $result
     * @return string
     */
    public function afterGetRemoveUrl(Item $subject, string $result): string
    {
        $filterInfo = $this->getFilterInfo($subject);
        if ($filterInfo === null) {
            return $result;
        }

        [$requestVar, $currentValues, $itemValue] = $filterInfo;

        // Remove this value from the selection
        $newValues = array_values(
            array_filter($currentValues, static fn($v) => $v !== $itemValue)
        );

        $query = [
            $requestVar => empty($newValues) ? null : implode(',', $newValues),
        ];

        $params = [
            '_current'     => true,
            '_use_rewrite' => true,
            '_query'       => $query,
            '_escape'      => true,
        ];

        return $this->url->getUrl('*/*/*', $params);
    }

    /**
     * Extract filter info needed for URL building; returns null if not a multiselect attr filter
     *
     * @param Item $subject
     * @return array{string, list<string>, string}|null  [requestVar, currentValues, itemValue]
     */
    private function getFilterInfo(Item $subject): ?array
    {
        try {
            $filter = $subject->getFilter();
        } catch (\Exception $e) {
            return null;
        }

        if (!($filter instanceof AbstractFilter)) {
            return null;
        }

        try {
            $attribute = $filter->getAttributeModel();
        } catch (\Exception $e) {
            return null;
        }
        if (!$attribute || !$attribute->getAttributeId()) {
            return null;
        }

        if (!$this->multiSelectConfig->isMultiSelect((int)$attribute->getAttributeId())) {
            return null;
        }

        $requestVar = $filter->getRequestVar();
        $rawValue = $this->request->getParam($requestVar, '');

        // Parse current comma-separated values
        $currentValues = $rawValue !== '' && $rawValue !== null
            ? array_values(array_filter(array_map('trim', explode(',', (string)$rawValue))))
            : [];

        $itemValue = (string)$subject->getValue();

        return [$requestVar, $currentValues, $itemValue];
    }
}
