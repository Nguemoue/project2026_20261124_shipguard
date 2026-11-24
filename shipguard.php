#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * shipguard — ce qui ne doit jamais partir en production.
 *
 * Un seul fichier, zéro dépendance. À lancer à la racine d'un projet
 * PHP / Laravel avant un commit, ou dans la CI.
 *
 *   php shipguard.php
 *   php shipguard.php --json --strict
 *   php shipguard.php --self-test
 *
 * SPDX-License-Identifier: MIT
 */

const SHIPGUARD_VERSION = '1.0.0';
const SHIPGUARD_FINDING_CAP = 500;

function main(array $argv): int
{
    $opts = parse_args($argv);
    if ($opts['error'] !== null) {
        fwrite(STDERR, $opts['error']);
        return 2;
    }
    if ($opts['version']) {
        fwrite(STDOUT, 'shipguard ' . SHIPGUARD_VERSION . "\n");
        return 0;
    }
    if ($opts['help']) {
        fwrite(STDOUT, help_text());
        return 0;
    }
    if ($opts['self_test']) {
        return self_test();
    }
    if ($opts['demo']) {
        return run_demo($opts['color']);
    }

    $path = $opts['path'] ?? getcwd();
    if ($path === false) {
        fwrite(STDERR, "Dossier courant introuvable.\n");
        return 2;
    }
    $root = realpath($path);
    if ($root === false || !is_dir($root)) {
        fwrite(STDERR, "Dossier introuvable : {$path}\n");
        return 2;
    }

    $result = scan_root($root, $opts['strict']);
    if ($opts['json']) {
        fwrite(STDOUT, format_json($result) . "\n");
    } else {
        fwrite(STDOUT, format_text($result, $opts['color']));
    }

    return result_failed($result) ? 1 : 0;
}

function parse_args(array $argv): array
{
    $opts = [
        'path' => null,
        'json' => false,
        'strict' => false,
        'color' => null,
        'demo' => false,
        'self_test' => false,
        'help' => false,
        'version' => false,
        'error' => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        switch ($arg) {
            case '--json':
                $opts['json'] = true;
                break;
            case '--strict':
                $opts['strict'] = true;
                break;
            case '--no-color':
                $opts['color'] = false;
                break;
            case '--demo':
                $opts['demo'] = true;
                break;
            case '--self-test':
                $opts['self_test'] = true;
                break;
            case '--help':
            case '-h':
                $opts['help'] = true;
                break;
            case '--version':
                $opts['version'] = true;
                break;
            default:
                if (str_starts_with($arg, '-')) {
                    $opts['error'] = "Option inconnue : {$arg}\n\n" . help_text();
                    return $opts;
                }
                if ($opts['path'] !== null) {
                    $opts['error'] = "Un seul chemin est accepté.\n";
                    return $opts;
                }
                $opts['path'] = $arg;
        }
    }

    if ($opts['color'] === null) {
        $opts['color'] = getenv('NO_COLOR') === false && stream_isatty(STDOUT);
    }
    if ($opts['json']) {
        $opts['color'] = false;
    }

    return $opts;
}

function help_text(): string
{
    $version = SHIPGUARD_VERSION;

    return <<<TXT
shipguard {$version} — ce qui ne doit jamais partir en production

Usage :
  php shipguard.php [chemin] [options]

Options :
  --json        Rapport JSON, pour la CI
  --strict      Échoue aussi sur les alertes de niveau moyen
  --no-color    Désactive les couleurs
  --demo        Affiche un rapport sur un faux projet
  --self-test   Lance les tests intégrés
  --version     Affiche la version
  --help        Cette aide

Sans chemin, le dossier courant est analysé.
Code de sortie : 0 rien à signaler, 1 problème, 2 erreur.
Les messages sont en français. Les identifiants de règles restent en anglais.
Respecte NO_COLOR.

TXT;
}

function run_demo(bool $color): int
{
    $dir = null;
    try {
        $dir = build_risky_fixture(fake_secrets());
        $result = scan_root($dir, false);
        $banner = "Démo — faux projet Laravel, rien à voir avec ton dépôt.\n"
            . "Les secrets affichés sont faux, et masqués.\n\n";
        fwrite(STDOUT, $banner . format_text($result, $color));
        return 0;
    } finally {
        if ($dir !== null) {
            rm_rf($dir);
        }
    }
}

/**
 * @return array{
 *   root: string,
 *   files_scanned: int,
 *   env_files: int,
 *   ignored_env_files: int,
 *   elapsed_ms: int,
 *   strict: bool,
 *   truncated: bool,
 *   findings: list<array<string, mixed>>
 * }
 */
function scan_root(string $root, bool $strict): array
{
    $started = hrtime(true);
    $gitRules = load_gitignore_rules($root);
    $policy = load_policy($root);
    $files = collect_files($root, $gitRules);
    $findings = [];
    $seen = [];
    $envFiles = 0;
    $ignoredEnv = 0;
    $scanned = 0;
    $truncated = false;

    foreach ($files as $file) {
        if (count($findings) >= SHIPGUARD_FINDING_CAP) {
            $truncated = true;
            break;
        }
        if (path_matches_any($file['path'], $policy['globs'])) {
            continue;
        }
        $content = @file_get_contents($file['full']);
        if ($content === false || str_contains($content, "\0")) {
            continue;
        }
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $scanned++;
        $base = basename($file['path']);
        if (is_env_filename($base)) {
            $envFiles++;
            if ($file['ignored'] && !is_example_env($base)) {
                $ignoredEnv++;
            }
        }
        scan_file($file, $content, $policy['disabled'], $findings, $seen);
    }

    usort($findings, static function (array $a, array $b): int {
        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2];
        return [$rank[$a['severity']] ?? 9, $a['path'], $a['line'], $a['rule']]
            <=> [$rank[$b['severity']] ?? 9, $b['path'], $b['line'], $b['rule']];
    });

    return [
        'root' => $root,
        'files_scanned' => $scanned,
        'env_files' => $envFiles,
        'ignored_env_files' => $ignoredEnv,
        'elapsed_ms' => (int) round((hrtime(true) - $started) / 1e6),
        'strict' => $strict,
        'truncated' => $truncated,
        'findings' => $findings,
    ];
}

function result_failed(array $result): bool
{
    foreach ($result['findings'] as $finding) {
        if ($finding['severity'] === 'critical' || $finding['severity'] === 'high') {
            return true;
        }
        if ($result['strict'] && $finding['severity'] === 'medium') {
            return true;
        }
    }

    return false;
}

function collect_files(string $root, array $gitRules): array
{
    $files = [];
    $walk = static function (string $dir, string $rel) use (&$walk, &$files, $gitRules): void {
        $names = @scandir($dir);
        if ($names === false) {
            return;
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $relPath = $rel === '' ? $name : $rel . '/' . $name;
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            $isDir = is_dir($full);
            if (always_skip($relPath, $isDir)) {
                continue;
            }
            if ($isDir) {
                if (gitignore_ignored($relPath, $gitRules)) {
                    continue;
                }
                $walk($full, $relPath);
                continue;
            }
            if (!is_file($full) || !is_scannable($name)) {
                continue;
            }
            $size = @filesize($full);
            if ($size === false || $size > 1048576) {
                continue;
            }
            $files[] = [
                'path' => $relPath,
                'full' => $full,
                'ignored' => gitignore_ignored($relPath, $gitRules),
            ];
        }
    };
    $walk($root, '');
    usort($files, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

    return $files;
}

function always_skip(string $rel, bool $isDir): bool
{
    $base = strtolower(basename($rel));
    if (in_array($base, ['.git', 'vendor', 'node_modules'], true)) {
        return true;
    }
    $check = $rel . ($isDir ? '/' : '');
    foreach (['storage/framework/', 'storage/logs/', 'bootstrap/cache/', 'public/build/'] as $prefix) {
        if ($check === $prefix || str_starts_with($check, $prefix) || $rel === rtrim($prefix, '/')) {
            return true;
        }
    }

    return false;
}

function is_scannable(string $name): bool
{
    if ($name === '.gitignore' || $name === '.shipguardignore') {
        return false;
    }
    if (str_starts_with($name, '.env') || $name === 'env') {
        return true;
    }
    $special = ['id_rsa', 'id_dsa', 'id_ecdsa', 'id_ed25519', '.npmrc', '.pypirc', '.htpasswd'];
    if (in_array($name, $special, true)) {
        return true;
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    return in_array($ext, [
        'php', 'inc', 'phtml', 'js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'vue',
        'json', 'yml', 'yaml', 'xml', 'ini', 'env', 'conf', 'toml', 'md', 'txt',
        'htaccess', 'dist', 'sample', 'example', 'cfg', 'properties', 'key', 'pem',
        'twig', 'sh', 'bash', 'zsh',
    ], true);
}

function scan_file(array $file, string $content, array $disabled, array &$findings, array &$seen): void
{
    $base = basename($file['path']);
    if (is_env_filename($base)) {
        $vars = parse_env($content);
        if (is_example_env($base)) {
            scan_example_env($file, $content, $vars, $disabled, $findings, $seen);
        } else {
            scan_real_env($file, $content, $vars, $disabled, $findings, $seen);
        }
    }
    if (!$file['ignored'] && is_config_php($file['path'])) {
        scan_config_php($file, $content, $disabled, $findings, $seen);
    }
    if (!$file['ignored']) {
        scan_secrets($file, $content, $disabled, $findings, $seen);
    }
}

function is_env_filename(string $base): bool
{
    return $base === 'env' || str_starts_with($base, '.env');
}

function is_example_env(string $base): bool
{
    $low = strtolower($base);
    foreach (['example', 'sample', 'template', 'dist'] as $hint) {
        if (str_contains($low, $hint)) {
            return true;
        }
    }

    return false;
}

function is_config_php(string $path): bool
{
    return preg_match('#(^|/)config/[^/]+\.php$#', $path) === 1;
}

function scan_real_env(array $file, string $content, array $vars, array $disabled, array &$findings, array &$seen): void
{
    $base = basename($file['path']);
    $prod = is_prod_context($vars, $base);
    $exposed = !$file['ignored'];
    $laravel = looks_like_laravel_env($vars);

    if ($exposed) {
        add_finding($findings, $seen, $disabled, finding(
            'env-not-ignored',
            $prod ? 'critical' : 'high',
            $file['path'],
            1,
            $prod
                ? "Ce fichier d'environnement de production n'est pas ignoré par Git."
                : "Ce fichier d'environnement n'est pas ignoré par Git.",
            "Ajoute-le au .gitignore. S'il a déjà été commité, sors-le de l'historique : le retirer maintenant ne suffit pas.",
            ''
        ));
    }

    if ($laravel && ($prod || $exposed)) {
        scan_app_key($file, $content, $vars, $prod, $exposed, $disabled, $findings, $seen);
    }

    if ($laravel && $prod && isset($vars['APP_DEBUG']) && is_truthy($vars['APP_DEBUG']['value'])) {
        $line = $vars['APP_DEBUG']['line'];
        if (!line_has_ignore($content, $line)) {
            add_finding($findings, $seen, $disabled, finding(
                'prod-debug',
                'critical',
                $file['path'],
                $line,
                "APP_DEBUG est activé alors que l'environnement est la production.",
                'Mets APP_DEBUG=false. Une page d\'erreur en production affiche tes chemins, tes requêtes et souvent tes variables.',
                excerpt_line($content, $line)
            ));
        }
    }

    if ($laravel && $prod && isset($vars['APP_URL']) && str_starts_with(strtolower($vars['APP_URL']['value']), 'http://')) {
        $line = $vars['APP_URL']['line'];
        if (!line_has_ignore($content, $line)) {
            add_finding($findings, $seen, $disabled, finding(
                'prod-http-url',
                'high',
                $file['path'],
                $line,
                'APP_URL est en http:// en production.',
                'Passe en https:// pour que les cookies de session ne circulent pas en clair.',
                excerpt_line($content, $line)
            ));
        }
    }

    if ($laravel && $prod) {
        $secure = $vars['SESSION_SECURE_COOKIE']['value'] ?? null;
        $line = $vars['SESSION_SECURE_COOKIE']['line'] ?? ($vars['APP_ENV']['line'] ?? 1);
        if (($secure === null || !is_truthy($secure)) && !line_has_ignore($content, $line)) {
            add_finding($findings, $seen, $disabled, finding(
                'prod-session-insecure',
                'high',
                $file['path'],
                $line,
                $secure === null
                    ? "SESSION_SECURE_COOKIE n'est pas défini en production."
                    : "SESSION_SECURE_COOKIE n'est pas actif en production.",
                'Mets SESSION_SECURE_COOKIE=true.',
                $secure === null ? '' : excerpt_line($content, $line)
            ));
        }
    }

    if ($laravel && isset($vars['DB_PASSWORD']) && ($prod || $exposed) && !is_sqlite($vars)) {
        $value = $vars['DB_PASSWORD']['value'];
        $line = $vars['DB_PASSWORD']['line'];
        if (is_placeholder($value) && !line_has_ignore($content, $line)) {
            add_finding($findings, $seen, $disabled, finding(
                'weak-db-password',
                'high',
                $file['path'],
                $line,
                $value === ''
                    ? "DB_PASSWORD est vide alors que la base n'est pas sqlite."
                    : 'DB_PASSWORD est un mot de passe évident.',
                'Utilise un mot de passe long et unique. Ne le mets pas dans un fichier versionné.',
                excerpt_line($content, $line, $value)
            ));
        }
    }

    if ($laravel && $prod && !$exposed && isset($vars['DATABASE_URL'])) {
        $line = $vars['DATABASE_URL']['line'];
        $classified = classify_db_url($vars['DATABASE_URL']['value']);
        if ($classified !== null && is_placeholder($classified['secret']) && !line_has_ignore($content, $line)) {
            add_finding($findings, $seen, $disabled, finding(
                'weak-db-password',
                'high',
                $file['path'],
                $line,
                'Le mot de passe dans DATABASE_URL est vide ou évident.',
                'Utilise un mot de passe long et unique.',
                excerpt_line($content, $line, $classified['secret'])
            ));
        }
    }

    if ($laravel && $prod && isset($vars['MAIL_MAILER']) && in_array(strtolower($vars['MAIL_MAILER']['value']), ['log', 'array'], true)) {
        $line = $vars['MAIL_MAILER']['line'];
        if (!line_has_ignore($content, $line)) {
            add_finding($findings, $seen, $disabled, finding(
                'prod-mail-log',
                'medium',
                $file['path'],
                $line,
                'Les mails partent vers log ou array en production : personne ne les reçoit.',
                'Branche un vrai transport (smtp, ses, postmark, resend...).',
                excerpt_line($content, $line)
            ));
        }
    }

    if ($laravel && $prod && isset($vars['DEBUGBAR_ENABLED']) && is_truthy($vars['DEBUGBAR_ENABLED']['value'])) {
        $line = $vars['DEBUGBAR_ENABLED']['line'];
        if (!line_has_ignore($content, $line)) {
            add_finding($findings, $seen, $disabled, finding(
                'debugbar-on',
                'high',
                $file['path'],
                $line,
                'Laravel Debugbar est activé en production.',
                'Mets DEBUGBAR_ENABLED=false.',
                excerpt_line($content, $line)
            ));
        }
    }

    if ($laravel && $prod && isset($vars['TELESCOPE_ENABLED']) && is_truthy($vars['TELESCOPE_ENABLED']['value'])) {
        $line = $vars['TELESCOPE_ENABLED']['line'];
        if (!line_has_ignore($content, $line)) {
            add_finding($findings, $seen, $disabled, finding(
                'telescope-on',
                'high',
                $file['path'],
                $line,
                'Telescope est activé en production.',
                'Mets TELESCOPE_ENABLED=false et protège la route.',
                excerpt_line($content, $line)
            ));
        }
    }
}

function scan_app_key(array $file, string $content, array $vars, bool $prod, bool $exposed, array $disabled, array &$findings, array &$seen): void
{
    if (!isset($vars['APP_KEY'])) {
        $line = $vars['APP_ENV']['line'] ?? 1;
        if (!line_has_ignore($content, $line)) {
            add_finding($findings, $seen, $disabled, finding(
                'empty-app-key',
                $prod ? 'critical' : 'high',
                $file['path'],
                $line,
                'APP_KEY est absent.',
                'Génère une clé avec php artisan key:generate. Ne la commit pas.',
                ''
            ));
        }
        return;
    }

    $value = $vars['APP_KEY']['value'];
    $line = $vars['APP_KEY']['line'];
    if (line_has_ignore($content, $line)) {
        return;
    }
    if (looks_like_app_key($value)) {
        if ($exposed) {
            add_finding($findings, $seen, $disabled, finding(
                'tracked-app-key',
                'critical',
                $file['path'],
                $line,
                'Une vraie APP_KEY est dans un fichier qui peut être commité.',
                'Retire-la du dépôt, régénère-la, et ignore ce fichier.',
                excerpt_line($content, $line, $value)
            ));
        }
        return;
    }

    add_finding($findings, $seen, $disabled, finding(
        'empty-app-key',
        $prod ? 'critical' : 'high',
        $file['path'],
        $line,
        'APP_KEY est vide ou factice.',
        'Génère une clé avec php artisan key:generate. Ne la commit pas.',
        excerpt_line($content, $line)
    ));
}

function scan_example_env(array $file, string $content, array $vars, array $disabled, array &$findings, array &$seen): void
{
    if (!isset($vars['APP_KEY'])) {
        return;
    }
    $value = $vars['APP_KEY']['value'];
    $line = $vars['APP_KEY']['line'];
    if (!looks_like_app_key($value) || line_has_ignore($content, $line)) {
        return;
    }
    add_finding($findings, $seen, $disabled, finding(
        'example-app-key',
        'high',
        $file['path'],
        $line,
        "Le fichier d'exemple contient une APP_KEY qui ressemble à une vraie clé.",
        'Laisse APP_KEY vide dans .env.example.',
        excerpt_line($content, $line, $value)
    ));
}

function scan_config_php(array $file, string $content, array $disabled, array &$findings, array &$seen): void
{
    $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
    foreach ($lines as $index => $line) {
        $lineNo = $index + 1;
        if (str_contains(strtolower($line), 'shipguard:ignore') || str_contains($line, 'env(')) {
            continue;
        }
        if (preg_match('#([\'"])debug\1\s*=>\s*true\b#', $line) === 1) {
            add_finding($findings, $seen, $disabled, finding(
                'hardcoded-debug',
                'high',
                $file['path'],
                $lineNo,
                'debug est forcé à true dans un fichier de config versionné.',
                "Utilise env('APP_DEBUG', false).",
                trim($line)
            ));
        }
        if (preg_match('#([\'"])(?:key|APP_KEY)\1\s*=>\s*([\'"])(base64:[A-Za-z0-9+/=]{16,})\2#', $line, $match) === 1) {
            add_finding($findings, $seen, $disabled, finding(
                'hardcoded-app-key',
                'critical',
                $file['path'],
                $lineNo,
                'Une APP_KEY est écrite en dur au lieu de passer par env().',
                "Remplace par env('APP_KEY') et régénère la clé si elle a été publiée.",
                excerpt_line($content, $lineNo, $match[3])
            ));
        }
    }
}

function scan_secrets(array $file, string $content, array $disabled, array &$findings, array &$seen): void
{
    $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
    $rules = secret_rules();
    foreach ($lines as $index => $line) {
        if (strlen($line) > 5000) {
            continue;
        }
        $lineNo = $index + 1;
        if (str_contains(strtolower($line), 'shipguard:ignore')) {
            continue;
        }
        foreach ($rules as $rule) {
            if (preg_match_all($rule['regex'], $line, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $match) {
                $secret = $match[0];
                if (is_boring_secret($secret)) {
                    continue;
                }
                add_finding($findings, $seen, $disabled, finding(
                    $rule['rule'],
                    $rule['severity'],
                    $file['path'],
                    $lineNo,
                    $rule['label'] . ' dans un fichier qui peut être commité.',
                    "Retire-le, révoque-le, et remplace-le par une variable d'environnement.",
                    $rule['rule'] === 'private-key'
                        ? 'en-tête de clé privée'
                        : excerpt_line($content, $lineNo, $secret)
                ));
                break;
            }
        }
        $db = classify_db_url($line);
        if ($db !== null) {
            add_finding($findings, $seen, $disabled, finding(
                'db-url',
                $db['severity'],
                $file['path'],
                $lineNo,
                $db['severity'] === 'critical'
                    ? 'URL de base de données avec mot de passe dans un fichier versionnable.'
                    : 'URL de base de données avec un mot de passe évident dans un fichier versionnable.',
                "Passe par une variable d'environnement. Révoque ce mot de passe s'il est réel.",
                excerpt_line($content, $lineNo, $db['secret'])
            ));
        }
    }
}

function secret_rules(): array
{
    return [
        [
            'rule' => 'aws-access-key',
            'severity' => 'critical',
            'label' => "Clé d'accès AWS",
            'regex' => '/(?<![A-Z0-9])(?:AKIA|ASIA)[A-Z0-9]{16}(?![A-Z0-9])/',
        ],
        [
            'rule' => 'github-token',
            'severity' => 'critical',
            'label' => 'Token GitHub',
            'regex' => '/(?<![A-Za-z0-9])ghp_[A-Za-z0-9]{36}(?![A-Za-z0-9])/',
        ],
        [
            'rule' => 'github-fine-token',
            'severity' => 'critical',
            'label' => 'Token GitHub fine-grained',
            'regex' => '/github_pat_[A-Za-z0-9_]{22,}/',
        ],
        [
            'rule' => 'gitlab-token',
            'severity' => 'critical',
            'label' => 'Token GitLab',
            'regex' => '/glpat-[A-Za-z0-9\-_]{20,}/',
        ],
        [
            'rule' => 'stripe-live',
            'severity' => 'critical',
            'label' => 'Clé secrète Stripe live',
            'regex' => '/(?<![A-Za-z0-9])(?:sk|rk)_live_[A-Za-z0-9]{16,}(?![A-Za-z0-9])/',
        ],
        [
            'rule' => 'stripe-test',
            'severity' => 'medium',
            'label' => 'Clé secrète Stripe de test',
            'regex' => '/(?<![A-Za-z0-9])(?:sk|rk)_test_[A-Za-z0-9]{16,}(?![A-Za-z0-9])/',
        ],
        [
            'rule' => 'slack-token',
            'severity' => 'critical',
            'label' => 'Token Slack',
            'regex' => '/xox[baprs]-[A-Za-z0-9-]{10,}/',
        ],
        [
            'rule' => 'google-api-key',
            'severity' => 'critical',
            'label' => 'Clé API Google',
            'regex' => '/(?<![A-Za-z0-9_\-])AIza[0-9A-Za-z_\-]{35,}(?![A-Za-z0-9_\-])/',
        ],
        [
            'rule' => 'private-key',
            'severity' => 'critical',
            'label' => 'Clé privée',
            'regex' => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY-----/',
        ],
        [
            'rule' => 'slack-webhook',
            'severity' => 'critical',
            'label' => 'Webhook Slack',
            'regex' => '#https://hooks\.slack\.com/services/T[A-Z0-9]+/B[A-Z0-9]+/[A-Za-z0-9]+#',
        ],
        [
            'rule' => 'discord-webhook',
            'severity' => 'critical',
            'label' => 'Webhook Discord',
            'regex' => '#https://(?:discord\.com|discordapp\.com)/api/webhooks/\d+/[A-Za-z0-9_\-]{20,}#',
        ],
        [
            'rule' => 'llm-api-key',
            'severity' => 'critical',
            'label' => "Clé d'API de modèle",
            'regex' => '/(?<![A-Za-z0-9])sk-(?:proj|ant|svcacct)-[A-Za-z0-9_\-]{20,}/',
        ],
        [
            'rule' => 'xai-key',
            'severity' => 'critical',
            'label' => 'Clé API xAI',
            'regex' => '/(?<![A-Za-z0-9])xai-[A-Za-z0-9]{24,}(?![A-Za-z0-9])/',
        ],
    ];
}

function is_boring_secret(string $secret): bool
{
    if (preg_match('/example|placeholder|changeme|dummy|sample/i', $secret) === 1) {
        return true;
    }
    $core = preg_replace(
        '/^(?:AKIA|ASIA|ghp_|github_pat_|glpat-|sk_live_|sk_test_|rk_live_|rk_test_|sk-proj-|sk-ant-|sk-svcacct-|xai-|xox[baprs]-)/i',
        '',
        $secret
    );
    if (!is_string($core) || $core === '') {
        return false;
    }

    return preg_match('/^(.)\1+$/', $core) === 1;
}

function classify_db_url(string $line): ?array
{
    if (preg_match('#(?i)(?:postgres(?:ql)?|mysql|mongodb(?:\+srv)?|redis|amqp)://[^:\s\'"]+:([^@\s\'"]+)@#', $line, $match) !== 1) {
        return null;
    }
    $password = rawurldecode($match[1]);
    if ($password === '' || str_contains($password, '${') || str_contains($password, '{{')) {
        return null;
    }
    $docHost = preg_match('#@(?:localhost|127\.0\.0\.1|0\.0\.0\.0|example\.com|example\.org|example\.test)\b#i', $line) === 1;
    if (is_placeholder($password) && $docHost) {
        return null;
    }

    return [
        'severity' => is_placeholder($password) ? 'high' : 'critical',
        'secret' => $password,
    ];
}

function parse_env(string $content): array
{
    $vars = [];
    $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
    foreach ($lines as $index => $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '#')) {
            continue;
        }
        if (str_starts_with($trim, 'export ')) {
            $trim = trim(substr($trim, 7));
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $trim, $match) !== 1) {
            continue;
        }
        $vars[$match[1]] = [
            'value' => unquote_env($match[2]),
            'line' => $index + 1,
        ];
    }

    return $vars;
}

function unquote_env(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $quote = $value[0];
    if ($quote === '"' || $quote === "'") {
        $length = strlen($value);
        $inner = '';
        for ($i = 1; $i < $length; $i++) {
            $char = $value[$i];
            if ($quote === '"' && $char === '\\' && $i + 1 < $length) {
                $next = $value[++$i];
                $inner .= match ($next) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"' => '"',
                    '\\' => '\\',
                    default => $next,
                };
                continue;
            }
            if ($char === $quote) {
                return $inner;
            }
            $inner .= $char;
        }

        return $inner;
    }
    $hash = strpos($value, ' #');
    if ($hash !== false) {
        $value = substr($value, 0, $hash);
    }

    return trim($value);
}

function is_truthy(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function is_prod_context(array $vars, string $basename): bool
{
    if (in_array($basename, ['.env.production', '.env.prod'], true)) {
        return true;
    }
    foreach (['APP_ENV', 'NODE_ENV', 'RAILS_ENV', 'APP_ENVIRONMENT'] as $key) {
        if (isset($vars[$key]) && in_array(strtolower($vars[$key]['value']), ['production', 'prod'], true)) {
            return true;
        }
    }

    return false;
}

function looks_like_laravel_env(array $vars): bool
{
    foreach (['APP_KEY', 'APP_ENV', 'APP_NAME', 'APP_DEBUG', 'DB_CONNECTION', 'MAIL_MAILER'] as $key) {
        if (isset($vars[$key])) {
            return true;
        }
    }

    return false;
}

function is_sqlite(array $vars): bool
{
    if (!isset($vars['DB_CONNECTION'])) {
        return false;
    }
    $driver = strtolower($vars['DB_CONNECTION']['value']);

    return $driver === 'sqlite' || $driver === 'sqlite3';
}

function looks_like_app_key(string $value): bool
{
    if (is_placeholder($value)) {
        return false;
    }
    if (str_starts_with($value, 'base64:')) {
        return strlen($value) >= 24;
    }

    return strlen($value) >= 20;
}

function is_placeholder(string $value): bool
{
    $value = trim($value);
    if ($value === '' || strlen($value) < 8) {
        return true;
    }
    $low = strtolower($value);
    $exact = [
        'password', 'secret', 'secret123', 'changeme', 'change_me', 'change-me',
        'your_password', 'your-password', 'your_secret', 'your-secret', 'your_api_key',
        'your-api-key', 'your_key', 'your_app_key', 'xxx', 'xxxx', 'xxxxx', 'xxxxxx',
        'todo', 'placeholder', 'example', 'sample', 'dummy', 'test', 'testing',
        'foo', 'bar', '1234', '123456', '12345678', '123456789', 'laravel', 'mysql',
        'postgres', 'root', 'admin', 'azerty', 'qwerty', 'toor', 'pass', 'passwd',
        'default', 'somerandomstring', 'changethis', 'change_this', 'replace_me',
        'replaceme', 'none', 'null', 'undefined', 'n/a', 'na', 'true', 'false',
        'base64:', 'password1', 'passw0rd', 'admin123', 'rootroot',
    ];
    if (in_array($low, $exact, true)) {
        return true;
    }
    if (preg_match('/^(?:your[_-]|xxx+|<.*>|\$\{.*\}|\{\{.*\}\})/i', $value) === 1) {
        return true;
    }
    foreach (['example', 'placeholder', 'changeme', 'somerandomstring', 'replace_me'] as $bit) {
        if (str_contains($low, $bit)) {
            return true;
        }
    }
    if (preg_match('/^(.)\1{7,}$/', $value) === 1) {
        return true;
    }

    return preg_match('/^base64:(.)\1{8,}=*$/', $value) === 1;
}

function line_has_ignore(string $content, int $line): bool
{
    $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];

    return str_contains(strtolower($lines[$line - 1] ?? ''), 'shipguard:ignore');
}

function excerpt_line(string $content, int $line, string $secret = ''): string
{
    $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
    $text = trim($lines[$line - 1] ?? '');
    if ($secret !== '' && $secret !== '0' && str_contains($text, $secret)) {
        $text = str_replace($secret, mask_secret($secret), $text);
    }
    if (strlen($text) > 180) {
        $text = substr($text, 0, 177) . '...';
    }

    return $text;
}

function mask_secret(string $secret): string
{
    $length = strlen($secret);
    if ($length <= 8) {
        return str_repeat('*', $length);
    }

    return substr($secret, 0, 4) . '••••' . substr($secret, -2);
}

function finding(string $rule, string $severity, string $path, int $line, string $message, string $hint, string $excerpt): array
{
    return [
        'rule' => $rule,
        'severity' => $severity,
        'path' => $path,
        'line' => $line,
        'message' => $message,
        'hint' => $hint,
        'excerpt' => $excerpt,
    ];
}

function add_finding(array &$findings, array &$seen, array $disabled, array $finding): void
{
    if (in_array($finding['rule'], $disabled, true)) {
        return;
    }
    if (count($findings) >= SHIPGUARD_FINDING_CAP) {
        return;
    }
    $key = $finding['rule'] . "\0" . $finding['path'] . "\0" . $finding['line'];
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $findings[] = $finding;
}

function load_gitignore_rules(string $root): array
{
    $path = $root . DIRECTORY_SEPARATOR . '.gitignore';
    if (!is_file($path)) {
        return [];
    }
    $text = file_get_contents($path);

    return $text === false ? [] : compile_gitignore($text);
}

function load_policy(string $root): array
{
    $path = $root . DIRECTORY_SEPARATOR . '.shipguardignore';
    $disabled = [];
    $globs = [];
    if (!is_file($path)) {
        return ['disabled' => $disabled, 'globs' => $globs];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['disabled' => $disabled, 'globs' => $globs];
    }
    foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^rule:\s*(.+)$/i', $line, $match) === 1) {
            $disabled[] = trim($match[1]);
            continue;
        }
        $globs[] = $line;
    }

    return ['disabled' => $disabled, 'globs' => $globs];
}

function path_matches_any(string $path, array $globs): bool
{
    foreach ($globs as $glob) {
        $rule = compile_gitignore_line($glob);
        if ($rule !== null && gitignore_ignored($path, [$rule])) {
            return true;
        }
    }

    return false;
}

function compile_gitignore(string $text): array
{
    $rules = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
        $rule = compile_gitignore_line($line);
        if ($rule !== null) {
            $rules[] = $rule;
        }
    }

    return $rules;
}

function compile_gitignore_line(string $line): ?array
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        return null;
    }
    $neg = false;
    if (str_starts_with($line, '!')) {
        $neg = true;
        $line = substr($line, 1);
    }
    if ($line === '') {
        return null;
    }
    $dirOnly = str_ends_with($line, '/');
    if ($dirOnly) {
        $line = substr($line, 0, -1);
    }
    $anchored = str_starts_with($line, '/');
    if ($anchored) {
        $line = substr($line, 1);
    }
    if (!$anchored && str_contains($line, '/')) {
        $anchored = true;
    }
    $body = glob_to_regex($line);
    $regex = ($anchored ? '^' : '(?:^|/)') . $body . ($dirOnly ? '(?:/.*)?$' : '$');

    return ['neg' => $neg, 'regex' => '#' . $regex . '#'];
}

function glob_to_regex(string $glob): string
{
    $out = '';
    $length = strlen($glob);
    for ($i = 0; $i < $length; $i++) {
        $char = $glob[$i];
        if ($char === '*') {
            $next = $i + 1 < $length ? $glob[$i + 1] : '';
            if ($next === '*') {
                $after = $i + 2 < $length ? $glob[$i + 2] : '';
                if ($after === '/') {
                    $out .= '(?:.*/)?';
                    $i += 2;
                    continue;
                }
                $out .= '.*';
                $i++;
                continue;
            }
            $out .= '[^/]*';
            continue;
        }
        if ($char === '?') {
            $out .= '[^/]';
            continue;
        }
        $out .= preg_quote($char, '#');
    }

    return $out;
}

function gitignore_ignored(string $path, array $rules): bool
{
    $path = str_replace('\\', '/', $path);
    $ignored = false;
    foreach ($rules as $rule) {
        if (preg_match($rule['regex'], $path) === 1) {
            $ignored = !$rule['neg'];
        }
    }

    return $ignored;
}

function format_text(array $result, bool $color): string
{
    $badges = ['critical' => 'critique', 'high' => 'élevé', 'medium' => 'moyen'];
    $colors = ['critical' => '1;31', 'high' => '1;33', 'medium' => '1;36'];
    $out = colorize('shipguard ' . SHIPGUARD_VERSION, '1', $color) . "\n";
    $out .= $result['root'] . "\n\n";

    if ($result['findings'] === []) {
        $out .= colorize('Rien à signaler.', '1;32', $color) . "\n";
    } else {
        foreach ($result['findings'] as $finding) {
            $badge = pad_display($badges[$finding['severity']] ?? $finding['severity'], 8);
            $out .= '  ' . colorize($badge, $colors[$finding['severity']] ?? '0', $color);
            $out .= '  ' . colorize($finding['rule'], '2', $color) . "\n";
            $out .= '  ' . $finding['path'] . ':' . $finding['line'] . "\n";
            $out .= '  ' . $finding['message'] . "\n";
            if ($finding['excerpt'] !== '') {
                $out .= '  ' . colorize($finding['excerpt'], '2', $color) . "\n";
            }
            $out .= '  → ' . $finding['hint'] . "\n\n";
        }
    }

    $counts = severity_counts($result['findings']);
    $total = count($result['findings']);
    if ($total > 0) {
        $noun = $total > 1 ? 'problèmes' : 'problème';
        $out .= $total . ' ' . $noun
            . ' · critique ' . $counts['critical']
            . ' · élevé ' . $counts['high']
            . ' · moyen ' . $counts['medium']
            . "\n";
    }
    $out .= footer_stats($result) . "\n";
    if ($result['ignored_env_files'] > 0) {
        $out .= "Les .env ignorés par Git sont contrôlés (debug, cookies, mails) mais leurs secrets ne sont pas listés.\n";
    }
    if ($result['truncated']) {
        $out .= 'Analyse arrêtée à ' . SHIPGUARD_FINDING_CAP . " résultats.\n";
    }
    if ($counts['critical'] > 0) {
        $out .= "Révoque les secrets déjà poussés. Les retirer du fichier ne suffit pas.\n";
    }

    return $out;
}

function format_json(array $result): string
{
    $findings = [];
    foreach ($result['findings'] as $finding) {
        $findings[] = [
            'rule' => $finding['rule'],
            'severity' => $finding['severity'],
            'path' => $finding['path'],
            'line' => $finding['line'],
            'message' => $finding['message'],
            'hint' => $finding['hint'],
            'excerpt' => $finding['excerpt'],
        ];
    }
    $payload = [
        'tool' => 'shipguard',
        'version' => SHIPGUARD_VERSION,
        'root' => $result['root'],
        'ok' => !result_failed($result),
        'strict' => $result['strict'],
        'files_scanned' => $result['files_scanned'],
        'env_files' => $result['env_files'],
        'ignored_env_files' => $result['ignored_env_files'],
        'elapsed_ms' => $result['elapsed_ms'],
        'truncated' => $result['truncated'],
        'counts' => severity_counts($result['findings']),
        'findings' => $findings,
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return $json === false ? '{"ok":false,"error":"json_encode a échoué"}' : $json;
}

function severity_counts(array $findings): array
{
    $counts = ['critical' => 0, 'high' => 0, 'medium' => 0];
    foreach ($findings as $finding) {
        if (isset($counts[$finding['severity']])) {
            $counts[$finding['severity']]++;
        }
    }

    return $counts;
}

function footer_stats(array $result): string
{
    $files = $result['files_scanned'];
    $envs = $result['env_files'];
    $text = $files . ' fichier' . ($files > 1 ? 's' : '') . ' lu' . ($files > 1 ? 's' : '');
    $text .= ' · ' . $envs . ' fichier' . ($envs > 1 ? 's' : '') . " d'environnement";
    if ($result['ignored_env_files'] > 0) {
        $ignored = $result['ignored_env_files'];
        $text .= ' (' . $ignored . ' ignoré' . ($ignored > 1 ? 's' : '') . ' par Git)';
    }
    $text .= ' · ' . $result['elapsed_ms'] . ' ms';

    return $text;
}

function colorize(string $text, string $code, bool $on): string
{
    if (!$on || $text === '') {
        return $text;
    }

    return "\033[" . $code . 'm' . $text . "\033[0m";
}

function pad_display(string $text, int $width): string
{
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length >= $width) {
        return $text;
    }

    return $text . str_repeat(' ', $width - $length);
}

/**
 * Les fixtures sont construites à l'exécution.
 * Le dépôt ne contient aucun secret contigu : les détecteurs de GitHub
 * bloqueraient le push, et un lecteur ne doit pas copier une vraie clé.
 */
function fake_secrets(): array
{
    return [
        'aws' => 'AK' . 'IA' . 'A1B2C3D4E5F6G7H8',
        'aws_doc' => 'AK' . 'IA' . 'IOSFODNN7EXAMPLE',
        'aws_log' => 'AK' . 'IA' . 'B1B2B3B4B5B6B7B8',
        'stripe' => 'sk_' . 'live_' . '51Hh0t4k3nN0t4R34lK3yXx',
        'stripe_ignored' => 'sk_' . 'live_' . 'SHOULDNOTAPPEAR99999',
        'stripe_test' => 'sk_' . 'test_' . '51Hh0t4k3nN0t4R34lZz',
        'stripe_modules' => 'sk_' . 'live_' . 'NodeModulesOnly000999',
        'github' => 'gh' . 'p_' . str_repeat('a1', 18),
        'github_fine' => 'github_' . 'pat_' . str_repeat('a1_', 12),
        'github_vendor' => 'gh' . 'p_' . str_repeat('b2', 18),
        'gitlab' => 'gl' . 'pat-' . str_repeat('ab', 12),
        'slack_token' => 'xox' . 'b-' . str_repeat('1a', 12),
        'google' => 'AI' . 'za' . str_repeat('A1', 18),
        'llm_openai' => 'sk-' . 'proj-' . str_repeat('Ab', 16),
        'llm_anthropic' => 'sk-' . 'ant-' . str_repeat('Cd', 16),
        'xai' => 'xai-' . str_repeat('k9', 16),
        'pem_begin' => '-----BEGIN ' . 'PRIVATE KEY-----',
        'pem_end' => '-----END ' . 'PRIVATE KEY-----',
        'ssh_begin' => '-----BEGIN ' . 'OPENSSH PRIVATE KEY-----',
        'slack_hook' => 'https://hooks.' . 'slack.com/services/' . 'T0ABCDEFG/' . 'B0HIJKLMN/' . 'AbCdEfGhIjKlMnOpQrStUvWx',
        'discord' => 'https://discord.com/api/' . 'webhooks/' . '123456789012345678/' . 'abcdefghijklmnopqrstuvwxyzABCD',
        'app_key' => 'base64:' . str_repeat('Zy', 16),
        'db_password' => 's3cr3t-db-Passw0rd',
        'strong_db' => 'N0tARealWord!9xQ',
    ];
}

function build_risky_fixture(array $s): string
{
    $dir = make_temp('risky');
    $pay = "<?php\n\n// Doc AWS : " . $s['aws_doc'] . "\nfunction pem(): string\n{\n    return \"" . $s['pem_begin'] . "\\n\" .\n        \"fake\\n\" .\n        \"" . $s['pem_end'] . "\\n\";\n}\n\nfunction ssh(): string\n{\n    return \"" . $s['ssh_begin'] . "\\n\";\n}\n";
    $tokens = implode("\n", [
        $s['github'],
        $s['github_fine'],
        $s['gitlab'],
        $s['stripe'],
        $s['stripe_test'],
        $s['slack_token'],
        $s['google'],
        $s['llm_openai'],
        $s['llm_anthropic'],
        $s['xai'],
        $s['slack_hook'],
        $s['discord'],
    ]) . "\n";
    write_tree($dir, [
        '.gitignore' => "/vendor\n/node_modules\n",
        '.env' => "APP_NAME=Risky\nexport APP_ENV=production\nAPP_DEBUG=\"true\"\nAPP_KEY=\nAPP_URL=http://shop.example.com\nDB_CONNECTION=mysql\nDB_PASSWORD=\"password\" # local\nSESSION_SECURE_COOKIE=false\nMAIL_MAILER=log\nDEBUGBAR_ENABLED=true\nTELESCOPE_ENABLED=true\nSTRIPE_SECRET=" . $s['stripe_ignored'] . " # shipguard:ignore\n",
        '.env.example' => "APP_NAME=Risky\nAPP_ENV=production\nAPP_DEBUG=true\nAPP_KEY=" . $s['app_key'] . "\nAPP_URL=http://example.com\nDB_CONNECTION=mysql\nDB_PASSWORD=\nSESSION_SECURE_COOKIE=false\nMAIL_MAILER=log\nDEBUGBAR_ENABLED=true\nTELESCOPE_ENABLED=true\nAWS_ACCESS_KEY_ID=" . $s['aws'] . "\n",
        'app/Pay.php' => $pay,
        'tokens.txt' => $tokens,
        'config/app.php' => "<?php\nreturn [\n    'debug' => true,\n    'key' => '" . $s['app_key'] . "',\n];\n",
        'config/database.php' => "<?php\nreturn ['url' => 'mysql://app:" . $s['db_password'] . "@db.internal/app'];\n",
        'vendor/evil.php' => "<?php\n\$token = '" . $s['github_vendor'] . "';\n",
        'node_modules/index.js' => 'const k = "' . $s['stripe_modules'] . "\";\n",
        'storage/logs/laravel.log' => 'key=' . $s['aws_log'] . "\n",
    ]);

    return $dir;
}

function build_healthy_fixture(array $s): string
{
    $dir = make_temp('healthy');
    write_tree($dir, [
        '.gitignore' => "/vendor\n/node_modules\n.env\n.env.backup\n.env.production\n",
        '.env' => "APP_NAME=Healthy\nAPP_ENV=local\nAPP_KEY=" . $s['app_key'] . "\nAPP_DEBUG=true\nAPP_URL=http://localhost\nDB_CONNECTION=mysql\nDB_PASSWORD=secret\nSESSION_SECURE_COOKIE=false\nMAIL_MAILER=log\nDEBUGBAR_ENABLED=true\n",
        '.env.example' => "APP_NAME=Healthy\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nDB_CONNECTION=mysql\nDB_PASSWORD=\nAWS_ACCESS_KEY_ID=\nSTRIPE_SECRET=\n",
        'app/Http/Controllers/OrderController.php' => "<?php\n\n// Doc AWS : " . $s['aws_doc'] . "\n\$password = env('DB_PASSWORD');\n\$key = env('APP_KEY');\n",
        'config/app.php' => "<?php\nreturn [\n    'debug' => (bool) env('APP_DEBUG', false),\n    'key' => env('APP_KEY'),\n];\n",
        'README.md' => 'Doc locale : postgres://user:' . "password@localhost/db\n",
    ]);

    return $dir;
}

function build_prodfile_fixture(array $s): string
{
    $dir = make_temp('prodfile');
    write_tree($dir, [
        '.gitignore' => ".env\n/vendor\n",
        '.env.production' => "APP_NAME=Shop\nAPP_ENV=production\nAPP_DEBUG=true\nAPP_KEY=" . $s['app_key'] . "\nAPP_URL=https://shop.example\nDB_CONNECTION=mysql\nDB_PASSWORD=" . $s['strong_db'] . "\nSESSION_SECURE_COOKIE=true\nMAIL_MAILER=smtp\n",
    ]);

    return $dir;
}

function build_medium_fixture(array $s): string
{
    $dir = make_temp('medium');
    write_tree($dir, [
        '.gitignore' => ".env\n",
        '.env' => "APP_NAME=Shop\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=" . $s['app_key'] . "\nAPP_URL=https://shop.test\nDB_CONNECTION=mysql\nDB_PASSWORD=" . $s['strong_db'] . "\nSESSION_SECURE_COOKIE=true\nMAIL_MAILER=log\n",
    ]);

    return $dir;
}

function build_sqlite_fixture(array $s): string
{
    $dir = make_temp('sqlite');
    write_tree($dir, [
        '.gitignore' => ".env\n",
        '.env' => "APP_NAME=Shop\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=" . $s['app_key'] . "\nAPP_URL=https://shop.test\nDB_CONNECTION=sqlite\nDB_PASSWORD=\nSESSION_SECURE_COOKIE=true\nMAIL_MAILER=smtp\n",
    ]);

    return $dir;
}

function self_test(): int
{
    $previous = error_reporting(E_ALL);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        if (!$ok) {
            $failures[] = $message;
        }
    };
    $dirs = [];

    try {
        $rules = compile_gitignore(".env\n/vendor\n*.log\nstorage/*.key\nsecret/*\n!secret/keep.txt\npublic/**/*.map\n");
        $check(gitignore_ignored('.env', $rules), 'gitignore .env');
        $check(!gitignore_ignored('.env.example', $rules), 'gitignore not .env.example');
        $check(!gitignore_ignored('.env.production', $rules), 'gitignore not .env.production');
        $check(gitignore_ignored('foo/.env', $rules), 'gitignore nested .env');
        $check(gitignore_ignored('vendor', $rules), 'gitignore /vendor directory');
        $check(!gitignore_ignored('vendor/a.php', $rules), 'gitignore /vendor does not match a nested path by itself');
        $check(!gitignore_ignored('app/Vendor.php', $rules), 'gitignore vendor is exact');
        $check(gitignore_ignored('foo.log', $rules), 'gitignore *.log');
        $check(gitignore_ignored('storage/logs/laravel.log', $rules), 'gitignore nested log');
        $check(gitignore_ignored('storage/oauth-private.key', $rules), 'gitignore storage/*.key');
        $check(!gitignore_ignored('storage/logs/oauth.key', $rules), 'gitignore star does not cross slash');
        $check(gitignore_ignored('secret/a.txt', $rules), 'gitignore secret/*');
        $check(!gitignore_ignored('secret/keep.txt', $rules), 'gitignore negation');
        $check(gitignore_ignored('public/a.map', $rules), 'gitignore ** file');
        $check(gitignore_ignored('public/build/app.js.map', $rules), 'gitignore ** nested');
        $check(!gitignore_ignored('public/a.map.bak', $rules), 'gitignore ** suffix');
        $check(!gitignore_ignored('src/public/a.map', $rules), 'gitignore ** anchored');

        $env = parse_env("# comment\nAPP_DEBUG=\"true\"\nAPP_KEY='base64:abc'\nDB_PASSWORD=password # nope\nexport MAIL_MAILER=log\nEMPTY=\nQUOTED=\"http://x.test # keep\"\n");
        $check(($env['APP_DEBUG']['value'] ?? '') === 'true' && ($env['APP_DEBUG']['line'] ?? 0) === 2, 'parse APP_DEBUG');
        $check(($env['APP_KEY']['value'] ?? '') === 'base64:abc', 'parse APP_KEY');
        $check(($env['DB_PASSWORD']['value'] ?? '') === 'password', 'parse inline comment');
        $check(($env['MAIL_MAILER']['value'] ?? '') === 'log', 'parse export');
        $check(($env['EMPTY']['value'] ?? 'x') === '', 'parse empty');
        $check(($env['QUOTED']['value'] ?? '') === 'http://x.test # keep', 'parse quoted hash');
        $check(!isset($env['COMMENT']), 'parse skips comments');
        $quoted = parse_env("DB_PASSWORD=\"password\" # local\nNAME=\"a\\nb\"\n");
        $check(($quoted['DB_PASSWORD']['value'] ?? '') === 'password', 'parse quoted then comment');
        $check(($quoted['NAME']['value'] ?? '') === "a\nb", 'parse escaped newline');

        $s = fake_secrets();
        $risky = build_risky_fixture($s);
        $dirs[] = $risky;
        $riskyResult = scan_root($risky, true);
        $diff = rules_diff($riskyResult, [
            'prod-debug' => 1,
            'empty-app-key' => 1,
            'env-not-ignored' => 1,
            'weak-db-password' => 1,
            'prod-http-url' => 1,
            'prod-session-insecure' => 1,
            'prod-mail-log' => 1,
            'debugbar-on' => 1,
            'telescope-on' => 1,
            'example-app-key' => 1,
            'aws-access-key' => 1,
            'stripe-live' => 1,
            'stripe-test' => 1,
            'github-token' => 1,
            'github-fine-token' => 1,
            'gitlab-token' => 1,
            'slack-token' => 1,
            'google-api-key' => 1,
            'llm-api-key' => 2,
            'xai-key' => 1,
            'private-key' => 2,
            'slack-webhook' => 1,
            'discord-webhook' => 1,
            'hardcoded-debug' => 1,
            'hardcoded-app-key' => 1,
            'db-url' => 1,
        ]);
        $check($diff === '', 'risky ' . $diff);
        $check(finding_line($riskyResult, 'prod-debug') === 3, 'prod-debug line');
        $check(finding_line($riskyResult, 'empty-app-key') === 4, 'empty-app-key line');
        $check(finding_line($riskyResult, 'prod-http-url') === 5, 'prod-http-url line');
        $check(finding_line($riskyResult, 'weak-db-password') === 7, 'weak-db-password line');
        $check(result_failed($riskyResult), 'risky fails');
        $riskyText = format_text($riskyResult, false);
        $riskyJson = format_json($riskyResult);
        assert_redacted($check, $riskyText . "\n" . $riskyJson, $s, 'risky');
        $check(str_contains($riskyText, 'AKIA••••H8'), 'aws mask shown');
        $check(!str_contains($riskyText, 'AKIA••••LE'), 'aws doc key not shown');
        $check(str_contains($riskyText, 'sk_l••••Xx'), 'stripe mask shown');
        $check(!str_contains($riskyText, 'SHOULDNOTAPPEAR'), 'ignored stripe hidden');

        $healthy = build_healthy_fixture($s);
        $dirs[] = $healthy;
        $healthyResult = scan_root($healthy, true);
        $check(rules_diff($healthyResult, []) === '', 'healthy ' . rules_diff($healthyResult, []));
        $check(!result_failed($healthyResult), 'healthy passes strict');

        $prodfile = build_prodfile_fixture($s);
        $dirs[] = $prodfile;
        $prodResult = scan_root($prodfile, false);
        $check(rules_diff($prodResult, [
            'env-not-ignored' => 1,
            'prod-debug' => 1,
            'tracked-app-key' => 1,
        ]) === '', 'prodfile ' . rules_diff($prodResult, [
            'env-not-ignored' => 1,
            'prod-debug' => 1,
            'tracked-app-key' => 1,
        ]));

        $medium = build_medium_fixture($s);
        $dirs[] = $medium;
        $mediumLoose = scan_root($medium, false);
        $mediumStrict = scan_root($medium, true);
        $check(rules_diff($mediumLoose, ['prod-mail-log' => 1]) === '', 'medium rules ' . rules_diff($mediumLoose, ['prod-mail-log' => 1]));
        $check(!result_failed($mediumLoose), 'medium does not fail by default');
        $check(result_failed($mediumStrict), 'medium fails when strict');
        $disabledMail = build_medium_fixture($s);
        $dirs[] = $disabledMail;
        file_put_contents($disabledMail . '/.shipguardignore', "rule: prod-mail-log\n");
        $mediumIgnored = scan_root($disabledMail, true);
        $check(rules_diff($mediumIgnored, []) === '', 'rule disable ' . rules_diff($mediumIgnored, []));

        $sqlite = build_sqlite_fixture($s);
        $dirs[] = $sqlite;
        $sqliteResult = scan_root($sqlite, true);
        $check(rules_diff($sqliteResult, []) === '', 'sqlite ' . rules_diff($sqliteResult, []));

        $ignored = make_temp('ignore');
        $dirs[] = $ignored;
        write_tree($ignored, [
            'secret.js' => 'const t = "' . $s['github'] . "\";\n",
            '.shipguardignore' => "secret.js\n",
        ]);
        $ignoredResult = scan_root($ignored, true);
        $check(rules_diff($ignoredResult, []) === '', 'shipguardignore glob');
        unlink($ignored . '/.shipguardignore');
        $visible = scan_root($ignored, true);
        $check(rules_diff($visible, ['github-token' => 1]) === '', 'ignore removed ' . rules_diff($visible, ['github-token' => 1]));

        $docUrl = make_temp('docurl');
        $dirs[] = $docUrl;
        write_tree($docUrl, [
            'note.md' => 'postgres://user:' . "password@localhost/db\n",
        ]);
        $check(rules_diff(scan_root($docUrl, true), []) === '', 'localhost doc url');

        $weakUrl = make_temp('weakurl');
        $dirs[] = $weakUrl;
        $weakLine = 'mysql://root:' . 'password@' . 'db.internal/app';
        write_tree($weakUrl, [
            'config/remote.php' => "<?php\n\$url = '" . $weakLine . "';\n",
        ]);
        $weakResult = scan_root($weakUrl, false);
        $check(rules_diff($weakResult, ['db-url' => 1]) === '', 'remote weak url ' . rules_diff($weakResult, ['db-url' => 1]));
        $check(($weakResult['findings'][0]['severity'] ?? '') === 'high', 'remote weak url is high');

        $repo = scan_root(dirname(__FILE__), true);
        $check(rules_diff($repo, []) === '', 'repo is clean ' . rules_diff($repo, []));

        $cliRisky = run_php([$risky]);
        $check($cliRisky['code'] === 1, 'cli risky exit ' . $cliRisky['code'] . ' ' . $cliRisky['err']);
        $check(!has_php_error($cliRisky), 'cli risky warnings ' . $cliRisky['err']);
        $cliHealthy = run_php([$healthy, '--strict']);
        $check($cliHealthy['code'] === 0, 'cli healthy exit ' . $cliHealthy['code'] . ' ' . $cliHealthy['err']);
        $check(!has_php_error($cliHealthy), 'cli healthy warnings');
        $cliMedium = run_php([$medium]);
        $check($cliMedium['code'] === 0, 'cli medium exit ' . $cliMedium['code']);
        $cliStrict = run_php([$medium, '--strict']);
        $check($cliStrict['code'] === 1, 'cli medium strict exit ' . $cliStrict['code']);
        $cliJson = run_php([$risky, '--json']);
        $payload = json_decode($cliJson['out'], true);
        $check($cliJson['code'] === 1 && is_array($payload), 'cli json');
        $check(is_array($payload) && ($payload['ok'] ?? true) === false, 'cli json ok false');
        $check(is_array($payload) && ($payload['counts']['critical'] ?? 0) > 0, 'cli json counts');
        $check(is_array($payload) && ($payload['tool'] ?? '') === 'shipguard', 'cli json tool');
        assert_redacted($check, $cliJson['out'], $s, 'cli json');
        $cliDemo = run_php(['--demo']);
        $check($cliDemo['code'] === 0, 'cli demo exit ' . $cliDemo['code'] . ' ' . $cliDemo['err']);
        $check(str_contains($cliDemo['out'], 'Démo'), 'cli demo banner');
        $check(str_contains($cliDemo['out'], 'prod-debug'), 'cli demo finding');
        assert_redacted($check, $cliDemo['out'], $s, 'demo');
        $cliHelp = run_php(['--help']);
        $check($cliHelp['code'] === 0 && str_contains($cliHelp['out'], 'Usage'), 'cli help');
        $cliVersion = run_php(['--version']);
        $check($cliVersion['code'] === 0 && str_contains($cliVersion['out'], SHIPGUARD_VERSION), 'cli version');
        $cliBad = run_php(['--nope']);
        $check($cliBad['code'] === 2, 'cli bad option ' . $cliBad['code']);
        $cliMissing = run_php([$risky . '-missing']);
        $check($cliMissing['code'] === 2, 'cli missing path ' . $cliMissing['code']);
    } catch (Throwable $e) {
        $failures[] = 'exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    } finally {
        restore_error_handler();
        error_reporting($previous);
        foreach ($dirs as $dir) {
            rm_rf($dir);
        }
    }

    if ($failures !== []) {
        fwrite(STDOUT, 'shipguard self-test: ' . count($failures) . " échec(s)\n");
        foreach ($failures as $failure) {
            fwrite(STDOUT, '  - ' . $failure . "\n");
        }
        return 1;
    }

    fwrite(STDOUT, "shipguard self-test: ok\n");

    return 0;
}

function rules_diff(array $result, array $expected): string
{
    $got = [];
    foreach ($result['findings'] as $finding) {
        $got[$finding['rule']] = ($got[$finding['rule']] ?? 0) + 1;
    }
    ksort($got);
    ksort($expected);
    if ($got === $expected) {
        return '';
    }

    return 'got ' . json_encode($got) . ' expected ' . json_encode($expected);
}

function finding_line(array $result, string $rule): ?int
{
    foreach ($result['findings'] as $finding) {
        if ($finding['rule'] === $rule) {
            return $finding['line'];
        }
    }

    return null;
}

function assert_redacted(callable $check, string $blob, array $secrets, string $label): void
{
    foreach ($secrets as $name => $value) {
        if (!is_string($value) || strlen($value) < 8) {
            continue;
        }
        $check(!str_contains($blob, $value), $label . ' leaks ' . $name);
    }
}

function has_php_error(array $run): bool
{
    return preg_match('/(Warning|Notice|Deprecated|Fatal error):/i', $run['err'] . $run['out']) === 1;
}

function run_php(array $args): array
{
    $cmd = array_merge([PHP_BINARY, __FILE__], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['code' => 127, 'out' => '', 'err' => 'proc_open a échoué'];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'code' => proc_close($proc),
        'out' => $out === false ? '' : $out,
        'err' => $err === false ? '' : $err,
    ];
}

function make_temp(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/shipguard-' . $prefix . '-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer ' . $dir);
    }

    return $dir;
}

function write_tree(string $dir, array $files): void
{
    foreach ($files as $path => $content) {
        $full = $dir . '/' . $path;
        $parent = dirname($full);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('Impossible de créer ' . $parent);
        }
        if (file_put_contents($full, $content) === false) {
            throw new RuntimeException('Impossible d\'écrire ' . $full);
        }
    }
}

function rm_rf(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
    exit(main($argv));
}
