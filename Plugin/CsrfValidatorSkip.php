<?php

namespace PayMaya\Payment\Plugin;

/**
 * Class CsrfValidatorSkip
 * Skips CSRF validation for PayMaya requests.
 */
class CsrfValidatorSkip
{
    /**
     * Skip CSRF check for the paymaya module
     *
     * @param \Magento\Framework\App\Request\CsrfValidator $subject
     * @param \Closure $proceed
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\App\ActionInterface $action
     * @return void
     */
    public function aroundValidate(
        $subject,
        \Closure $proceed,
        $request,
        $action
    ) {
        if ($request->getModuleName() == 'paymaya') {
            return; // Skip CSRF check
        }

        $proceed($request, $action);
    }
}
