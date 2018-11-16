<?php
    /*
      Plugin Name: WooCommerce CyberSource Tokenization Payment Gateway
      Plugin URI: http://codecanyon.net/user/oragon/portfolio
      Description: CyberSource Payment Gateway for WooCommerce Extension
      Version: 1.1.2
      Author:oragon
      Author URI: http://codecanyon.net/user/oragon
     */

    function woocommerce_api_cybersource_init() {

        if (!class_exists('WC_Payment_Gateway'))
            return;

        include plugin_dir_path(__FILE__) . 'soap.class.php';

        class WC_API_Cybersource extends WC_Payment_Gateway_CC {

            //CUSTOMIZATION ADDED AVS VALIDATION FEATURE
            public $avs;

            public function __construct() {
                $this->id = 'cybersource';
                $this->method_title = __('CyberSource', 'woocommerce');
                $this->has_fields = true;
                $this->supports = array(
                    'products',
                    'subscriptions',
                    'subscription_cancellation',
                    'subscription_suspension',
                    'subscription_reactivation',
                    'subscription_amount_changes',
                    'subscription_date_changes',
                    'subscription_payment_method_change',
                    'default_credit_card_form',
                    'tokenization',
                    'add_payment_method'
                );

                $this->init_form_fields();
                $this->init_settings();

                $this->title = __($this->settings['title'], 'woocommerce');
                $this->description = __($this->settings['description'], 'woocommerce');
                $this->mode = __($this->settings['mode'], 'woocommerce');
                $this->merchantID = __($this->settings['merchantID'], 'woocommerce');
                $this->transactionKey = __($this->settings['transactionKey'], 'woocommerce');
                $this->merchantReference = __($this->settings['merchantReference'], 'woocommerce');
                $this->paymentaction = __($this->settings['paymentaction'], 'woocommerce');
                $this->returnUrl = __($this->settings['returnUrl'], 'woocommerce');
                $this->debugMode = __($this->settings['debugMode'], 'woocommerce');
                $this->msg['message'] = '';
                $this->msg['class'] = '';

                //CUSTOMIZATION ADDED AVS VALIDATION FEATURE
                $this->avs = 'yes' === $this->settings[ 'avs' ];

                if ($this->mode == 'p') {
                    $this->endpointURL = 'https://ics2ws.ic3.com/commerce/1.x/transactionProcessor/CyberSourceTransaction_1.26.wsdl';
                } else {

                    $this->endpointURL = 'https://ics2wstest.ic3.com/commerce/1.x/transactionProcessor/CyberSourceTransaction_1.26.wsdl';
                }

                if (!empty($this->settings['cards'])) {
                    if ($this->settings['cards'] == 'yes') {
                        $this->icon = plugins_url('assets/images/cards.png', __FILE__);
                    } else {
                        $this->icon = '';
                    }
                } else {

                    $this->icon = '';
                }

                if (isset($this->settings['orderstatus'])) {
                    $this->orderstatus = $this->settings['orderstatus'];
                } else {
                    $this->orderstatus = 1;
                }

                if (!empty($this->settings['enabledsubscriptions'])) {
                    $this->enabledsubscriptions = $this->settings['enabledsubscriptions'];
                } else {
                    $this->enabledsubscriptions = 'no';
                }


                if ($this->debugMode == 'on') {
                    $this->logs = new WC_Logger();
                }

                add_action('cancelled_subscription_' . $this->id, array($this, 'trigger_cancelled_subscription'), 10, 2);
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

                add_filter("woocommerce_credit_card_form_fields", [$this, "alter_form_to_add_cc_name"]);

                add_action('woocommerce_scheduled_subscription_payment_'. $this->id,array($this,'scheduled_subscription_payment'),10,2);

//              add_filter("woocommerce_payment_gateway_get_new_payment_method_option_html", [$this, "alter_form_to_add_cc_name"]);
                
                add_filter('wcs_renewal_order_created',[$this,'make_payment_for_renewal_order'],99,2);              

            }

            public function add_payment_method(){
                
                try {
                    
                    $referenceCode = time();
                    
                    $client = new ExtendedClient(
                        $this->endpointURL, array(
                            'merchantID' => $this->merchantID,
                            'transactionKey' => $this->transactionKey
                        )
                    );
                    
                    $return=true;
                    
                    $request = new stdClass();
                    $request->clientLibrary = "PHP";
                    $request->clientLibraryVersion = phpversion();
                    $request->clientEnvironment = php_uname();
                    $request->merchantID = $this->merchantID;
                    $request->merchantReferenceCode = $referenceCode;



                    //CUSTOMIZATION ADDED AVS VALIDATION FEATURE
                    // disable Address Verification Services (AVS)
                    $businessRules = new stdClass();
                    if( $this->avs === true ){
                        $ignore_avs = false;
                    } else {
                        $ignore_avs = true;
                    }
                    $businessRules->ignoreAVSResult = $ignore_avs;
                    $request->businessRules  = $businessRules;


                    
                    $paySubscriptionCreateService = new stdClass();
                    $paySubscriptionCreateService->run = 'true';
                    $request->paySubscriptionCreateService = $paySubscriptionCreateService;

                    
                    $user_id=get_current_user_id();
                    
                    $billTo = new stdClass();
                    $billTo->firstName = __(get_user_meta($user_id,'billing_first_name',true), 'woocommerce');
                    $billTo->lastName = __(get_user_meta($user_id,'billing_last_name',true), 'woocommerce');
                    $billTo->street1 = __(get_user_meta($user_id,'billing_address_1',true), 'woocommerce');
                    $billTo->city = __(get_user_meta($user_id,'billing_city',true), 'woocommerce');
                    $billTo->state = __(get_user_meta($user_id,'billing_state',true), 'woocommerce');
                    $billTo->postalCode = __(get_user_meta($user_id,'billing_postcode',true), 'woocommerce');
                    $billTo->country = __(get_user_meta($user_id,'billing_country',true), 'woocommerce');
                    $billTo->email = __(get_user_meta($user_id,'billing_email',true), 'woocommerce');
                    $billTo->ipAddress = !empty($_SERVER['HTTP_X_FORWARD_FOR']) ? $_SERVER['HTTP_X_FORWARD_FOR'] : $_SERVER['REMOTE_ADDR'];
                    
                    $request->billTo = $billTo;
                    
                    $card_number = str_replace(' ', '', woocommerce_clean($_POST['cybersource-card-number']));
                    $card_cvc = str_replace(' ', '', woocommerce_clean($_POST['cybersource-card-cvc']));
                    $card_exp_year = str_replace(' ', '', woocommerce_clean($_POST['cybersource-card-expiry']));
                    $productinfo = sprintf(__('%s - Order %s', 'woocommerce'), esc_html(get_bloginfo()), $order->id);
                    
                    $last4=substr($card_number, -4);
                    
                    
                    $exp = explode("/", $card_exp_year);
                    if (count($exp) == 2) {
                        $card_exp_month = str_replace(' ', '', $exp[0]);
                        $card_exp_year = str_replace(' ', '', $exp[1]);
                    } else {
                        wc_add_notice(__('Payment error: Card expiration date is invalid', 'woocommerce'), "error");
                        return false;
                    }
                    
                    global $wpdb;
                    
                    $subscription_results=$wpdb->get_results('select distinct(a.payment_token_id) as token_id from '.$wpdb->prefix.'woocommerce_payment_tokenmeta as a,'.$wpdb->prefix.'woocommerce_payment_tokens as p where a.payment_token_id=p.token_id and  meta_key="last4" and meta_value="'.$last4.'" and p.user_id="'.$user_id.'"');
                    
                    if(!empty($subscription_results)){
                        
                        $data_store = WC_Data_Store::load( 'payment-token' );
                        
                        foreach($subscription_results as $res){
                            
                            $meta = $data_store->get_metadata( $res->token_id );
                            
                            //echo 'year:20'.$card_exp_year." month: ".$card_exp_month." type:".$this->get_cc_type_name($card_number);
                            
                            if($meta['expiry_year'][0]=="20".$card_exp_year && $meta['expiry_month'][0]==$card_exp_month && $meta['card_type'][0]==$this->get_cc_type_name($card_number)){
                                
                                // echo "INNN";
                                
                                return array(
                                    'result'   => 'failure',
                                    //      'msg'      => 'Credit card already exists',
                                    'redirect' => wc_get_endpoint_url( 'payment-methods' ),
                                );
                                
                            }
                            
                        }
                    }
                    
                    $card = new stdClass();
                    $card->accountNumber = $card_number;
                    $card->expirationMonth = $card_exp_month;
                    $card->expirationYear = '20' . $card_exp_year;
                    
                    $cardtype = $this->get_cc_type($card_number);
                    
                    if (empty($cardtype)) {
                        $return=false;
                        //   wc_add_notice(__('Transaction Error: Could not determine the credit card type.', 'woocommerce'), "error");
                    } else {
                        $card->cardType = $cardtype;
                    }
                    
                    if($return){
                        $request->card = $card;
                        $purchaseTotals = new stdClass();
                        $purchaseTotals->currency = get_woocommerce_currency();
                        $request->purchaseTotals = $purchaseTotals;
                        
                        $recurringSubscriptionInfo = new stdClass();
                        $recurringSubscriptionInfo->frequency = 'on-demand';
                        $request->recurringSubscriptionInfo = $recurringSubscriptionInfo;
                        
                        $reply = $client->runTransaction($request);
                        
                        
                        $order_user_id = $user_id;
                        $reason = $this->reason_code($reply->reasonCode);

                        // wc_add_notice('1 Transaction Error: '.$reason.' ', 'error');
                        // return false;
                        
                        switch ($reply->decision) {
                            case 'ACCEPT':
                            case 'REVIEW':
                                if (isset($reply->paySubscriptionCreateReply->subscriptionID) && !empty($reply->paySubscriptionCreateReply->subscriptionID)) {
                                    $subscription_id = $reply->paySubscriptionCreateReply->subscriptionID;
                                    if (!empty($order_user_id)) {
                                        update_user_meta($user_id, "cybersource_subscription_id", $subscription_id);
                                        // update_user_meta($order_user_id, "cybersource_subscription_id_", $subscription_id);
                                        update_user_meta($user_id, "card_type", $cardtype);
                                        update_user_meta($user_id, "card_exp_date", $exp);
                                        
                                        $token = new WC_Payment_Token_CC();
                                        $token->set_token($subscription_id);
                                        $token->set_gateway_id($this->id); // `$this->id` references the gateway ID set in `__construct`
                                        $cardTypeName = $this->get_cc_type_name($card_number);
                                        
                                        $token->set_card_type($cardTypeName);
                                        
                                        $token->set_last4(substr($card_number, -4, 4));
                                        $token->set_expiry_month($card_exp_month);
                                        $token->set_expiry_year('20' . $card_exp_year);
                                        
                                        $token->set_user_id($user_id);
                                        $token->save();
                                        do_action( 'woocommerce_cybersource_add_source', $user_id, $token);
                                    }
                                    
                                    $return=true;
                                }
                                break;
                            case 'ERROR':
                            case 'REJECT':
                                
                                $return=false;
                                break;
                        }
                    }
                } catch (SoapFault $e) {
                    
                    $return=false;
                }
                
                if($return)
                {
                    $return='success';
                    $msg="Your credit card added successfully";
                }
                else
                {
                    $return="failure";
                    $msg="Unable to add your payment method";
                }
                
                return array(
                    'result'   => $return,
                    //   'msg'      => $msg,
                    'redirect' => wc_get_endpoint_url( 'payment-methods' ),
                );
                
            }

            public function make_payment_for_renewal_order($order, $subscription){

              //  $amount=$order->get_total();
                $amount=1;

                $response=$this->scheduled_subscription_payment($amount,$order);
                
                return $order;
            }
      
            public function scheduled_subscription_payment($amount = 0, $order = ''){

                //echo "Its comes here...";

                //$amount=50;

                //$order=wc_get_order('21476');

                if ( 0 == $amount ) {
                    //$order->payment_complete();
                    return;
                }

                if(empty($order))
                {
                    return;
                }

                if($order->post->post_status=='wc-processing' || $order->post->post_status=='wc-completed')
                {
                  //  echo $order->needs_payment();
                  //  die("Payment not needed");
                    return;
                }

                $parent_order = WC_Subscriptions_Renewal_Order::get_parent_order_id( $order->id );
                
                //echo "Parent Order: ".$parent_order;

                if(empty($parent_order))
                {
                    return;
                }

                $renewal_id=$order->id;

                $token_id = wc_clean(get_post_meta($parent_order,'cybersource_token',true));

                if(empty($token_id))
                {
                    return;
                }

                //echo "Token: ".$token_id;

                $cybersource_token_id = get_post_meta($parent_order, 'cybersource_token_id', true);
                $token_card_expire_month_year = get_post_meta($parent_order, 'cybersource_token_expire_date', true);
                $token_card_type = get_post_meta($parent_order, 'cybersource_token_card_type', true);
                
                update_post_meta($renewal_id, 'cybersource_token_id', $cybersource_token_id);
                update_post_meta($renewal_id, 'cybersource_token', $token_id);
                update_post_meta($renewal_id, 'cybersource_token_card_type', $token_card_type);
                update_post_meta($renewal_id, 'cybersource_token_expire_date', $token_card_expire_month_year);    

                $order_user_id = $order->customer_user;

                if(!empty($order_user_id))
                {
                    $subscription_id = $token_id;
                }

                if(!empty($subscription_id))
                {
                 //   $cybersource=new WC_API_Cybersource();
                   
                   $response=$this->payUsingSubscriptionID($subscription_id, $order);

                //    print_r($response);
                }

                return $order;
            }

            function alter_form_to_add_cc_name($default_fields) {
                $default_fields = [];

                $cvc_field = '<p class="form-row form-row-last">
			<label for="' . esc_attr($this->id) . '-card-cvc">' . __('Card Code', 'woocommerce') . ' <span class="required">*</span></label>
			<input id="' . esc_attr($this->id) . '-card-cvc" class="input-text wc-credit-card-form-card-cvc" type="text" autocomplete="off" placeholder="' . esc_attr__('CVC', 'woocommerce') . '" name="' . esc_attr($this->id) . '-card-cvc" style="width:100px" />
		</p>';


                $default_fields = array(
                    'card-number-field' => '<p class="form-row form-row-wide">
				<label for="' . esc_attr($this->id) . '-card-number">' . __('Card Number', 'woocommerce') . ' <span class="required">*</span></label>
				<input id="' . esc_attr($this->id) . '-card-number" class="input-text wc-credit-card-form-card-number" type="text" maxlength="20" autocomplete="off" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" name="' . esc_attr($this->id) . '-card-number" />
			</p>',
                    'card-expiry-field' => '<p class="form-row form-row-first">
				<label for="' . esc_attr($this->id) . '-card-expiry">' . __('Expiry (MM/YY)', 'woocommerce') . ' <span class="required">*</span></label>
				<input id="' . esc_attr($this->id) . '-card-expiry" class="input-text wc-credit-card-form-card-expiry" type="text" autocomplete="off" placeholder="' . esc_attr__('MM / YY', 'woocommerce') . '" name="' . esc_attr($this->id) . '-card-expiry" />
			</p>'
                );

                if (!$this->supports('credit_card_form_cvc_on_saved_method')) {
                    $default_fields['card-cvc-field'] = $cvc_field;
                }

                return $default_fields;
            }

            public function trigger_cancelled_subscription($order, $product_id) {

                $subscriptionID = get_post_meta($order->id, $this->id . '_subscriptionID', true);
                $merchantReferenceCode = get_post_meta($order->id, $this->id . '_merchantReferenceCode', true);

                $item = WC_Subscriptions_Order::get_item_by_product_id($order, $product_id);

                $client = new ExtendedClient(
                        $this->endpointURL, array(
                    'merchantID' => $this->merchantID,
                    'transactionKey' => $this->transactionKey
                        )
                );


                $request = new stdClass();
                $request->clientLibrary = "PHP";
                $request->clientLibraryVersion = phpversion();
                $request->clientEnvironment = php_uname();
                $request->merchantID = $this->merchantID;
                $request->merchantReferenceCode = $merchantReferenceCode;



                //CUSTOMIZATION ADDED AVS VALIDATION FEATURE
                // disable Address Verification Services (AVS)
                $businessRules = new stdClass();
                if( $this->avs === true ){
                    $ignore_avs = false;
                } else {
                    $ignore_avs = true;
                }
                $businessRules->ignoreAVSResult = $ignore_avs;
                $request->businessRules  = $businessRules;



                $paySubscriptionUpdateService = new stdClass();
                $paySubscriptionUpdateService->run = "true";
                $request->paySubscriptionUpdateService = $paySubscriptionUpdateService;

                $recurringSubscriptionInfo = new stdClass();
                $recurringSubscriptionInfo->status = "cancel";
                $recurringSubscriptionInfo->subscriptionID = $subscriptionID;
                $request->recurringSubscriptionInfo = $recurringSubscriptionInfo;

                $reply = $client->runTransaction($request);

                $reason = $this->reason_code($reply->reasonCode);

                // wc_add_notice('2 Transaction Error: '.$reason.' ', 'error');
                        // return false;

                if ($reply->decision == 'ACCEPT') {

                    echo __('<div class="updated fade">', 'woocommerce');
                    echo __('<p>', 'woocommerce');
                    echo __(sprintf('Subscription %s cancelled with %s', $item['name'], $this->method_title), 'woocommerce');
                    echo __('</p>', 'woocommerce');
                    echo __('</div>', 'woocommerce');

                    $order->add_order_note(sprintf(__('Subscription %s cancelled with %s', 'woocommerce'), $item['name'], $this->method_title));
                } else {

                    echo __('<div class="inline error">', 'woocommerce');
                    echo __('<p>', 'woocommerce');
                    echo __(sprintf('Subscription %s cancellation failed with %s. %s ', $item['name'], $this->method_title, $reason), 'woocommerce');
                    echo __('</p>', 'woocommerce');
                    echo __('</div>', 'woocommerce');

                    $order->add_order_note(__(sprintf('Subscription %s cancellation failed with %s. %s ', $item['name'], $this->method_title, $reason), 'woocommerce'));
                }
            }

            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'woocommerce'),
                        'type' => 'checkbox',
                        'label' => __('Enable CyberSource Payment Module.', 'woocommerce'),
                        'default' => 'no'
                    ),
                    'title' => array(
                        'title' => __('Title:', 'woocommerce'),
                        'type' => 'text',
                        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                        'default' => __('CyberSource', 'woocommerce')
                    ),
                    'description' => array(
                        'title' => __('Description:', 'woocommerce'),
                        'type' => 'textarea',
                        'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                        'default' => __('Pay with your credit card via CyberSource.', 'woocommerce')
                    ),
                    'mode' => array(
                        'title' => __('Environment', 'woocommerce'),
                        'type' => 'select',
                        'description' => '',
                        'options' => array(
                            's' => __('Sandbox', 'woocommerce'),
                            'p' => __('Production', 'woocommerce')
                        )
                    ),
                    'enabledsubscriptions' => array(
                        'title' => __('Subscriptions', 'woocommerce'),
                        'type' => 'checkbox',
                        'label' => __('Enable Subscriptions and Recurring Payment.', 'woocommerce'),
                        'description' => __('Requires: WooCommerce Subscriptions plugin 1.4.5 or higher .', 'woocommerce'),
                        'default' => 'no',
                        'desc_tip' => true,
                    ),
                    'merchantID' => array(
                        'title' => __('Merchant ID', 'woocommerce'),
                        'type' => 'text',
                        'description' => __('Your Cybersource Merchant ID.', 'woocommerce'),
                        'desc_tip' => true,
                    ),
                    'transactionKey' => array(
                        'title' => __('Transaction Key', 'woocommerce'),
                        'type' => 'text',
                        'description' => __('Your Cybersource Transaction Key.', 'woocommerce'),
                        'desc_tip' => true,
                    ),
                    'merchantReference' => array(
                        'title' => __('Merchant Reference Prefix', 'woocommerce'),
                        'type' => 'text',
                        'description' => __('Your Cybersource Merchant Reference Prefix. eg. MYSTORENAME #{ORDERNO}', 'woocommerce'),
                        'default' => __("#{ORDERNO}" . " " . get_bloginfo(), 'woocommerce'),
                        'desc_tip' => true,
                    ),
                    'paymentaction' => array(
                        'title' => __('Payment Action', 'woocommerce'),
                        'type' => 'select',
                        'description' => __('Choose whether you wish to capture funds immediately or authorize payment only.', 'woocommerce'),
                        'default' => 'sale',
                        'desc_tip' => true,
                        'options' => array(
                            'sale' => __('Capture', 'woocommerce'),
                            'authorization' => __('Authorize', 'woocommerce')
                        )
                    ),
                    'cards' => array(
                        'title' => __('Accepted Card Logos', 'woocommerce'),
                        'type' => 'checkbox',
                        'description' => __('You can dispaly your selected accepted credit cards & logos during checkout', 'woocommerce'),
                        'desc_tip' => true,
                        'label' => __('Enable Accepted Card Logos.', 'woocommerce'),
                        'default' => 'no'
                    ),
                    'orderstatus' => array(
                        'title' => __('Order Status', 'woocommerce'),
                        'type' => 'select',
                        'description' => 'The order status will update after payment complete.',
                        'default' => '1',
                        'desc_tip' => true,
                        'options' => array(
                            '1' => __('completed', 'woocommerce'),
                            '2' => __('processing', 'woocommerce')
                        )
                    ),
                    'returnUrl' => array(
                        'title' => __('Return Url', 'woocommerce'),
                        'type' => 'select',
                        'desc_tip' => true,
                        'options' => $this->getPages('Select Page'),
                        'description' => __('URL of success page', 'woocommerce')
                    ),
                    //CUSTOMIZATION ADDED AVS VALIDATION FEATURE
                    'avs' => array(
                        'title'             => __( 'Address Verification Service (AVS)', 'woocommerce' ),
                        'type'              => 'checkbox',
                        'label'             => __( 'Disable Simple Order (SOAP) API Address Verification Service (AVS)', 'woocommerce' ),
                        'default'           => 'no',
                        'desc_tip'          => false
                    ),
                    'debugMode' => array(
                        'title' => __('Debug Mode', 'woocommerce'),
                        'type' => 'select',
                        'description' => '',
                        'options' => array(
                            'off' => __('Off', 'woocommerce'),
                            'on' => __('On', 'woocommerce')
                        ))
                );
            }

            public function payUsingSubscriptionID($subscription_id, $order) {
                // Include the following fields in the request:
                // merchantID
                // merchantReferenceCode
                // purchaseTotals_currency
                // purchaseTotals_grandTotalAmount
                // recurringSubscriptionInfo_subscriptionID
                try {

                    //Get card type and only allow Visa, Mastercard, American Express and Discover cards to go through transaction, else return false
                    $card_number = str_replace(' ', '', woocommerce_clean($_POST['cybersource-card-number']));
                    $card_type = $this->get_cc_type_name($card_number);
                    if ($card_type != 'Visa' && $card_type != 'MasterCard' && $card_type != 'American Express' && $card_type != 'Discover') {
                        if (!empty($_POST['wc-cybersource-payment-token'])) {
                            if ($_POST['wc-cybersource-payment-token'] == 'new') {
                                //wc_add_notice('Transaction Error: Credit card type not accepted '. $_POST['wc-cybersource-payment-token'] .'.', 'error');
                                wc_add_notice('Transaction Error: Credit card type not accepted.', 'error');
                                return false;
                            }
                        }
                    }

                    $order_id = $order->id;
                    $client = new ExtendedClient(
                            $this->endpointURL, array(
                        'merchantID' => $this->merchantID,
                        'transactionKey' => $this->transactionKey
                            )
                    );

                    $request = new stdClass();
                    $request->clientLibrary = "PHP";
                    $request->clientLibraryVersion = phpversion();
                    $request->clientEnvironment = php_uname();
                    $request->merchantID = $this->merchantID;
                    $request->merchantReferenceCode = $order_id;


                    //CUSTOMIZATION ADDED AVS VALIDATION FEATURE
                    // disable Address Verification Services (AVS)
                    $businessRules = new stdClass();
                    if( $this->avs === true ){
                        $ignore_avs = false;
                    } else {
                        $ignore_avs = true;
                    }
                    $businessRules->ignoreAVSResult = $ignore_avs;
                    $request->businessRules  = $businessRules;



                    $purchaseTotals = new stdClass();
                    $purchaseTotals->currency = get_woocommerce_currency();
                    $purchaseTotals->grandTotalAmount = $order->order_total;
                    $request->purchaseTotals = $purchaseTotals;

                    $recurringSubscriptionInfo = new stdClass();
                    $recurringSubscriptionInfo->subscriptionID = $subscription_id;
                    $request->recurringSubscriptionInfo = $recurringSubscriptionInfo;

                    $ccAuthService = new stdClass();
                    $ccAuthService->run = "true";
                    $request->ccAuthService = $ccAuthService;

                    if ($this->paymentaction == 'sale') {
                        $ccCaptureService = new stdClass();
                        $ccCaptureService->run = "true";
                        $request->ccCaptureService = $ccCaptureService;
                    }

                    $reply = $client->runTransaction($request);

                    $reason = $this->reason_code($reply->reasonCode);

                    // wc_add_notice('3 Transaction Error: '.$reason.' ', 'error');
                        // return false;
                    
                    if( $this->debugMode == 'on' ){
                        $order->add_order_note( sprintf(
                                            "1 Debug %s => '%s'", $reply->reasonCode, $reason
                                    ) ); }

                    switch ($reply->decision) {
                        case 'ACCEPT':
                            switch ($this->orderstatus) {
                                case '1':
                                    $order->payment_complete();
                                    $order->update_status('completed');
                                    $order->add_order_note(
                                            sprintf(
                                                    "%s Payment Completed with Transaction Id of '%s'", $this->method_title, $reply->requestID
                                            )
                                    );

                                    break;

                                case '2':
                                default:

                                    //$order->payment_complete();
                                    $order->update_status('processing', __('Payment received and stock has been reduced- the order is awaiting fulfilment', 'woocommerce'));
                                    $order->reduce_order_stock();
                                    $order->add_order_note(
                                            sprintf(
                                                    "%s Payment Completed with Transaction Id of '%s'", $this->method_title, $reply->requestID
                                            )
                                    );


                                    break;
                            }
                            update_post_meta($order_id, 'transaction_id', $reply->requestID);
                            update_post_meta($order_id, '_transaction_id', $reply->requestID);

                            if (isset($reply->ccAuthReply->avsCode)) {
                                update_post_meta($order_id, 'cybersource_avsCode', $reply->ccAuthReply->avsCode);
                            }

                            if (isset($reply->ccAuthReply->cvCode)) {
                                update_post_meta($order_id, 'cybersource_cvCode', $reply->ccAuthReply->cvCode);
                            }

                            WC()->cart->empty_cart();

                            if ($this->returnUrl == '' || $this->returnUrl == 0) {
                                $redirect_url = $this->get_return_url($order);
                            } else {
                                $redirect_url = get_permalink($this->returnUrl);
                            }

                            return array(
                                'result' => 'success',
                                'redirect' => $redirect_url
                            );

                            break;
                        case 'ERROR':
                        case 'REJECT':

                            $order->add_order_note(
                                    sprintf(
                                            "%s Payment Failed with message: '%s'", $this->method_title, $reply->decision . " - " . $reason
                                    )
                            );

                            //OLD wc_add_notice(__('Transaction Error: Could not complete your payment', 'woocommerce'), "error");
                            wc_add_notice('Transaction Error: Could not complete your payment - '.$reason.' ', 'error');
                            return false;

                            break;
                        case 'REVIEW':

                            // Decision Manager flagged the order for review.
                            $order->update_status('on-hold', __($reason, 'woocommerce'));
                            $order->reduce_order_stock();

                            $order->add_order_note(
                                    sprintf(
                                            "%s Payment Completed with Transaction Id of '%s'", $this->method_title, $reply->decision . " - " . $reason
                                    )
                            );
	                        update_post_meta($order_id, 'transaction_id', $reply->requestID);
	                        update_post_meta($order_id, '_transaction_id', $reply->requestID);

                            WC()->cart->empty_cart();

                            if ($this->returnUrl == '' || $this->returnUrl == 0) {
                                $redirect_url = $this->get_return_url($order);
                            } else {
                                $redirect_url = get_permalink($this->returnUrl);
                            }

                            return [
                                'result' => 'success',
                                'redirect' => $redirect_url
                            ];

                            break;
                    }
                } catch (SoapFault $e) {
                    $order->add_order_note(
                            sprintf(
                                    "%s Payment Failed with message: '%s'", $this->method_title, $e->faultcode . " - " . $e->faultstring
                            )
                    );

                    //wc_add_notice(__('Transaction Error: Could not complete your payment', 'woocommerce'), "error"); // comment by KD
                    wc_add_notice(__('Transaction Error: Could not complete your payment'.$e->faultstring.'-'.$e->faultcode, 'woocommerce'), "error");
                    return false;
                }

                return false;
            }

            public function process_payment($order_id) {

                $order = new WC_Order($order_id);

               $order_user_id = $order->customer_user;

                if (!empty($order_user_id)) {
                    if (!empty($_POST['wc-cybersource-payment-token'])) {
                        if ($_POST['wc-cybersource-payment-token'] == 'new') {
                            //PAYMENT TOKENISATION
                            $subscription_id = $this->payment_tokenization($order);
                        } else {
                            $token_id = wc_clean($_POST['wc-cybersource-payment-token']);
                            $token = WC_Payment_Tokens::get($token_id);
                            
                            $token_card_type = $token->get_meta('card_type');
                            $token_card_expire_month = $token->get_meta('expiry_month');
                            $token_card_expire_year = $token->get_meta('expiry_year');
							update_post_meta($order_id, 'cybersource_token_id', $token->get_id());
							update_post_meta($order_id, 'cybersource_token', $token->get_token());
							update_post_meta($order_id, 'cybersource_token_card_type', $token_card_type);
							update_post_meta($order_id, 'cybersource_token_expire_date', $token_card_expire_month.'/'.$token_card_expire_year);
                            if (!is_null($token)) {
                                // Token user ID does not match the current user... bail out of payment processing.
									if ($token->get_user_id() != $order_user_id) {
										wc_add_notice(__('Payment error: The selected payment option is invalid', 'woocommerce'), "error");
										return;
									}

                                $subscription_id = $token->get_token();
                            } else {
                                wc_add_notice(__('Payment error: The selected payment option is invalid', 'woocommerce'), "error");
                                return;
                            }
                        }
                    }
                }
                else
                {
                    $subscription_id = $this->payment_tokenization($order);
                }

                if (isset($subscription_id) && !empty($subscription_id)) {
                    return $this->payUsingSubscriptionID($subscription_id, $order);
                } else {
                    $card_number = str_replace(' ', '', woocommerce_clean($_POST['cybersource-card-number']));
                    $card_cvc = str_replace(' ', '', woocommerce_clean($_POST['cybersource-card-cvc']));
                    $card_exp_year = str_replace(' ', '', woocommerce_clean($_POST['cybersource-card-expiry']));
                    $productinfo = sprintf(__('%s - Order %s', 'woocommerce'), esc_html(get_bloginfo()), $order_id);

                    $exp = explode("/", $card_exp_year);
                    if (count($exp) == 2) {
                        $card_exp_month = str_replace(' ', '', $exp[0]);
                        $card_exp_year = str_replace(' ', '', $exp[1]);
                    } else {
                        wc_add_notice(__('Payment error: Card expiration date is invalid', 'woocommerce'), "error");
                        return false;
                    }

                    try {
                        $client = new ExtendedClient(
                                $this->endpointURL, array(
                            'merchantID' => $this->merchantID,
                            'transactionKey' => $this->transactionKey
                                )
                        );

                        if (!empty($this->merchantReference)) {
                            $reference = str_replace('{ORDERNO}', $order_id, $this->merchantReference);
                        } else {
                            $reference = $productinfo;
                        }

                        $request = new stdClass();
                        $request->clientLibrary = "PHP";
                        $request->clientLibraryVersion = phpversion();
                        $request->clientEnvironment = php_uname();
                        $request->merchantID = $this->merchantID;
                        $request->merchantReferenceCode = $order_id;



                        //CUSTOMIZATION ADDED AVS VALIDATION FEATURE
                        // disable Address Verification Services (AVS)
                        $businessRules = new stdClass();
                        if( $this->avs === true ){
                            $ignore_avs = false;
                        } else {
                            $ignore_avs = true;
                        }
                        $businessRules->ignoreAVSResult = $ignore_avs;
                        $request->businessRules  = $businessRules;



                        if ($this->paymentaction == 'sale') {
                            $ccAuthService = new stdClass();
                            $ccAuthService->run = "true";
                            $request->ccAuthService = $ccAuthService;
                            $ccCaptureService = new stdClass();
                            $ccCaptureService->run = "true";
                            $request->ccCaptureService = $ccCaptureService;
                        } else {
                            $ccAuthService = new stdClass();
                            $ccAuthService->run = "true";
                            $request->ccAuthService = $ccAuthService;
                        }

                        $item0 = new stdClass();
                        $item0->unitPrice = $order->order_total;
                        $item0->productName = $productinfo;
                        $item0->quantity = 1;
                        $item0->id = 0;
                        $request->item = array($item0);

                        $purchaseTotals = new stdClass();
                        $purchaseTotals->currency = get_woocommerce_currency();
                        $request->purchaseTotals = $purchaseTotals;

                        $billTo = new stdClass();
                        $billTo->firstName = __($order->billing_first_name, 'woocommerce');
                        $billTo->lastName = __($order->billing_last_name, 'woocommerce');
                        $billTo->street1 = __($order->billing_address_1, 'woocommerce');
                        $billTo->city = __($order->billing_city, 'woocommerce');
                        $billTo->state = __($order->billing_state, 'woocommerce');
                        $billTo->postalCode = __($order->billing_postcode, 'woocommerce');
                        $billTo->country = __($order->billing_country, 'woocommerce');
                        $billTo->email = __($order->billing_email, 'woocommerce');
                        $billTo->ipAddress = !empty($_SERVER['HTTP_X_FORWARD_FOR']) ? $_SERVER['HTTP_X_FORWARD_FOR'] : $_SERVER['REMOTE_ADDR'];
                        $request->billTo = $billTo;

                        $card = new stdClass();
                        $card->accountNumber = $card_number;
                        $card->expirationMonth = $card_exp_month;
                        $card->expirationYear = '20' . $card_exp_year;
                        $cardtype = $this->get_cc_type($card_number);

                        if (empty($cardtype)) {
                            $order->add_order_note(
                                    sprintf(
                                            "%s Payment Failed with message: '%s'", $this->method_title, "Could not determine the credit card type."
                                    )
                            );

                            wc_add_notice(__('Transaction Error: Could not determine the credit card type.', 'woocommerce'), "error");
                            return false;
                        } else {
                            $card->cardType = $this->get_cc_type($card_number);
                        }

                        $card->cvNumber = $card_cvc;
                        $request->card = $card;

                        $reply = $client->runTransaction($request);

                        $reason = $this->reason_code($reply->reasonCode);

                        // wc_add_notice('4 Transaction Error: '.$reason.' ', 'error');
                        // return false;

                        if( $this->debugMode == 'on' ){
                        $order->add_order_note( sprintf(
                                            "2 Debug %s => '%s'", $reply->reasonCode, $reason
                                    ) ); }

                        //TODO ADD HARD ERROR IF $reason = "The authorization request was approved by the issuing bank but declined by CyberSorce because it did not pass the CVN check."
                        // if ($reason == "The authorization request was approved by the issuing bank but declined by CyberSorce because it did not pass the CVN check.") {
                        //     wc_add_notice(__('Transaction Error: Invalid CVN/CVV', 'woocommerce'), "error");
                        //     wc_add_notice('Transaction Error Code: '.$reply->reasonCode.' ', 'error');
                        //     return false;
                        // }

                        switch ($reply->decision) {
                            case 'ACCEPT':
                                switch ($this->orderstatus) {
                                    case '1':
                                        $order->payment_complete();
                                        $order->update_status('completed');
                                        $order->add_order_note(
                                                sprintf(
                                                        "%s Payment Completed with Transaction Id of '%s'", $this->method_title, $reply->requestID
                                                )
                                        );
                                        update_post_meta($order_id, 'transaction_id', $reply->requestID);
                                        update_post_meta($order_id, '_transaction_id', $reply->requestID);
                                        break;

                                    case '2':
                                    default:

                                        //$order->payment_complete();
                                        $order->update_status('processing', __('Payment received and stock has been reduced- the order is awaiting fulfilment', 'woocommerce'));
                                        $order->reduce_order_stock();
                                        $order->add_order_note(
                                                sprintf(
                                                        "%s Payment Completed with Transaction Id of '%s'", $this->method_title, $reply->requestID
                                                )
                                        );
                                        update_post_meta($order_id, 'transaction_id', $reply->requestID);
                                        update_post_meta($order_id, '_transaction_id', $reply->requestID);

                                        break;
                                }

                                if (class_exists('WC_Subscriptions_Order')) {
                                    if (WC_Subscriptions_Order::order_contains_subscription($order_id)) {
                                        update_post_meta($order_id, $this->id . '_merchantReferenceCode', $reply->merchantReferenceCode);
                                        update_post_meta($order_id, $this->id . '_subscriptionID', $reply->paySubscriptionCreateReply->subscriptionID);
                                    }
                                }

                                WC()->cart->empty_cart();

                                if ($this->returnUrl == '' || $this->returnUrl == 0) {
                                    $redirect_url = $this->get_return_url($order);
                                } else {
                                    $redirect_url = get_permalink($this->returnUrl);
                                }

                                return array(
                                    'result' => 'success',
                                    'redirect' => $redirect_url
                                );

                                break;
                            case 'ERROR':
                            case 'REJECT':

                                $order->add_order_note(
                                        sprintf(
                                                "%s Payment Failed with message: '%s'", $this->method_title, $reply->decision . " - " . $reason
                                        )
                                );

                                //OLD wc_add_notice(__('Transaction Error: Could not complete your payment', 'woocommerce'), "error");
                                wc_add_notice('Transaction Error: Could not complete your payment - '.$reason.' - card type: '.$cardtype.'.', 'error');
                                return false;

                                break;
                            case 'REVIEW':

                                // Decision Manager flagged the order for review.
                                $order->update_status('on-hold', __($reason, 'woocommerce'));
                                $order->reduce_order_stock();

                                $order->add_order_note(
                                        sprintf(
                                                "%s Payment Completed with Transaction Id of '%s'", $this->method_title, $reply->decision . " - " . $reason
                                        )
                                );

	                            update_post_meta($order_id, 'transaction_id', $reply->requestID);
	                            update_post_meta($order_id, '_transaction_id', $reply->requestID);
	
                                WC()->cart->empty_cart();

                                if ($this->returnUrl == '' || $this->returnUrl == 0) {
                                    $redirect_url = $this->get_return_url($order);
                                } else {
                                    $redirect_url = get_permalink($this->returnUrl);
                                }

                                return array(
                                    'result' => 'success',
                                    'redirect' => $redirect_url
                                );

                                break;
                        }
                    } catch (SoapFault $e) {

                        $order->add_order_note(
                                sprintf(
                                        "%s Payment Failed with message: '%s'", $this->method_title, $e->faultcode . " - " . $e->faultstring
                                )
                        );

                        wc_add_notice(__('Transaction Error: Could not complete your payment', 'woocommerce'), "error");
                        return false;
                    }
                }
            }

            //FUNCTION FOR PAYMENT TOKENIZATION

            private function payment_tokenization($order) {

                try {
                    $referenceCode = $order->id;

                    $client = new ExtendedClient(
                            $this->endpointURL, array(
                        'merchantID' => $this->merchantID,
                        'transactionKey' => $this->transactionKey
                            )
                    );

                    $request = new stdClass();
                    $request->clientLibrary = "PHP";
                    $request->clientLibraryVersion = phpversion();
                    $request->clientEnvironment = php_uname();
                    $request->merchantID = $this->merchantID;
                    $request->merchantReferenceCode = $referenceCode;



                    //CUSTOMIZATION ADDED AVS VALIDATION FEATURE
                    // disable Address Verification Services (AVS)
                    $businessRules = new stdClass();
                    if( $this->avs === true ){
                        $ignore_avs = false;
                    } else {
                        $ignore_avs = true;
                    }
                    $businessRules->ignoreAVSResult = $ignore_avs;
                    $request->businessRules  = $businessRules;



                    $paySubscriptionCreateService = new stdClass();
                    $paySubscriptionCreateService->run = 'true';
                    $request->paySubscriptionCreateService = $paySubscriptionCreateService;

                    $billTo = new stdClass();
                    $billTo->firstName = __($order->billing_first_name, 'woocommerce');
                    $billTo->lastName = __($order->billing_last_name, 'woocommerce');
                    $billTo->street1 = __($order->billing_address_1, 'woocommerce');
                    $billTo->city = __($order->billing_city, 'woocommerce');
                    $billTo->state = __($order->billing_state, 'woocommerce');
                    $billTo->postalCode = __($order->billing_postcode, 'woocommerce');
                    $billTo->country = __($order->billing_country, 'woocommerce');
                    $billTo->email = __($order->billing_email, 'woocommerce');
                    $billTo->ipAddress = !empty($_SERVER['HTTP_X_FORWARD_FOR']) ? $_SERVER['HTTP_X_FORWARD_FOR'] : $_SERVER['REMOTE_ADDR'];

                    $request->billTo = $billTo;

                    $card_number = str_replace(' ', '', woocommerce_clean($_POST['cybersource-card-number']));
                    $card_cvc = str_replace(' ', '', woocommerce_clean($_POST['cybersource-card-cvc']));
                    $card_exp_year = str_replace(' ', '', woocommerce_clean($_POST['cybersource-card-expiry']));
                    $productinfo = sprintf(__('%s - Order %s', 'woocommerce'), esc_html(get_bloginfo()), $order->id);

                    $exp = explode("/", $card_exp_year);
                    if (count($exp) == 2) {
                        $card_exp_month = str_replace(' ', '', $exp[0]);
                        $card_exp_year = str_replace(' ', '', $exp[1]);
                    } else {
                        wc_add_notice(__('Payment error: Card expiration date is invalid', 'woocommerce'), "error");
                        return false;
                    }

                    $card = new stdClass();
                    $card->accountNumber = $card_number;
                    $card->expirationMonth = $card_exp_month;
                    $card->expirationYear = '20' . $card_exp_year;

                    $cardtype = $this->get_cc_type($card_number);

                    if (empty($cardtype)) {
                        $order->add_order_note(
                                sprintf(
                                        "%s Payment Failed with message: '%s'", $this->method_title, "Could not determine the credit card type."
                                )
                        );
                        wc_add_notice(__('Transaction Error: Could not determine the credit card type.', 'woocommerce'), "error");
                        return false;
                    } else {
                        $card->cardType = $cardtype;
                    }

                    //ADDED BY TARUN
                    $card->cvNumber = $card_cvc;
                    //ADDED BY TARUN

                    $request->card = $card;
                    $purchaseTotals = new stdClass();
                    $purchaseTotals->currency = get_woocommerce_currency();
                    $request->purchaseTotals = $purchaseTotals;

                    $recurringSubscriptionInfo = new stdClass();
                    $recurringSubscriptionInfo->frequency = 'on-demand';
                    $request->recurringSubscriptionInfo = $recurringSubscriptionInfo;

                    $reply = $client->runTransaction($request);

                    $order_user_id = $order->customer_user;
                    $reason = $this->reason_code($reply->reasonCode);

                    // wc_add_notice('5 Transaction Error: '.$reason.' ', 'error');
                        // return false;

                    if( $this->debugMode == 'on' ){
                        $order->add_order_note( sprintf(
                                            "3 Debug %s => '%s'", $reply->reasonCode, $reason
                                    ) ); }

                    $cardTypeName= $this->get_cc_type_name($card_number);

                    switch ($reply->decision) {
                        case 'ACCEPT':
                            if (isset($reply->paySubscriptionCreateReply->subscriptionID) && !empty($reply->paySubscriptionCreateReply->subscriptionID)) {
                                $subscription_id = $reply->paySubscriptionCreateReply->subscriptionID;
                                if (!empty($order_user_id)) {
                                   update_user_meta($order_user_id, "cybersource_subscription_id", $subscription_id);
								  // update_user_meta($order_user_id, "cybersource_subscription_id_", $subscription_id);
								   update_user_meta($order_user_id, "card_type", $cardtype);
								   update_user_meta($order_user_id, "card_exp_date", $exp);
//
////                                    update_user_meta($order_user_id, "cybersource_subscription_id_" . $subscription_id, [
////                                        'card_number' => substr($card_number, -4, 4),
////                                        'card_type' => $cardtype,
////                                        'card_exp_date' => $exp
////                                    ]);

                                    $token = new WC_Payment_Token_CC();
                                    $token->set_token($subscription_id);
                                    $token->set_gateway_id($this->id); // `$this->id` references the gateway ID set in `__construct`

                                    $token->set_card_type($cardTypeName);

                                    $token->set_last4(substr($card_number, -4, 4));
                                    $token->set_expiry_month($card_exp_month);
                                    $token->set_expiry_year('20' . $card_exp_year);

										$token->set_user_id($order_user_id);
                                    $token->save();
                                    
                                update_post_meta($referenceCode, 'cybersource_token_id', $token->get_id());
                                }
                            //
                                update_post_meta($referenceCode, 'cybersource_token', $subscription_id);
                                update_post_meta($referenceCode, 'cybersource_token_card_type', $cardTypeName);
                                update_post_meta($referenceCode, 'cybersource_token_expire_date', $card_exp_month.'/20'.$card_exp_year);

                                return $subscription_id;
                            }
                            break;
                        case 'ERROR':
							$order->add_order_note(
                                    sprintf(
                                            "%s PaymentError with message: '%s'", $this->method_title, $reply->decision . " - " . $reason
                                    )
                            );
                        case 'REVIEW':
						$order->add_order_note(
                                    sprintf(
                                            "%s Payment Review with message: '%s'", $this->method_title, $reply->decision . " - " . $reason
                                    )
                            );
                        case 'REJECT':
                            $order->add_order_note(
                                    sprintf(
                                            "%s Payment Failed with message: '%s'", $this->method_title, $reply->decision . " - " . $reason
                                    )
                            );

                            //OLD wc_add_notice(__('Transaction Error: Could not complete your payment', 'woocommerce'), "error");
                            wc_add_notice('Transaction Error: Could not complete your payment - '.$reason.' - card type: '.$cardtype.'.', 'error');
                            break;
                    }
                } catch (SoapFault $e) {
                    $order->add_order_note(
                            sprintf(
                                    "%s Payment Failed with message: '%s'", $this->method_title, $e->faultcode . " - " . $e->faultstring
                            )
                    );

                    wc_add_notice(__('Transaction Error: Could not complete your payment', 'woocommerce'), "error");
                }
                return false;
            }

            public function admin_options() {
                if ($this->mode == 'p' && get_option('woocommerce_force_ssl_checkout') == 'no' && $this->enabled == 'yes') :
                    ?>
                    <div class="inline error">
                        <p>
                            <?php _e(sprintf(__('%s Sandbox testing is disabled and can performe live transactions but the <a href="%s">force SSL option</a> is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.', 'woothemes'), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')), 'woocommerce'); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <h3><?php _e($this->method_title . ' Payment Gateway', 'woocommerce'); ?></h3>
                <p><?php _e('Merchant Details.', 'woocommerce'); ?></p>
                <table class="form-table"><?php
                    // Generate the HTML For the settings form.
                    $this->generate_settings_html();
                    ?>
                </table>
                <!--/.form-table-->
                <?php
            }

            public function validate_fields() {
                if (!empty($_POST['wc-cybersource-payment-token'])) {
                    if ($_POST['wc-cybersource-payment-token'] == 'new') {
                        $card_number = woocommerce_clean($_POST['cybersource-card-number']);
                        $card_number = str_replace(' ', '', $card_number);
                        $card_cvc = woocommerce_clean($_POST['cybersource-card-cvc']);
                        $card_exp_year = woocommerce_clean($_POST['cybersource-card-expiry']);

                        if (empty($card_number) || !ctype_digit($card_number)) {
                            wc_add_notice(__('Payment error: Card number is invalid', 'woocommerce'), "error");
                            return false;
                        }

                        if (!ctype_digit($card_cvc)) {
                            wc_add_notice(__('Payment error: Card security code is invalid (only digits are allowed)', 'woocommerce'), "error");
                            return false;
                        }

                        if (strlen($card_cvc) > 4) {
                            wc_add_notice(__('Payment error: Card security code is invalid (wrong length)', 'woocommerce'), "error");
                            return false;
                        }

                        if (!empty($card_exp_year)) {
                            $exp = explode("/", $card_exp_year);
                            if (count($exp) == 2) {
                                $card_exp_month = str_replace(' ', '', $exp[0]);
                                $card_exp_year = str_replace(' ', '', $exp[1]);
                                if (
                                        !ctype_digit($card_exp_month) ||
                                        !ctype_digit($card_exp_year) ||
                                        $card_exp_month > 12 ||
                                        $card_exp_month < 1 ||
                                        $card_exp_year < date('y') ||
                                        $card_exp_year > date('y') + 20
                                ) {
                                    wc_add_notice(__('Payment error: Card expiration date is invalid', 'woocommerce'), "error");
                                    return;
                                }
                            } else {
                                wc_add_notice(__('Payment error: Card expiration date is invalid', 'woocommerce'), "error");
                                return;
                            }
                        }
                    } else {
                        $token_id = wc_clean($_POST['wc-cybersource-payment-token']);
                        $token = WC_Payment_Tokens::get($token_id);
                        // Token user ID does not match the current user... bail out of payment processing.
                        if ($token->get_user_id() !== get_current_user_id()) {
                            wc_add_notice(__('Payment error: The selected payment option is invalid', 'woocommerce'), "error");
                            return;
                        }
                    }
                }
                return true;
            }

            public function payment_fields() {
                if ($this->mode == 's') {
                    echo '<p>';
                    echo __('TEST MODE/SANDBOX ENABLED', 'woocommerce');
                    echo '<br>';
                    echo __('TEST CARDS:', 'woocommerce');
                    echo '<br>';
                    echo __('Visa: 4111 1111 1111 1111', 'woocommerce');
                    echo '<br>';
                    echo __('MasterCard: 5555 5555 5555 4444', 'woocommerce');
                    echo '<br>';
                    echo __('American Express: 3782 8224 6310 005', 'woocommerce');
                    echo '<br>';
                    echo __('JCB: 3566 1111 1111 1113', 'woocommerce');
                    echo '<br>';
                    echo __('Diners Club: 3800 000000 0006', 'woocommerce');
                    echo '<p>';
                }


                if ($this->description) {
                    echo wpautop(wptexturize($this->description));
                }


                if ($this->supports('tokenization') && is_checkout()) {
                    $this->tokenization_script();
                    $this->saved_payment_methods();
                    $this->form();
//                    $this->save_payment_method_checkbox();
                } else {
                    $this->form();
                }
            }

            public function showMessage($content) {
                $html = '';
                $html .= '<div class="box ' . $this->msg['class'] . '-box">';
                $html .= $this->msg['message'];
                $html .= '</div>';
                $html .= $content;

                return $html;
            }

            public function getPages($title = false, $indent = true) {
                $wp_pages = get_pages('sort_column=menu_order');
                $page_list = array();
                if ($title)
                    $page_list[] = $title;
                foreach ($wp_pages as $page) {
                    $prefix = '';
                    if ($indent) {
                        $has_parent = $page->post_parent;
                        while ($has_parent) {
                            $prefix .= ' - ';
                            $next_page = get_page($has_parent);
                            $has_parent = $next_page->post_parent;
                        }
                    }
                    $page_list[$page->ID] = $prefix . $page->post_title;
                }
                return $page_list;
            }

            public function reason_code($code) {

                $reason_codes['100'] = __("Successful transaction.", "woocommerce");
                $reason_codes['101'] = __("The request is missing one or more required fields. ", "woocommerce");
                $reason_codes['102'] = __("One or more fields in the request contains invalid data.", "woocommerce");
                $reason_codes['104'] = __("The merchant reference code for this authorization request matches the merchant reference code of another authorization request that you sent within the past 15 minutes.", "woocommerce");
                $reason_codes['110'] = __("Only a partial amount was approved.", "woocommerce");
                $reason_codes['150'] = __(sprintf("General system failure. Recurring Billing or Secure Storage service is not enabled for the merchant please contact the %s support", $this->method_title), "woocommerce");
                $reason_codes['151'] = __("The request was received but there was a server timeout. This error does not include timeouts between the client and the server.", "woocommerce");
                $reason_codes['152'] = __("The request was received, but a service did not finish running in time. ", "woocommerce");
                $reason_codes['200'] = __("The authorization request was approved by the issuing bank but declined by CyberSource because it did not pass the Address Verification System (AVS) check.", "woocommerce");
                $reason_codes['201'] = __("The issuing bank has questions about the request. You do not receive an authorization code programmatically, but you might receive one verbally by calling the processor.", "woocommerce");
                $reason_codes['202'] = __("Expired card. You might also receive this value if the expiration date you provided does not match the date the issuing bank has on file.", "woocommerce");
                $reason_codes['203'] = __("General decline of the card. No other information was provided by the issuing bank.", "woocommerce");
                $reason_codes['204'] = __("Insufficient funds in the account.", "woocommerce");
                $reason_codes['205'] = __("Stolen or lost card.", "woocommerce");
                $reason_codes['207'] = __("Issuing bank unavailable.", "woocommerce");
                $reason_codes['208'] = __("Inactive card or card not authorized for card-not-present transactions.", "woocommerce");
                $reason_codes['209'] = __("CVN did not match.", "woocommerce");
                $reason_codes['210'] = __("The card has reached the credit limit. ", "woocommerce");
                $reason_codes['211'] = __("Invalid CVN.", "woocommerce");
                $reason_codes['221'] = __("The customer matched an entry on the processor�s negative file. ", "woocommerce");
                $reason_codes['230'] = __("The authorization request was approved by the issuing bank but declined by CyberSorce because it did not pass the CVN check.", "woocommerce");
                $reason_codes['231'] = __("Invalid account number.", "woocommerce");
                $reason_codes['232'] = __("The card type is not accepted by the payment processor.", "woocommerce");
                $reason_codes['233'] = __("General decline by the processor.", "woocommerce");
                $reason_codes['234'] = __("There is a problem with the information in your CyberSource account.", "woocommerce");
                $reason_codes['235'] = __("The requested capture amount exceeds the originally authorized amount. ", "woocommerce");
                $reason_codes['236'] = __("Processor failure. ", "woocommerce");
                $reason_codes['237'] = __("The authorization has already been reversed.", "woocommerce");
                $reason_codes['238'] = __("The authorization has already been captured.", "woocommerce");
                $reason_codes['239'] = __("The requested transaction amount must match the previous transaction amount. ", "woocommerce");
                $reason_codes['240'] = __("The card type sent is invalid or does not correlate with the credit card number.", "woocommerce");
                $reason_codes['241'] = __("The request ID is invalid.", "woocommerce");
                $reason_codes['242'] = __("You requested a capture, but there is no corresponding, unused authorization record. Occurs if there was not a previously successful authorization request or if the previously successful authorization has already been used by another capture request.", "woocommerce");
                $reason_codes['243'] = __("The transaction has already been settled or reversed.", "woocommerce");
                $reason_codes['246'] = __("The capture or credit is not voidable because the capture or credit information has already been submitted to your processor. or You requested a void for a type of transaction that cannot be voided.
Possible action: No action required.", "woocommerce");
                $reason_codes['247'] = __("You requested a credit for a capture that was previously voided.", "woocommerce");
                $reason_codes['250'] = __("The request was received, but there was a timeout at the payment processor.", "woocommerce");
                $reason_codes['254'] = __("Stand-alone credits are not allowed.", "woocommerce");

                if (!empty($code)) {
                    $result = $reason_codes[$code];
                } else {
                    $result = 'Unknown Error!';
                }

                return $result;
            }

            public function get_cc_type($cardnum) {

                /* Visa */

                if (preg_match("/^4(\d{12}|\d{15})$/", $cardnum)) {
                    $type = '001';

                    /* MasterCard */
                } else if (preg_match("/^5[1-5]\d{14}$/", $cardnum)) {
                    $type = '002';

                    /* American Express */
                } else if (preg_match("/^3[47]\d{13}$/", $cardnum)) {
                    $type = '003';

                    /* Discover */
                } else if (preg_match("/^6011\d{12}$/", $cardnum)) {
                    $type = '004'; // Discover

                    /* Diners Club */
                } else if (preg_match("/^[300-305]\d{11}$/", $cardnum) ||
                        preg_match("/^3[68]\d{12}$/", $cardnum)) {
                    $type = '005';

                    /* EnRoute */
                } else if (preg_match("/^2(014|149)\d{11}$/", $cardnum)) {
                    $type = '014';

                    /* JCB */
                } else if (preg_match("/^3\d{15}$/", $cardnum) ||
                        preg_match("/^(2131|1800)\d{11}$/", $cardnum)) {
                    $type = '007';

                    /* Maestro */
                } else if (preg_match("/^(?:5020|6\\d{3})\\d{12}$/", $cardnum)) {
                    $type = '024';

                    /* Visa Electron */
                } else if (preg_match("/^4(17500|917[0-9][0-9]|913[0-9][0-9]|508[0-9][0-9]|844[0-9][0-9])\d{10}$/", $cardnum)) {
                    $type = '033';

                    /* Laser */
                } else if (preg_match("/^(6304|670[69]|6771)[0-9]{12,15}$/", $cardnum)) {
                    $type = '035';

                    /* Carte Blanche */
                } else if (preg_match("/^389[0-9]{11}$/", $cardnum)) {
                    $type = '006';

                    /* Dankort */
                } else if (preg_match("/^5019\d{12}$/", $cardnum)) {
                    $type = '034';
                } else {
                    $type = '';
                }


                return $type;
            }

            public function get_cc_type_name($cardnum) {

                /* Visa */

                if (preg_match("/^4(\d{12}|\d{15})$/", $cardnum)) {
                    $type = 'Visa';

                    /* MasterCard */
                } else if (preg_match("/^5[1-5]\d{14}$/", $cardnum)) {
                    $type = 'MasterCard';

                    /* American Express */
                } else if (preg_match("/^3[47]\d{13}$/", $cardnum)) {
                    $type = 'American Express';

                    /* Discover */
                } else if (preg_match("/^6011\d{12}$/", $cardnum)) {
                    $type = 'Discover'; // Discover

                    /* Diners Club */
                } else if (preg_match("/^[300-305]\d{11}$/", $cardnum) ||
                        preg_match("/^3[68]\d{12}$/", $cardnum)) {
                    $type = 'Diners Club';

                    /* EnRoute */
                } else if (preg_match("/^2(014|149)\d{11}$/", $cardnum)) {
                    $type = 'EnRoute';

                    /* JCB */
                } else if (preg_match("/^3\d{15}$/", $cardnum) ||
                        preg_match("/^(2131|1800)\d{11}$/", $cardnum)) {
                    $type = 'JCB';

                    /* Maestro */
                } else if (preg_match("/^(?:5020|6\\d{3})\\d{12}$/", $cardnum)) {
                    $type = 'Maestro';

                    /* Visa Electron */
                } else if (preg_match("/^4(17500|917[0-9][0-9]|913[0-9][0-9]|508[0-9][0-9]|844[0-9][0-9])\d{10}$/", $cardnum)) {
                    $type = 'Visa Electron';

                    /* Laser */
                } else if (preg_match("/^(6304|670[69]|6771)[0-9]{12,15}$/", $cardnum)) {
                    $type = 'Laser';

                    /* Carte Blanche */
                } else if (preg_match("/^389[0-9]{11}$/", $cardnum)) {
                    $type = 'Carte Blanche';

                    /* Dankort */
                } else if (preg_match("/^5019\d{12}$/", $cardnum)) {
                    $type = 'Dankort';
                } else {
                    $type = '';
                }


                return $type;
            }

        }

        function woocommerce_add_cybersource($methods) {
            $methods[] = 'WC_API_Cybersource';
            return $methods;
        }

        add_filter('woocommerce_payment_gateways', 'woocommerce_add_cybersource');

    }

    add_action('plugins_loaded', 'woocommerce_api_cybersource_init', 0);

//    add_action('init',array('WC_API_Cybersource','scheduled_subscription_payment'));
