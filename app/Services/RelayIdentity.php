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
        $headers = [
            'x-opencode-session-id' => $this->sessionId,
            'x-session-affinity' => $this->affinity,
            'X-Session-Id' => $this->affinity,
            'x-opencode-project' => $this->projectId,
            'x-opencode-session' => $this->affinity,
            'x-opencode-client' => $this->clientName,
            'User-Agent' => $this->userAgent,
        ];
        if ($this->parentSessionId !== null && $this->parentSessionId !== '') {
            $headers['x-opencode-parent-session-id'] = $this->parentSessionId;
            $headers['x-parent-session-id'] = $this->parentSessionId;
        }
        return $headers;
    }
}
