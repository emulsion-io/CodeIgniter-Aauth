<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Minimal demonstration controller for the Aauth authentication flows.
 *
 * Do not use this controller as-is for application-specific authorization.
 */
class Account extends CI_Controller
{
	public function __construct()
	{
		parent::__construct();
		$this->load->library('aauth');
		$this->load->helper(array('form', 'url'));
	}

	public function index()
	{
		if (!$this->aauth->is_loggedin()) {
			redirect('account/login');
			return;
		}

		$this->render('dashboard', array(
			'title' => 'Mon compte',
			'user' => $this->aauth->get_user(),
		));
	}

	public function login()
	{
		if ($this->aauth->is_totp_required()) {
			redirect(ltrim($this->aauth->config_vars['totp_two_step_login_redirect'], '/'));
			return;
		}

		if ($this->aauth->is_loggedin()) {
			redirect('account');
			return;
		}

		$identifier = '';
		if ($this->is_post()) {
			$identifier = trim((string) $this->input->post('identifier', false));
			$password = $this->input->post('password', false);
			$totp_code = trim((string) $this->input->post('totp_code', false));

			if ($this->aauth->login($identifier, $password, $totp_code ?: null)) {
				$this->notice('Connexion réussie.');
				redirect('account');
				return;
			}

			if ($this->aauth->is_totp_required()) {
				redirect(ltrim($this->aauth->config_vars['totp_two_step_login_redirect'], '/'));
				return;
			}
		}

		$this->render('login', array(
			'title' => 'Connexion',
			'identifier' => $identifier,
			'identifier_label' => $this->aauth->config_vars['login_with_name'] ? "Nom d'utilisateur" : 'Adresse e-mail',
			'identifier_type' => $this->aauth->config_vars['login_with_name'] ? 'text' : 'email',
			'show_totp' => (bool) $this->aauth->config_vars['totp_active'],
			'captcha' => $this->aauth->generate_captcha_field($identifier ?: false),
		));
	}

	public function logout()
	{
		if (!$this->is_post()) {
			redirect('account');
			return;
		}

		$this->aauth->logout();
		redirect('account/login');
	}

	public function forgot_password()
	{
		$email = '';
		$sent = false;

		if ($this->is_post()) {
			$email = trim((string) $this->input->post('email', false));
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$this->aauth->error('Adresse e-mail invalide.');
			} else {
				$this->aauth->remind_password($email);
				$sent = true;
			}
		}

		$this->render('forgot_password', array(
			'title' => 'Mot de passe oublié',
			'email' => $email,
			'sent' => $sent,
		));
	}

	public function reset_password($verification_code = '')
	{
		$success = false;
		$token_valid = $verification_code !== ''
			&& $this->aauth->is_password_reset_token_valid($verification_code);

		if ($this->is_post() && $token_valid) {
			$password = (string) $this->input->post('password', false);
			$password_confirmation = (string) $this->input->post('password_confirmation', false);

			if ($password !== $password_confirmation) {
				$this->aauth->error('Les mots de passe ne correspondent pas.');
			} else {
				$success = $this->aauth->reset_password($verification_code, $password);
				$token_valid = !$success;
			}
		}

		$this->render('reset_password', array(
			'title' => 'Réinitialiser le mot de passe',
			'verification_code' => $verification_code,
			'token_valid' => $token_valid,
			'success' => $success,
			'password_min' => (int) $this->aauth->config_vars['min'],
			'password_max' => (int) $this->aauth->config_vars['max'],
		));
	}

	public function verification($user_id = null, $verification_code = '')
	{
		$success = is_numeric($user_id)
			&& $verification_code !== ''
			&& $this->aauth->verify_user((int) $user_id, $verification_code);

		$this->render('message', array(
			'title' => $success ? 'Compte vérifié' : 'Vérification impossible',
			'message' => $success
				? 'Votre compte est maintenant actif. Vous pouvez vous connecter.'
				: 'Ce lien de vérification est invalide ou a déjà été utilisé.',
			'action_url' => site_url('account/login'),
			'action_label' => 'Aller à la connexion',
		));
	}

	public function twofactor_verification()
	{
		if (!$this->aauth->is_totp_required()) {
			redirect($this->aauth->is_loggedin() ? 'account' : 'account/login');
			return;
		}

		if ($this->is_post()) {
			$code = trim((string) $this->input->post('totp_code', false));
			if ($this->aauth->verify_user_totp_code($code)) {
				$this->notice('Double authentification validée.');
				redirect('account');
				return;
			}
		}

		$this->render('totp_verify', array(
			'title' => 'Double authentification',
		));
	}

	public function cancel_twofactor()
	{
		if ($this->is_post()) {
			$this->session->unset_userdata(array('totp_required', 'totp_user_id'));
		}

		redirect('account/login');
	}

	public function totp_setup()
	{
		if (!$this->aauth->is_loggedin()) {
			redirect('account/login');
			return;
		}

		$user = $this->aauth->get_user();
		if (!$user) {
			show_error('Utilisateur introuvable.', 404);
			return;
		}

		if ($this->is_post() && $this->input->post('action') === 'disable') {
			$this->aauth->update_user_totp_secret($user->id, '');
			$this->session->unset_userdata('aauth_demo_totp_secret');
			$this->notice('Double authentification désactivée.');
			redirect('account/totp_setup');
			return;
		}

		$secret = $this->session->userdata('aauth_demo_totp_secret');
		if (!$secret) {
			$secret = $this->aauth->generate_unique_totp_secret();
			$this->session->set_userdata('aauth_demo_totp_secret', $secret);
		}

		if ($this->is_post() && $this->input->post('action') === 'enable') {
			$code = trim((string) $this->input->post('totp_code', false));
			$this->load->helper('googleauthenticator');
			$authenticator = new PHPGangsta_GoogleAuthenticator();

			if ($authenticator->verifyCode($secret, $code, 1)) {
				$this->aauth->update_user_totp_secret($user->id, $secret);
				$this->session->unset_userdata('aauth_demo_totp_secret');
				$this->notice('Double authentification activée.');
				redirect('account/totp_setup');
				return;
			}

			$this->aauth->error('Le code saisi est invalide.');
		}

		$this->render('totp_setup', array(
			'title' => 'Configurer le TOTP',
			'enabled' => !empty($user->totp_secret),
			'totp_feature_active' => (bool) $this->aauth->config_vars['totp_active'],
			'secret' => $secret,
			'totp_uri' => $this->aauth->generate_totp_uri($secret, $user->id),
			'qr_script_url' => base_url($this->aauth->config_vars['totp_qr_script']),
		));
	}

	private function render($view, array $data = array())
	{
		$data['errors'] = $this->aauth->get_errors_array();
		$data['notice'] = $this->session->flashdata('aauth_demo_notice');
		$data['logged_in'] = $this->aauth->is_loggedin();
		$data['content'] = $this->load->view('aauth_demo/' . $view, $data, true);
		$this->load->view('aauth_demo/layout', $data);
	}

	private function notice($message)
	{
		$this->session->set_flashdata('aauth_demo_notice', $message);
	}

	private function is_post()
	{
		return $this->input->method(true) === 'POST';
	}
}
