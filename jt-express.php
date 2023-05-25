<?php /*
   Plugin Name: Jt express
   Description: Jt express
   Author: Thanh Nam
   Version: 1.0
   */



    function register_shipping_order_status() {
        register_post_status( 'wc-shipping', array(
            'label'                     => 'Shipping',
            'public'                    => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list'    => true,
            'exclude_from_search'       => false,
            'label_count'               => _n_noop( 'Shipping (%s)', 'Shipping (%s)' )
        ) );
    }
    // Add custom status to order status list
    function add_shipping_to_order_statuses( $order_statuses ) {
        $new_order_statuses = array();
        foreach ( $order_statuses as $key => $status ) {
            $new_order_statuses[ $key ] = $status;
            if ( 'wc-on-hold' === $key ) {
                $new_order_statuses['wc-shipping'] = 'Shipping';
            }
        }
        return $new_order_statuses;
    }
    add_action( 'init', 'register_shipping_order_status' );
    add_filter( 'wc_order_statuses', 'add_shipping_to_order_statuses' );


    function register_cancel_shipping_order_status() {
        register_post_status( 'wc-cancel-shipping', array(
            'label'                     => 'Cancel shipping',
            'public'                    => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list'    => true,
            'exclude_from_search'       => false,
            'label_count'               => _n_noop( 'Cancel shipping (%s)', 'Cancel shipping (%s)' )
        ) );
    }
    // Add custom status to order status list
    function add_cancel_shipping_to_order_statuses( $order_statuses ) {
        $new_order_statuses = array();
        foreach ( $order_statuses as $key => $status ) {
            $new_order_statuses[ $key ] = $status;
            if ( 'wc-shipping' === $key ) {
                $new_order_statuses['wc-cancel-shipping'] = 'Cancel shipping';
            }
        }
        return $new_order_statuses;
    }
    add_action( 'init', 'register_cancel_shipping_order_status' );
    add_filter( 'wc_order_statuses', 'add_cancel_shipping_to_order_statuses' );

    //add_action( 'woocommerce_process_shop_order_meta', 'woocommerce_process_shop_order', 10, 2 );
    //function woocommerce_process_shop_order ( $order_id, $order ) {
    //    $order =  // Order id
    //    var_dump($order);
    //    die();
    //}

    add_filter('woocommerce_get_country_locale', function($locales){
        foreach ($locales as $key => $value) {
            $locales[$key]['postcode']['required'] = true;
        }
        return $locales;
    });

    add_action('woocommerce_order_status_changed', 'so_status_completed', 10, 3);

    function so_status_completed($order_id, $old_status, $new_status)
    {
        $order = new WC_Order($order_id);
        $options = get_option( 'jt_options' );
        $merchart_code = isset($options['jt_merchant_code']) ? $options['jt_merchant_code'] : '';
        $service_code = isset($options['jt_service_code']) ? $options['jt_service_code'] : '';
        $email = isset($options['jt_email']) ? $options['jt_email'] : '';
        $password = isset($options['jt_password']) ? $options['jt_password'] : '';
        $prefix = isset($options['jt_prefix']) ? $options['jt_prefix'] : '';
        if ($new_status == 'shipping' || $new_status == 'cancel-shipping') {
            if ($email && $password && $merchart_code && $service_code && $prefix) {
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => 'http://edishops.jtexpress.sg/jts-service-doorstep/api/gateway/v1/auth/login',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_USERPWD => $email .':'. $password
                ));
                $response = curl_exec($curl);
                curl_close($curl);
                $accessToken = '';
                if(json_decode($response)) {
                    $accessToken = json_decode($response)->token;
                }

                $referenceNumber = $prefix . $order_id;
                $contactName = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();
                $phoneNumber = !empty($order->get_shipping_phone()) ? $order->get_shipping_phone() : $order->get_billing_phone();
                $address = $order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_shipping_address_2();
                $fullAddress = $address . ', ' . $order->get_shipping_city() . ', ' . $order->get_shipping_country();
                $email = $order->get_billing_email();
                $postCode = !empty($order->get_shipping_postcode()) ? $order->get_shipping_postcode() : $order->get_billing_postcode();
                $totalTax = (float)$order->get_total();
                $isFullData = true;
                $itemDetail = '"item_details": [';
                $key = 0;
                foreach($order->get_items() as $item_id => $item_values){
                    $key++;
                    $product_id = $item_values['product_id'];
                    $product_detail = wc_get_product($product_id);
                    if (empty($product_detail->get_length()) || empty($product_detail->get_width()) || empty($product_detail->get_height()) || empty($product_detail->get_weight())) {
                        $isFullData = false;
                    }
                    $itemDetail .= '{
                    "length": "'.$product_detail->get_length().'",
                    "width": "'.$product_detail->get_width().'",
                    "height": "'.$product_detail->get_height().'",
                    "weight": '.$product_detail->get_weight() * 1000 .',
                    "weight_unit": "G",
                    "quantity": '.$item_values->get_quantity().',
                    "description": "'.$item_values->get_name().'"
                }';

                    if ($key < count($order->get_items())) {
                        $itemDetail .= ',';
                    }
                }
                $itemDetail .= '],';

                if ($new_status == 'shipping') {
                    if ($accessToken && $contactName && $phoneNumber && $fullAddress && $email && $postCode && $isFullData) {
                        $curlCreate = curl_init();
                        curl_setopt_array($curlCreate, array(
                            CURLOPT_URL => 'http://edishops.jtexpress.sg/jts-service-doorstep/api/gateway/v1/deliveries',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING => '',
                            CURLOPT_MAXREDIRS => 10,
                            CURLOPT_TIMEOUT => 0,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST => 'POST',
                            CURLOPT_POSTFIELDS =>'{
                                                    "reference_number": "'.$referenceNumber.'",
                                                    "merchant_code":"'.$merchart_code.'",
                                                    "service_code": "'.$service_code.'",
                                                    "consignee_details": {
                                                        "contact_name": "'.$contactName. '",
                                                        "phone_number": "'.$phoneNumber.'",
                                                        "address": "'.$fullAddress.'",
                                                        "email": "'.$email.'",
                                                        "unit": "#22-181",
                                                        "postcode": "'.$postCode.'",
                                                        "country_code": "SG"
                                                    },
                                                    '.$itemDetail.'
                                                       "cod": {
                                                            "amount": "'.$totalTax.'",
                                                            "currency": "SGD"
                                                        }
                                                }',
                            CURLOPT_HTTPHEADER => array(
                                'Content-Type: application/json',
                                'Authorization: JWT ' . $accessToken
                            ),
                        ));
                        $responseCreate = curl_exec($curlCreate);
                        curl_close($curlCreate);
                        $statusCreate = json_decode($responseCreate)->status;
                        if ($statusCreate == 400) {
                            $order->update_status('on-hold', 'Update faild, This order has already been created or invalid shipping information, you cannot recreate this order!  ');
                        }
                    } else {
                        $order->update_status($old_status, 'Update faild, please update the product shipping information before changing the order status!  ');
                    }
                }

                if ($new_status == 'cancel-shipping') {
                    $curlStatus = curl_init();
                    curl_setopt_array($curlStatus, array(
                        CURLOPT_URL => 'http://edishops.jtexpress.sg/jts-service-doorstep/api/gateway/v1/track/' . $referenceNumber,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => '',
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 0,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => 'GET',
                        CURLOPT_HTTPHEADER => array(
                            'Authorization: JWT ' . $accessToken
                        ),
                    ));

                    $responseStatus = curl_exec($curlStatus);

                    curl_close($curlStatus);
                    $status = '';
                    if ($responseStatus) {
                        $status = json_decode($responseStatus)->status;
                    }
                    if (!empty($status)) {
                        if ($status == 'PENDING_PICKUP') {
                            $curlCancel = curl_init();
                            curl_setopt_array($curlCancel, array(
                                CURLOPT_URL => 'http://edishops.jtexpress.sg/jts-service-doorstep/api/gateway/v1/deliveries/operation',
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_ENCODING => '',
                                CURLOPT_MAXREDIRS => 10,
                                CURLOPT_TIMEOUT => 0,
                                CURLOPT_FOLLOWLOCATION => true,
                                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                                CURLOPT_CUSTOMREQUEST => 'POST',
                                CURLOPT_POSTFIELDS =>'{
                                                  "data": {
                                                    "ids": [
                                                      "'.$referenceNumber.'"
                                                    ],
                                                    "reason": "Admin cancels the order"
                                                  },
                                                  "type": "CANCEL"
                                                }',
                                CURLOPT_HTTPHEADER => array(
                                    'Content-Type: application/json',
                                    'Authorization: JWT ' . $accessToken
                                ),
                            ));

                            $responseCancel = curl_exec($curlCancel);
                            curl_close($curlCancel);
                        } else {
                            $order->update_status('on-hold', 'Update faild, this order cannot be canceled!  ');
                        }
                    } else {
                        $order->update_status($old_status, 'Update faild, this order does not exist!  ');
                    }
                }
            } else {
                $order->update_status($old_status, 'Update faild, please check your setting in J&T Settings before change status order to shipping!  ');
            }
        }
    }


    class MySettingsPage{
        /**
         * Holds the values to be used in the fields callbacks
         */
        private $options;

        /**
         * Start up
         */
        public function __construct()
        {
            add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
            add_action( 'admin_init', array( $this, 'page_init' ) );
        }

        /**
         * Add options page
         */
        public function add_plugin_page()
        {
            // This page will be under "Settings"
            add_options_page(
                'Settings J&T',
                'Settings J&T',
                'manage_options',
                'jt_setting_page',
                array( $this, 'create_admin_page' )
            );
        }



        public function create_admin_page()
        {
            $this->options = get_option( 'jt_options' );
            ?>
            <div class="wrap">
                <?php screen_icon(); ?>
                <h2>Settings J&T </h2>
                <form method="post" action="options.php">
                    <?php
                    // This prints out all hidden setting fields
                    settings_fields( 'jt_options_group' );
                    do_settings_sections( 'jt_setting_page' );
                    submit_button();
                    ?>
                </form>
            </div>
            <?php
        }

        /**
         * Register and add settings
         */
        public function page_init()
        {
            register_setting(
                'jt_options_group', // Option group
                'jt_options', // Option name
                array( $this, 'sanitize' ) // Sanitize
            );

            add_settings_section(
                'jt_setting_section_id', // ID
                'Settings J&T', // Title
                array( $this, 'print_section_info' ), // Callback
                'jt_setting_page' // Page
            );

            add_settings_field(
                'jt_email', // ID
                'J&T Email', // Title
                array( $this, 'jt_email_callback' ), // Callback
                'jt_setting_page', // Page
                'jt_setting_section_id' // Section
            );

            add_settings_field(
                'jt_password',
                'J&T Password',
                array( $this, 'jt_password_callback' ),
                'jt_setting_page',
                'jt_setting_section_id'
            );

            add_settings_field(
                'jt_merchant_code',
                'J&T Merchant code',
                array( $this, 'jt_merchant_code_callback' ),
                'jt_setting_page',
                'jt_setting_section_id'
            );

            add_settings_field(
                'jt_service_code',
                'J&T Service code',
                array( $this, 'jt_service_code_callback' ),
                'jt_setting_page',
                'jt_setting_section_id'
            );


            add_settings_field(
                'jt_prefix',
                'J&T Prefix code',
                array( $this, 'jt_prefix_callback' ),
                'jt_setting_page',
                'jt_setting_section_id'
            );

            add_settings_field(
                'jt_country_code',
                'J&T Country code',
                array( $this, 'jt_country_code_callback' ),
                'jt_setting_page',
                'jt_setting_section_id'
            );
        }
        public function sanitize( $input )
        {
            if( !is_numeric( $input['jt_email'] ) )
                $input['jt_email'] = sanitize_text_field( $input['jt_email'] );

            if( !empty( $input['jt_password'] ) )
                $input['jt_password'] = sanitize_text_field( $input['jt_password'] );

            return $input;
        }
        public function print_section_info()
        {
            print 'Enter your settings below:';
        }
        public function jt_email_callback()
        {
            printf(
                '<input type="text" id="jt_email" name="jt_options[jt_email]" value="%s" style="width: 350px"/>',
                esc_attr( $this->options['jt_email'])
            );
        }
        public function jt_password_callback()
        {
            printf(
                '<input type="text" id="jt_password" name="jt_options[jt_password]" value="%s" style="width: 350px"/>',
                esc_attr( $this->options['jt_password'])
            );
        }
        public function jt_merchant_code_callback()
        {
            printf(
                '<input type="text" id="jt_merchant_code" name="jt_options[jt_merchant_code]" value="%s" style="width: 350px"/>',
                esc_attr( $this->options['jt_merchant_code'])
            );
        }
        public function jt_service_code_callback()
        {
            printf(
                '<input type="text" id="jt_service_code" name="jt_options[jt_service_code]" value="%s" style="width: 350px"/>',
                esc_attr( $this->options['jt_service_code'])
            );
        }

        public function jt_prefix_callback()
        {
            printf(
                '<input type="text" id="jt_prefix" name="jt_options[jt_prefix]" value="%s" style="width: 350px"/>',
                esc_attr( $this->options['jt_prefix'])
            );
        }

        public function jt_country_code_callback()
        {
            printf(
                '<input type="text" id="jt_country_code" name="jt_options[jt_country_code]" value="%s" style="width: 350px"/>',
                esc_attr( $this->options['jt_country_code'])
            );
        }
    }

    if( is_admin() )
        $my_settings_page = new MySettingsPage();



add_filter( 'woocommerce_package_rates', 'custom_adjust_shipping_rate', 100 );
function custom_adjust_shipping_rate( $rates )
{
    $rateData= 0;
    $options = get_option( 'jt_options' );
    $email = isset($options['jt_email']) ? $options['jt_email'] : '';
    $password = isset($options['jt_password']) ? $options['jt_password'] : '';
    if ($email && $password) {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'http://edishops.jtexpress.sg/jts-service-doorstep/api/gateway/v1/auth/login',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_USERPWD => $email . ':' . $password
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        $accessToken = '';
        if (json_decode($response)) {
            $accessToken = json_decode($response)->token;
        }

        if ($accessToken) {
            $weight = 0;
            $length = 0;
            $width = 0;
            $height = 0;

            global $woocommerce;
            $items = $woocommerce->cart->get_cart();
            foreach($items as $item => $values) {
                $quantity = $values['quantity'];
                $_product =  wc_get_product( $values['data']->get_id());
                if ($_product && $_product->get_weight()) {
                    $weight += ($_product->get_weight() * $quantity);
                }
                if ($_product && $_product->get_length()) {
                    $length += ($_product->get_length() * $quantity);
                }
                if ($_product && $_product->get_width()) {
                    $width += ($_product->get_width() * $quantity);
                }
                if ($_product && $_product->get_height()) {
                    $height += ($_product->get_height() * $quantity);
                }
            }

            $curl = curl_init();
            $dataArr = [
                    'weight' => $weight,
                    'weight_unit' => 'KG',
                    'length' => $length,
                    'width' => $width,
                    'height' => $height,
                    'service_code' =>isset($options['jt_service_code']) ? $options['jt_service_code'] : '',
                    'country_code' => isset($options['jt_country_code']) ? $options['jt_country_code'] : ''
            ];
            $data = http_build_query($dataArr);
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'http://edishops.jtexpress.sg/jts-service-doorstep/api/gateway/v1/services/price?'. $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Authorization: JWT ' . $accessToken
                ),
            ));
            $response = curl_exec($curl);
            curl_close($curl);
            $dataRes = json_decode($response);
            $rateData = $dataRes->rate;
        }
    }
    foreach ($rates as $rate) {
        $rate->cost = $rateData;
    }
    if (!$rateData) {
        remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
        add_action( 'woocommerce_proceed_to_checkout', 'custom_button_proceed_to_checkout', 20 );
        add_filter( 'woocommerce_order_button_html', 'replace_order_button_html', 10, 2 );
    }
    return $rates;
}

function custom_button_proceed_to_checkout() {
    $style = ' style="color:#fff;cursor:not-allowed;background-color:#999;"';
    echo '<a class="checkout-button button alt wc-forward" '.$style.'>' .
        __("PROCEED TO CHECKOUT", "woocommerce") . '<small style="font-size: 10px;text-transform: none;">Failed to get the shipping fee, please check the product again or contact sales</small></a>';
}

function replace_order_button_html( $order_button ) {
    $order_button_text = __( "PLACE ORDER", "woocommerce" );

    $style = ' style="color:#fff;cursor:not-allowed;background-color:#999;padding:0px;"';
    return '<a class="button alt"'.$style.' name="woocommerce_checkout_place_order" id="place_order" >' . esc_html( $order_button_text ) . '<small style="font-size: 10px;text-transform: none;">Failed to get the shipping fee, please check the product again or contact sales</small></a>';
}

add_filter('woocommerce_currency_symbol', 'add_my_currency_symbol', 20, 2);
function add_my_currency_symbol( $currency_symbol, $currency ) {
    switch( $currency ) {
        case 'SGD': $currency_symbol = 'S$';
            break;
    }

    return $currency_symbol;
}