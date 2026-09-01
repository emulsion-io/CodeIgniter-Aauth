<h1>Mot de passe oublié</h1>
<?php if ($sent): ?>
	<p>Si un compte correspond à cette adresse, un message de réinitialisation a été envoyé.</p>
	<a class="button" href="<?= site_url('account/login') ?>">Retour à la connexion</a>
<?php else: ?>
	<p class="muted">Saisissez l’adresse e-mail associée à votre compte.</p>
	<?= form_open(current_url()) ?>
		<label for="email">Adresse e-mail</label>
		<input id="email" name="email" type="email" value="<?= html_escape($email) ?>" autocomplete="email" required autofocus>
		<div class="actions"><button type="submit">Envoyer le lien</button></div>
	<?= form_close() ?>
<?php endif; ?>
