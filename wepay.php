<?php
/**
 * @package      CrowdFunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2013 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport('joomla.plugin.plugin');

/**
 * CrowdFunding WePay Payment Plugin
 *
 * @package      CrowdFunding
 * @subpackage   Plugins
 * 
 * @todo Use $this->app and $autoloadLanguage to true, when Joomla! 2.5 is not actual anymore.
 */
class plgCrowdFundingPaymentWePay extends JPlugin {
    
    const WEPAY_ERROR_CONFIGURATION = 101;
    const WEPAY_ERROR_CHECKOUT      = 102;
     
    protected   $log;
    protected   $logFile = "plg_crowdfunding_wepay.php";
    
    public function __construct(&$subject, $config = array()) {
    
        parent::__construct($subject, $config);
    
        // Create log object
        $file = JPath::clean(JFactory::getApplication()->getCfg("log_path") .DIRECTORY_SEPARATOR. $this->logFile);
    
        $this->log = new CrowdFundingLog();
        $this->log->addWriter(new CrowdFundingLogWriterDatabase(JFactory::getDbo()));
        $this->log->addWriter(new CrowdFundingLogWriterFile($file));
    
        // Load language
        $this->loadLanguage();
    }
    
    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string 	$context	This string gives information about that where it has been executed the trigger.
     * @param object 	$item	    A project data.
     * @param JRegistry $params	    The parameters of the component
     */
    public function onProjectPayment($context, $item, $params) {
        
        $app = JFactory::getApplication();
        /** @var $app JSite **/

        if($app->isAdmin()) {
            return;
        }

        $doc     = JFactory::getDocument();
        /**  @var $doc JDocumentHtml **/
        
        // Check document type
        $docType = $doc->getType();
        if(strcmp("html", $docType) != 0){
            return;
        }
       
        if(strcmp("com_crowdfunding.payment", $context) != 0){
            return;
        }
        
        // Load language
        $this->loadLanguage();
        
        // Flag for error.
        $error = false;
        
        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/wepay";
        
        // Load the script that initialize the select element with banks.
        if(version_compare(JVERSION, "3", ">=")) {
            JHtml::_("jquery.framework");
        }
        
        // Get intention
        $userId        = JFactory::getUser()->id;
        $aUserId       = $app->getUserState("auser_id");
        
        // Create intention object
        $intention     = CrowdFundingHelper::getIntention($userId, $aUserId, $item->id);
        
        $accountId     = (int)$this->params->get("wepay_account_id");
        $accessToken   = JString::trim($this->params->get("wepay_access_token"));
        $clientId      = (int)$this->params->get("wepay_client_id");
        $clientSecret  = JString::trim($this->params->get("wepay_client_secret"));
        
        if(!$accountId OR !$accessToken OR !$clientId OR !$clientSecret) {
            $error = self::WEPAY_ERROR_CONFIGURATION;
        }
        
        if(!$error) { // Create checkout object
            
            $notifyUrl = $this->getNotifyUrl();
            $returnUrl = $this->getReturnUrl($item->slug, $item->catslug);
            
            try {
                
                jimport("itprism.payment.wepay.libs.wepay");
                
                if($this->params->get("wepay_staging", 1)) {
                    // change to useStaging for live environments
                    Wepay::useStaging($clientId, $clientSecret);
                } else {
                    // change to useProduction for live environments
                    Wepay::useProduction($clientId, $clientSecret);
                }
                
                $customCertificate = (!$this->params->get("wepay_use_cacert", 0)) ? false : true;
                
                $wePay = new WePay($accessToken);
                /** @var $wePay WePay **/
                
                // create the checkout
                $response = $wePay->request('checkout/create', array(
                    'account_id'        => $accountId,
                    'amount'            => $item->amount,
                    'short_description' => JText::sprintf("PLG_CROWDFUNDINGPAYMENT_WEPAY_INVESTING_IN_S", htmlentities($item->title, ENT_QUOTES, "UTF-8")),
                    'type'              => 'DONATION',
                    'redirect_uri'      => $returnUrl,
                    'callback_uri'      => $notifyUrl,
                    'mode'              => "iframe"
                ),
                    $customCertificate);
                
                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_WEPAY_CO"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $wePay) : null;
                
                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_WEPAY_COR"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $response) : null;
                
                $intentionData = array(
                    "txn_id"    => $response->checkout_id,
                    "gateway"   => "WePay"
                );
                
                $intention->bind($intentionData);
                $intention->store();
                
            } catch (Exception $e) {
                
                JLog::add($e->getMessage());
                $error = self::WEPAY_ERROR_CHECKOUT;
                
            }
            
        }
            
        $html   =  array();
        $html[] = '<h4><img src="'.$pluginURI.'/images/wepay_icon.png" width="32" height="32" alt="WePay" />'.JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_TITLE").'</h4>';
        
        if(!$error) {
                
            $html[] = '<div class="cf-wepay-payment">';
            
            $html[] = '<div id="cf-wepay-payment-form">';
            $html[] = '</div>';
            
            $html[] = '<div class="clearfix"></div>';
            
            if($this->params->get('wepay_display_info', 1)) {
                $html[] = '<p class="sticky">'.JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_INFO").'</p>';
            }
            
            if($this->params->get('wepay_sandbox', 1)) {
                $html[] = '<p class="sticky">'.JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_WORKS_SANDBOX").'</p>';
            }
            
            $html[] = '</div>';
            
            // Add scripts
            $doc->addScript("https://www.wepay.com/min/js/iframe.wepay.js");
            
            $js = '
            jQuery(document).ready(function() {
            	WePay.iframe_checkout("cf-wepay-payment-form", "'.$response->checkout_uri.'");
            });';
            
            $doc->addScriptDeclaration($js);
            
        } else {
            
            switch($error) {
                case 101:
                    $html[] = '<div class="alert">'.JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_CONFIGURATION").'</div>';
                    break;
                    
                case 102:
                    $html[] = '<div class="alert">'.JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_CANNOT_CREATE_CHECKOUT").'</div>';
                    break;
            }
            
        }
        
        return implode("\n", $html);
        
    }
    
    /**
     * This method processes transaction data that comes from the paymetn gateway.
     *  
     * @param string 	$context	This string gives information about that where it has been executed the trigger.
     * @param JRegistry $params	    The parameters of the component
     */
    public function onPaymenNotify($context, $params) {
        
        $app = JFactory::getApplication();
        /** @var $app JSite **/
        
        if($app->isAdmin()) {
            return;
        }

        $doc     = JFactory::getDocument();
        /**  @var $doc JDocumentHtml **/
        
        // Check document type
        $docType = $doc->getType();
        if(strcmp("raw", $docType) != 0){
            return;
        }
       
        if(strcmp("com_crowdfunding.notify", $context) != 0){
            return;
        }
        
        // Load language
        $this->loadLanguage();
        
        // Validate request method
        $requestMethod = $app->input->getMethod();
        if(strcmp("POST", $requestMethod) != 0) {
            $this->log->add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_INVALID_REQUEST_METHOD"),
                "WEPAY_PAYMENT_PLUGIN_ERROR",
                JText::sprintf("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_INVALID_TRANSACTION_REQUEST_METHOD", $requestMethod)
            );
            return null;
        }
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_RESPONSE"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $_POST) : null;
        
        // Get checkout ID
        $checkoutId      = $app->input->get("checkout_id");
        if(!$checkoutId) {
            $this->log->add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_INVALID_CHECKOUT_ID"),
                "WEPAY_PAYMENT_PLUGIN_ERROR"
            );
            return null;
        }
        
        // Prepare the array that will be returned by this method
        $result = array(
        	"project"          => null, 
        	"reward"           => null, 
        	"transaction"      => null,
            "payment_service"  => "wepay"
        );
        
        // Get currency
        jimport("crowdfunding.currency");
        $currencyId      = $params->get("project_currency");
        $currency        = CrowdFundingCurrency::getInstance($currencyId);
        
        // Get intention data
        $keys = array(
            "txn_id" => $checkoutId
        );
        jimport("crowdfunding.intention");
        $intention     = new CrowdFundingIntention($keys);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_INTENTION"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $intention->getProperties()) : null;
        
        // Validate the payment gateway.
        if(!$this->isWePayGateway($intention)) {
            $this->log->add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_INVALID_PAYMENT_GATEWAY"),
                "WEPAY_PAYMENT_PLUGIN_ERROR",
                array("INTENTION" => $intention->getProperties())
            );
            return null;
        }
        
        $accountId     = (int)$this->params->get("wepay_account_id");
        $accessToken   = JString::trim($this->params->get("wepay_access_token"));
        $clientId      = (int)$this->params->get("wepay_client_id");
        $clientSecret  = JString::trim($this->params->get("wepay_client_secret"));
        
        jimport("itprism.payment.wepay.libs.wepay");
        
        if($this->params->get("wepay_staging", 1)) {
            // change to useStaging for live environments
            Wepay::useStaging($clientId, $clientSecret);
        } else {
            // change to useProduction for live environments
            Wepay::useProduction($clientId, $clientSecret);
        }
        
        $customCertificate = (!$this->params->get("wepay_use_cacert", 0)) ? false : true;
        
        $wePay = new WePay($accessToken);
        /** @var $wePay WePay **/
        
        try {
            
            $requestParams = array(
                'checkout_id' => $checkoutId,
            );
            
            // Get data about the checkout
            $response = $wePay->request('checkout', $requestParams, $customCertificate);
        
            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_WEPAY_CHECKOUT"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $wePay) : null;
            
            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_WEPAY_COR"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $response) : null;
            
        } catch (Exception $e) {
        
            // Log error
            $this->log->add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_CHECKOUT_REQUEST"), 
                "WEPAY_PAYMENT_PLUGIN_ERROR", 
                $e->getMessage()
            );
            
			return $result;
        
        }
        
        $response = JArrayHelper::fromObject($response);
        
        // Validate transaction data
        $validData = $this->validateData($response, $currency->getAbbr(), $intention);
        if(is_null($validData)) {
            return $result;
        }
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_VALID_DATA"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $validData) : null;
        
        // Get project
        jimport("crowdfunding.project");
        $projectId = JArrayHelper::getValue($validData, "project_id");
        $project   = CrowdFundingProject::getInstance($projectId);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_PROJECT_OBJECT"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $project->getProperties()) : null;
        
        // Check for valid project
        if(!$project->getId()) {
            $error  = JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_INVALID_PROJECT");
            $error .= "\n". JText::sprintf("PLG_CROWDFUNDINGPAYMENT_WEPAY_TRANSACTION_DATA", var_export($validData, true));
			JLog::add($error);
			return $result;
        }
        
        // Set the receiver of funds
        $validData["receiver_id"] = $project->getUserId();
        
        // Save transaction data.
        // If it is not completed, return empty results.
        // If it is complete, continue with process transaction data
        if(!$this->storeTransaction($validData, $project)) {
            return $result;
        }
        
        // Validate and Update distributed value of the reward
        $rewardId  = JArrayHelper::getValue($validData, "reward_id");
        $reward    = null;
        if(!empty($rewardId)) {
            $reward = $this->updateReward($validData);
        }
        
        //  Prepare the data that will be returned
        
        $result["transaction"]    = JArrayHelper::toObject($validData);
        
        // Generate object of data based on the project properties
        $properties               = $project->getProperties();
        $result["project"]        = JArrayHelper::toObject($properties);
        
        // Generate object of data based on the reward properties
        if(!empty($reward)) {
            $properties           = $reward->getProperties();
            $result["reward"]     = JArrayHelper::toObject($properties);
        }
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_RESULT_DATA"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $result) : null;
        
        // Remove intention
        $intention->delete();
        unset($intention);
        
        return $result;
                
    }
    
    /**
     * This metod is executed after complete payment.
     * It is used to be sent mails to user and administrator
     * 
     * @param stdObject  Transaction data
     * @param JRegistry  Component parameters
     * @param stdObject  Project data
     * @param stdObject  Reward data
     */
    public function onAfterPayment($context, &$transaction, $params, $project, $reward) {
        
        $app = JFactory::getApplication();
        /** @var $app JSite **/
        
        if($app->isAdmin()) {
            return;
        }

        $doc     = JFactory::getDocument();
        /**  @var $doc JDocumentHtml **/
        
        // Check document type
        $docType = $doc->getType();
        if(strcmp("raw", $docType) != 0){
            return;
        }
       
        if(strcmp("com_crowdfunding.notify.wepay", $context) != 0){
            return;
        }
        
        // Send mails
        $this->sendMails($project, $transaction);
        
    }
    
	/**
     * Validate transaction
     * 
     * @param array $data
     * @param string $currency
     * @param array $intention
     */
    protected function validateData($data, $currency, $intention) {
        
        $timesamp = JArrayHelper::getValue($data, "create_time");
        $date     = new JDate($timesamp);
        
        // Prepare extra data
        $extraData = array(
            "account_id"    => JArrayHelper::getValue($data, "account_id"),
            "type"          => JArrayHelper::getValue($data, "type"),
            "fee_payer"     => JArrayHelper::getValue($data, "fee_payer"),
            "state"         => JArrayHelper::getValue($data, "state"),
            "auto_capture"  => JArrayHelper::getValue($data, "auto_capture"),
            "app_fee"       => JArrayHelper::getValue($data, "app_fee"),
            "create_time"   => JArrayHelper::getValue($data, "create_time"),
            "mode"          => JArrayHelper::getValue($data, "mode"),
            "gross"         => JArrayHelper::getValue($data, "gross"),
            "fee"           => JArrayHelper::getValue($data, "fee"),
            "tax"           => JArrayHelper::getValue($data, "tax")
        );
        
        // Prepare transaction status
        $txnState = JArrayHelper::getValue($data, "state");
        if(strcmp("captured", $txnState) == 0) {
            $txnState = "completed";
        } else {
            $txnState = "pending";
        }
        
        // Prepare transaction data
        $transaction = array(
            "investor_id"		     => $intention->getUserId(),
            "project_id"		     => $intention->getProjectId(),
            "reward_id"			     => ($intention->isAnonymous()) ? 0 : $intention->getRewardId(),
        	"txn_id"                 => JArrayHelper::getValue($data, "checkout_id"),
        	"txn_amount"		     => JArrayHelper::getValue($data, "amount"),
            "txn_currency"           => $currency,
            "txn_status"             => $txnState,
            "txn_date"               => $date->toSql(),
            "extra_data"             => $extraData,
            "service_provider"       => "WePay",
        ); 
        
        // Check User Id, Project ID and Transaction ID
        if(!$transaction["project_id"] OR !$transaction["txn_id"]) {
            // Log data in the database
            $this->log->add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_INVALID_TRANSACTION_DATA"),
                "WEPAY_PAYMENT_PLUGIN_ERROR",
                $transaction
            );
            return null;
        }
        
        return $transaction;
    }
    
    protected function updateReward(&$data) {
        
        // Get reward
        jimport("crowdfunding.reward");
        $keys   = array(
        	"id"         => $data["reward_id"], 
        	"project_id" => $data["project_id"]
        );
        $reward = new CrowdFundingReward($keys);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_REWARD_OBJECT"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $reward->getProperties()) : null;
        
        // Check for valid reward
        if(!$reward->getId()) {
            
            // Log data in the database
            $this->log->add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_INVALID_REWARD"),
                "WEPAY_PAYMENT_PLUGIN_ERROR",
                array("data" => $data, "reward object" => $reward->getProperties())
            );
            
			
			$data["reward_id"] = 0;
			return null;
        }
        
        // Check for valida amount between reward value and payed by user
        $txnAmount = JArrayHelper::getValue($data, "txn_amount");
        if($txnAmount < $reward->getAmount()) {
            
            // Log data in the database
            $this->log->add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_INVALID_REWARD_AMOUNT"),
                "WEPAY_PAYMENT_PLUGIN_ERROR",
                array("data" => $data, "reward object" => $reward->getProperties())
            );
            
			
			$data["reward_id"] = 0;
			return null;
        }
        
        // Verify the availability of rewards
        if($reward->isLimited() AND !$reward->getAvailable()) {
            
            // Log data in the database
            $this->log->add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_REWARD_NOT_AVAILABLE"),
                "WEPAY_PAYMENT_PLUGIN_ERROR",
                array("data" => $data, "reward object" => $reward->getProperties())
            );
            
			$data["reward_id"] = 0;
			return null;
        }
        
        // Increase the number of distributed rewards 
        // if there is a limit.
        if($reward->isLimited()) {
            $reward->increaseDistributed();
            $reward->store();
        }
        
        return $reward;
    }
    
    /**
     * Save transaction
     * 
     * @param array               $data
     * @param CrowdFundingProject $project
     * 
     * @return boolean
     */
    protected function storeTransaction($data, $project) {
        
        // Get transaction by txn ID
        jimport("crowdfunding.transaction");
        $keys = array(
            "txn_id" => JArrayHelper::getValue($data, "txn_id")
        );
        $transaction = new CrowdFundingTransaction($keys);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_TRANSACTION_OBJECT"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $transaction->getProperties()) : null;
        
        // Check for existed transaction
        if($transaction->getId()) {
            
            // If the current status if completed,
            // stop the process.
            if($transaction->isCompleted()) {
                return false;
            } 
            
        }

        // Encode extra data
        if(!empty($data["extra_data"])) {
            $data["extra_data"] = json_encode($data["extra_data"]);
        } else {
            $data["extra_data"] = null;
        }
        
        // Store the new transaction data.
        $transaction->bind($data);
        $transaction->store(true);
        
        $txnStatus = JArrayHelper::getValue($data, "txn_status");
        
        // If it is not completed (it might be pending or other status),
        // stop the process. Only completed transaction will continue 
        // and will process the project, rewards,...
        if(!$transaction->isCompleted()) {
            return false;
        }
        
        // If the new transaction is completed, 
        // update project funded amount.
        $amount = JArrayHelper::getValue($data, "txn_amount");
        $project->addFunds($amount);
        $project->store();
        
        return true;
    }
    
    
    protected function getNotifyUrl($html = true) {
        
        $notifyPage = JString::trim($this->params->get('wepay_notify_url'));
        
        $uri        = JURI::getInstance();
        $domain     = $uri->toString(array("host"));
        
        if( false == strpos($notifyPage, $domain) ) {
            $notifyPage = JURI::root().str_replace("&", "&amp;", $notifyPage);
        }
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_NOTIFY_URL"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $notifyPage) : null;
        
        return $notifyPage;
        
    }
    
    protected function getReturnUrl($slug, $catslug) {
        
        $returnPage = JString::trim($this->params->get('wepay_return_url'));
        if(!$returnPage) {
            $uri        = JURI::getInstance();
            $returnPage = $uri->toString(array("scheme", "host")).JRoute::_(CrowdFundingHelperRoute::getBackingRoute($slug, $catslug, "share"), false);
        } 
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_DEBUG_RETURN_URL"), "WEPAY_PAYMENT_PLUGIN_DEBUG", $returnPage) : null;
        
        return $returnPage;
        
    }
    
    protected function isWePayGateway($intention) {
        
        $gateway = $intention->getGateway();

        if(strcmp("WePay", $gateway) != 0 ) {
            return false;
        }
        
        return true;
    }
    
    protected function sendMails($project, $transaction) {
    
        $app = JFactory::getApplication();
        /** @var $app JSite **/
    
        // Get website
        $uri     = JUri::getInstance();
        $website = $uri->toString(array("scheme", "host"));
    
        jimport("itprism.string");
        jimport("crowdfunding.email");
    
        $emailMode  = $this->params->get("email_mode", "plain");
    
        // Prepare data for parsing
        $data = array(
            "site_name"         => $app->getCfg("sitename"),
            "site_url"          => JUri::root(),
            "item_title"        => $project->title,
            "item_url"          => $website.JRoute::_(CrowdFundingHelperRoute::getDetailsRoute($project->slug, $project->catslug)),
            "amount"            => ITPrismString::getAmount($transaction->txn_amount, $transaction->txn_currency),
            "transaction_id"    => $transaction->txn_id
        );
    
        // Send mail to the administrator
        $emailId = $this->params->get("admin_mail_id", 0);
        if(!empty($emailId)) {
    
            $table    = new CrowdFundingTableEmail(JFactory::getDbo());
            $email    = new CrowdFundingEmail();
            $email->setTable($table);
            $email->load($emailId);
    
            if(!$email->getSenderName()) {
                $email->setSenderName($app->getCfg("fromname"));
            }
            if(!$email->getSenderEmail()) {
                $email->setSenderEmail($app->getCfg("mailfrom"));
            }
    
            $recipientName = $email->getSenderName();
            $recipientMail = $email->getSenderEmail();
    
            // Prepare data for parsing
            $data["sender_name"]     =  $email->getSenderName();
            $data["sender_email"]    =  $email->getSenderEmail();
            $data["recipient_name"]  =  $recipientName;
            $data["recipient_email"] =  $recipientMail;
    
            $email->parse($data);
            $subject    = $email->getSubject();
            $body       = $email->getBody($emailMode);
    
            $mailer  = JFactory::getMailer();
            if(strcmp("html", $emailMode) == 0) { // Send as HTML message
                $return  = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, CrowdFundingEmail::MAIL_MODE_HTML);
    
            } else { // Send as plain text.
                $return  = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, CrowdFundingEmail::MAIL_MODE_PLAIN);
    
            }
    
            // Check for an error.
            if ($return !== true) {
    
                // Log error
                $this->log->add(
                    JText::_("PLG_CROWDFUNDINGPAYMENT_AUTHORIZENET_ERROR_MAIL_SENDING_ADMIN"),
                    "WEPAY_PAYMENT_PLUGIN_DEBUG"
                );
    
            }
    
        }
    
        // Send mail to project owner
        $emailId = $this->params->get("creator_mail_id", 0);
        if(!empty($emailId)) {
    
            $table    = new CrowdFundingTableEmail(JFactory::getDbo());
            $email    = new CrowdFundingEmail();
            $email->setTable($table);
            $email->load($emailId);
    
            if(!$email->getSenderName()) {
                $email->setSenderName($app->getCfg("fromname"));
            }
            if(!$email->getSenderEmail()) {
                $email->setSenderEmail($app->getCfg("mailfrom"));
            }
    
            $user          = JFactory::getUser($transaction->receiver_id);
            $recipientName = $user->get("name");
            $recipientMail = $user->get("email");
    
            // Prepare data for parsing
            $data["sender_name"]     =  $email->getSenderName();
            $data["sender_email"]    =  $email->getSenderEmail();
            $data["recipient_name"]  =  $recipientName;
            $data["recipient_email"] =  $recipientMail;
    
            $email->parse($data);
            $subject    = $email->getSubject();
            $body       = $email->getBody($emailMode);
    
            $mailer  = JFactory::getMailer();
            if(strcmp("html", $emailMode) == 0) { // Send as HTML message
                $return  = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, CrowdFundingEmail::MAIL_MODE_HTML);
    
            } else { // Send as plain text.
                $return  = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, CrowdFundingEmail::MAIL_MODE_PLAIN);
    
            }
    
            // Check for an error.
            if ($return !== true) {
    
                // Log error
                $this->log->add(
                    JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_MAIL_SENDING_PROJECT_OWNER"),
                    "WEPAY_PAYMENT_PLUGIN_DEBUG"
                );
    
            }
        }
    
        // Send mail to backer
        $emailId    = $this->params->get("user_mail_id", 0);
        $investorId = $transaction->investor_id;
        if(!empty($emailId) AND !empty($investorId)) {
    
            $table    = new CrowdFundingTableEmail(JFactory::getDbo());
            $email    = new CrowdFundingEmail();
            $email->setTable($table);
            $email->load($emailId);
    
            if(!$email->getSenderName()) {
                $email->setSenderName($app->getCfg("fromname"));
            }
            if(!$email->getSenderEmail()) {
                $email->setSenderEmail($app->getCfg("mailfrom"));
            }
    
            $user          = JFactory::getUser($investorId);
            $recipientName = $user->get("name");
            $recipientMail = $user->get("email");
    
            // Prepare data for parsing
            $data["sender_name"]     =  $email->getSenderName();
            $data["sender_email"]    =  $email->getSenderEmail();
            $data["recipient_name"]  =  $recipientName;
            $data["recipient_email"] =  $recipientMail;
    
            $email->parse($data);
            $subject    = $email->getSubject();
            $body       = $email->getBody($emailMode);
    
            $mailer  = JFactory::getMailer();
            if(strcmp("html", $emailMode) == 0) { // Send as HTML message
                $return  = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, CrowdFundingEmail::MAIL_MODE_HTML);
    
            } else { // Send as plain text.
                $return  = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, CrowdFundingEmail::MAIL_MODE_PLAIN);
    
            }
    
            // Check for an error.
            if ($return !== true) {
    
                // Log error
                $this->log->add(
                    JText::_("PLG_CROWDFUNDINGPAYMENT_WEPAY_ERROR_MAIL_SENDING_USER"),
                    "WEPAY_PAYMENT_PLUGIN_DEBUG"
                );
    
            }
    
        }
    
    }
    
}