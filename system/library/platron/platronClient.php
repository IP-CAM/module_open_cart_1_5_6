<?php

class PlatronClient {
	private $registry;
	private $log;

	public function __construct($registry) {
		$this->registry = $registry;
		$this->log = $registry->get('log');
	}

	public function doRequest($url, $params) {
		$data = http_build_query($params);
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_NOPROGRESS, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
		$response = curl_exec($ch);
		if ($error = curl_error($ch)) {
			$this->log->write('Request to ' . $url . ' error: ' . $error);
			return null;
		}
		curl_close($ch);

		$xmlResponse = null;
		try {
			$xmlResponse = new SimpleXMLElement($response);
		} catch (Exception $e) {
			$error = $e->getMessage();
			$this->log->write('Platron response from ' . $url . ' error: ' . $error);
			return null;
		}
		
		return $xmlResponse;
	}
}