<?php
declare(strict_types=1);

namespace Zwernemann\Withdrawal\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Zwernemann\Withdrawal\Api\WithdrawalRepositoryInterface;
use Zwernemann\Withdrawal\Model\ResourceModel\Withdrawal as WithdrawalResource;
use Zwernemann\Withdrawal\Model\ResourceModel\Withdrawal\CollectionFactory;
use Zwernemann\Withdrawal\Model\WithdrawalFactory;

class WithdrawalRepository implements WithdrawalRepositoryInterface
{
    protected $resource;
    protected $withdrawalFactory;
    protected $collectionFactory;
    protected $orderRepository;
    protected $configHelper;
    protected $resourceConnection;

    public function __construct(
        WithdrawalResource       $resource,
        WithdrawalFactory        $withdrawalFactory,
        CollectionFactory        $collectionFactory,
        OrderRepositoryInterface $orderRepository,
        ResourceConnection       $resourceConnection
    )
    {
        $this->resource = $resource;
        $this->withdrawalFactory = $withdrawalFactory;
        $this->collectionFactory = $collectionFactory;
        $this->orderRepository = $orderRepository;
        $this->resourceConnection = $resourceConnection;
    }

    public function setConfigHelper($configHelper)
    {
        $this->configHelper = $configHelper;
    }

    public function getList()
    {
        $collection = $this->collectionFactory->create();
        $collection->setOrder('created_at', 'DESC');
        return $collection->getData();
    }

    public function create($orderId, $comment = null)
    {
        $withdrawal = $this->withdrawalFactory->create();
        $withdrawal->setData('order_id', $orderId);
        $withdrawal->setData('comment', $comment);
        $this->resource->save($withdrawal);
        return $withdrawal;
    }

    /**
     * Get all withdrawals by order ID (returns array with one or zero elements for single-entry approach)
     */
    public function getAllWithdrawalsByOrderId(int $orderId): array
    {
        $withdrawal = $this->getByOrderId($orderId);
        return $withdrawal ? [$withdrawal] : [];
    }

    public function getByOrderId(int $orderId): ?Withdrawal
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        if ($item && $item->getId()) {
            return $item;
        }
        return null;
    }

    /**
     * Save items for a withdrawal request.
     *
     * @param int $withdrawalId
     * @param array $items Each entry: ['order_item_id' => int, 'name' => string, 'sku' => string, 'qty' => float]
     */
    public function saveWithdrawalItems(int $withdrawalId, array $items): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('zwernemann_withdrawal_items');

        foreach ($items as $item) {
            $connection->insert($tableName, [
                'withdrawal_id' => $withdrawalId,
                'order_item_id' => (int)$item['order_item_id'],
                'order_item_name' => $item['name'] ?? null,
                'order_item_sku' => $item['sku'] ?? null,
                'qty_withdrawn' => (float)($item['qty'] ?? 1),
            ]);
        }
    }

    /**
     * Returns items stored for a given withdrawal record.
     *
     * @return array
     */
    public function getItemsByWithdrawalId(int $withdrawalId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('zwernemann_withdrawal_items');

        $select = $connection->select()
            ->from($tableName)
            ->where('withdrawal_id = ?', $withdrawalId);

        return $connection->fetchAll($select);
    }

    public function hasWithdrawal(int $orderId): bool
    {
        $withdrawal = $this->getByOrderId($orderId);

        if (!$withdrawal) {
            return false;
        }

        // Check if all withdrawable items have been withdrawn
        if ($this->configHelper) {
            try {
                $order = $this->orderRepository->get($orderId);
                $withdrawnItemIds = $this->getWithdrawnItemIds($orderId);

                // Get withdrawable items EXCLUDING already withdrawn ones
                $withdrawableItems = $this->configHelper->getWithdrawableItems($order, $withdrawnItemIds);

                // If there are still withdrawable items left, return false
                if (!empty($withdrawableItems)) {
                    return false; // Still items available to withdraw
                }

                return true; // All withdrawable items withdrawn
            } catch (\Exception $e) {
                // Fallback to old logic
                return true;
            }
        }

        return true;
    }

    /**
     * Get all withdrawn item IDs for an order (alias for getWithdrawnOrderItemIds)
     */
    public function getWithdrawnItemIds(int $orderId): array
    {
        return $this->getWithdrawnOrderItemIds($orderId);
    }

    /**
     * Returns all order_item_ids that have already been included in any withdrawal for this order.
     *
     * @return int[]
     */
    public function getWithdrawnOrderItemIds(int $orderId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $withdrawalTable = $this->resourceConnection->getTableName('zwernemann_withdrawal');
        $itemsTable = $this->resourceConnection->getTableName('zwernemann_withdrawal_items');

        $select = $connection->select()
            ->from(['wi' => $itemsTable], ['wi.order_item_id'])
            ->join(['w' => $withdrawalTable], 'w.entity_id = wi.withdrawal_id', [])
            ->where('w.order_id = ?', $orderId);

        $result = $connection->fetchCol($select);
        return array_map('intval', $result);
    }

    public function updateStatus(int $entityId, string $status): void
    {
        $withdrawal = $this->getById($entityId);
        $withdrawal->setData('status', $status);
        $this->resource->save($withdrawal);
    }

    public function getById(int $entityId): Withdrawal
    {
        $withdrawal = $this->withdrawalFactory->create();
        $this->resource->load($withdrawal, $entityId);
        if (!$withdrawal->getId()) {
            throw new NoSuchEntityException(__('Withdrawal with ID "%1" does not exist.', $entityId));
        }
        return $withdrawal;
    }
}

