<?php

namespace Emipro\Apichange\Api;

/**
 * Guarded refund commit.
 *
 * Replays the exact payload that produced a preview, re-derives the payload
 * hash, the state fingerprint and the credit memo totals under a mutex, and
 * refuses the refund when anything moved since the preview was issued.
 * When the guard passes, the refund itself is delegated to the native
 * \Magento\Sales\Api\RefundOrderInterface / RefundInvoiceInterface services.
 */
interface RefundInterface
{
    /**
     * @api
     * @param \Emipro\Apichange\Api\Data\RefundRequestInterface $request
     * @return string JSON-encoded refund result contract.
     */
    public function execute(\Emipro\Apichange\Api\Data\RefundRequestInterface $request);
}
