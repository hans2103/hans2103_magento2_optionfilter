<?php
/**
 * Copyright © Hans2103. All rights reserved.
 */

declare(strict_types=1);

namespace Hans2103\OptionFilter\Plugin\Swatches\LayeredNavigation;

use Hans2103\OptionFilter\Model\Attribute\MultiSelectConfig;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Swatches\Block\LayeredNavigation\RenderLayered;
use Magento\Theme\Block\Html\Pager;

/**
 * Plugin on RenderLayered::buildUrl() to support multiselect swatch filters.
 *
 * The native buildUrl() always sets exactly one value: ?size=20.
 * For multiselect-enabled attributes we build a comma-appended or toggle URL instead:
 *   - If the value is not yet active: ?size=current,new
 *   - If the value is already active: ?size=remaining (removes it)
 */
class BuildMultiSelectUrl
{
    /**
     * @var MultiSelectConfig
     */
    private MultiSelectConfig $multiSelectConfig;

    /**
     * @var ProductAttributeRepositoryInterface
     */
    private ProductAttributeRepositoryInterface $attributeRepository;

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
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param RequestInterface $request
     * @param UrlInterface $url
     * @param Pager $pager
     */
    public function __construct(
        MultiSelectConfig $multiSelectConfig,
        ProductAttributeRepositoryInterface $attributeRepository,
        RequestInterface $request,
        UrlInterface $url,
        Pager $pager
    ) {
        $this->multiSelectConfig = $multiSelectConfig;
        $this->attributeRepository = $attributeRepository;
        $this->request = $request;
        $this->url = $url;
        $this->pager = $pager;
    }

    /**
     * Replace single-value URL with a comma-toggled URL for multiselect attributes.
     *
     * @param RenderLayered $subject
     * @param string $result  Native URL built by RenderLayered::buildUrl()
     * @param string $attributeCode
     * @param mixed $optionId
     * @return string
     */
    public function afterBuildUrl(RenderLayered $subject, string $result, string $attributeCode, $optionId): string
    {
        try {
            $attribute = $this->attributeRepository->get($attributeCode);
        } catch (NoSuchEntityException $e) {
            return $result;
        }

        if (!$this->multiSelectConfig->isMultiSelect((int)$attribute->getAttributeId())) {
            return $result;
        }

        $rawValue = $this->request->getParam($attributeCode, '');

        // Parse existing comma-separated active values.
        $currentValues = ($rawValue !== '' && $rawValue !== null)
            ? array_values(array_filter(array_map('trim', explode(',', (string)$rawValue))))
            : [];

        $itemValue = (string)$optionId;

        if (in_array($itemValue, $currentValues, true)) {
            // Value is active → remove it (toggle off).
            $newValues = array_values(array_filter($currentValues, static fn($v) => $v !== $itemValue));
        } else {
            // Value is not active → add it (toggle on).
            $newValues = array_merge($currentValues, [$itemValue]);
        }

        $query = [
            $attributeCode                   => empty($newValues) ? null : implode(',', $newValues),
            $this->pager->getPageVarName()   => null,
        ];

        return $this->url->getUrl('*/*/*', [
            '_current'     => true,
            '_use_rewrite' => true,
            '_query'       => $query,
        ]);
    }
}
