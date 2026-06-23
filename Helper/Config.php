<?php
declare(strict_types=1);

namespace Zwernemann\Withdrawal\Helper;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Config extends AbstractHelper
{
    const XML_PATH_ENABLED = 'zwernemann_withdrawal/general/enabled';
    const XML_PATH_NOTIFICATION_EMAIL = 'zwernemann_withdrawal/general/email';
    const XML_PATH_WITHDRAWAL_PERIOD = 'zwernemann_withdrawal/general/withdrawal_period';
    const XML_PATH_EMAIL_TEMPLATE_CUSTOMER = 'zwernemann_withdrawal/email/customer_template';
    const XML_PATH_EMAIL_TEMPLATE_ADMIN = 'zwernemann_withdrawal/email/admin_template';
    const XML_PATH_EMAIL_SENDER = 'zwernemann_withdrawal/email/sender';
    const XML_PATH_ALLOWED_ORDER_STATUSES = 'zwernemann_withdrawal/general/allowed_order_statuses';
    const XML_PATH_EXCLUDED_ATTRIBUTES = 'zwernemann_withdrawal/general/excluded_product_attributes';

    private ShipmentCollectionFactory $shipmentCollectionFactory;
    private ProductRepositoryInterface $productRepository;
    private ProductCollectionFactory $productCollectionFactory;
    private LoggerInterface $logger;
    private $withdrawalRepository;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        ShipmentCollectionFactory             $shipmentCollectionFactory,
        ProductRepositoryInterface            $productRepository,
        ProductCollectionFactory              $productCollectionFactory,
        LoggerInterface                       $logger
    )
    {
        parent::__construct($context);
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->productRepository = $productRepository;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->logger = $logger;
    }

    public function setWithdrawalRepository($withdrawalRepository)
    {
        $this->withdrawalRepository = $withdrawalRepository;
    }

    public function getNotificationEmail($storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_NOTIFICATION_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getCustomerEmailTemplate($storeId = null): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_EMAIL_TEMPLATE_CUSTOMER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value ?: 'zwernemann_withdrawal_email_customer_template';
    }

    public function getAdminEmailTemplate($storeId = null): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_EMAIL_TEMPLATE_ADMIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value ?: 'zwernemann_withdrawal_email_admin_template';
    }

    public function getEmailSender($storeId = null): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_EMAIL_SENDER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value ?: 'general';
    }

    public function getNonWithdrawableItems(\Magento\Sales\Api\Data\OrderInterface $order): array
    {
        $excludedAttributes = $this->getExcludedProductAttributes();

        if (empty($excludedAttributes)) {
            return [];
        }

        $nonWithdrawableItems = [];
        $orderItems = $order->getAllVisibleItems();

        foreach ($orderItems as $item) {
            if (!$this->isItemWithdrawable($item)) {
                $nonWithdrawableItems[] = $item;
            }
        }

        return $nonWithdrawableItems;
    }

    public function getExcludedProductAttributes($storeId = null): array
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_EXCLUDED_ATTRIBUTES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (empty($value)) {
            return [];
        }

        $attributes = explode(',', $value);
        return array_map('trim', array_filter($attributes));
    }

    public function isItemWithdrawable(\Magento\Sales\Api\Data\OrderItemInterface $item): bool
    {
        $excludedAttributes = $this->getExcludedProductAttributes();

        if (empty($excludedAttributes)) {
            return true;
        }

        try {
            // For configurable products, check the simple product attributes
            $productToCheck = null;
            $isConfigurable = false;
            $checkedProductId = (int)$item->getProductId();

            if ($item->getProductType() === \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE) {
                $isConfigurable = true;
                $childItems = $item->getChildrenItems();
                if (!empty($childItems)) {
                    $childItem = reset($childItems); // Get first child item
                    $checkedProductId = (int)$childItem->getProductId();
                    $productToCheck = $this->productRepository->getById($checkedProductId);
                }
            }

            // Fall back to the main product if no child found
            if (!$productToCheck) {
                $productToCheck = $this->productRepository->getById($checkedProductId);
            }

            return $this->checkProductWithdrawability(
                $productToCheck,
                $excludedAttributes,
                (int)$item->getId(),
                (int)$item->getProductId(),
                $checkedProductId,
                $isConfigurable
            );
        } catch (\Exception $e) {
            $this->logger->debug('Withdrawal product load failed', [
                'item_id' => $item->getId(),
                'parent_product_id' => $item->getProductId(),
                'checked_product_id' => $checkedProductId ?? $item->getProductId(),
                'is_configurable' => $isConfigurable ?? false,
                'error' => $e->getMessage()
            ]);
            return true; // If product can't be loaded, allow withdrawal
        }
    }

    /**
     * Check if a product passes withdrawal attribute checks.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $excludedAttributes
     * @param int|null $itemId
     * @param int|null $parentProductId
     * @param int|null $checkedProductId
     * @param bool $isConfigurable
     * @return bool
     */
    private function checkProductWithdrawability(
        \Magento\Catalog\Api\Data\ProductInterface $product,
        array                                      $excludedAttributes,
        ?int                                       $itemId = null,
        ?int                                       $parentProductId = null,
        ?int                                       $checkedProductId = null,
        bool                                       $isConfigurable = false
    ): bool
    {
        foreach ($excludedAttributes as $attributeCode) {
            try {
                $attributeValue = $product->getData($attributeCode);

                // Check if attribute is set to true/1/Yes
                if ($attributeValue === true || $attributeValue === 1 || $attributeValue === '1' || strtolower((string)$attributeValue) === 'yes') {
                    return false;
                }
            } catch (\Exception $e) {
                $this->logger->debug('Withdrawal attribute check failed', [
                    'item_id' => $itemId,
                    'parent_product_id' => $parentProductId,
                    'checked_product_id' => $checkedProductId ?? $product->getId(),
                    'is_configurable' => $isConfigurable,
                    'attribute' => $attributeCode,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return true;
    }

    /**
     * Get item IDs that have been partially or fully withdrawn.
     *
     * @param int $orderId
     * @return int[]
     */
    public function getAlreadyWithdrawnItemIds(int $orderId): array
    {
        if (!$this->withdrawalRepository) {
            return [];
        }

        return $this->withdrawalRepository->getWithdrawnItemIds($orderId);
    }

    /**
     * Get withdrawn quantities for all items in an order.
     *
     * @param int $orderId
     * @return array [order_item_id => qty_withdrawn]
     */
    public function getWithdrawnQuantities(int $orderId): array
    {
        if (!$this->withdrawalRepository) {
            return [];
        }

        return $this->withdrawalRepository->getWithdrawnQuantitiesByOrderId($orderId);
    }

    public function isWithdrawalAllowed(\Magento\Sales\Api\Data\OrderInterface $order): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $allowedStatuses = $this->getAllowedOrderStatuses();
        if (!empty($allowedStatuses) && !in_array($order->getStatus(), $allowedStatuses)) {
            return false;
        }


        $shipmentDate = $this->getLatestShipmentDate($order);

        if ($shipmentDate === null) {
            // Not yet shipped: check if there are withdrawable items
            $withdrawable = $this->getWithdrawableItems($order, []);
            return count($withdrawable) > 0;
        }

        $now = new \DateTime();
        $diff = $now->diff($shipmentDate);
        $daysDiff = (int)$diff->days;

        if ($daysDiff > $this->getWithdrawalPeriodDays()) {
            return false;
        }

        // Check if there are still withdrawable items
        $withdrawable = $this->getWithdrawableItems($order, []);
        return count($withdrawable) > 0;
    }

    public function isEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getAllowedOrderStatuses($storeId = null): array
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_ALLOWED_ORDER_STATUSES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value ? explode(',', $value) : [];
    }

    private function getLatestShipmentDate(\Magento\Sales\Api\Data\OrderInterface $order): ?\DateTime
    {
        $collection = $this->shipmentCollectionFactory->create();
        $collection->setOrderFilter($order->getEntityId());
        $collection->setOrder('created_at', 'DESC');
        $collection->setPageSize(1);

        $shipment = $collection->getFirstItem();

        if ($shipment && $shipment->getId()) {
            return new \DateTime($shipment->getCreatedAt());
        }

        return null;
    }

    public function getWithdrawableItems(\Magento\Sales\Api\Data\OrderInterface $order, array $excludedItemIds = []): array
    {
        $excludedAttributes = $this->getExcludedProductAttributes();
        $withdrawableItems = [];

        // Get all visible items
        $orderItems = $order->getAllVisibleItems();

        // Get withdrawn quantities for quantity-based filtering
        $withdrawnQtys = [];
        if ($this->withdrawalRepository) {
            $withdrawnQtys = $this->withdrawalRepository->getWithdrawnQuantitiesByOrderId((int)$order->getEntityId());
        }

        if (empty($excludedAttributes)) {
            // No exclusions, just filter by excluded IDs and remaining quantity
            foreach ($orderItems as $item) {
                $itemId = (int)$item->getId();

                // Skip if explicitly excluded
                if (in_array($itemId, $excludedItemIds)) {
                    continue;
                }

                // Check if item has remaining quantity
                $orderedQty = (float)$item->getQtyOrdered();
                $withdrawnQty = $withdrawnQtys[$itemId] ?? 0;

                if ($withdrawnQty < $orderedQty) {
                    $withdrawableItems[] = $item;
                }
            }
            return $withdrawableItems;
        }

        // Build product IDs array for collection
        // For configurable products, we need to include the simple product IDs
        $productIds = [];
        foreach ($orderItems as $item) {
            $productIds[] = $item->getProductId();

            // Add simple product IDs for configurable products
            if ($item->getProductType() === \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE) {
                $childItems = $item->getChildrenItems();
                if (!empty($childItems)) {
                    foreach ($childItems as $childItem) {
                        $productIds[] = $childItem->getProductId();
                    }
                }
            }
        }

        if (empty($productIds)) {
            return [];
        }

        // Load product collection with attributes for performance
        $productCollection = $this->productCollectionFactory->create();
        $productCollection->addIdFilter(array_unique($productIds));
        $productCollection->addAttributeToSelect($excludedAttributes);

        $products = [];
        foreach ($productCollection as $product) {
            $products[$product->getId()] = $product;
        }

        // Filter items using pre-loaded products
        foreach ($orderItems as $item) {
            $itemId = (int)$item->getId();

            // Skip if explicitly excluded
            if (in_array($itemId, $excludedItemIds)) {
                continue;
            }

            // Check if item has remaining quantity
            $orderedQty = (float)$item->getQtyOrdered();
            $withdrawnQty = $withdrawnQtys[$itemId] ?? 0;

            if ($withdrawnQty >= $orderedQty) {
                continue; // Already fully withdrawn
            }

            // Determine which product to check for exclusion attributes
            $productToCheck = null;
            $isConfigurable = false;
            $checkedProductId = (int)$item->getProductId();

            if ($item->getProductType() === \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE) {
                $isConfigurable = true;
                $childItems = $item->getChildrenItems();
                if (!empty($childItems)) {
                    $childItem = reset($childItems);
                    $checkedProductId = (int)$childItem->getProductId();
                    $productToCheck = $products[$checkedProductId] ?? null;
                }
            }

            // Fall back to parent product if child not found in collection
            if (!$productToCheck) {
                $productToCheck = $products[$checkedProductId] ?? null;
            }

            // If product not in collection (shouldn't happen), skip to be safe
            if (!$productToCheck) {
                $this->logger->warning('Product not found in pre-loaded collection', [
                    'item_id' => $itemId,
                    'checked_product_id' => $checkedProductId
                ]);
                continue;
            }

            // Check if product is withdrawable using pre-loaded product
            if ($this->checkProductWithdrawability(
                $productToCheck,
                $excludedAttributes,
                $itemId,
                (int)$item->getProductId(),
                $checkedProductId,
                $isConfigurable
            )) {
                $withdrawableItems[] = $item;
            }
        }

        return $withdrawableItems;
    }

    public function getWithdrawalPeriodDays($storeId = null): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_WITHDRAWAL_PERIOD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value ? (int)$value : 14;
    }

    public function getWithdrawalDeadline(\Magento\Sales\Api\Data\OrderInterface $order): string
    {
        $shipmentDate = $this->getLatestShipmentDate($order);

        if ($shipmentDate === null) {
            return '';
        }

        $shipmentDate->modify('+' . $this->getWithdrawalPeriodDays() . ' days');
        return $shipmentDate->format('d.m.Y');
    }
}