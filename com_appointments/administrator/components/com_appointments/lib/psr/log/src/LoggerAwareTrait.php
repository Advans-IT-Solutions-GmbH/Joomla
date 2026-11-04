<?php

namespace Advans\Component\Appointments\Vendor\Psr\Log;
\defined('_JEXEC') or die;

/**
 * Basic Implementation of LoggerAwareInterface.
 */
trait LoggerAwareTrait
{
    /**
     * The logger instance.
     */
    protected ?LoggerInterface $logger = null;
    /**
     * Sets a logger.
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
