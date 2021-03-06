<?php

namespace Selfreliance\CoinPayments;

use Illuminate\Http\Request;
use Config;
use Route;

use Illuminate\Foundation\Validation\ValidatesRequests;

use Selfreliance\CoinPayments\Events\CoinPaymentsPaymentIncome;
use Selfreliance\CoinPayments\Events\CoinPaymentsPaymentCancel;

use Selfreliance\CoinPayments\CoinPaymentsInterface;

use Selfreliance\CoinPayments\Libs\CoinPaymentsAPI;
use Selfreliance\CoinPayments\Exceptions\CoinPaymentsException;
use Log;
use Withdraw;
use Selfreliance\Etheris\Etheris;

class CoinPayments implements CoinPaymentsInterface
{
	use ValidatesRequests;
	private $cps;
	private $unit = 'LTCT';
	function __construct(){
		$this->cps = new CoinPaymentsAPI();
		$this->cps->Setup(Config::get('coinpayments.private_key'), Config::get('coinpayments.public_key'));
	}

	public function balance($unit = false){
		if(!$unit){
			$unit = $this->unit;
		}
		$result = $this->cps->GetBalances(true);
		if ($result['error'] != 'ok'){
			throw new \Exception($result['error']);			
		}
		return $result['result'][$unit]['balancef'];
	}

	public function form($payment_id, $sum, $units){
		$req = [
			'amount'      => $sum,
			'currency1'   => $units,
			'currency2'   => $units,
			'item_name'   => 'Order '.$payment_id,
			'item_number' => $payment_id,
			'ipn_url'     => Route('coinpayments.confirm')
		];
		$result = $this->cps->CreateTransaction($req);

		if ($result['error'] != 'ok'){
			throw new \Exception($result['error']);			
		}
		$PassData = new \stdClass();
		$PassData->address = $result['result']['address'];
		$PassData->another_site = false;
		return $PassData;

	}

	public function check_transaction($request){
		
	}
	/**
     * @param array $request
     * @param array|null $server
     * @param array $headers
     * @return Ipn
     * @throws IpnIncompleteException|CoinPaymentsException
     */
	public function income_payment(array $request, array $server, $headers = []){
		Log::info('CoinPayments IPN', [
			'request' => $request,
			'headers' => $headers,
			'server'  => array_intersect_key($server, [
				'PHP_AUTH_USER', 'PHP_AUTH_PW'
			])
		]);

		try {
			$is_complete = $this->validateIPN($request, $server);
			if($is_complete){			
				$PassData                     = new \stdClass();
				$PassData->amount             = $request['received_amount'];
				$PassData->payment_id         = $request['item_number'];
				$PassData->search_by_currency = true;
				$PassData->currency           = $request['currency1'];
				$PassData->transaction        = $request['txn_id'];
				$PassData->add_info           = [
					"ipn_id"        => $request['ipn_id'],
					"full_data_ipn" => json_encode($request)
				];
				event(new CoinPaymentsPaymentIncome($PassData));			
			}
		} catch (CoinPaymentsException $e) {
			Log::error('CoinPayments IPN', [
				'message' => $e->getMessage()
			]);			
		}

	}

	/**
     * Validate the IPN request and payment.
     *
     * @param  array $post_data
     * @param  array $server_data
     * @return mixed
     * @throws CoinPaymentsException
     */
	public function validateIPN(array $post_data, array $server_data){
		if (!isset($post_data['ipn_mode'], $post_data['merchant'], $post_data['status'], $post_data['status_text'])) {
            throw new CoinPaymentsException("Insufficient POST data provided.");
        }

        if ($post_data['ipn_mode'] == 'httpauth') {
            if ($server_data['PHP_AUTH_USER'] !== Config::get('coinpayments.merchant_id')) {
                throw new CoinPaymentsException("Invalid merchant ID provided.");
            }
            if ($server_data['PHP_AUTH_PW'] !== Config::get('coinpayments.ipn_secret')) {
                throw new CoinPaymentsException("Invalid IPN secret provided.");
            }
        } elseif ($post_data['ipn_mode'] == 'hmac') {
            $hmac = hash_hmac("sha512", file_get_contents('php://input'), Config::get('coinpayments.ipn_secret'));
            if ($hmac !== $server_data['HTTP_HMAC']) {
                throw new CoinPaymentsException("Invalid HMAC provided.");
            }
            if ($post_data['merchant'] !== Config::get('coinpayments.merchant_id')) {
                throw new CoinPaymentsException("Invalid merchant ID provided.");
            }
        } else {
            throw new CoinPaymentsException("Invalid IPN mode provided.");
        }

        $order_status = $post_data['status'];

        return ($order_status >= 100 || $order_status == 2);
	}

	public function validateIPNRequest(Request $request) {
        return $this->income_payment($request->all(), $request->server(), $request->headers);
    }

	public function send_money($payment_id, $amount, $address, $currency){
		if($currency == 'ETH'){
			$EthClass = new Etheris();
			return $EthClass->send_money($payment_id, $amount, $address, $currency);
		}else{
			$auto_confirm = true;
			$ipn_url      = Route('coinpayments.webhookwithdraw');
			$result       = $this->cps->CreateWithdrawal($amount, $currency, $address, $auto_confirm, $ipn_url);
			if ($result['error'] != 'ok'){
				throw new \Exception($result['error']);			
			}

			$PassData               = new \stdClass();
			$PassData->sending      = false;
			$PassData->coinpayments = true;
			$PassData->add_info     = [
				"id"        => $result['result']['id'],
				'status'    => $result['result']['status'],
				'amount'    => $result['result']['amount'],
				"full_data" => $result
			];

			return $PassData;
		}
	}

	public function webhookwithdraw(Request $request){
		/**
		 * Добавить больше проверок валидации вход данных
		 */

		Withdraw::id($request->input('id'))->currency($request->input('currency'))->txn_id($request->input('txn_id'))->transaction_compleated();
	}

	public function cancel_payment(Request $request){
		
	}
}