<?php
class ControllerPaymentPlatron extends Controller {
	protected function index() {
		$this->language->load('payment/platron');

		$this->data['button_confirm'] = $this->language->get('button_confirm');
		$this->data['button_back'] = $this->language->get('button_back');
		$this->data['text_wait'] = $this->language->get('text_wait');

		if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/platron.tpl')) {
            $this->template = $this->config->get('config_template') . '/template/payment/platron.tpl';
        } else {
            $this->template = 'default/template/payment/platron.tpl';
        }

        $this->render();
	}

	public function send() {
		$this->load->library('platron/platronClient');
		$this->load->library('platron/PlatronSignature');
		$this->load->library('platron/ofd');

        $this->language->load('payment/platron');

		$this->data['button_confirm'] = $this->language->get('button_confirm');
		$this->data['button_back'] = $this->language->get('button_back');

		$this->load->model('account/order');
		$order_products = $this->model_account_order->getOrderProducts($this->session->data['order_id']);
		$strOrderDescription = "";
		foreach($order_products as $product){
			$product_descriptions[] = @$product["name"] . " " . @$product["model"] . " * " . @$product["quantity"];

			$ofdReceiptItem = new OfdReceiptItem();
			$ofdReceiptItem->label = substr(@$product["name"], 0, 128);
			$ofdReceiptItem->price = round(@$product["price"] + @$product["tax"], 2);
			$ofdReceiptItem->quantity = round(@$product["quantity"], 3);
			$ofdReceiptItem->vat = $this->config->get('platron_ofd_vat');
			$ofdReceiptItems[] = $ofdReceiptItem;
		}

		if (isset($this->session->data['shipping_method']['cost']) && $this->session->data['shipping_method']['cost'] > 0) {
			$ofdReceiptItem = new OfdReceiptItem();
			$ofdReceiptItem->label = 'Доставка';
			$ofdReceiptItem->price = round($this->session->data['shipping_method']['cost'], 2);
			$ofdReceiptItem->quantity = 1;
			$ofdReceiptItem->vat = 18;
			$ofdReceiptItems[] = $ofdReceiptItem;
		}
		$strOrderDescription = implode(';', $product_descriptions);

		$this->load->model('payment/platron');
		$this->load->model('checkout/order');
		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        // Настройки

        $merchant_id = $this->config->get('platron_merchant_id');
        $secret_word = $this->config->get('platron_secret_word');
        $lifetime = $this->config->get('platron_lifetime');

        $msg_description = $this->language->get('msg_description');

        $this->load->model('payment/platron');
		
        $arrReq = array(
            'pg_amount'         => round($order_info['total'], 2),
            'pg_check_url'      => HTTPS_SERVER . 'index.php?route=payment/platron/check',
            'pg_description'    => $strOrderDescription,
            'pg_encoding'       => 'UTF-8',
			'pg_currency'       => $order_info['currency_code'],
			//'pg_user_ip'		=> $_SERVER['REMOTE_ADDR'],
            'pg_lifetime'       => !empty($lifetime) ? $lifetime * 3600 : 86400,
            'pg_merchant_id'    => $merchant_id,
            'pg_order_id'       => $order_info['order_id'],
            'pg_result_url'     => HTTPS_SERVER . 'index.php?route=payment/platron/callback',
			'pg_request_method'	=> 'POST',
            'pg_salt'           => rand(21, 43433),
            'pg_success_url'    => HTTPS_SERVER . 'index.php?route=checkout/platron_success',
            'pg_failure_url'    => HTTPS_SERVER . 'index.php?route=checkout/platron_fail',
            //'pg_user_ip'        => $_SERVER['REMOTE_ADDR'],
            'pg_user_phone'     => $order_info['telephone'],
            'pg_user_contact_email' => $order_info['email'],
			'cms_payment_module'	=> 'OPENCART',
        );

        if($this->config->get('platron_test') == 1) {
			$arrReq['pg_testing_mode'] = 1;
			unset($arrReq['pg_currency']);
        }

        $arrReq['pg_sig'] = PlatronSignature::make('init_payment.php', $arrReq, $secret_word);

        $json = array();
        $platronClient = new PlatronClient($this->registry);

        $init_payment_response = $platronClient->doRequest('https://www.platron.ru/init_payment.php', $arrReq);
		if (!$init_payment_response) {
			$json['error'] = $this->language->get('payment_failed');
		} else if (!PlatronSignature::checkXML('init_payment.php', $init_payment_response, $secret_word)) {
			$json['error'] = $this->language->get('payment_failed');
			$this->log->write('Platron init_payment response invalid signature');
		} else if ($init_payment_response->pg_status == 'error') {
			$json['error'] = $this->language->get('payment_failed');
			$this->log->write('Platron init_payment error: ' . $init_payment_response->pg_error_description);
		}

		if (!isset($json['error']) && $this->config->get('platron_ofd_send_receipt') == 1) {
			$ofdReceiptRequest = new OfdReceiptRequest($merchant_id, $init_payment_response->pg_payment_id);
			$ofdReceiptRequest->items = $ofdReceiptItems;
			$ofdReceiptRequest->prepare();
			$ofdReceiptRequest->sign($secret_word);
			$receipt_response = $platronClient->doRequest('https://www.platron.ru/receipt.php', array('pg_xml' => $ofdReceiptRequest->asXml()));
			if (!$receipt_response) {
				$json['error'] = $this->language->get('payment_failed');
			} else if (!PlatronSignature::checkXML('receipt.php', $receipt_response, $secret_word)) {
				$json['error'] = $this->language->get('payment_failed');
				$this->log->write('Platron receipt response signature');
			} else if ($receipt_response->pg_status == 'error') {
				$json['error'] = $this->language->get('payment_failed');
				$this->log->write('Platron receipt error: ' . $receipt_response->pg_error_description);
			}
		}

		if (!isset($json['error'])) {
			$json['success'] = (string) $init_payment_response->pg_redirect_url;
		}

		$this->response->setOutput(json_encode($json));
    }

    public function check() {

        $this->language->load('payment/platron');
        $this->load->model('checkout/order');
        $this->load->model('payment/platron');

        $arrResponse = array();

       	if(!empty($this->request->post))
			$data = $this->request->post;
		else
			$data = $this->request->get;
		
        $pg_sig = !empty($data['pg_sig'])?$data['pg_sig']:'';
        unset($data['pg_sig']);

        $secret_word = $this->config->get('platron_secret_word');

        if(!$this->model_payment_platron->checkSig($pg_sig, 'index.php', $data, $secret_word)) {
            die('Incorrect signature!');
        }

        // Получаем информацию о заказе
        $order_id = $data['pg_order_id'];
        $order_info = $this->model_checkout_order->getOrder($order_id);

        $arrResponse['pg_salt'] = $data['pg_salt'];

        if(isset($order_info['order_id'])) {
            $arrResponse['pg_status'] = 'ok';
            $arrResponse['pg_description'] = '';
        } else {
            $arrResponse['pg_status'] = 'rejected';
            $arrResponse['pg_description'] = $this->language->get('err_order_not_found');
        }

        $arrResponse['pg_sig'] = $this->model_payment_platron->make('index.php', $arrResponse, $secret_word);

        header('Content-type: text/xml');
        echo "<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n";
        echo "<response>\r\n";
        echo "<pg_salt>" . $arrResponse['pg_salt'] . "</pg_salt>\r\n";
        echo "<pg_status>" .$arrResponse['pg_status'] . "</pg_status>\r\n";
        echo "<pg_description>" . htmlentities($arrResponse['pg_description']). "</pg_description>\r\n";
        echo "<pg_sig>" . $arrResponse['pg_sig'] . "</pg_sig>\r\n";
        echo "</response>";

    }

    public function callback() {

        $this->language->load('payment/platron');
        $this->load->model('payment/platron');
        $this->load->model('checkout/order');

        $arrResponse = array();

		if(!empty($this->request->post))
			$data = $this->request->post;
		else
			$data = $this->request->get;
		
        $pg_sig = $data['pg_sig'];
        unset($data['pg_sig']);

        $secret_word = $this->config->get('platron_secret_word');

        if(!$this->model_payment_platron->checkSig($pg_sig, 'index.php', $data, $secret_word)) {
            die('Incorrect signature!');
        }

        // Получаем информацию о заказе
        $order_id = $data['pg_order_id'];
        $order_info = $this->model_checkout_order->getOrder($order_id);

        $arrResponse['pg_salt'] = $data['pg_salt'];

		if($data['pg_result'] != 1){
			$arrResponse['pg_status'] = 'failed';
            $arrResponse['pg_error_description'] = '';
		}
        elseif(isset($order_info['order_id'])) {
            $arrResponse['pg_status'] = 'ok';
            $arrResponse['pg_error_description'] = '';
        } else {
            $arrResponse['pg_status'] = 'rejected';
            $arrResponse['pg_error_description'] = $this->language->get('err_order_not_found');
        }

        $arrResponse['pg_sig'] = $this->model_payment_platron->make('index.php', $arrResponse, $secret_word);

        header('Content-type: text/xml');
        echo "<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n";
        echo "<response>\r\n";
        echo "<pg_salt>" . $arrResponse['pg_salt'] . "</pg_salt>\r\n";
        echo "<pg_status>" .$arrResponse['pg_status'] . "</pg_status>\r\n";
        echo "<pg_error_description>" . htmlentities($arrResponse['pg_error_description']). "</pg_error_description>\r\n";
        echo "<pg_sig>" . $arrResponse['pg_sig'] . "</pg_sig>\r\n";
        echo "</response>\r\n";

        if($arrResponse['pg_status'] == 'ok') {
            if($order_info['order_status_id'] == 0) {
                $this->model_checkout_order->confirm($order_id, $this->config->get('platron_order_status_id'), 'Platron');
                return;
            }

            if($order_info['order_status_id'] != $this->config->get('platron_order_status_id'))
                $this->model_checkout_order->update($order_id, $this->config->get('platron_order_status_id'), 'Platron', TRUE);

        }

    }
}
?>