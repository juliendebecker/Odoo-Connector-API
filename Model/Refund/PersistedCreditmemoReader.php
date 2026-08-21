<?php

namespace Emipro\Apichange\Model\Refund;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads a credit memo back from the database, and only from the database.
 *
 * Why not CreditmemoRepositoryInterface::get()
 * -------------------------------------------
 * \Magento\Sales\Model\Order\CreditmemoRepository keeps an in-memory registry
 * keyed by entity id, and save() puts the entity it has just written into it.
 * A get() issued later in the same request therefore hands back the very object
 * the refund service built, totals included. Verifying that object against the
 * previewed totals compares our own computation with itself: it can only ever
 * match, which makes the post write check look like a guarantee while proving
 * nothing about what MySQL actually stored.
 *
 * A plain SELECT on the primary key has none of that. It goes to the same
 * connection the refund was written on - so an offline refund still inside its
 * outer transaction reads its own uncommitted rows, which is exactly what the
 * rollback path needs - and it returns the stored columns, whatever a plugin,
 * a trigger or a column definition did to them on the way in.
 *
 * The raw row is returned rather than a hydrated model on purpose: hydrating it
 * would mean going through an entity factory again, and the point here is to
 * stay as close to the stored bytes as possible.
 */
class PersistedCreditmemoReader
{
    /**
     * Physical table, not an entity name: this class deliberately bypasses the
     * ORM layer.
     */
    const TABLE = 'sales_creditmemo';

    /**
     * The only columns the post write verification needs.
     */
    const COLUMNS = ['entity_id', 'increment_id', 'base_grand_total', 'grand_total'];

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param int $creditmemoId
     * @return array|null The stored row, or null when there is no such row.
     */
    public function read($creditmemoId)
    {
        $connection = $this->resourceConnection->getConnection(Contract::SALES_CONNECTION);

        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), self::COLUMNS)
            ->where('entity_id = ?', (int) $creditmemoId)
            ->limit(1);

        $row = $connection->fetchRow($select);

        return $row === false || $row === null ? null : $row;
    }
}
