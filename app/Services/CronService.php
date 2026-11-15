<?php

declare(strict_types=1);

namespace Relay\Services;

use Relay\Repositories\IpPoolRepository;
use Relay\Repositories\LogRepository;
use Relay\Repositories\ModelRepository;
use Relay\Support\Database;
use Relay\Support\Logger;
use Relay\Support\Settings;

final class CronService
{
    public function __construct(
        private Database $db,
        private Settings $settings,
    ) {
    }

    /** @return array<string,mixed> */
    public function run(string $task = 'all'): array
    {
        if (!$this->db->advisoryLock('relay:cron')) {
            return ['status' => 'busy', 'message' => 'Another cron run is already active.'];
        }
        $result = [];
        try {
            if (in_array($task, ['models', 'all'], true)) {
                $result['models'] = (new ModelSyncService($this->db, $this->settings, new ModelRepository($this->db)))
                    ->syncIfDue($this->settings->int('models.sync_interval', 3600));
            }
            if (in_array($task, ['cleanup', 'all'], true)) {
                $result['cleanup'] = (new LogRepository($this->db))->cleanup(
                    max(1, $this->settings->int('logging.log_retention_days', 30)),
                    max(0, $this->settings->int('logging.lease_retention_days', 7))
                );
                $result['cleanup']['expired_pool_leases'] = (new IpPoolRepository($this->db))->deleteExpiredLeases(10000);
            }
            Logger::info('Cron run completed', ['task' => $task, 'result' => $result]);
            return ['status' => 'ok', 'task' => $task, 'result' => $result, 'time' => gmdate('c')];
        } catch (\Throwable $e) {
            Logger::error('Cron run failed', ['task' => $task, 'message' => $e->getMessage()]);
            return ['status' => 'error', 'task' => $task, 'message' => $e->getMessage()];
        } finally {
            $this->db->advisoryUnlock('relay:cron');
        }
    }
}
