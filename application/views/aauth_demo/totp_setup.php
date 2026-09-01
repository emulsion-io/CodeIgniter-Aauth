<h1>Configurer le TOTP</h1>

<?php if (!$totp_feature_active): ?>
	<div class="alert">Le TOTP est désactivé dans <code>application/config/aauth.php</code>. Activez <code>totp_active</code> pour l’exiger à la connexion.</div>
<?php endif; ?>

<?php if ($enabled): ?>
	<div class="notice">La double authentification est actuellement activée.</div>
	<p class="muted">Vous pouvez la reconfigurer avec le nouveau secret ci-dessous, ou la désactiver.</p>
<?php else: ?>
	<p class="muted">Scannez le QR code avec votre application d’authentification, puis confirmez avec un premier code.</p>
<?php endif; ?>

<img class="qr" src="<?= html_escape($qr_code_url) ?>" alt="QR code de configuration TOTP" width="200" height="200">
<p class="secret"><?= html_escape($secret) ?></p>

<?= form_open(current_url()) ?>
	<input type="hidden" name="action" value="enable">
	<label for="totp_code">Code de vérification</label>
	<input id="totp_code" name="totp_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" data-totp required>
	<div class="actions"><button type="submit"><?= $enabled ? 'Remplacer la configuration' : 'Activer le TOTP' ?></button></div>
<?= form_close() ?>

<?php if ($enabled): ?>
	<h2>Désactiver</h2>
	<?= form_open(current_url()) ?>
		<input type="hidden" name="action" value="disable">
		<button class="danger" type="submit">Désactiver le TOTP</button>
	<?= form_close() ?>
<?php endif; ?>
