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
		$inputId = $id . '-token';
		$errorId = $id . '-error';
		$scriptUrl = json_encode($this->widgetScriptUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$endpoint = json_encode($this->siteEndpoint() . '/', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$inputIdJson = json_encode($inputId);
		$errorIdJson = json_encode($errorId);
		$errorMessage = htmlspecialchars((string) $errorMessage, ENT_QUOTES, 'UTF-8');

		return '<input type="hidden" name="cap-token" id="' . $inputId . '">'
			. '<span id="' . $errorId . '" role="alert" hidden>' . $errorMessage . '</span>'
			. '<script>(function(){'
			. 'var input=document.getElementById(' . $inputIdJson . ');'
			. 'var error=document.getElementById(' . $errorIdJson . ');'
			. 'var form=input&&input.closest("form");'
			. 'if(!form){return;}'
			. 'var solving=false;var resubmitting=false;var capPromise;'
			. 'form.addEventListener("submit",async function(event){'
			. 'if(resubmitting||input.value){return;}'
			. 'event.preventDefault();'
			. 'if(solving){return;}'
			. 'solving=true;error.hidden=true;form.setAttribute("aria-busy","true");'
			. 'var submitter=event.submitter||null;'
			. 'try{'
			. 'capPromise=capPromise||import(' . $scriptUrl . ').then(function(module){'
			. 'var CapConstructor=module.default||module.Cap||window.Cap;'
			. 'if(typeof CapConstructor!=="function"){throw new Error("Cap programmatic API is unavailable");}'
			. 'return new CapConstructor({apiEndpoint:' . $endpoint . '});});'
			. 'var cap=await capPromise;var solution=await cap.solve();'
			. 'if(!solution||!solution.token){throw new Error("Cap returned no token");}'
			. 'input.value=solution.token;resubmitting=true;'
			. 'if(typeof form.requestSubmit==="function"){if(submitter){form.requestSubmit(submitter);}else{form.requestSubmit();}}else{HTMLFormElement.prototype.submit.call(form);}'
			. '}catch(exception){capPromise=null;input.value="";error.hidden=false;'
			. 'if(window.console&&console.error){console.error("Cap CAPTCHA:",exception);}'
			. '}finally{solving=false;form.removeAttribute("aria-busy");}'
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
		$data = is_string($response) ? json_decode($response, true) : null;

		$result->success = is_array($data) && !empty($data['success']);
		if (!$result->success) {
			$result->errorCodes = is_array($data) && isset($data['error-codes'])
				? (array) $data['error-codes']
				: array('verification-failed');
		}

		return $result;
	}

	private function siteEndpoint()
	{
		return $this->instanceUrl . '/' . rawurlencode($this->siteKey);
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
