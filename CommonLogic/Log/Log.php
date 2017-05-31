<?php
namespace exface\Core\CommonLogic\Log;

use exface\Core\CommonLogic\Filemanager;
use exface\Core\CommonLogic\Log\Handlers\DebugMessageFileHandler;
use exface\Core\CommonLogic\Log\Handlers\limit\FileLimitingLogHandler;
use exface\Core\CommonLogic\Log\Handlers\limit\DirLimitingLogHandler;
use exface\Core\CommonLogic\Log\Handlers\LogfileHandler;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\Model\MetaObjectNotFoundError;
use exface\Core\Interfaces\Log\LogHandlerInterface;
use Monolog\ErrorHandler;
use Psr\Log\LogLevel;

class Log
{

    /** @var Logger $logger */
    private static $logger = null;

    /** @var LogHandlerInterface[] $errorLogHandlers */
    private static $errorLogHandlers = null;

    /**
     * Creates a LoggerInterface implementation if none is there yet and returns it after adding default
     * LogHandlerInterface instances to it.
     *
     * @param Workbench $workbench
     *
     * @return LoggerInterface
     */
    public static function getErrorLogger($workbench)
    {
        if (! static::$logger) {
            static::$logger = new Logger();

            try {
                $handlers = static::getErrorLogHandlers($workbench);
                foreach ($handlers as $handler) {
                    static::$logger->pushHandler($handler);
                }
            } catch (\Throwable $t) {
                static::$logger->pushHandler(static::getFallbackHandler($workbench));
                static::$logger->critical('Log initialisation failed', array(
                    'exception',
                    $t
                ));
            }
        }

        return static::$logger;
    }

    /**
     *
     * @param Workbench $workbench
     *
     * @return string
     * @throws MetaObjectNotFoundError
     */
    private static function getCoreLogPath($workbench)
    {
        return $workbench->filemanager()->getPathToLogFolder() . '/' . $workbench->filemanager()->getCoreLogFilename();
    }

    /**
     *
     * @param Workbench $workbench
     *
     * @return string
     * @throws MetaObjectNotFoundError
     */
    private static function getDetailsLogPath($workbench)
    {
        return $workbench->filemanager()->getPathToLogDetailsFolder();
    }

    /**
     * Registers error logger at monolog ErrorHandler.
     *
     * @param
     *            $workbench
     */
    public static function registerErrorLogger($workbench)
    {
        ErrorHandler::register(static::getErrorLogger($workbench));
    }

    protected static function getErrorLogHandlers($workbench)
    {
        if (! static::$errorLogHandlers) {
            static::$errorLogHandlers = array();

            $coreLogFilePath = static::getCoreLogPath($workbench);
            $detailsLogBasePath = static::getDetailsLogPath($workbench);

            $minLogLevel = $workbench->getConfig()->getOption('LOG.MINIMUM_LEVEL_TO_LOG');
            $maxDaysToKeep = $workbench->getConfig()->getOption('LOG.MAX_DAYS_TO_KEEP');

            static::$errorLogHandlers["filelog"] = new FileLimitingLogHandler(new LogfileHandler("exface", "", $workbench, $minLogLevel), // real file name is determined late by FileLimitingLogHandler
$coreLogFilePath, '', $maxDaysToKeep);

            static::$errorLogHandlers["detaillog"] = new DirLimitingLogHandler(new DebugMessageFileHandler($detailsLogBasePath, ".json", $minLogLevel), $detailsLogBasePath, ".json", $maxDaysToKeep);
        }

        return static::$errorLogHandlers;
    }

    protected static function getFallbackHandler($workbench)
    {
        return new FileLimitingLogHandler(new LogfileHandler("exface", "", LogLevel::DEBUG), // real file name is determined late by FileLimitingLogHandler
Filemanager::pathNormalize($workbench->filemanager()->getPathToBaseFolder()) . '/logs/fallback.log', '-', 3);
    }
}
