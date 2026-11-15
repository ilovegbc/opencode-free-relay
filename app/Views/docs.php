<?php
$base = rtrim((string) ($baseUrl ?? cfg('app.url', '')), '/');
$apiBase = $base . '/v1';
?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>API Reference</span>
        <h1 style="margin-top:.7rem">API 文档</h1>
        <p class="muted" style="margin:0">OpenAI Chat Completions 兼容；同时提供 Anthropic Messages 与 Responses 透传入口（取决于模型）。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/status')) ?>">运行状态</a>
</div>

<div class="split">
    <div>
        <div class="card" id="quickstart">
            <h2>快速开始</h2>
            <p class="muted">Base URL：<code><?= e($apiBase) ?></code>。把任意 OpenAI 兼容客户端的 Base URL 指到这里，并使用控制台创建的 <code>sk-oc-...</code> Key。</p>
            <div class="code-block">
                <button type="button" class="copy-button" data-copy="#example-curl">复制</button>
                <pre id="example-curl"><code>curl <?= e($apiBase) ?>/chat/completions \
  -H "Authorization: Bearer sk-oc-..." \
  -H "Content-Type: application/json" \
  -d '{
    "model": "big-pickle",
    "messages": [{"role": "user", "content": "你好"}],
    "stream": true
  }'</code></pre>
            </div>
            <h3 style="margin-top:1rem">Python（OpenAI SDK）</h3>
            <pre><code>from openai import OpenAI

client = OpenAI(
    base_url="<?= e($apiBase) ?>",
    api_key="sk-oc-...",
)

stream = client.chat.completions.create(
    model="big-pickle",
    messages=[{"role": "user", "content": "你好"}],
    stream=True,
)
for chunk in stream:
    print(chunk.choices[0].delta.content or "", end="")</code></pre>
            <h3>Node.js</h3>
            <pre><code>import OpenAI from "openai";

const client = new OpenAI({
  baseURL: "<?= e($apiBase) ?>",
  apiKey: "sk-oc-...",
});

const stream = await client.chat.completions.create({
  model: "big-pickle",
  messages: [{ role: "user", content: "你好" }],
  stream: true,
});
for await (const chunk of stream) {
  process.stdout.write(chunk.choices[0]?.delta?.content ?? "");
}</code></pre>
        </div>

        <div class="card" id="endpoints">
            <h2>端点</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>方法</th><th>路径</th><th>说明</th></tr></thead>
                    <tbody>
                        <tr><td><span class="badge green">POST</span></td><td><code>/v1/chat/completions</code></td><td>OpenAI Chat Completions（推荐）</td></tr>
                        <tr><td><span class="badge green">POST</span></td><td><code>/v1/messages</code></td><td>Anthropic Messages 透传（仅当模型声明 messages）</td></tr>
                        <tr><td><span class="badge green">POST</span></td><td><code>/v1/responses</code></td><td>OpenAI Responses 透传（仅当模型声明 responses）</td></tr>
                        <tr><td><span class="badge blue">GET</span></td><td><code>/v1/models</code></td><td>免费模型列表；公开可读，带 Key 时按套餐过滤</td></tr>
                        <tr><td><span class="badge blue">GET</span></td><td><code>/v1/models/{id}</code></td><td>单个模型信息</td></tr>
                        <tr><td><span class="badge blue">GET</span></td><td><code>/v1/me</code></td><td>当前 Key 的账户、额度与今日用量</td></tr>
                        <tr><td><span class="badge blue">GET</span></td><td><code>/health</code></td><td>数据库、模型、IPv6 池健康检查</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" id="models">
            <h2>模型</h2>
            <p class="muted">模型目录每小时从 OpenCode 刷新。只有 <code>cost=0</code> 或名称中带 Free 的 chat 模型会被暴露；<code>big-pickle</code> 这类零成本隐身模型也会被识别。</p>
            <?php if (empty($models)): ?>
                <p class="muted">当前没有已同步的免费模型。管理员可在后台执行“立即同步”。</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>模型 ID</th><th>名称</th><th>上下文</th><th>输出</th><th>上游</th></tr></thead>
                        <tbody>
                        <?php foreach ($models as $model): ?>
                            <tr>
                                <td><code><?= e($model['id']) ?></code></td>
                                <td><?= e($model['name']) ?></td>
                                <td class="small"><?= e($model['context_limit'] ? format_number((int) $model['context_limit']) : '—') ?></td>
                                <td class="small"><?= e($model['output_limit'] ? format_number((int) $model['output_limit']) : '—') ?></td>
                                <td><span class="badge blue"><?= e($model['upstream_kind']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card" id="headers">
            <h2>官方客户端一致性</h2>
            <p>中转默认复刻 OpenCode 2.3.0 客户端在模型请求上附加的身份头，并保持会话亲和度稳定：</p>
            <pre><code>Authorization: Bearer public
User-Agent: opencode/latest/2.3.0/cli
x-opencode-client: cli
x-opencode-session-id: ses_&lt;64 hex&gt;
x-session-affinity: ses_&lt;64 hex&gt;
X-Session-Id: ses_&lt;64 hex&gt;
x-opencode-session: ses_&lt;64 hex&gt;
x-opencode-project: prj_&lt;...&gt;
prompt_cache_key: &lt;64 hex&gt;   # JSON body</code></pre>
            <ul>
                <li>不会修改 <code>system</code>、<code>messages</code>、<code>tools</code>、<code>tool_choice</code> 或温度等参数。</li>
                <li>仅在上游允许时补充 <code>stream_options.include_usage</code>，便于计费与日志。</li>
                <li><code>prompt_cache_key</code> 优先使用客户端传入值；否则由 API Key + 首条用户消息 + system + 模型稳定派生。</li>
                <li>免费模型本身不需要真实 Key；<code>public</code> 是官方客户端的占位值，可在后台清空。</li>
            </ul>
        </div>

        <div class="card" id="ipv6">
            <h2>IPv6 策略</h2>
            <p>每个上游请求从后台配置的 IPv6 池中分配一个新地址。池的 CIDR 和前缀长度完全由数据库决定，代码不假设 /48、/56 或 /64。</p>
            <ul>
                <li>强制 <code>CURL_IPRESOLVE_V6</code>，禁止重定向，禁用环境代理。</li>
                <li>使用 <code>CURLOPT_INTERFACE</code>（或可选的 opensocket）绑定源地址。</li>
                <li>租约窗口内的地址不会再次分配；小池耗尽直接返回 <code>503 ipv6_pool_exhausted</code>。</li>
                <li>429/5xx/绑定失败会换一个新地址重试，次数由 <code>upstream.max_ip_retries</code> 控制。</li>
                <li>任何情况下都不会回退 IPv4。</li>
            </ul>
        </div>

        <div class="card" id="errors">
            <h2>错误格式</h2>
            <p>错误使用 OpenAI 兼容结构：</p>
            <pre><code>{
  "error": {
    "message": "Rate limit exceeded. Try again shortly.",
    "type": "invalid_request_error",
    "code": "rate_limit"
  }
}</code></pre>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>HTTP</th><th>code</th><th>含义</th></tr></thead>
                    <tbody>
                        <tr><td>401</td><td><code>invalid_api_key</code></td><td>Key 缺失、无效、吊销或过期</td></tr>
                        <tr><td>403</td><td><code>model_forbidden</code> / <code>model_not_free</code></td><td>套餐无权或非免费模型</td></tr>
                        <tr><td>422</td><td><code>validation_error</code></td><td>请求字段缺失或非法</td></tr>
                        <tr><td>429</td><td><code>rate_limit</code> / <code>daily_*_limit</code> / <code>free_usage_limit</code></td><td>限流、额度或上游免费额度耗尽</td></tr>
                        <tr><td>502</td><td><code>upstream_error</code></td><td>上游错误或所有 IPv6 尝试失败</td></tr>
                        <tr><td>503</td><td><code>ipv6_unavailable</code> / <code>ipv6_pool_exhausted</code></td><td>没有可用 IPv6 池或地址</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <aside>
        <div class="card docs-toc">
            <h3>目录</h3>
            <a href="#quickstart">快速开始</a>
            <a href="#endpoints">端点</a>
            <a href="#models">模型</a>
            <a href="#headers">客户端一致性</a>
            <a href="#ipv6">IPv6 策略</a>
            <a href="#errors">错误格式</a>
        </div>
        <div class="card">
            <h3>需要帮助？</h3>
            <p class="desc">先查看 <a href="<?= e(url('/status')) ?>">状态页</a> 与 <a href="<?= e(url('/admin/diagnostics')) ?>">诊断</a>（管理员）。自部署问题可运行 <code>php bin/doctor.php</code>。</p>
        </div>
    </aside>
</div>
