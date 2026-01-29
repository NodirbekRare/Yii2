<?php

namespace app\models;

use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;

/**
 * Class RealEstate
 * @package app\models
 *
 * @property int $id
 * @property int $member_id
 * @property string $type
 * @property string $address
 * @property string $ownership
 * @property int $created_at
 *
 * @property FamilyMember $member
 */
class RealEstate extends ActiveRecord
{
    public static function tableName()
    {
        return 'real_estate';
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => false,
                'value' => time(),
            ],
        ];
    }

    /**
     * Релейшен на владельца
     */
    public function getMember()
    {
        return $this->hasOne(FamilyMember::class, ['id' => 'member_id']);
    }
}
