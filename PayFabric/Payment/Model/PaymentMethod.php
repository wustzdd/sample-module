<?php

namespace PayFabric\Payment\Model;

use PayFabric\Payment\Helper\Helper;
use PayFabric\Payment\Model\Config\Source\NewOrderPaymentActions;
use PayFabric\Payment\Model\Config\Source\DisplayMode;
use Magento\Framework\DataObject;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\ConfigInterface;
use Magento\Payment\Model\Method\Online\GatewayInterface;
use Magento\Sales\Model\Order\Payment\Transaction;


class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod implements GatewayInterface
{
    const METHOD_CODE = 'payfabric_payment';
    const NOT_AVAILABLE = 'N/A';

    /**
     * @var string
     */
    protected $_code = self::METHOD_CODE;

    /**
     * Payment Method feature.
     *
     * @var bool
     */
    protected $_canAuthorize = false;

    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * @var bool
     */
    protected $_canCapturePartial = true;

    /**
     * @var bool
     */
    protected $_canCaptureOnce = true;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @var bool
     */
    protected $_isGateway = true;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * @var bool
     */
    protected $_canUseInternal = true;

    /**
     * @var bool
     */
    protected $_canVoid = true;


    protected $_canCancelInvoice = true;

    /**
     * @var bool
     */
    protected $_canReviewPayment = false;
    /**
     * @var \Magento\Framework\App\ProductMetadataInterface
     */
    protected $_productMetadata;
    /**
     * @var \Magento\Framework\Module\ResourceInterface
     */
    protected $_resourceInterface;

    /**
     * @var \PayFabric\Payment\Helper\Helper
     */
    private $_helper;
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $_storeManager;
    /**
     * @var \Magento\Framework\UrlInterface
     */
    private $_urlBuilder;
    /**
     * @var \Magento\Framework\Locale\ResolverInterface
     */
    private $_resolver;
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $_ipgLogger;
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $_session;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    private $_customerRepository;

    /**
     * PaymentMethod constructor.
     *
     * @param \Magento\Framework\App\RequestInterface                      $request
     * @param \Magento\Framework\UrlInterface                              $urlBuilder
     * @param Helper                                                       $helper
     * @param \Magento\Store\Model\StoreManagerInterface                   $storeManager
     * @param \Magento\Framework\Locale\ResolverInterface                  $resolver
     * @param \Magento\Framework\Model\Context                             $context
     * @param \Magento\Framework\Registry                                  $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory            $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory                 $customAttributeFactory
     * @param \Magento\Payment\Helper\Data                                 $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface           $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger                         $logger
     * @param \Psr\Log\LoggerInterface                                     $ipgLogger
     * @param \Magento\Framework\App\ProductMetadataInterface              $productMetadata
     * @param \Magento\Framework\Module\ResourceInterface                  $resourceInterface
     * @param \Magento\Checkout\Model\Session                              $session
     * @param \Magento\Customer\Api\CustomerRepositoryInterface            $customerRepository
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null           $resourceCollection
     * @param array                                                        $data
     */
    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\UrlInterface $urlBuilder,
        Helper $helper,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Locale\ResolverInterface $resolver,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Psr\Log\LoggerInterface $ipgLogger,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata,
        \Magento\Framework\Module\ResourceInterface $resourceInterface,
        \Magento\Checkout\Model\Session $session,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->_urlBuilder = $urlBuilder;
        $this->_helper = $helper;
        $this->_storeManager = $storeManager;
        $this->_resolver = $resolver;
        $this->_request = $request;
        $this->_ipgLogger = $ipgLogger;
        $this->_productMetadata = $productMetadata;
        $this->_resourceInterface = $resourceInterface;
        $this->_session = $session;
        $this->_customerRepository = $customerRepository;
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function initialize($paymentAction, $stateObject)
    {
        /*
         * do not send order confirmation mail after order creation wait for
         * result confirmation
         */
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);

        $stateObject->setState(\Magento\Sales\Model\Order::STATE_NEW);
        $stateObject->setStatus($this->_helper->getConfigData('order_status'));
        $stateObject->setIsNotified(false);
    }

    /**
     * Retrieve payment method title
     *
     * @return string
     */
    public function getTitle()
    {
        $title_code = $this->getConfigData('title');
        return $title_code;
    }

    /**
     * Checkout redirect URL.
     *
     * @see \Magento\Checkout\Controller\Onepage::savePaymentAction()
     * @see \Magento\Quote\Model\Quote\Payment::getCheckoutRedirectUrl()
     *
     * @return string
     */
    public function getCheckoutRedirectUrl()
    {
        return $this->_urlBuilder->getUrl('payfabric/hosted/request');
    }

    /**
     * Post request to gateway and return response.
     *
     * @param DataObject      $request
     * @param ConfigInterface $config
     */
    public function postRequest(DataObject $request, ConfigInterface $config)
    {
        // Do nothing
        $this->_helper->logDebug('Gateway postRequest called');
    }

    /**
     * @desc Get form method
     *
     * @return string
     */
    public function getFormMethod()
    {
        return $this->_helper->getFormMethod();
    }

    /**
     * @desc Form fields that will be sent with the request
     *
     * @return array
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getFormFields()
    {
        $paymentAction = $this->_helper->getConfigData('payment_action');
        $sessionTokenData = $this->getTokenHostedData($this->toAPIOperation($paymentAction));
        try {
            $responseToken = $this->_helper->executeGatewayTransaction($sessionTokenData['action'], $sessionTokenData);
        }catch (\Exception $e){
            return  array('status' => 'error', 'message' => $e->getMessage());
        }
        $displayMode = $this->getConfigData('display_mode');
        if ($displayMode === DisplayMode::DISPLAY_MODE_IFRAME) {
            $formFields = array(
                'environment' => $this->_helper->isSandboxMode() ? (stripos(TESTGATEWAY,'DEV-US2')===FALSE ? (stripos(TESTGATEWAY,'QA')===FALSE ? 'SANDBOX' : 'QA') : 'DEV-US2') : 'LIVE',
                'target' => 'cashierDiv',
                'displayMethod' => 'dialog',
                'session' => $responseToken->Token,
                'disableCancel' => true
            );
        } else {
            $formFields = array(
                'token' => $responseToken->Token,
                'successUrl' => $this->getMerchantLandingPageUrl()
            );
        }

        return $formFields;
    }

    private function getTokenHostedData($apiOperation)
    {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();

        // allow origin url
        // to get only the site's domain url name to assign to the parameter allowOriginUrl .
        //otherwise it will encounter a CORS issue when it is deployed inside a subfolder of the web server.
        $url = $this->_urlBuilder->getBaseUrl();
        $parse_result = parse_url($url);
        if(isset($parse_result['port'])){
            $allowOriginUrl = $parse_result['scheme']."://".$parse_result['host'].":".$parse_result['port'];
        }else{
            $allowOriginUrl = $parse_result['scheme']."://".$parse_result['host'];
        }
        // currency code
        $orderCurrencyCode = $order->getBaseCurrencyCode();
        // amount
        $amount = $this->formatAmount($order->getBaseGrandTotal());
        // order id
        $orderId = $order->getRealOrderId();
        if(strlen($orderId) > 50) {
            $orderId = substr($orderId, -50);
        }
        // customer id
        $customerId = $order->getCustomerId();
        if ($customerId == '') {
            $customerId = 'guest_'.$orderId;
        }
        if(strlen($customerId) > 20) {
            $customerId = substr($customerId, -20);
        }

        // merchant notification URL: server-to-server, URL to which the Transaction Result Call will be sent
        $merchantNotificationUrl = $this->_urlBuilder->getUrl($this->_helper->getNotificationRoute($order->getRealOrderId()));
        // The URL to which the customer’s browser is redirected after the payment
        $merchantLandingPageUrl = $this->_urlBuilder->getUrl($this->_helper->getLandingPageOnReturnAfterRedirect($order->getRealOrderId()));
        // add to session in order to be retrieved on return
        if ($this->_session->getOrderId()) {
            $this->_session->unsOrderId();
        }
        $this->_session->setOrderId($orderId);

        $shippingAddress = $billingAddress = array();
        $shipping = $order->getShippingAddress();
        if(!empty($shipping)){
            $shippingAddress = array(
                //Shipping Information
                "shippingCity" => (string)$shipping->getCity() ?? '', // Optional - Customer city
                "shippingCountry" => (string)$shipping->getCountryId() ?? '', // Optional - Customer country code per ISO 3166-2
                "shippingEmail" => (string)$shipping->getEmail() ?? '', // Optional - Customer email address
                "shippingAddress1" => (string)$shipping->getStreetLine(1) ?? '', // Optional - Customer address
                "shippingAddress2" => (string)$shipping->getStreetLine(2) ?? '', // Optional - Customer address
                "shippingAddress3" => (string)$shipping->getStreetLine(3) ?? '', // Optional - Customer address
                "shippingPhone" => (string)$shipping->getTelephone() ?? '', // Optional - Customer phone number
                "shippingState" => (string)$shipping->getRegionCode() ?? '', // Optional - Customer state with 2 characters
                "shippingPostalCode" => (string)$shipping->getPostcode() ?? '', // Optional - Customer zip code
            );
        }
        $billing = $order->getBillingAddress();
        if(!empty($billing)){
            $billingAddress = array(
                //Billing Information
                'billingFirstName' => (string)$billing->getFirstname() ?? '',
                'billingLastName'  => (string)$billing->getLastname() ?? '',
                'billingCompany'    => (string)$billing->getCompany() ?? '',
                'billingAddress1'  => (string)$billing->getStreetLine(1) ?? '',
                'billingAddress2'  => (string)$billing->getStreetLine(2) ?? '',
                'billingAddress3'  => (string)$billing->getStreetLine(3) ?? '',
                'billingCity'       => (string)$billing->getCity() ?? '',
                'billingState'      => (string)$billing->getRegionCode() ?? '',
                'billingPostalCode'   => (string)$billing->getPostcode() ?? '',
                'billingCountry'    => (string)$billing->getCountryId() ?? '',
                'billingEmail'      => (string)$billing->getEmail() ?? '',
                'billingPhone'      => (string)$billing->getTelephone() ?? '',
            );
        }

        return  array_merge(array(
            "action" => $apiOperation,
            "referenceNum" => $orderId, // REQUIRED - Merchant internal order number //
            "Amount" => $amount, // REQUIRED - Transaction amount in US format //
            "Currency" => $orderCurrencyCode, // Optional - Valid only for ChasePaymentech multi-currecy setup. Please see full documentation for more info
            "pluginName" => "Magento PayFabric Gateway",
            "pluginVersion" => "1.0.0",
            "customerId" => $customerId,
            //level2/3
            'freightAmount'    => $this->formatAmount($order->getBaseShippingAmount()),
            'taxAmount' => $this->formatAmount($order->getBaseTaxAmount()),
            'lineItems' => $this->get_level3_data_from_order($order),
            //Optional
            'allowOriginUrl' => $allowOriginUrl,
            "merchantNotificationUrl" => $merchantNotificationUrl,
        ), $shippingAddress, $billingAddress);
    }

    private function get_level3_data_from_order($order)
    {
        $items = array();
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = array(
                'product_code'                  => $item->getSku() ? $item->getSku() : $item->getProductId(),
                'product_description'           => $item->getDescription() ? $item->getDescription() : $item->getName(),
                'unit_cost'                     => $this->formatAmount($item->getPrice()),
                'quantity'                      => (int)$item->getQtyOrdered(),
                'discount_amount'               => $this->formatAmount($item->getDiscountAmount()),
                'tax_amount'                    => $this->formatAmount($item->getTaxAmount()),
                'item_amount'                   => $this->formatAmount($item->getRowTotal())
            );
        }
        return $items;
    }

    public function formatAmount($amount, $asFloat = false)
    {
        return number_format((float)$amount, 2, '.', '');
    }

    public function toAPIOperation($paymentAction)
    {
        switch ($paymentAction) {
            case NewOrderPaymentActions::PAYMENT_ACTION_AUTH: {
                return "AUTH";
            }
            case NewOrderPaymentActions::PAYMENT_ACTION_SALE: {
                return "PURCHASE";
            }
            default: {
                return strtoupper($paymentAction);
            }
        }
    }

    public function getMerchantNotificationUrl()
    {
        // merchant notification URL: server-to-server, URL to which the Transaction Result Call will be sent
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $merchantNotificationUrl = $this->_urlBuilder->getUrl($this->_helper->getNotificationRoute($order->getRealOrderId()));
        return $merchantNotificationUrl;

    }

    public function getMerchantLandingPageUrl()
    {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        // The URL to which the customer’s browser is redirected after the payment
        $merchantLandingPageUrl = $this->_urlBuilder->getUrl($this->_helper->getLandingPageOnReturnAfterRedirect($order->getRealOrderId()));
        return $merchantLandingPageUrl;
    }

    /**
     * Capture payment
     *
     * @param \Magento\Framework\DataObject|InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @deprecated 100.2.0
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        if(!$payment->getParentTransactionId())    return $this;
        parent::capture($payment, $amount);
        $params = array(
            "amount" => $amount,
            "originalMerchantTxId" => $payment->getParentTransactionId()
        );
		$result = $this->_helper->executeGatewayTransaction("CAPTURE", $params);
        if(strtolower($result->Status) == 'approved') {
            $payment->setTransactionId($result->TrxKey)
                ->setTransactionAdditionalInfo(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS, json_decode(json_encode($result),true));
//            $order = $payment->getOrder();
//            $order->setState("processing")
//                ->setStatus("processing")
//                ->addStatusHistoryComment(__('Payment captured'));
//            $order->save();
        } else {
            throw new \Magento\Framework\Validator\Exception(isset($result->Message) ? __($result->Message) : __( 'Capture error!' ));
        }

        return $this;
    }

    /**
     * Refund specified amount for payment
     *
     * @param \Magento\Framework\DataObject|InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @deprecated 100.2.0
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        parent::refund($payment, $amount);

        $params = array(
            "amount" => $amount,
            "originalMerchantTxId" => $payment->getRefundTransactionId()
        );
        $result = $this->_helper->executeGatewayTransaction("REFUND", $params);
        if(strtolower($result->Status) == 'approved') {
            $payment->setTransactionId($result->TrxKey)
                ->setTransactionAdditionalInfo(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS, json_decode(json_encode($result), true));
//            $order = $payment->getOrder();
//            $order->setState("processing")
//                ->setStatus("processing")
//                ->addStatusHistoryComment('Payment refunded amount ' . $amount);
//            $transaction = $payment->addTransaction(Transaction::TYPE_REFUND, null, true);
//            $transaction->setIsClosed(0);
//            $transaction->save();
//            $order->save();
        } else {
            throw new \Magento\Framework\Validator\Exception(isset($result->Message) ? __($result->Message) : __( 'Refund error!' ));
        }

        return $this;
    }

    /**
     * Cancel payment
     *
     * @param \Magento\Framework\DataObject|InfoInterface $payment
     * @return $this
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @deprecated 100.2.0
     */
    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        return $this->void($payment);
    }

    /**
     * Void payment
     *
     * @param \Magento\Framework\DataObject|InfoInterface $payment
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @deprecated 100.2.0
     */
    public function void(\Magento\Payment\Model\InfoInterface $payment)
    {
        parent::void($payment);
        $params = array(
            "originalMerchantTxId" => $payment->getParentTransactionId()
        );
        $result = $this->_helper->executeGatewayTransaction("VOID", $params);
        if(strtolower($result->Status) == 'approved') {
            $payment->setTransactionId($result->TrxKey)
                ->setTransactionAdditionalInfo(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS, json_decode(json_encode($result), true));
            $order = $payment->getOrder();
            $order->setState("canceled")
                ->setStatus("canceled")
                ->addStatusHistoryComment(__('Payment voided'));
            $transaction = $payment->addTransaction(Transaction::TYPE_VOID, null, true);
            $transaction->setIsClosed(1);
            $transaction->save();
            $order->save();
        } else {
            throw new \Magento\Framework\Validator\Exception(isset($result->Message) ? __($result->Message) : __( 'Void error!' ));
        }

        return $this;
    }

    /**
     * Retrieve request object.
     *
     * @return \Magento\Framework\App\RequestInterface
     */
    protected function _getRequest()
    {
        return $this->_request;
    }

    public function getUrlBuilder()
    {
        return $this->_urlBuilder;
    }
}
