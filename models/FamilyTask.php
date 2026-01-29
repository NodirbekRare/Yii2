<?php

namespace app\models;

use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * Class FamilyTask
 * @package app\models
 *
 * @property int $id
 * @property string $status
 * @property string $input_file
 * @property string|null $result_file
 * @property string|null $error_message
 * @property int $created_at
 * @property int $updated_at
 *
 * @property FamilyMember[] $members
 */
class FamilyTask extends ActiveRecord
{

    const  STATUS_DONE = 'done';
    const  STATUS_FAILED = 'failed';
    const  STATUS_PANDING = 'pending';
    public static function tableName()
    {
        return 'family_task';
    }

    public function behaviors()
    {
        return [
            // Автозаполнение created_at и updated_at
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
                'value' => time(),
            ],
        ];
    }

    /**
     * Релейшен на членов семьи
     */
    public function getMembers()
    {
        return $this->hasMany(FamilyMember::class, ['task_id' => 'id']);
    }
}
