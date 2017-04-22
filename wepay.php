<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2017 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

use Joomla\String\StringHelper;
use Joomla\Utilities\ArrayHelper;
use Joomla\Registry\Registry;
use Crowdfunding\Transaction\Transaction;
use Crowdfunding\Transaction\TransactionManager;
use Crowdfunding\Observer\Transaction\TransactionObserver;
use Crowdfunding\Payment\Session as PaymentSessionRemote;
use Prism\Payment\Result as PaymentResult;

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('Emailtemplates.init');

JObserverMapper::addObserverClassToClass(TransactionObserver::class, TransactionManager::class, array('typeAlias' => 'com_crowdfunding.payment'));

/**
 * Crowdfunding WePay Payment Plugin
 *
 * @package      Crowdfunding
 * @subpackage   Plugins
 */
class plgCrowdfundingPaymentWepay extends Crowdfunding\Payment\Plugin
{
    const WEPAY_ERROR_CONFIGURATION = 101;
    const WEPAY_ERROR_CHECKOUT      = 102;

    public function __construct(&$subject, $config = array())
    {
        $this->serviceProvider = 'WePay';
        $this->serviceAlias    = 'wepay';

        $this->extraDataKeys = array (
            'account_id', 'type', 'fee_payer', 'state', 'auto_capture', 'app_fee',
            'app_fee', 'create_time', 'mode', 'gross', 'fee', 'tax'
        );

        parent::__construct($subject, $config);
    }

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param stdClass  $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|string
     *
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    public function onProjectPayment($context, $item, $params)
    {
        if (strcmp('com_crowdfunding.payment', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // Flag for error.
        $error = false;

        // This is a URI path to the plugin folder
        $pluginURI = 'plugins/crowdfundingpayment/wepay/';

        // Load the script that initialize the select element with banks.
        JHtml::_('jquery.framework');

        // Get payment session
        $paymentSessionContext    = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $paymentSessionLocal      = $this->app->getUserState($paymentSessionContext);

        $paymentSession = $this->getPaymentSession(array(
            'session_id' => $paymentSessionLocal->session_id
        ));

        $accountId    = (int)$this->params->get('account_id');
        $clientId     = (int)$this->params->get('client_id');
        $clientSecret = StringHelper::trim($this->params->get('client_secret'));

        // Get access token
        $accessToken  = $this->getAccessToken();
        if (!$accountId or !$accessToken['token'] or !$clientId or !$clientSecret) {
            $error = self::WEPAY_ERROR_CONFIGURATION;
        }

        $response = null;

        if (!$error) { // Create checkout object

            $notifyUrl = $this->getCallbackUrl();
            $returnUrl = $this->getReturnUrl($item->slug, $item->catslug);
            $cancelUrl = $this->getCancelUrl($item->slug, $item->catslug);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_NOTIFY_URL'), $this->debugType, $notifyUrl) : null;
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RETURN_URL'), $this->debugType, $returnUrl) : null;

            try {
                jimport('Prism.libs.WePay.wepay');

                if ($accessToken['test']) {
                    WePay::useStaging($clientId, $clientSecret);
                } else {
                    WePay::useProduction($clientId, $clientSecret);
                }

                $wePay = new WePay($accessToken['token']);
                /** @var $wePay WePay */

                // create the checkout
                $response = $wePay->request(
                    'checkout/create',
                    array(
                        'account_id'        => $accountId,
                        'amount'            => $item->amount,
                        'currency'          => $item->currencyCode,
                        'short_description' => JText::sprintf($this->textPrefix . '_INVESTING_IN_S', htmlentities($item->title, ENT_QUOTES, 'UTF-8')),
                        'type'              => 'donation',
                        'callback_uri'      => $notifyUrl,
                        'fee'               => ['fee_payer' => $this->params->get('fees_payer', 'payee')],
                        'hosted_checkout' => [
                            'redirect_uri' => $returnUrl,
                            'fallback_uri' => $cancelUrl,
                            'mode' => 'iframe'
                        ]
                    )
                );

                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_WEPAY_CO'), $this->debugType, $wePay) : null;

                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_WEPAY_COR'), $this->debugType, $response) : null;

                $paymentSessionData = array(
                    'unique_key' => $response->checkout_id,
                    'gateway'    => $this->serviceAlias
                );

                $paymentSession->bind($paymentSessionData);
                $paymentSession->store();

            } catch (Exception $e) {
                JLog::add($e->getMessage(), JLog::ERROR, 'com_crowdfunding');
                $error = self::WEPAY_ERROR_CHECKOUT;
            }
        }

        $html   = array();
        $html[] = '<div class="well">'; // Open "well".
        $html[] = '<img src="' . $pluginURI . 'images/wepay_icon.png" width="32" height="32" alt="WePay" />';

        if (!$error and is_object($response)) {
            $html[] = '<div class="cf-wepay-payment">';
            $html[] = '<div id="js-cfwepay-payment-form">';
            $html[] = '</div>';

            if ($this->params->get('display_info', Prism\Constants::YES)) {
                $html[] = $this->generateSystemMessage(JText::_($this->textPrefix . '_INFO'), ['type' => 'info', 'icon' => 'info-circle']);
            }

            if ($accessToken['test']) {
                $html[] = $this->generateSystemMessage(JText::_($this->textPrefix . '_WORKS_SANDBOX'), ['type' => 'warning', 'icon' => 'warning']);
            }

            $html[] = '</div>';

            // Add scripts
            $doc->addScript('https://www.wepay.com/min/js/iframe.wepay.js');

            $js = '
            jQuery(document).ready(function() {
            	WePay.iframe_checkout("js-cfwepay-payment-form", "' . $response->hosted_checkout->checkout_uri . '");
            });';
            $doc->addScriptDeclaration($js);

        } else {
            switch ($error) {
                case 101:
                    $html[] = $this->generateSystemMessage(JText::_($this->textPrefix . '_ERROR_CONFIGURATION'), ['type' => 'warning', 'icon' => 'warning']);
                    break;

                case 102:
                    $html[] = $this->generateSystemMessage(JText::_($this->textPrefix . '_ERROR_CANNOT_CREATE_CHECKOUT', ['type' => 'warning', 'icon' => 'warning']));
                    break;
            }
        }

        $html[] = '</div>'; // Close "well".

        return implode("\n", $html);
    }

    /**
     * This method processes transaction data that comes from the paymetn gateway.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @throws \OutOfBoundsException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     *
     * @return null|PaymentResult
     */
    public function onPaymentNotify($context, $params)
    {
        if (strcmp('com_crowdfunding.notify.'.$this->serviceAlias, $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('raw', $docType) !== 0) {
            return null;
        }

        // Validate request method
        $requestMethod = $this->app->input->getMethod();
        if (strcmp('POST', $requestMethod) !== 0) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_REQUEST_METHOD'), 'WEPAY_PAYMENT_PLUGIN_ERROR', JText::sprintf($this->textPrefix . '_ERROR_INVALID_TRANSACTION_REQUEST_METHOD', $requestMethod));
            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $_POST) : null;

        // Get checkout ID
        $checkoutId = $this->app->input->get('checkout_id');
        if (!$checkoutId) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_CHECKOUT_ID'), 'WEPAY_PAYMENT_PLUGIN_ERROR');
            return null;
        }

        // Prepare an object that have to be returned by this method.
        $paymentResult = new PaymentResult;

        // Get currency
        $containerHelper  = new Crowdfunding\Container\Helper();
        $currency         = $containerHelper->fetchCurrency($this->container, $params);

        // Get payment session data
        $paymentSessionRemote = $this->getPaymentSession(['unique_key' => $checkoutId]);
        if (!$paymentSessionRemote->getId()) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_PAYMENT_SESSION'), 'WEPAY_PAYMENT_PLUGIN_ERROR', ['PAYMENT_SESSION' => $paymentSessionRemote->getProperties()]);
            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION'), $this->debugType, $paymentSessionRemote->getProperties()) : null;

        // Validate the payment gateway.
        $gateway = $paymentSessionRemote->getGateway();
        if (!$this->isValidPaymentGateway($gateway)) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_PAYMENT_GATEWAY'), 'WEPAY_PAYMENT_PLUGIN_ERROR', ['PAYMENT_SESSION' => $paymentSessionRemote->getProperties()]);
            return null;
        }

        jimport('Prism.libs.WePay.wepay');

        $clientId     = (int)$this->params->get('client_id');
        $clientSecret = StringHelper::trim($this->params->get('client_secret'));

        // Get access token.
        $accessToken  = $this->getAccessToken();
        
        if ($accessToken['test']) { // Staging server access token.
            WePay::useStaging($clientId, $clientSecret);
        } else {// Live server access token.
            WePay::useProduction($clientId, $clientSecret);
        }

        $wePay = new WePay($accessToken['token']);
        /** @var $wePay WePay */

        try {
            $requestParams = ['checkout_id' => $checkoutId];

            // Do checkout request and get a response.
            $response = $wePay->request('checkout', $requestParams);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_WEPAY_CHECKOUT'), $this->debugType, $wePay) : null;
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_WEPAY_COR'), $this->debugType, $response) : null;

        } catch (Exception $e) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_CHECKOUT_REQUEST'), 'WEPAY_PAYMENT_PLUGIN_ERROR', $e->getMessage());
            return $paymentResult;
        }

        $response = Joomla\Utilities\ArrayHelper::fromObject($response);

        // Validate transaction data
        $validData = $this->validateData($response, $currency->getCode(), $paymentSessionRemote);
        if ($validData === null) {
            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_VALID_DATA'), $this->debugType, $validData) : null;

        // Set the receiver ID.
        $project = $containerHelper->fetchProject($this->container, $validData['project_id']);
        $validData['receiver_id'] = $project->getUserId();

        // Get reward object.
        $reward = null;
        if ($validData['reward_id']) {
            $reward = $containerHelper->fetchReward($this->container, $validData['reward_id'], $project->getId());
        }

        // Save transaction data.
        // If it is not completed, return empty results.
        // If it is complete, continue with process transaction data
        $transaction = $this->storeTransaction($validData);
        if ($transaction === null) {
            return null;
        }

        //  Prepare the data that will be returned

        // Generate object of data, based on the transaction properties.
        $paymentResult->transaction = $transaction;

        // Generate object of data based on the project properties.
        $paymentResult->project = $project;

        // Generate object of data based on the reward properties.
        if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
            $paymentResult->reward = $reward;
        }

        // Generate data object, based on the payment session properties.
        $paymentResult->paymentSession = $paymentSessionRemote;

        // Removing intention.
        $this->removeIntention($paymentSessionRemote, $transaction);

        // Do not remove session record and do not send email if the payment is Authorized.
        // Remove session if the payment is Released.
        if (strcmp($response['state'], 'authorized') === 0) {
            $paymentResult->triggerEvents = array();
        }

        return $paymentResult;
    }

    /**
     * Validate transaction data.
     *
     * @param array  $data
     * @param string $currency
     * @param Crowdfunding\Payment\Session  $paymentSession
     *
     * @throws \InvalidArgumentException
     *
     * @return array
     */
    protected function validateData($data, $currency, $paymentSession)
    {
        $timestamp = ArrayHelper::getValue($data, 'create_time');

        $dateValidator = new Prism\Validator\Date($timestamp);
        if ($dateValidator->isValid()) {
            $date = new JDate($timestamp);
        } else {
            $date = new JDate();
        }

        // Prepare transaction status.
        $txnState = ArrayHelper::getValue($data, 'state');
        switch ($txnState) {
            case 'released':
                $txnState = 'completed';
                break;
            case 'failed':
                $txnState = 'failed';
                break;

            case 'charged back':
            case 'refunded':
                $txnState = 'refunded';
                break;

            case 'canceled':
                $txnState = 'canceled';
                break;
            default:
                $txnState = 'pending';
                break;
        }

        // Prepare transaction data.
        $transactionData = array(
            'investor_id'      => $paymentSession->getUserId(),
            'project_id'       => $paymentSession->getProjectId(),
            'reward_id'        => $paymentSession->getRewardId(),
            'txn_id'           => ArrayHelper::getValue($data, 'checkout_id'),
            'txn_amount'       => ArrayHelper::getValue($data, 'amount'),
            'txn_currency'     => $currency,
            'txn_status'       => $txnState,
            'txn_date'         => $date->toSql(),
            'extra_data'       => $this->prepareExtraData($data),
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias,
        );

        // Check User Id, Project ID and Transaction ID.
        if (!$transactionData['project_id'] or !$transactionData['txn_id']) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'), 'WEPAY_PAYMENT_PLUGIN_ERROR', $transactionData);
            return null;
        }

        return $transactionData;
    }

    /**
     * Save transaction
     *
     * @param array $transactionData
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     *
     * @return Transaction|null
     */
    protected function storeTransaction($transactionData)
    {
        // Get transaction by txn ID
        $keys  = array(
            'txn_id' => ArrayHelper::getValue($transactionData, 'txn_id')
        );
        $transaction = new Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT'), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction.
        // If the current status if completed, stop the process.
        if ($transaction->getId() and $transaction->isCompleted()) {
            return null;
        }

        // Add extra data.
        if (array_key_exists('extra_data', $transactionData)) {
            if (!empty($transactionData['extra_data'])) {
                $transaction->addExtraData($transactionData['extra_data']);
            }

            unset($transactionData['extra_data']);
        }

        // IMPORTANT: It must be placed before ->bind();
        $options = array(
            'old_status' => $transaction->getStatus(),
            'new_status' => $transactionData['txn_status']
        );

        // Create the new transaction record if there is not record.
        // If there is new record, store new data with new status.
        // Example1: It was 'pending' and now it will be 'completed'.
        // Example2: It was 'pending' and now it will be 'failed'.
        $transaction->bind($transactionData);

        // Start database transaction.
        $db = JFactory::getDbo();

        try {
            $db->transactionStart();

            $transactionManager = new TransactionManager($db);
            $transactionManager->setTransaction($transaction);
            $transactionManager->process('com_crowdfunding.payment', $options);

            $db->transactionCommit();
        } catch (Exception $e) {
            $db->transactionRollback();

            $this->log->add(JText::_($this->textPrefix . '_ERROR_TRANSACTION_PROCESS'), $this->errorType, $e->getMessage());
            return null;
        }

        return $transaction;
    }

    /**
     * Get the keys from plug-in options.
     *
     * @return array
     */
    protected function getAccessToken()
    {
        $options = array();

        if ($this->params->get('staging', Prism\Constants::YES)) { // Test server published key.
            $options['token'] = StringHelper::trim($this->params->get('staging_access_token'));
            $options['test']  = true;
        } else {// Live server access token.
            $options['token'] = StringHelper::trim($this->params->get('access_token'));
            $options['test']  = false;
        }

        return $options;
    }
}
