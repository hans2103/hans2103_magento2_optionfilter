<?php
/**
 * Copyright © Hans2103. All rights reserved.
 */

declare(strict_types=1);

namespace Hans2103\OptionFilter\Plugin\Adminhtml\Attribute\Form;

use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Catalog\Block\Adminhtml\Product\Attribute\Edit\Tab\Front;
use Magento\Config\Model\Config\Source\Yesno;
use Magento\Framework\Data\Form;

/**
 * Plugin to add "Use in Multiselect Layered Navigation" field to attribute Storefront Properties tab
 */
class AddMultiselectField
{
    /**
     * @var Yesno
     */
    private Yesno $yesno;

    /**
     * @param Yesno $yesno
     */
    public function __construct(Yesno $yesno)
    {
        $this->yesno = $yesno;
    }

    /**
     * Add multiselect filter field after the storefront properties form is built
     *
     * @param Front $subject
     * @param Generic $result
     * @param Form $form
     * @return Generic
     */
    public function afterSetForm(Front $subject, Generic $result, Form $form): Generic
    {
        $fieldset = $form->getElement('front_fieldset');
        if (!$fieldset) {
            return $result;
        }

        $fieldset->addField(
            'is_multiselect_filter',
            'select',
            [
                'name'  => 'is_multiselect_filter',
                'label' => __('Use in Multiselect Layered Navigation'),
                'title' => __('Use in Multiselect Layered Navigation'),
                'note'  => __('Allow multiple values to be selected simultaneously in layered navigation.'),
                'values' => $this->yesno->toOptionArray(),
            ]
        );

        return $result;
    }
}
