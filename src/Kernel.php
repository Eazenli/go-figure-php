<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * APP_CACHE_DIR, as Symfony reads APP_LOG_DIR: lets a test build a container of its own,
     * away from the one var/cache/ holds (tests/Mcp/StdioTest.php).
     */
    public function getCacheDir(): string
    {
        return isset($_SERVER['APP_CACHE_DIR']) ? $_SERVER['APP_CACHE_DIR'].'/'.$this->environment : parent::getCacheDir();
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
