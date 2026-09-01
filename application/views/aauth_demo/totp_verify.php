<h1>Double authentification</h1>
<p class="muted">Saisissez le code à six chiffres généré par votre application d’authentification.</p>

<?= form_open(current_url()) ?>
	<label for="totp_code">Code TOTP</label>
	<input id="totp_code" name="totp_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" data-totp required autofocus>
	<div class="actions"><button type="submit">Vérifier</button></div>
<?= form_close() ?>

<?= form_open('account/cancel_twofactor') ?>
	<div class="actions"><button class="secondary" type="submit">Annuler et revenir à la connexion</button></div>
<?= form_close() ?>
