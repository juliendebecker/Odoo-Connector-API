<?php

namespace Emipro\Apichange\Test\Unit\Model\Refund;

use Emipro\Apichange\Model\Refund\Contract;
use Emipro\Apichange\Model\Refund\PersistedCreditmemoReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

/**
 * The point of this class is *where* it reads from, so that is what is asserted
 * here: the sales connection, the sales_creditmemo table, by primary key. A
 * repository would answer from its in-memory registry and the post write
 * verification would end up comparing our own computation against itself.
 */
class PersistedCreditmemoReaderTest extends TestCase
{
    /**
     * @var array
     */
    private $captured = [];

    /**
     * @var string|null
     */
    private $requestedConnection;

    /**
     * @var AdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $connection;

    /**
     * @var ResourceConnection|\PHPUnit\Framework\MockObject\MockObject
     */
    private $resourceConnection;

    protected function setUp(): void
    {
        $this->captured = ['where' => []];
        $this->requestedConnection = null;

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnCallback(function ($table, $columns = '*') use (&$select) {
            $this->captured['from'] = [$table, $columns];

            return $select;
        });
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use (&$select) {
            $this->captured['where'][] = [$condition, $value];

            return $select;
        });
        $select->method('limit')->willReturnCallback(function ($count = null) use (&$select) {
            $this->captured['limit'] = $count;

            return $select;
        });

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);

        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturnCallback(function ($name = null) {
            $this->requestedConnection = $name;

            return $this->connection;
        });
        $this->resourceConnection->method('getTableName')->willReturnCallback(function ($table) {
            return 'pfx_' . $table;
        });
    }

    /**
     * @param array|false $row
     * @return PersistedCreditmemoReader
     */
    private function reader($row)
    {
        $this->connection->method('fetchRow')->willReturn($row);

        return new PersistedCreditmemoReader($this->resourceConnection);
    }

    public function testTheRowIsReadFromTheSalesConnection()
    {
        $this->reader(['entity_id' => '77'])->read(77);

        $this->assertSame(Contract::SALES_CONNECTION, $this->requestedConnection);
    }

    public function testTheRowIsSelectedByPrimaryKeyFromTheCreditmemoTable()
    {
        $this->reader(['entity_id' => '77'])->read(77);

        $this->assertSame(
            ['pfx_' . PersistedCreditmemoReader::TABLE, PersistedCreditmemoReader::COLUMNS],
            $this->captured['from']
        );
        $this->assertSame([['entity_id = ?', 77]], $this->captured['where']);
        $this->assertSame(1, $this->captured['limit']);
    }

    /**
     * The id reaches the query as an integer, never as the caller's raw value.
     */
    public function testTheCreditmemoIdIsCastToAnInteger()
    {
        $this->reader(['entity_id' => '77'])->read('77 OR 1=1');

        $this->assertSame([['entity_id = ?', 77]], $this->captured['where']);
    }

    public function testTheStoredColumnsAreReturnedVerbatim()
    {
        $row = [
            'entity_id' => '77',
            'increment_id' => '000000077',
            'base_grand_total' => '24.0000',
            'grand_total' => '24.0000',
        ];

        $this->assertSame($row, $this->reader($row)->read(77));
    }

    public function testAMissingRowIsReportedAsNull()
    {
        $this->assertNull($this->reader(false)->read(4242));
    }
}
