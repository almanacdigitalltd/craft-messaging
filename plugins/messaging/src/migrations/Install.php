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
            'userId' => $this->string()->notNull(),
            'joinedAt' => $this->dateTime()->notNull(),
        ]);
        $this->addForeignKey(null, '{{%chat_participants}}', 'chatId', '{{%chats}}', 'id', 'CASCADE');

        $this->createTable('{{%messages}}', [
            'id' => $this->primaryKey(),
            'chatId' => $this->integer()->notNull(),
            'senderId' => $this->string()->notNull(),
            'content' => $this->text()->notNull(),
            'type' => $this->string()->notNull()->defaultValue('text'),
            'sentAt' => $this->dateTime()->notNull(),
        ]);
        $this->addForeignKey(null, '{{%messages}}', 'chatId', '{{%chats}}', 'id', 'CASCADE');

        $this->createTable('{{%message_statuses}}', [
            'id' => $this->primaryKey(),
            'messageId' => $this->integer()->notNull(),
            'userId' => $this->string()->notNull(),
            'status' => $this->string()->notNull(), // 'sent', 'delivered', 'read'
            'updatedAt' => $this->dateTime()->notNull(),
        ]);
        $this->addForeignKey(null, '{{%message_statuses}}', 'messageId', '{{%messages}}', 'id', 'CASCADE');
        $this->createIndex(null, '{{%message_statuses}}', ['messageId', 'userId'], true);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Place uninstallation code here...

        return true;
    }
}
