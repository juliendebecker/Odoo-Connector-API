<?php

namespace Emipro\Apichange\Model\Refund;

use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;

/**
 * Thrown when the outcome of a refund cannot be established.
 *
 * This is the honest exception. It means: the native refund service returned,
 * so a credit memo may exist and, on the online path, the payment gateway may
 * already have moved money - but something failed afterwards and this process
 * can no longer prove what was persisted.
 *
 * It is deliberately NOT a 409: a 409 tells the caller "nothing happened, fix
 * your request and retry", which would be a lie here. It carries a 500 so that
 * Odoo treats it as an incident, and it makes the idempotency ledger record
 * `indeterminate`, which permanently blocks automatic retries under that key.
 */
class IndeterminateRefundException extends WebapiException
{
    /**
     * @var \Exception|null
     */
    private $indeterminateCause;

    /**
     * @param Phrase $phrase
     * @param array $details
     * @param \Exception|null $previous
     */
    public function __construct(Phrase $phrase, array $details = [], \Exception $previous = null)
    {
        $details['error_code'] = Contract::ERR_REFUND_INDETERMINATE;

        parent::__construct($phrase, 0, Contract::HTTP_INTERNAL_ERROR, $details);

        if ($previous !== null) {
            // WebapiException does not expose the previous exception through its
            // constructor in every Magento minor version; keep it reachable for
            // logging without depending on that signature.
            $this->indeterminateCause = $previous;
        }
    }

    /**
     * @return \Exception|null
     */
    public function getIndeterminateCause()
    {
        return $this->indeterminateCause;
    }
}
