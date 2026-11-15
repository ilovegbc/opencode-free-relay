-- Safe seed data. No IP addresses, subnets, users or secrets are shipped.
-- Runtime settings live in the `settings` table only after an administrator
-- saves them; until then, env-backed defaults from config/config.php apply.

INSERT INTO plans (code, name, description, price_credits, daily_requests, daily_tokens, rpm, concurrency, is_default, sort_order)
VALUES
    ('free', 'Community', 'Default free tier. Open registration can be enabled or disabled by the operator.', 0, 200, 5000000, 30, 3, true, 0),
    ('supporter', 'Supporter', 'Higher limits for people who contribute to the relay.', 0, 1000, 20000000, 120, 8, false, 10),
    ('staff', 'Staff', 'Unlimited internal tier.', 0, -1, -1, 600, 32, false, 20)
ON CONFLICT (code) DO NOTHING;

INSERT INTO announcements (title, body, level, active)
SELECT '欢迎使用 OpenCode 免费模型中转',
       '这是一个自托管中转站。管理员需要先在「IPv6 池」添加自定义网段，然后运行每小时模型同步（php bin/cron.php models），再创建 API Key 即可调用。',
       'info',
       true
WHERE NOT EXISTS (SELECT 1 FROM announcements);
