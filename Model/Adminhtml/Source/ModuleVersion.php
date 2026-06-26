<?php

namespace PayMaya\Payment\Model\Adminhtml\Source;

/**
 * Class ModuleVersion
 * Renders the extension version number in the Magento admin configuration.
 */
class ModuleVersion extends \Magento\Config\Block\System\Config\Form\Field
{
    /**
     * Render system config element
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     * @return string
     */
    public function render(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Get the element HTML representation
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        return \PayMaya\Payment\Model\Config::$moduleVersion;
    }
}
