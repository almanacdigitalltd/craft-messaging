<?php

namespace almanac\craftmessaging;

use Craft;
use craft\base\Plugin as BasePlugin;
use yii\base\Event;
use craft\web\Application;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return ['components' => []];
    }

    public function init(): void
    {
        parent::init();

        // CORS for local dev
        Event::on(
            Application::class,
            Application::EVENT_BEFORE_REQUEST,
            function () {
                $request = Craft::$app->getRequest();
                $origin = $request->getOrigin();
                $allowedOrigin = 'http://localhost:5173';

                if ($origin === $allowedOrigin) {
                    header('Access-Control-Allow-Origin: ' . $origin);
                    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
                    header('Access-Control-Allow-Headers: Content-Type');
                    header('Access-Control-Allow-Credentials: true');
                }

                if ($request->getMethod() === 'OPTIONS') {
                    Craft::$app->end();
                }
            }
        );

        Craft::$app->getUrlManager()->addRules([
            // Chat endpoints
            'messaging/chat/create' => 'messaging/chat/create',
            'messaging/chat/list' => 'messaging/chat/list',

            // Message endpoints
            'messaging/message/send-message' => 'messaging/message/send-message',
            'messaging/message/get-messages' => 'messaging/message/get-messages',
            'messaging/message/mark-as-read' => 'messaging/message/mark-as-read',

            // Test endpoint
            'messaging/test' => 'messaging/default/index',
        ], false);
    }
}
