<?php
/**
 * Browsershot HTTP Service
 * 
 * 提供HTML转图片API，返回base64编码的图片
 * 支持并发控制、请求队列、超时处理
 */

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    $vendorAutoload = __DIR__ . '/vendor/autoload.php';
}
if (!file_exists($vendorAutoload)) {
    $vendorAutoload = __DIR__ . '/../server/vendor/autoload.php';
}
require_once $vendorAutoload;

use Spatie\Browsershot\Browsershot;

class BrowsershotServer
{
    private int $maxConcurrency = 10;
    private int $activeRequests = 0;
    private array $requestQueue = [];
    private bool $shutdown = false;
    private int $defaultTimeout = 30;
    private int $defaultWidth = 1920;
    private int $defaultHeight = 1080;
    private string $nodeModulePath = '';
    
    public function __construct()
    {
        // 从环境变量读取配置
        $this->maxConcurrency = (int)($_ENV['MAX_CONCURRENCY'] ?? 10);
        $this->defaultTimeout = (int)($_ENV['DEFAULT_TIMEOUT'] ?? 30);
        $this->defaultWidth = (int)($_ENV['DEFAULT_WIDTH'] ?? 1920);
        $this->defaultHeight = (int)($_ENV['DEFAULT_HEIGHT'] ?? 1080);
        
        // 本地测试：自动检测node模块路径
        $nodePathFromEnv = $_ENV['NODE_PATH'] ?? '';
        if ($nodePathFromEnv && file_exists($nodePathFromEnv . '/puppeteer')) {
            $this->nodeModulePath = $nodePathFromEnv;
        } else {
            // macOS NVM/Homebrew路径 - 按优先级检测
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
                    break;
                }
            }
        }
        
        if (!$this->nodeModulePath) {
            error_log("Warning: Puppeteer module path not found. Set NODE_PATH environment variable.");
        }
    }
    
    /**
     * 处理HTTP请求
     */
    public function handleRequest(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_SERVER['REQUEST_URI'];
        
        // 设置响应头
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
            $this->sendError(500, $e->getMessage());
        }
    }
    
    /**
     * 健康检查
     */
    private function handleHealth(): void
    {
        echo json_encode([
            'status' => $this->shutdown ? 'shutting_down' : 'ok',
            'active_requests' => $this->activeRequests,
            'max_concurrency' => $this->maxConcurrency,
            'queue_length' => count($this->requestQueue)
        ]);
    }
    
    /**
     * 状态检查
     */
    private function handleStatus(): void
    {
        echo json_encode([
            'status' => $this->shutdown ? 'shutting_down' : 'running',
            'config' => [
                'max_concurrency' => $this->maxConcurrency,
                'default_timeout' => $this->defaultTimeout,
                'default_width' => $this->defaultWidth,
                'default_height' => $this->defaultHeight
            ],
            'metrics' => [
                'active_requests' => $this->activeRequests,
                'queue_length' => count($this->requestQueue)
            ]
        ]);
    }
    
    /**
     * 处理截图请求
     */
    private function handleScreenshot(): void
    {
        // 如果正在关闭，直接拒绝
        if ($this->shutdown) {
            $this->sendError(503, 'Server is shutting down');
            return;
        }
        
        // 并发控制 - 如果达到上限，加入队列等待
        if ($this->activeRequests >= $this->maxConcurrency) {
            $this->requestQueue[] = true;
            // 等待队列空闲（简单实现，可以用更好的队列机制）
            while ($this->activeRequests >= $this->maxConcurrency && !$this->shutdown) {
                usleep(100000); // 100ms
            }
            array_shift($this->requestQueue);
            
            // 如果在等待期间服务关闭了，拒绝请求
            if ($this->shutdown) {
                $this->sendError(503, 'Server is shutting down');
                return;
            }
        }
        
        $this->activeRequests++;
        
        try {
            // 读取请求体
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data || !isset($data['html'])) {
                $this->sendError(400, 'Missing required field: html');
                return;
            }
            
            $html = $data['html'];
            $options = $data['options'] ?? [];
            
            // 设置参数
            $width = (int)($options['width'] ?? $this->defaultWidth);
            $height = (int)($options['height'] ?? $this->defaultHeight);
            $timeout = (int)($options['timeout'] ?? $this->defaultTimeout);
            $format = $options['format'] ?? 'png';
            $quality = isset($options['quality']) ? (int)$options['quality'] : null;
            $fullPage = isset($options['fullPage']) && $options['fullPage'];
            $scaleFactor = isset($options['scaleFactor']) ? (int)$options['scaleFactor'] : 1;
            
            // 创建Browsershot实例
            $browsershot = Browsershot::html($html)
                ->windowSize($width, $height)
                ->timeout($timeout)
                ->noSandbox()
                ->setScreenshotType($format, $quality);
            
            if ($this->nodeModulePath) {
                $browsershot->setNodeModulePath($this->nodeModulePath);
            }
            
            if ($fullPage) {
                $browsershot->fullPage();
            }
            
            if ($scaleFactor > 1) {
                $browsershot->deviceScaleFactor($scaleFactor);
            }
            
            // 执行截图并获取base64
            $base64Image = $browsershot->base64Screenshot();
            
            // 成功响应
            echo json_encode([
                'success' => true,
                'data' => $base64Image,
                'format' => $format
            ]);
            
        } catch (Exception $e) {
            $this->sendError(500, 'Screenshot failed: ' . $e->getMessage());
        } finally {
            $this->activeRequests--;
        }
    }
    
    /**
     * 处理关闭请求
     */
    private function handleShutdown(): void
    {
        $this->shutdown = true;
        
        echo json_encode([
            'status' => 'shutting_down',
            'message' => 'Server is shutting down. New requests will be rejected.',
            'active_requests' => $this->activeRequests
        ]);
    }
    
    /**
     * 发送错误响应
     */
    private function sendError(int $code, string $message): void
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ]);
    }
}

// 主入口
$server = new BrowsershotServer();
$server->handleRequest();