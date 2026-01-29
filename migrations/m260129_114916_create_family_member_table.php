<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%family_member}}`.
 */
class m260129_114916_create_family_member_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('family_member', [
            'id' => $this->primaryKey(),
            'task_id' => $this->integer()->notNull(),
            'last_name' => $this->string()->notNull(),
            'first_name' => $this->string()->notNull(),
            'middle_name' => $this->string()->null(),
            'birth_date' => $this->date()->notNull(),
            'relation' => $this->string()->notNull(),
            'is_applicant' => $this->boolean()->defaultValue(false),
            'created_at' => $this->integer()->notNull(),
        ]);

        // Связь с задачей
        $this->addForeignKey(
            'fk_family_member_task',
            'family_member',
            'task_id',
            'family_task',
            'id',
            'CASCADE'
        );

        // Индекс для поиска
        $this->createIndex(
            'idx_family_member_fio_birth',
            'family_member',
            ['last_name', 'first_name', 'middle_name', 'birth_date']
        );

        // Уникальность человека
        $this->createIndex(
            'uq_family_member_unique_person',
            'family_member',
            ['last_name', 'first_name', 'middle_name', 'birth_date'],
            true
        );
    }

    public function safeDown()
    {
        $this->dropTable('family_member');
    }
}
