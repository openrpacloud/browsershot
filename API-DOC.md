# Browsershot Service API 文档

HTML转图片HTTP服务接口说明

## 服务地址

```
http://localhost:8080
```

生产环境请替换为实际服务器地址。

---

## 接口列表

| 接口 | 方法 | 功能 |
|------|------|------|
| `/screenshot` | POST | HTML转图片（返回base64） |
| `/health` | GET | 健康检查 |
| `/status` | GET | 服务状态详情 |
| `/shutdown` | POST | 优雅关闭服务 |

---

## 1. 截图接口 `/screenshot`

### 请求

**方法**: POST

**Content-Type**: application/json

**请求体**:
```json
{
  "html": "HTML字符串（必填）",
  "options": {
    "width": 1920,
    "height": 1080,
    "timeout": 30,
    "format": "png",
    "quality": 80,
    "fullPage": false
  }
}
```

### 参数说明

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| `html` | string | ✓ | - | HTML字符串内容 |
| `options.width` | int | - | 1920 | 图片宽度（像素） |
| `options.height` | int | - | 1080 | 图片高度（像素） |
| `options.timeout` | int | - | 30 | 超时时间（秒） |
| `options.format` | string | - | png | 图片格式：png 或 jpeg |
| `options.quality` | int | - | - | JPEG质量（1-100），仅jpeg格式有效 |
| `options.fullPage` | bool | - | false | 是否截取完整页面（自动高度） |
| `options.scaleFactor` | int | - | 1 | 缩放因子（1/2/3），提高图片分辨率 |

### 响应

**成功响应**:
```json
{
  "success": true,
  "data": "iVBORw0KGgoAAAANSUhEUgAAA...",
  "format": "png"
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `success` | bool | 是否成功 |
| `data` | string | 图片base64编码字符串 |
| `format` | string | 图片格式 |

**失败响应**:
```json
{
  "success": false,
  "error": {
    "code": 500,
    "message": "Screenshot failed: ..."
  }
}
```

| 错误码 | 说明 |
|--------|------|
| 400 | 请求参数错误（缺少html字段） |
| 500 | 截图失败（超时、渲染错误等） |
| 503 | 服务正在关闭，拒绝新请求 |

### 示例

```bash
# PNG格式截图
curl -X POST http://localhost:8080/screenshot \
  -H "Content-Type: application/json" \
  -d '{"html":"<h1>Hello</h1>","options":{"width":800,"height":600}}'
```

```bash
# JPEG格式截图（指定质量）
curl -X POST http://localhost:8080/screenshot \
  -H "Content-Type: application/json" \
  -d '{"html":"<h1>Hello</h1>","options":{"width":800,"height":600,"format":"jpeg","quality":80}}'
```

```bash
# 高分辨率截图（scaleFactor=2，图片尺寸翻倍，文字更清晰）
curl -X POST http://localhost:8080/screenshot \
  -H "Content-Type: application/json" \
  -d '{"html":"<h1>Hello</h1>","options":{"width":800,"height":600,"scaleFactor":2}}'
```

```bash
# 超高清截图（scaleFactor=3，适用于打印/大屏展示）
curl -X POST http://localhost:8080/screenshot \
  -H "Content-Type: application/json" \
  -d '{"html":"<h1>Hello</h1>","options":{"width":800,"height":600,"scaleFactor":3}}'
```

```bash
# 全页面截图
curl -X POST http://localhost:8080/screenshot \
  -H "Content-Type: application/json" \
  -d '{"html":"<html><body><h1>Title</h1><p>Content</p></body></html>","options":{"fullPage":true}}'
```

---

## 2. 健康检查 `/health`

### 请求

**方法**: GET

### 响应

```json
{
  "status": "ok",
  "active_requests": 2,
  "max_concurrency": 10,
  "queue_length": 3
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `status` | string | 服务状态：ok / shutting_down |
| `active_requests` | int | 当前活跃请求数 |
| `max_concurrency` | int | 最大并发数 |
| `queue_length` | int | 等待队列长度 |

### 示例

```bash
curl http://localhost:8080/health
```

---

## 3. 状态详情 `/status`

### 请求

**方法**: GET

### 响应

```json
{
  "status": "running",
  "config": {
    "max_concurrency": 10,
    "default_timeout": 30,
    "default_width": 1920,
    "default_height": 1080
  },
  "metrics": {
    "active_requests": 0,
    "queue_length": 0
  }
}
```

### 示例

```bash
curl http://localhost:8080/status
```

---

## 4. 优雅关闭 `/shutdown`

### 请求

**方法**: POST

### 响应

```json
{
  "status": "shutting_down",
  "message": "Server is shutting down. New requests will be rejected.",
  "active_requests": 2
}
```

### 说明

- 调用后服务进入关闭状态，拒绝新的截图请求
- 等待进行中的请求完成后可安全停止服务
- 关闭期间`/health`返回`status: shutting_down`

### 示例

```bash
curl -X POST http://localhost:8080/shutdown
```

---

## 并发控制机制

### 排队等待

当并发请求数达到`max_concurrency`上限时，新请求自动进入等待队列。

队列中的请求每100ms检查一次：
- 若有空闲位置，立即执行
- 若服务关闭，返回503错误

### 配置调整

通过环境变量修改并发参数：

```bash
MAX_CONCURRENCY=10     # 最大并发数
DEFAULT_TIMEOUT=30     # 默认超时（秒）
DEFAULT_WIDTH=1920     # 默认宽度
DEFAULT_HEIGHT=1080    # 默认高度
```

启动时设置：
```bash
MAX_CONCURRENCY=20 php -S localhost:8080 -t server server/server.php
```

---

## Go集成示例

```go
package html2image

import (
    "bytes"
    "encoding/json"
    "fmt"
    "io"
    "net/http"
    "time"
)

type ScreenshotRequest struct {
    HTML    string         `json:"html"`
    Options RequestOptions `json:"options,omitempty"`
}

type RequestOptions struct {
    Width    int    `json:"width,omitempty"`
    Height   int    `json:"height,omitempty"`
    Timeout  int    `json:"timeout,omitempty"`
    Format   string `json:"format,omitempty"`
    Quality  int    `json:"quality,omitempty"`
    FullPage bool   `json:"fullPage,omitempty"`
}

type ScreenshotResponse struct {
    Success bool   `json:"success"`
    Data    string `json:"data,omitempty"`
    Format  string `json:"format,omitempty"`
    Error   *Error `json:"error,omitempty"`
}

type Error struct {
    Code    int    `json:"code"`
    Message string `json:"message"`
}

func ConvertHTMLToImage(endpoint, html string, opts RequestOptions) (string, error) {
    req := ScreenshotRequest{
        HTML:    html,
        Options: opts,
    }
    
    body, _ := json.Marshal(req)
    
    client := &http.Client{Timeout: 60 * time.Second}
    resp, err := client.Post(endpoint + "/screenshot", 
        "application/json", bytes.NewBuffer(body))
    if err != nil {
        return "", err
    }
    defer resp.Body.Close()
    
    respBody, _ := io.ReadAll(resp.Body)
    
    var result ScreenshotResponse
    json.Unmarshal(respBody, &result)
    
    if !result.Success {
        return "", fmt.Errorf("error %d: %s", result.Error.Code, result.Error.Message)
    }
    
    return result.Data, nil
}

// 调用示例
func ExampleUsage() {
    html := `<h1 style="color:red">测试标题</h1><p>正文内容</p>`
    
    base64Img, err := ConvertHTMLToImage("http://localhost:8080", html, RequestOptions{
        Width:  800,
        Height: 600,
        Format: "png",
    })
    
    if err != nil {
        panic(err)
    }
    
    // base64Img可直接传输或解码保存为文件
    fmt.Printf("图片base64长度: %d\n", len(base64Img))
}
```

---

## 配置参数详解

### 环境变量配置

| 变量名 | 默认值 | 说明 |
|--------|--------|------|
| `MAX_CONCURRENCY` | 10 | 最大并发请求数 |
| `DEFAULT_TIMEOUT` | 30 | 默认超时时间（秒） |
| `DEFAULT_WIDTH` | 1920 | 默认图片宽度（像素） |
| `DEFAULT_HEIGHT` | 1080 | 默认图片高度（像素） |
| `NODE_PATH` | 自动检测 | Node模块路径（Puppeteer所在位置） |

### 启动命令示例

```bash
# 默认配置启动
php -S localhost:8080 -t server server/server.php

# 自定义并发数启动
MAX_CONCURRENCY=20 php -S localhost:8080 -t server server/server.php

# 完整配置启动
MAX_CONCURRENCY=15 DEFAULT_TIMEOUT=60 DEFAULT_WIDTH=1200 DEFAULT_HEIGHT=800 \
  php -S localhost:8080 -t server server/server.php
```

---

## 并发数配置建议

### 资源消耗基准

| 资源 | 消耗量 | 说明 |
|------|--------|------|
| Chrome基础进程 | 100-150 MB | 主进程固定开销 |
| 单个Tab | 250-380 MB | 每个页面渲染 |
| JS密集页面 | 400-600 MB | 复杂JavaScript执行 |
| PNG截图输出 | 1-5 MB | 根据图片尺寸变化 |

### 并发数计算公式

```
并发上限 = (可用内存 - 系统预留) / 单Tab内存消耗
推荐值 = 并发上限 × 85%（安全余量）
```

**计算示例**：

```
服务器内存: 8GB
系统预留: 2GB（操作系统+其他服务）
可用内存: 6GB
单Tab消耗: 350MB（取中间值）

并发上限 = (6GB - 2GB) / 350MB ≈ 11
推荐值 = 11 × 0.85 ≈ 10 → MAX_CONCURRENCY=10
```

### 不同服务器配置推荐

| 服务器内存 | MAX_CONCURRENCY | 预估内存占用 | 适用场景 |
|------------|-----------------|--------------|----------|
| 4GB | 5 | 1.75GB | 小流量、轻量页面 |
| 8GB | 10 | 3.5GB | 中流量、常规页面 |
| 16GB | 20 | 7GB | 高流量、多任务 |
| 32GB | 40 | 14GB | 大规模生产环境 |

### Docker资源配置

在`docker-compose.yml`中配置资源限制：

```yaml
services:
  browsershot-service:
    environment:
      - MAX_CONCURRENCY=15
    shm_size: '1gb'          # 必须配置，Chrome崩溃临界值
    deploy:
      resources:
        limits:
          memory: 4G         # 内存上限 = MAX_CONCURRENCY × 350MB × 1.5
        reservations:
          memory: 2G         # 内存预留 = MAX_CONCURRENCY × 350MB
```

**资源计算公式**：
```
内存上限 = MAX_CONCURRENCY × 350MB × 1.5（缓冲系数）
内存预留 = MAX_CONCURRENCY × 350MB
```

### Go应用配置建议

在Go Executor中配置并发数，需与PHP服务保持一致：

```yaml
# runner_html2image.yaml
executor_type: "html2image"
concurrency: 10            # 与 MAX_CONCURRENCY 一致
prefetch: 10               # MQ预取数量 = concurrency
timeout: 60                # 单任务超时
```

**Go配置原则**：
- `concurrency` ≤ `MAX_CONCURRENCY`（避免PHP服务过载）
- `prefetch` = `concurrency`（MQ消费与处理能力匹配）
- `timeout` ≥ `DEFAULT_TIMEOUT + 10`（预留网络传输时间）

---

## 性能优化建议

### 1. 减少页面复杂度

```json
{
  "options": {
    "width": 800,           // 小尺寸减少渲染开销
    "height": 600,
    "timeout": 20,          // 简单页面快速超时
    "format": "jpeg",       // JPEG比PNG体积小
    "quality": 70           // 降低质量进一步压缩
  }
}
```

### 2. 批量处理策略

```go
// 批量处理时分批调用，避免瞬间并发过高
func BatchProcess(htmls []string) {
    batchSize := 5
    for i := 0; i < len(htmls); i += batchSize {
        end := min(i+batchSize, len(htmls))
        for _, html := range htmls[i:end] {
            go ConvertHTMLToImage(html)  // 最多5个并发
        }
        time.Sleep(1 * time.Second)      // 批次间隔
    }
}
```

### 3. 超时分级配置

| 页面类型 | timeout建议 | 说明 |
|----------|-------------|------|
| 纯文本 | 10-15秒 | 最快渲染 |
| 简单布局 | 20-30秒 | 标准配置 |
| 富媒体 | 40-60秒 | 图片/视频加载 |
| 数据可视化 | 60-90秒 | 图表渲染 |
| 表单交互 | 90-120秒 | 动态内容 |

---

## 分辨率调整说明

### scaleFactor参数

| 值 | 输出尺寸 | 适用场景 |
|----|----------|----------|
| 1 | 原始尺寸（800×600→800×600） | 普通网页、快速生成 |
| 2 | 2倍尺寸（800×600→1600×1200） | 高清显示、文字清晰 |
| 3 | 3倍尺寸（800×600→2400×1800） | 打印输出、大屏展示 |

**原理**：Chromium的`deviceScaleFactor`模拟高DPI设备（如Retina屏幕），渲染时像素密度提升，文字和图形更清晰。

**示例对比**：

```bash
# 普通（scaleFactor=1）- 文字可能有锯齿
curl -X POST http://localhost:8080/screenshot \
  -H "Content-Type: application/json" \
  -d '{"html":"<p style=\"font-size:14px\">测试文字清晰度</p>","options":{"width":400,"height":200}}'
  
# 高清（scaleFactor=2）- 文字平滑清晰
curl -X POST http://localhost:8080/screenshot \
  -H "Content-Type: application/json" \
  -d '{"html":"<p style=\"font-size:14px\">测试文字清晰度</p>","options":{"width":400,"height":200,"scaleFactor":2}}'
```

**注意事项**：
- scaleFactor越大，生成时间越长（约增加30-50%）
- 图片体积增大（PNG约3倍，JPEG约1.5倍）
- 内存消耗增加，建议减少并发数

---

## 注意事项

1. **超时设置**: 复杂页面建议设置`timeout: 60`，默认30秒可能不足
2. **内存占用**: 每个Chrome tab约250-400MB内存，注意控制并发数
3. **HTML内联资源**: CSS/图片需使用内联样式或绝对URL
4. **中文支持**: 服务已安装中文字体，无需额外配置
5. **代理访问**: 本地测试时使用`--noproxy localhost`避免代理干扰
6. **共享内存**: Docker必须配置`shm_size: '1gb'`，否则Chrome崩溃
7. **Go并发匹配**: Go Executor的`concurrency`需≤`MAX_CONCURRENCY`
8. **资源监控**: 生产环境建议监控内存使用率，超过80%需降并发