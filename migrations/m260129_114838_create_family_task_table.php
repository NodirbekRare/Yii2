<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%family_task}}`.
 */
class m260129_114838_create_family_task_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('family_task', [
            'id' => $this->primaryKey(),
            'status' => $this->string()->notNull()->defaultValue('pending'),
            'input_file' => $this->string()->notNull(),
            'result_file' => $this->string()->null(),
            'error_message' => $this->text()->null(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->createIndex('idx_family_task_status', 'family_task', 'status');
    }

    public function safeDown()
    {
        $this->dropTable('family_task');
    }
}
