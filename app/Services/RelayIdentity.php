<?php

declare(strict_types=1);

namespace Relay\Services;

final class RelayIdentity
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $affinity,
        public readonly string $promptCacheKey,
        public readonly string $projectId,
        public readonly ?string $parentSessionId,
        public readonly string $clientName,
        public readonly string $userAgent,
    ) {
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        // 只发送官方 1.19.6 CLI 实际发出的头；多余头在免费通道准入里没有收益。
        $headers = [
            'x-opencode-session-id' => $this->sessionId,
            'x-opencode-project' => $this->projectId,
            'x-opencode-session' => $this->affinity,
            'x-opencode-client' => $this->clientName,
            'User-Agent' => $this->userAgent,
        ];
        if ($this->parentSessionId !== null && $this->parentSessionId !== '') {
            $headers['x-opencode-parent-session-id'] = $this->parentSessionId;
        }
        return $headers;
    }
}
