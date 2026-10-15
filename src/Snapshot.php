<?php
declare(strict_types=1);
namespace FloCMS\Reactive;

use FloCMS\Core\HttpException;

final class Snapshot
{
    public function __construct(private readonly string $secret, private readonly int $ttl = 1800)
    {
        if (strlen($secret) < 32 || $ttl < 60 || $ttl > 86400) {
            throw new \LogicException('Reactive needs a signing key of at least 32 bytes and a TTL of 60–86400 seconds.');
        }
    }

    public function sign(array $data, string $sessionToken, ?int $now = null): string
    {
        $data['issued'] = $now ?? time();
        $data['session'] = hash('sha256', $sessionToken);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 262144) { throw new \LogicException('Component snapshot is too large.'); }
        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $payload . '.' . hash_hmac('sha256', $payload, $this->secret);
    }

    public function verify(string $token, string $sessionToken, ?int $now = null): array
    {
        if (strlen($token) > 350000 || substr_count($token, '.') !== 1) { throw new HttpException(400); }
        [$payload, $signature] = explode('.', $token, 2);
        if (!hash_equals(hash_hmac('sha256', $payload, $this->secret), $signature)) { throw new HttpException(400, 'Invalid component snapshot.'); }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json !== false && strlen($json) > 262144) { throw new HttpException(400); }
        try { $data = json_decode($json === false ? '' : $json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpException(400); }
        $now ??= time();
        if (!is_array($data) || !is_int($data['issued'] ?? null) || !is_string($data['session'] ?? null)) { throw new HttpException(400); }
        if (!hash_equals(hash('sha256', $sessionToken), $data['session'])) { throw new HttpException(419); }
        if ($data['issued'] > $now + 30 || $now - $data['issued'] > $this->ttl) { throw new HttpException(419, 'Component expired. Reload the page.'); }
        return $data;
    }
}
