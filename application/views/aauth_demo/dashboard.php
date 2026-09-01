<h1>Mon compte</h1>
<p class="muted">La session Aauth est active.</p>

<h2>Utilisateur</h2>
<p><strong><?= html_escape($user->username ?: 'Sans nom') ?></strong><br><?= html_escape($user->email) ?></p>

<div class="actions">
	<a class="button" href="<?= site_url('account/totp_setup') ?>">Configurer le TOTP</a>
	<?= form_open('account/logout') ?><button class="secondary" type="submit">Se déconnecter</button><?= form_close() ?>
</div>
