<?php
declare(strict_types=1);

namespace Zwernemann\Withdrawal\Controller\Index;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Zwernemann\Withdrawal\Helper\Config;
use Zwernemann\Withdrawal\Model\Email\Sender as EmailSender;
use Zwernemann\Withdrawal\Model\WithdrawalRepository;

class Submit implements HttpPostActionInterface
{
    protected $request;
    protected $redirectFactory;
    protected $messageManager;
    protected $orderRepository;
    protected $dateTime;
    protected $timezone;
    protected $customerSession;
    protected $config;
    protected $withdrawalRepository;
    protected $emailSender;
    protected $resource;
    protected $formKeyValidator;

    public function __construct(
        RequestInterface         $request,
        RedirectFactory          $redirectFactory,
        ManagerInterface         $messageManager,
        OrderRepositoryInterface $orderRepository,
        DateTime                 $dateTime,
        TimezoneInterface        $timezone,
        CustomerSession          $customerSession,
        Config                   $config,
        WithdrawalRepository     $withdrawalRepository,
        EmailSender              $emailSender,
        ResourceConnection       $resource,
        FormKeyValidator         $formKeyValidator
    )
    {
        $this->request = $request;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
        $this->orderRepository = $orderRepository;
        $this->dateTime = $dateTime;
        $this->timezone = $timezone;
        $this->customerSession = $customerSession;
        $this->config = $config;
        $this->withdrawalRepository = $withdrawalRepository;
        $this->emailSender = $emailSender;
        $this->resource = $resource;
        $this->formKeyValidator = $formKeyValidator;
    }

    public function execute()
    {
        $redirect = $this->redirectFactory->create();

        if (!$this->formKeyValidator->validate($this->request)) {
            $this->messageManager->addErrorMessage(__('Invalid form key. Please try again.'));
            return $redirect->setPath('sales/order/history');
        }

        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('The withdrawal function is currently not available.'));
            return $redirect->setPath('sales/order/history');
        }

        $orderId = (int)$this->request->getParam('order_id');
        $isGuest = (bool)$this->request->getParam('guest');
        $guestEmail = $this->request->getParam('guest_email');

        if (!$orderId) {
            $this->messageManager->addErrorMessage(__('No order specified.'));
            return $redirect->setPath('sales/order/history');
        }

        try {
            $order = $this->orderRepository->get($orderId);

            // Validate access: either logged-in customer owns order, or guest email matches
            if (!$isGuest) {
                if (!$this->customerSession->isLoggedIn()) {
                    $this->messageManager->addErrorMessage(__('Please log in to submit a withdrawal.'));
                    return $redirect->setPath('customer/account/login');
                }
                $customerId = $this->customerSession->getCustomerId();
                if ((int)$order->getCustomerId() !== (int)$customerId) {
                    $this->messageManager->addErrorMessage(__('You are not authorized to withdraw this order.'));
                    return $redirect->setPath('sales/order/history');
                }
            } else {
                if (!$guestEmail || strtolower($guestEmail) !== strtolower($order->getCustomerEmail())) {
                    $this->messageManager->addErrorMessage(__('The provided email does not match the order.'));
                    return $redirect->setPath('withdrawal/guest/search');
                }
            }

            // Check if within withdrawal period
            if (!$this->config->isWithdrawalAllowed($order)) {
                $this->messageManager->addErrorMessage(
                    __('The withdrawal period for this order has expired.')
                );
                if ($isGuest) {
                    return $redirect->setPath('withdrawal/guest/search');
                }
                return $redirect->setPath('sales/order/history');
            }

            // Get withdrawable items
            // NOTE: We don't pass excludedItemIds anymore because getWithdrawableItems()
            // now checks remaining quantities internally
            $withdrawableItems = $this->config->getWithdrawableItems($order, []);

            if (empty($withdrawableItems)) {
                $this->messageManager->addErrorMessage(
                    __('All items in this order have already been withdrawn.')
                );
                if ($isGuest) {
                    return $redirect->setPath('withdrawal/guest/search');
                }
                return $redirect->setPath('sales/order/history');
            }

            // Get selected item IDs from request
            $selectedItemIds = $this->request->getParam('withdrawal_items', []);
            if (!is_array($selectedItemIds)) {
                $selectedItemIds = [];
            }
            $selectedItemIds = array_map('intval', $selectedItemIds);

            // Get requested quantities from request
            $requestedQuantities = $this->request->getParam('withdrawal_qty', []);
            if (!is_array($requestedQuantities)) {
                $requestedQuantities = [];
            }

            // Validate that selected items are actually withdrawable
            $withdrawableItemIds = array_map(function ($item) {
                return (int)$item->getId();
            }, $withdrawableItems);

            // Filter: only keep selected items that are also withdrawable
            $withdrawableItemIds = array_intersect($selectedItemIds, $withdrawableItemIds);

            if (empty($withdrawableItemIds)) {
                $this->messageManager->addErrorMessage(
                    __('Please select at least one item to withdraw.')
                );
                if ($isGuest) {
                    return $redirect->setPath('withdrawal/index/view', ['order_id' => $orderId, 'guest' => 1]);
                }
                return $redirect->setPath('withdrawal/index/view', ['order_id' => $orderId]);
            }

            // Get withdrawn quantities to calculate available quantities
            $withdrawnQuantities = $this->config->getWithdrawnQuantities($orderId);

            // Filter withdrawableItems to only include selected items
            $selectedWithdrawableItems = array_filter($withdrawableItems, function ($item) use ($withdrawableItemIds) {
                return in_array((int)$item->getId(), $withdrawableItemIds, true);
            });

            // Validate requested quantities and prepare items data
            $itemsData = [];
            foreach ($selectedWithdrawableItems as $item) {
                $itemId = (int)$item->getId();
                $orderedQty = (float)$item->getQtyOrdered();
                $withdrawnQty = $withdrawnQuantities[$itemId] ?? 0;
                $availableQty = $orderedQty - $withdrawnQty;

                // Get requested quantity from form
                $requestedQty = isset($requestedQuantities[$itemId]) ? (float)$requestedQuantities[$itemId] : $availableQty;

                // Validate quantity
                if ($requestedQty <= 0) {
                    $this->messageManager->addErrorMessage(
                        __('Invalid quantity for item "%1". Quantity must be greater than 0.', $item->getName())
                    );
                    return $redirect->setPath('withdrawal/index/view', ['order_id' => $orderId]);
                }

                if ($requestedQty > $availableQty) {
                    $this->messageManager->addErrorMessage(
                        __('Requested quantity for item "%1" exceeds available quantity. Available: %2, Requested: %3',
                            $item->getName(),
                            $availableQty,
                            $requestedQty
                        )
                    );
                    return $redirect->setPath('withdrawal/index/view', ['order_id' => $orderId]);
                }

                $itemsData[] = [
                    'order_item_id' => $itemId,
                    'name' => $item->getName(),
                    'sku' => $item->getSku(),
                    'qty' => $requestedQty
                ];
            }

            // Get non-withdrawable items for email
            $nonWithdrawableItems = $this->config->getNonWithdrawableItems($order);
            $totalVisibleItems = count($order->getAllVisibleItems());

            // Build customer name
            $customerName = trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname());
            if (!$customerName || $customerName === ' ') {
                $billingAddress = $order->getBillingAddress();
                if ($billingAddress) {
                    $customerName = trim($billingAddress->getFirstname() . ' ' . $billingAddress->getLastname());
                }
            }

            // Check if withdrawal already exists for this order
            $existingWithdrawal = $this->withdrawalRepository->getByOrderId($orderId);
            $connection = $this->resource->getConnection();

            if ($existingWithdrawal) {
                // UPDATE existing withdrawal - add new items
                $existingItemIds = $this->withdrawalRepository->getWithdrawnOrderItemIds($orderId);
                $mergedItemIds = array_unique(array_merge($existingItemIds, $withdrawableItemIds));

                // Save new items first to update withdrawn quantities
                $this->withdrawalRepository->saveWithdrawalItems((int) $existingWithdrawal->getId(), $itemsData);

                // Determine if this completes the withdrawal based on quantities
                // Get updated withdrawn quantities after saving new items
                $updatedWithdrawnQtys = $this->config->getWithdrawnQuantities($orderId);
                $isPartial = $this->isPartialWithdrawal($order, $updatedWithdrawnQtys);

                $connection->update(
                    'zwernemann_withdrawal',
                    [
                        'is_partial' => $isPartial ? 1 : 0
                    ],
                    ['entity_id = ?' => $existingWithdrawal->getId()]
                );

                $isUpdate = true;
                $previousItemCount = count($existingItemIds);
                $withdrawalType = $isPartial ? 'partial' : 'full';
            } else {
                // CREATE new withdrawal
                // Calculate what withdrawn quantities will be after this submission
                $projectedWithdrawnQtys = [];
                foreach ($itemsData as $itemData) {
                    $itemId = (int)$itemData['order_item_id'];
                    $projectedWithdrawnQtys[$itemId] = ($withdrawnQuantities[$itemId] ?? 0) + (float)$itemData['qty'];
                }

                // Determine if this is a full withdrawal based on quantities
                $isPartial = $this->isPartialWithdrawal($order, $projectedWithdrawnQtys);
                $withdrawalType = $isPartial ? 'partial' : 'full';

                $connection->insert('zwernemann_withdrawal', [
                    'order_id' => $order->getEntityId(),
                    'order_increment_id' => $order->getIncrementId(),
                    'customer_email' => $order->getCustomerEmail(),
                    'customer_name' => $customerName,
                    'status' => 'pending',
                    'order_created_at' => $order->getCreatedAt(),
                    'created_at' => $this->dateTime->gmtDate(),
                    'is_partial' => $isPartial ? 1 : 0
                ]);

                $withdrawalId = (int) $connection->lastInsertId();

                // Save items
                $this->withdrawalRepository->saveWithdrawalItems($withdrawalId, $itemsData);

                $isUpdate = false;
                $previousItemCount = 0;
                $mergedItemIds = $withdrawableItemIds;
            }

            // Add comment to order history
            if ($isUpdate) {
                if ($withdrawalType === 'full') {
                    $commentText = __('Withdrawal updated: Now complete withdrawal of all %1 items on %2',
                        count($mergedItemIds),
                        $this->dateTime->gmtDate()
                    );
                } else {
                    $commentText = __('Withdrawal updated: Additional %1 items withdrawn (total %2 of %3) on %4',
                        count($withdrawableItemIds),
                        count($mergedItemIds),
                        $totalVisibleItems,
                        $this->dateTime->gmtDate()
                    );
                }
            } else {
                if ($withdrawalType === 'partial') {
                    $commentText = __('Partial withdrawal: %1 of %2 items withdrawn on %3',
                        count($withdrawableItemIds),
                        $totalVisibleItems,
                        $this->dateTime->gmtDate()
                    );
                } else {
                    $commentText = __('Full withdrawal submitted on %1', $this->dateTime->gmtDate());
                }
            }

            $order->addCommentToStatusHistory($commentText);
            $this->orderRepository->save($order);

            // Build withdrawn quantities map for email (item_id => qty)
            $itemWithdrawnQuantities = [];
            foreach ($itemsData as $itemData) {
                $itemWithdrawnQuantities[(int)$itemData['order_item_id']] = (float)$itemData['qty'];
            }

            // Send emails
            $templateVars = [
                'order_increment_id' => $order->getIncrementId(),
                'customer_name' => $customerName,
                'customer_email' => $order->getCustomerEmail(),
                'order_date' => $this->timezone->formatDateTime(
                    new \DateTime($order->getCreatedAt()),
                    \IntlDateFormatter::MEDIUM,
                    \IntlDateFormatter::MEDIUM
                ),
                'withdrawal_date' => $this->timezone->formatDateTime(
                    new \DateTime(),
                    \IntlDateFormatter::MEDIUM,
                    \IntlDateFormatter::MEDIUM
                ),
                'withdrawal_type' => $withdrawalType,
                'withdrawn_item_count' => count($mergedItemIds),
                'newly_withdrawn_item_count' => count($withdrawableItemIds),
                'previous_item_count' => $previousItemCount,
                'total_item_count' => $totalVisibleItems,
                'is_update' => $isUpdate ? '1' : '',
                'is_now_complete' => ($withdrawalType === 'full' && $isUpdate) ? '1' : ''
            ];

            $this->emailSender->sendCustomerEmail(
                $templateVars,
                $order->getCustomerEmail(),
                $customerName,
                $selectedWithdrawableItems,
                $nonWithdrawableItems,
                $withdrawalType,
                $isUpdate,
                $itemWithdrawnQuantities
            );
            $this->emailSender->sendAdminEmail(
                $templateVars,
                $selectedWithdrawableItems,
                $nonWithdrawableItems,
                $withdrawalType,
                $isUpdate,
                $itemWithdrawnQuantities
            );

            // Redirect to success page
            return $redirect->setPath('withdrawal/index/success', [
                'order_id' => $orderId,
            ]);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Unable to submit withdrawal request. Please try again.'));
        }

        if ($isGuest) {
            return $redirect->setPath('withdrawal/guest/search');
        }
        return $redirect->setPath('sales/order/history');
    }

    /**
     * Determine if a withdrawal is partial based on quantities.
     *
     * A withdrawal is considered "full" (is_partial = 0) only when ALL withdrawable items
     * will have their ENTIRE ordered quantity withdrawn.
     *
     * A withdrawal is considered "partial" (is_partial = 1) if any withdrawable item
     * still has remaining quantity that hasn't been withdrawn.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $withdrawnQuantities Array of [order_item_id => total_qty_withdrawn]
     * @return bool True if partial (has remaining qty), false if full (all qty withdrawn)
     */
    protected function isPartialWithdrawal(\Magento\Sales\Api\Data\OrderInterface $order, array $withdrawnQuantities): bool
    {
        // Get all withdrawable items (excluding non-withdrawable items by attribute)
        $withdrawableItems = $this->config->getWithdrawableItems($order, []);

        if (empty($withdrawableItems)) {
            // No withdrawable items means nothing can be withdrawn (edge case)
            return false;
        }

        // Check if all withdrawable items are fully withdrawn
        foreach ($withdrawableItems as $item) {
            $itemId = (int)$item->getId();
            $orderedQty = (float)$item->getQtyOrdered();
            $withdrawnQty = $withdrawnQuantities[$itemId] ?? 0;

            // Use epsilon comparison for floating-point safety (precision: 4 decimals as per db_schema.xml)
            $remainingQty = $orderedQty - $withdrawnQty;
            if ($remainingQty > 0.0001) {
                // This item still has quantity remaining to withdraw
                return true; // It's a partial withdrawal
            }
        }

        // All withdrawable items are fully withdrawn
        return false; // It's a full withdrawal
    }
}

