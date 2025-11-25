<?php

namespace App\Services;

use Psr\Log\LoggerInterface;

class SessionManager
{
    private $logger;
    private $sessionFile;
    private $sessionData = [];
    private $isCliMode;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->isCliMode = (php_sapi_name() === 'cli');
        $this->sessionFile = __DIR__ . '/../../storage/sessions/cli_session.json';
        
        $this->initialize();
    }

    private function initialize()
    {
        if ($this->isCliMode) {
            if (file_exists($this->sessionFile)) {
                $content = file_get_contents($this->sessionFile);
                $this->sessionData = json_decode($content, true) ?? [];
            }
        } else {
            if (session_status() === PHP_SESSION_NONE) {
                // Use secure session settings if possible
                if (!headers_sent()) {
                    ini_set('session.cookie_httponly', 1);
                    ini_set('session.use_strict_mode', 1);
                }
                session_start();
            }
        }
    }

    public function get(string $key, $default = null)
    {
        $data = $this->read($key);
        
        if ($data === null) {
            return $default;
        }

        if (isset($data['expires_at']) && $data['expires_at'] > 0 && time() > $data['expires_at']) {
            $this->delete($key);
            return $default;
        }

        return $data['value'];
    }

    public function set(string $key, $value, int $ttl = 0)
    {
        $data = [
            'value' => $value,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0
        ];

        $this->write($key, $data);
    }

    public function delete(string $key)
    {
        if ($this->isCliMode) {
            if (isset($this->sessionData[$key])) {
                unset($this->sessionData[$key]);
                $this->saveToFile();
            }
        } else {
            if (isset($_SESSION[$key])) {
                unset($_SESSION[$key]);
            }
        }
    }

    public function has(string $key): bool
    {
        $data = $this->read($key);
        if ($data === null) {
            return false;
        }
        
        if (isset($data['expires_at']) && $data['expires_at'] > 0 && time() > $data['expires_at']) {
            $this->delete($key);
            return false;
        }
        
        return true;
    }

    private function read(string $key)
    {
        if ($this->isCliMode) {
            return $this->sessionData[$key] ?? null;
        } else {
            return $_SESSION[$key] ?? null;
        }
    }

    private function write(string $key, array $data)
    {
        if ($this->isCliMode) {
            $this->sessionData[$key] = $data;
            $this->saveToFile();
        } else {
            $_SESSION[$key] = $data;
        }
    }

    private function saveToFile()
    {
        $dir = dirname($this->sessionFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->sessionFile, json_encode($this->sessionData, JSON_PRETTY_PRINT));
    }
}
