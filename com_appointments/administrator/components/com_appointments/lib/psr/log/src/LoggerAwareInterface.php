<?php

namespace Advans\Component\Appointments\Vendor\Psr\Log;
\defined('_JEXEC') or die;

/**
 * Describes a logger-aware instance.
 */
interface LoggerAwareInterface
{
    /**
     * Sets a logger instance on the object.
     */
    public function setLogger(LoggerInterface $logger): void;
}
