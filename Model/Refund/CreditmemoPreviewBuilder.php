<?php

namespace Emipro\Apichange\Model\Refund;

use Emipro\Apichange\Api\Data\RefundRequestInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;

/**
 * Builds an unsaved credit memo with the native Magento engine.
 *
 * What is used
 * ------------
 * \Magento\Sales\Model\Order\CreditmemoFactory::createByOrder() and
 * ::createByInvoice(). Both build the credit memo through
 * \Magento\Sales\Model\Convert\Order and finish with
 * Creditmemo::collectTotals(), i.e. the real, configured chain of total
 * collectors (subtotal, shipping, tax, discount, adjustments, grand total).
 * This is the same factory that \Magento\Sales\Api\RefundOrderInterface and
 * RefundInvoiceInterface reach through CreditmemoDocumentFactory, so preview
 * and commit compute their totals with identical code.
 *
 * What is NOT done
 * ----------------
 * Creditmemo::register() is never called, CreditmemoRepository::save() is never
 * called, no payment method is touched, no transaction is created, no notifier
 * and no e-mail sender is invoked. The returned object is a detached in-memory
 * aggregate and is discarded at the end of the request.
 *
 * Isolation
 * ---------
 * The order is loaded through OrderFactory rather than OrderRepositoryInterface
 * so that the instance is not the one cached by the repository for the rest of
 * the request. Even though the collectors are believed to be read-only with
 * respect to the order, nothing this class produces can leak into a later save.
 */
class CreditmemoPreviewBuilder
{
    /**
     * @var OrderFactory
     */
    private $orderFactory;

    /**
     * @var InvoiceRepositoryInterface
     */
    private $invoiceRepository;

    /**
     * @var CreditmemoFactory
     */
    private $creditmemoFactory;

    /**
     * @param OrderFactory $orderFactory
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param CreditmemoFactory $creditmemoFactory
     */
    public function __construct(
        OrderFactory $orderFactory,
        InvoiceRepositoryInterface $invoiceRepository,
        CreditmemoFactory $creditmemoFactory
    ) {
        $this->orderFactory = $orderFactory;
        $this->invoiceRepository = $invoiceRepository;
        $this->creditmemoFactory = $creditmemoFactory;
    }

    /**
     * Order level simulation.
     *
     * @param RefundRequestInterface $request
     * @return array{order: Order, invoice: null, creditmemo: Creditmemo, warnings: string[]}
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function buildFromOrder(RefundRequestInterface $request)
    {
        $orderId = $request->getOrderId();
        if (!$orderId) {
            throw new InputException(__('order_id is required for an order level refund preview.'));
        }

        $order = $this->loadOrder($orderId);
        $warnings = $this->collectOrderWarnings($order, $request);
        $creditmemo = $this->creditmemoFactory->createByOrder($order, $this->buildFactoryData($request));

        return [
            'order' => $order,
            'invoice' => null,
            'creditmemo' => $creditmemo,
            'warnings' => $warnings,
        ];
    }

    /**
     * Invoice level simulation.
     *
     * @param RefundRequestInterface $request
     * @return array{order: Order, invoice: Invoice, creditmemo: Creditmemo, warnings: string[]}
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function buildFromInvoice(RefundRequestInterface $request)
    {
        $invoiceId = $request->getInvoiceId();
        if (!$invoiceId) {
            throw new InputException(__('invoice_id is required for an invoice level refund preview.'));
        }

        $invoice = $this->invoiceRepository->get($invoiceId);
        if (!$invoice instanceof Invoice) {
            throw new InputException(
                __('Invoice #%1 is not a \Magento\Sales\Model\Order\Invoice instance.', $invoiceId)
            );
        }

        // Invoice::getOrder() lazily loads its own detached order instance.
        $order = $invoice->getOrder();
        if (!$order instanceof Order || !$order->getEntityId()) {
            throw new NoSuchEntityException(__('Invoice #%1 has no loadable order.', $invoiceId));
        }

        if ($request->getOrderId() && (int) $request->getOrderId() !== (int) $order->getEntityId()) {
            throw new InputException(
                __('order_id %1 does not own invoice #%2.', $request->getOrderId(), $invoiceId)
            );
        }

        $warnings = $this->collectInvoiceWarnings($order, $invoice, $request);
        $creditmemo = $this->creditmemoFactory->createByInvoice($invoice, $this->buildFactoryData($request));

        return [
            'order' => $order,
            'invoice' => $invoice,
            'creditmemo' => $creditmemo,
            'warnings' => $warnings,
        ];
    }

    /**
     * Translate our request into the array CreditmemoFactory understands.
     *
     * Keys are only set when the caller supplied a value: an absent key means
     * "let Magento decide", which is not the same as sending 0.
     *
     * @param RefundRequestInterface $request
     * @return array
     */
    private function buildFactoryData(RefundRequestInterface $request)
    {
        $data = [];

        $qtys = [];
        foreach ((array) $request->getItems() as $item) {
            $itemId = (int) $item->getOrderItemId();
            $qty = (float) $item->getQty();
            $qtys[$itemId] = isset($qtys[$itemId]) ? $qtys[$itemId] + $qty : $qty;
        }
        if (!empty($qtys)) {
            $data['qtys'] = $qtys;
        }

        if ($request->getShippingAmount() !== null) {
            $data['shipping_amount'] = $request->getShippingAmount();
        }
        if ($request->getAdjustmentPositive() !== null) {
            $data['adjustment_positive'] = $request->getAdjustmentPositive();
        }
        if ($request->getAdjustmentNegative() !== null) {
            $data['adjustment_negative'] = $request->getAdjustmentNegative();
        }

        return $data;
    }

    /**
     * @param int $orderId
     * @return Order
     * @throws NoSuchEntityException
     */
    private function loadOrder($orderId)
    {
        $order = $this->orderFactory->create()->load((int) $orderId);
        if (!$order->getEntityId()) {
            throw new NoSuchEntityException(__('Order with id "%1" does not exist.', $orderId));
        }

        return $order;
    }

    /**
     * Cheap, non authoritative pre-checks.
     *
     * These are NOT Magento's refund validators. The authoritative validation
     * runs inside RefundOrderInterface / RefundInvoiceInterface at commit time,
     * so a preview that returns no warning can still be refused at commit.
     * Warnings exist to give Odoo an early, actionable signal, nothing more.
     *
     * @param Order $order
     * @param RefundRequestInterface $request
     * @return string[]
     */
    private function collectOrderWarnings(Order $order, RefundRequestInterface $request)
    {
        $warnings = [];

        if (!$order->canCreditmemo()) {
            $warnings[] = 'Order::canCreditmemo() is false; the commit will be refused by Magento.';
        }
        if ($request->getIsOnline()) {
            $warnings[] = 'is_online is ignored for an order level refund: Magento only supports '
                . 'online refunds through RefundInvoiceInterface. Use the invoice endpoint.';
        }

        return array_merge($warnings, $this->collectItemWarnings($order, $request));
    }

    /**
     * @param Order $order
     * @param Invoice $invoice
     * @param RefundRequestInterface $request
     * @return string[]
     */
    private function collectInvoiceWarnings(Order $order, Invoice $invoice, RefundRequestInterface $request)
    {
        $warnings = [];

        if (!$order->canCreditmemo()) {
            $warnings[] = 'Order::canCreditmemo() is false; the commit will be refused by Magento.';
        }
        if (!$invoice->canRefund()) {
            $warnings[] = 'Invoice::canRefund() is false; the commit will be refused by Magento.';
        }
        if ($request->getIsOnline() && !$invoice->getTransactionId()) {
            $warnings[] = 'is_online is requested but the invoice carries no transaction id; '
                . 'the payment method will most likely reject the gateway refund.';
        }

        return array_merge($warnings, $this->collectItemWarnings($order, $request));
    }

    /**
     * @param Order $order
     * @param RefundRequestInterface $request
     * @return string[]
     */
    private function collectItemWarnings(Order $order, RefundRequestInterface $request)
    {
        $items = (array) $request->getItems();
        if (empty($items)) {
            return [];
        }

        $byId = [];
        foreach ($order->getAllItems() as $orderItem) {
            $byId[(int) $orderItem->getId()] = $orderItem;
        }

        $warnings = [];
        foreach ($items as $item) {
            $itemId = (int) $item->getOrderItemId();
            $qty = (float) $item->getQty();

            if ($qty <= 0) {
                $warnings[] = sprintf('Order item %d: qty %s is not strictly positive.', $itemId, (string) $qty);
            }
            if (!isset($byId[$itemId])) {
                $warnings[] = sprintf('Order item %d does not belong to this order.', $itemId);
                continue;
            }

            $refundable = (float) $byId[$itemId]->getQtyToRefund();
            if ($qty > $refundable) {
                $warnings[] = sprintf(
                    'Order item %d: requested qty %s exceeds the refundable qty %s.',
                    $itemId,
                    (string) $qty,
                    (string) $refundable
                );
            }
        }

        return $warnings;
    }
}
