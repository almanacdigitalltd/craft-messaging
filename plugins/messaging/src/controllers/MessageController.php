<?php

namespace almanac\craftmessaging\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use yii\db\Query;
use yii\db\Exception;

class MessageController extends Controller
{
    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function actionSendMessage(int $chatId): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $senderId = $request->getBodyParam('sender_id');
        $content = $request->getBodyParam('content');
        $type = $request->getBodyParam('type', 'text');

        if (!$senderId || !$content) {
            return $this->asJson(['error' => 'sender_id and content are required'])->setStatusCode(400);
        }

        $chat = (new Query())->from('{{%chats}}')->where(['id' => $chatId])->one();
        if (!$chat) {
            return $this->asJson(['error' => 'Chat not found'])->setStatusCode(404);
        }

        $db = Craft::$app->getDb();
        $sentAt = (new \DateTime())->format('Y-m-d H:i:s');

        try {
            $db->createCommand()->insert('{{%messages}}', [
                'chatId' => $chatId,
                'senderId' => $senderId,
                'content' => $content,
                'type' => $type,
                'sentAt' => $sentAt
            ])->execute();

            $messageId = $db->getLastInsertID();

            // Get recipients
            $recipients = (new Query())
                ->select(['userId'])
                ->from('{{%chat_participants}}')
                ->where(['chatId' => $chatId])
                ->andWhere(['<>', 'userId', $senderId])
                ->column();

            $now = (new \DateTime())->format('Y-m-d H:i:s');

            // Insert a message_status row for each person
            foreach ($recipients as $recipientId) {
                $db->createCommand()->insert('{{%message_statuses}}', [
                    'messageId' => $messageId,
                    'userId' => $recipientId,
                    'status' => 'sent',
                    'updatedAt' => $now
                ])->execute();
            }

            return $this->asJson([
                'message_id' => $messageId,
                'sent_at' => $sentAt
            ]);
        } catch (Exception $e) {
            return $this->asErrorJson('Message sending failed');
        }
    }

    public function actionGetMessages(int $chatId): Response
    {
        if (!Craft::$app->getRequest()->getIsGet()) {
            return $this->asErrorJson('Only GET requests are allowed')->setStatusCode(405);
        }
        
        $userId = Craft::$app->getRequest()->getQueryParam('userId');

        if (!$userId) {
            return $this->asJson(['error' => 'Missing userId'])->setStatusCode(400);
        }

        $chat = (new Query())->from('{{%chats}}')->where(['id' => $chatId])->one();
        if (!$chat) {
            return $this->asJson(['error' => 'Chat not found'])->setStatusCode(404);
        }

        $messages = (new Query())
            ->from('{{%messages}}')
            ->where(['chatId' => $chatId])
            ->orderBy(['sentAt' => SORT_ASC])
            ->all();

        $data = [];

        foreach ($messages as $msg) {
            $status = null;

            if ($msg['senderId'] === $userId) {
                $statuses = (new Query())
                    ->from('{{%message_statuses}}')
                    ->where(['messageId' => $msg['id']])
                    ->all();

                $priority = ['sent' => 0, 'delivered' => 1, 'read' => 2];
                $status = 'sent';
                foreach ($statuses as $s) {
                    if ($priority[$s['status']] > $priority[$status]) {
                        $status = $s['status'];
                    }
                }
            } else {
                $recipientStatus = (new Query())
                    ->from('{{%message_statuses}}')
                    ->where([
                        'messageId' => $msg['id'],
                        'userId' => $userId
                    ])
                    ->one();

                if ($recipientStatus && $recipientStatus['status'] === 'sent') {
                    Craft::$app->getDb()->createCommand()->update('{{%message_statuses}}', [
                        'status' => 'delivered',
                        'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s')
                    ], ['id' => $recipientStatus['id']])->execute();
                }
            }

            $data[] = [
                'message_id' => $msg['id'],
                'sender_id' => $msg['senderId'],
                'type' => $msg['type'],
                'content' => $msg['content'],
                'sent_at' => (new \DateTime($msg['sentAt']))->format(DATE_ATOM),
                'status' => $status
            ];
        }

        return $this->asJson($data);
    }

    public function actionMarkAsRead(int $messageId): Response
    {
        $this->requirePostRequest();

        $messageId = Craft::$app->getRequest()->getQueryParam('messageId');
        $userId = Craft::$app->getRequest()->getQueryParam('userId');

        if (!$messageId || !$userId) {
            return $this->asJson(['error' => 'Missing parameters'])->setStatusCode(400);
        }

        $status = (new Query())
            ->from('{{%message_statuses}}')
            ->where(['messageId' => $messageId, 'userId' => $userId])
            ->one();

        if (!$status) {
            return $this->asJson(['error' => 'Status not found'])->setStatusCode(404);
        }

        if ($status['status'] !== 'read') {
            Craft::$app->getDb()->createCommand()->update('{{%message_statuses}}', [
                'status' => 'read',
                'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s')
            ], ['id' => $status['id']])->execute();
        }

        return $this->asJson([
            'success' => true,
            'message_id' => $messageId,
            'status' => 'read'
        ]);
    }
}
