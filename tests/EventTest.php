<?php

namespace omnilight\scheduling\Tests;

use omnilight\scheduling\Event;
use PHPUnit\Framework\TestCase;
use yii\mutex\Mutex;

class EventTest extends TestCase
{
    public function buildCommandData()
    {
        return [
            [false, 'php -i', '/dev/null', 'php -i > /dev/null'],
            [false, 'php -i', '/my folder/foo.log', 'php -i > /my folder/foo.log'],
            [true, 'php -i', '/dev/null', 'php -i > /dev/null 2>&1 &'],
            [true, 'php -i', '/my folder/foo.log', 'php -i > /my folder/foo.log 2>&1 &'],
        ];
    }

    /**
     * @dataProvider buildCommandData
     * @param bool $omitErrors
     * @param string $command
     * @param string $outputTo
     * @param string $result
     */
    public function testBuildCommandSendOutputTo(bool $omitErrors, string $command, string $outputTo, string $result)
    {
        /** @var Mutex $mutex */
        $mutex = $this->getMockBuilder(Mutex::class)->getMock();
        $event = new Event($mutex, $command);
        $event->omitErrors($omitErrors);
        $event->sendOutputTo($outputTo);
        $this->assertSame($result, $event->buildCommand());
    }
}
