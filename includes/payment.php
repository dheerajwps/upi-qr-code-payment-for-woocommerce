<?php
/**
 * The admin-facing functionality of the plugin.
 *
 * @package    UPI QR Code Payment Gateway
 * @subpackage Includes
 * @author     Sayan Datta
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 */

// add Gateway to woocommerce
add_filter( 'woocommerce_payment_gateways', 'upiwc_woocommerce_payment_add_gateway_class' );

function upiwc_woocommerce_payment_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_UPI_Payment_Gateway'; // class name
	
	return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'upiwc_payment_gateway_init' );

function upiwc_payment_gateway_init() {

	// If the WooCommerce payment gateway class is not available nothing will return
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

	class WC_UPI_Payment_Gateway extends WC_Payment_Gateway {
		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
	  
			$this->id                 = 'wc-upi';
			$this->icon               = apply_filters( 'upiwc_custom_gateway_icon', UPI_WOO_PLUGIN_DIR . 'includes/icon/payment.gif' );
			$this->has_fields         = true;
			$this->method_title       = __( 'UPI QR Code', 'upi-qr-code-payment-for-woocommerce' );
			$this->method_description = __( 'Allows customers to use UPI mobile app like Paytm, Google Pay, BHIM, PhonePe to pay to your bank account directly using UPI. All of the below fields are required. Merchant needs to manually checks the payment and mark it as complete on the Order edit page as automatic verification is not available in this payment method.', 'upi-qr-code-payment-for-woocommerce' );
			$this->order_button_text  = __( 'Proceed to Payment', 'upi-qr-code-payment-for-woocommerce' );

			// Method with all the options fields
            $this->init_form_fields();
         
            // Load the settings.
            $this->init_settings();
		  
			// Define user set variables
			$this->title                = $this->get_option( 'title' );
			$this->description          = $this->get_option( 'description' );
			$this->instructions         = $this->get_option( 'instructions', $this->description );
			$this->instructions_mobile  = $this->get_option( 'instructions_mobile', $this->description );
			$this->confirm_message      = $this->get_option( 'confirm_message' );
			$this->thank_you            = $this->get_option( 'thank_you' );
			$this->payment_status       = $this->get_option( 'payment_status', 'on-hold' );
			$this->name                 = $this->get_option( 'name' );
			$this->vpa                  = $this->get_option( 'vpa' );
			$this->pay_button           = $this->get_option( 'pay_button' );
			$this->mcc                  = $this->get_option( 'mc_code' );
			$this->upi_address          = $this->get_option( 'upi_address', 'show_require' );
			$this->require_upi          = $this->get_option( 'require_upi', 'yes' );
			$this->transaction_id       = $this->get_option( 'transaction_id', 'show_require' );
			$this->intent               = $this->get_option( 'intent', 'no' );
			$this->auto_intent          = $this->get_option( 'auto_intent', 'no' );
			$this->download_qr          = $this->get_option( 'download_qr', 'no' );
			$this->qrcode_mobile        = $this->get_option( 'qrcode_mobile', 'yes' );
			$this->hide_on_mobile       = $this->get_option( 'hide_on_mobile', 'no' );
			$this->email_enabled        = $this->get_option( 'email_enabled' );
			$this->email_subject        = $this->get_option( 'email_subject' );
			$this->email_heading        = $this->get_option( 'email_heading' );
			$this->additional_content   = $this->get_option( 'additional_content' );
			$this->default_status       = apply_filters( 'upiwc_process_payment_order_status', 'pending' );
			
			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// We need custom JavaScript to obtain the transaction number
	        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

			// thank you page output
			add_action( 'woocommerce_receipt_'.$this->id, array( $this, 'generate_qr_code' ), 4, 1 );

			// verify payment from redirection
            add_action( 'woocommerce_api_upiwc-payment', array( $this, 'capture_payment' ) );

			// Customize on hold email template subject
			add_filter( 'woocommerce_email_subject_customer_on_hold_order', array( $this, 'email_subject_pending_order' ), 10, 3 );

			// Customize on hold email template heading
			add_filter( 'woocommerce_email_heading_customer_on_hold_order', array( $this, 'email_heading_pending_order' ), 10, 3 );

			// Customize on hold email template additional content
			add_filter( 'woocommerce_email_additional_content_customer_on_hold_order', array( $this, 'email_additional_content_pending_order' ), 10, 3 );

			// Customer Emails
			add_action( 'woocommerce_email_after_order_table', array( $this, 'email_instructions' ), 10, 4 );

			// add support for payment for on hold orders
			add_action( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'on_hold_payment' ), 10, 2 );

			// change wc payment link if exists payment method is QR Code
			add_filter( 'woocommerce_get_checkout_payment_url', array( $this, 'custom_checkout_url' ), 10, 2 );
			
			// add custom text on thankyou page
			add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'order_received_text' ), 10, 2 );

			// disable upi payment gateway
			add_filter( 'woocommerce_available_payment_gateways', array( $this, 'disable_gateway' ), 10, 1 );

			// add order column data
			add_filter( 'manage_edit-shop_order_columns', array( $this, 'admin_list_column' ) );
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'admin_list_column_content' ) );

			if ( ! $this->is_valid_for_use() ) {
                $this->enabled = 'no';
            }
		}

		/**
	     * Check if this gateway is enabled and available in the user's country.
	     *
	     * @return bool
	     */
	    public function is_valid_for_use() {
			if ( in_array( get_woocommerce_currency(), apply_filters( 'upiwc_supported_currencies', array( 'INR' ) ) ) ) {
				return true;
			}

	    	return false;
        }
        
        /**
	     * Admin Panel Options.
	     *
	     * @since 1.0.0
	     */
	    public function admin_options() {
	    	if ( $this->is_valid_for_use() ) {
	    		parent::admin_options();
	    	} else {
	    		?>
	    		<div class="inline error">
	    			<p>
	    				<strong><?php esc_html_e( 'Gateway disabled', 'upi-qr-code-payment-for-woocommerce' ); ?></strong>: <?php esc_html_e( 'This plugin does not support your store currency. UPI Payment only supports Indian Currency. Contact developer for support.', 'upi-qr-code-payment-for-woocommerce' ); ?>
	    			</p>
	    		</div>
	    		<?php
	    	}
        }
	
		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {

			$this->form_fields = array(
				'enabled'             => array(
					'title'       => __( 'Enable/Disable:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable UPI QR Code Payment Method', 'upi-qr-code-payment-for-woocommerce' ),
					'description' => __( 'Enable this if you want to collect payment via UPI QR Codes.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => 'yes',
					'desc_tip'    => true,
				),
				'title'               => array(
					'title'       => __( 'Title:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'text',
					'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => __( 'Pay with UPI QR Code', 'upi-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => false,
				),
				'description'         => array(
					'title'       => __( 'Description:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => __( 'It uses UPI apps like BHIM, Paytm, Google Pay, PhonePe or any Banking UPI app to make payment.', 'upi-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => false,
				),
				'instructions'        => array(
					'title'       => __( 'Instructions:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Instructions that will be added to the order pay popup on desktop devices.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => __( 'Scan the QR Code with any UPI apps like BHIM, Paytm, Google Pay, PhonePe or any Banking UPI app to make payment for this order. After successful payment, enter the UPI Reference ID or Transaction Number and your UPI ID in the next screen and submit the form. We will manually verify this payment against your 12-digits UPI Reference ID or Transaction Number (e.g. 301422121258) and your UPI ID.', 'upi-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => false,
				),
				'instructions_mobile' => array(
					'title'       => __( 'Mobile Instructions:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Instructions that will be added to the order pay popup on mobile devices.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => __( 'Scan the QR Code with any UPI apps like BHIM, Paytm, Google Pay, PhonePe or any Banking UPI app to make payment for this order. After successful payment, enter the UPI Reference ID or Transaction Number and your UPI ID in the next screen and submit the form. We will manually verify this payment against your 12-digits UPI Reference ID or Transaction Number (e.g. 301422121258) and your UPI ID.', 'upi-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => false,
				),
				'upi_address'         => array(
					'title'       => __( 'UPI Address (VPA):', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'select',
					'description' => __( 'If you want to collect UPI Address from customers on checkout page, set it here.', 'upi-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => false,
                    'default'     => 'show_handle',
                    'options'     => array(
						'hide'        => __( 'Hide Field', 'upi-qr-code-payment-for-woocommerce' ),
						'show'        => __( 'Show Input Field', 'upi-qr-code-payment-for-woocommerce' ),
						'show_handle' => __( 'Show Input Field & Handle', 'upi-qr-code-payment-for-woocommerce' ),
                    ),
				),
				'require_upi'         => array(
					'title'       => __( 'Require UPI ID:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'select',
					'description' => __( 'If you want to make UPI Address field required on checkout page, set it here.', 'upi-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => false,
                    'default'     => 'yes',
                    'options'     => array(
						'yes' => __( 'Require Field', 'upi-qr-code-payment-for-woocommerce' ),
						'no'  => __( 'Don\'t Require Field', 'upi-qr-code-payment-for-woocommerce' ),
                    ),
				),
				'confirm_message'     => array(
					'title'       => __( 'Confirm Message:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'This displays a message to customer as payment processing text.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => __( 'Click Confirm, only after amount deducted from your account. We will manually verify your transaction. Are you sure?', 'upi-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => false,
				),
                'thank_you'           => array(
                    'title'       => __( 'Thank You Message:', 'upi-qr-code-payment-for-woocommerce' ),
                    'type'        => 'textarea',
                    'description' => __( 'This displays a message to customer after a successful payment is made.', 'upi-qr-code-payment-for-woocommerce' ),
                    'default'     => __( 'Thank you for your payment. Your transaction has been completed, and your order has been successfully placed. Please check you Email inbox for details. Please check your bank account statement to view transaction details.', 'upi-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => false,
				),
				'payment_status'      => array(
                    'title'       => __( 'Payment Complete Status:', 'upi-qr-code-payment-for-woocommerce' ),
                    'type'        => 'select',
					'description' => __( 'Payment action on successful UPI Transaction ID submission. Recommended: On Hold', 'upi-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => false,
                    'default'     => 'on-hold',
                    'options'     => apply_filters( 'upiwc_settings_order_statuses', array(
						'pending'    => __( 'Pending Payment', 'upi-qr-code-payment-for-woocommerce' ),
						'on-hold'    => __( 'On Hold', 'upi-qr-code-payment-for-woocommerce' ),
						'processing' => __( 'Processing', 'upi-qr-code-payment-for-woocommerce' ),
						'completed'  => __( 'Completed', 'upi-qr-code-payment-for-woocommerce' ),
                    ) ),
                ),
				'payemnt_page'        => array(
                    'title'       => __( 'Payment Page Settings', 'upi-qr-code-payment-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'Customize various settings of the Payment Page here.', 'upi-qr-code-payment-for-woocommerce' ),
				),
				'name'                => array(
			    	'title'       => __( 'Your Store or Shop Name:', 'upi-qr-code-payment-for-woocommerce' ),
			    	'type'        => 'text',
			    	'description' => __( 'Please enter Your Store or Shop name. If you are a person, you can enter your name.', 'upi-qr-code-payment-for-woocommerce' ),
			    	'default'     => get_bloginfo( 'name' ),
			    	'desc_tip'    => false,
				),
			    'vpa'                 => array(
			    	'title'       => __( 'Merchant UPI VPA ID:', 'upi-qr-code-payment-for-woocommerce' ),
			    	'type'        => 'email',
			    	'description' => __( 'Please enter Your Merchant UPI VPA (e.g. Q12345678@ybl) at which you want to collect payments. Receiver and Sender UPI ID can\'t be same. General User UPI VPA is not acceptable. To Generate Merchant UPI ID, you can use apps like PhonePe Business or Paytm for Business etc.', 'upi-qr-code-payment-for-woocommerce' ),
			    	'default'     => '',
			    	'desc_tip'    => false,
				),
				'pay_button'          => array(
			    	'title'       => __( 'Pay Now Button Text:', 'upi-qr-code-payment-for-woocommerce' ),
			    	'type'        => 'text',
			    	'description' => __( 'Enter the text to show as the payment button.', 'upi-qr-code-payment-for-woocommerce' ),
			    	'default'     => __( 'Scan & Pay Now', 'upi-qr-code-payment-for-woocommerce' ),
			    	'desc_tip'    => false,
				),
				'mc_code'             => array(
			    	'title'       => __( 'Merchant Category Code:', 'upi-qr-code-payment-for-woocommerce' ),
			    	'type'        => 'number',
			    	'description' => sprintf( '%s <a href="https://www.citibank.com/tts/solutions/commercial-cards/assets/docs/govt/Merchant-Category-Codes.pdf" target="_blank">%s</a> or <a href="https://docs.checkout.com/resources/codes/merchant-category-codes" target="_blank">%s</a>', __( 'You can refer to these links to find out your MCC.', 'upi-qr-code-payment-for-woocommerce' ), 'Citi Bank', 'Checkout.com' ),
			    	'default'     => 8931,
			    	'desc_tip'    => false,
				),
				'transaction_id'      => array(
					'title'       => __( 'UPI Transaction ID:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'select',
					'description' => __( 'If you want to collect UPI Transaction ID from customers on payment page, set it here. If you sell any downloable product, it is recommended to keep "Show & Require Input Field" option selected.', 'upi-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => false,
                    'default'     => 'show_require',
                    'options'     => array(
						'hide'         => __( 'Hide Field', 'upi-qr-code-payment-for-woocommerce' ),
						'show'         => __( 'Show Input Field', 'upi-qr-code-payment-for-woocommerce' ),
						'show_require' => __( 'Show & Require Input Field', 'upi-qr-code-payment-for-woocommerce' ),
                    ),
				),
				'intent'              => array(
					'title'       => __( 'One Tap Payment Button:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Show / Hide One Tap / Direct Payment Button', 'upi-qr-code-payment-for-woocommerce' ),
					'description' => __( 'Enable this if you want to show direct pay now option only on android devices. Only Merchent UPI IDs will work.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => 'no',
					'desc_tip'    => false,
				),
				'auto_intent'         => array(
					'title'       => __( 'Auto Launch UPI Apps:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable / Disable Auto Launch UPI Apps', 'upi-qr-code-payment-for-woocommerce' ),
					'description' => __( 'Enable this if you want to auto launch UPI apps only on android devices. Only Merchent UPI IDs will work.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => 'no',
					'desc_tip'    => false,
				),
				'download_qr'         => array(
					'title'       => __( 'Download Button:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Show / Hide download QR Code Button', 'upi-qr-code-payment-for-woocommerce' ),
					'description' => __( 'Enable this if you want to show download QR Code Button. Buyers can pay using this QR Code bu uploading it from gallery to any UPI supported apps.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => 'no',
					'desc_tip'    => false,
				),
				'qrcode_mobile'       => array(
					'title'       => __( 'Mobile QR Code:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Show / Hide QR Code on Mobile Devices', 'upi-qr-code-payment-for-woocommerce' ),
					'description' => __( 'Enable this if you want to show UPI QR Code on mobile devices.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => 'yes',
					'desc_tip'    => false,
				),
				'hide_on_mobile'      => array(
					'title'       => __( 'Disable Gateway on Mobile:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Disable QR Code Payment Gateway on Mobile Devices', 'upi-qr-code-payment-for-woocommerce' ),
					'description' => __( 'Enable this if you want to disable QR Code Payment Gateway on Mobile Devices.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => 'no',
					'desc_tip'    => false,
				),
				'email'               => array(
                    'title'       => __( 'Configure Email', 'upi-qr-code-payment-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'Configure the Payment Pending email settings here.', 'upi-qr-code-payment-for-woocommerce' ),
				),
				'email_enabled'       => array(
					'title'       => __( 'Enable / Disable:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable Email Notification', 'upi-qr-code-payment-for-woocommerce' ),
					'description' => __( 'Enable this option if you want to send payment link to the customer via email after placing the successful order.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => 'yes',
					'desc_tip'    => false,
				),
				'email_subject'       => array(
					'title'       => __( 'Email Subject:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'text',
					'desc_tip'    => false,
					'description' => sprintf( __( 'Available placeholders: %s', 'woocommerce' ), '<code>' . esc_html( '{site_title}, {site_address}, {order_date}, {order_number}' ) . '</code>' ),
					'default'     => __( '[{site_title}]: Payment pending #{order_number}', 'upi-qr-code-payment-for-woocommerce' ),
				),
				'email_heading'       => array(
					'title'       => __( 'Email Heading:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'text',
					'desc_tip'    => false,
					'description' => sprintf( __( 'Available placeholders: %s', 'woocommerce' ), '<code>' . esc_html( '{site_title}, {site_address}, {order_date}, {order_number}' ) . '</code>' ),
					'default'     => __( 'Thank you for your order', 'upi-qr-code-payment-for-woocommerce' ),
				),
				'additional_content'  => array(
					'title'       => __( 'Email Body Text:', 'upi-qr-code-payment-for-woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'This text will be attached to the On Hold email template sent to customer. Use {upi_pay_link} to add the link of payment page.', 'upi-qr-code-payment-for-woocommerce' ),
					'default'     => __( 'Please complete the payment via UPI by going to this link: {upi_pay_link} (ignore if already done).', 'upi-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => false,
				),
			);
		}

		/**
		 * Display the UPi Id field
		 */
		public function payment_fields() {
			global $woocommerce;
			$order_id = $woocommerce->session->order_awaiting_payment; 
			$payment_upi_id = get_post_meta( $order_id, '_transaction_upi_id', true );

			$upi_address = $upi_handle = '';
			if ( ! empty( $payment_upi_id ) ) {
				$payment_upi_id = explode( '@', $payment_upi_id );
				$upi_address = $payment_upi_id[0];
				$upi_handle = $payment_upi_id[1];
			}

			// display description before the payment form
	        if ( $this->description ) {
	        	// display the description with <p> tags
	        	echo wpautop( wp_kses_post( $this->description ) );
			}
			
			$handles = array_unique( apply_filters( 'upiwc_upi_handle_list', array( '@airtel', '@airtelpaymentsbank', '@apb', '@apl', '@allbank', '@albk', '@allahabadbank', '@andb', '@axisgo', '@axis', '@axisbank', '@axisb', '@okaxis', '@abfspay', '@axl', '@barodampay', '@barodapay', '@boi', '@cnrb', '@csbpay', '@csbcash', '@centralbank', '@cbin', '@cboi', '@cub', '@dbs', '@dcb', '@dcbbank', '@denabank', '@equitas', '@federal', '@fbl', '@finobank', '@hdfcbank', '@payzapp', '@okhdfcbank', '@rajgovhdfcbank', '@hsbc', '@imobile', '@pockets', '@ezeepay', '@eazypay', '@idbi', '@idbibank', '@idfc', '@idfcbank', '@idfcnetc', '@cmsidfc', '@indianbank', '@indbank', '@indianbk', '@iob', '@indus', '@indusind', '@icici', '@myicici', '@okicici', '@ikwik', '@ibl', '@jkb', '@jsbp', '@kbl', '@karb', '@kbl052', '@kvb', '@karurvysyabank', '@kvbank', '@kotak', '@kaypay', '@kmb', '@kmbl', '@okbizaxis', '@obc', '@paytm', '@pingpay', '@psb', '@pnb', '@sib', '@srcb', '@sc', '@scmobile', '@scb', '@scbl', '@sbi', '@oksbi', '@syndicate', '@syndbank', '@synd', '@lvb', '@lvbank', '@rbl', '@tjsb', '@uco', '@unionbankofindia', '@unionbank', '@uboi', '@ubi', '@united', '@utbi', '@upi', '@vjb', '@vijb', '@vijayabank', '@ubi', '@yesbank', '@ybl', '@yesbankltd' ) ) );
			sort( $handles );

			$class = 'form-row-wide';
			$placeholder = apply_filters( 'upiwc_upi_address_placeholder', 'mobilenumber@paytm' );
			$required = '';
			$upi_address = ( isset( $_POST['customer_upiwc_address'] ) ) ? sanitize_text_field( $_POST['customer_upiwc_address'] ) : $upi_address;
			
			if ( $this->upi_address === 'show_handle' ) {
				$class = 'form-row-first';
				$placeholder = apply_filters( 'upiwc_upi_address_placeholder', 'mobilenumber' );
			}

			if ( $this->require_upi === 'yes' ) {
				$required = ' <span class="required">*</span>';
			}

			if ( in_array( $this->upi_address, array( 'show', 'show_handle' ) ) ) {
	            echo '<fieldset id="' . esc_attr( $this->id ) . '-payment-form" class="wc-upi-form wc-payment-form" style="background:transparent;">';
    
		    	do_action( 'woocommerce_upi_form_start', $this->id );
    
		    	echo '<div class="form-row ' . $class . ' upiwc-input"><label style="font-weight: 700;">' . __( 'UPI Address', 'upi-qr-code-payment-for-woocommerce' ) . $required . '</label>
		                <input id="upiwc-address" class="upiwc-address" name="customer_upiwc_address" type="text" autocomplete="off" placeholder="e.g. ' . $placeholder . '" value="' . $upi_address . '" style="width: 100%;height: 34px;min-height: 34px;text-transform: lowercase;">
		    		</div>';
		    	if ( $this->upi_address === 'show_handle' ) {
		    		echo '<div class="form-row form-row-last upiwc-input"><label style="font-weight: 700;">' . __( 'UPI Handle', 'upi-qr-code-payment-for-woocommerce' ) . $required . '</label>
		    			<select id="upiwc-handle" name="customer_upiwc_handle" style="width: 100%;height: 34px;min-height: 34px;"><option selected disabled hidden value="">' . __( '-- Select --', 'upi-qr-code-payment-for-woocommerce' ) . '</option>';
		    			foreach ( $handles as $handle ) {
		    				echo '<option value="' . $handle . '" ' . selected( $upi_handle, $handle, false ) . '>' . $handle . '</option>';
		    			}
		    		echo '</select></div>';
		    	}
    
		    	do_action( 'woocommerce_upi_form_end', $this->id );
    
				echo '<div class="clear"></div></fieldset>'; ?>
                <script type="text/javascript">
                    ( function( $ ) {
			    		if ( $( '#upiwc-handle' ).length ) {
                            var select = $( "#upiwc-handle" ).selectize( {
			            	    create: <?php echo apply_filters( 'upiwc_create_upi_handle', 'false' ); ?>,
			    	    	} );
							<?php if ( ! empty( $upi_handle ) ) { ?>
								var selectize = select[0].selectize;
								selectize.setValue( selectize.search( '<?php echo $upi_handle; ?>').items[0].id );
							<?php } ?>
			    	    }
                    } )( jQuery );
                </script>
				<?php
		    }
		}

		/**
		 * Validate UPI ID field
		 */
		public function validate_fields() {
			if ( empty( $_POST['customer_upiwc_address'] ) && in_array( $this->upi_address, array( 'show', 'show_handle' ) ) && $this->require_upi === 'yes' ) {
				wc_add_notice( __( '<strong>UPI Address</strong> is a required field.', 'upi-qr-code-payment-for-woocommerce' ), 'error' );
				return false;
			}

			if ( empty( $_POST['customer_upiwc_handle'] ) && $this->upi_address === 'show_handle' && $this->require_upi === 'yes' ) {
				wc_add_notice( __( '<strong>UPI Handle</strong> is a required field.', 'upi-qr-code-payment-for-woocommerce' ), 'error' );
				return false;
			}

			$regex = "/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*$/i";
			if ( $this->upi_address === 'show_handle' ) {
				$regex = "/^[_a-z0-9-]+(\.[_a-z0-9-]+)*$/i";
			}
			if ( ! preg_match( $regex, sanitize_text_field( $_POST['customer_upiwc_address'] ) ) && in_array( $this->upi_address, array( 'show', 'show_handle' ) ) && $this->require_upi === 'yes' ) {
				wc_add_notice( __( 'Please enter a <strong>valid UPI Address</strong>!', 'upi-qr-code-payment-for-woocommerce' ), 'error' );
				return false;
			}

			return true;
		}

		/**
		 * Custom CSS and JS
		 */
		public function payment_scripts() {
			// if our payment gateway is disabled, we do not have to enqueue JS too
	        if ( 'no' === $this->enabled ) {
	        	return;
			}
			
			if ( is_checkout() ) {
			    wp_enqueue_style( 'upiwc-selectize', plugins_url( 'css/selectize.min.css' , __FILE__ ), array(), '0.12.6' );
				wp_enqueue_script( 'upiwc-selectize', plugins_url( 'js/selectize.min.js' , __FILE__ ), array( 'jquery' ), '0.12.6', false );
			}
		
			wp_register_style( 'upiwc-jquery-confirm', plugins_url( 'css/jquery-confirm.min.css' , __FILE__ ), array(), '3.3.4' );
			wp_register_style( 'upiwc', plugins_url( 'css/upi.min.css' , __FILE__ ), array( 'upiwc-jquery-confirm' ), UPI_WOO_PLUGIN_VERSION );
			
			wp_register_script( 'upiwc-qr-code', plugins_url( 'js/easy.qrcode.min.js' , __FILE__ ), array( 'jquery' ), '3.8.3', true );
			wp_register_script( 'upiwc-jquery-confirm', plugins_url( 'js/jquery-confirm.min.js' , __FILE__ ), array( 'jquery' ), '3.3.4', true );
		    wp_register_script( 'upiwc', plugins_url( 'js/upi.min.js' , __FILE__ ), array( 'jquery', 'upiwc-qr-code', 'upiwc-jquery-confirm' ), UPI_WOO_PLUGIN_VERSION, true );
		}

		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );
			$upi_address = ! empty( $_POST['customer_upiwc_address'] ) ? sanitize_text_field( $_POST['customer_upiwc_address'] ) : '';
			$upi_address = ! empty( $_POST['customer_upiwc_handle'] ) ? $upi_address . sanitize_text_field( $_POST['customer_upiwc_handle'] ) : $upi_address;
			$message = __( 'Awaiting UPI Payment!', 'upi-qr-code-payment-for-woocommerce' );

			// Mark as pending (we're awaiting the payment)
			$order->update_status( $this->default_status );

			// update meta
			update_post_meta( $order->get_id(), '_upiwc_order_paid', 'no' );

			if ( ! empty( $upi_address ) ) {
			    update_post_meta( $order->get_id(), '_transaction_upi_id', preg_replace( "/\s+/", "", $upi_address ) );
				$message .= '<br />' . sprintf( __( 'UPI ID: %s', 'upi-qr-code-payment-for-woocommerce' ), preg_replace( "/\s+/", "", $upi_address ) );
			}

			// add some order notes
			$order->add_order_note( apply_filters( 'upiwc_process_payment_note', $message, $order ), false );

			if ( apply_filters( 'upiwc_payment_empty_cart', false ) ) {
			    // Empty cart
			    WC()->cart->empty_cart();
			}

			do_action( 'upiwc_after_payment_init', $order_id, $order );

			// check plugin settings
			if ( 'yes' === $this->enabled && 'yes' === $this->email_enabled && $order->has_status( 'pending' ) ) {
				// Get an instance of the WC_Email_Customer_On_Hold_Order object
				$wc_email = WC()->mailer()->get_emails()['WC_Email_Customer_On_Hold_Order'];
				
                // Send "New Email" notification
                $wc_email->trigger( $order_id );
			}

			// Return redirect
			return array(
				'result'   => 'success',
				'redirect' => apply_filters( 'upiwc_process_payment_redirect', $order->get_checkout_payment_url( true ), $order ),
			);
		}
		
		/**
	     * Show UPI details as html output
	     *
	     * @param WC_Order $order_id Order id.
	     * @return string
	     */
		public function generate_qr_code( $order_id ) {
			// get order object from id
			$order = wc_get_order( $order_id );
            $total = apply_filters( 'upiwc_order_total_amount', $order->get_total(), $order );
            //$total = 1;
			// enqueue required css & js files
			wp_enqueue_style( 'upiwc-jquery-confirm' );
			wp_enqueue_style( 'upiwc' );
			wp_enqueue_script( 'upiwc-jquery-confirm' );
		    wp_enqueue_script( 'upiwc' );
			wp_enqueue_script( 'upiwc' );
			
			// add localize scripts
			wp_localize_script( 'upiwc', 'upiwc_params',
                array( 
					'order_id'          => $order_id,
					'order_amount'      => $total,
					'order_key'         => $order->get_order_key(),
					'order_number'      => htmlentities( $order->get_order_number() ),
					'confirm_message'   => $this->confirm_message,
					'callback_url'      => add_query_arg( array( 'wc-api' => 'upiwc-payment' ), trailingslashit( get_home_url() ) ),
					'payment_url'       => $order->get_checkout_payment_url(),
					'cancel_url'        => apply_filters( 'upiwc_payment_cancel_url', wc_get_checkout_url(), $this->get_return_url( $order ), $order ),
					'transaction_id'    => $this->transaction_id,
					'mc_code'           => $this->mcc ? $this->mcc : 8931,
					'prevent_reload'    => apply_filters( 'upiwc_enable_payment_reload', true ),
					'btn_timer'    		=> apply_filters( 'upiwc_enable_button_timer', true ),
					'btn_show_interval' => apply_filters( 'upiwc_button_show_interval', 30000 ),
					'can_intent'        => ( wp_is_mobile() && ( stripos( $_SERVER['HTTP_USER_AGENT'], "iPhone" ) === false ) && $this->auto_intent === 'yes' ),
					'payer_vpa'         => htmlentities( strtolower( get_post_meta( $order->get_id(), '_transaction_upi_id', true ) ) ),
					'payee_vpa'         => htmlentities( strtolower( $this->vpa ) ),
					'payee_name'        => preg_replace('/[^\p{L}\p{N}\s]/u', '', $this->name ),
					'is_mobile'         => ( wp_is_mobile() ) ? 'yes' : 'no',
					'app_version'       => UPI_WOO_PLUGIN_VERSION,
                )
			);

			$can_show_qr = ( stripos( $_SERVER['HTTP_USER_AGENT'], "iPhone" ) === false && $this->intent === 'yes' );
			$can_show_download = ( $this->download_qr === 'yes' );

			// add html output on payment endpoint
			if ( 'yes' === $this->enabled && $order->needs_payment() === true && $order->has_status( $this->default_status ) && ! empty( $this->vpa ) ) { ?>
			    <section class="upiwc-section">
				    <div class="upiwc-info">
				        <h6 class="upiwc-waiting-text"><?php esc_html_e( 'Please wait and don\'t press back or refresh this page while we are processing your payment.', 'upi-qr-code-payment-for-woocommerce' ); ?></h6>
                        <?php do_action( 'upiwc_after_before_title', $order ); ?>
						<div class="upiwc-buttons">
							<button id="upiwc-processing" class="btn button" disabled="disabled"><?php esc_html_e( 'Waiting for payment...', 'upi-qr-code-payment-for-woocommerce' ); ?></button>
							<button id="upiwc-confirm-payment" class="btn button" style="display: none;"><?php echo esc_html( apply_filters( 'upiwc_payment_button_text', $this->pay_button ) ); ?></button>
			    	        <?php if ( apply_filters( 'upiwc_show_cancel_button', true ) ) { ?>
							    <button id="upiwc-cancel-payment" class="btn button" style="display: none;"><?php esc_html_e( 'Cancel', 'upi-qr-code-payment-for-woocommerce' ); ?></button>
							<?php } ?>
						</div>
						<?php if ( apply_filters( 'upiwc_show_choose_payment_method', true ) ) { ?>
						    <div class="upiwc-return-link" style="margin-top: 5px;"><?php esc_html_e( 'Choose another payment method', 'upi-qr-code-payment-for-woocommerce' ); ?></div>
						<?php } ?>
						<?php do_action( 'upiwc_after_payment_buttons', $order ); ?>
						<div id="upiwc-payment-success-container" style="display: none;"></div>
					</div>
					<div class="upiwc-modal-header">
						<div class="upiwc-payment-header">
							<div class="upiwc-payment-merchant-name"><?php echo preg_replace('/[^\p{L}\p{N}\s]/u', '', $this->name ); ?></div>
							<div class="upiwc-payment-order-info">
								<span class="upiwc-payment-prefix"><?php esc_html_e( 'Payment for Order Id: ', 'upi-qr-code-payment-for-woocommerce' ); ?></span> 
								<span class="upiwc-payment-order-id">#<?php echo esc_html( $order->get_order_number() ); ?></span>
							</div>
						</div>
					</div>
					<div class="upiwc-modal-content">
						<div class="upiwc-payment-content">
							<?php if ( ! wp_is_mobile() || $this->qrcode_mobile !== 'no' ) { ?>
								<div id="upiwc-payment-qr-code" class="upiwc-payment-qr-code"></div>
							<?php } ?>
							<div class="upiwc-payment-info">
								<div class="upiwc-payment-upi-id" title="<?php esc_attr_e( 'Click to Copy', 'upi-qr-code-payment-for-woocommerce' ); ?>"><?php echo htmlentities( strtoupper( $this->vpa ) ); ?></div>
								<?php if ( wp_is_mobile() && ( $can_show_qr || $can_show_download ) ) { ?>
									<div class="upiwc-payment-button">
										<?php if ( $can_show_qr ) { ?>
											<button type="button" id="upi-pay" class="btn"><?php echo apply_filters( 'upiwc_upi_direct_pay_text', __( 'One Tap to Pay', 'upi-qr-code-payment-for-woocommerce' ) ); ?></button>
										<?php } ?>
										<?php if ( $can_show_download ) { ?>
											<button type="button" id="upi-download" class="btn"><?php echo apply_filters( 'upiwc_donwload_button_text', __( 'Download QR Code', 'upi-qr-code-payment-for-woocommerce' ) ); ?></button>
										<?php } ?>
									</div>
								<?php } ?>
								<div class="upiwc-payment-info-text">
									<?php if ( wp_is_mobile() ) { 
										echo wptexturize( $this->instructions_mobile );
									} else {
										echo wptexturize( $this->instructions ); 
									} ?>
								</div>
								<div class="upiwc-payment-info-logo">
									<img src="<?php echo esc_url( UPI_WOO_PLUGIN_DIR . 'includes/icon/googlepay.svg' ); ?>" alt="google-pay-app-logo" class="logo">
									<img src="<?php echo esc_url( UPI_WOO_PLUGIN_DIR . 'includes/icon/phonepe.svg' ); ?>" alt="phonepe-app-logo" class="logo">
									<img src="<?php echo esc_url( UPI_WOO_PLUGIN_DIR . 'includes/icon/paytm.svg' ); ?>" alt="paytm-app-logo" class="logo">
									<img src="<?php echo esc_url( UPI_WOO_PLUGIN_DIR . 'includes/icon/bhim.svg' ); ?>" alt="bhim-app-logo" class="logo">
								</div>
							</div>
							<div class="upiwc-payment-confirm" style="display: none;">
								<?php if ( $this->transaction_id !== 'hide' ) { ?>
									<div class="upiwc-payment-confirm-form-container">
										<form id="upiwc-payment-confirm-form" class="upiwc-payment-confirm-form">
											<label for="upiwc-payment-transaction-number">
												<strong><?php esc_html_e( 'Enter 12-digit Transaction / UTR / Reference ID:', 'upi-qr-code-payment-for-woocommerce' ); ?></strong> 
												<?php if ( $this->transaction_id === 'show_require' ) { ?>
													<span class="field-required">*</span>
												<?php } ?>
											</label>
											<input type="text" id="upiwc-payment-transaction-number" class="" placeholder="" maxlength="12" onkeypress="return upiwcIsNumber(event)" />
										</form>
										<div class="upiwc-payment-error" style="display: none;"></div>
									</div>
								<?php } ?>
								<div class="upiwc-payment-confirm-text"><?php echo $this->confirm_message; ?></div>
							</div>
						</div>
					</div>
				</section><?php
			}
		}

		/**
	     * Process payment verification.
	     */
        public function capture_payment() {
            // get order id
            if ( ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) || ! isset( $_GET['wc-api'] ) || ( 'upiwc-payment' !== $_GET['wc-api'] ) ) {
                return;
            }

            // generate order
			$order_id = wc_get_order_id_by_order_key( sanitize_text_field( $_POST['wc_order_key'] ) );
			$order = wc_get_order( $order_id );
            
            // check if it an order
            if ( is_a( $order, 'WC_Order' ) ) {
				$order->update_status( apply_filters( 'upiwc_capture_payment_order_status', $this->payment_status ) );

				// set upi id as trnsaction id
				if ( isset( $_POST['wc_transaction_id'] ) && ! empty( $_POST['wc_transaction_id'] ) ) {
					update_post_meta( $order->get_id(), '_transaction_id', sanitize_text_field( $_POST['wc_transaction_id'] ) );
				}

				// reduce stock level
				wc_reduce_stock_levels( $order->get_id() );

				// check order if it actually needs payment
				if ( in_array( $this->payment_status, apply_filters( 'upiwc_valid_order_status_for_note', array( 'pending', 'on-hold' ) ) ) ) {
		            // set order note
		            $order->add_order_note( __( 'Payment primarily completed. Needs shop owner\'s verification.', 'upi-qr-code-payment-for-woocommerce' ), false );
				}

				// update post meta
				update_post_meta( $order->get_id(), '_upiwc_order_paid', 'yes' );

                // add custom actions 
				do_action( 'upiwc_after_payment_verify', $order->get_id(), $order );

				// create redirect
				wp_safe_redirect( apply_filters( 'upiwc_payment_redirect_url', $this->get_return_url( $order ), $order ) );
                exit;
            } else {
				// create redirect
                $title = __( 'Order can\'t be found against this Order ID. If the money debited from your account, please Contact with Site Administrator for further action.', 'upi-qr-code-payment-for-woocommerce' );
                        
                wp_die( $title, get_bloginfo( 'name' ) );
                exit;
			}
        }

        /**
		 * Customize the WC emails template.
		 *
		 * @access public
		 * @param string $formated_subject
		 * @param WC_Order $order
		 * @param object $object
		 */

		public function email_subject_pending_order( $formated_subject, $order, $object ) {
			// We exit for 'order-accepted' custom order status
			if ( $this->id === $order->get_payment_method() && 'yes' === $this->enabled && $order->has_status( 'pending' ) ) {
				return $object->format_string( $this->email_subject );
			}

			return $formated_subject;
		}

		/**
		 * Customize the WC emails template.
		 *
		 * @access public
		 * @param string $formated_subject
		 * @param WC_Order $order
		 * @param object $object
		 */
		public function email_heading_pending_order( $formated_heading, $order, $object ) {
			// We exit for 'order-accepted' custom order status
			if ( $this->id === $order->get_payment_method() && 'yes' === $this->enabled && $order->has_status( 'pending' ) ) {
				return $object->format_string( $this->email_heading );
			}

			return $formated_heading;
		}

		/**
		 * Customize the WC emails template.
		 *
		 * @access public
		 * @param string $formated_subject
		 * @param WC_Order $order
		 * @param object $object
		 */
		public function email_additional_content_pending_order( $formated_additional_content, $order, $object ) {
			// We exit for 'order-accepted' custom order status
			if ( $this->id === $order->get_payment_method() && 'yes' === $this->enabled && $order->has_status( 'pending' ) ) {
                return $object->format_string( str_replace( '{upi_pay_link}', $order->get_checkout_payment_url( true ), $this->additional_content ) );
			}

			return $formated_additional_content;
		}

		/**
	     * Custom order received text.
	     *
	     * @param string   $text Default text.
	     * @param WC_Order $order Order data.
	     * @return string
	     */
	    public function order_received_text( $text, $order ) {
	    	if ( $this->id === $order->get_payment_method() && ! empty( $this->thank_you ) ) {
	    		return esc_html( $this->thank_you );
	    	}
    
	    	return $text;
        }

		/**
	     * Custom checkout URL.
	     *
	     * @param string   $url Default URL.
	     * @param WC_Order $order Order data.
	     * @return string
	     */
	    public function custom_checkout_url( $url, $order ) {
	    	if ( $this->id === $order->get_payment_method() && ( ( $order->has_status( 'on-hold' ) && $this->default_status === 'on-hold' ) || ( $order->has_status( 'pending' ) && apply_filters( 'upiwc_custom_checkout_url', false ) ) ) ) {
	    		return esc_url( remove_query_arg( 'pay_for_order', $url ) );
	    	}
    
	    	return $url;
		}

		/**
		 * Add content to the WC emails.
		 *
		 * @access public
		 * @param WC_Order $order
		 * @param bool     $sent_to_admin
		 * @param bool     $plain_text
		 * @param object   $email
		 */
		public function email_instructions( $order, $sent_to_admin, $plain_text, $email ) {
		    // check upi gateway name
			if ( 'yes' === $this->enabled && 'yes' === $this->email_enabled && ! empty( $this->additional_content ) && ! $sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
				echo wpautop( wptexturize( str_replace( '{upi_pay_link}', $order->get_checkout_payment_url( true ), $this->additional_content ) ) ) . PHP_EOL;
			}
		}

		/**
	     * Allows payment for orders with on-hold status.
	     *
	     * @param array   $statuses  Default status.
	     * @param WC_Order $order     Order data.
	     * @return string
	     */
		public function on_hold_payment( $statuses, $order ) {
			if ( $this->id === $order->get_payment_method() && $order->has_status( 'on-hold' ) && $order->get_meta( '_upiwc_order_paid', true ) !== 'yes' && $this->default_status === 'on-hold' ) {
				$statuses[] = 'on-hold';
			}
		
			return $statuses;
		}

		/**
	     * Disable UPI from available payment gateways.
	     *
	     * @param array   $available_gateways  Available payment gateways.
	     * @return array
	     */
		public function disable_gateway( $available_gateways ) {
			if ( empty( $this->vpa ) || ( wp_is_mobile() && $this->hide_on_mobile === 'yes' ) ) {
			    unset( $available_gateways['wc-upi'] );
			}

			return $available_gateways;
		}

		/**
	     * Add admin list column.
	     *
	     * @param array   $columns  Columns.
	     * @return array
	     */
		public function admin_list_column( $columns ) {
			$columns['wc_upi'] = __( 'UPI Payment', 'upi-qr-code-payment-for-woocommerce' );
			return $columns;
		}

		/**
	     * Add admin list column content.
	     *
	     * @param string   $column  Column name.
	     */
		public function admin_list_column_content( $column ) {
			global $post;

			// check column
			if ( 'wc_upi' === $column ) {
				$order = wc_get_order( $post->ID );
				$payment_method = $order->get_payment_method();
				$content = '';

				// check payment method
				if ( $this->id === $payment_method ) {
					$payment_id = get_post_meta( $order->get_id(), '_transaction_id', true );
					$payment_upi_id = get_post_meta( $order->get_id(), '_transaction_upi_id', true );
					$is_paid = get_post_meta( $order->get_id(), '_upiwc_order_paid', true );
					
					// fix for old orders.
					if ( strpos( $payment_id, '@' ) !== false ) {
						$payment_id = '';
					}

					if ( 'yes' === $is_paid ) {
						if ( ! empty( $payment_id ) ) {
							$content .= sprintf( '<p><strong>%1$s</strong> %2$s</p>', __( 'UTR:', 'upi-qr-code-payment-for-woocommerce' ), $payment_id );
						}
						if ( ! empty( $payment_upi_id ) ) {
							if ( empty( $payment_id ) ) {
								$content .= sprintf( '<p><strong>%1$s</strong> %2$s</p>', __( 'Paid via:', 'upi-qr-code-payment-for-woocommerce' ), $payment_upi_id );
							} else {
								$content .= sprintf( '<span style="font-size: 12px;">%1$s %2$s</span>', __( 'Paid via:', 'upi-qr-code-payment-for-woocommerce' ), $payment_upi_id );
							}
						}
					} else {
						if ( ! empty( $payment_upi_id ) ) {
							$content .= sprintf( '<p>%1$s %2$s</p>', __( 'Initiated:', 'upi-qr-code-payment-for-woocommerce' ), $payment_upi_id );
						}
					}
				}

				echo ! empty( $content ) ? $content : '—';
			}
		}
    }
}