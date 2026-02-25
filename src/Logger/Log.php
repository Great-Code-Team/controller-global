<?php

namespace Greatcode\ControllerGlobal\Logger;

/**
 * Class Log Facade
 * @package Greatcode\ControllerGlobal\Logger
 * @author Ifan Fairuz <ifanfairuz@gmail.com>
 */
class Log
{
    /**
     * @var \Greatcode\ControllerGlobal\Logger\Logger
     */
    private static $instance;

    /**
     * @param string $name
     * @param array $config
     * @return \Greatcode\ControllerGlobal\Logger\Logger
     */
    public static function initialize($name, $config)
    {
        self::$instance = new Logger($name, $config);
        return self::$instance;
    }

    /**
     * @return \Greatcode\ControllerGlobal\Logger\Logger
     */
    public static function getInstance()
    {
        if (empty(self::$instance)) {
            self::$instance = new Logger();
        }

        return self::$instance;
    }

    /**
     * Log debug
     * @param string $message
     * @return void
     */
    public static function debug($message)
    {
        self::getInstance()->debug($message);
    }

    /**
     * Log info
     * @param string $message
     * @return void
     */
    public static function info($message)
    {
        self::getInstance()->info($message);
    }

    /**
     * Log warning
     * @param string $message
     * @return void
     */
    public static function warning($message)
    {
        self::getInstance()->warning($message);
    }

    /**
     * Log error
     * @param string $message
     * @return void
     */
    public static function error($message)
    {
        self::getInstance()->error($message);
    }

    /**
     * Log fatal
     * @param string $message
     * @return void
     */
    public static function fatal($message)
    {
        self::getInstance()->fatal($message);
    }
}
