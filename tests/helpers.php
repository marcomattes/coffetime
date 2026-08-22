<?php

declare(strict_types=1);

/**
 * Shared, dependency-free utilities for the standalone test scripts in this
 * directory. Every test file is still runnable on its own via
 * `php tests/X.php`; this file only avoids duplicating plumbing.
 */

$GLOBALS['__coffee_test_failures'] = 0;

/** Prints a single check result and tracks the running failure count. */
function check(string $label, bool $condition): void
{
    echo ($condition ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$condition) {
        $GLOBALS['__coffee_test_failures']++;
    }
}

/** Prints the summary line and exits non-zero if any check() call failed. */
function summarize_and_exit(): never
{
    $failures = $GLOBALS['__coffee_test_failures'];
    printf("%d failure(s)\n", $failures);
    exit($failures === 0 ? 0 : 1);
}

// ------------------------------------------------------------ Workspace ---

/**
 * Creates an empty temp directory and schedules its removal on shutdown,
 * so every test file cleans up after itself even if a check fails.
 */
function make_temp_workspace(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create temp workspace: ' . $dir);
    }
    register_shutdown_function(static function () use ($dir): void {
        remove_directory_recursive($dir);
    });

    return $dir;
}

function remove_directory_recursive(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);

        return;
    }
    $items = @scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            remove_directory_recursive($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

// ---------------------------------------------------------------- Crypto ---

/**
 * Generates a fresh 4096-bit RSA keypair. This takes a couple of seconds, so
 * callers should generate it once per test run and reuse it.
 *
 * @return array{public: string, private: string}
 */
function generate_rsa_keypair(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key === false) {
        throw new RuntimeException('Could not generate RSA keypair: ' . openssl_error_string());
    }
    if (!openssl_pkey_export($key, $privatePem)) {
        throw new RuntimeException('Could not export RSA private key');
    }
    $details = openssl_pkey_get_details($key);
    $publicPem = $details['key'] ?? null;
    if (!is_string($publicPem)) {
        throw new RuntimeException('Could not read RSA public key');
    }

    return ['public' => $publicPem, 'private' => $privatePem];
}

// ---------------------------------------------------------------- Config ---

/**
 * Writes a config.php file into $dir with sane test defaults, overridden by
 * $overrides. Returns the config file path (for COFFEE_CONFIG_PATH).
 *
 * @param array<string, mixed> $overrides
 */
function write_test_config(string $dir, array $overrides = []): string
{
    $defaults = [
        'priceCents' => 150,
        'invite' => 'TEST-INVITE',
        'admins' => [],
        'rpId' => 'localhost',
        'origin' => 'http://localhost',
        'dbPath' => $dir . '/data/coffee.sqlite',
        'testMode' => true,
        'testToken' => 'test-token-' . bin2hex(random_bytes(8)),
        'adminPublicKey' => '',
        'namePepper' => 'test-pepper',
    ];

    $dbConfig = test_db_config();
    if ($dbConfig !== null) {
        $defaults['db'] = $dbConfig;
    }

    $config = array_merge($defaults, $overrides);

    $path = $dir . '/config.php';
    file_put_contents($path, "<?php\nreturn " . var_export($config, true) . ";\n");

    return $path;
}

/**
 * Opt-in MySQL/MariaDB target for the whole test suite, controlled by the
 * COFFEE_TEST_DB environment variable (a JSON object, e.g.
 * {"driver":"mysql","host":"127.0.0.1","port":3306,"database":"coffee_test",
 * "user":"coffee","password":"x"}). Unset or non-mysql: returns null and
 * every test keeps using its own SQLite file, exactly as before.
 *
 * Every call drops and recreates the configured database, so each call to
 * write_test_config() starts from an empty schema — the same isolation a
 * fresh SQLite file path gives each test phase.
 *
 * @return array<string, mixed>|null
 */
function test_db_config(): ?array
{
    $raw = getenv('COFFEE_TEST_DB');
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || ($decoded['driver'] ?? null) !== 'mysql') {
        return null;
    }

    $host = is_string($decoded['host'] ?? null) ? $decoded['host'] : '127.0.0.1';
    $port = is_numeric($decoded['port'] ?? null) ? (int) $decoded['port'] : 3306;
    $database = is_string($decoded['database'] ?? null) ? $decoded['database'] : '';
    $user = is_string($decoded['user'] ?? null) ? $decoded['user'] : '';
    $password = is_string($decoded['password'] ?? null) ? $decoded['password'] : '';
    if ($database === '' || $user === '') {
        throw new RuntimeException('COFFEE_TEST_DB is missing "database" or "user"');
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $quoted = '`' . str_replace('`', '``', $database) . '`';
    $pdo->exec('DROP DATABASE IF EXISTS ' . $quoted);
    $pdo->exec('CREATE DATABASE ' . $quoted . ' CHARACTER SET utf8mb4');

    return [
        'driver' => 'mysql',
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'user' => $user,
        'password' => $password,
        'charset' => 'utf8mb4',
    ];
}

// ------------------------------------------------------------- HTTP server ---

/** Waits until something accepts TCP connections on host:port, or times out. */
function wait_for_port(string $host, int $port, float $timeoutSeconds = 10.0): bool
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if ($conn !== false) {
            fclose($conn);

            return true;
        }
        usleep(100000);
    }

    return false;
}

/**
 * Starts `php -S 127.0.0.1:<port> -t $docroot` with COFFEE_CONFIG_PATH set,
 * retrying on a fresh random port if the chosen one is already taken.
 *
 * @return array{0: resource, 1: int} the process handle and the bound port
 */
function start_php_server(string $docroot, string $configPath, int $attempts = 5): array
{
    $projectRoot = dirname(__DIR__);
    putenv('COFFEE_CONFIG_PATH=' . $configPath);
    putenv('PHP_CLI_SERVER_WORKERS=2');

    for ($i = 0; $i < $attempts; $i++) {
        $port = random_int(20000, 40000);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot],
            $descriptors,
            $pipes,
            $projectRoot,
            null
        );
        if (!is_resource($process)) {
            continue;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        if (wait_for_port('127.0.0.1', $port, 8.0)) {
            $status = proc_get_status($process);
            if ($status['running']) {
                return [$process, $port];
            }
        }
        stop_php_server($process);
    }

    throw new RuntimeException("Could not start the PHP built-in server after {$attempts} attempts");
}

/** @param resource $process */
function stop_php_server($process): void
{
    if (!is_resource($process)) {
        return;
    }
    $status = proc_get_status($process);
    if ($status && $status['running']) {
        proc_terminate($process, 15);
        for ($i = 0; $i < 30; $i++) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(50000);
        }
        $status = proc_get_status($process);
        if ($status && $status['running']) {
            proc_terminate($process, 9);
        }
    }
    proc_close($process);
}

// --------------------------------------------------------------- HTTP client ---

/**
 * Minimal cookie-aware HTTP client built on curl. Set-Cookie headers are
 * parsed by hand and replayed as a Cookie header on subsequent requests, so
 * tests can also inspect or swap cookies between simulated users.
 */
final class HttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    public function setCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function clearCookies(): void
    {
        $this->cookies = [];
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, json: mixed, raw: string}
     */
    public function request(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        $ch = curl_init();
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        $payload = null;
        if ($json !== null) {
            $payload = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headerLines[] = 'Content-Type: application/json';
        }

        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headerLines[] = 'Cookie: ' . implode('; ', $pairs);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        if (!is_string($response)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request to {$method} {$path} failed: {$error}");
        }
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $rawHeaders = substr($response, 0, $headerSize);
        $rawBody = substr($response, $headerSize);

        $respHeaders = [];
        foreach (preg_split('/\r\n/', trim($rawHeaders)) ?: [] as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookiePart = trim(substr($line, strlen('Set-Cookie:')));
                $firstPair = explode(';', $cookiePart, 2)[0];
                [$cName, $cValue] = array_pad(explode('=', $firstPair, 2), 2, '');
                $cName = trim($cName);
                if ($cName !== '') {
                    $this->cookies[$cName] = trim($cValue);
                }
                continue;
            }
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[trim($k)] = trim($v);
            }
        }

        return [
            'status' => $status,
            'headers' => $respHeaders,
            'json' => json_decode($rawBody, true),
            'raw' => $rawBody,
        ];
    }

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    public function post(string $path, ?array $json = null, array $headers = []): array
    {
        return $this->request('POST', $path, $json, $headers);
    }
}
