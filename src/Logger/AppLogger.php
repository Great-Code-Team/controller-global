<?php

namespace Greatcode\ControllerGlobal\Logger;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;

/**
 * Class Logger
 * @package Greatcode\ControllerGlobal\Logger
 * @author Ifan Fairuz <ifanfairuz@gmail.com>
 */
class Logger
{
    /**
     * @var \Monolog\Logger
     */
    private $logger;

    /**
     * @var \Monolog\Formatter\FormatterInterface
     */
    private $formatter;

    /**
     * @return \Monolog\Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Logger constructor.
     * 
     * @param string $name
     * @param array{
     *     format: 'json'|'line'|null,
     *     handler: array{
     *         type: 'console'|'file',
     *         level: 'debug'|'info'|'warning'|'error'|'fatal',
     *         path: string|null,
     *     },
     * } $config
     */
    public function __construct(string $name = "app", array $config = [])
    {
        $this->logger = new MonologLogger($name);
        $this->formatter = $this->getFormatterFromConfig($config);
        $this->setupHandler($config['handler'] ?? [['type' => 'console', 'level' => 'info']]);
    }

    /**
     * Get formatter
     * @param array $config
     * @return \Monolog\Formatter\FormatterInterface
     */
    protected function getFormatterFromConfig($config)
    {
        switch ($config['format']) {
            case 'json':
                return new JsonFormatter();
            case 'line':
                return new LineFormatter();
            default:
                return new JsonFormatter();
        }
    }

    /**
     * setup handler
     * @param array{
     *     type: 'console'|'file',
     *     level: 'debug'|'info'|'warning'|'error'|'fatal',
     *     path: string|null,
     * } $handlers
     * @return void
     */
    public function setupHandler($handlers)
    {
        foreach ($handlers as $handler) {
            $type = $handler['type'];
            switch ($handler['level']) {
                case 'debug':
                    $level = Level::Debug;
                    break;
                case 'info':
                    $level = Level::Info;
                    break;
                case 'warning':
                    $level = Level::Warning;
                    break;
                case 'error':
                    $level = Level::Error;
                    break;
                case 'fatal':
                    $level = Level::Emergency;
                    break;
                default:
                    $level = Level::Info;
                    break;
            }

            switch ($type) {
                case 'console':
                    $handler = new StreamHandler('php://stdout', $level);
                    break;
                case 'file':
                    if (empty($handler['path'])) {
                        throw new \Exception("Handler type is file but path is empty");
                    }
                    $handler = new StreamHandler($handler['path'], $level);
                    break;
                default:
                    throw new \Exception("Invalid handler type: $type");
                    break;
            }

            $handler->setFormatter($this->formatter);
            $this->logger->pushHandler($handler);
        }
    }

    /**
     * Log debug
     * @param string $message
     * @return void
     */
    public function debug($message)
    {
        $this->logger->debug($message);
    }

    /**
     * Log info
     * @param string $message
     * @return void
     */
    public function info($message)
    {
        $this->logger->info($message);
    }

    /**
     * Log warning
     * @param string $message
     * @return void
     */
    public function warning($message)
    {
        $this->logger->warning($message);
    }

    /**
     * Log error
     * @param string $message
     * @return void
     */
    public function error($message)
    {
        $this->logger->error($message);
    }

    /**
     * Log fatal
     * @param string $message
     * @return void
     */
    public function fatal($message)
    {
        $this->logger->emergency($message);
    }
}
