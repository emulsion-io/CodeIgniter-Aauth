<h1><?= html_escape($title) ?></h1>
<p><?= html_escape($message) ?></p>
<?php if (!empty($action_url)): ?>
	<a class="button" href="<?= html_escape($action_url) ?>"><?= html_escape($action_label) ?></a>
<?php endif; ?>
