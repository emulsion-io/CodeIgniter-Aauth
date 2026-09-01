<h1>Réinitialiser le mot de passe</h1>
<?php if ($success): ?>
	<p>Votre mot de passe a été réinitialisé. Vous pouvez maintenant vous connecter.</p>
	<a class="button" href="<?= site_url('account/login') ?>">Se connecter</a>
<?php elseif (!$token_valid): ?>
	<p>Ce lien de réinitialisation est invalide, expiré ou a déjà été utilisé.</p>
	<a class="button" href="<?= site_url('account/forgot_password') ?>">Demander un nouveau lien</a>
<?php else: ?>
	<p class="muted">Choisissez votre nouveau mot de passe. Ce lien ne pourra être utilisé qu’une seule fois.</p>
	<p class="muted"><?= $password_min ?> à <?= $password_max ?> caractères.</p>
	<?= form_open(current_url()) ?>
		<label for="password">Nouveau mot de passe</label>
		<div class="password-wrap">
			<input id="password" name="password" type="password" minlength="<?= $password_min ?>" maxlength="<?= $password_max ?>" autocomplete="new-password" required autofocus>
			<button class="password-toggle" type="button" data-password-toggle="password">Afficher</button>
		</div>

		<label for="password_confirmation">Confirmer le mot de passe</label>
		<div class="password-wrap">
			<input id="password_confirmation" name="password_confirmation" type="password" minlength="<?= $password_min ?>" maxlength="<?= $password_max ?>" autocomplete="new-password" required>
			<button class="password-toggle" type="button" data-password-toggle="password_confirmation">Afficher</button>
		</div>

		<div class="actions">
			<button type="submit">Enregistrer le mot de passe</button>
			<a class="button secondary" href="<?= site_url('account/login') ?>">Annuler</a>
		</div>
	<?= form_close() ?>
<?php endif; ?>
