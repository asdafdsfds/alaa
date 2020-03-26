<?php
defined('BASEPATH') OR exit('No direct script access allowed');
 
class two_checkout extends MX_Controller {
	public $tb_users;
	public $tb_transaction_logs;
	public $two_checkout;
	public $payment_type;
	public $currency_code;
	public $mode;

	public function __construct(){
		parent::__construct();
		$this->tb_users            = USERS;
		$this->tb_transaction_logs = TRANSACTION_LOGS;
		$this->payment_type		   = "2checkout";
		$this->mode 			   = get_option("payment_environment", "");
		$this->currency_code       = (get_option("currency_code", "USD") == "")? 'USD' : get_option("currency_code", "");
		$this->load->library("two_checkoutapi");
		$this->two_checkout = new two_checkoutapi(get_option('2checkout_private_key',""), get_option('2checkout_seller_id',""), $this->mode);
	}

	public function index(){

		redirect(cn("add_funds"));
	}

	/**
	 *
	 * Create payment
	 *
	 */
	public function create_payment(){
		$amount = session("amount");
		$token  = post("token");
		if(!empty($token)){
			// Card info
			$card_num       = post('card_num');
			$card_cvv       = post('cvv');
			$card_exp_month = post('exp_month');
			$card_exp_year  = post('exp_year');
			
			// Buyer info
			$data_buyer_info = array(
				"name" 		  => post('name'),
				"addrLine1"   => '123 Test St',
				"city" 		  => 'Columbus',
				"state" 	  => 'OH',
				"zipCode" 	  => '43123',
				"country" 	  => 'USA',
				"email" 	  => post('email'),
				"phoneNumber" => '555-555-5555'
			);
			
			// Item info

			$itemName   = 'SmartPanel';
			$itemNumber = 'SMMPANEL9271';
			$orderID    = 'SKA92712382139';//charge a credit or a debit card.

			$data_charge = array(
				"merchantOrderId" => $orderID,
				"token"      	  => $token,
				"currency"        => $this->currency_code,
				"total"			  => $amount,
				"billingAddr"     => $data_buyer_info,
			);
			$result = $this->two_checkout->create_payment($data_charge);

			if (!empty($result) && $result->status == 'success') {
				/*----------  Insert to Transaction table  ----------*/
				$response = $result->response;

				unset_session("amount");
				$data = array(
					"ids" 				=> ids(),
					"uid" 				=> session("uid"),
					"type" 				=> $this->payment_type,
					"transaction_id" 	=> $response['transactionId'],
					"amount" 	        => $response['total'],
					"created" 			=> NOW,
				);

				$this->db->insert($this->tb_transaction_logs, $data);
				$transaction_id = $this->db->insert_id();

				/*----------  Add funds to user balance  ----------*/
				$user_balance = get_field($this->tb_users, ["id" => session("uid")], "balance");
				$user_balance += session('real_amount');
				$this->db->update($this->tb_users, ["balance" => $user_balance], ["id" => session("uid")]);
				unset_session("real_amount");

				/*----------  Send payment notification email  ----------*/
				if (get_option("is_payment_notice_email", '')) {
					$CI = &get_instance();
					if(empty($CI->payment_model)){
						$CI->load->model('model', 'payment_model');
					}
					$check_send_email_issue = $CI->payment_model->send_email(get_option('email_payment_notice_subject', ''), get_option('email_payment_notice_content', ''), session('uid'));
					if($check_send_email_issue){
						ms(array(
							"status" => "error",
							"message" => $check_send_email_issue,
						));
					}
				}
				set_session("transaction_id", $transaction_id);
				redirect(cn("add_funds/success"));
			}else{
				redirect(cn("add_funds/unsuccess"));
			}
	
		}else{
			redirect(cn("add_funds"));
		}
	}
}