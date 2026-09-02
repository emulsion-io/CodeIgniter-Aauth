<h1>Double authentification</h1>
<p class="muted">Saisissez le code à six chiffres généré par votre application, ou l’un de vos codes de récupération.</p>

<?= form_open(current_url()) ?>
	<label for="totp_code">Code TOTP ou code de récupération</label>
	<input id="totp_code" name="totp_code" type="text" maxlength="24" autocomplete="one-time-code" autocapitalize="characters" required autofocus>
	<div class="actions"><button type="submit">Vérifier</button></div>
<?= form_close() ?>

<?= form_open('account/cancel_twofactor') ?>
	<div class="actions"><button class="secondary" type="submit">Annuler et revenir à la connexion</button></div>
<?= form_close() ?>
