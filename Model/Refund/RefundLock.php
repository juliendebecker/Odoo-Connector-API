<?php

namespace Emipro\Apichange\Model\Refund;

use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Per order mutual exclusion.
 *
 * Honest description of what this buys, because it is easy to overstate:
 *
 *  - It serialises the callers of THIS module. Two Odoo commits on the same
 *    order cannot interleave their read-verify-write sequence.
 *  - It does NOT stop anyone else. An admin credit memo, another integration or
 *    a cron job never takes this lock. Those writers are caught by the state
 *    fingerprint (if they committed before we read) and, failing that, by
 *    Magento's own refund validators and by the sales transaction.
 *  - It is an advisory lock, not a database transaction. Acquiring it commits
 *    nothing and rolls back nothing.
 *
 * The default LockManagerInterface backend is MySQL GET_LOCK, which is bound to
 * a connection, not to a transaction. Installations that configure the `file`
 * lock provider without a shared filesystem lose cross node exclusion entirely;
 * that is a deployment property this module cannot detect from inside.
 *
 * Lock names are kept short because \Magento\Framework\Lock\Backend\Database
 * prefixes them with the database name and refuses names longer than 64 bytes.
 */
class RefundLock
{
    /**
     * @var LockManagerInterface
     */
    private $lockManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     */
    public function __construct(LockManagerInterface $lockManager, LoggerInterface $logger)
    {
        $this->lockManager = $lockManager;
        $this->logger = $logger;
    }

    /**
     * @param int $orderId
     * @return string
     */
    public function nameFor($orderId)
    {
        return 'ept_rfnd_' . (int) $orderId;
    }

    /**
     * @param string $name
     * @param int $timeout Seconds.
     * @return bool
     */
    public function acquire($name, $timeout)
    {
        return (bool) $this->lockManager->lock($name, (int) $timeout);
    }

    /**
     * Never throws: releasing happens in a finally block and must not mask the
     * exception that is already travelling up.
     *
     * @param string $name
     * @return void
     */
    public function release($name)
    {
        try {
            $this->lockManager->unlock($name);
        } catch (\Exception $e) {
            $this->logger->error(
                'Emipro_Apichange: could not release refund lock ' . $name . ': ' . $e->getMessage()
            );
        }
    }
}
