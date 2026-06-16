<?php
declare(strict_types=1);

namespace Zwernemann\Withdrawal\Block\Adminhtml\Withdrawal;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Zwernemann\Withdrawal\Api\WithdrawalRepositoryInterface;
use Zwernemann\Withdrawal\Model\Withdrawal;

class View extends Template
{
    protected WithdrawalRepositoryInterface $withdrawalRepository;
    protected OrderRepositoryInterface $orderRepository;
    protected ?Withdrawal $withdrawal = null;
    protected ?OrderInterface $order = null;
    protected ?array $items = null;

    public function __construct(
        Context $context,
        WithdrawalRepositoryInterface $withdrawalRepository,
        OrderRepositoryInterface $orderRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->withdrawalRepository = $withdrawalRepository;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Get the current withdrawal entity
     */
    public function getWithdrawal(): ?Withdrawal
    {
        if ($this->withdrawal === null) {
            $id = (int) $this->getRequest()->getParam('id');
            if ($id) {
                try {
                    $this->withdrawal = $this->withdrawalRepository->getById($id);
                } catch (NoSuchEntityException $e) {
                    return null;
                }
            }
        }
        return $this->withdrawal;
    }

    /**
     * Get withdrawal items
     */
    public function getWithdrawalItems(): array
    {
        if ($this->items === null) {
            $withdrawal = $this->getWithdrawal();
            if ($withdrawal) {
                $this->items = $this->withdrawalRepository->getItemsByWithdrawalId(
                    (int) $withdrawal->getId()
                );
            } else {
                $this->items = [];
            }
        }
        return $this->items;
    }

    /**
     * Get the related order
     */
    public function getOrder(): ?OrderInterface
    {
        if ($this->order === null) {
            $withdrawal = $this->getWithdrawal();
            if ($withdrawal && $withdrawal->getData('order_id')) {
                try {
                    $this->order = $this->orderRepository->get(
                        (int) $withdrawal->getData('order_id')
                    );
                } catch (NoSuchEntityException $e) {
                    return null;
                }
            }
        }
        return $this->order;
    }

    /**
     * Get URL to view the order in admin
     */
    public function getOrderViewUrl(): string
    {
        $order = $this->getOrder();
        if ($order) {
            return $this->getUrl('sales/order/view', ['order_id' => $order->getEntityId()]);
        }
        return '';
    }

    /**
     * Get formatted status label
     */
    public function getStatusLabel(string $status): string
    {
        $labels = [
            'pending' => __('Pending'),
            'confirmed' => __('Confirmed'),
            'rejected' => __('Rejected'),
        ];
        return (string) ($labels[$status] ?? $status);
    }

    /**
     * Get withdrawal type label
     */
    public function getWithdrawalTypeLabel(): string
    {
        $withdrawal = $this->getWithdrawal();
        if ($withdrawal && $withdrawal->getData('is_partial')) {
            return (string) __('Partial Withdrawal');
        }
        return (string) __('Full Withdrawal');
    }

    /**
     * Get back URL to withdrawal list
     */
    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/');
    }

    /**
     * Get confirm URL
     */
    public function getConfirmUrl(): string
    {
        $withdrawal = $this->getWithdrawal();
        if ($withdrawal) {
            return $this->getUrl('withdrawal/index/updatestatus', [
                'id' => $withdrawal->getId(),
                'status' => 'confirmed'
            ]);
        }
        return '';
    }

    /**
     * Get reject URL
     */
    public function getRejectUrl(): string
    {
        $withdrawal = $this->getWithdrawal();
        if ($withdrawal) {
            return $this->getUrl('withdrawal/index/updatestatus', [
                'id' => $withdrawal->getId(),
                'status' => 'rejected'
            ]);
        }
        return '';
    }

    /**
     * Check if confirm button should be shown
     */
    public function canConfirm(): bool
    {
        $withdrawal = $this->getWithdrawal();
        return $withdrawal && $withdrawal->getData('status') !== 'confirmed';
    }

    /**
     * Check if reject button should be shown
     */
    public function canReject(): bool
    {
        $withdrawal = $this->getWithdrawal();
        return $withdrawal && $withdrawal->getData('status') !== 'rejected';
    }
}

