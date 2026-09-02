<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Result returned by a Cap token verification.
 */
class CapCaptchaResponse
{
	public $success = false;
	public $errorCodes = array();
}

/**
 * Minimal client for a self-hosted Cap CAPTCHA instance.
 *
 * @see https://trycap.dev/guide/
 */
class CapCaptcha
{
	private $instanceUrl;
	private $siteKey;
	private $secret;
	private $widgetScriptUrl;
	private $widgetMode;
	private static $widgetCounter = 0;

	public function __construct($instanceUrl, $siteKey, $secret, $widgetScriptUrl, $widgetMode = 'checkbox')
	{
		$this->instanceUrl = rtrim((string) $instanceUrl, '/');
		$this->siteKey = trim((string) $siteKey);
		$this->secret = (string) $secret;
		$this->widgetScriptUrl = (string) $widgetScriptUrl;
		$this->widgetMode = strtolower(trim((string) $widgetMode));

		if (!$this->isHttpUrl($this->instanceUrl) || $this->siteKey === '' || $this->secret === '') {
			throw new InvalidArgumentException('Cap requires a valid instance URL, site key and secret.');
		}

		if (!$this->isHttpUrl($this->widgetScriptUrl)) {
			throw new InvalidArgumentException('Cap requires a valid widget script URL.');
		}

		if (!in_array($this->widgetMode, array('checkbox', 'invisible'), true)) {
			throw new InvalidArgumentException("Cap widget mode must be 'checkbox' or 'invisible'.");
		}
	}

	/**
	 * Render the configured Cap integration.
	 */
	public function renderWidget($errorMessage = 'CAPTCHA verification failed. Please try again.')
	{
		if ($this->widgetMode === 'invisible') {
			return $this->renderInvisibleWidget($errorMessage);
		}

		$scriptUrl = htmlspecialchars($this->widgetScriptUrl, ENT_QUOTES, 'UTF-8');
		$endpoint = htmlspecialchars($this->siteEndpoint() . '/', ENT_QUOTES, 'UTF-8');

		return '<script type="module" src="' . $scriptUrl . '"></script>'
			. '<cap-widget required data-cap-api-endpoint="' . $endpoint . '"></cap-widget>';
	}

	/**
	 * Solve Cap when the surrounding form is submitted, without a visible checkbox.
	 */
	private function renderInvisibleWidget($errorMessage)
	{
		self::$widgetCounter++;
		$id = 'cap-captcha-' . self::$widgetCounter;
		$errorId = $id . '-error';
		$scriptUrl = json_encode($this->widgetScriptUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$endpoint = json_encode($this->siteEndpoint() . '/', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$scriptIdJson = json_encode($id);
		$errorIdJson = json_encode($errorId);
		$errorMessage = htmlspecialchars((string) $errorMessage, ENT_QUOTES, 'UTF-8');

		return '<span id="' . $errorId . '" role="alert" hidden>' . $errorMessage . '</span>'
			. '<script id="' . $id . '">(function(){'
			. 'var script=document.getElementById(' . $scriptIdJson . ');'
			. 'var error=document.getElementById(' . $errorIdJson . ');'
			. 'var form=script&&script.closest("form");'
			. 'if(!form){return;}'
			. 'var capPromise;var capInstance;var preparation;'
			. 'function getCap(){'
			. 'capPromise=capPromise||import(' . $scriptUrl . ').then(function(module){'
			. 'var CapConstructor=module.default||module.Cap||window.Cap;'
			. 'if(typeof CapConstructor!=="function"){throw new Error("Cap programmatic API is unavailable");}'
			. 'capInstance=new CapConstructor({apiEndpoint:' . $endpoint . '});return capInstance;});'
			. 'return capPromise;}'
			. 'function clearToken(){var input=form.querySelector("input[name=\"cap-token\"]");if(input){input.remove();}}'
			. 'function prepareToken(showError){'
			. 'var existing=form.querySelector("input[name=\"cap-token\"]");if(existing&&existing.value){return Promise.resolve(existing.value);}'
			. 'if(preparation){return preparation;}'
			. 'error.hidden=true;form.setAttribute("aria-busy","true");'
			. 'preparation=getCap().then(function(cap){return cap.solve();}).then(function(solution){'
			. 'if(!solution||solution.success===false||!solution.token){throw new Error("Cap returned no token");}'
			. 'clearToken();var input=document.createElement("input");input.type="hidden";input.name="cap-token";input.value=solution.token;form.appendChild(input);return solution.token;'
			. '}).catch(function(exception){clearToken();if(capInstance&&typeof capInstance.reset==="function"){capInstance.reset();}'
			. 'if(showError){error.hidden=false;}if(window.console&&console.error){console.error("Cap CAPTCHA:",exception);}throw exception;'
			. '}).finally(function(){preparation=null;form.removeAttribute("aria-busy");});return preparation;}'
			. 'var prefetched=prepareToken(false).catch(function(){return null;});'
			. 'form.addEventListener("submit",async function(event){event.preventDefault();error.hidden=true;'
			. 'try{var token=await prefetched;if(!token){token=await prepareToken(true);}if(!token){throw new Error("Cap returned no token");}'
			. 'HTMLFormElement.prototype.submit.call(form);'
			. '}catch(exception){error.hidden=false;}'
			. '});'
			. '})();</script>';
	}

	/**
	 * Verify a single-use token with the Cap instance.
	 */
	public function verifyResponse($token)
	{
		$result = new CapCaptchaResponse();
		$token = trim((string) $token);

		if ($token === '') {
			$result->errorCodes = array('missing-input');
			return $result;
		}

		$payload = json_encode(array(
			'secret' => $this->secret,
			'response' => $token,
		), JSON_THROW_ON_ERROR);
		$response = false;
		$statusCode = 0;

		if (function_exists('curl_init')) {
			$curl = curl_init($this->siteEndpoint() . '/siteverify');
			$options = array(
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $payload,
				CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => 10,
			);
			if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
				$options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
			}
			curl_setopt_array($curl, $options);
			$response = curl_exec($curl);
			$statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
			if ($response === false) {
				$result->errorCodes = array('transport-error-' . (int) curl_errno($curl));
			}
			curl_close($curl);
		} else {
			$context = stream_context_create(array(
				'http' => array(
					'method' => 'POST',
					'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
					'content' => $payload,
					'timeout' => 10,
					'ignore_errors' => true,
				),
			));
			$response = @file_get_contents($this->siteEndpoint() . '/siteverify', false, $context);
			$statusCode = $this->statusCodeFromHeaders(isset($http_response_header) ? $http_response_header : array());
		}

		$data = is_string($response) ? json_decode($response, true) : null;

		$result->success = $statusCode >= 200 && $statusCode < 300
			&& is_array($data) && isset($data['success']) && $data['success'] === true;
		if (!$result->success) {
			if (!$result->errorCodes) {
				$result->errorCodes = is_array($data) && isset($data['error-codes'])
					? (array) $data['error-codes']
					: array($statusCode ? 'http-' . $statusCode : 'invalid-response');
			}
		}

		return $result;
	}

	private function siteEndpoint()
	{
		return $this->instanceUrl . '/' . rawurlencode($this->siteKey);
	}

	private function statusCodeFromHeaders(array $headers)
	{
		foreach (array_reverse($headers) as $header) {
			if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $header, $matches)) {
				return (int) $matches[1];
			}
		}

		return 0;
	}

	private function isHttpUrl($url)
	{
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			return false;
		}

		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
		return $scheme === 'http' || $scheme === 'https';
	}
}
