<?php
declare(strict_types=1);

namespace Zwernemann\Withdrawal\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Zwernemann\Withdrawal\Api\WithdrawalRepositoryInterface;

class View extends Action
{
    const ADMIN_RESOURCE = 'Zwernemann_Withdrawal::withdrawals';

    protected PageFactory $resultPageFactory;
    protected WithdrawalRepositoryInterface $withdrawalRepository;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        WithdrawalRepositoryInterface $withdrawalRepository
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->withdrawalRepository = $withdrawalRepository;
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');

        if (!$id) {
            $this->messageManager->addErrorMessage(__('Invalid withdrawal ID.'));
            $resultRedirect = $this->resultRedirectFactory->create();
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $withdrawal = $this->withdrawalRepository->getById($id);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('This withdrawal no longer exists.'));
            $resultRedirect = $this->resultRedirectFactory->create();
            return $resultRedirect->setPath('*/*/');
        }

        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Zwernemann_Withdrawal::withdrawals');
        $resultPage->getConfig()->getTitle()->prepend(__('Withdrawal Details'));
        $resultPage->getConfig()->getTitle()->prepend(
            __('Withdrawal #%1', $withdrawal->getId())
        );


        return $resultPage;
    }
}

