<?php

namespace almanac\craftmessaging\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use yii\db\Query;
use yii\db\Exception;

class ChatController extends Controller
{
    public array|int|bool $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $user1 = $request->getBodyParam('user1');
        $user2 = $request->getBodyParam('user2');

        if (!$user1 || !$user2 || $user1 === $user2) {
            return $this->asJson(['error' => 'Invalid users'])->setStatusCode(400);
        }

        // Check if a one-on-one chat already exists
        $existingChat = (new Query())
            ->select(['c.id'])
            ->from('{{%chats}} c')
            ->innerJoin('{{%chat_participants}} p1', 'p1.chatId = c.id')
            ->innerJoin('{{%chat_participants}} p2', 'p2.chatId = c.id')
            ->where([
                'or',
                ['p1.userId' => $user1, 'p2.userId' => $user2],
                ['p1.userId' => $user2, 'p2.userId' => $user1]
            ])
            ->one();

        if ($existingChat) {
            $chatId = $existingChat['id'];
            return $this->asJson([
                'chat_id' => $chatId,
                'participants' => [$user1, $user2]
            ]);
        }

        // Create new chat
        $db = Craft::$app->getDb();

        $transaction = $db->beginTransaction();
        try {
            $now = (new \DateTime())->format('Y-m-d H:i:s');

            $db->createCommand()->insert('{{%chats}}', [
                'createdAt' => $now,
            ])->execute();

            $chatId = $db->getLastInsertID();

            $db->createCommand()->batchInsert(
                '{{%chat_participants}}',
                ['chatId', 'userId', 'joinedAt'],
                [
                    [$chatId, $user1, $now],
                    [$chatId, $user2, $now]
                ]
            )->execute();

            $transaction->commit();

            return $this->asJson([
                'chat_id' => $chatId,
                'participants' => [$user1, $user2]
            ]);
        } catch (Exception $e) {
            $transaction->rollBack();
            return $this->asErrorJson('Failed to create chat');
        }
    }

    public function actionList(): Response
    {
        if (!Craft::$app->getRequest()->getIsGet()) {
            return $this->asErrorJson('Only GET requests are allowed')->setStatusCode(405);
        }

        $userId = Craft::$app->getRequest()->getQueryParam('user_id');

        if (!$userId) {
            return $this->asJson(['error' => 'Missing user_id'])->setStatusCode(400);
        }

        $uid = (int) $userId;

        $rows = (new Query())
            ->select([
                'c.id AS chat_id',
                'c.createdAt',
                'p2.userId AS participant',

                'MAX(m.id) AS latestMessageId',
                'MAX(m.sentAt) AS latestSentAt',

                new \yii\db\Expression("MAX(CASE WHEN m.senderId <> :uid THEN m.id ELSE 0 END) AS latestInboundId"),

                'cp.lastReadMessageId',
            ])
            ->from('{{%chat_participants}} p')
            ->innerJoin('{{%chats}} c', 'p.chatId = c.id')
            ->innerJoin('{{%chat_participants}} p2', 'p2.chatId = c.id')
            ->leftJoin('{{%messages}} m', 'm.chatId = c.id')
            ->leftJoin('{{%chat_participants}} cp', 'cp.chatId = c.id AND cp.userId = :uid')
            ->where(['p.userId' => $uid])
            ->groupBy(['c.id', 'c.createdAt', 'p2.userId', 'cp.lastReadMessageId'])
            ->params([':uid' => $uid])
            ->all();

        $grouped = [];
        foreach ($rows as $row) {
            $chatId = (int) $row['chat_id'];
            $g = $grouped[$chatId] ?? [
                'chat_id' => $chatId,
                'created_at' => $row['createdAt'],
                'participants' => [],
                'latest' => null,
                'hasUnread' => false,
            ];
            $g['participants'][] = (int) $row['participant'];

            $latestId = (int) ($row['latestMessageId'] ?? 0);
            $latestSentAt = $row['latestSentAt'] ?? null;
            $latestInboundId = (int) ($row['latestInboundId'] ?? 0);
            $lastRead = (int) ($row['lastReadMessageId'] ?? 0);

            if ($latestId && !$g['latest']) {
                $g['latest'] = [
                    'message_id' => $latestId,
                    'sent_at' => $latestSentAt ? (new \DateTime($latestSentAt))->format(DATE_ATOM) : null,
                ];
            }

            if ($latestInboundId > $lastRead) {
                $g['hasUnread'] = true;
            }

            $grouped[$chatId] = $g;
        }

        return $this->asJson(array_values($grouped));
    }
}
