<?php
$base = rtrim((string) ($baseUrl ?? cfg('app.url', '')), '/');
$apiBase = $base . '/v1';
?>
<div class="section-title">
    <div>
        <span class="eyebrow"><span class="dot"></span>API Reference</span>
        <h1 style="margin-top:.7rem">API 文档</h1>
        <p class="muted" style="margin:0">兼容 OpenAI Chat Completions；同时提供 Anthropic Messages 与 Responses 入口（取决于模型）。</p>
    </div>
    <a class="button ghost small" href="<?= e(url('/dashboard')) ?>">控制台</a>
</div>

<div class="split">
    <div>
        <div class="card" id="quickstart">
            <h2>快速开始</h2>
            <p class="muted">Base URL：<code><?= e($apiBase) ?></code>。把任意 OpenAI 兼容客户端的 Base URL 指到这里，并使用控制台创建的 <code>sk-oc-...</code> Key。</p>
            <p class="muted">流式与非流式都支持：chat 请求在服务端统一以流式发往上游，非流式调用会被重组为标准 <code>chat.completion</code> JSON。转发前会按上游免费通道要求塑形 <code>system</code> 与 <code>tools</code>，调用方消息本身不改。</p>
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
                        <tr><td><span class="badge green">POST</span></td><td><code>/v1/messages</code></td><td>Anthropic Messages（仅当模型支持）</td></tr>
                        <tr><td><span class="badge green">POST</span></td><td><code>/v1/responses</code></td><td>OpenAI Responses（仅当模型支持）</td></tr>
                        <tr><td><span class="badge blue">GET</span></td><td><code>/v1/models</code></td><td>免费模型列表</td></tr>
                        <tr><td><span class="badge blue">GET</span></td><td><code>/v1/models/{id}</code></td><td>单个模型信息</td></tr>
                        <tr><td><span class="badge blue">GET</span></td><td><code>/v1/me</code></td><td>当前 Key 的账户、额度与今日用量</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" id="models">
            <h2>模型</h2>
            <?php if (empty($models)): ?>
                <p class="muted">当前没有可用模型，请稍后再试。</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>模型 ID</th><th>名称</th><th>思考档位</th><th>上下文</th><th>输出</th></tr></thead>
                        <tbody>
                        <?php foreach ($models as $model): ?>
                            <?php $thinking = \Relay\Services\Thinking::forModel($model); ?>
                            <tr>
                                <td><code><?= e($model['id']) ?></code></td>
                                <td><?= e($model['name']) ?></td>
                                <td class="small"><?= e($thinking['levels'] !== [] ? implode(' / ', $thinking['levels']) : '—') ?></td>
                                <td class="small"><?= e($model['context_limit'] ? format_number((int) $model['context_limit']) : '—') ?></td>
                                <td class="small"><?= e($model['output_limit'] ? format_number((int) $model['output_limit']) : '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card" id="thinking">
            <h2>思考等级</h2>
            <p class="muted">模型目录里的 <code>thinking.levels</code> 是从上游 <code>capabilities.reasoning</code> 同步的可用档位
                （<code>toggle</code> 为 true 时额外提供 <code>none</code>）。选择方式任选其一：</p>
            <pre><code>{
  "model": "deepseek-v4.1-flash-free",
  "messages": [{"role": "user", "content": "你好"}],
  "reasoning_effort": "high"
}</code></pre>
            <p class="muted">也接受 <code>reasoning.effort</code>、<code>thinking.effort</code>、<code>thinking.budget_tokens</code>，
                或者直接把档位写进模型 ID：<code>deepseek-v4.1-flash-free:high</code>（也支持 <code>#high</code>）。</p>
            <p class="muted">中转会按模型的合法档位收敛：不在列表里的值取阶梯上最近的档位，<code>auto</code>/<code>default</code> 表示跟随上游默认。
                客户端完全没有指定档位时，默认按该模型的最高档发送（模型没有声明档位列表时交给上游默认）。</p>
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
                    <thead><tr><th>HTTP</th><th>含义</th></tr></thead>
                    <tbody>
                        <tr><td>401</td><td>API Key 缺失、无效或已过期</td></tr>
                        <tr><td>403</td><td>当前套餐无权使用该模型</td></tr>
                        <tr><td>404</td><td>模型不存在，或已被上游下架</td></tr>
                        <tr><td>422</td><td>请求字段缺失或非法</td></tr>
                        <tr><td>429</td><td>请求过于频繁，或今日额度已用尽</td></tr>
                        <tr><td>502 / 503</td><td>服务暂时不可用，请稍后重试</td></tr>
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
            <a href="#thinking">思考等级</a>
            <a href="#errors">错误格式</a>
        </div>
        <div class="card">
            <h3>需要帮助？</h3>
            <p class="desc">API Key、额度与套餐都可在 <a href="<?= e(url('/dashboard')) ?>">控制台</a> 自助管理；其他问题请联系管理员。</p>
        </div>
    </aside>
</div>
