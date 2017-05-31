<?php
namespace exface\Core\CommonLogic\Log\Handlers;

use exface\Core\CommonLogic\Log\Handlers\monolog\AbstractMonologHandler;
use exface\Core\CommonLogic\Log\Processors\IdProcessor;
use exface\Core\CommonLogic\Log\Processors\RequestIdProcessor;
use exface\Core\CommonLogic\Log\Processors\UserNameProcessor;
use exface\Core\Interfaces\iCanGenerateDebugWidgets;
use exface\Core\Interfaces\Log\LoggerInterface;
use FemtoPixel\Monolog\Handler\CsvHandler;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Logger;

class LogfileHandler extends AbstractMonologHandler implements FileHandlerInterface
{

    private $name;

    private $filename;

    private $workbench;

    private $level;

    private $bubble;

    private $filePermission;

    private $useLocking;

    /**
     *
     * @param string $name
     * @param string $filename
     * @param string $level
     *            The minimum logging level name at which this handler will be triggered (see LoggerInterface
     *            level values)
     * @param string $requestId
     * @param string $userName
     * @param Boolean $bubble
     *            Whether the messages that are handled can bubble up the stack or not
     * @param int|null $filePermission
     *            Optional file permissions (default (0644) are only for owner read/write)
     * @param Boolean $useLocking
     *            Try to lock log file before doing any writes
     *
     * @throws \Exception If a missing directory is not buildable
     * @throws \InvalidArgumentException If stream is not a resource or string
     */
    function __construct($name, $filename, $workbench, $level = LoggerInterface::DEBUG, $bubble = true, $filePermission = null, $useLocking = false)
    {
        $this->name = $name;
        $this->filename = $filename;
        $this->workbench = $workbench;
        $this->level = $level;
        $this->bubble = $bubble;
        $this->filePermission = $filePermission;
        $this->useLocking = $useLocking;
    }

    public function setFilename($filename)
    {
        $this->filename = $filename;
    }

    protected function createRealLogger()
    {
        $logger = new Logger($this->name);

        // create csv log handler and set formatter with customized date format
        $csvHandler = new CsvHandler($this->filename, $this->level, $this->bubble, $this->filePermission,
            $this->useLocking);
        $csvHandler->setFormatter(new NormalizerFormatter("Y-m-d H:i:s-v")); // with milliseconds

        $logger->pushHandler($csvHandler);
        $logger->pushProcessor(new IdProcessor());
        $logger->pushProcessor(new RequestIdProcessor($this->workbench));
        $logger->pushProcessor(new UsernameProcessor($this->workbench));

        return $logger;
    }

    public function handle($level, $message, array $context = array(), iCanGenerateDebugWidgets $sender = null){
        // Keeping the exception corrupted log files in some cases, so they could not be read any more.
        unset($context['exception']);

        parent::handle($level, $message, $context, $sender);
    }
}
