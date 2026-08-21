<?php

namespace Emipro\Apichange\Api;

/**
 * Read-only refund simulation.
 *
 * Both methods build a real \Magento\Sales\Model\Order\Creditmemo through the
 * native \Magento\Sales\Model\Order\CreditmemoFactory and run the native total
 * collectors (Creditmemo::collectTotals()). Nothing is registered, nothing is
 * saved, no payment is captured or refunded and no e-mail is queued.
 */
interface RefundPreviewInterface
{
    /**
     * Simulate an offline order level credit memo.
     *
     * @api
     * @param \Emipro\Apichange\Api\Data\RefundRequestInterface $request
     * @return mixed[] Versioned refund preview contract.
     */
    public function previewOrder(\Emipro\Apichange\Api\Data\RefundRequestInterface $request);

    /**
     * Simulate an invoice level credit memo (online or offline).
     *
     * @api
     * @param \Emipro\Apichange\Api\Data\RefundRequestInterface $request
     * @return mixed[] Versioned refund preview contract.
     */
    public function previewInvoice(\Emipro\Apichange\Api\Data\RefundRequestInterface $request);
}
