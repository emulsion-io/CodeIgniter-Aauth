<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Aauth is a User Authorization Library for CodeIgniter 3.x, which aims to make
 * easy some essential jobs such as login, permissions and access operations.
 * Despite ease of use, it has also very advanced features like private messages,
 * groupping, access management, public access etc..
 *
 * @author		Emre Akay <emreakayfb@hotmail.com>
 * @contributor Jacob Tomlinson
 * @contributor Tim Swagger (Renowne, LLC) <tim@renowne.com>
 * @contributor Raphael Jackstadt <info@rejack.de>
 * @contributor Simonet Fabrice <fabrice@emulsion.io>
 *
 * @copyright 2014-2018 Emre Akay
 * @copyright 2026 Simonet Fabrice
 *
 * @version 3
 * @requires PHP 8.2+
 * @see https://github.com/pocketarc/codeigniter Compatible CodeIgniter fork
 *
 * @license LGPL
 * @license http://opensource.org/licenses/LGPL-3.0 Lesser GNU Public License
 *
 * The latest version of Aauth can be obtained from:
 * https://github.com/emulsion-io/CodeIgniter-Aauth
 * 
 * Original repository:
 * https://github.com/emreakay/CodeIgniter-Aauth
 *
 */
class Aauth {

	/**
	 * The CodeIgniter object variable
	 * @access public
	 * @var object
	 */
	public $CI;

	/**
	 * Variable for loading the config array into
	 * @access public
	 * @var array
	 */
	public $config_vars;

	/**
	 * Array to store error messages
	 * @access public
	 * @var array
	 */
	public $errors = array();

	/**
	 * Array to store info messages
	 * @access public
	 * @var array
	 */
	public $infos = array();

	/**
	 * Local temporary storage for current flash errors
	 *
	 * Used to update current flash data list since flash data is only available on the next page refresh
	 * @access public
	 * var array
	 */
	public $flash_errors = array();

	/**
	 * Local temporary storage for current flash infos
	 *
	 * Used to update current flash data list since flash data is only available on the next page refresh
	 * @access public
	 * var array
	 */
	public $flash_infos = array();

	/**
	 * The CodeIgniter object variable
	 * @access public
	 * @var object
	 */
	public $aauth_db;

	/**
	 * Array to cache permission-ids.
	 * @access private
	 * @var array
	 */
	private $cache_perm_id;

	/**
	 * Array to cache group-ids.
	 * @access private
	 * @var array
	 */
	private $cache_group_id;

	/**
	 * Active CAPTCHA provider: recaptcha, cap or FALSE.
	 * @var string|bool
	 */
	private $captcha_provider = false;

	########################
	# Base Functions
	########################

	/**
	 * Constructor
	 */
	public function __construct() {

		// get main CI object
		$this->CI = & get_instance();

		// Dependencies
		$this->CI->load->library('session');
		$this->CI->lang->load('aauth');

 		// config/aauth.php
		$this->CI->config->load('aauth');
		$this->config_vars = $this->CI->config->item('aauth');
		$this->configure_login_throttling();
		$this->configure_totp_recovery_codes();
		$this->captcha_provider = $this->resolve_captcha_provider();

		$this->aauth_db = $this->CI->load->database($this->config_vars['db_profile'], true);

		// load error and info messages from flashdata (but don't store back in flashdata)
		$this->errors = $this->CI->session->flashdata('errors') ?: array();
		$this->infos = $this->CI->session->flashdata('infos') ?: array();

		// Initialize Variables

		$this->cache_perm_id		= array();
		$this->cache_group_id		= array();
		
		// Pre-Cache IDs
		$this->precache_perms();
		$this->precache_groups();

	}

	/**
	 * Supply v3 throttling defaults while keeping older application config files
	 * usable. The legacy max_login_attempt value becomes the identifier limit;
	 * the IP limit is deliberately higher to avoid penalizing shared networks.
	 */
	private function configure_login_throttling() {
		$legacy_enabled = array_key_exists('ddos_protection', $this->config_vars)
			&& $this->config_vars['ddos_protection'] !== null
			? (bool) $this->config_vars['ddos_protection']
			: null;
		$legacy_limit = !empty($this->config_vars['max_login_attempt'])
			? max(1, (int) $this->config_vars['max_login_attempt'])
			: null;

		$defaults = array(
			'login_throttling' => $legacy_enabled !== null ? $legacy_enabled : true,
			'login_throttle_identifier_limit' => $legacy_limit ?: 5,
			'login_throttle_ip_limit' => $legacy_limit ? max(30, $legacy_limit * 3) : 30,
			'login_throttle_totp_identifier_limit' => 10,
			'login_throttle_totp_ip_limit' => 30,
			'login_throttle_lockout_time' => '15 minutes',
			'login_throttle_secret' => '',
			'login_throttle_cleanup_probability' => 100,
			'login_throttle_cleanup_after' => '1 day',
			'max_login_attempt_time_period' => '15 minutes',
			'remove_successful_attempts' => true,
		);

		$this->config_vars = array_merge($defaults, $this->config_vars);
		if ($legacy_enabled !== null) {
			$this->config_vars['login_throttling'] = $legacy_enabled;
		}
	}

	/**
	 * Supply recovery-code defaults for applications that keep their own config.
	 */
	private function configure_totp_recovery_codes() {
		$defaults = array(
			'totp_recovery_codes' => 'aauth_totp_recovery_codes',
			'totp_recovery_code_count' => 10,
		);

		$this->config_vars = array_merge($defaults, $this->config_vars);
	}
	
	/**
	 * precache_perms() caches all permission IDs for later use.
	 */
	private function precache_perms() {
		$query	= $this->aauth_db->get($this->config_vars['perms']);

		foreach ($query->result() as $row) {
			$key				= str_replace(' ', '', trim(strtolower($row->name)));
			$this->cache_perm_id[$key]	= $row->id;
		}
	}
	
	/**
	 * precache_groups() caches all group IDs for later use.
	 */
	private function precache_groups() {
		$query	= $this->aauth_db->get($this->config_vars['groups']);

		foreach ($query->result() as $row) {
			$key				= str_replace(' ', '', trim(strtolower($row->name)));
			$this->cache_group_id[$key]	= $row->id;
		}
	}

	/**
	 * Resolve the single active CAPTCHA provider while supporting the legacy
	 * recaptcha_active option.
	 */
	private function resolve_captcha_provider() {
		$provider = isset($this->config_vars['captcha_provider'])
			? strtolower(trim((string) $this->config_vars['captcha_provider']))
			: '';

		if ($provider === '' || $provider === 'false' || $provider === 'none') {
			$provider = false;
		}

		if ($provider !== false && !in_array($provider, array('recaptcha', 'cap'), true)) {
			throw new InvalidArgumentException("Unsupported CAPTCHA provider: {$provider}");
		}

		$legacyRecaptcha = !empty($this->config_vars['recaptcha_active']);
		if ($legacyRecaptcha && $provider === 'cap') {
			throw new InvalidArgumentException('Cap and reCAPTCHA cannot be enabled at the same time.');
		}

		return $provider ?: ($legacyRecaptcha ? 'recaptcha' : false);
	}

	/**
	 * Whether a CAPTCHA is required for the current login attempt.
	 */
	private function captcha_is_required($identifier = false) {
		return $this->captcha_provider
			&& $this->config_vars['login_throttling']
			&& $this->get_login_attempts($identifier) >= $this->config_vars['recaptcha_login_attempts'];
	}

	/**
	 * Verify the response for the configured CAPTCHA provider.
	 */
	private function verify_captcha_response($identifier = false) {
		if (!$this->captcha_is_required($identifier)) {
			return true;
		}

		try {
			if ($this->captcha_provider === 'recaptcha') {
				$this->CI->load->helper('recaptchalib');
				$captcha = new ReCaptcha($this->config_vars['recaptcha_secret']);
				$response = $captcha->verifyResponse(
					$this->CI->input->server('REMOTE_ADDR'),
					$this->CI->input->post('g-recaptcha-response')
				);
			} else {
				$this->CI->load->helper('cap');
				$captcha = new CapCaptcha(
					$this->config_vars['cap_instance_url'],
					$this->config_vars['cap_site_key'],
					$this->config_vars['cap_secret'],
					$this->config_vars['cap_widget_script_url']
				);
				$response = $captcha->verifyResponse($this->CI->input->post('cap-token'));
			}
		} catch (Throwable $exception) {
			log_message('error', 'Aauth CAPTCHA configuration error: ' . $exception->getMessage());
			$this->error($this->captcha_error_message());
			return false;
		}

		if (!$response->success) {
			$error_codes = isset($response->errorCodes) ? (array) $response->errorCodes : array('unknown');
			$error_codes = array_map(function ($code) {
				return preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $code);
			}, $error_codes);
			log_message('error', 'Aauth CAPTCHA verification failed: ' . implode(', ', $error_codes));
			$this->error($this->captcha_error_message());
			return false;
		}

		return true;
	}

	private function captcha_error_message() {
		$message = $this->CI->lang->line('aauth_error_captcha_not_correct');
		return $message ?: $this->CI->lang->line('aauth_error_recaptcha_not_correct');
	}
	
	########################
	# Login Functions
	########################

	/**
	 * Login user
	 * Check provided details against the database. Add items to error array on fail, create session if success
	 * 
	 * @param string $identifier
	 * @param string $pass
	 * @param string|null $totp_code
	 * @return bool Indicates successful login.
	 */
	public function login($identifier, $pass, $totp_code = null) {
		if( $this->config_vars['login_with_name'] == true){

			if (!is_string($identifier) OR trim($identifier) === '' OR !is_string($pass) OR $pass === '')
			{
				$this->error($this->CI->lang->line('aauth_error_login_failed_name'));
				return false;
			}
			$db_identifier = 'username';
		}else{
			$this->CI->load->helper('email');
			if (!is_string($identifier) OR !valid_email($identifier) OR !is_string($pass) OR $pass === '')
			{
				$this->error($this->CI->lang->line('aauth_error_login_failed_email'));
				return false;
			}
			$db_identifier = 'email';
		}

		$identifier = trim($identifier);
		$throttle_identifier = $this->normalize_login_identifier($identifier);
		if ($this->config_vars['login_throttling'] && !$this->login_attempt_is_allowed($throttle_identifier)) {
			log_message('info', 'Aauth login throttled for IP and/or identifier bucket.');
			$this->error($this->CI->lang->line('aauth_error_login_attempts_exceeded'));
			return false;
		}
		if (!$this->verify_captcha_response($throttle_identifier)) {
			$this->record_login_failure($throttle_identifier);
			return false;
		}

		// Fetch the account once, then evaluate its independent states.
		$query = $this->aauth_db->where($db_identifier, $identifier);
		$query = $this->aauth_db->get($this->config_vars['users']);

		if($query->num_rows() == 0){
			$this->error($this->CI->lang->line('aauth_error_login_failed_all'));
			$this->record_login_failure($throttle_identifier);
			return false;
		}
		$row = $query->row();

		if ($row->email_verified_at === null) {
			$this->error($this->CI->lang->line('aauth_error_login_failed_all'));
			$this->record_login_failure($throttle_identifier);
			return false;
		}

		if ($row->banned_at !== null) {
			$this->error($this->CI->lang->line('aauth_error_login_failed_all'));
			$this->record_login_failure($throttle_identifier);
			return false;
		}

		if (!$this->verify_password($pass, $row->pass, $row->id)) {
			$this->error($this->CI->lang->line('aauth_error_login_failed_all'));
			$this->record_login_failure($throttle_identifier);
			return false;
		}

		if ($this->user_requires_totp($row)) {
			if (empty($totp_code)) {
				$this->error($this->CI->lang->line('aauth_error_totp_code_required'));

				if ($this->config_vars['totp_two_step_login_active']) {
					$this->CI->session->set_userdata(array(
						'totp_required' => true,
						'totp_user_id' => $row->id,
					));
				}

				return false;
			}

			if ($this->config_vars['login_throttling']
				&& !$this->login_attempt_is_allowed($throttle_identifier, 'totp')) {
				log_message('info', 'Aauth TOTP verification throttled for IP and/or identifier bucket.');
				$this->error($this->CI->lang->line('aauth_error_login_attempts_exceeded'));
				return false;
			}

			if (!$this->verify_second_factor_code($row, $totp_code)) {
				$this->error($this->CI->lang->line('aauth_error_totp_code_invalid'));
				$this->record_login_failure($throttle_identifier, 'totp');
				return false;
			}
		}

		return $this->complete_login($row);
	}

	/**
	 * Determine whether a user must provide a TOTP code for this login.
	 * 
	 * @param object $user The user object.
	 * @return bool True if the user must provide a TOTP code, false otherwise.
	 */
	private function user_requires_totp($user) {
		if (!$this->config_vars['totp_active'] || empty($user->totp_secret)) {
			return false;
		}

		return !$this->config_vars['totp_only_on_ip_change']
			|| $user->ip_address !== $this->CI->input->ip_address();
	}

	/**
	 * Verify a TOTP code against a secret.
	 * 
	 * @param string $secret The TOTP secret.
	 * @param string $totp_code The TOTP code to verify.
	 * @return bool True if the code is valid, false otherwise.
	 */
	private function verify_totp_code($secret, $totp_code) {
		$this->CI->load->helper('googleauthenticator');
		$ga = new PHPGangsta_GoogleAuthenticator();
		return $ga->verifyCode($secret, (string) $totp_code, 1);
	}

	/**
	 * Verify a current TOTP value or consume a one-time recovery code.
	 */
	private function verify_second_factor_code($user, $code) {
		$code = trim((string) $code);
		if (preg_match('/^[0-9]{6}$/', $code)
			&& $this->verify_totp_code($user->totp_secret, $code)) {
			return true;
		}

		return $this->consume_totp_recovery_code($code, $user->id);
	}

	/**
	 * Create the authenticated session.
	 * 
	 * @param object $user The user object.
	 * @return bool True on successful login.
	 */
	private function complete_login($user) {
		$this->CI->session->sess_regenerate(true);
		$this->CI->session->set_userdata(array(
			'id' => $user->id,
			'username' => $user->username,
			'email' => $user->email,
			'loggedin' => true,
		));

		$this->CI->session->unset_userdata(array('totp_required', 'totp_user_id'));

		$this->update_last_login($user->id);
		$this->update_activity($user->id);

		if ($this->config_vars['remove_successful_attempts']) {
			$identifier_field = $this->config_vars['login_with_name'] ? 'username' : 'email';
			$identifier = $this->normalize_login_identifier($user->{$identifier_field});
			$this->reset_login_attempt_buckets($identifier, 'login', false);
			$this->reset_login_attempt_buckets($identifier, 'totp', false);
		}

		return true;
	}

	/**
	 * Check user login
	 * Checks if the CodeIgniter session is authenticated.
	 * 
	 * @return bool
	 */
	public function is_loggedin() {
		return (bool) $this->CI->session->userdata('loggedin');
	}

	/**
	 * Controls if a logged or public user has permission
	 *
	 * If user does not have permission to access page, it stops script and gives
	 * error message, unless 'no_permission' value is set in config.  If 'no_permission' is
	 * set in config it redirects user to the set url and passes the 'no_access' error message.
	 * It also updates last activity every time function called.
	 *
	 * @param bool $perm_par If not given just control user logged in or not
	 */
	public function control( $perm_par = false ){

		$this->CI->load->helper('url');

		if($this->CI->session->userdata('totp_required')){
			$this->error($this->CI->lang->line('aauth_error_totp_verification_required'));
			redirect($this->config_vars['totp_two_step_login_redirect']);
		}

		$perm_id = $this->get_perm_id($perm_par);
		$this->update_activity();
		if($perm_par == false){
			if($this->is_loggedin()){
				return true;
			}else {
				$this->error($this->CI->lang->line('aauth_error_no_access'));
				if($this->config_vars['no_permission'] !== false){
					redirect($this->config_vars['no_permission']);
				}
			}

		}else if ( ! $this->is_allowed($perm_id) ){
			if( $this->config_vars['no_permission'] ) {
				$this->error($this->CI->lang->line('aauth_error_no_access'));
				if($this->config_vars['no_permission'] !== false){
					redirect($this->config_vars['no_permission']);
				}
			}
			else {
				echo $this->CI->lang->line('aauth_error_no_access');
				die();
			}
		}
	}

	/**
	 * Logout user
	 * Destroys the CodeIgniter session.
	 * 
	 * @return bool If session destroy successful
	 */
	public function logout() {
		return $this->CI->session->sess_destroy();
	}

	/**
	 * Reset last login attempts
	 * Removes a Login Attempt
	 * 
	 * @return bool Reset fails/succeeds
	 */
	public function reset_login_attempts($identifier = false) {
		if ($identifier === false) {
			return $this->reset_login_attempt_buckets(false, 'login', true);
		}

		return $this->reset_login_attempt_buckets(
			$this->normalize_login_identifier($identifier),
			'login',
			true
		);
	}

	/**
	 * Create a temporary, single-use password reset link without sending it.
	 *
	 * @param string $email User email address
	 * @return string|bool Reset link, or FALSE when the account is not found
	 */
	public function create_password_reset_link($email){
		if (!is_string($email) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
			return false;
		}
		$email = trim($email);

		$query = $this->aauth_db->where('email', $email);
		$query = $this->aauth_db->where('email_verified_at IS NOT NULL', null, false);
		$query = $this->aauth_db->where('banned_at', null);
		$query = $this->aauth_db->get($this->config_vars['users']);

		if ($query->num_rows() < 1) {
			return false;
		}

		$expiration = isset($this->config_vars['reset_password_expiration'])
			? $this->config_vars['reset_password_expiration']
			: '+1 hour';
		$expires = strtotime($expiration);
		if ($expires === false || $expires <= time()) {
			throw new InvalidArgumentException('reset_password_expiration must resolve to a future date.');
		}

		$token = bin2hex(random_bytes(32));
		$data = array(
			'verification_code' => 'reset:' . hash('sha256', $token),
			'forgot_exp' => date('Y-m-d H:i:s', $expires),
		);

		$this->aauth_db->where('id', $query->row()->id);
		if (!$this->aauth_db->update($this->config_vars['users'], $data)) {
			return false;
		}

		$this->CI->load->helper('url');
		$path = trim($this->config_vars['reset_password_link'], '/') . '/' . rawurlencode($token);
		return site_url($path);
	}

	/**
	 * Email a temporary password reset link.
	 *
	 * Kept for backward compatibility. Use create_password_reset_link() when
	 * another trusted delivery channel is responsible for sharing the link.
	 *
	 * @param string $email User email address
	 * @return bool Email sent successfully
	 */
	public function remind_password($email){
		$email = is_string($email) ? trim($email) : '';
		$link = $this->create_password_reset_link($email);
		if ($link === false) {
			return false;
		}

		$this->CI->load->library('email');
		if(isset($this->config_vars['email_config']) && is_array($this->config_vars['email_config'])){
			$this->CI->email->initialize($this->config_vars['email_config']);
		}

		$this->CI->email->from($this->config_vars['email'], $this->config_vars['name']);
		$this->CI->email->to($email);
		$this->CI->email->subject($this->CI->lang->line('aauth_email_reset_subject'));
		$this->CI->email->message($this->CI->lang->line('aauth_email_reset_text') . $link);
		return (bool) $this->CI->email->send();
	}

	/**
	 * Check whether a temporary password reset token is valid.
	 * 
	 * @param string $token Password reset token
	 * @return bool True if the token is valid, false otherwise.
	 */
	public function is_password_reset_token_valid($token){
		return $this->get_password_reset_user($token) !== false;
	}

	/**
	 * Set a new password using a temporary, single-use token.
	 *
	 * @param string $token Password reset token
	 * @param string|null $password New password chosen by the user, or NULL for
	 * legacy generated-password mode
	 * @return bool Password reset fails/succeeds
	 */
	public function reset_password($token, $password = null){
		$generated_password = $password === null && $this->password_recovery_mode() === 'generated_password';
		if ($generated_password) {
			$password = $this->generate_temporary_password();
		}

		if (!is_string($password)
			|| strlen($password) < $this->config_vars['min']
			|| strlen($password) > $this->config_vars['max']) {
			$this->error($this->CI->lang->line('aauth_error_password_invalid'));
			return false;
		}

		$row = $this->get_password_reset_user($token);
		if ($row === false) {
			$this->error($this->CI->lang->line('aauth_error_vercode_invalid'));
			return false;
		}

		$data = array(
			'verification_code' => '',
			'forgot_exp' => null,
			'pass' => $this->hash_password($password, $row->id),
		);

		$reset_totp = $this->config_vars['totp_active'] == true
			&& $this->config_vars['totp_reset_over_reset_password'] == true;
		if ($reset_totp) {
			$data['totp_secret'] = null;
		}

		$transactional = $generated_password || $reset_totp;
		if ($transactional) {
			if (!$this->aauth_db->trans_begin()) {
				return false;
			}
		}

		$this->aauth_db->where('id', $row->id);
		$this->aauth_db->where('verification_code', 'reset:' . hash('sha256', $token));
		if (!$this->aauth_db->update($this->config_vars['users'], $data)) {
			if ($transactional) {
				$this->aauth_db->trans_rollback();
			}
			return false;
		}

		if ($this->aauth_db->affected_rows() !== 1) {
			if ($transactional) {
				$this->aauth_db->trans_rollback();
			}
			return false;
		}

		if ($reset_totp && !$this->delete_totp_recovery_codes($row->id)) {
			$this->aauth_db->trans_rollback();
			return false;
		}

		if (!$generated_password) {
			if ($transactional) {
				return (bool) $this->aauth_db->trans_commit();
			}
			return true;
		}

		if (!$this->send_generated_password($row->email, $password)
			|| $this->aauth_db->trans_status() === false) {
			$this->aauth_db->trans_rollback();
			return false;
		}

		return (bool) $this->aauth_db->trans_commit();
	}

	/**
	 * Return the configured password recovery mode.
	 */
	private function password_recovery_mode(){
		$mode = isset($this->config_vars['password_recovery_mode'])
			? strtolower(trim((string) $this->config_vars['password_recovery_mode']))
			: 'link';

		if (!in_array($mode, array('link', 'generated_password'), true)) {
			throw new InvalidArgumentException('password_recovery_mode must be "link" or "generated_password".');
		}

		return $mode;
	}

	/**
	 * Generate a cryptographically secure temporary password within configured limits.
	 */
	private function generate_temporary_password(){
		$min_length = (int) $this->config_vars['min'];
		$max_length = (int) $this->config_vars['max'];
		if ($min_length < 1 || $max_length < $min_length) {
			throw new InvalidArgumentException('Invalid Aauth password length configuration.');
		}

		$length = min($max_length, max($min_length, 20));
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_';
		$password = '';
		$last_index = strlen($alphabet) - 1;
		for ($i = 0; $i < $length; $i++) {
			$password .= $alphabet[random_int(0, $last_index)];
		}

		return $password;
	}

	/**
	 * Send the generated password used by the opt-in legacy recovery mode.
	 * 
	 * @param string $email The recipient's email address.
	 * @param string $password The generated temporary password.
	 * @return bool True if the email was sent successfully, false otherwise.
	 */
	private function send_generated_password($email, $password){
		$this->CI->load->library('email');
		if(isset($this->config_vars['email_config']) && is_array($this->config_vars['email_config'])){
			$this->CI->email->initialize($this->config_vars['email_config']);
		}

		$this->CI->email->from($this->config_vars['email'], $this->config_vars['name']);
		$this->CI->email->to($email);
		$this->CI->email->subject($this->CI->lang->line('aauth_email_reset_success_subject'));
		$this->CI->email->message(
			$this->CI->lang->line('aauth_email_reset_success_new_password') . $password
		);

		return (bool) $this->CI->email->send();
	}

	/**
	 * Return the user associated with an unexpired reset token.
	 *
	 * @param string $token The password reset token.
	 * @return object|bool
	 */
	private function get_password_reset_user($token){
		if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
			return false;
		}

		$this->aauth_db->where('verification_code', 'reset:' . hash('sha256', $token));
		$this->aauth_db->where('forgot_exp >=', date('Y-m-d H:i:s'));
		$this->aauth_db->where('email_verified_at IS NOT NULL', null, false);
		$this->aauth_db->where('banned_at', null);
		$query = $this->aauth_db->get($this->config_vars['users']);

		return $query->num_rows() > 0 ? $query->row() : false;
	}

	/**
	 * Update last login
	 * Update user's last login date
	 * 
	 * @param int|bool $user_id User id to update or FALSE for current user
	 * @return bool Update fails/succeeds
	 */
	public function update_last_login($user_id = false) {

		if ($user_id == false)
			$user_id = $this->CI->session->userdata('id');

		$data['last_login'] = date("Y-m-d H:i:s");
		$data['ip_address'] = $this->CI->input->ip_address();

		$this->aauth_db->where('id', $user_id);
		return $this->aauth_db->update($this->config_vars['users'], $data);
	}


	/**
	 * Record a failed login attempt. Passing an identifier updates both the IP
	 * and identifier buckets; omitting it preserves the old IP-only helper API.
	 *
	 * @param string|bool $identifier Login email/username, or FALSE
	 * @return bool TRUE when a following attempt is still allowed
	 */
	public function update_login_attempts($identifier = false) {
		$normalized = $identifier === false
			? false
			: $this->normalize_login_identifier($identifier);
		$this->record_login_failure($normalized);

		return $this->login_attempt_is_allowed($normalized);
	}

	/**
	 * Return the largest active login counter for the current IP and, when
	 * supplied, the normalized identifier.
	 *
	 * @param string|bool $identifier Login email/username, or FALSE
	 * @return int
	 */
	public function get_login_attempts($identifier = false) {
		$normalized = $identifier === false
			? false
			: $this->normalize_login_identifier($identifier);
		$buckets = $this->login_throttle_buckets($normalized, 'login');
		$cutoff = $this->login_throttle_cutoff();
		$maximum = 0;

		foreach ($buckets as $bucket) {
			$row = $this->get_login_throttle_bucket($bucket);
			if ($row && $row->window_started_at >= $cutoff) {
				$maximum = max($maximum, (int) $row->attempts);
			}
		}

		return $maximum;
	}

	/**
	 * Remove inactive throttle buckets. Suitable for a scheduled maintenance
	 * task; failed logins also run it occasionally according to configuration.
	 *
	 * @return bool
	 */
	public function cleanup_login_attempts() {
		$cutoff = $this->relative_login_throttle_date(
			$this->config_vars['login_throttle_cleanup_after'],
			true,
			'1 day'
		);
		$this->aauth_db->where('updated_at <', $cutoff);
		$this->aauth_db->group_start();
		$this->aauth_db->where('blocked_until', null);
		$this->aauth_db->or_where('blocked_until <', date('Y-m-d H:i:s'));
		$this->aauth_db->group_end();

		return $this->aauth_db->delete($this->config_vars['login_attempts']);
	}

	private function normalize_login_identifier($identifier) {
		$identifier = trim((string) $identifier);

		return function_exists('mb_strtolower')
			? mb_strtolower($identifier, 'UTF-8')
			: strtolower($identifier);
	}

	private function login_throttle_buckets($identifier = false, $context = 'login') {
		$prefix = $context === 'totp' ? 'totp' : 'login';
		$buckets = array(array(
			'scope' => $prefix . '_ip',
			'value' => (string) $this->CI->input->ip_address(),
			'limit' => (int) $this->config_vars[
				$prefix === 'totp' ? 'login_throttle_totp_ip_limit' : 'login_throttle_ip_limit'
			],
		));

		if ($identifier !== false && $identifier !== '') {
			$buckets[] = array(
				'scope' => $prefix . '_identifier',
				'value' => $identifier,
				'limit' => (int) $this->config_vars[
					$prefix === 'totp'
						? 'login_throttle_totp_identifier_limit'
						: 'login_throttle_identifier_limit'
				],
			);
		}

		return $buckets;
	}

	private function login_throttle_hash($scope, $value) {
		$secret = trim((string) $this->config_vars['login_throttle_secret']);
		if ($secret === '') {
			$secret = (string) $this->CI->config->item('encryption_key');
		}
		if ($secret === '') {
			throw new RuntimeException(
				'Aauth login throttling requires login_throttle_secret or CodeIgniter encryption_key.'
			);
		}

		return hash_hmac('sha256', $scope . "\0" . $value, $secret);
	}

	private function get_login_throttle_bucket($bucket) {
		$query = $this->aauth_db->where(array(
			'scope' => $bucket['scope'],
			'key_hash' => $this->login_throttle_hash($bucket['scope'], $bucket['value']),
		));
		$query = $this->aauth_db->get($this->config_vars['login_attempts']);

		return $query->num_rows() ? $query->row() : false;
	}

	private function login_attempt_is_allowed($identifier = false, $context = 'login') {
		if (!$this->config_vars['login_throttling']) {
			return true;
		}

		$now = date('Y-m-d H:i:s');
		$cutoff = $this->login_throttle_cutoff();
		foreach ($this->login_throttle_buckets($identifier, $context) as $bucket) {
			$row = $this->get_login_throttle_bucket($bucket);
			if (!$row) {
				continue;
			}
			if ($row->blocked_until !== null && $row->blocked_until > $now) {
				return false;
			}
			if ($row->window_started_at >= $cutoff
				&& (int) $row->attempts >= max(1, $bucket['limit'])) {
				return false;
			}
		}

		return true;
	}

	private function record_login_failure($identifier = false, $context = 'login') {
		if (!$this->config_vars['login_throttling']) {
			return;
		}

		$now = date('Y-m-d H:i:s');
		$cutoff = $this->login_throttle_cutoff();
		$blocked_until = $this->relative_login_throttle_date(
			$this->config_vars['login_throttle_lockout_time'],
			false,
			'15 minutes'
		);
		$table = $this->aauth_db->protect_identifiers($this->config_vars['login_attempts'], true);

		foreach ($this->login_throttle_buckets($identifier, $context) as $bucket) {
			$limit = max(1, $bucket['limit']);
			$sql = "INSERT INTO {$table} "
				. "(scope, key_hash, window_started_at, attempts, blocked_until, updated_at) "
				. "VALUES (?, ?, ?, 1, ?, ?) "
				. "ON DUPLICATE KEY UPDATE "
				. "blocked_until = CASE "
				. "WHEN window_started_at < ? THEN NULL "
				. "WHEN attempts + 1 >= ? THEN ? ELSE blocked_until END, "
				. "attempts = CASE WHEN window_started_at < ? THEN 1 "
				. "ELSE LEAST(attempts + 1, 65535) END, "
				. "window_started_at = CASE WHEN window_started_at < ? THEN ? ELSE window_started_at END, "
				. "updated_at = ?";
			$insert_blocked_until = $limit === 1 ? $blocked_until : null;
			$this->aauth_db->query($sql, array(
				$bucket['scope'],
				$this->login_throttle_hash($bucket['scope'], $bucket['value']),
				$now,
				$insert_blocked_until,
				$now,
				$cutoff,
				$limit,
				$blocked_until,
				$cutoff,
				$cutoff,
				$now,
				$now,
			));
		}

		$probability = max(0, (int) $this->config_vars['login_throttle_cleanup_probability']);
		if ($probability > 0 && random_int(1, $probability) === 1) {
			$this->cleanup_login_attempts();
		}
	}

	private function reset_login_attempt_buckets($identifier, $context, $include_ip) {
		$buckets = $this->login_throttle_buckets($identifier, $context);
		$deleted = true;
		foreach ($buckets as $bucket) {
			if (!$include_ip && substr($bucket['scope'], -3) === '_ip') {
				continue;
			}
			$this->aauth_db->where(array(
				'scope' => $bucket['scope'],
				'key_hash' => $this->login_throttle_hash($bucket['scope'], $bucket['value']),
			));
			$deleted = $this->aauth_db->delete($this->config_vars['login_attempts']) && $deleted;
		}

		return $deleted;
	}

	private function login_throttle_cutoff() {
		return $this->relative_login_throttle_date(
			$this->config_vars['max_login_attempt_time_period'],
			true,
			'15 minutes'
		);
	}

	private function relative_login_throttle_date($relative, $past, $fallback) {
		$relative = ltrim(trim((string) $relative), '+- ');
		if ($relative === '') {
			$relative = $fallback;
		}
		$timestamp = strtotime(($past ? '-' : '+') . $relative);
		if ($timestamp === false) {
			$timestamp = strtotime(($past ? '-' : '+') . $fallback);
		}

		return date('Y-m-d H:i:s', $timestamp);
	}

	########################
	# User Functions
	########################

	/**
	 * Create user
	 * Creates a new user
	 * 
	 * @param string $email User's email address
	 * @param string $pass User's password
	 * @param string $username User's username
	 * @return int|bool False if create fails or returns user id if successful
	 */
	public function create_user($email, $pass, $username = false) {

		$valid = true;

		if($this->config_vars['login_with_name'] == true){
			if (empty($username)){
				$this->error($this->CI->lang->line('aauth_error_username_required'));
				$valid = false;
			}
		}
		if ($this->user_exist_by_username($username) && $username != false) {
			$this->error($this->CI->lang->line('aauth_error_username_exists'));
			$valid = false;
		}

		if ($this->user_exist_by_email($email)) {
			$this->error($this->CI->lang->line('aauth_error_email_exists'));
			$valid = false;
		}
		$valid_email = (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
		if (!$valid_email){
			$this->error($this->CI->lang->line('aauth_error_email_invalid'));
			$valid = false;
		}
		if ( strlen($pass) < $this->config_vars['min'] OR strlen($pass) > $this->config_vars['max'] ){
			$this->error($this->CI->lang->line('aauth_error_password_invalid'));
			$valid = false;
		}
		if ($username != false && !ctype_alnum(str_replace($this->config_vars['additional_valid_chars'], '', $username))){
			$this->error($this->CI->lang->line('aauth_error_username_invalid'));
			$valid = false;
		}
		if (!$valid) {
			return false;
		}

		$requires_verification = $this->config_vars['verification'] && !$this->is_admin();
		$data = array(
			'email' => $email,
			'pass' => $this->hash_password($pass, 0), // Password cannot be blank but user_id required for salt, setting bad password for now
			'username' => (!$username) ? '' : $username ,
			'date_created' => date("Y-m-d H:i:s"),
			'email_verified_at' => $requires_verification ? null : date("Y-m-d H:i:s"),
		);

		if ( $this->aauth_db->insert($this->config_vars['users'], $data )){

			$user_id = $this->aauth_db->insert_id();

			// set default group
			$this->add_member($user_id, $this->config_vars['default_group']);

			// Send verification without treating the new user as banned.
			if($requires_verification){
				$this->send_verification($user_id);
			}

			// Update to correct salted password
			if( !$this->config_vars['use_password_hash']){
				$data = null;
				$data['pass'] = $this->hash_password($pass, $user_id);
				$this->aauth_db->where('id', $user_id);
				$this->aauth_db->update($this->config_vars['users'], $data);
			}

			return $user_id;

		} else {
			return false;
		}
	}

	/**
	 * Update user
	 * Updates existing user details
	 * 
	 * @param int $user_id User id to update
	 * @param string|bool $email User's email address, or FALSE if not to be updated
	 * @param string|bool $pass User's password, or FALSE if not to be updated
	 * @param string|bool $username User's name, or FALSE if not to be updated
	 * @return bool Update fails/succeeds
	 */
	public function update_user($user_id, $email = false, $pass = false, $username = false) {

		$data = array();
		$valid = true;
		$user = $this->get_user($user_id);
		if (!$user) {
			return false;
		}

		if ($user->email == $email) {
			$email = false;
		}

		if ($email != false) {
			if ($this->user_exist_by_email($email)) {
				$this->error($this->CI->lang->line('aauth_error_update_email_exists'));
				$valid = false;
			}
			$valid_email = (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
			if (!$valid_email){
				$this->error($this->CI->lang->line('aauth_error_email_invalid'));
				$valid = false;
			}
			$data['email'] = $email;
			if ($this->config_vars['verification']) {
				$data['email_verified_at'] = null;
				$data['verification_code'] = '';
				$data['verification_exp'] = null;
			}
		}

		if ($pass != false) {
			if ( strlen($pass) < $this->config_vars['min'] OR strlen($pass) > $this->config_vars['max'] ){
				$this->error($this->CI->lang->line('aauth_error_password_invalid'));
				$valid = false;
			}
			$data['pass'] = $this->hash_password($pass, $user_id);
		}

		if ($user->username == $username) {
			$username = false;
		}

		if ($username != false) {
			if ($this->user_exist_by_username($username)) {
				$this->error($this->CI->lang->line('aauth_error_update_username_exists'));
				$valid = false;
			}
			if ($username !='' && !ctype_alnum(str_replace($this->config_vars['additional_valid_chars'], '', $username))){
				$this->error($this->CI->lang->line('aauth_error_username_invalid'));
				$valid = false;
			}
			$data['username'] = $username;
		}

		if ( !$valid || empty($data)) {
			return false;
		}

		$this->aauth_db->where('id', $user_id);
		$updated = $this->aauth_db->update($this->config_vars['users'], $data);
		if ($updated && $email !== false && $this->config_vars['verification']) {
			$this->send_verification($user_id);
		}

		return $updated;
	}

	/**
	 * List users
	 * Return users as an object array
	 * @param bool|int $group_par Specify group id to list group or FALSE for all users
	 * @param string $limit Limit of users to be returned
	 * @param bool $offset Offset for limited number of users
	 * @param bool $include_banneds Include banned users
	 * @param string $sort Order by MYSQL string (e.g. 'name ASC', 'email DESC')
	 * @return array Array of users
	 */
	public function list_users($group_par = false, $limit = false, $offset = false, $include_banneds = false, $sort = false) {

		// if group_par is given
		if ($group_par != false) {

			$group_par = $this->get_group_id($group_par);
			$this->aauth_db->select('*')
				->from($this->config_vars['users'])
				->join($this->config_vars['user_to_group'], $this->config_vars['users'] . ".id = " . $this->config_vars['user_to_group'] . ".user_id")
				->where($this->config_vars['user_to_group'] . ".group_id", $group_par);

			// if group_par is not given, lists all users
		} else {

			$this->aauth_db->select('*')
				->from($this->config_vars['users']);
		}

		// Banned users
		if (!$include_banneds) {
			$this->aauth_db->where('banned_at', null);
		}

		// order_by
		if ($sort) {
			$this->aauth_db->order_by($sort);
		}

		// limit
		if ($limit) {

			if ($offset == false)
				$this->aauth_db->limit($limit);
			else
				$this->aauth_db->limit($limit, $offset);
		}

		$query = $this->aauth_db->get();

		return $query->result();
	}

	/**
	 * Get user
	 * Get user information
	 * @param int|bool $user_id User id to get or FALSE for current user
	 * @return object User information
	 */
	public function get_user($user_id = false) {

		if ($user_id == false)
			$user_id = $this->CI->session->userdata('id');

		$query = $this->aauth_db->where('id', $user_id);
		$query = $this->aauth_db->get($this->config_vars['users']);

		if ($query->num_rows() <= 0){
			$this->error($this->CI->lang->line('aauth_error_no_user'));
			return false;
		}
		return $query->row();
	}

	/**
	 * Verify user
	 * Activates a user account using a temporary, single-use token.
	 * @param int $user_id User id to activate
	 * @param string $ver_code Verification token
	 * @return bool Activation fails/succeeds
	 */
	public function verify_user($user_id, $ver_code){
		if (!is_numeric($user_id)
			|| !is_string($ver_code)
			|| !preg_match('/\A[a-f0-9]{64}\z/D', $ver_code)) {
			return false;
		}

		$token_hash = 'verify:' . hash('sha256', $ver_code);
		$data = array(
			'verification_code' => '',
			'verification_exp' => null,
			'email_verified_at' => date('Y-m-d H:i:s'),
		);

		$this->aauth_db->where('id', (int) $user_id);
		$this->aauth_db->where('email_verified_at', null);
		$this->aauth_db->where('verification_code', $token_hash);
		$this->aauth_db->where('verification_exp >=', date('Y-m-d H:i:s'));
		if (!$this->aauth_db->update($this->config_vars['users'], $data)) {
			return false;
		}

		return $this->aauth_db->affected_rows() === 1;
	}

	/**
	 * Create a temporary, single-use verification link without sending it.
	 *
	 * @param int $user_id User id to verify
	 * @return string|bool Verification link, or FALSE when the user is not found
	 */
	public function create_verification_link($user_id){
		if (!is_numeric($user_id) || (int) $user_id < 1) {
			return false;
		}

		$query = $this->aauth_db->where('id', (int) $user_id);
		$query = $this->aauth_db->where('email_verified_at', null);
		$query = $this->aauth_db->get($this->config_vars['users']);
		if ($query->num_rows() < 1) {
			return false;
		}

		$expiration = isset($this->config_vars['verification_expiration'])
			? $this->config_vars['verification_expiration']
			: '+24 hours';
		$expires = strtotime($expiration);
		if ($expires === false || $expires <= time()) {
			throw new InvalidArgumentException('verification_expiration must resolve to a future date.');
		}

		$token = bin2hex(random_bytes(32));
		$data = array(
			'verification_code' => 'verify:' . hash('sha256', $token),
			'verification_exp' => date('Y-m-d H:i:s', $expires),
		);

		$this->aauth_db->where('id', (int) $user_id);
		if (!$this->aauth_db->update($this->config_vars['users'], $data)) {
			return false;
		}

		$this->CI->load->helper('url');
		$path = trim($this->config_vars['verification_link'], '/')
			. '/' . (int) $user_id . '/' . rawurlencode($token);
		return site_url($path);
	}

	/**
	 * Send verification email
	 * Sends a verification email based on user id
	 * @param int $user_id User id to send verification email to
	 * @return bool Email sent successfully
	 */
	public function send_verification($user_id){
		$query = $this->aauth_db->where('id', $user_id);
		$query = $this->aauth_db->where('email_verified_at', null);
		$query = $this->aauth_db->get($this->config_vars['users']);
		if ($query->num_rows() < 1) {
			return false;
		}

		$row = $query->row();
		$link = $this->create_verification_link($user_id);
		if ($link === false) {
			return false;
		}

		$this->CI->load->library('email');
		if(isset($this->config_vars['email_config']) && is_array($this->config_vars['email_config'])){
			$this->CI->email->initialize($this->config_vars['email_config']);
		}

		$this->CI->email->from($this->config_vars['email'], $this->config_vars['name']);
		$this->CI->email->to($row->email);
		$this->CI->email->subject($this->CI->lang->line('aauth_email_verification_subject'));
		$this->CI->email->message($this->CI->lang->line('aauth_email_verification_text') . $link);
		return (bool) $this->CI->email->send();
	}

	/**
	 * Delete user
	 * Delete a user from database. WARNING Can't be undone
	 * @param int $user_id User id to delete
	 * @return bool Delete fails/succeeds
	 */
	public function delete_user($user_id) {

		$this->aauth_db->trans_begin();

		// delete from perm_to_user
		$this->aauth_db->where('user_id', $user_id);
		$this->aauth_db->delete($this->config_vars['perm_to_user']);

		// delete from user_to_group
		$this->aauth_db->where('user_id', $user_id);
		$this->aauth_db->delete($this->config_vars['user_to_group']);

		// delete user vars
		$this->aauth_db->where('user_id', $user_id);
		$this->aauth_db->delete($this->config_vars['user_variables']);

		// delete TOTP recovery codes (the foreign key also provides a safety net)
		$this->aauth_db->where('user_id', $user_id);
		$this->aauth_db->delete($this->config_vars['totp_recovery_codes']);

		// delete user
		$this->aauth_db->where('id', $user_id);
		$this->aauth_db->delete($this->config_vars['users']);

		if ($this->aauth_db->trans_status() === false) {
			$this->aauth_db->trans_rollback();
			return false;
		} else {
			$this->aauth_db->trans_commit();
			return true;
		}

	}

	/**
	 * Ban user
	 * Bans a user account
	 * @param int $user_id User id to ban
	 * @param string|null $reason Optional administrative reason
	 * @return bool Ban fails/succeeds
	 */
	public function ban_user($user_id, $reason = null) {
		if ($reason !== null && !is_string($reason)) {
			return false;
		}
		$reason = $reason === null ? null : trim($reason);

		$data = array(
			'banned_at' => date('Y-m-d H:i:s'),
			'ban_reason' => $reason === '' ? null : $reason,
		);

		$this->aauth_db->where('id', $user_id);

		return $this->aauth_db->update($this->config_vars['users'], $data);
	}

	/**
	 * Unban user
	 * Activates user account
	 * Same with unlock_user()
	 * @param int $user_id User id to activate
	 * @return bool Activation fails/succeeds
	 */
	public function unban_user($user_id) {

		$data = array(
			'banned_at' => null,
			'ban_reason' => null,
		);

		$this->aauth_db->where('id', $user_id);

		return $this->aauth_db->update($this->config_vars['users'], $data);
	}

	/**
	 * Check user banned
	 * Checks if a user is banned
	 * @param int $user_id User id to check
	 * @return bool TRUE if banned, FALSE otherwise
	 */
	public function is_banned($user_id) {

		if ( ! $this->user_exist_by_id($user_id)) {
			return true;
		}

		$query = $this->aauth_db->where('id', $user_id);
		$query = $this->aauth_db->where('banned_at IS NOT NULL', null, false);

		$query = $this->aauth_db->get($this->config_vars['users']);

		if ($query->num_rows() > 0)
			return true;
		else
			return false;
	}

	/**
	 * user_exist_by_username
	 * Check if user exist by username
	 * @param string $name Username to check
	 *
	 * @return bool
	 */
	public function user_exist_by_username( $name ) {
		$query = $this->aauth_db->where('username', $name);

		$query = $this->aauth_db->get($this->config_vars['users']);

		if ($query->num_rows() > 0)
			return true;
		else
			return false;
	}

	/**
	 * user_exist_by_name !DEPRECATED!
	 * Check if user exist by name
	 * @param string $name Username to check
	 *
	 * @return bool
	 */
	public function user_exist_by_name( $name ) {
		return $this->user_exist_by_username($name);
	}

	/**
	 * user_exist_by_email
	 * Check if a user exists by their email address.
	 * 
	 * @param string $user_email The email address to check.
	 *
	 * @return bool
	 */
	public function user_exist_by_email( $user_email ) {
		$query = $this->aauth_db->where('email', $user_email);

		$query = $this->aauth_db->get($this->config_vars['users']);

		if ($query->num_rows() > 0)
			return true;
		else
			return false;
	}

	/**
	 * user_exist_by_id
	 * Check if user exist by user id
	 * 
	 * @param int $user_id
	 *
	 * @return bool
	 */
	public function user_exist_by_id( $user_id ) {
		$query = $this->aauth_db->where('id', $user_id);

		$query = $this->aauth_db->get($this->config_vars['users']);

		if ($query->num_rows() > 0)
			return true;
		else
			return false;
	}

	/**
	 * Get user id
	 * Get user id from email address,
	 * If no email is provided, the current user's ID will be returned.
	 * 
	 * @param string|bool $email Email address for user
	 * @return int User id
	 */
	public function get_user_id($email=false) {

		if( ! $email){
			$query = $this->aauth_db->where('id', $this->CI->session->userdata('id'));
		} else {
			$query = $this->aauth_db->where('email', $email);
		}

		$query = $this->aauth_db->get($this->config_vars['users']);

		if ($query->num_rows() <= 0){
			$this->error($this->CI->lang->line('aauth_error_no_user'));
			return false;
		}
		return $query->row()->id;
	}

	/**
	 * Get user groups
	 * Get groups a user is in
	 * 
	 * @param int|bool $user_id User id to get or FALSE for current user
	 * @return array Groups
	 */
	public function get_user_groups($user_id = false){

		if( !$user_id) { $user_id = $this->CI->session->userdata('id'); }
		if( !$user_id){
			$this->aauth_db->where('name', $this->config_vars['public_group']);
			$query = $this->aauth_db->get($this->config_vars['groups']);
		}else if($user_id){
			$this->aauth_db->join($this->config_vars['groups'], "id = group_id");
			$this->aauth_db->where('user_id', $user_id);
			$query = $this->aauth_db->get($this->config_vars['user_to_group']);
		}
		return $query->result();
	}

	/**
	 * Get user permissions
	 * Get user permissions from user id ( ! Case sensitive)
	 * @param int|bool $user_id User id to get or FALSE for current user
	 * @return int Group id
	 */
	public function get_user_perms ( $user_id = false ) {
		if( ! $user_id) { $user_id = $this->CI->session->userdata('id'); }

		if($user_id){
			$query = $this->aauth_db->select($this->config_vars['perms'].'.*');
			$query = $this->aauth_db->where('user_id', $user_id);
			$query = $this->aauth_db->join($this->config_vars['perms'], $this->config_vars['perms'].'.id = '.$this->config_vars['perm_to_user'].'.perm_id');
			$query = $this->aauth_db->get($this->config_vars['perm_to_user']);

			return $query->result();
		}

		return false;
	}

	/**
	 * Update activity
	 * Update user's last activity date
	 * 
	 * @param int|bool $user_id User id to update or FALSE for current user
	 * @return bool Update fails/succeeds
	 */
	public function update_activity($user_id = false) {

		if ($user_id == false)
			$user_id = $this->CI->session->userdata('id');

		if($user_id==false){return false;}

		$data['last_activity'] = date("Y-m-d H:i:s");

		$query = $this->aauth_db->where('id',$user_id);
		return $this->aauth_db->update($this->config_vars['users'], $data);
	}

	/**
	 * Hash password
	 * Hash the password for storage in the database
	 * (thanks to Jacob Tomlinson for contribution)
	 * 
	 * @param string $pass Password to hash
	 * @param int $userid User id required for legacy hash salting.
	 * @return string Hashed password
	 */
	function hash_password($pass, $userid) {
		if($this->config_vars['use_password_hash']){
			return password_hash($pass, $this->config_vars['password_hash_algo'], $this->config_vars['password_hash_options']);
		}else{
			$salt = md5($userid);
			return hash($this->config_vars['hash'], $salt.$pass);
		}
	}

	/**
	 * Verify password
	 * Verfies the hashed password
	 * Transparently migrates valid legacy hashes and refreshes modern hashes
	 * whenever the configured algorithm or options change.
	 *
	 * @param string $password Plain-text password
	 * @param string $hash Stored password hash
	 * @param int|bool $user_id User id required to verify and migrate a legacy hash
	 * @return bool False or True
	 */
	function verify_password($password, $hash, $user_id = false) {
		$valid = password_verify($password, $hash);

		if (!$valid && $user_id !== false) {
			$legacy_salt = md5((string) $user_id);
			$legacy_hash = hash($this->config_vars['hash'], $legacy_salt . $password);
			$valid = hash_equals($hash, $legacy_hash);
		}

		if (!$valid) {
			return false;
		}

		if ($this->config_vars['use_password_hash'] && $user_id !== false && password_needs_rehash(
			$hash,
			$this->config_vars['password_hash_algo'],
			$this->config_vars['password_hash_options']
		)) {
			$data = array(
				'pass' => password_hash(
					$password,
					$this->config_vars['password_hash_algo'],
					$this->config_vars['password_hash_options']
				)
			);
			$this->aauth_db->where('id', $user_id);
			$this->aauth_db->update($this->config_vars['users'], $data);
		}

		return true;
	}

	########################
	# Group Functions
	########################

	/**
	 * Create group
	 * Creates a new group
	 * 
	 * @param string $group_name New group name
	 * @param string $definition Description of the group
	 * @return int|bool Group id or FALSE on fail
	 */
	public function create_group($group_name, $definition = '') {

		$query = $this->aauth_db->get_where($this->config_vars['groups'], array('name' => $group_name));

		if ($query->num_rows() < 1) {

			$data = array(
				'name' => $group_name,
				'definition'=> $definition
			);
			$this->aauth_db->insert($this->config_vars['groups'], $data);
			$this->precache_groups();
			return $this->aauth_db->insert_id();
		}

		$this->info($this->CI->lang->line('aauth_info_group_exists'));
		return false;
	}

	/**
	 * Update group
	 * Change a groups name
	 * 
	 * @param int|string $group_par Group id or name to update
	 * @param string $group_name New group name
	 * @return bool Update success/failure
	 */
	public function update_group($group_par, $group_name=false, $definition=false) {

		$group_id = $this->get_group_id($group_par);
		$data = array();

		if (!$group_id) {
			return false;
		}

		if ($group_name != false) {
			$data['name'] = $group_name;
		}

		if ($definition != false) {
			$data['definition'] = $definition;
		}

		if (empty($data)) {
			return false;
		}

		$this->aauth_db->where('id', $group_id);
		return $this->aauth_db->update($this->config_vars['groups'], $data);
	}

	/**
	 * Delete group
	 * Delete a group from database. WARNING Can't be undone
	 * 
	 * @param int|string $group_par Group id or name to delete
	 * @return bool Delete success/failure
	 */
	public function delete_group($group_par) {

		$group_id = $this->get_group_id($group_par);

		$this->aauth_db->where('id',$group_id);
		$query = $this->aauth_db->get($this->config_vars['groups']);
		if ($query->num_rows() == 0){
			return false;
		}

		$this->aauth_db->trans_begin();

		// bug fixed
		// now users are deleted from user_to_group table
		$this->aauth_db->where('group_id', $group_id);
		$this->aauth_db->delete($this->config_vars['user_to_group']);

		$this->aauth_db->where('group_id', $group_id);
		$this->aauth_db->delete($this->config_vars['perm_to_group']);

		$this->aauth_db->where('group_id', $group_id);
		$this->aauth_db->delete($this->config_vars['group_to_group']);

		$this->aauth_db->where('subgroup_id', $group_id);
		$this->aauth_db->delete($this->config_vars['group_to_group']);

		$this->aauth_db->where('id', $group_id);
		$this->aauth_db->delete($this->config_vars['groups']);

		if ($this->aauth_db->trans_status() === false) {
			$this->aauth_db->trans_rollback();
			return false;
		} else {
			$this->aauth_db->trans_commit();
			$this->precache_groups();
			return true;
		}

	}

	/**
	 * Add member
	 * Add a user to a group
	 * 
	 * @param int $user_id User id to add to group
	 * @param int|string $group_par Group id or name to add user to
	 * @return bool Add success/failure
	 */
	public function add_member($user_id, $group_par) {

		$group_id = $this->get_group_id($group_par);

		if( ! $group_id ) {

			$this->error( $this->CI->lang->line('aauth_error_no_group') );
			return false;
		}

		$query = $this->aauth_db->where('user_id',$user_id);
		$query = $this->aauth_db->where('group_id',$group_id);
		$query = $this->aauth_db->get($this->config_vars['user_to_group']);

		if ($query->num_rows() < 1) {
			$data = array(
				'user_id' => $user_id,
				'group_id' => $group_id
			);

			return $this->aauth_db->insert($this->config_vars['user_to_group'], $data);
		}
		$this->info($this->CI->lang->line('aauth_info_already_member'));
		return true;
	}

	/**
	 * Remove member
	 * Remove a user from a group
	 * 
	 * @param int $user_id User id to remove from group
	 * @param int|string $group_par Group id or name to remove user from
	 * @return bool Remove success/failure
	 */
	public function remove_member($user_id, $group_par) {

		$group_par = $this->get_group_id($group_par);
		$this->aauth_db->where('user_id', $user_id);
		$this->aauth_db->where('group_id', $group_par);
		return $this->aauth_db->delete($this->config_vars['user_to_group']);
	}

	/**
	 * Add subgroup
	 * Add a subgroup to a group
	 * 
	 * @param int|string $group_par Group id or name to add the subgroup to
	 * @param int|string $subgroup_par Sub-Group id or name to add to the group.
	 * @return bool Add success/failure
	 */
	public function add_subgroup($group_par, $subgroup_par) {

		$group_id = $this->get_group_id($group_par);
		$subgroup_id = $this->get_group_id($subgroup_par);

		if( ! $group_id ) {
			$this->error( $this->CI->lang->line('aauth_error_no_group') );
			return false;
		}

		if( ! $subgroup_id ) {
			$this->error( $this->CI->lang->line('aauth_error_no_subgroup') );
			return false;
		}

		if ($group_groups = $this->get_subgroups($group_id)) {
			foreach ($group_groups as $item) {
					if ($item->subgroup_id == $subgroup_id) {
						return false;
					}
			}
		}

		if ($subgroup_groups = $this->get_subgroups($subgroup_id)) {
			foreach ($subgroup_groups as $item) {
					if ($item->subgroup_id == $group_id) {
						return false;
					}
			}
		}

		$query = $this->aauth_db->where('group_id',$group_id);
		$query = $this->aauth_db->where('subgroup_id',$subgroup_id);
		$query = $this->aauth_db->get($this->config_vars['group_to_group']);

		if ($query->num_rows() < 1) {
			$data = array(
				'group_id' => $group_id,
				'subgroup_id' => $subgroup_id,
			);

			return $this->aauth_db->insert($this->config_vars['group_to_group'], $data);
		}
		$this->info($this->CI->lang->line('aauth_info_already_subgroup'));
		return true;
	}

	/**
	 * Remove subgroup
	 * Remove a subgroup from a group
	 * 
	 * @param int|string $group_par Group id or name to remove
	 * @param int|string $subgroup_par Sub-Group id or name to remove
	 * @return bool Remove success/failure
	 */
	public function remove_subgroup($group_par, $subgroup_par) {

		$group_par = $this->get_group_id($group_par);
		$subgroup_par = $this->get_group_id($subgroup_par);
		$this->aauth_db->where('group_id', $group_par);
		$this->aauth_db->where('subgroup_id', $subgroup_par);
		return $this->aauth_db->delete($this->config_vars['group_to_group']);
	}

	/**
	 * Remove member
	 * Remove a user from all groups
	 * 
	 * @param int $user_id User id to remove from all groups
	 * @return bool Remove success/failure
	 */
	public function remove_member_from_all($user_id) {

		$this->aauth_db->where('user_id', $user_id);
		return $this->aauth_db->delete($this->config_vars['user_to_group']);
	}
	
	/**
	 * Is member
	 * Check if current user is a member of at least one group.
	 * Accepts a group id/name, an array, or the legacy "group1|group2" syntax.
	 *
	 * @param int|string|array $group_par Group ids or names to check
	 * @param int|bool $user_id User id, if not given current user
	 * @return bool
	 */
	public function is_member( $group_par, $user_id = false ) {
		if ($user_id === false) {
			$user_id = $this->CI->session->userdata('id');
		}

		if (!$user_id) {
			return false;
		}

		$group_ids = $this->resolve_group_ids($group_par, false);
		if (empty($group_ids)) {
			return false;
		}

		$this->aauth_db->where('user_id', $user_id);
		$this->aauth_db->where_in('group_id', $group_ids);
		$query = $this->aauth_db->get($this->config_vars['user_to_group']);

		return $query->num_rows() > 0;
	}

	/**
	 * Check if current user is a member of at least one supplied group.
	 *
	 * @param int|string|array $groups Group ids or names to check
	 * @param int|bool $user_id User id, if not given current user
	 * @return bool
	 */
	public function is_member_of_any($groups, $user_id = false) {
		return $this->is_member($groups, $user_id);
	}

	/**
	 * Check if current user is a member of every supplied group.
	 * Unknown groups make the check fail.
	 *
	 * @param int|string|array $groups Group ids or names to check
	 * @param int|bool $user_id User id, if not given current user
	 * @return bool
	 */
	public function is_member_of_all($groups, $user_id = false) {
		if ($user_id === false) {
			$user_id = $this->CI->session->userdata('id');
		}

		if (!$user_id) {
			return false;
		}

		$group_ids = $this->resolve_group_ids($groups, true);
		if ($group_ids === false || empty($group_ids)) {
			return false;
		}

		$this->aauth_db->select('group_id');
		$this->aauth_db->distinct();
		$this->aauth_db->where('user_id', $user_id);
		$this->aauth_db->where_in('group_id', $group_ids);
		$query = $this->aauth_db->get($this->config_vars['user_to_group']);

		return $query->num_rows() === count($group_ids);
	}

	/**
	 * Resolve group ids from scalar, array or legacy pipe-separated input.
	 *
	 * @param int|string|array $groups
	 * @param bool $fail_on_unknown Return FALSE if any group is unknown
	 * @return array|bool
	 */
	private function resolve_group_ids($groups, $fail_on_unknown = false) {
		if (is_string($groups) && strpos($groups, '|') !== false) {
			$groups = explode('|', $groups);
		} elseif (!is_array($groups)) {
			$groups = array($groups);
		}

		$group_ids = array();
		foreach ($groups as $group) {
			if (!is_int($group) && !is_string($group)) {
				if ($fail_on_unknown) {
					return false;
				}
				continue;
			}

			$group_id = $this->get_group_id($group);
			if ($group_id === false) {
				if ($fail_on_unknown) {
					return false;
				}
				continue;
			}

			$group_ids[] = (int) $group_id;
		}

		return array_values(array_unique($group_ids));
	}

	/**
	 * Is admin
	 * Check if current user is a member of the admin group
	 * 
	 * @param int $user_id User id to check, if it is not given checks current user
	 * @return bool
	 */
	public function is_admin( $user_id = false ) {

		return $this->is_member($this->config_vars['admin_group'], $user_id);
	}

	/**
	 * List groups
	 * List all groups
	 * 
	 * @return object Array of groups
	 */
	public function list_groups() {

		$query = $this->aauth_db->get($this->config_vars['groups']);
		return $query->result();
	}


	/**
	 * Get group name
	 * Get group name from group id
	 * 
	 * @param int $group_id Group id to get
	 * @return string Group name
	 */
	public function get_group_name($group_id) {

		$query = $this->aauth_db->where('id', $group_id);
		$query = $this->aauth_db->get($this->config_vars['groups']);

		if ($query->num_rows() == 0)
			return false;

		$row = $query->row();
		return $row->name;
	}

	/**
	 * Get group id
	 * Get group id from group name or id ( ! Case sensitive)
	 * 
	 * @param int|string $group_par Group id or name to get
	 * @return int Group id
	 */
	public function get_group_id ( $group_par ) {

		if( is_numeric($group_par) ) { return $group_par; }

		$key	= str_replace(' ', '', trim(strtolower($group_par)));

		if (isset($this->cache_group_id[$key])) {
			return $this->cache_group_id[$key];
		} else {
			return false;
		}

	}

	/**
	 * Get group
	 * Get group from group name or id ( ! Case sensitive)
	 * 
	 * @param int|string $group_par Group id or name to get
	 * @return object Group object
	 */
	public function get_group ( $group_par ) {
		if ($group_id = $this->get_group_id($group_par)) {
			$query = $this->aauth_db->where('id', $group_id);
			$query = $this->aauth_db->get($this->config_vars['groups']);

			return $query->row();
		}

		return false;
	}

	/**
	 * Get group permissions
	 * Get group permissions from group name or id ( ! Case sensitive)
	 * 
	 * @param int|string $group_par Group id or name to get
	 * @return object Array of permissions for the group
	 */
	public function get_group_perms ( $group_par ) {
		if ($group_id = $this->get_group_id($group_par)) {
			$query = $this->aauth_db->select($this->config_vars['perms'].'.*');
			$query = $this->aauth_db->where('group_id', $group_id);
			$query = $this->aauth_db->join($this->config_vars['perms'], $this->config_vars['perms'].'.id = '.$this->config_vars['perm_to_group'].'.perm_id');
			$query = $this->aauth_db->get($this->config_vars['perm_to_group']);

			return $query->result();
		}

		return false;
	}

	/**
	 * Get subgroups
	 * Get subgroups from group name or id ( ! Case sensitive)
	 * 
	 * @param int|string $group_par Group id or name to get
	 * @return object Array of subgroup_id's
	 */
	public function get_subgroups ( $group_par ) {

		$group_id = $this->get_group_id($group_par);

		$query = $this->aauth_db->where('group_id', $group_id);
		$query = $this->aauth_db->select('subgroup_id');
		$query = $this->aauth_db->get($this->config_vars['group_to_group']);

		if ($query->num_rows() == 0)
			return false;

		return $query->result();
	}

	########################
	# Permission Functions
	########################

	/**
	 * Create permission
	 * Creates a new permission type
	 * 
	 * @param string $perm_name New permission name
	 * @param string $definition Permission description
	 * @return int|bool Permission id or FALSE on fail
	 */
	public function create_perm($perm_name, $definition='') {

		$query = $this->aauth_db->get_where($this->config_vars['perms'], array('name' => $perm_name));

		if ($query->num_rows() < 1) {

			$data = array(
				'name' => $perm_name,
				'definition'=> $definition
			);
			$this->aauth_db->insert($this->config_vars['perms'], $data);
			$this->precache_perms();
			return $this->aauth_db->insert_id();
		}
		$this->info($this->CI->lang->line('aauth_info_perm_exists'));
		return false;
	}

	/**
	 * Update permission
	 * Updates permission name and description
	 * 
	 * @param int|string $perm_par Permission id or permission name
	 * @param string $perm_name New permission name
	 * @param string $definition Permission description
	 * @return bool Update success/failure
	 */
	public function update_perm($perm_par, $perm_name=false, $definition=false) {

		$perm_id = $this->get_perm_id($perm_par);
		$data = array();

		if ($perm_name != false)
			$data['name'] = $perm_name;

		if ($definition != false)
			$data['definition'] = $definition;

		$this->aauth_db->where('id', $perm_id);
		return $this->aauth_db->update($this->config_vars['perms'], $data);
	}

	//not ok
	/**
	 * Delete permission
	 * Delete a permission from database. WARNING Can't be undone
	 * 
	 * @param int|string $perm_par Permission id or perm name to delete
	 * @return bool Delete success/failure
	 */
	public function delete_perm($perm_par) {

		$perm_id = $this->get_perm_id($perm_par);

		$this->aauth_db->trans_begin();

		// deletes from perm_to_gropup table
		$this->aauth_db->where('perm_id', $perm_id);
		$this->aauth_db->delete($this->config_vars['perm_to_group']);

		// deletes from perm_to_user table
		$this->aauth_db->where('perm_id', $perm_id);
		$this->aauth_db->delete($this->config_vars['perm_to_user']);

		// deletes from permission table
		$this->aauth_db->where('id', $perm_id);
		$this->aauth_db->delete($this->config_vars['perms']);

		if ($this->aauth_db->trans_status() === false) {
			$this->aauth_db->trans_rollback();
			return false;
		} else {
			$this->aauth_db->trans_commit();
			$this->precache_perms();
			return true;
		}

	}

	/**
	 * List Group Permissions
	 * List all permissions by Group
	 * 
 	 * @param int $group_par Group id or name to check
	 * @return object Array of permissions
	 */
	public function list_group_perms($group_par) {
		if(empty($group_par)){
			return false;
		}

		$group_par = $this->get_group_id($group_par);

		$this->aauth_db->select('*');
		$this->aauth_db->from($this->config_vars['perms']);
		$this->aauth_db->join($this->config_vars['perm_to_group'], "perm_id = ".$this->config_vars['perms'].".id");
		$this->aauth_db->where($this->config_vars['perm_to_group'].'.group_id', $group_par);

		$query = $this->aauth_db->get();
		if ($query->num_rows() == 0)
			return false;

		return $query->result();
	}

	/**
	 * Is user allowed
	 * Check if user allowed to do specified action, admin always allowed
	 * first checks user permissions then check group permissions
	 * 
	 * @param int $perm_par Permission id or name to check
	 * @param int|bool $user_id User id to check, or if FALSE checks current user
	 * @return bool
	 */
	public function is_allowed($perm_par, $user_id=false){

		$this->CI->load->helper('url');

		if($this->CI->session->userdata('totp_required')){
			$this->error($this->CI->lang->line('aauth_error_totp_verification_required'));
			redirect($this->config_vars['totp_two_step_login_redirect']);
		}

		if( $user_id == false){
			$user_id = $this->CI->session->userdata('id');
		}

		if($this->is_admin($user_id))
		{
			return true;
		}

		if ( ! $perm_id = $this->get_perm_id($perm_par)) {
			return false;
		}

		$query = $this->aauth_db->where('perm_id', $perm_id);
		$query = $this->aauth_db->where('user_id', $user_id);
		$query = $this->aauth_db->get( $this->config_vars['perm_to_user'] );

		if( $query->num_rows() > 0){
			return true;
		} else {
			$g_allowed=false;
			foreach( $this->get_user_groups($user_id) as $group ){
				if ( $this->is_group_allowed($perm_id, $group->id) ){
					$g_allowed=true;
					break;
				}
			}
			return $g_allowed;
		}
	}

	/**
	 * Is Group allowed
	 * Check if group is allowed to do specified action, admin always allowed
	 * 
	 * @param int $perm_par Permission id or name to check
	 * @param int|string|bool $group_par Group id or name to check, or if FALSE checks all user groups
	 * @return bool
	 */
	public function is_group_allowed($perm_par, $group_par=false){

		$perm_id = $this->get_perm_id($perm_par);

		// if group par is given
		if($group_par != false){

			// if group is admin group, as admin group has access to all permissions
			if (strcasecmp($group_par, $this->config_vars['admin_group']) == 0)
			{return true;}

			$subgroup_ids = $this->get_subgroups($group_par);
			$group_par = $this->get_group_id($group_par);
			$query = $this->aauth_db->where('perm_id', $perm_id);
			$query = $this->aauth_db->where('group_id', $group_par);
			$query = $this->aauth_db->get( $this->config_vars['perm_to_group'] );

			$g_allowed=false;
			if(is_array($subgroup_ids)){
				foreach ($subgroup_ids as $g ){
					if($this->is_group_allowed($perm_id, $g->subgroup_id)){
						$g_allowed=true;
					}
				}
			}

			if( $query->num_rows() > 0){
				$g_allowed=true;
			}
			return $g_allowed;
		}
		// if group par is not given
		// checks current user's all groups
		else {
			// if public is allowed or he is admin
			if ( $this->is_admin( $this->CI->session->userdata('id')) OR
				$this->is_group_allowed($perm_id, $this->config_vars['public_group']) )
			{return true;}

			// if is not login
			if (!$this->is_loggedin()){return false;}

			$group_pars = $this->get_user_groups();
			foreach ($group_pars as $g ){
				if($this->is_group_allowed($perm_id, $g->id)){
					return true;
				}
			}
			return false;
		}
	}

	/**
	 * Allow User
	 * Add User to permission
	 * 
	 * @param int $user_id User id to deny
	 * @param int $perm_par Permission id or name to allow
	 * @return bool Allow success/failure
	 */
	public function allow_user($user_id, $perm_par) {

		$perm_id = $this->get_perm_id($perm_par);

		if( ! $perm_id) {
			return false;
		}

		$query = $this->aauth_db->where('user_id',$user_id);
		$query = $this->aauth_db->where('perm_id',$perm_id);
		$query = $this->aauth_db->get($this->config_vars['perm_to_user']);

		// if not inserted before
		if ($query->num_rows() < 1) {

			$data = array(
				'user_id' => $user_id,
				'perm_id' => $perm_id
			);

			return $this->aauth_db->insert($this->config_vars['perm_to_user'], $data);
		}
		return true;
	}

	/**
	 * Deny User
	 * Remove user from permission
	 * 
	 * @param int $user_id User id to deny
	 * @param int $perm_par Permission id or name to deny
	 * @return bool Deny success/failure
	 */
	public function deny_user($user_id, $perm_par) {

		$perm_id = $this->get_perm_id($perm_par);

		$this->aauth_db->where('user_id', $user_id);
		$this->aauth_db->where('perm_id', $perm_id);

		return $this->aauth_db->delete($this->config_vars['perm_to_user']);
	}

	/**
	 * Allow Group
	 * Add group to permission
	 * 
	 * @param int|string|bool $group_par Group id or name to allow
	 * @param int $perm_par Permission id or name to allow
	 * @return bool Allow success/failure
	 */
	public function allow_group($group_par, $perm_par) {

		$perm_id = $this->get_perm_id($perm_par);

		if( ! $perm_id) {
			return false;
		}

		$group_id = $this->get_group_id($group_par);

		if( ! $group_id) {
			return false;
		}

		$query = $this->aauth_db->where('group_id',$group_id);
		$query = $this->aauth_db->where('perm_id',$perm_id);
		$query = $this->aauth_db->get($this->config_vars['perm_to_group']);

		if ($query->num_rows() < 1) {

			$data = array(
				'group_id' => $group_id,
				'perm_id' => $perm_id
			);

			return $this->aauth_db->insert($this->config_vars['perm_to_group'], $data);
		}

		return true;
	}

	/**
	 * Deny Group
	 * Remove group from permission
	 * 
	 * @param int|string|bool $group_par Group id or name to deny
	 * @param int $perm_par Permission id or name to deny
	 * @return bool Deny success/failure
	 */
	public function deny_group($group_par, $perm_par) {

		$perm_id = $this->get_perm_id($perm_par);
		$group_id = $this->get_group_id($group_par);

		$this->aauth_db->where('group_id', $group_id);
		$this->aauth_db->where('perm_id', $perm_id);

		return $this->aauth_db->delete($this->config_vars['perm_to_group']);
	}

	/**
	 * List Permissions
	 * List all permissions
	 * 
	 * @return object Array of permissions
	 */
	public function list_perms() {

		$query = $this->aauth_db->get($this->config_vars['perms']);
		return $query->result();
	}

	/**
	 * Get permission id
	 * Get permission id from permisison name or id
	 * 
	 * @param int|string $perm_par Permission id or name to get
	 * @return int Permission id or NULL if perm does not exist
	 */
	public function get_perm_id($perm_par) {

		if( is_numeric($perm_par) ) { return $perm_par; }

		$key	= str_replace(' ', '', trim(strtolower($perm_par)));

		if (isset($this->cache_perm_id[$key])) {
			return $this->cache_perm_id[$key];
		} else {
			return false;
		}

	}

	/**
	 * Get permission
	 * Get permission from permisison name or id
	 * 
	 * @param int|string $perm_par Permission id or name to get
	 * @return int Permission id or NULL if perm does not exist
	 */
	public function get_perm($perm_par) {
		if ($perm_id = $this->get_perm_id($perm_par)) {
			$query = $this->aauth_db->where('id', $perm_id);
			$query = $this->aauth_db->get($this->config_vars['perms']);

			return $query->row();
		}

		return false;
	}

	########################
	# Private Message Functions
	########################

	/**
	 * Send Private Message
	 * Send a private message to another user
	 * 
	 * @param int $sender_id User id of private message sender
	 * @param int $receiver_id User id of private message receiver
	 * @param string $title Message title/subject
	 * @param string $message Message body/content
	 * @return bool Send successful/failed
	 */
	public function send_pm( $sender_id, $receiver_id, $title, $message ){

		if ( !is_numeric($receiver_id) OR $sender_id == $receiver_id ){
			$this->error($this->CI->lang->line('aauth_error_self_pm'));
			return false;
		}
		if (($this->is_banned($receiver_id) || !$this->user_exist_by_id($receiver_id)) || ($sender_id && ($this->is_banned($sender_id) || !$this->user_exist_by_id($sender_id)))){
			$this->error($this->CI->lang->line('aauth_error_no_user'));
			return false;
		}
		if ( !$sender_id){
			$sender_id = 0;
		}

		if ($this->config_vars['pm_encryption']){
			$this->CI->load->library('encrypt');
			$title = $this->CI->encrypt->encode($title);
			$message = $this->CI->encrypt->encode($message);
		}

		$data = array(
			'sender_id' => $sender_id,
			'receiver_id' => $receiver_id,
			'title' => $title,
			'message' => $message,
			'date_sent' => date('Y-m-d H:i:s')
		);

		return $this->aauth_db->insert( $this->config_vars['pms'], $data );
	}

	/**
	 * Send multiple Private Messages
	 * Send multiple private messages to another users
	 * 
	 * @param int $sender_id User id of private message sender
	 * @param array $receiver_ids Array of User ids of private message receiver
	 * @param string $title Message title/subject
	 * @param string $message Message body/content
	 * @return array/bool Array with User ID's as key and TRUE or a specific error message OR FALSE if sender doesn't exist
	 */
	public function send_pms( $sender_id, $receiver_ids, $title, $message ){
		if ($this->config_vars['pm_encryption']){
			$this->CI->load->library('encrypt');
			$title = $this->CI->encrypt->encode($title);
			$message = $this->CI->encrypt->encode($message);
		}
		if ($sender_id && ($this->is_banned($sender_id) || !$this->user_exist_by_id($sender_id))){
			$this->error($this->CI->lang->line('aauth_error_no_user'));
			return false;
		}
		if ( !$sender_id){
			$sender_id = 0;
		}
		if (is_numeric($receiver_ids)) {
			$receiver_ids = array($receiver_ids);
		}
		if (!is_array($receiver_ids)) {
			return false;
		}

		$return_array = array();
		foreach ($receiver_ids as $receiver_id) {
			if ($sender_id == $receiver_id ){
				$return_array[$receiver_id] = $this->CI->lang->line('aauth_error_self_pm');
				continue;
			}
			if ($this->is_banned($receiver_id) || !$this->user_exist_by_id($receiver_id)){
				$return_array[$receiver_id] = $this->CI->lang->line('aauth_error_no_user');
				continue;
			}

			$data = array(
				'sender_id' => $sender_id,
				'receiver_id' => $receiver_id,
				'title' => $title,
				'message' => $message,
				'date_sent' => date('Y-m-d H:i:s')
			);
			$return_array[$receiver_id] = $this->aauth_db->insert( $this->config_vars['pms'], $data );
		}

		return $return_array;
	}

	/**
	 * List Private Messages
	 * If receiver id not given retruns current user's pms, if sender_id given, it returns only pms from given sender
	 * 
	 * @param int $limit Number of private messages to be returned
	 * @param int $offset Offset for private messages to be returned (for pagination)
	 * @param int $sender_id User id of private message sender
	 * @param int $receiver_id User id of private message receiver
	 * @return object Array of private messages
	 */
	public function list_pms($limit=5, $offset=0, $receiver_id=null, $sender_id=null){
		if (!is_numeric($receiver_id) && !is_numeric($sender_id)) {
			$receiver_id = $this->CI->session->userdata('id');
		}
		if (is_numeric($receiver_id)){
			$query = $this->aauth_db->where('receiver_id', $receiver_id);
			$query = $this->aauth_db->where('pm_deleted_receiver', null);
		}
		if (is_numeric($sender_id)){
			$query = $this->aauth_db->where('sender_id', $sender_id);
			$query = $this->aauth_db->where('pm_deleted_sender', null);
		}

		$query = $this->aauth_db->order_by('id','DESC');
		$query = $this->aauth_db->get( $this->config_vars['pms'], $limit, $offset);

		$result = $query->result();

		if ($this->config_vars['pm_encryption']){
			$this->CI->load->library('encrypt');

			foreach ($result as $k => $r)
			{
				$result[$k]->title = $this->CI->encrypt->decode($r->title);
				$result[$k]->message = $this->CI->encrypt->decode($r->message);
			}
		}

		return $result;
	}

	/**
	 * Get Private Message
	 * Get private message by id
	 * 
	 * @param int $pm_id Private message id to be returned
	 * @param int $user_id User ID of Sender or Receiver
	 * @param bool $set_as_read Whether or not to mark message as read
	 * @return object Private message
	 */
	public function get_pm($pm_id, $user_id = null, $set_as_read = true){
		if(!$user_id){
			$user_id = $this->CI->session->userdata('id');
		}
		if( !is_numeric($user_id) || !is_numeric($pm_id)){
			$this->error( $this->CI->lang->line('aauth_error_no_pm') );
			return false;
		}

		$query = $this->aauth_db->where('id', $pm_id);
		$query = $this->aauth_db->group_start();
		$query = $this->aauth_db->where('receiver_id', $user_id);
		$query = $this->aauth_db->or_where('sender_id', $user_id);
		$query = $this->aauth_db->group_end();
		$query = $this->aauth_db->get( $this->config_vars['pms'] );

		if ($query->num_rows() < 1) {
			$this->error( $this->CI->lang->line('aauth_error_no_pm') );
			return false;
		}

		$result = $query->row();

		if ($user_id == $result->receiver_id && $set_as_read){
			$this->set_as_read_pm($pm_id, $user_id);
		}

		if ($this->config_vars['pm_encryption']){
			$this->CI->load->library('encrypt');
			$result->title = $this->CI->encrypt->decode($result->title);
			$result->message = $this->CI->encrypt->decode($result->message);
		}

		return $result;
	}

	/**
	 * Delete Private Message
	 * Delete private message by id
	 * 
	 * @param int $pm_id Private message id to be deleted
	 * @return bool Delete success/failure
	 */
	public function delete_pm($pm_id, $user_id = null){
		if(!$user_id){
			$user_id = $this->CI->session->userdata('id');
		}
		if( !is_numeric($user_id) || !is_numeric($pm_id)){
			$this->error( $this->CI->lang->line('aauth_error_no_pm') );
			return false;
		}

		$query = $this->aauth_db->where('id', $pm_id);
		$query = $this->aauth_db->group_start();
		$query = $this->aauth_db->where('receiver_id', $user_id);
		$query = $this->aauth_db->or_where('sender_id', $user_id);
		$query = $this->aauth_db->group_end();
		$query = $this->aauth_db->get( $this->config_vars['pms'] );
		if ($query->num_rows() < 1) {
			$this->error( $this->CI->lang->line('aauth_error_no_pm') );
			return false;
		}
		$result = $query->row();
		if ($user_id == $result->sender_id){
			if($result->pm_deleted_receiver == 1){
				return $this->aauth_db->delete( $this->config_vars['pms'], array('id' => $pm_id));
			}

			return $this->aauth_db->update( $this->config_vars['pms'], array('pm_deleted_sender'=>1), array('id' => $pm_id));
		}else if ($user_id == $result->receiver_id){
			if($result->pm_deleted_sender == 1){
				return $this->aauth_db->delete( $this->config_vars['pms'], array('id' => $pm_id));
			}

			return $this->aauth_db->update( $this->config_vars['pms'], array('pm_deleted_receiver'=>1, 'date_read'=>date('Y-m-d H:i:s')), array('id' => $pm_id) );
		}
	}

	/**
	 * Cleanup PMs
	 * Removes PMs older than 'pm_cleanup_max_age' (definied in aauth config).
	 * recommend for a cron job
	 */
	public function cleanup_pms(){
		$pm_cleanup_max_age = $this->config_vars['pm_cleanup_max_age'];
		$date_sent = date('Y-m-d H:i:s', strtotime("now -".$pm_cleanup_max_age));
		$this->aauth_db->where('date_sent <', $date_sent);

		return $this->aauth_db->delete($this->config_vars['pms']);
	}

	/**
	 * Count unread Private Message
	 * Count number of unread private messages
	 * 
	 * @param int|bool $receiver_id User id for message receiver, if FALSE returns for current user
	 * @return int Number of unread messages
	 */
	public function count_unread_pms($receiver_id=false){

		if(!$receiver_id){
			$receiver_id = $this->CI->session->userdata('id');
		}

		$query = $this->aauth_db->where('receiver_id', $receiver_id);
		$query = $this->aauth_db->where('date_read', null);
		$query = $this->aauth_db->where('pm_deleted_receiver', null);
		$query = $this->aauth_db->get( $this->config_vars['pms'] );

		return $query->num_rows();
	}

	/**
	 * Set Private Message as read
	 * Set private message as read
	 * 
	 * @param int $pm_id Private message id to mark as read
	 * @param int|bool $user_id Receiver id, or FALSE for the current user
	 * @return bool Update success/failure
	 */
	public function set_as_read_pm($pm_id, $user_id = false){
		if ($user_id === false) {
			$user_id = $this->CI->session->userdata('id');
		}

		if (!is_numeric($pm_id) || !is_numeric($user_id)) {
			return false;
		}

		$data = array(
			'date_read' => date('Y-m-d H:i:s')
		);

		$this->aauth_db->where('id', $pm_id);
		$this->aauth_db->where('receiver_id', $user_id);
		return $this->aauth_db->update($this->config_vars['pms'], $data);
	}

	########################
	# Error / Info Functions
	########################

	/**
	 * Error
	 * Add message to error array and set flash data
	 * 
	 * @param string $message Message to add to array
	 * @param boolean $flashdata if TRUE add $message to CI flashdata (deflault: FALSE)
	 */
	public function error($message = '', $flashdata = false){
		$this->errors[] = $message;
		if($flashdata)
		{
			$this->flash_errors[] = $message;
			$this->CI->session->set_flashdata('errors', $this->flash_errors);
		}
	}

	/**
	 * Keep Errors
	 *
	 * Keeps the flashdata errors for one more page refresh.  Optionally adds the default errors into the
	 * flashdata list.  This should be called last in your controller, and with care as it could continue
	 * to revive all errors and not let them expire as intended.
	 * Benefitial when using Ajax Requests
	 * 
	 * @see http://ellislab.com/codeigniter/user-guide/libraries/sessions.html
	 * @param boolean $include_non_flash TRUE if it should stow basic errors as flashdata (default = FALSE)
	 */
	public function keep_errors($include_non_flash = false)
	{
		// NOTE: keep_flashdata() overwrites anything new that has been added to flashdata so we are manually reviving flash data
		// $this->CI->session->keep_flashdata('errors');

		if($include_non_flash)
		{
			$this->flash_errors = array_merge($this->flash_errors, $this->errors);
		}
		$this->flash_errors = array_merge($this->flash_errors, (array)$this->CI->session->flashdata('errors'));
		$this->CI->session->set_flashdata('errors', $this->flash_errors);
	}

	/**
	 * Get Errors Array
	 * Return array of errors
	 * 
	 * @return array Array of messages, empty array if no errors
	 */
	public function get_errors_array()
	{
		return $this->errors;
	}

	/**
	 * Print Errors
	 *
	 * Prints string of errors separated by delimiter
	 * 
	 * @param string $divider Separator for errors
	 */
	public function print_errors($divider = '<br />')
	{
		$msg = '';
		$msg_num = count($this->errors);
		$i = 1;
		foreach ($this->errors as $e)
		{
			$msg .= $e;

			if ($i != $msg_num)
			{
				$msg .= $divider;
			}
			$i++;
		}
		echo $msg;
	}

	/**
	 * Clear Errors
	 *
	 * Removes errors from error list and clears all associated flashdata
	 */
	public function clear_errors()
	{
		$this->errors = array();
		$this->CI->session->set_flashdata('errors', $this->errors);
	}

	/**
	 * Info
	 *
	 * Add message to info array and set flash data
	 *
	 * @param string $message Message to add to infos array
	 * @param boolean $flashdata if TRUE add $message to CI flashdata (deflault: FALSE)
	 */
	public function info($message = '', $flashdata = false)
	{
		$this->infos[] = $message;
		if($flashdata)
		{
			$this->flash_infos[] = $message;
			$this->CI->session->set_flashdata('infos', $this->flash_infos);
		}
	}

	/**
	 * Keep Infos
	 *
	 * Keeps the flashdata infos for one more page refresh.  Optionally adds the default infos into the
	 * flashdata list.  This should be called last in your controller, and with care as it could continue
	 * to revive all infos and not let them expire as intended.
	 * Benefitial by using Ajax Requests
	 * 
	 * @see http://ellislab.com/codeigniter/user-guide/libraries/sessions.html
	 * @param boolean $include_non_flash TRUE if it should stow basic infos as flashdata (default = FALSE)
	 */
	public function keep_infos($include_non_flash = false)
	{
		// NOTE: keep_flashdata() overwrites anything new that has been added to flashdata so we are manually reviving flash data
		// $this->CI->session->keep_flashdata('infos');

		if($include_non_flash)
		{
			$this->flash_infos = array_merge($this->flash_infos, $this->infos);
		}
		$this->flash_infos = array_merge($this->flash_infos, (array)$this->CI->session->flashdata('infos'));
		$this->CI->session->set_flashdata('infos', $this->flash_infos);
	}

	/**
	 * Get Info Array
	 *
	 * Return array of infos
	 * 
	 * @return array Array of messages, empty array if no errors
	 */
	public function get_infos_array()
	{
		return $this->infos;
	}


	/**
	 * Print Info
	 *
	 * Print string of info separated by delimiter
	 * 
	 * @param string $divider Separator for info
	 *
	 */
	public function print_infos($divider = '<br />')
	{

		$msg = '';
		$msg_num = count($this->infos);
		$i = 1;
		foreach ($this->infos as $e)
		{
			$msg .= $e;

			if ($i != $msg_num)
			{
				$msg .= $divider;
			}
			$i++;
		}
		echo $msg;
	}

	/**
	 * Clear Info List
	 *
	 * Removes info messages from info list and clears all associated flashdata
	 */
	public function clear_infos()
	{
		$this->infos = array();
		$this->CI->session->set_flashdata('infos', $this->infos);
	}

	########################
	# User Variables
	########################

	/**
	 * Set User Variable as key value
	 * if variable not set before, it will ve set
	 * if set, overwrites the value
	 * 
	 * @param string $key
	 * @param string $value
	 * @param int $user_id ; if not given current user
	 * @return bool
	 */
	public function set_user_var( $key, $value, $user_id = false ) {

		if ( ! $user_id ){
			$user_id = $this->CI->session->userdata('id');
		}

		// if specified user is not found
		if ( ! $this->get_user($user_id)){
			return false;
		}

		// if var not set, set
		if ($this->get_user_var($key,$user_id) ===false) {

			$data = array(
				'data_key' => $key,
				'value' => $value,
				'user_id' => $user_id
			);

			return $this->aauth_db->insert( $this->config_vars['user_variables'] , $data);
		}
		// if var already set, overwrite
		else {

			$data = array(
				'data_key' => $key,
				'value' => $value,
				'user_id' => $user_id
			);

			$this->aauth_db->where( 'data_key', $key );
			$this->aauth_db->where( 'user_id', $user_id);

			return $this->aauth_db->update( $this->config_vars['user_variables'], $data);
		}
	}

	/**
	 * Unset User Variable as key value
	 * 
	 * @param string $key
	 * @param int $user_id ; if not given current user
	 * @return bool
	 */
	public function unset_user_var( $key, $user_id = false ) {

		if ( ! $user_id ){
			$user_id = $this->CI->session->userdata('id');
		}

		// if specified user is not found
		if ( ! $this->get_user($user_id)){
			return false;
		}

		$this->aauth_db->where('data_key', $key);
		$this->aauth_db->where('user_id', $user_id);

		return $this->aauth_db->delete( $this->config_vars['user_variables'] );
	}

	/**
	 * Get User Variable by key
	 * Return string of variable value or FALSE
	 * 
	 * @param string $key
	 * @param int $user_id ; if not given current user
	 * @return bool|string , FALSE if var is not set, the value of var if set
	 */
	public function get_user_var( $key, $user_id = false){

		if ( ! $user_id ){
			$user_id = $this->CI->session->userdata('id');
		}

		// if specified user is not found
		if ( ! $this->get_user($user_id)){
			return false;
		}

		$query = $this->aauth_db->where('user_id', $user_id);
		$query = $this->aauth_db->where('data_key', $key);

		$query = $this->aauth_db->get( $this->config_vars['user_variables'] );

		// if variable not set
		if ($query->num_rows() < 1) { return false;}

		else {

			$row = $query->row();
			return $row->value;
		}

	}


	/**
	 * Get User Variables by user id
	 * Return array with all user keys & variables
	 * @param int $user_id ; if not given current user
	 * @return bool|array , FALSE if var is not set, the value of var if set
	 */
	public function get_user_vars( $user_id = false){

		if ( ! $user_id ){
			$user_id = $this->CI->session->userdata('id');
		}

		// if specified user is not found
		if ( ! $this->get_user($user_id)){
			return false;
		}

		$query = $this->aauth_db->select('data_key, value');

		$query = $this->aauth_db->where('user_id', $user_id);

		$query = $this->aauth_db->get( $this->config_vars['user_variables'] );

		return $query->result();

	}

	/**
	 * List User Variable Keys by UserID
	 * Return array of variable keys or FALSE
	 * 
	 * @param int $user_id ; if not given current user
	 * @return bool|array, FALSE if no user vars, otherwise array
	 */
	public function list_user_var_keys($user_id = false){

		if ( ! $user_id ){
			$user_id = $this->CI->session->userdata('id');
		}

		// if specified user is not found
		if ( ! $this->get_user($user_id)){
			return false;
		}
		$query = $this->aauth_db->select('data_key');

		$query = $this->aauth_db->where('user_id', $user_id);

		$query = $this->aauth_db->get( $this->config_vars['user_variables'] );

		// if variable not set
		if ($query->num_rows() < 1) { return false;}
		else {
			return $query->result();
		}
	}

	/**
	 * Generate the CAPTCHA field HTML.
	 *
	 * @param string|bool $identifier Current login identifier, when known
	 * @return string HTML for the CAPTCHA field, or an empty string if CAPTCHA is not required.
	 */
	public function generate_captcha_field($identifier = false){
		if (!$this->captcha_is_required($identifier)) {
			return '';
		}

		try {
			if ($this->captcha_provider === 'cap') {
				$this->CI->load->helper('cap');
				$captcha = new CapCaptcha(
					$this->config_vars['cap_instance_url'],
					$this->config_vars['cap_site_key'],
					$this->config_vars['cap_secret'],
					$this->config_vars['cap_widget_script_url'],
					isset($this->config_vars['cap_widget_mode']) ? $this->config_vars['cap_widget_mode'] : 'checkbox'
				);
				return $captcha->renderWidget($this->CI->lang->line('aauth_error_captcha_not_correct'));
			}

			$siteKey = htmlspecialchars($this->config_vars['recaptcha_siteKey'], ENT_QUOTES, 'UTF-8');
			return "<script src='https://www.google.com/recaptcha/api.js'></script>"
				. "<div class='g-recaptcha' data-sitekey='{$siteKey}'></div>";
		} catch (Throwable $exception) {
			log_message('error', 'Aauth CAPTCHA rendering error: ' . $exception->getMessage());
			return '';
		}
	}

	/**
	 * Backward-compatible alias.
	 */
	public function generate_recaptcha_field($identifier = false){
		return $this->generate_captcha_field($identifier);
	}

	/**
	 * Update the TOTP secret for a user.
	 *
	 * @param int|bool $user_id User id, or FALSE for the current user
	 * @param string|null $secret The new TOTP secret
	 * @return bool TRUE on success, FALSE on failure
	 */
	public function update_user_totp_secret($user_id = false, $secret = NULL) {

		if ($secret === NULL) {
			return false;
		}

		if ($user_id == false)
			$user_id = $this->CI->session->userdata('id');

		$data['totp_secret'] = $secret;
		$clearing = $secret === '';

		if ($clearing && !$this->aauth_db->trans_begin()) {
			return false;
		}

		$this->aauth_db->where('id', $user_id);
		if (!$this->aauth_db->update($this->config_vars['users'], $data)) {
			if ($clearing) {
				$this->aauth_db->trans_rollback();
			}
			return false;
		}

		if ($clearing && !$this->delete_totp_recovery_codes($user_id)) {
			$this->aauth_db->trans_rollback();
			return false;
		}

		return $clearing ? (bool) $this->aauth_db->trans_commit() : true;
	}

	/**
	 * Replace a user's recovery codes and return their one-time clear-text values.
	 * Only SHA-256 digests are persisted.
	 *
	 * @param int|bool $user_id User id, or FALSE for the current user
	 * @param int|bool $count Number of codes, or FALSE for the configured value
	 * @return array|bool Clear-text codes, or FALSE on failure
	 */
	public function generate_totp_recovery_codes($user_id = false, $count = false) {
		if ($user_id == false) {
			$user_id = $this->CI->session->userdata('id');
		}
		$user_id = (int) $user_id;
		$count = $count === false ? (int) $this->config_vars['totp_recovery_code_count'] : (int) $count;
		if ($user_id < 1 || $count < 1 || $count > 20 || !$this->get_user($user_id)) {
			return false;
		}

		$codes = array();
		$rows = array();
		for ($i = 0; $i < $count; $i++) {
			$normalized = strtoupper(bin2hex(random_bytes(10)));
			$codes[] = implode('-', str_split($normalized, 4));
			$rows[] = array(
				'user_id' => $user_id,
				'code_hash' => $this->hash_totp_recovery_code($normalized, $user_id),
				'created_at' => date('Y-m-d H:i:s'),
			);
		}

		if (!$this->aauth_db->trans_begin()) {
			return false;
		}
		if (!$this->delete_totp_recovery_codes($user_id)
			|| !$this->aauth_db->insert_batch($this->config_vars['totp_recovery_codes'], $rows)
			|| $this->aauth_db->trans_status() === false) {
			$this->aauth_db->trans_rollback();
			return false;
		}

		return $this->aauth_db->trans_commit() ? $codes : false;
	}

	/**
	 * Atomically consume a recovery code. A successful code cannot be reused.
	 */
	public function consume_totp_recovery_code($code, $user_id = false) {
		if ($user_id == false) {
			$user_id = $this->CI->session->userdata('id');
		}
		$user_id = (int) $user_id;
		$submitted = strtoupper(trim((string) $code));
		if ($user_id < 1 || !preg_match('/^[A-F0-9]{4}(?:[ -]?[A-F0-9]{4}){4}$/', $submitted)) {
			return false;
		}
		$normalized = str_replace(array('-', ' '), '', $submitted);

		$this->aauth_db->where('user_id', $user_id);
		$this->aauth_db->where('code_hash', $this->hash_totp_recovery_code($normalized, $user_id));
		if (!$this->aauth_db->delete($this->config_vars['totp_recovery_codes'])) {
			return false;
		}

		return $this->aauth_db->affected_rows() === 1;
	}

	/**
	 * Remove every recovery code belonging to a user.
	 */
	public function delete_totp_recovery_codes($user_id = false) {
		if ($user_id == false) {
			$user_id = $this->CI->session->userdata('id');
		}
		$user_id = (int) $user_id;
		if ($user_id < 1) {
			return false;
		}

		$this->aauth_db->where('user_id', $user_id);
		return (bool) $this->aauth_db->delete($this->config_vars['totp_recovery_codes']);
	}

	/**
	 * Count the recovery codes which are still available.
	 */
	public function get_totp_recovery_code_count($user_id = false) {
		if ($user_id == false) {
			$user_id = $this->CI->session->userdata('id');
		}
		$user_id = (int) $user_id;
		if ($user_id < 1) {
			return 0;
		}

		return (int) $this->aauth_db
			->where('user_id', $user_id)
			->count_all_results($this->config_vars['totp_recovery_codes']);
	}

	private function hash_totp_recovery_code($normalized_code, $user_id) {
		return hash('sha256', (int) $user_id . ':' . $normalized_code);
	}

	/**
	 * Generate a unique TOTP secret for a user.
	 *
	 * @return string The generated TOTP secret
	 */
	public function generate_unique_totp_secret(){
		$this->CI->load->helper('googleauthenticator');
		$ga = new PHPGangsta_GoogleAuthenticator();
		while (true) {
			$secret = $ga->createSecret();
			$query = $this->aauth_db->where('totp_secret', $secret);
			$query = $this->aauth_db->get($this->config_vars['users']);
			if ($query->num_rows() == 0) {
				return $secret;
			}
		}
	}

	/**
	 * Generate the TOTP provisioning URI rendered as a QR code by the client.
	 *
	 * @param string $secret Base32-encoded TOTP secret
	 * @param int|bool $user_id User id, or FALSE for the current user
	 * @return string|bool
	 */
	public function generate_totp_uri($secret, $user_id = false){
		$user = $this->get_user($user_id);
		if (!$user) {
			return false;
		}

		$issuer = isset($this->config_vars['totp_issuer'])
			? trim((string) $this->config_vars['totp_issuer'])
			: 'Aauth';
		$template = isset($this->config_vars['totp_label'])
			? (string) $this->config_vars['totp_label']
			: '{issuer} - {email}';
		$label = trim(strtr($template, array(
			'{issuer}' => $issuer,
			'{site}' => $issuer,
			'{email}' => (string) $user->email,
			'{username}' => (string) $user->username,
		)));

		if ($label === '') {
			$label = $issuer !== '' ? $issuer : (string) $user->email;
		}

		$this->CI->load->helper('googleauthenticator');
		$ga = new PHPGangsta_GoogleAuthenticator();
		return $ga->getOtpAuthUrl($label, $secret, $issuer);
	}

	/**
	 * Verify the TOTP code for a user.
	 *
	 * @param string $totp_code The TOTP code to verify
	 * @param int|bool $user_id User id, or FALSE for the current user
	 * @return bool TRUE if the TOTP code is valid, FALSE otherwise
	 */
	public function verify_user_totp_code($totp_code, $user_id = false){
		if ( !$this->is_totp_required()) {
			return true;
		}

		$pending_user_id = $this->CI->session->userdata('totp_user_id');
		if ($pending_user_id) {
			$user_id = $pending_user_id;
		} elseif ($user_id == false) {
			$user_id = $this->CI->session->userdata('id');
		}
		if (empty($totp_code)) {
			$this->error($this->CI->lang->line('aauth_error_totp_code_required'));
			return false;
		}
		$query = $this->aauth_db->where('id', $user_id);
		$query = $this->aauth_db->get($this->config_vars['users']);
		if ($query->num_rows() < 1) {
			$this->error($this->CI->lang->line('aauth_error_no_user'));
			return false;
		}
		$user = $query->row();
		$identifier_field = $this->config_vars['login_with_name'] ? 'username' : 'email';
		$identifier = $this->normalize_login_identifier($user->{$identifier_field});
		if ($this->config_vars['login_throttling']
			&& !$this->login_attempt_is_allowed($identifier, 'totp')) {
			log_message('info', 'Aauth TOTP verification throttled for IP and/or identifier bucket.');
			$this->error($this->CI->lang->line('aauth_error_login_attempts_exceeded'));
			return false;
		}
		if (!$this->verify_second_factor_code($user, $totp_code)) {
			$this->error($this->CI->lang->line('aauth_error_totp_code_invalid'));
			$this->record_login_failure($identifier, 'totp');
			if (!$this->login_attempt_is_allowed($identifier, 'totp')) {
				$this->error($this->CI->lang->line('aauth_error_login_attempts_exceeded'));
			}
			return false;
		}

		return $this->complete_login($user);
	}

	/**
	 * Check if TOTP is required for the current session.
	 *
	 * @return bool TRUE if TOTP is required, FALSE otherwise
	 */
	public function is_totp_required(){
		return (bool) $this->CI->session->userdata('totp_required');
	}

} // end class

/* End of file Aauth.php */
/* Location: ./application/libraries/Aauth.php */
