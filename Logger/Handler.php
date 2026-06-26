<?php

namespace PayMaya\Payment\Logger;

use Magento\Framework\Filesystem\DriverInterface;

/**
 * Class Handler
 * Custom logger handler to generate daily Maya log files.
 */
class Handler extends \Magento\Framework\Logger\Handler\Base
{
    /**
     * @var string
     */
    protected $fileNamePrefix = '/var/log/maya-log';

    /**
     * Handler constructor.
     *
     * @param DriverInterface $filesystem
     */
    public function __construct(
        DriverInterface $filesystem
    ) {
        $this->filesystem = $filesystem;

        $fileName = $this->fileNamePrefix . '-' . date('Y-m-d') . '.log';

        parent::__construct(
            $filesystem,
            null,
            $fileName
        );
    }
}
