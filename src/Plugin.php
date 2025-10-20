<?php

namespace almanac\craftmessaging;

use Craft;
use craft\base\Plugin as BasePlugin;
use yii\base\Event;
use craft\web\Application;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.2';

    public static function config(): array
    {
        return ['components' => []];
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->getUrlManager()->addRules([
            // Chat endpoints
            'messaging/chat/create' => 'messaging/chat/create',
            'messaging/chat/list' => 'messaging/chat/list',

            // Message endpoints
            'messaging/message/send-message' => 'messaging/message/send-message',
            'messaging/message/get-messages' => 'messaging/message/get-messages',
            'messaging/message/mark-as-read' => 'messaging/message/mark-as-read',
            'messaging/message/broadcast' => 'messaging/message/broadcast',

            // Test endpoint
            'messaging/test' => 'messaging/default/index',
        ], false);
    }
}
