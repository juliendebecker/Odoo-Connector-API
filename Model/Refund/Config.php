<?php

namespace Emipro\Apichange\Model\Refund;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Store scoped settings of the refund bridge.
 */
class Config
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Lifetime of a preview token, in seconds.
     *
     * Short on purpose: the token only certifies a snapshot, and the longer it
     * lives the more likely the state fingerprint check is to reject it anyway.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getPreviewTtl($storeId = null)
    {
        $value = (int) $this->scopeConfig->getValue(
            Contract::XML_PATH_PREVIEW_TTL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value > 0 ? $value : Contract::DEFAULT_PREVIEW_TTL;
    }

    /**
     * Seconds spent waiting for the per order lock before giving up.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getLockTimeout($storeId = null)
    {
        $value = (int) $this->scopeConfig->getValue(
            Contract::XML_PATH_LOCK_TIMEOUT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value > 0 ? $value : Contract::DEFAULT_LOCK_TIMEOUT;
    }

    /**
     * Age, in seconds, at which an `in_progress` idempotency claim may be taken
     * over by a new attempt using the same key and the same payload.
     *
     * Such a row means "a process claimed this key and never came back": either
     * it is still running, or it was killed between the claim and the refund.
     * Waiting long enough makes the first reading implausible; the second is
     * then handled under the per order lock, and the state fingerprint still
     * stands between the retry and a duplicate credit memo.
     *
     * Zero - unlike the other two settings, where zero means "unset" - turns the
     * takeover off entirely: a stale claim then blocks its key until an operator
     * resolves it by hand. Negative values are read as zero.
     *
     * @param int|null $storeId
     * @return int Seconds, or 0 when automatic recovery is disabled.
     */
    public function getInProgressTtl($storeId = null)
    {
        $value = $this->scopeConfig->getValue(
            Contract::XML_PATH_IN_PROGRESS_TTL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($value === null || $value === '') {
            return Contract::DEFAULT_IN_PROGRESS_TTL;
        }

        $value = (int) $value;

        return $value > 0 ? $value : 0;
    }

    /**
     * Whether offline refunds are wrapped in an outer database transaction so
     * that a post commit total mismatch can still be rolled back.
     *
     * Defaults to enabled. Turn it off only if a third party module misbehaves
     * with nested transactions.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAtomicVerifyEnabled($storeId = null)
    {
        $value = $this->scopeConfig->getValue(
            Contract::XML_PATH_ATOMIC_VERIFY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value === null ? true : (bool) $value;
    }
}
