<?php

namespace BinktermPHP;

class SystemConfig
{
    public static function getSystemFidonetAddress()
    {
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            return $binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            // Fall back to a default or config value
            return Config::FIDONET_ORIGIN ?? '1:1/0';
        }
    }
    
    public static function getSystemSysop()
    {
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            return $binkpConfig->getSystemSysop();
        } catch (\Exception $e) {
            // Fall back to a default
            return Config::SYSOP_NAME ?? 'System Operator';
        }
    }
}