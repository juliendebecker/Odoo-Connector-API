<?php

namespace Emipro\Apichange\Api\Data;

/**
 * Refund request contract shared by the preview endpoints and the guarded
 * commit endpoint.
 *
 * The same payload MUST be replayed byte-for-byte between preview and commit:
 * the commit endpoint recomputes the payload hash and refuses when it differs.
 */
interface RefundRequestInterface
{
    const ORDER_ID = 'order_id';
    const INVOICE_ID = 'invoice_id';
    const ITEMS = 'items';
    const SHIPPING_AMOUNT = 'shipping_amount';
    const ADJUSTMENT_POSITIVE = 'adjustment_positive';
    const ADJUSTMENT_NEGATIVE = 'adjustment_negative';
    const IS_ONLINE = 'is_online';
    const PREVIEW_TOKEN = 'preview_token';
    const IDEMPOTENCY_KEY = 'idempotency_key';
    const COMMENT = 'comment';

    /**
     * Sales order entity id. Required for the order flow, optional (and ignored
     * for document selection) for the invoice flow.
     *
     * @api
     * @return int|null
     */
    public function getOrderId();

    /**
     * @api
     * @param int|null $orderId
     * @return $this
     */
    public function setOrderId($orderId);

    /**
     * Invoice entity id. Required for the invoice flow and for any online refund.
     *
     * @api
     * @return int|null
     */
    public function getInvoiceId();

    /**
     * @api
     * @param int|null $invoiceId
     * @return $this
     */
    public function setInvoiceId($invoiceId);

    /**
     * Lines to refund. An empty list means "everything still refundable",
     * which is the native Magento behaviour of CreditmemoFactory.
     *
     * @api
     * @return \Emipro\Apichange\Api\Data\RefundItemInterface[]|null
     */
    public function getItems();

    /**
     * @api
     * @param \Emipro\Apichange\Api\Data\RefundItemInterface[]|null $items
     * @return $this
     */
    public function setItems($items);

    /**
     * Shipping amount to refund.
     *
     * WARNING - this value is in BASE currency, not in order currency. It is
     * forwarded verbatim to Magento's CreditmemoFactory::initData(), which
     * assigns it through Creditmemo::setBaseShippingAmount(). That asymmetry
     * with the adjustment fields below is Magento's, not this module's: the
     * native REST refund endpoints behave exactly the same way.
     *
     * Null lets Magento compute the default refundable shipping amount.
     * Always read the previewed totals back rather than assuming.
     *
     * @api
     * @return float|null
     */
    public function getShippingAmount();

    /**
     * @api
     * @param float|null $shippingAmount
     * @return $this
     */
    public function setShippingAmount($shippingAmount);

    /**
     * Positive adjustment ("Refund Shipping" sibling field in the admin form),
     * forwarded verbatim to Magento's CreditmemoFactory::initData(). Currency
     * handling is Magento's; read the previewed totals back to know the exact
     * effect on base_grand_total.
     *
     * @api
     * @return float|null
     */
    public function getAdjustmentPositive();

    /**
     * @api
     * @param float|null $adjustmentPositive
     * @return $this
     */
    public function setAdjustmentPositive($adjustmentPositive);

    /**
     * Negative adjustment deducted from the refund, forwarded verbatim to
     * Magento's CreditmemoFactory::initData(). Same caveat as
     * getAdjustmentPositive().
     *
     * @api
     * @return float|null
     */
    public function getAdjustmentNegative();

    /**
     * @api
     * @param float|null $adjustmentNegative
     * @return $this
     */
    public function setAdjustmentNegative($adjustmentNegative);

    /**
     * True to ask the payment gateway for a real refund. Magento only supports
     * online refunds against an invoice, so this requires invoice_id.
     *
     * @api
     * @return bool
     */
    public function getIsOnline();

    /**
     * @api
     * @param bool $isOnline
     * @return $this
     */
    public function setIsOnline($isOnline);

    /**
     * Token issued by a preview call. Ignored by preview, mandatory for commit.
     *
     * @api
     * @return string|null
     */
    public function getPreviewToken();

    /**
     * @api
     * @param string|null $previewToken
     * @return $this
     */
    public function setPreviewToken($previewToken);

    /**
     * Caller supplied idempotency key, persisted by the commit endpoint.
     *
     * @api
     * @return string|null
     */
    public function getIdempotencyKey();

    /**
     * @api
     * @param string|null $idempotencyKey
     * @return $this
     */
    public function setIdempotencyKey($idempotencyKey);

    /**
     * Optional credit memo comment. Never customer visible, never notified.
     *
     * @api
     * @return string|null
     */
    public function getComment();

    /**
     * @api
     * @param string|null $comment
     * @return $this
     */
    public function setComment($comment);
}
