-- 思考等级（reasoning effort）档位：每次同步从上游 capabilities.reasoning 提取并覆盖。
ALTER TABLE model_catalog
    ADD COLUMN IF NOT EXISTS thinking jsonb NOT NULL DEFAULT '{}'::jsonb;

COMMENT ON COLUMN model_catalog.thinking IS '上游 capabilities.reasoning 归一化后的思考档位（supported/toggle/efforts/levels/default/budget）';
