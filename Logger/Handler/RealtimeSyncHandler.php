<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Logger\Handler;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class RealtimeSyncHandler extends Base
{
    protected $loggerType = Logger::DEBUG;
    protected $fileName   = '/var/log/smartsearchluma-realtime.log';
}
