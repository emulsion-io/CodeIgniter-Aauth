<h1>Réinitialiser le mot de passe</h1>
<?php if ($success): ?>
	<p>Le mot de passe a été réinitialisé. Le nouveau mot de passe a été envoyé par e-mail.</p>
	<a class="button" href="<?= site_url('account/login') ?>">Se connecter</a>
<?php elseif ($verification_code === ''): ?>
	<p>Le lien de réinitialisation est incomplet.</p>
<?php else: ?>
	<p class="muted">Confirmez la demande. Cette action invalidera le lien reçu par e-mail.</p>
	<?= form_open(current_url()) ?>
		<div class="actions">
			<button type="submit">Confirmer la réinitialisation</button>
			<a class="button secondary" href="<?= site_url('account/login') ?>">Annuler</a>
		</div>
	<?= form_close() ?>
<?php endif; ?>
