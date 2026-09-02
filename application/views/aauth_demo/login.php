<h1>Connexion</h1>
<p class="muted">Utilisez vos identifiants pour accéder à votre compte.</p>

<?= form_open(current_url()) ?>
	<label for="identifier"><?= html_escape($identifier_label) ?></label>
	<input id="identifier" name="identifier" type="<?= html_escape($identifier_type) ?>" value="<?= html_escape($identifier) ?>" autocomplete="username" required autofocus>

	<label for="password">Mot de passe</label>
	<div class="password-wrap">
		<input id="password" name="password" type="password" autocomplete="current-password" required>
		<button class="password-toggle" type="button" data-password-toggle="password">Afficher</button>
	</div>

	<?php if ($show_totp): ?>
		<label for="totp_code">Code TOTP ou code de récupération <span class="muted">(si demandé)</span></label>
		<input id="totp_code" name="totp_code" type="text" maxlength="24" autocomplete="one-time-code" autocapitalize="characters">
	<?php endif; ?>

	<?= $captcha ?>

	<div class="actions">
		<button type="submit">Se connecter</button>
		<a class="text-link" href="<?= site_url('account/forgot_password') ?>">Mot de passe oublié ?</a>
	</div>
<?= form_close() ?>
