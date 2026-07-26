<?php

namespace anvildev\beacon\jobs;

use anvildev\beacon\records\LinkRecord;
use craft\queue\BaseJob;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class LinkBrokenJob extends BaseJob
{
    private const TTR_SECONDS = 60;

    public int $linkId;
    public int $timeout = 10;

    /**
     * Rate-limit delay in milliseconds. Applied via usleep at the start of
     * execute() so consecutive audits are throttled regardless of how the
     * queue driver schedules jobs.
     *
     * Capped at roughly (TTR − timeout) to guarantee the job can still make
     * its HTTP call within the TTR window; otherwise the queue driver
     * reclaims the reservation mid-sleep and the link is either re-checked
     * or dropped.
     */
    public int $delayMs = 0;

    public function getTtr(): int
    {
        return self::TTR_SECONDS;
    }

    /**
     * @param int $attempt
     * @param \Throwable $error
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < 4;
    }

    /**
     * Classify a Guzzle exception into an HTTP status and error message.
     *
     * - ConnectException with "timeout" → status 0 (timeout)
     * - ConnectException otherwise → status 0 (connection error)
     * - RequestException with response → actual HTTP status code
     * - Other GuzzleException → status 0
     *
     * @return array{httpStatus: int, error: string}
     */
    public function classifyException(GuzzleException $e): array
    {
        if ($e instanceof ConnectException) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
                return ['httpStatus' => 0, 'error' => 'timeout: ' . $e->getMessage()];
            }
            return ['httpStatus' => 0, 'error' => 'connection error: ' . $e->getMessage()];
        }

        if ($e instanceof RequestException && $e->hasResponse()) {
            return ['httpStatus' => $e->getResponse()->getStatusCode(), 'error' => $e->getMessage()];
        }

        return ['httpStatus' => 0, 'error' => $e->getMessage()];
    }

    public function execute($queue): void
    {
        // Cap the sleep so the job still has room to finish the HTTP call
        // within its TTR window. Without this, a generous --delay (e.g.
        // 50 000 ms) burns the whole TTR before the request starts, and the
        // queue driver reclaims the reservation mid-sleep.
        $maxDelayMs = max(0, (self::TTR_SECONDS - $this->timeout - 1) * 1000);
        $sleepMs = min(max(0, $this->delayMs), $maxDelayMs);
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        $record = LinkRecord::findOne($this->linkId);
        if ($record === null || $record->targetUrl === null) {
            return;
        }

        if ($this->isBlockedHost($record->targetUrl)) {
            $record->httpStatus = 0;
            $record->httpCheckedAt = \craft\helpers\Db::prepareDateForDb(new \DateTime());
            $record->save(false);
            return;
        }

        $httpStatus = 0;
        $timeout = $this->timeout;
        try {
            $client = new Client(['timeout' => $timeout, 'allow_redirects' => true]);
            $response = $client->head($record->targetUrl, ['timeout' => $timeout, 'allow_redirects' => true]);
            $httpStatus = $response->getStatusCode();
            if ($httpStatus >= 400) {
                // Retry with GET — many servers 405 on HEAD
                $response = $client->get($record->targetUrl, ['timeout' => $timeout, 'allow_redirects' => true, 'stream' => true]);
                $httpStatus = $response->getStatusCode();
            }
        } catch (GuzzleException $e) {
            $classified = $this->classifyException($e);
            $httpStatus = $classified['httpStatus'];
        }

        $record->httpStatus = $httpStatus;
        $record->httpCheckedAt = date('Y-m-d H:i:s');
        $record->save();
    }

    private function isBlockedHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false || $host === '') {
            return true;
        }

        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            // Unresolvable (including IPv6-only names we couldn't look up):
            // block rather than let Guzzle reach a potentially internal host.
            return true;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
            // Block cloud metadata explicitly
            if ($ip === '169.254.169.254' || $ip === 'fd00:ec2::254') {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a host to all of its IPv4 and IPv6 addresses.
     *
     * IP literals (including bracketed IPv6 like "[::1]") are returned as-is.
     * DNS names are resolved over both A and AAAA records so that IPv6-only
     * hosts are not silently skipped by the SSRF guard in isBlockedHost().
     *
     * @return string[]
     */
    private function resolveHostIps(string $host): array
    {
        // parse_url() keeps IPv6 literals bracketed (e.g. "[::1]").
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $ipv4 = @gethostbynamel($host);
        if ($ipv4 !== false) {
            $ips = $ipv4;
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if ($records !== false) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    protected function defaultDescription(): string
    {
        return "Checking broken link {$this->linkId} for Beacon";
    }
}
