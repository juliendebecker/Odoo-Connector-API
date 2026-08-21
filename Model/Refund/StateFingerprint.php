<?php

namespace Emipro\Apichange\Model\Refund;

use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;

/**
 * Hash of every persisted value that can change the outcome of a refund.
 *
 * The fingerprint is what makes the guard useful against changes performed
 * outside this API (admin credit memo, another integration, a cron job): those
 * writers do not take our lock, but they cannot avoid moving one of the columns
 * below.
 *
 * Known blind spots, by construction:
 *  - a change that leaves all of these columns untouched is invisible
 *    (for example a store configuration change such as a tax rate, which is not
 *    part of an order row but does influence some total collectors);
 *  - rows written by third party modules in their own tables are not covered.
 */
class StateFingerprint
{
    /**
     * @var Canonicalizer
     */
    private $canonicalizer;

    /**
     * @var CreditmemoRepositoryInterface
     */
    private $creditmemoRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var SortOrderBuilder
     */
    private $sortOrderBuilder;

    /**
     * @param Canonicalizer $canonicalizer
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     */
    public function __construct(
        Canonicalizer $canonicalizer,
        CreditmemoRepositoryInterface $creditmemoRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder
    ) {
        $this->canonicalizer = $canonicalizer;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->sortOrderBuilder = $sortOrderBuilder;
    }

    /**
     * @param OrderInterface $order
     * @param InvoiceInterface|null $invoice
     * @return string
     */
    public function fingerprint(OrderInterface $order, InvoiceInterface $invoice = null)
    {
        return $this->canonicalizer->hash($this->describe($order, $invoice));
    }

    /**
     * Structure behind the fingerprint. Exposed so that a mismatch can be
     * diagnosed without guessing.
     *
     * @param OrderInterface $order
     * @param InvoiceInterface|null $invoice
     * @return array
     */
    public function describe(OrderInterface $order, InvoiceInterface $invoice = null)
    {
        return [
            'contract_version' => Contract::CONTRACT_VERSION,
            'order' => $this->describeOrder($order),
            'items' => $this->describeItems($order),
            'payment' => $this->describePayment($order),
            'invoice' => $invoice === null ? null : $this->describeInvoice($invoice),
            'creditmemos' => $this->describeCreditmemos($order),
        ];
    }

    /**
     * @param OrderInterface $order
     * @return array
     */
    private function describeOrder(OrderInterface $order)
    {
        return [
            'entity_id' => (string) $order->getEntityId(),
            'store_id' => (string) $order->getStoreId(),
            'state' => (string) $order->getState(),
            'status' => (string) $order->getStatus(),
            'updated_at' => (string) $order->getUpdatedAt(),
            'base_currency_code' => (string) $order->getBaseCurrencyCode(),
            'order_currency_code' => (string) $order->getOrderCurrencyCode(),
            'base_to_order_rate' => $this->canonicalizer->nullableScalar($order->getBaseToOrderRate()),
            'base_grand_total' => $this->canonicalizer->nullableScalar($order->getBaseGrandTotal()),
            'base_subtotal' => $this->canonicalizer->nullableScalar($order->getBaseSubtotal()),
            'base_total_paid' => $this->canonicalizer->nullableScalar($order->getBaseTotalPaid()),
            'base_total_refunded' => $this->canonicalizer->nullableScalar($order->getBaseTotalRefunded()),
            'base_total_invoiced' => $this->canonicalizer->nullableScalar($order->getBaseTotalInvoiced()),
            'base_total_due' => $this->canonicalizer->nullableScalar($order->getBaseTotalDue()),
            'base_shipping_amount' => $this->canonicalizer->nullableScalar($order->getBaseShippingAmount()),
            'base_shipping_invoiced' => $this->canonicalizer->nullableScalar($order->getBaseShippingInvoiced()),
            'base_shipping_refunded' => $this->canonicalizer->nullableScalar($order->getBaseShippingRefunded()),
            'base_shipping_tax_refunded' => $this->canonicalizer->nullableScalar(
                $order->getBaseShippingTaxRefunded()
            ),
            'base_discount_refunded' => $this->canonicalizer->nullableScalar($order->getBaseDiscountRefunded()),
            'base_tax_refunded' => $this->canonicalizer->nullableScalar($order->getBaseTaxRefunded()),
            'base_subtotal_refunded' => $this->canonicalizer->nullableScalar($order->getBaseSubtotalRefunded()),
            'base_adjustment_positive' => $this->canonicalizer->nullableScalar($order->getBaseAdjustmentPositive()),
            'base_adjustment_negative' => $this->canonicalizer->nullableScalar($order->getBaseAdjustmentNegative()),
        ];
    }

    /**
     * @param OrderInterface $order
     * @return array
     */
    private function describeItems(OrderInterface $order)
    {
        $items = [];
        foreach ((array) $order->getItems() as $item) {
            $items[(int) $item->getItemId()] = [
                'item_id' => (string) $item->getItemId(),
                'parent_item_id' => $item->getParentItemId() === null
                    ? null
                    : (string) $item->getParentItemId(),
                'sku' => (string) $item->getSku(),
                'product_type' => (string) $item->getProductType(),
                'qty_ordered' => $this->canonicalizer->nullableScalar($item->getQtyOrdered()),
                'qty_invoiced' => $this->canonicalizer->nullableScalar($item->getQtyInvoiced()),
                'qty_shipped' => $this->canonicalizer->nullableScalar($item->getQtyShipped()),
                'qty_refunded' => $this->canonicalizer->nullableScalar($item->getQtyRefunded()),
                'qty_canceled' => $this->canonicalizer->nullableScalar($item->getQtyCanceled()),
                'base_price' => $this->canonicalizer->nullableScalar($item->getBasePrice()),
                'base_row_total' => $this->canonicalizer->nullableScalar($item->getBaseRowTotal()),
                'base_tax_amount' => $this->canonicalizer->nullableScalar($item->getBaseTaxAmount()),
                'base_discount_amount' => $this->canonicalizer->nullableScalar($item->getBaseDiscountAmount()),
                'base_amount_refunded' => $this->canonicalizer->nullableScalar($item->getBaseAmountRefunded()),
                'base_tax_refunded' => $this->canonicalizer->nullableScalar($item->getBaseTaxRefunded()),
                'base_discount_refunded' => $this->canonicalizer->nullableScalar($item->getBaseDiscountRefunded()),
            ];
        }

        ksort($items, SORT_NUMERIC);

        return array_values($items);
    }

    /**
     * @param OrderInterface $order
     * @return array|null
     */
    private function describePayment(OrderInterface $order)
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return null;
        }

        return [
            'method' => (string) $payment->getMethod(),
            'base_amount_paid' => $this->canonicalizer->nullableScalar($payment->getBaseAmountPaid()),
            'base_amount_refunded' => $this->canonicalizer->nullableScalar($payment->getBaseAmountRefunded()),
            'base_amount_refunded_online' => $this->canonicalizer->nullableScalar(
                $payment->getBaseAmountRefundedOnline()
            ),
            'last_trans_id' => $payment->getLastTransId() === null ? null : (string) $payment->getLastTransId(),
        ];
    }

    /**
     * @param InvoiceInterface $invoice
     * @return array
     */
    private function describeInvoice(InvoiceInterface $invoice)
    {
        $items = [];
        foreach ((array) $invoice->getItems() as $item) {
            $items[(int) $item->getOrderItemId()] = [
                'order_item_id' => (string) $item->getOrderItemId(),
                'qty' => $this->canonicalizer->nullableScalar($item->getQty()),
                'base_row_total' => $this->canonicalizer->nullableScalar($item->getBaseRowTotal()),
            ];
        }
        ksort($items, SORT_NUMERIC);

        return [
            'entity_id' => (string) $invoice->getEntityId(),
            'increment_id' => (string) $invoice->getIncrementId(),
            'state' => (string) $invoice->getState(),
            'updated_at' => (string) $invoice->getUpdatedAt(),
            'base_grand_total' => $this->canonicalizer->nullableScalar($invoice->getBaseGrandTotal()),
            'base_total_refunded' => $this->canonicalizer->nullableScalar($invoice->getBaseTotalRefunded()),
            'transaction_id' => $invoice->getTransactionId() === null
                ? null
                : (string) $invoice->getTransactionId(),
            'items' => array_values($items),
        ];
    }

    /**
     * Every credit memo already attached to the order.
     *
     * This is what catches a refund created between the preview and the commit
     * even when the order row itself was not reloaded by the caller.
     *
     * @param OrderInterface $order
     * @return array
     */
    private function describeCreditmemos(OrderInterface $order)
    {
        $sortOrder = $this->sortOrderBuilder
            ->setField('entity_id')
            ->setAscendingDirection()
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('order_id', (int) $order->getEntityId())
            ->addSortOrder($sortOrder)
            ->create();

        $creditmemos = [];
        foreach ($this->creditmemoRepository->getList($searchCriteria)->getItems() as $creditmemo) {
            $creditmemos[] = [
                'entity_id' => (string) $creditmemo->getEntityId(),
                'state' => (string) $creditmemo->getState(),
                'base_grand_total' => $this->canonicalizer->nullableScalar($creditmemo->getBaseGrandTotal()),
            ];
        }

        return $creditmemos;
    }
}
