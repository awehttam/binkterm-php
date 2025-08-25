<?php

namespace BinktermPHP;

class Config
{
    const DB_PATH = __DIR__ . '/../data/binktest.db';
    const TEMPLATE_PATH = __DIR__ . '/../templates';
    const BINKD_INBOUND = __DIR__ . '/../data/inbound';
    const BINKD_OUTBOUND = __DIR__ . '/../data/outbound';
    
    const SESSION_LIFETIME = 86400 * 30; // 30 days

    const FIDONET_ORIGIN = '1:1/0';
    const SYSTEM_NAME = 'BinktermPHP System';
    const SYSOP_NAME = 'System Operator';
}