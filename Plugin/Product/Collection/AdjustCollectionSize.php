<?php
/**
 * Copyright © Hans2103. All rights reserved.
 */

declare(strict_types=1);

namespace Hans2103\OptionFilter\Plugin\Product\Collection;

use Magento\Catalog\Model\ResourceModel\Product\Collection;

/**
 * Plugin to ensure collection size reflects filtered products
 */
class AdjustCollectionSize
{
    /**
     * Clear the size cache after filtering so it gets recalculated
     *
     * @param Collection $subject
     * @param Collection $result
     * @return Collection
     */
    public function afterLoad(Collection $subject, Collection $result): Collection
    {
        // Force size recalculation by clearing cached size
        // This ensures toolbar shows correct count after filtering
        $reflection = new \ReflectionClass($result);

        if ($reflection->hasProperty('_totalRecords')) {
            $property = $reflection->getProperty('_totalRecords');
            $property->setAccessible(true);
            $property->setValue($result, null);
        }

        return $result;
    }
}
