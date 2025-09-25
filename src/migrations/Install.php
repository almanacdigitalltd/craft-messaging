<?php

namespace almanac\craftmessaging\migrations;

use Craft;
use craft\db\Migration;

/**
 * Install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Place installation code here...
        $this->createTable('{{%chats}}', [
            'id' => $this->primaryKey(),
            'createdAt' => $this->dateTime()->notNull(),
        ]);

        $this->createTable('{{%chat_participants}}', [
            'id' => $this->primaryKey(),
            'chatId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'joinedAt' => $this->dateTime()->notNull(),
            'lastDeliveredMessageId' => $this->integer()->null(),
            'lastReadMessageId' => $this->integer()->null(),
        ]);
        $this->addForeignKey(null, '{{%chat_participants}}', 'chatId', '{{%chats}}', 'id', 'CASCADE');
        $this->createIndex(null, '{{%chat_participants}}', ['chatId', 'userId', 'lastReadMessageId'], false);

        $this->createTable('{{%messages}}', [
            'id' => $this->primaryKey(),
            'chatId' => $this->integer()->notNull(),
            'senderId' => $this->integer()->notNull(),
            'content' => $this->text()->notNull(),
            'type' => $this->string()->notNull()->defaultValue('text'),
            'sentAt' => $this->dateTime()->notNull(),
            'assetId' => $this->integer()->null()
        ]);
        $this->addForeignKey(null, '{{%messages}}', 'chatId', '{{%chats}}', 'id', 'CASCADE');
        $this->addForeignKey(null, '{{%messages}}', 'assetId', '{{%assets}}', 'id', 'SET NULL');
        $this->createIndex(null, '{{%messages}}', ['chatId', 'id'], false);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%message_statuses}}');
        $this->dropTableIfExists('{{%messages}}');
        $this->dropTableIfExists('{{%chat_participants}}');
        $this->dropTableIfExists('{{%chats}}');
        return true;
    }
}
