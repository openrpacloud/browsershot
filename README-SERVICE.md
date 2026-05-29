# Browsershot HTTP Service

将Browsershot封装为HTTP服务，支持并发请求处理和请求队列。

## 快速开始

### 1. 构建并启动服务

```bash
cd /Users/mac/go/src/github.com/openrpacloud/browsershot

# 构建镜像
docker-compose build

# 启动服务
docker-compose up -d

# 查看日志
docker-compose logs -f
```

### 2. 健康检查

```bash
curl http://localhost:8080/health
```

响应：
```json
{
  "status": "ok",
  "active_requests": 0,
  "max_concurrency": 10,
  "queue_length": 0
}
```

### 3. HTML转图片

```bash
curl -X POST http://localhost:8080/screenshot \
  -H "Content-Type: application/json" \
  -d '{
    "html": "<h1>Hello World</h1><p style=\"color:red;\">This is a test</p>",
    "options": {
      "width": 800,
      "height": 600,
      "format": "png",
      "fullPage": false
    }
  }'
```

响应：
```json
{
  "success": true,
  "data": "iVBORw0KGgoAAAANSUhEUgAA...",
  "format": "png"
}
```

## API接口

### `/health` (GET)

健康检查，返回服务状态。

### `/status` (GET)

详细状态，包含配置和运行指标。

### `/screenshot` (POST)

截图接口，将HTML转为图片base64。

**请求参数：**
```json
{
  "html": "HTML字符串（必填）",
  "options": {
    "width": 1920,          // 宽度（默认1920）
    "height": 1080,         // 高度（默认1080）
    "timeout": 30,          // 超时秒数（默认30）
    "format": "png",        // 格式：png/jpeg（默认png）
    "quality": 80,          // JPEG质量（仅jpeg格式）
    "fullPage": false       // 全页面截图（默认false）
  }
}
```

### `/shutdown` (POST)

优雅关闭服务，拒绝新请求，等待现有请求完成。

## 并发控制

### 配置参数

| 参数 | 默认值 | 说明 |
|------|--------|------|
| MAX_CONCURRENCY | 10 | 最大并发请求数 |
| DEFAULT_TIMEOUT | 30 | 默认超时（秒） |
| DEFAULT_WIDTH | 1920 | 默认宽度 |
| DEFAULT_HEIGHT | 1080 | 默认高度 |

### 排队机制

- 当并发达到上限时，新请求自动进入队列等待
- 等待期间检查服务是否关闭，若关闭则拒绝请求
- 使用轮询等待（每100ms检查一次）

### 资源限制

Docker配置：
- `shm_size: '1gb'` - Chrome必需，否则崩溃
- 内存限制：4GB
- 内存预留：2GB

## 本地开发（不使用Docker）

需要安装：
- PHP 8.2+
- Node.js 22+
- Puppeteer 23+

```bash
# 安装Node依赖
npm install -g puppeteer@23.0.2

# 安装PHP依赖
cd /Users/mac/go/src/github.com/openrpacloud/browsershot/server
composer install --no-dev

# 启动服务（自动检测puppeteer路径）
cd /Users/mac/go/src/github.com/openrpacloud/browsershot
php -S localhost:8080 -t server server/server.php

# 或显式指定NODE_PATH
NODE_PATH=$(npm root -g) php -S localhost:8080 -t server server/server.php
```

**本地测试结果：**
- HTML转图片成功（生成2327字节的PNG）
- 健康检查正常
- 并发控制有效（MAX_CONCURRENCY=10）
- 优雅关闭验证通过

## 集成到Go应用

在Go Executor中调用：

```go
package html2image

import (
    "bytes"
    "encoding/json"
    "net/http"
)

type ScreenshotRequest struct {
    HTML    string         `json:"html"`
    Options RequestOptions `json:"options"`
}

type RequestOptions struct {
    Width    int    `json:"width"`
    Height   int    `json:"height"`
    Timeout  int    `json:"timeout"`
    Format   string `json:"format"`
    FullPage bool   `json:"fullPage"`
}

func (e *Executor) Execute(input map[string]interface{}) (map[string]interface{}, error) {
    html := input["html"].(string)
    
    req := ScreenshotRequest{
        HTML: html,
        Options: RequestOptions{
            Width:  1920,
            Height: 1080,
            Format: "png",
        },
    }
    
    body, _ := json.Marshal(req)
    resp, err := http.Post("http://browsershot-server:8080/screenshot", 
        "application/json", bytes.NewBuffer(body))
    if err != nil {
        return nil, err
    }
    
    var result map[string]interface{}
    json.NewDecoder(resp.Body).Decode(&result)
    
    return result, nil
}
```

## 注意事项

1. **Chrome沙箱**：Docker环境下必须使用`noSandbox()`，已在代码中自动设置
2. **共享内存**：`shm_size`必须>=1GB，否则Chrome会崩溃
3. **超时设置**：根据页面复杂度调整timeout，复杂页面建议60秒
4. **内存监控**：每个Chrome tab约250-400MB，需根据服务器内存调整MAX_CONCURRENCY