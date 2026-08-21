<?php

namespace Emipro\Apichange\Model\Refund;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;

/**
 * Turns an unsaved credit memo into the versioned JSON contract sent to Odoo.
 *
 * Every amount is read with getData() using the physical column name of
 * sales_creditmemo / sales_creditmemo_item. That is deliberate: the column set
 * is far more stable across Magento minor versions than the getter set (the
 * base shipping discount tax compensation column, for instance, is exposed by a
 * truncated getter name), and it lets Odoo map fields one to one against a
 * schema it can look up.
 *
 * All amounts are decimal STRINGS with 4 decimals, never JSON floats: a JSON
 * float would be re-parsed by Odoo as a binary double and would break the
 * hash comparison performed at commit time.
 */
class ContractSerializer
{
    /**
     * Credit memo total columns, order currency and base currency side by side.
     *
     * @var string[]
     */
    private static $totalColumns = [
        'subtotal',
        'base_subtotal',
        'subtotal_incl_tax',
        'base_subtotal_incl_tax',
        'shipping_amount',
        'base_shipping_amount',
        'shipping_incl_tax',
        'base_shipping_incl_tax',
        'shipping_tax_amount',
        'base_shipping_tax_amount',
        'tax_amount',
        'base_tax_amount',
        'discount_amount',
        'base_discount_amount',
        'discount_tax_compensation_amount',
        'base_discount_tax_compensation_amount',
        'shipping_discount_tax_compensation_amount',
        'base_shipping_discount_tax_compensation_amnt',
        'adjustment',
        'base_adjustment',
        'adjustment_positive',
        'base_adjustment_positive',
        'adjustment_negative',
        'base_adjustment_negative',
        'grand_total',
        'base_grand_total',
    ];

    /**
     * Credit memo item columns.
     *
     * @var string[]
     */
    private static $itemColumns = [
        'price',
        'base_price',
        'price_incl_tax',
        'base_price_incl_tax',
        'row_total',
        'base_row_total',
        'row_total_incl_tax',
        'base_row_total_incl_tax',
        'tax_amount',
        'base_tax_amount',
        'discount_amount',
        'base_discount_amount',
        'discount_tax_compensation_amount',
        'base_discount_tax_compensation_amount',
    ];

    /**
     * @var Canonicalizer
     */
    private $canonicalizer;

    /**
     * @param Canonicalizer $canonicalizer
     */
    public function __construct(Canonicalizer $canonicalizer)
    {
        $this->canonicalizer = $canonicalizer;
    }

    /**
     * @param Creditmemo $creditmemo
     * @param Order $order
     * @param Invoice|null $invoice
     * @param string $source
     * @param bool $isOnline
     * @return array
     */
    public function serialize(
        Creditmemo $creditmemo,
        Order $order,
        Invoice $invoice = null,
        $source = '',
        $isOnline = false
    ) {
        return [
            'contract_version' => Contract::CONTRACT_VERSION,
            'source' => $source,
            'is_online' => (bool) $isOnline,
            'order' => [
                'order_id' => (int) $order->getEntityId(),
                'increment_id' => (string) $order->getIncrementId(),
                'state' => (string) $order->getState(),
                'status' => (string) $order->getStatus(),
                'store_id' => (int) $order->getStoreId(),
            ],
            'invoice' => $invoice === null ? null : [
                'invoice_id' => (int) $invoice->getEntityId(),
                'increment_id' => (string) $invoice->getIncrementId(),
                'state' => (int) $invoice->getState(),
            ],
            'currency' => $this->serializeCurrency($order),
            'items' => $this->serializeItems($creditmemo),
            'totals' => $this->serializeTotals($creditmemo),
            'refundable' => $this->serializeRefundable($order, $invoice),
        ];
    }

    /**
     * @param Order $order
     * @return array
     */
    private function serializeCurrency(Order $order)
    {
        return [
            'base_currency_code' => (string) $order->getBaseCurrencyCode(),
            'order_currency_code' => (string) $order->getOrderCurrencyCode(),
            'global_currency_code' => (string) $order->getGlobalCurrencyCode(),
            'base_to_order_rate' => $this->canonicalizer->nullableScalar($order->getBaseToOrderRate()),
            'scale' => Contract::SCALE,
        ];
    }

    /**
     * @param Creditmemo $creditmemo
     * @return array
     */
    private function serializeItems(Creditmemo $creditmemo)
    {
        $items = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $row = [
                'order_item_id' => (int) $item->getOrderItemId(),
                'sku' => (string) $item->getSku(),
                'name' => (string) $item->getName(),
                'qty' => $this->canonicalizer->scalar((float) $item->getQty()),
            ];
            foreach (self::$itemColumns as $column) {
                $row[$column] = $this->canonicalizer->nullableScalar($item->getData($column));
            }
            $items[] = $row;
        }

        // Stable ordering: the credit memo item order is an implementation
        // detail of the collectors, the contract must not depend on it.
        usort($items, function (array $left, array $right) {
            return $left['order_item_id'] < $right['order_item_id'] ? -1
                : ($left['order_item_id'] > $right['order_item_id'] ? 1 : 0);
        });

        return $items;
    }

    /**
     * @param Creditmemo $creditmemo
     * @return array
     */
    private function serializeTotals(Creditmemo $creditmemo)
    {
        // Read the physical creditmemo column like every other total. This
        // avoids depending on Magento's magic getter (and keeps the contract
        // serializer compatible with strict/mock objects).
        $totals = ['total_qty' => $this->canonicalizer->scalar((float) $creditmemo->getData('total_qty'))];
        foreach (self::$totalColumns as $column) {
            $totals[$column] = $this->canonicalizer->nullableScalar($creditmemo->getData($column));
        }

        return $totals;
    }

    /**
     * Head room left on the order, so Odoo can decide without a second call.
     *
     * @param Order $order
     * @param Invoice|null $invoice
     * @return array
     */
    private function serializeRefundable(Order $order, Invoice $invoice = null)
    {
        $paid = (float) $order->getBaseTotalPaid();
        $refunded = (float) $order->getBaseTotalRefunded();

        return [
            'order_can_creditmemo' => (bool) $order->canCreditmemo(),
            'invoice_can_refund' => $invoice === null ? null : (bool) $invoice->canRefund(),
            'base_total_paid' => $this->canonicalizer->scalar($paid),
            'base_total_refunded' => $this->canonicalizer->scalar($refunded),
            'base_max_refundable_online' => $this->canonicalizer->scalar(max(0.0, $paid - $refunded)),
            'payment_method' => $order->getPayment() === null ? null : (string) $order->getPayment()->getMethod(),
        ];
    }
}
