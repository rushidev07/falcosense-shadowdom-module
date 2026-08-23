<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Service;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

class SyncLockManager
{
    private const LOCK_DIR     = 'SmartSearch';
    private const LOCK_FILE    = 'full_sync.lock';
    private const RESULT_FILE  = 'last_sync_result.json';
    private const MAX_LOCK_AGE = 14400; // 4 hours
    private const ADMIN_TTL    = 60;    // pre-launch "admin" lock expires after 60s if process never starts

    private Filesystem $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->ensureLockDir();
    }

    /**
     * Atomically acquire the lock. Returns false if already locked by another process.
     */
    public function acquire(string $source = 'unknown'): bool
    {
        $this->clearIfStale();

        $payload = json_encode([
            'pid'     => getmypid(),
            'source'  => $source,
            'started' => date('Y-m-d H:i:s'),
        ]);

        $fh = @fopen($this->getLockPath(), 'x');
        if ($fh === false) {
            return false;
        }

        fwrite($fh, $payload);
        fclose($fh);
        return true;
    }

    /**
     * Used by the background command: take over an existing 'admin' pre-lock,
     * or acquire fresh if nothing is there. Fails if locked by 'command'/'cron'.
     */
    public function acquireOrTakeOver(string $source): bool
    {
        $this->clearIfStale();

        $existing = $this->getLockInfo();

        if ($existing !== null) {
            if ($existing['source'] === 'admin') {
                // Pre-lock from the controller — take ownership
                @file_put_contents($this->getLockPath(), json_encode([
                    'pid'     => getmypid(),
                    'source'  => $source,
                    'started' => $existing['started'], // keep original start time
                ]));
                return true;
            }
            // Locked by cron or another command — do not steal
            return false;
        }

        return $this->acquire($source);
    }

    /**
     * True if a valid (non-stale) lock exists.
     */
    public function isLocked(): bool
    {
        if (!file_exists($this->getLockPath())) {
            return false;
        }

        $this->clearIfStale();

        return file_exists($this->getLockPath());
    }

    /**
     * Remove the lock file unconditionally.
     */
    public function release(): void
    {
        $path = $this->getLockPath();
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Return parsed lock info, or null if not locked.
     */
    public function getLockInfo(): ?array
    {
        $path = $this->getLockPath();
        if (!file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!$raw) {
            return ['pid' => null, 'source' => 'unknown', 'started' => 'unknown'];
        }

        return json_decode($raw, true) ?: ['pid' => null, 'source' => 'unknown', 'started' => 'unknown'];
    }

    /**
     * Write the outcome of a sync run. Called just before lock release.
     */
    public function writeResult(bool $success, string $message): void
    {
        @file_put_contents($this->getResultPath(), json_encode([
            'success' => $success,
            'message' => $message,
            'at'      => date('Y-m-d H:i:s'),
        ]));
    }

    /**
     * Read the last sync result, or null if never run.
     */
    public function getLastResult(): ?array
    {
        $path = $this->getResultPath();
        if (!file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        return $raw ? (json_decode($raw, true) ?: null) : null;
    }

    public function getLockPath(): string
    {
        return $this->filesystem
                    ->getDirectoryRead(DirectoryList::VAR_DIR)
                    ->getAbsolutePath(self::LOCK_DIR . '/' . self::LOCK_FILE);
    }

    // -------------------------------------------------------------------------

    private function getResultPath(): string
    {
        return $this->filesystem
                    ->getDirectoryRead(DirectoryList::VAR_DIR)
                    ->getAbsolutePath(self::LOCK_DIR . '/' . self::RESULT_FILE);
    }

    private function ensureLockDir(): void
    {
        $dir = $this->filesystem
                    ->getDirectoryRead(DirectoryList::VAR_DIR)
                    ->getAbsolutePath(self::LOCK_DIR);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function clearIfStale(): void
    {
        $path = $this->getLockPath();
        if (!file_exists($path)) {
            return;
        }

        $info    = $this->getLockInfo();
        $started = $info ? strtotime((string)($info['started'] ?? '')) : 0;

        // Admin pre-lock: short TTL — if the process never started, clear quickly
        if (($info['source'] ?? '') === 'admin') {
            if ($started && (time() - $started) > self::ADMIN_TTL) {
                @unlink($path);
            }
            return;
        }

        // Normal lock: max age check
        if ($started && (time() - $started) > self::MAX_LOCK_AGE) {
            @unlink($path);
            return;
        }

        // Dead PID check (Unix only)
        $pid = (int)($info['pid'] ?? 0);
        if ($pid > 0 && function_exists('posix_kill') && !posix_kill($pid, 0)) {
            @unlink($path);
        }
    }
}
