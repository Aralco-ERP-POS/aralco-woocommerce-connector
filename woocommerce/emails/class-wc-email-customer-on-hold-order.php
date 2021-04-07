<?php
/**
 * Class WC_Email_Customer_On_Hold_Order file.
 *
 * @package WooCommerce\Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WC_Email_Customer_On_Hold_Order_Aralco', false ) ) :

	/**
	 * Customer On-hold Order Email.
	 *
	 * An email sent to the customer when a new order is on-hold for.
	 *
	 * @class       WC_Email_Customer_On_Hold_Order_Aralco
	 * @version     2.6.0
	 * @package     WooCommerce/Classes/Emails
	 * @extends     WC_Email
	 */
	class WC_Email_Customer_On_Hold_Order_Aralco extends WC_Email {

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id             = 'customer_on_hold_order';
			$this->customer_email = true;
			$this->title          = __( 'Order/Quote on-hold', 'woocommerce' );
			$this->description    = __( 'This is an order/quote notification sent to customers containing order/quote details after an order/quote is placed on-hold.', 'woocommerce' );
			$this->template_html  = 'emails/customer-on-hold-order.php';
			$this->template_plain = 'emails/plain/customer-on-hold-order.php';
			$this->placeholders   = array(
				'{order_date}'   => '',
				'{order_number}' => '',
                '{trans_type}' => '',
			);

			// Triggers for this email.
			add_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $this, 'trigger' ), 10, 2 );
			add_action( 'woocommerce_order_status_failed_to_on-hold_notification', array( $this, 'trigger' ), 10, 2 );
			add_action( 'woocommerce_order_status_cancelled_to_on-hold_notification', array( $this, 'trigger' ), 10, 2 );

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Get email subject.
		 *
		 * @since  3.1.0
		 * @return string
		 */
		public function get_default_subject() {
			return __( 'Your {site_title} {trans_type} has been received!', ARALCO_SLUG );
		}

		/**
		 * Get email heading.
		 *
		 * @since  3.1.0
		 * @return string
		 */
		public function get_default_heading() {
			return __( 'Thank you for your {trans_type}', ARALCO_SLUG );
		}

		/**
		 * Trigger the sending of this email.
		 *
		 * @param int            $order_id The order ID.
		 * @param WC_Order|false $order Order object.
		 */
		public function trigger( $order_id, $order = false ) {
			$this->setup_locale();
            $options = get_option(ARALCO_SLUG . '_options');
            $trans_type = (isset($options[ARALCO_SLUG . '_field_order_is_quote']) &&
                $options[ARALCO_SLUG . '_field_order_is_quote'] == '1') ? 'Quote' : 'Order';

			if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
				$order = wc_get_order( $order_id );
			}

			if ( is_a( $order, 'WC_Order' ) ) {
				$this->object                         = $order;
				$this->recipient                      = $this->object->get_billing_email();
				$this->placeholders['{order_date}']   = wc_format_datetime( $this->object->get_date_created() );
				$this->placeholders['{order_number}'] = $this->object->get_order_number();
                $this->placeholders['{trans_type}']   = $trans_type;
			}

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}

			$this->restore_locale();
		}

		/**
		 * Get content html.
		 *
		 * @return string
		 */
		public function get_content_html() {
			return wc_get_template_html(
				$this->template_html,
				array(
					'order'              => $this->object,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => false,
					'plain_text'         => false,
					'email'              => $this,
				)
			);
		}

		/**
		 * Get content plain.
		 *
		 * @return string
		 */
		public function get_content_plain() {
			return wc_get_template_html(
				$this->template_plain,
				array(
					'order'              => $this->object,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => false,
					'plain_text'         => true,
					'email'              => $this,
				)
			);
		}

		/**
		 * Default content to show below main email content.
		 *
		 * @since 3.7.0
		 * @return string
		 */
		public function get_default_additional_content() {
			return __( 'We look forward to fulfilling your {trans_type} soon.', ARALCO_SLUG );
		}
	}

endif;

return new WC_Email_Customer_On_Hold_Order_Aralco();
