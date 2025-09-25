<?php
namespace almanac\craftmessaging\controllers;

use craft\web\Controller;
use yii\web\Response;

class DefaultController extends Controller
{
    protected array|int|bool $allowAnonymous = true;
    public function actionIndex(): Response
    {
        return $this->asJson([
            'message' => 'Hello, World!',
        ]);
    }
}