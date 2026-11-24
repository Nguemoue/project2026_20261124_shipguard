# shipguard

Un seul fichier PHP. Zéro Composer. Zéro compte. Rien ne quitte ta machine.

Tu le lances avant `git push`. Il attrape les trucs qui font honte en production : un `APP_DEBUG=true`, un `.env` qui n'est pas ignoré, une clé Stripe ou un token GitHub collés dans le code.

```bash
php shipguard.php
php shipguard.php --demo
```

PHP **8.1+**, aucune extension hors du cœur. Testé sur 8.1, 8.3 et 8.4.

## Ça attrape quoi

| Règle | Niveau | Quand |
| --- | --- | --- |
| `prod-debug` | critique | `APP_DEBUG` actif en production |
| `empty-app-key` | critique / élevé | `APP_KEY` vide ou factice |
| `tracked-app-key` | critique | une vraie `APP_KEY` dans un fichier versionnable |
| `example-app-key` | élevé | `.env.example` contient une vraie clé |
| `env-not-ignored` | critique / élevé | `.env` (ou `.env.production`) absent du `.gitignore` |
| `weak-db-password` | élevé | mot de passe de base vide ou évident |
| `prod-http-url` | élevé | `APP_URL=http://` en production |
| `prod-session-insecure` | élevé | cookie de session non sécurisé en production |
| `debugbar-on` | élevé | Debugbar allumé en production |
| `telescope-on` | élevé | Telescope allumé en production |
| `hardcoded-debug` | élevé | `'debug' => true` dans `config/*.php` |
| `hardcoded-app-key` | critique | `APP_KEY` en dur dans `config/*.php` |
| `db-url` | critique / élevé | URL `mysql://` / `postgres://` avec mot de passe |
| `prod-mail-log` | moyen | `MAIL_MAILER=log` en production |
| `stripe-test` | moyen | clé Stripe de test |
| `aws-access-key`, `github-token`, `github-fine-token`, `gitlab-token`, `stripe-live`, `slack-token`, `slack-webhook`, `discord-webhook`, `google-api-key`, `llm-api-key`, `xai-key`, `private-key` | critique | secret dans un fichier qui peut être commité |

Le code de sortie est `1` dès qu'il y a une alerte critique ou élevée. `--strict` ajoute les alertes moyennes. Pratique en CI.

## Exemple

```text
critique  prod-debug
.env:3
APP_DEBUG est activé alors que l'environnement est la production.
APP_DEBUG="true"
→ Mets APP_DEBUG=false.

critique  aws-access-key
.env.example:12
Clé d'accès AWS dans un fichier qui peut être commité.
AWS_ACCESS_KEY_ID=AKIA••••H8
→ Retire-le, révoque-le, et passe par une variable d'environnement.
```

Les extraits sont masqués. Tu peux coller le rapport dans un ticket sans republier le secret. Ça ne le révoque pas : si la clé a déjà été poussée, il faut la tourner.

`php shipguard.php --demo` affiche le même genre de rapport sur un faux projet.

## Lancer

```bash
curl -fsSL -o shipguard.php \
  https://raw.githubusercontent.com/Nguemoue/project2026_20261124_shipguard/main/shipguard.php

php shipguard.php
php shipguard.php /chemin/du/projet --json
php shipguard.php --self-test
```

`--self-test` rejoue les cas limites dans un dossier temporaire. Le dépôt ne contient aucun secret en clair : les fixtures sont construites au moment du test.

## GitHub Actions

```yaml
name: shipguard
on: [push, pull_request]
jobs:
  guard:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
      - run: php shipguard.php --self-test
      - run: php shipguard.php --strict
```

`--json` sert si tu veux parser le rapport. Le champ `ok` est `false` quand le processus doit échouer. Les `rule` et `severity` (`critical`, `high`, `medium`) restent en anglais. Les messages sont en français.

## Ce qui est volontairement ignoré

- `vendor/`, `node_modules/`, `.git/`, `storage/logs/`, `storage/framework/`, `bootstrap/cache/`, `public/build/`
- un `.env` déjà couvert par le `.gitignore` : on vérifie quand même la prod (`APP_DEBUG`, cookies, mails), mais on ne liste pas les secrets qui ne seront pas commités
- les mots de passe évidents sur `localhost` (`postgres://user:password@localhost/db`)
- la clé de documentation AWS qui finit par `EXAMPLE`
- une ligne qui contient `shipguard:ignore`

`.env` dans le `.gitignore` ne couvre pas `.env.production`. C'est un oubli classique. shipguard le signale.

## Faire taire un faux positif

Sur la ligne :

```php
$legacy = $token; // shipguard:ignore
```

Ou dans `.shipguardignore` à la racine :

```gitignore
# un fichier
tests/fixtures/tokens.txt

# une règle, partout
rule: stripe-test
```

Seul le `.gitignore` à la racine est lu.

## Limites

C'est un filet avant déploiement, pas un audit. Pas d'appel réseau, pas de base de fuites, pas d'analyse de l'historique git. Un secret déjà poussé reste valide tant que tu ne le révoques pas.

## English

One PHP file, no dependencies, nothing uploaded. It flags production misconfig (`APP_DEBUG`, insecure cookies, Debugbar, Telescope), env files missing from `.gitignore`, and high-confidence secrets (AWS, GitHub, GitLab, Stripe, Slack, Google, private keys, LLM API keys) in files that can be committed. Exit code `1` fails CI.

```bash
php shipguard.php
php shipguard.php --json --strict
php shipguard.php --self-test
```

## Licence

[MIT](LICENSE)
