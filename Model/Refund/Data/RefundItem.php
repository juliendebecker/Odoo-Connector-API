<?php

namespace Emipro\Apichange\Model\Refund\Data;

use Emipro\Apichange\Api\Data\RefundItemInterface;
use Magento\Framework\DataObject;

class RefundItem extends DataObject implements RefundItemInterface
{
    /**
     * @return int
     */
    public function getOrderItemId()
    {
        $value = $this->getData(self::ORDER_ITEM_ID);

        return $value === null ? null : (int) $value;
    }

    /**
     * @param int $orderItemId
     * @return $this
     */
    public function setOrderItemId($orderItemId)
    {
        return $this->setData(self::ORDER_ITEM_ID, $orderItemId);
    }

    /**
     * @return float
     */
    public function getQty()
    {
        $value = $this->getData(self::QTY);

        return $value === null ? null : (float) $value;
    }

    /**
     * @param float $qty
     * @return $this
     */
    public function setQty($qty)
    {
        return $this->setData(self::QTY, $qty);
    }
}
