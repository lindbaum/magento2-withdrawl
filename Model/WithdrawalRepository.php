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
        if ($item instanceof Withdrawal && $item->getId()) {
            return $item;
        }
        return null;
    }

    /**
     * Save items for a withdrawal request.
     * If an item already exists for this withdrawal, increment qty_withdrawn instead of inserting a duplicate.
     *
     * @param int $withdrawalId
     * @param array $items Each entry: ['order_item_id' => int, 'name' => string, 'sku' => string, 'qty' => float]
     */
    public function saveWithdrawalItems(int $withdrawalId, array $items): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('zwernemann_withdrawal_items');

        foreach ($items as $item) {
            $orderItemId = (int)$item['order_item_id'];
            $qtyToAdd = (float)($item['qty'] ?? 1);

            // Check if this item already exists for this withdrawal
            $select = $connection->select()
                ->from($tableName, ['entity_id', 'qty_withdrawn'])
                ->where('withdrawal_id = ?', $withdrawalId)
                ->where('order_item_id = ?', $orderItemId);

            $existingItem = $connection->fetchRow($select);

            if ($existingItem) {
                // Update existing entry by incrementing qty_withdrawn
                $connection->update(
                    $tableName,
                    ['qty_withdrawn' => new \Zend_Db_Expr('qty_withdrawn + ' . $qtyToAdd)],
                    ['entity_id = ?' => $existingItem['entity_id']]
                );
            } else {
                // Insert new entry
                $connection->insert($tableName, [
                    'withdrawal_id' => $withdrawalId,
                    'order_item_id' => $orderItemId,
                    'order_item_name' => $item['name'] ?? null,
                    'order_item_sku' => $item['sku'] ?? null,
                    'qty_withdrawn' => $qtyToAdd,
                ]);
            }
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

        // Check if all withdrawable items have been FULLY withdrawn (based on quantities)
        if ($this->configHelper) {
            try {
                $order = $this->orderRepository->get($orderId);

                // Get all withdrawable items (no exclusions - we want ALL withdrawable items)
                $withdrawableItems = $this->configHelper->getWithdrawableItems($order, []);

                if (empty($withdrawableItems)) {
                    return true; // No withdrawable items means nothing left to withdraw
                }

                // Get withdrawn quantities
                $withdrawnQuantities = $this->getWithdrawnQuantitiesByOrderId($orderId);

                // Check if all withdrawable items are FULLY withdrawn (100% of quantity)
                foreach ($withdrawableItems as $item) {
                    $itemId = (int)$item->getId();
                    $orderedQty = (float)$item->getQtyOrdered();
                    $withdrawnQty = $withdrawnQuantities[$itemId] ?? 0;

                    // If any item still has remaining quantity, return false
                    if (($orderedQty - $withdrawnQty) > 0.0001) {
                        return false; // Still quantity available to withdraw
                    }
                }

                return true; // All withdrawable items fully withdrawn
            } catch (\Exception $e) {
                // Fallback: check is_partial flag
                return $withdrawal->getData('is_partial') == 0;
            }
        }

        return true;
    }

    /**
     * Get withdrawn quantities by order ID.
     * Returns array mapping order_item_id to total withdrawn quantity.
     *
     * @param int $orderId
     * @return array [order_item_id => total_qty_withdrawn]
     */
    public function getWithdrawnQuantitiesByOrderId(int $orderId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $withdrawalTable = $this->resourceConnection->getTableName('zwernemann_withdrawal');
        $itemsTable = $this->resourceConnection->getTableName('zwernemann_withdrawal_items');

        $select = $connection->select()
            ->from(['wi' => $itemsTable], [
                'order_item_id' => 'wi.order_item_id',
                'total_qty' => new \Zend_Db_Expr('SUM(wi.qty_withdrawn)')
            ])
            ->join(['w' => $withdrawalTable], 'w.entity_id = wi.withdrawal_id', [])
            ->where('w.order_id = ?', $orderId)
            ->group('wi.order_item_id');

        $result = $connection->fetchPairs($select);
        return array_map('floatval', $result);
    }

    /**
     * Get all withdrawn item IDs for an order (alias for getWithdrawnOrderItemIds)
     */
    public function getWithdrawnItemIds(int $orderId): array
    {
        return $this->getWithdrawnOrderItemIds($orderId);
    }

    /**
     * Returns all order_item_ids that have been included in any withdrawal for this order.
     * Note: This returns items that have ANY quantity withdrawn (partial or full).
     * Use getFullyWithdrawnItemIds() to get only items with no remaining quantity.
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
            ->where('w.order_id = ?', $orderId)
            ->group('wi.order_item_id');

        $result = $connection->fetchCol($select);
        return array_map('intval', $result);
    }

    /**
     * Get fully withdrawn item IDs (where withdrawn qty >= ordered qty).
     * Requires order to determine ordered quantities.
     *
     * @param int $orderId
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return int[]
     */
    public function getFullyWithdrawnItemIds(int $orderId, \Magento\Sales\Api\Data\OrderInterface $order): array
    {
        $withdrawnQtys = $this->getWithdrawnQuantitiesByOrderId($orderId);
        $fullyWithdrawn = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $itemId = (int)$item->getId();
            $orderedQty = (float)$item->getQtyOrdered();
            $withdrawnQty = $withdrawnQtys[$itemId] ?? 0;

            if ($withdrawnQty >= $orderedQty) {
                $fullyWithdrawn[] = $itemId;
            }
        }

        return $fullyWithdrawn;
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

