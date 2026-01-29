<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%real_estate}}`.
 */
class m260129_115219_create_real_estate_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('real_estate', [
            'id' => $this->primaryKey(),
            'member_id' => $this->integer()->notNull(),
            'type' => $this->string()->notNull(),
            'address' => $this->string()->notNull(),
            'ownership' => $this->string()->notNull(),
            'created_at' => $this->integer()->notNull(),
        ]);

        $this->addForeignKey(
            'fk_real_estate_member',
            'real_estate',
            'member_id',
            'family_member',
            'id',
            'CASCADE'
        );

        $this->createIndex('idx_real_estate_member', 'real_estate', 'member_id');
    }

    public function safeDown()
    {
        $this->dropTable('real_estate');
    }
}
