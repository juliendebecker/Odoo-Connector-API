<?php

namespace Emipro\Apichange\Api\Data;

/**
 * One line of a refund request coming from Odoo.
 *
 * Quantities are expressed in the order item unit, exactly like
 * \Magento\Sales\Api\Data\CreditmemoItemCreationInterface::getQty().
 */
interface RefundItemInterface
{
    const ORDER_ITEM_ID = 'order_item_id';
    const QTY = 'qty';

    /**
     * Order item entity id (sales_order_item.item_id).
     *
     * @api
     * @return int
     */
    public function getOrderItemId();

    /**
     * @api
     * @param int $orderItemId
     * @return $this
     */
    public function setOrderItemId($orderItemId);

    /**
     * Quantity to refund for this order item.
     *
     * @api
     * @return float
     */
    public function getQty();

    /**
     * @api
     * @param float $qty
     * @return $this
     */
    public function setQty($qty);
}
