<?php

namespace Emipro\Apichange\Model\Refund;

/**
 * Frozen constants of the Odoo <-> Magento refund contract.
 *
 * CONTRACT_VERSION is part of both the payload hash and the preview token, so
 * bumping it invalidates every token issued by an older deployment. Bump it on
 * any change that alters the meaning or the serialisation of the contract.
 */
class Contract
{
    /**#@+
     * Contract identity.
     */
    const CONTRACT_VERSION = '1.0.0';
    const TOKEN_VERSION = 'v1';
    /**#@-*/

    /**#@+
     * Refund document source.
     */
    const SOURCE_ORDER = 'order';
    const SOURCE_INVOICE = 'invoice';
    /**#@-*/

    /**
     * Number of decimals used to normalise every monetary and quantity value
     * before hashing. Magento stores sales amounts as decimal(20,4).
     */
    const SCALE = 4;

    /**
     * Resource name of the connection every refund write and every post write
     * read goes through.
     *
     * The 'sales' resource rather than 'default', so that the adapter is exactly
     * the one Magento\Sales\Model\RefundOrder uses, split database setups
     * included - and so that a read issued inside the outer refund transaction
     * sees that transaction's own, not yet committed, rows.
     */
    const SALES_CONNECTION = 'sales';

    /**#@+
     * Machine readable guard rejection codes returned to Odoo.
     */
    const ERR_PREVIEW_TOKEN_MISSING = 'PREVIEW_TOKEN_MISSING';
    const ERR_PREVIEW_TOKEN_INVALID = 'PREVIEW_TOKEN_INVALID';
    const ERR_PREVIEW_EXPIRED = 'PREVIEW_EXPIRED';
    const ERR_CONTRACT_VERSION_MISMATCH = 'CONTRACT_VERSION_MISMATCH';
    const ERR_SCOPE_MISMATCH = 'SCOPE_MISMATCH';
    const ERR_PAYLOAD_MISMATCH = 'PAYLOAD_MISMATCH';
    const ERR_STATE_MISMATCH = 'STATE_MISMATCH';
    const ERR_TOTAL_MISMATCH = 'TOTAL_MISMATCH';
    const ERR_LOCK_TIMEOUT = 'LOCK_TIMEOUT';
    const ERR_IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';
    const ERR_IDEMPOTENCY_IN_PROGRESS = 'IDEMPOTENCY_IN_PROGRESS';
    const ERR_NOT_REFUNDABLE = 'NOT_REFUNDABLE';
    const ERR_INVALID_REQUEST = 'INVALID_REQUEST';
    const ERR_POST_COMMIT_MISMATCH = 'POST_COMMIT_MISMATCH';
    const ERR_REFUND_INDETERMINATE = 'REFUND_INDETERMINATE';
    /**#@-*/

    /**
     * HTTP status used for every guard rejection. \Magento\Framework\Webapi\Exception
     * accepts any code in the 400-599 range, it only exposes named constants for a
     * subset of them, hence these local constants.
     */
    const HTTP_CONFLICT = 409;

    /**
     * Reserved for the one case that is genuinely not the caller's fault: the
     * outcome of the refund could not be established.
     */
    const HTTP_INTERNAL_ERROR = 500;

    /**#@+
     * Configuration paths.
     */
    const XML_PATH_PREVIEW_TTL = 'apichange/refund/preview_ttl';
    const XML_PATH_LOCK_TIMEOUT = 'apichange/refund/lock_timeout';
    const XML_PATH_ATOMIC_VERIFY = 'apichange/refund/atomic_verify';
    const XML_PATH_IN_PROGRESS_TTL = 'apichange/refund/in_progress_ttl';
    /**#@-*/

    /**#@+
     * Configuration fallbacks used when the store config is unset.
     */
    const DEFAULT_PREVIEW_TTL = 900;
    const DEFAULT_LOCK_TIMEOUT = 10;

    /**
     * Age at which an in_progress ledger row is considered abandoned by the
     * process that claimed it. Well above any realistic refund duration:
     * recovering too early would race a live refund, and the only cost of
     * recovering late is that the key stays blocked a little longer.
     */
    const DEFAULT_IN_PROGRESS_TTL = 900;
    /**#@-*/
}
