<?php

namespace kernpfad\commerceklaviyo\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%commerceklaviyo_trackedevents}}', [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'eventType' => $this->string(50)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%commerceklaviyo_trackedevents}}', ['orderId', 'eventType'], true);

        $this->addForeignKey(
            null,
            '{{%commerceklaviyo_trackedevents}}',
            ['orderId'],
            '{{%commerce_orders}}',
            ['id'],
            'CASCADE'
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%commerceklaviyo_trackedevents}}');

        return true;
    }
}
