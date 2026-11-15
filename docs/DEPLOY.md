# 部署指南

以下以 Debian / Ubuntu + Nginx + PHP-FPM + PostgreSQL 为例。示例中的 `2001:db8:...` 均为文档保留地址，**必须替换为你自己的网段**。

## 1. 系统依赖

```bash
sudo apt update
sudo apt install -y nginx postgresql php-fpm php-pgsql php-curl php-mbstring php-xml unzip
# 可选：sockets / bcmath 扩展通常随 php-common 提供
php -m | grep -E 'pdo_pgsql|curl|openssl|mbstring|sockets|bcmath'
```

要求 PHP 8.1+、PostgreSQL 14+。

## 2. 数据库

```bash
sudo -u postgres psql
CREATE ROLE relay LOGIN PASSWORD 'change-me';
CREATE DATABASE opencode_relay OWNER relay;
\q
```

```bash
cd /var/www/opencode-relay
cp .env.example .env
# 编辑 .env：APP_URL、APP_KEY、DB_*、CRON_SECRET
php bin/migrate.php
php bin/create-admin.php admin admin@example.com 'change-me-please'
chown -R www-data:www-data storage
```

## 3. IPv6 段接入

### 3.1 运营商把整段路由到本机

先确认段已经路由到服务器，而不是只在网卡上配了一个地址：

```bash
ip -6 route show
ip -6 addr show dev eth0
```

允许绑定非本机地址：

```bash
sudo sysctl -w net.ipv6.ip_nonlocal_bind=1
echo 'net.ipv6.ip_nonlocal_bind=1' | sudo tee /etc/sysctl.d/99-opencode-relay.conf
sudo sysctl --system
```

如果运营商把整个段路由到服务器（例如 /48 或 /56），把段加到 `lo` 的 local 路由：

```bash
sudo ip -6 route add local YOUR_PREFIX::/48 dev lo
# 持久化可用 systemd-networkd / netplan / rc.local，按发行版选择
```

### 3.2 /64 且上游在 on-link 网络

需要 NDP 代理，让上游把整个 /64 的邻居发现交给服务器。安装 `ndppd`：

```bash
sudo apt install -y ndppd
```

`/etc/ndppd.conf` 示例：

```conf
route-eth0 {
    iface eth0
    static {
        YOUR_PREFIX::/64
    }
}
```

```bash
sudo systemctl enable --now ndppd
```

> 不同运营商/网络环境的 NDP 配置不同。若 `bin/doctor.php --probe` 失败，先检查段是否真的路由到本机、NDP 是否工作、防火墙是否放行。

### 3.3 隧道方案（HE TunnelBroker 等）

```bash
# 示例：把 TUNNEL_ENDPOINT / LOCAL_IPV4 / TUNNEL_IPV6 换成服务商给的值
sudo ip tunnel add he-ipv6 mode sit remote TUNNEL_ENDPOINT local LOCAL_IPV4 ttl 255
sudo ip link set he-ipv6 up
sudo ip addr add TUNNEL_IPV6::2/64 dev he-ipv6
sudo ip -6 route add ::/0 dev he-ipv6
sudo sysctl -w net.ipv6.ip_nonlocal_bind=1
sudo ip -6 route add local TUNNEL_IPV6::/64 dev lo
```

隧道方案通常不需要 NDP；以服务商文档为准。

## 4. 添加地址池

后台 → **IPv6 池**，或：

```bash
php bin/import-pools.php <<'EOF'
# cidr,name,weight,ttl,interface,notes
2001:db8:1234::/48,provider-a,100,604800,eth0,primary routed /48
2001:db8:5678::/56,provider-b,50,604800,eth0,backup /56
EOF
```

前缀长度完全由 CIDR 决定；支持任意 `/1`–`/128`，代码不会假设 `/48` 或 `/56`。

## 5. 诊断

```bash
php bin/doctor.php
php bin/doctor.php --probe
```

`--probe` 会从每个池随机取一个地址，与 `ipv6.probe_url` 建立 TCP 连接。它不会调用模型，不消耗额度。

常见失败：

- `Failed binding local connection end`：`ip_nonlocal_bind` 未开，或段没有路由到本机。
- `Network is unreachable`：本机没有 IPv6 默认路由。
- 目标无 AAAA：`opencode.ai` 必须能通过 IPv6 访问；本项目不会回退 IPv4。
- `No enabled IPv6 pool`：后台添加池并启用。

## 6. Nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name relay.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name relay.example.com;

    ssl_certificate     /etc/letsencrypt/live/relay.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/relay.example.com/privkey.pem;

    root /var/www/opencode-relay/public;
    index index.php;

    client_max_body_size 32m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 900s;
        fastcgi_buffering off;   # SSE 必须关闭
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

`fastcgi_buffering off;` 对流式响应非常重要。也可以在应用层通过 `X-Accel-Buffering: no` 关闭，但建议双保险。

PHP-FPM 建议：

```ini
; /etc/php/8.3/fpm/php.ini
max_execution_time = 0
default_socket_timeout = 900
memory_limit = 256M
```

重载：

```bash
sudo systemctl reload php8.3-fpm nginx
```

## 7. 定时任务

```cron
0 * * * * www-data php /var/www/opencode-relay/bin/cron.php models >> /var/log/opencode-relay-cron.log 2>&1
30 3 * * * www-data php /var/www/opencode-relay/bin/cron.php cleanup >> /var/log/opencode-relay-cron.log 2>&1
```

或 systemd timer：

```ini
# /etc/systemd/system/opencode-relay-models.service
[Unit]
Description=OpenCode Relay hourly model sync
After=network-online.target postgresql.service

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/var/www/opencode-relay
ExecStart=/usr/bin/php /var/www/opencode-relay/bin/cron.php models
```

```ini
# /etc/systemd/system/opencode-relay-models.timer
[Unit]
Description=Hourly OpenCode Relay model sync

[Timer]
OnCalendar=hourly
RandomizedDelaySec=120
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now opencode-relay-models.timer
```

## 8. 首次检查清单

- [ ] `php bin/doctor.php` 全部关键项 PASS
- [ ] `php bin/doctor.php --probe` 每个池都能绑定
- [ ] 后台模型目录有免费模型，最近同步不超过 2 小时
- [ ] 创建普通用户与 Key，调用 `/v1/chat/completions` 成功
- [ ] 失败时状态页显示 `503 ipv6_*`，而不是偷偷走 IPv4
- [ ] 日志中每个请求的出口 IPv6 不同
- [ ] 备份 PostgreSQL 与 `.env`

## 9. 升级

```bash
cd /var/www/opencode-relay
git pull
php bin/migrate.php
php bin/cron.php models
```

迁移是幂等的；已有表不会被删除。升级前先备份数据库。
