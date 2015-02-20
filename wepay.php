<?php
/**
 * @package      CrowdFunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport('crowdfunding.payment.plugin');

/**
 * CrowdFunding WePay Payment Plugin
 *
 * @package      CrowdFunding
 * @subpackage   Plugins
 */
class plgCrowdFundingPaymentWePay extends CrowdFundingPaymentPlugin
{
    const WEPAY_ERROR_CONFIGURATION = 101;
    const WEPAY_ERROR_CHECKOUT      = 102;

    protected $paymentService       = "wepay";

    protected $textPrefix           = "PLG_CROWDFUNDINGPAYMENT_WEPAY";
    protected $debugType            = "WEPAY_PAYMENT_PLUGIN_DEBUG";

    protected $extraDataKeys        = array(
        "account_id", "type", "fee_payer", "state", "auto_capture", "app_fee",
        "app_fee", "create_time", "mode", "gross", "fee", "tax"
    );

    /**
     * @var JApplicationSite
     */
    protected $app;

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param object    $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|string
     */
    public function onProjectPayment($context, &$item, &$params)
    {
        if (strcmp("com_crowdfunding.payment", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        // Flag for error.
        $error = false;

        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/wepay";

        // Load the script that initialize the select element with banks.
        JHtml::_("jquery.framework");

        // Get intention
        $userId  = JFactory::getUser()->get("id");
        $aUserId = $this->app->getUserState("auser_id");

        // Create intention object
        $intention = $this->getIntention(array(
            "user_id"    => $userId,
            "auser_id"   => $aUserId,
            "project_id" => $item->id
        ));

        $accountId    = (int)$this->params->get("wepay_account_id");
        $clientId     = (int)$this->params->get("wepay_client_id");
        $clientSecret = JString::trim($this->params->get("wepay_client_secret"));

        // Get access token
        if ($this->params->get("wepay_staging", 1)) { // Staging server access token.
            $accessToken  = JString::trim($this->params->get("wepay_staging_access_token"));
        } else {// Live server access token.
            $accessToken  = JString::trim($this->params->get("wepay_access_token"));
        }

        if (!$accountId or !$accessToken or !$clientId or !$clientSecret) {
            $error = self::WEPAY_ERROR_CONFIGURATION;
        }

        if (!$error) { // Create checkout object

            $notifyUrl = $this->getCallbackUrl();
            $returnUrl = $this->getReturnUrl($item->slug, $item->catslug);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_NOTIFY_URL"), $this->debugType, $notifyUrl) : null;
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RETURN_URL"), $this->debugType, $returnUrl) : null;

            try {

                jimport("itprism.payment.wepay.libs.wepay");

                if ($this->params->get("wepay_staging", 1)) {
                    Wepay::useStaging($clientId, $clientSecret);
                } else {
                    Wepay::useProduction($clientId, $clientSecret);
                }

                $customCertificate = (!$this->params->get("wepay_use_cacert", 0)) ? false : true;

                $wePay = new WePay($accessToken);
                /** @var $wePay WePay */

                // create the checkout
                $response = $wePay->request(
                    'checkout/create',
                    array(
                        'account_id'        => $accountId,
                        'amount'            => $item->amount,
                        'short_description' => JText::sprintf($this->textPrefix . "_INVESTING_IN_S", htmlentities($item->title, ENT_QUOTES, "UTF-8")),
                        'type'              => 'DONATION',
                        'redirect_uri'      => $returnUrl,
                        'callback_uri'      => $notifyUrl,
                        'mode'              => "iframe",
                        'fee_payer'         => $this->params->get("fees_payer", "payee")
                    ),
                    $customCertificate
                );

                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_WEPAY_CO"), $this->debugType, $wePay) : null;

                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_WEPAY_COR"), $this->debugType, $response) : null;

                $intentionData = array(
                    "unique_key" => $response->checkout_id,
                    "gateway"    => "WePay"
                );

                $intention->bind($intentionData);
                $intention->store();

            } catch (Exception $e) {

                JLog::add($e->getMessage());
                $error = self::WEPAY_ERROR_CHECKOUT;
            }
        }

        $html   = array();
        $html[] = '<div class="well">'; // Open "well".

        $html[] = '<h4><img src="' . $pluginURI . '/images/wepay_icon.png" width="32" height="32" alt="WePay" />' . JText::_($this->textPrefix . "_TITLE") . '</h4>';

        if (!$error) {

            $html[] = '<div class="cf-wepay-payment">';

            $html[] = '<div id="cf-wepay-payment-form">';
            $html[] = '</div>';

            $html[] = '<div class="clearfix"></div>';

            if ($this->params->get('wepay_display_info', 1)) {
                $html[] = '<p class="alert alert-info"><i class="icon-info-sign"></i>' . JText::_($this->textPrefix . "_INFO") . '</p>';
            }

            if ($this->params->get('wepay_sandbox', 1)) {
                $html[] = '<p class="alert alert-info"><i class="icon-info-sign"></i>' . JText::_($this->textPrefix . "_WORKS_SANDBOX") . '</p>';
            }

            $html[] = '</div>';

            // Add scripts
            $doc->addScript("https://www.wepay.com/min/js/iframe.wepay.js");

            $js = '
            jQuery(document).ready(function() {
            	WePay.iframe_checkout("cf-wepay-payment-form", "' . $response->checkout_uri . '");
            });';

            $doc->addScriptDeclaration($js);

        } else {

            switch ($error) {
                case 101:
                    $html[] = '<div class="alert">' . JText::_($this->textPrefix . "_ERROR_CONFIGURATION") . '</div>';
                    break;

                case 102:
                    $html[] = '<div class="alert">' . JText::_($this->textPrefix . "_ERROR_CANNOT_CREATE_CHECKOUT") . '</div>';
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
     * @return null|array
     */
    public function onPaymentNotify($context, &$params)
    {
        if (strcmp("com_crowdfunding.notify.wepay", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return null;
        }

        // Validate request method
        $requestMethod = $this->app->input->getMethod();
        if (strcmp("POST", $requestMethod) != 0) {
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_REQUEST_METHOD"),
                "WEPAY_PAYMENT_PLUGIN_ERROR",
                JText::sprintf($this->textPrefix . "_ERROR_INVALID_TRANSACTION_REQUEST_METHOD", $requestMethod)
            );

            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESPONSE"), $this->debugType, $_POST) : null;

        // Get checkout ID
        $checkoutId = $this->app->input->get("checkout_id");
        if (!$checkoutId) {
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_CHECKOUT_ID"),
                "WEPAY_PAYMENT_PLUGIN_ERROR"
            );

            return null;
        }

        // Prepare the array that will be returned by this method
        $result = array(
            "project"         => null,
            "reward"          => null,
            "transaction"     => null,
            "payment_session" => null,
            "payment_service" => $this->paymentService
        );

        // Get currency
        jimport("crowdfunding.currency");
        $currencyId = $params->get("project_currency");
        $currency   = CrowdFundingCurrency::getInstance(JFactory::getDbo(), $currencyId);

        // Get intention data
        $keys = array(
            "unique_key" => $checkoutId
        );
        jimport("crowdfunding.intention");
        $intention = new CrowdFundingIntention(JFactory::getDbo());
        $intention->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_INTENTION"), $this->debugType, $intention->getProperties()) : null;

        // Validate the payment gateway.
        $gateway = $intention->getGateway();
        if (!$this->isValidPaymentGateway($gateway)) {
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_PAYMENT_GATEWAY"),
                "WEPAY_PAYMENT_PLUGIN_ERROR",
                array("INTENTION" => $intention->getProperties())
            );

            return null;
        }

        jimport("itprism.payment.wepay.libs.wepay");

        $clientId     = (int)$this->params->get("wepay_client_id");
        $clientSecret = JString::trim($this->params->get("wepay_client_secret"));

        // Get access token
        if ($this->params->get("wepay_staging", 1)) { // Staging server access token.

            $accessToken  = JString::trim($this->params->get("wepay_staging_access_token"));
            Wepay::useStaging($clientId, $clientSecret);

        } else {// Live server access token.

            $accessToken  = JString::trim($this->params->get("wepay_access_token"));
            Wepay::useProduction($clientId, $clientSecret);

        }

        $customCertificate = (!$this->params->get("wepay_use_cacert", 0)) ? false : true;

        $wePay = new WePay($accessToken);
        /** @var $wePay WePay */

        try {

            $requestParams = array(
                'checkout_id' => $checkoutId,
            );

            // Get data about the checkout
            $response = $wePay->request('checkout', $requestParams, $customCertificate);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_WEPAY_CHECKOUT"), $this->debugType, $wePay) : null;

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_WEPAY_COR"), $this->debugType, $response) : null;

        } catch (Exception $e) {

            // Log error
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_CHECKOUT_REQUEST"),
                "WEPAY_PAYMENT_PLUGIN_ERROR",
                $e->getMessage()
            );

            return $result;

        }

        $response = JArrayHelper::fromObject($response);

        // Validate transaction data
        $validData = $this->validateData($response, $currency->getAbbr(), $intention);
        if (is_null($validData)) {
            return $result;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_VALID_DATA"), $this->debugType, $validData) : null;

        // Get project
        jimport("crowdfunding.project");
        $projectId = JArrayHelper::getValue($validData, "project_id");
        $project   = CrowdFundingProject::getInstance(JFactory::getDbo(), $projectId);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PROJECT_OBJECT"), $this->debugType, $project->getProperties()) : null;

        // Check for valid project
        if (!$project->getId()) {
            $error = JText::_($this->textPrefix . "_ERROR_INVALID_PROJECT");
            $error .= "\n" . JText::sprintf($this->textPrefix . "_TRANSACTION_DATA", var_export($validData, true));
            JLog::add($error);

            return $result;
        }

        // Set the receiver of funds
        $validData["receiver_id"] = $project->getUserId();

        // Save transaction data.
        // If it is not completed, return empty results.
        // If it is complete, continue with process transaction data
        $transactionData = $this->storeTransaction($validData, $project);
        if (is_null($transactionData)) {
            return $result;
        }

        // Update the number of distributed reward.
        $rewardId = JArrayHelper::getValue($transactionData, "reward_id");
        $reward   = null;
        if (!empty($rewardId)) {
            $reward = $this->updateReward($transactionData);

            // Validate the reward.
            if (!$reward) {
                $transactionData["reward_id"] = 0;
            }
        }

        //  Prepare the data that will be returned

        $result["transaction"] = JArrayHelper::toObject($transactionData);

        // Generate object of data based on the project properties
        $properties        = $project->getProperties();
        $result["project"] = JArrayHelper::toObject($properties);

        // Generate object of data based on the reward properties
        if (!empty($reward)) {
            $properties       = $reward->getProperties();
            $result["reward"] = JArrayHelper::toObject($properties);
        }

        // Generate data object, based on the intention properties.
        $properties       = $intention->getProperties();
        $result["payment_session"] = JArrayHelper::toObject($properties);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESULT_DATA"), $this->debugType, $result) : null;

        // Remove intention
        $intention->delete();
        unset($intention);

        return $result;

    }

    /**
     * This metod is executed after complete payment.
     * It is used to be sent mails to user and administrator
     *
     * @param string $context
     * @param object $transaction Transaction data
     * @param Joomla\Registry\Registry $params Component parameters
     * @param object $project Project data
     * @param object $reward Reward data
     * @param object $paymentSession Payment session data.
     *
     * @return void
     */
    public function onAfterPayment($context, &$transaction, &$params, &$project, &$reward, &$paymentSession)
    {
        if (strcmp("com_crowdfunding.notify.wepay", $context) != 0) {
            return;
        }

        if ($this->app->isAdmin()) {
            return;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return;
        }

        // Send mails
        $this->sendMails($project, $transaction, $params);
    }

    /**
     * Validate transaction data.
     *
     * @param array  $data
     * @param string $currency
     * @param CrowdFundingIntention  $intention
     *
     * @return array
     */
    protected function validateData($data, $currency, $intention)
    {
        $timesamp = JArrayHelper::getValue($data, "create_time");
        $date     = new JDate($timesamp);

        // Prepare transaction status.
        $txnState = JArrayHelper::getValue($data, "state");
        switch ($txnState) {
            case "captured":
                $txnState = "completed";
                break;
            case "failed":
                $txnState = "failed";
                break;
            case "canceled":
                $txnState = "canceled";
                break;
            default:
                $txnState = "pending";
                break;
        }

        // Prepare transaction data.
        $transaction = array(
            "investor_id"      => $intention->getUserId(),
            "project_id"       => $intention->getProjectId(),
            "reward_id"        => ($intention->isAnonymous()) ? 0 : $intention->getRewardId(),
            "txn_id"           => JArrayHelper::getValue($data, "checkout_id"),
            "txn_amount"       => JArrayHelper::getValue($data, "amount"),
            "txn_currency"     => $currency,
            "txn_status"       => $txnState,
            "txn_date"         => $date->toSql(),
            "extra_data"       => $this->prepareExtraData($data),
            "service_provider" => "WePay",
        );

        // Check User Id, Project ID and Transaction ID.
        if (!$transaction["project_id"] or !$transaction["txn_id"]) {
            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_TRANSACTION_DATA"),
                "WEPAY_PAYMENT_PLUGIN_ERROR",
                $transaction
            );

            return null;
        }

        return $transaction;
    }

    /**
     * Save transaction
     *
     * @param array               $transactionData
     * @param CrowdFundingProject $project
     *
     * @return null|array
     */
    protected function storeTransaction($transactionData, $project)
    {
        // Get transaction by txn ID
        jimport("crowdfunding.transaction");
        $keys        = array(
            "txn_id" => JArrayHelper::getValue($transactionData, "txn_id")
        );
        $transaction = new CrowdFundingTransaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_TRANSACTION_OBJECT"), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction
        if ($transaction->getId()) {

            // If the current status if completed,
            // stop the process.
            if ($transaction->isCompleted()) {
                return null;
            }

        }

        // Add extra data.
        if (isset($transactionData["extra_data"])) {
            if (!empty($transactionData["extra_data"])) {
                $transaction->addExtraData($transactionData["extra_data"]);
            }

            unset($transactionData["extra_data"]);
        }

        // Store the new transaction data.
        $transaction->bind($transactionData);
        $transaction->store();

        // Set transaction ID.
        $transactionData["id"] = $transaction->getId();

        // If it is not completed (it might be pending or other status),
        // stop the process. Only completed transaction will continue
        // and will process the project, rewards,...
        if (!$transaction->isCompleted()) {
            return null;
        }

        // update project funded amount.
        $amount = JArrayHelper::getValue($transactionData, "txn_amount");
        $project->addFunds($amount);
        $project->updateFunds();

        return $transactionData;
    }
}
