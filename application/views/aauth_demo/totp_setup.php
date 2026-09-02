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

<?php if ($recovery_codes): ?>
	<section id="recovery-codes-panel" class="recovery-sheet" aria-labelledby="recovery-codes-title">
		<h2 id="recovery-codes-title">Codes de récupération</h2>
		<p><strong>Conservez-les maintenant.</strong> Ils ne seront plus affichés. Chaque code remplace une fois le code TOTP, puis devient inutilisable.</p>
		<ul id="recovery-codes" class="recovery-codes">
			<?php foreach ($recovery_codes as $recovery_code): ?>
				<li><?= html_escape($recovery_code) ?></li>
			<?php endforeach; ?>
		</ul>
		<div class="actions no-print">
			<button type="button" class="secondary" data-copy-recovery>Copier la liste</button>
			<button type="button" class="secondary" data-print-recovery>Imprimer</button>
			<span class="muted" data-copy-status role="status" aria-live="polite"></span>
		</div>
	</section>
<?php elseif ($enabled): ?>
	<p class="muted"><?= (int) $recovery_code_count ?> code<?= $recovery_code_count > 1 ? 's' : '' ?> de récupération encore disponible<?= $recovery_code_count > 1 ? 's' : '' ?>. Leur contenu ne peut pas être réaffiché.</p>
<?php endif; ?>

<div id="totp-qr" class="qr" data-totp-uri="<?= html_escape($totp_uri) ?>" role="img" aria-label="QR code de configuration TOTP"></div>
<noscript><p class="alert">JavaScript est nécessaire pour afficher le QR code. Vous pouvez saisir manuellement le secret ci-dessous.</p></noscript>
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

<script src="<?= html_escape($qr_script_url) ?>"></script>
<script>
	(function () {
		var target = document.getElementById('totp-qr');
		if (!target || !target.dataset.totpUri) {
			return;
		}

		if (typeof QrCreator === 'undefined') {
			target.textContent = 'QR code indisponible. Utilisez le secret affiché ci-dessous.';
			target.classList.add('alert');
			return;
		}

		QrCreator.render({
			text: target.dataset.totpUri,
			radius: 0,
			ecLevel: 'M',
			fill: '#111827',
			background: '#ffffff',
			size: 200
		}, target);
	})();

	(function () {
		var list = document.getElementById('recovery-codes');
		if (!list) {
			return;
		}

		var codes = Array.prototype.map.call(list.querySelectorAll('li'), function (item) {
			return item.textContent.trim();
		}).join('\n');
		var copyButton = document.querySelector('[data-copy-recovery]');
		var copyStatus = document.querySelector('[data-copy-status]');
		copyButton.addEventListener('click', function () {
			var copied = navigator.clipboard && navigator.clipboard.writeText
				? navigator.clipboard.writeText(codes)
				: new Promise(function (resolve, reject) {
					var field = document.createElement('textarea');
					field.value = codes;
					field.setAttribute('readonly', '');
					field.style.position = 'fixed';
					field.style.opacity = '0';
					document.body.appendChild(field);
					field.select();
					document.execCommand('copy') ? resolve() : reject();
					field.remove();
				});

			copied.then(function () {
				copyStatus.textContent = 'Liste copiée.';
			}).catch(function () {
				copyStatus.textContent = 'Copie impossible : sélectionnez les codes manuellement.';
			});
		});

		document.querySelector('[data-print-recovery]').addEventListener('click', function () {
			window.print();
		});
	})();
</script>
