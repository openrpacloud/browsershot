<?php

require __DIR__ . '/vendor/autoload.php';

use Spatie\Browsershot\Browsershot;

class BrowsershotServer
{
    private int $maxConcurrency = 10;
    private int $defaultTimeout = 30;
    private int $defaultWidth = 1920;
    private int $defaultHeight = 1080;
    private string $nodeModulePath = '';
    private string $chromiumWsEndpoint = '';

    public function __construct()
    {
        $this->maxConcurrency = (int)($_ENV['MAX_CONCURRENCY'] ?? 10);
        $this->defaultTimeout = (int)($_ENV['DEFAULT_TIMEOUT'] ?? 30);
        $this->defaultWidth = (int)($_ENV['DEFAULT_WIDTH'] ?? 1920);
        $this->defaultHeight = (int)($_ENV['DEFAULT_HEIGHT'] ?? 1080);

        $this->discoverNodeModulePath();
        $this->discoverChromiumWsEndpoint();
    }

    private function discoverNodeModulePath(): void
    {
        $nodePathFromEnv = $_ENV['NODE_PATH'] ?? '';
        if ($nodePathFromEnv && file_exists($nodePathFromEnv . '/puppeteer')) {
            $this->nodeModulePath = $nodePathFromEnv;
            return;
        }

        $home = $_ENV['HOME'] ?? '/Users/mac';
        $possiblePaths = [
            "$home/.nvm/versions/node/v24.14.0/lib/node_modules",
            "$home/.nvm/versions/node/v22.0.0/lib/node_modules",
            '/opt/homebrew/lib/node_modules',
            '/usr/local/lib/node_modules',
            '/usr/lib/node_modules',
        ];
        foreach ($possiblePaths as $path) {
            if (file_exists($path . '/puppeteer')) {
                $this->nodeModulePath = $path;
                return;
            }
        }

        error_log("Warning: Puppeteer module path not found. Set NODE_PATH environment variable.");
    }

    private function discoverChromiumWsEndpoint(): void
    {
        $wsPort = (int)($_ENV['CHROMIUM_WS_PORT'] ?? 9222);
        $url = "http://127.0.0.1:${wsPort}/json/version";

        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $response = @file_get_contents($url, false, $ctx);

        if ($response === false) {
            error_log("Warning: Could not reach Chromium debug port ${wsPort}. Will fallback to per-request launch.");
            return;
        }

        $data = json_decode($response, true);
        if (!$data || empty($data['webSocketDebuggerUrl'])) {
            error_log("Warning: Chromium debug port responded but no webSocketDebuggerUrl found.");
            return;
        }

        $this->chromiumWsEndpoint = $data['webSocketDebuggerUrl'];
        error_log("Chromium WebSocket endpoint: {$this->chromiumWsEndpoint}");
    }

    public function handleRequest(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_SERVER['REQUEST_URI'];

        error_log("[request] {$method} {$path}");

        header('Content-Type: application/json');

        try {
            switch ($path) {
                case '/health':
                    $this->handleHealth();
                    break;
                case '/screenshot':
                    if ($method === 'POST') {
                        $this->handleScreenshot();
                    } else {
                        $this->sendError(405, 'Method not allowed');
                    }
                    break;
                case '/shutdown':
                    $this->handleShutdown();
                    break;
                case '/status':
                    $this->handleStatus();
                    break;
                default:
                    $this->sendError(404, 'Not found');
            }
        } catch (Exception $e) {
            error_log("[error] Unhandled exception: {$e->getMessage()} | file={$e->getFile()} line={$e->getLine()}");
            $this->sendError(500, $e->getMessage());
        }
    }

    private function handleHealth(): void
    {
        $chromiumReady = !empty($this->chromiumWsEndpoint);
        echo json_encode([
            'status' => $chromiumReady ? 'ok' : 'degraded',
            'chromium_connected' => $chromiumReady,
            'chromium_ws_endpoint' => $chromiumReady ? $this->chromiumWsEndpoint : null,
            'max_concurrency' => $this->maxConcurrency,
        ]);
    }

    private function handleStatus(): void
    {
        $chromiumReady = !empty($this->chromiumWsEndpoint);
        echo json_encode([
            'status' => $chromiumReady ? 'running' : 'degraded',
            'config' => [
                'max_concurrency' => $this->maxConcurrency,
                'default_timeout' => $this->defaultTimeout,
                'default_width' => $this->defaultWidth,
                'default_height' => $this->defaultHeight,
            ],
            'chromium' => [
                'connected' => $chromiumReady,
                'ws_endpoint' => $chromiumReady ? $this->chromiumWsEndpoint : null,
            ],
        ]);
    }

    private function handleScreenshot(): void
    {
        $lockFile = fopen('/tmp/browsershot_concurrency.lock', 'c');
        if (!flock($lockFile, LOCK_EX)) {
            error_log("[screenshot] Failed to acquire concurrency lock");
            $this->sendError(503, 'Could not acquire concurrency lock');
            fclose($lockFile);
            return;
        }

        $concurrencyFile = '/tmp/browsershot_active_count';
        $active = (int)@file_get_contents($concurrencyFile);

        if ($active >= $this->maxConcurrency) {
            flock($lockFile, LOCK_UN);
            fclose($lockFile);
            error_log("[screenshot] Concurrency limit reached: active={$active} max={$this->maxConcurrency}");
            $this->sendError(429, "Concurrency limit reached ({$this->maxConcurrency}). Retry later.");
            return;
        }

        $newCount = $active + 1;
        file_put_contents($concurrencyFile, $newCount);
        error_log("[screenshot] Concurrency: active={$newCount}/max={$this->maxConcurrency}");
        flock($lockFile, LOCK_UN);
        fclose($lockFile);

        try {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['html'])) {
                error_log("[screenshot] Missing required field: html");
                $this->sendError(400, 'Missing required field: html');
                return;
            }

            $html = $data['html'];
            $options = $data['options'] ?? [];

            $width = (int)($options['width'] ?? $this->defaultWidth);
            $height = (int)($options['height'] ?? $this->defaultHeight);
            $timeout = (int)($options['timeout'] ?? $this->defaultTimeout);
            $format = $options['format'] ?? 'png';
            $quality = isset($options['quality']) ? (int)$options['quality'] : null;
            $fullPage = isset($options['fullPage']) && $options['fullPage'];
            $scaleFactor = isset($options['scaleFactor']) ? (int)$options['scaleFactor'] : 1;

            error_log("[screenshot] Params: {$width}x{$height} format={$format} fullPage={$fullPage} scaleFactor={$scaleFactor} timeout={$timeout}s wsEndpoint=" . ($this->chromiumWsEndpoint ? 'yes' : 'no'));

            $browsershot = Browsershot::html($html)
                ->windowSize($width, $height)
                ->timeout($timeout)
                ->noSandbox()
                ->setScreenshotType($format, $quality);

            if ($this->nodeModulePath) {
                $browsershot->setNodeModulePath($this->nodeModulePath);
            }

            if ($this->chromiumWsEndpoint) {
                $browsershot->setWSEndpoint($this->chromiumWsEndpoint);
            }

            if ($fullPage) {
                $browsershot->fullPage();
            }

            if ($scaleFactor > 1) {
                $browsershot->deviceScaleFactor($scaleFactor);
            }

            $base64Image = $browsershot->base64Screenshot();

            $imageSize = strlen($base64Image);
            error_log("[screenshot] Success: imageSize={$imageSize} bytes format={$format}");

            echo json_encode([
                'success' => true,
                'data' => $base64Image,
                'format' => $format,
            ]);

        } catch (Exception $e) {
            error_log("[screenshot] Failed: {$e->getMessage()} | class=" . get_class($e) . " | file={$e->getFile()} line={$e->getLine()}");
            $this->sendError(500, 'Screenshot failed: ' . $e->getMessage());
        } finally {
            $lockFile2 = fopen('/tmp/browsershot_concurrency.lock', 'c');
            flock($lockFile2, LOCK_EX);
            $current = (int)@file_get_contents($concurrencyFile);
            $decremented = max(0, $current - 1);
            file_put_contents($concurrencyFile, $decremented);
            error_log("[screenshot] Concurrency released: active={$decremented}/max={$this->maxConcurrency}");
            flock($lockFile2, LOCK_UN);
            fclose($lockFile2);
        }
    }

    private function handleShutdown(): void
    {
        echo json_encode([
            'status' => 'shutting_down',
            'message' => 'Server is shutting down.',
        ]);
    }

    private function sendError(int $code, string $message): void
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
    }
}

$server = new BrowsershotServer();
$server->handleRequest();