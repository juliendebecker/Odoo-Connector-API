<?php

namespace Emipro\Apichange\Test\Unit\Model\Refund;

use Emipro\Apichange\Model\Refund\IdempotencyStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Only the pure parts are unit tested here. claim(), complete() and fail()
 * depend on a real UNIQUE index doing its job and are covered by the
 * integration test, not by mocks: a mocked connection would happily "prove" a
 * uniqueness guarantee it does not provide.
 */
class IdempotencyStoreTest extends TestCase
{
    /**
     * @var IdempotencyStore
     */
    private $store;

    protected function setUp(): void
    {
        $this->store = new IdempotencyStore(
            $this->createMock(ResourceConnection::class),
            new Json(),
            $this->createMock(DateTime::class)
        );
    }

    public function testValidKeysAreAcceptedAndTrimmed()
    {
        $this->assertSame('odoo-refund-42', $this->store->normalizeKey('  odoo-refund-42  '));
        $this->assertSame(
            str_repeat('k', IdempotencyStore::MAX_KEY_LENGTH),
            $this->store->normalizeKey(str_repeat('k', IdempotencyStore::MAX_KEY_LENGTH))
        );
    }

    public function testEmptyKeyIsRejected()
    {
        $this->expectException(InputException::class);
        $this->store->normalizeKey('   ');
    }

    /**
     * Truncating would merge two distinct refunds under one key, which is the
     * exact failure an idempotency ledger exists to prevent.
     */
    public function testOverlongKeyIsRejectedRatherThanTruncated()
    {
        $this->expectException(InputException::class);
        $this->store->normalizeKey(str_repeat('k', IdempotencyStore::MAX_KEY_LENGTH + 1));
    }

    /**
     * @dataProvider unsafeKeyProvider
     * @param string $key
     */
    public function testKeysWithNonPrintableOrSpaceCharactersAreRejected($key)
    {
        $this->expectException(InputException::class);
        $this->store->normalizeKey($key);
    }

    /**
     * @return array
     */
    public function unsafeKeyProvider()
    {
        return [
            'inner space' => ['odoo refund 42'],
            'newline' => ["odoo\n42"],
            'null byte' => ["odoo\0" . '42'],
            'tab' => ["odoo\t42"],
            'non ascii' => ['odoo-rembôursement'],
        ];
    }

    public function testResponseDecodingIsFailSafe()
    {
        $this->assertNull($this->store->decodeResponse([]));
        $this->assertNull($this->store->decodeResponse(['response_json' => null]));
        $this->assertNull($this->store->decodeResponse(['response_json' => 'not json']));
        $this->assertSame(
            ['creditmemo_id' => 7],
            $this->store->decodeResponse(['response_json' => '{"creditmemo_id":7}'])
        );
    }
}
