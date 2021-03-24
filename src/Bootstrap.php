<?php

declare(strict_types=1);

namespace omnilight\scheduling;

use yii\base\Application;
use yii\base\BootstrapInterface;

class Bootstrap implements BootstrapInterface
{
    /**
     * Bootstrap method to be called during application bootstrap stage.
     *
     * @param Application $app the application currently running
     */
    public function bootstrap($app)
    {
        if ($app instanceof \yii\console\Application) {
            if (!isset($app->controllerMap['schedule'])) {
                $app->controllerMap['schedule'] = 'omnilight\scheduling\ScheduleController';
            }
        }
    }
}
