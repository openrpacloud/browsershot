# Browsershot Service 待办事项

## 状态说明

- ❌ 未完成
- ⏳ 进行中
- ✅ 已完成

---

## ❌ 1. Docker镜像构建（阻塞项）

**问题**：Docker镜像源不可用，无法拉取基础镜像。

**错误信息**：
```
failed to resolve source metadata for docker.io/library/node:22-alpine
EOF from registry.docker-cn.com
```

**解决方案**：

修改 `~/.docker/daemon.json`，替换为可用镜像源：

```json
{
  "registry-mirrors": [
    "https://mirror.gcr.io",
    "https://docker.m.daocloud.io"
  ]
}
```

**操作步骤**：
1. 编辑 `vim ~/.docker/daemon.json`
2. 重启 Docker Desktop
3. 执行构建：
   ```bash
   cd /Users/mac/go/src/github.com/openrpacloud/browsershot
   docker-compose build
   docker-compose up -d
   curl http://localhost:8080/health
   ```

**验证标准**：health接口返回 `{"status":"ok"}`

---

## ❌ 2. Go Executor集成（核心待办）

**目标**：在Go应用中创建Executor，调用Browsershot服务。

**目标路径**：
```
/Users/mac/go/src/github.com/openrpacloud/apigine/apps/apiexecutor/executors/executor/ecbrowsershot/
├── html2image.go
└── html2image_test.go
```

**实现要点**：

1. 参考 `ecexample/example.go` 创建 `html2image.go`
2. 关键配置：
   - API地址：`http://browsershot-server:8080/screenshot`
   -Concurrency ≤ MAX_CONCURRENCY（服务端配置）
   - Timeout ≥ DEFAULT_TIMEOUT + 10秒

**代码结构**：

```go
package ecbrowsershot

type Executor struct {
    endpoint string
    timeout  int
}

func (e *Executor) Execute(input map[string]interface{}) (map[string]interface{}, error) {
    html := input["html"].(string)
    opts := input["options"].(map[string]interface{})
    
    // 调用 http://browsershot-server:8080/screenshot
    // 返回 base64 图片数据
}
```

**YAML配置**：

在 `/Users/mac/go/src/github.com/openrpacloud/apigine/apps/apiexecutor/` 添加：

```yaml
# runner_html2image.yaml
executor_type: "html2image"
concurrency: 10
prefetch: 10
timeout: 60
endpoint: "http://browsershot-server:8080"
```

---

## ❌ 3. 生产环境配置

### 3.1 并发数调整

根据服务器内存调整 `MAX_CONCURRENCY`：

```bash
# 修改 docker-compose.yml
environment:
  - MAX_CONCURRENCY=15  # 8GB服务器推荐10，16GB推荐20
```

### 3.2 资源限制验证

确保 `docker-compose.yml` 配置：

```yaml
shm_size: '1gb'          # 必须配置
deploy:
  resources:
    limits:
      memory: 4G
```

### 3.3 日志配置

当前日志写入 `/tmp/php-server.log`，生产环境需配置：

```yaml
volumes:
  - ./logs:/var/log/browsershot
```

修改 `server/server.php` 输出日志到文件：

```php
ini_set('error_log', '/var/log/browsershot/error.log');
```

### 3.4 监控告警

添加监控项（可选）：

- 内存使用率 > 80% 告警
- active_requests 接近 MAX_CONCURRENCY 告警
- ``/health` 接口定期检查（每30秒）

---

## ⏳ 4. 可选优化（优先级低）

### 4.1 单元测试

添加 PHPUnit 测试：

```bash
cd /Users/mac/go/src/github.com/openrpacloud/browsershot/server
composer require --dev phpunit/phpunit
```

测试项：
- 并发控制逻辑
- 错误处理（超时、参数缺失）
- shutdown机制

### 4.2 Prometheus指标

添加 `/metrics` 端点：

```json
{
  "active_requests": 2,
  "max_concurrency": 10,
  "queue_length": 3,
  "total_requests": 100,
  "failed_requests": 5
}
```

供 Prometheus 抓取：

```
# HELP browsershot_active_requests 当前活跃请求数
# TYPE browsershot_active_requests gauge
browsershot_active_requests 2
```

### 4.3 中文字体优化

Alpine默认字体有限，如需更好中文渲染：

```dockerfile
RUN apk add --no-cache \
    wqy-zenbi  # 文泉驿字体
```

---

## ✅ 已完成项（无需操作）

| 项目| 文件 | 说明 |
|------|------|------|
| PHP HTTP服务 | `server/server.php` | 并发控制、请求队列、优雅关闭 |
| 本地验证 | - | health/status/screenshot 全部通过|
| API文档 | `API-DOC.md` | 接口说明、参数、Go示例、并发配置建议 |
| Dockerfile | `Dockerfile` | 单阶段Alpine构建（待镜像源修复） |
| docker-compose | `docker-compose.yml` | 容器编排、资源配置 |
| 高清截图 | `server.php` | scaleFactor参数支持 |
| 完整页面| `server.php` | fullPage参数支持 |
| 自动检测NODE_PATH | `server.php` | macOS NVM/Homebrew路径自动检测 |

---

## 操作优先级

```
高优先级：1. Docker镜像构建 → 2. Go Executor集成
中优先级：3. 生产环境配置（并发数、日志）
低优先级：4. 可选优化（测试、监控、字体）
```

---

## 快速检查清单

部署前检查：

- [ ] Docker镜像源已修复并构建成功
- [ ] `docker-compose up -d` 启动无错误
- [ ] `curl http://localhost:8080/health` 返回ok
- [ ] Go Executor 已创建并测试通过
- [ ] MAX_CONCURRENCY 与 Go Concurrency 匹配
- [ ] shm_size 配置为1gb或更大