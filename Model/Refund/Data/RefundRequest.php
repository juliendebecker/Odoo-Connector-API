<?php

namespace Emipro\Apichange\Model\Refund\Data;

use Emipro\Apichange\Api\Data\RefundRequestInterface;
use Magento\Framework\DataObject;

class RefundRequest extends DataObject implements RefundRequestInterface
{
    /**
     * @return int|null
     */
    public function getOrderId()
    {
        $value = $this->getData(self::ORDER_ID);

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @param int|null $orderId
     * @return $this
     */
    public function setOrderId($orderId)
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    /**
     * @return int|null
     */
    public function getInvoiceId()
    {
        $value = $this->getData(self::INVOICE_ID);

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @param int|null $invoiceId
     * @return $this
     */
    public function setInvoiceId($invoiceId)
    {
        return $this->setData(self::INVOICE_ID, $invoiceId);
    }

    /**
     * @return \Emipro\Apichange\Api\Data\RefundItemInterface[]|null
     */
    public function getItems()
    {
        return $this->getData(self::ITEMS);
    }

    /**
     * @param \Emipro\Apichange\Api\Data\RefundItemInterface[]|null $items
     * @return $this
     */
    public function setItems($items)
    {
        return $this->setData(self::ITEMS, $items);
    }

    /**
     * @return float|null
     */
    public function getShippingAmount()
    {
        return $this->nullableFloat(self::SHIPPING_AMOUNT);
    }

    /**
     * @param float|null $shippingAmount
     * @return $this
     */
    public function setShippingAmount($shippingAmount)
    {
        return $this->setData(self::SHIPPING_AMOUNT, $shippingAmount);
    }

    /**
     * @return float|null
     */
    public function getAdjustmentPositive()
    {
        return $this->nullableFloat(self::ADJUSTMENT_POSITIVE);
    }

    /**
     * @param float|null $adjustmentPositive
     * @return $this
     */
    public function setAdjustmentPositive($adjustmentPositive)
    {
        return $this->setData(self::ADJUSTMENT_POSITIVE, $adjustmentPositive);
    }

    /**
     * @return float|null
     */
    public function getAdjustmentNegative()
    {
        return $this->nullableFloat(self::ADJUSTMENT_NEGATIVE);
    }

    /**
     * @param float|null $adjustmentNegative
     * @return $this
     */
    public function setAdjustmentNegative($adjustmentNegative)
    {
        return $this->setData(self::ADJUSTMENT_NEGATIVE, $adjustmentNegative);
    }

    /**
     * @return bool
     */
    public function getIsOnline()
    {
        return (bool) $this->getData(self::IS_ONLINE);
    }

    /**
     * @param bool $isOnline
     * @return $this
     */
    public function setIsOnline($isOnline)
    {
        return $this->setData(self::IS_ONLINE, $isOnline);
    }

    /**
     * @return string|null
     */
    public function getPreviewToken()
    {
        return $this->nullableString(self::PREVIEW_TOKEN);
    }

    /**
     * @param string|null $previewToken
     * @return $this
     */
    public function setPreviewToken($previewToken)
    {
        return $this->setData(self::PREVIEW_TOKEN, $previewToken);
    }

    /**
     * @return string|null
     */
    public function getIdempotencyKey()
    {
        return $this->nullableString(self::IDEMPOTENCY_KEY);
    }

    /**
     * @param string|null $idempotencyKey
     * @return $this
     */
    public function setIdempotencyKey($idempotencyKey)
    {
        return $this->setData(self::IDEMPOTENCY_KEY, $idempotencyKey);
    }

    /**
     * @return string|null
     */
    public function getComment()
    {
        return $this->nullableString(self::COMMENT);
    }

    /**
     * @param string|null $comment
     * @return $this
     */
    public function setComment($comment)
    {
        return $this->setData(self::COMMENT, $comment);
    }

    /**
     * @param string $key
     * @return float|null
     */
    private function nullableFloat($key)
    {
        $value = $this->getData($key);

        return $value === null || $value === '' ? null : (float) $value;
    }

    /**
     * @param string $key
     * @return string|null
     */
    private function nullableString($key)
    {
        $value = $this->getData($key);
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
