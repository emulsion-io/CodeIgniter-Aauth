# Environnement de test de l'authentification

## CodeIgniter

Le script récupère automatiquement la dernière release stable du fork
[`pocketarc/codeigniter`](https://github.com/pocketarc/codeigniter). L'archive
est conservée dans `demo/.cache` et n'est pas téléchargée de nouveau tant que
la même version est présente.

Pour rendre un test reproductible, une version précise peut être demandée :

```powershell
.\demo\deploy-test.ps1 -CodeIgniterVersion '3.4.4'
```

Si GitHub est temporairement indisponible, le script utilise la version la plus
récente déjà présente dans le cache.

## User demo 

Les identifiants par défaut sont :
- Identifiant utilisateur : 1
- E-mail de connexion : admin@example.com
- Username : Admin
- Mot de passe : 12345

## BDD 

- serveur : `localhost`
- utilisateur : `root`
- mot de passe : vide
- base : `auth_test`

## Dossier de test 

Le déploiement généré se trouve dans `demo/test`. Ce dossier est ignoré par
Git.

## Instructions de test

Depuis PowerShell, à la racine du dépôt :

```powershell
.\demo\deploy-test.ps1
```

Le script extrait CodeIgniter, copie la version courante d'Aauth et ses vues,
génère la configuration CI de l'environnement `development`, crée `auth_test`
et importe `sql/Aauth_v3.sql`.

Si les tables existent déjà, le script refuse de les écraser. Pour recréer
explicitement la base de test :

```powershell
.\demo\deploy-test.ps1 -ResetDatabase
```

Les paramètres `DatabaseHost`, `DatabasePort`, `DatabaseUser`,
`DatabasePassword`, `DatabaseName`, `MailpitHost`, `MailpitSmtpPort`,
`CodeIgniterVersion`, `CodeIgniterRepository`, `CacheDirectory`,
`TargetDirectory` et `BaseUrl` permettent de surcharger les valeurs par défaut.
Le mot de passe SQL est transmis au programme d'import par une variable
d'environnement temporaire, pas dans la ligne de commande.

## CAPTCHA Cap

La démo peut activer Cap sans inscrire les identifiants dans le dépôt. Définissez
les trois variables uniquement dans le terminal courant, puis redéployez :

```powershell
$env:AAUTH_DEMO_CAP_INSTANCE_URL = 'https://cap.example.com/'
$env:AAUTH_DEMO_CAP_SITE_KEY = 'your-site-key'
$env:AAUTH_DEMO_CAP_SECRET = 'your-secret-key'
\.\demo\deploy-test.ps1 -EnableCapCaptcha -SkipDatabase
```

Le CAPTCHA est alors affiché dès la première tentative afin de faciliter le
test. Utilisez `-CaptchaLoginAttempts 4` pour retrouver le seuil recommandé en
usage normal, et `-CapWidgetMode invisible` pour tester le mode transparent.
La configuration générée reste sous `demo/test`, qui est ignoré par Git.

## E-mails avec Mailpit

Le script génère `application/config/development/email.php` pour envoyer tous
les e-mails de CodeIgniter en SMTP vers `127.0.0.1:1025`, sans authentification
ni chiffrement. Il faut donc démarrer Mailpit séparément et vérifier qu'il
écoute sur ce port. Son interface web est généralement disponible sur
<http://localhost:8025>.

Exemple avec Docker :

```powershell
docker run --rm -p 1025:1025 -p 8025:8025 axllent/mailpit
```

Pour un Mailpit exécuté sur une autre machine ou un autre port :

```powershell
.\demo\deploy-test.ps1 -MailpitHost '192.168.1.10' -MailpitSmtpPort 1025 -SkipDatabase
```

Pour déployer les fichiers sans toucher à MySQL :

```powershell
.\demo\deploy-test.ps1 -SkipDatabase
```

Puis lancer le serveur depuis `demo/test` :

```powershell
$env:CI_ENV = 'development'
php -S 127.0.0.1:8080 router.php
```

La page de connexion est disponible sur
<http://localhost:8080/account/login>.
