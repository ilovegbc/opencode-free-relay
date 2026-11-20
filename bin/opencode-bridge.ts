#!/usr/bin/env bun
// 上游桥接服务：用 Bun 的 TLS/HTTP2 栈转发到 opencode.ai。
// 站点 PHP 侧（libcurl 7.29/NSS）无法通过上游的传输层准入，必须由本进程代发。
//
// 协议：POST /forward
//   请求体 JSON: { "url": string, "headers": {..}, "body": string }
//   响应：HTTP 状态等于上游状态，头 x-upstream-status/x-upstream-content-type，
//         正文为上游原始字节流（SSE 或 JSON）。

const PORT = Number(process.env.BRIDGE_PORT || 18130);
const HOST = process.env.BRIDGE_HOST || "127.0.0.1";
const TOKEN = process.env.BRIDGE_TOKEN || "";
const ALLOWED = new Set((process.env.BRIDGE_ALLOWED_HOSTS || "opencode.ai").split(",").map((h) => h.trim()));

const log = (...parts: unknown[]) => console.log(new Date().toISOString(), ...parts);

const server = Bun.serve({
  hostname: HOST,
  port: PORT,
  idleTimeout: 0,
  async fetch(req) {
    const url = new URL(req.url);
    if (url.pathname === "/health") {
      return new Response(JSON.stringify({ ok: true, upstreams: [...ALLOWED] }), {
        headers: { "content-type": "application/json" },
      });
    }
    if (url.pathname !== "/forward" || req.method !== "POST") {
      return new Response("not found", { status: 404 });
    }
    if (TOKEN !== "" && req.headers.get("x-bridge-token") !== TOKEN) {
      return new Response("forbidden", { status: 403 });
    }

    let payload: { url?: string; headers?: Record<string, string>; body?: string };
    try {
      payload = (await req.json()) as typeof payload;
    } catch {
      return new Response("bad json", { status: 400 });
    }
    const target = payload.url || "";
    let parsed: URL;
    try {
      parsed = new URL(target);
    } catch {
      return new Response("bad url", { status: 400 });
    }
    if (!ALLOWED.has(parsed.hostname)) {
      return new Response("host not allowed", { status: 400 });
    }

    const started = Date.now();
    let upstream: Response;
    try {
      upstream = await fetch(parsed.toString(), {
        method: "POST",
        headers: { accept: "*/*", "accept-encoding": "gzip, deflate, br, zstd", ...(payload.headers || {}) },
        body: payload.body ?? "",
      });
    } catch (err) {
      log("forward failed", parsed.pathname, String(err));
      return new Response(JSON.stringify({ error: String(err) }), {
        status: 502,
        headers: { "content-type": "application/json", "x-upstream-status": "0" },
      });
    }

    const headers = new Headers({
      "x-upstream-status": String(upstream.status),
      "x-upstream-content-type": upstream.headers.get("content-type") || "application/json",
      "content-type": upstream.headers.get("content-type") || "application/json",
      "cache-control": "no-cache",
    });
    log("forward", parsed.pathname, upstream.status, Date.now() - started, "ms");
    return new Response(upstream.body, { status: upstream.status, headers });
  },
});

log(`bridge listening on ${HOST}:${server.port}`);
