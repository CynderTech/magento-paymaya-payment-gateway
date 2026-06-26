<?php

namespace PayMaya\Payment\Model\Adminhtml\Source;

/**
 * Class Mode
 * Source model providing environment modes (Test/Live) for Magento Admin configuration.
 */
class Mode
{
    /**
     * Test/Sandbox environment mode
     */
    public const TEST = 'test';

    /**
     * Live/Production environment mode
     */
    public const LIVE = 'live';

    /**
     * Options array for backend environment mode dropdown
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => Mode::TEST,
                'label' => __('Test')
            ],
            [
                'value' => Mode::LIVE,
                'label' => __('Live')
            ],
        ];
    }
}
