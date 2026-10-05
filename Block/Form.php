<?php

namespace PayMaya\Payment\Block;

/**
 * Class Form
 * Block class for rendering the payment form template in checkout.
 */
class Form extends \Magento\Payment\Block\Form\Cc
{
    // protected $_template = 'form/paymaya_payments.phtml';

    /**
     * @var mixed
     */
    public $config;

    /**
     * @var mixed
     */
    public $setupIntent;
}
