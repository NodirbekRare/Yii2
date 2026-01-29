<?php

namespace app\models;

use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;

/**
 * Class FamilyMember
 * @package app\models
 *
 * @property int $id
 * @property int $task_id
 * @property string $last_name
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $birth_date
 * @property string $relation
 * @property bool $is_applicant
 * @property int $created_at
 *
 * @property FamilyTask $task
 * @property RealEstate[] $realEstates
 */
class FamilyMember extends ActiveRecord
{
    public function rules(): array
    {
        return [
            [['first_name', 'last_name', 'birth_date', 'task_id', 'relation'], 'required'],
            [['first_name', 'last_name', 'middle_name', 'relation'], 'string', 'max' => 255],
            ['birth_date', 'date', 'format' => 'php:Y-m-d'],
            ['is_applicant', 'boolean'],
            ['is_applicant', 'default', 'value' => false],

            // Уникальность в рамках задачи
            [['first_name', 'last_name', 'middle_name', 'birth_date'],
                'unique',
                'targetAttribute' => ['first_name', 'last_name', 'middle_name', 'birth_date'],
                'message' => 'Этот член семьи уже существует в данной задаче'
            ],

            // Внешний ключ
            ['task_id', 'exist', 'targetClass' => FamilyTask::class, 'targetAttribute' => 'id'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'last_name' => 'Фамилия',
            'first_name' => 'Имя',
            'middle_name' => 'Отчество',
            'birth_date' => 'Дата рождения',
            'relation' => 'Родство',
            'is_applicant' => 'Заявитель',
        ];
    }

    /**
     * Валидация возраста (только для заявителя)
     */
    public function validateAge(string $attribute): void
    {
        if ($this->is_applicant && $this->birth_date) {
            $birthDate = new \DateTime($this->birth_date);
            $today = new \DateTime();
            $age = $today->diff($birthDate)->y;

            if ($age < 18) {
                $this->addError($attribute, 'Заявитель должен быть совершеннолетним (18 лет и старше)');
            }
        }
    }

    /**
     * Валидация наличия только одного заявителя
     */
    public function validateApplicant($attribute, $params, $validator): void
    {
        if ($this->is_applicant) {
            // Проверяем, есть ли уже заявитель в этой задаче
            $existingApplicant = self::find()
                ->where(['task_id' => $this->task_id, 'is_applicant' => true])
                ->andWhere(['!=', 'id', $this->id])
                ->exists();

            if ($existingApplicant) {
                $this->addError($attribute, 'В задаче может быть только один заявитель');
            }
        }
    }

    public static function tableName(): string
    {
        return '{{%family_member}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => false,
                'value' => fn() => date('Y-m-d H:i:s'),
        ],
        ];
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        // Валидация возраста перед сохранением
        $this->validateAge('birth_date');

        if ($this->hasErrors()) {
            return false;
        }

        return true;
    }

    public function afterSave($insert, $changedAttributes): void
    {
        parent::afterSave($insert, $changedAttributes);

        if ($insert) {
            \Yii::info(sprintf(
                'Создан член семьи: %s %s %s (ID: %d, Задача: %d)',
                $this->last_name,
                $this->first_name,
                $this->middle_name,
                $this->id,
                $this->task_id
            ), 'family');
        }
    }

    /**
     * Релейшен на задачу
     */
    public function getTask(): ActiveQuery
    {
        return $this->hasOne(FamilyTask::class, ['id' => 'task_id']);
    }

    /**
     * Релейшен на недвижимость
     */
    public function getRealEstates(): ActiveQuery
    {
        return $this->hasMany(RealEstate::class, ['member_id' => 'id']);
    }

    /**
     * Полное ФИО
     */
    public function getFullName(): string
    {
        return trim(sprintf(
            '%s %s %s',
            $this->last_name,
            $this->first_name,
            $this->middle_name ?? ''
        ));
    }
}