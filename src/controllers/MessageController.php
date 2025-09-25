<?php

namespace almanac\craftmessaging\controllers;

use Craft;
use craft\web\Controller;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\UrlHelper;
use yii\web\Response;
use yii\web\UploadedFile;
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
        $type = $request->getBodyParam('type', 'text');

        if (!$senderId) {
            return $this->asJson(['error' => 'sender_id is required'])->setStatusCode(400);
        }

        $chat = (new Query())->from('{{%chats}}')->where(['id' => $chatId])->one();
        if (!$chat) {
            return $this->asJson(['error' => 'Chat not found'])->setStatusCode(404);
        }

        $db = Craft::$app->getDb();
        $sentAt = (new \DateTime())->format('Y-m-d H:i:s');
        $content = $request->getBodyParam('content', '');

        $assetId = null;

        // Handle media uploads
        if ($type !== 'text') {
            $uploadedFile = UploadedFile::getInstanceByName('file');

            if (!$uploadedFile) {
                return $this->asJson(['error' => 'File upload missing'])->setStatusCode(400);
            }

            $volumeHandle = 'messagingUploads';
            $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

            if (!$volume) {
                return $this->asJson([
                    'error' => "Upload volume '$volumeHandle' not found"
                ])->setStatusCode(500);
            }

            $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
            if (!$rootFolder) {
                return $this->asJson(['error' => 'Root folder not found'])->setStatusCode(500);
            }

            $tempPath = AssetsHelper::tempFilePath($uploadedFile->extension);
            $uploadedFile->saveAs($tempPath);

            $asset = new Asset();
            $asset->tempFilePath = $tempPath;
            $asset->filename      = $uploadedFile->name;
            $asset->newFolderId   = $rootFolder->id;
            $asset->volumeId      = $volume->id;
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (!Craft::$app->getElements()->saveElement($asset)) {
                return $this->asJson(['error' => 'Failed to save uploaded file'])
                    ->setStatusCode(500);
            }

            $assetId = $asset->id;
        }

        try {
            $db->createCommand()->insert('{{%messages}}', [
                'chatId' => $chatId,
                'senderId' => $senderId,
                'content' => $content,
                'type' => $type,
                'sentAt' => $sentAt,
                'assetId' => $assetId
            ])->execute();

            $messageId = (int) $db->getLastInsertID();

            // No per-message status rows anymore.

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
        $userIdInt = (int) $userId;

        $chat = (new Query())->from('{{%chats}}')->where(['id' => $chatId])->one();
        if (!$chat) {
            return $this->asJson(['error' => 'Chat not found'])->setStatusCode(404);
        }

        $participants = (new Query())
            ->select(['userId', 'lastDeliveredMessageId', 'lastReadMessageId'])
            ->from('{{%chat_participants}}')
            ->where(['chatId' => $chatId])
            ->all();

        $me = null;
        $others = [];
        foreach ($participants as $p) {
            if ((int) $p['userId'] === $userIdInt)
                $me = $p;
            else
                $others[] = $p;
        }

        $messages = (new Query())
            ->from('{{%messages}}')
            ->where(['chatId' => $chatId])
            ->orderBy(['sentAt' => SORT_ASC])
            ->all();

        $data = [];

        foreach ($messages as $msg) {
            $content = $msg['content'];

            if ($msg['type'] !== 'text' && $msg['assetId']) {
                $asset = Craft::$app->getAssets()->getAssetById((int) $msg['assetId']);
                if ($asset) {
                    $url = $asset->getUrl();
                    if ($url && !UrlHelper::isAbsoluteUrl($url)) {
                        $url = rtrim(UrlHelper::baseSiteUrl(), '/') . '/' . ltrim($url, '/');
                    }
                    $content = $url ?: null;
                } else {
                    $content = null;
                }
            }

            $status = 'sent';
            $mid = (int) $msg['id'];
            $senderIdInt = (int) $msg['senderId'];

            if ($senderIdInt === $userIdInt) {
                // Sender's view
                $allRead = !empty($others);
                $anyDelivered = false;
                foreach ($others as $r) {
                    $lr = (int) ($r['lastReadMessageId'] ?? 0);
                    $ld = (int) ($r['lastDeliveredMessageId'] ?? 0);
                    if ($lr < $mid)
                        $allRead = false;
                    if ($ld >= $mid)
                        $anyDelivered = true;
                }
                $status = $allRead ? 'read' : ($anyDelivered ? 'delivered' : 'sent');
            } else {
                // Recipient's view
                $myRead = (int) ($me['lastReadMessageId'] ?? 0);
                $myDelivered = (int) ($me['lastDeliveredMessageId'] ?? 0);
                $status = ($myRead >= $mid) ? 'read' : (($myDelivered >= $mid) ? 'delivered' : 'sent');
            }

            $data[] = [
                'message_id' => $msg['id'],
                'sender_id' => $msg['senderId'],
                'type' => $msg['type'],
                'content' => $content,
                'sent_at' => (new \DateTime($msg['sentAt']))->format(DATE_ATOM),
                'status' => $status
            ];
        }

        return $this->asJson($data);
    }

    public function actionMarkAsRead(): Response
    {
        $this->requirePostRequest();
        $req = Craft::$app->getRequest();

        $chatId = (int) $req->getBodyParam('chatId');
        $userId = (int) $req->getBodyParam('userId');
        $upTo = (int) $req->getBodyParam('upToMessageId');

        if (!$chatId || !$userId || !$upTo) {
            return $this->asJson(['error' => 'chatId, userId, upToMessageId required'])->setStatusCode(400);
        }

        $sql = "UPDATE {{%chat_participants}}
                SET lastDeliveredMessageId = GREATEST(COALESCE(lastDeliveredMessageId,0), :upTo),
                    lastReadMessageId      = GREATEST(COALESCE(lastReadMessageId,0), :upTo)
                WHERE chatId = :chat AND userId = :user";
        $rows = Craft::$app->getDb()->createCommand($sql, [
            ':upTo' => $upTo,
            ':chat' => $chatId,
            ':user' => $userId
        ])->execute();

        return $this->asJson(['ok' => true, 'updated' => $rows]);
    }

    public function actionAckDelivered(): Response
    {
        $this->requirePostRequest();
        $req = Craft::$app->getRequest();

        $chatId = (int) $req->getBodyParam('chatId');
        $userId = (int) $req->getBodyParam('userId');
        $upTo = (int) $req->getBodyParam('upToMessageId');

        if (!$chatId || !$userId || !$upTo) {
            return $this->asJson(['error' => 'chatId, userId, upToMessageId required'])->setStatusCode(400);
        }

        $sql = "UPDATE {{%chat_participants}}
                SET lastDeliveredMessageId = GREATEST(COALESCE(lastDeliveredMessageId,0), :upTo)
                WHERE chatId = :chat AND userId = :user";
        $rows = Craft::$app->getDb()->createCommand($sql, [
            ':upTo' => $upTo,
            ':chat' => $chatId,
            ':user' => $userId
        ])->execute();

        return $this->asJson(['ok' => true, 'updated' => $rows]);
    }

    public function actionBroadcast(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $adminId = $request->getBodyParam('adminId');
        $recipientIds = $request->getBodyParam('recipientIds', []);
        $content = $request->getBodyParam('content');

        if (!$adminId || !is_array($recipientIds) || !$content) {
            return $this->asJson(['error' => 'Missing parameters'])->setStatusCode(400);
        }

        $db = Craft::$app->getDb();
        $sentAt = (new \DateTime())->format('Y-m-d H:i:s');
        $results = [];

        foreach ($recipientIds as $recipientId) {
            $chat = (new Query())
                ->select(['c.id'])
                ->from('{{%chats}} c')
                ->innerJoin('{{%chat_participants}} p1', 'p1.chatId = c.id')
                ->innerJoin('{{%chat_participants}} p2', 'p2.chatId = c.id')
                ->where([
                    'or',
                    ['p1.userId' => $adminId, 'p2.userId' => $recipientId],
                    ['p1.userId' => $recipientId, 'p2.userId' => $adminId]
                ])
                ->one();

            if ($chat) {
                $chatId = $chat['id'];
            } else {
                $db->createCommand()->insert('{{%chats}}', [
                    'createdAt' => $sentAt,
                ])->execute();
                $chatId = $db->getLastInsertID();

                $db->createCommand()->batchInsert(
                    '{{%chat_participants}}',
                    ['chatId', 'userId', 'joinedAt'],
                    [
                        [$chatId, $adminId, $sentAt],
                        [$chatId, $recipientId, $sentAt],
                    ]
                )->execute();
            }

            $db->createCommand()->insert('{{%messages}}', [
                'chatId' => $chatId,
                'senderId' => $adminId,
                'content' => $content,
                'type' => 'text',
                'sentAt' => $sentAt,
            ])->execute();

            $messageId = $db->getLastInsertID();

            $results[] = [
                'recipient_id' => $recipientId,
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ];
        }

        return $this->asJson([
            'success' => true,
            'results' => $results,
        ]);
    }

}
