<!doctype html>
<html lang="fr">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= html_escape($title) ?> · Aauth</title>
	<style>
		:root { color-scheme: light; --bg:#f5f6f8; --card:#fff; --text:#20242a; --muted:#69717d; --line:#dfe3e8; --accent:#2457d6; --danger:#a4262c; --ok:#166534; }
		* { box-sizing:border-box; }
		body { margin:0; background:var(--bg); color:var(--text); font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; }
		main { width:min(100% - 32px, 520px); margin:7vh auto; }
		.card { padding:28px; background:var(--card); border:1px solid var(--line); border-radius:12px; box-shadow:0 8px 28px rgba(24,32,46,.06); }
		h1 { margin:0 0 8px; font-size:1.55rem; }
		h2 { margin:24px 0 8px; font-size:1.05rem; }
		p { margin:8px 0 18px; }
		.muted { color:var(--muted); }
		label { display:block; margin:16px 0 6px; font-weight:650; }
		input { width:100%; min-height:44px; padding:10px 12px; border:1px solid #bcc3cc; border-radius:7px; background:#fff; color:inherit; font:inherit; }
		input:focus { outline:3px solid rgba(36,87,214,.16); border-color:var(--accent); }
		.check { display:flex; align-items:center; gap:8px; font-weight:400; }
		.check input { width:auto; min-height:auto; }
		.actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:22px; }
		button,.button { display:inline-flex; align-items:center; justify-content:center; min-height:42px; padding:9px 16px; border:0; border-radius:7px; background:var(--accent); color:#fff; font:650 14px/1 inherit; text-decoration:none; cursor:pointer; }
		.button.secondary,button.secondary { background:#e9edf3; color:var(--text); }
		button.danger { background:var(--danger); }
		.alert { margin:0 0 18px; padding:11px 13px; border-radius:7px; background:#feecec; color:var(--danger); }
		.notice { margin:0 0 18px; padding:11px 13px; border-radius:7px; background:#eaf7ed; color:var(--ok); }
		nav { display:flex; gap:14px; align-items:center; margin:0 0 14px; font-size:.92rem; }
		nav form { margin:0; }
		nav button { min-height:auto; padding:0; background:none; color:var(--accent); font:inherit; }
		nav a,.text-link { color:var(--accent); text-decoration:none; }
		.secret { padding:10px 12px; overflow-wrap:anywhere; border:1px dashed #aeb6c2; border-radius:7px; background:#fafbfc; font-family:ui-monospace,monospace; }
		.qr { display:flex; align-items:center; justify-content:center; width:200px; height:200px; margin:18px auto; border:1px solid var(--line); }
		.qr canvas, .qr svg { display:block; max-width:100%; height:auto; }
		.recovery-sheet { margin:22px 0; padding:16px; border:1px solid #d6b55c; border-radius:8px; background:#fffaf0; }
		.recovery-sheet h2 { margin-top:0; }
		.recovery-codes { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px 28px; margin:16px 0; padding-left:24px; font:650 14px/1.5 ui-monospace,monospace; }
		@media (max-width:440px) { .recovery-codes { grid-template-columns:1fr; } }
		@media print {
			body * { visibility:hidden; }
			.recovery-sheet,.recovery-sheet * { visibility:visible; }
			.recovery-sheet { position:absolute; inset:0 auto auto 0; width:100%; border:0; background:#fff; }
			.no-print { display:none !important; }
		}
		.password-wrap { position:relative; }
		.password-wrap input { padding-right:72px; }
		.password-toggle { position:absolute; top:5px; right:5px; min-height:34px; padding:6px 9px; background:#eef1f5; color:var(--text); font-size:12px; }
		.captcha { margin-top:16px; }
	</style>
</head>
<body>
	<main>
		<nav aria-label="Navigation principale">
			<?php if ($logged_in): ?>
				<a href="<?= site_url('account') ?>">Compte</a>
				<a href="<?= site_url('account/totp_setup') ?>">TOTP</a>
				<?= form_open('account/logout') ?><button type="submit">Déconnexion</button><?= form_close() ?>
			<?php else: ?>
				<a href="<?= site_url('account/login') ?>">Connexion</a>
				<a href="<?= site_url('account/forgot_password') ?>">Mot de passe oublié</a>
			<?php endif; ?>
		</nav>

		<section class="card">
			<?php if ($notice): ?><div class="notice" role="status"><?= html_escape($notice) ?></div><?php endif; ?>
			<?php foreach ($errors as $error): ?><div class="alert" role="alert"><?= html_escape($error) ?></div><?php endforeach; ?>
			<?= $content ?>
		</section>
	</main>
	<script>
		document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
			button.addEventListener('click', function () {
				var input = document.getElementById(button.dataset.passwordToggle);
				var visible = input.type === 'text';
				input.type = visible ? 'password' : 'text';
				button.textContent = visible ? 'Afficher' : 'Masquer';
			});
		});
		document.querySelectorAll('[data-totp]').forEach(function (input) {
			input.addEventListener('input', function () { input.value = input.value.replace(/\D/g, '').slice(0, 6); });
		});
	</script>
</body>
</html>
