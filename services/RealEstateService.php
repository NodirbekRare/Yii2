<?php

namespace app\services;

use app\models\FamilyMember;

class RealEstateService
{
    /**
     * Возвращает данные о недвижимости для конкретного члена семьи
     *
     * @param FamilyMember $member
     * @return array ['hasRealEstate' => bool, 'objects' => array]
     */
    public function getByPerson(FamilyMember $member): array
    {
        // Для тестирования возвращаем фиксированные данные
        // Можно заменить на реальный API-запрос
        $hasRealEstate = true;

        $objects = [
            [
                'type' => 'Квартира',
                'address' => 'ул. Ленина, д. 10',
                'ownership' => 'Долевая',
            ],
            [
                'type' => 'Дом',
                'address' => 'ул. Пушкина, д. 15',
                'ownership' => 'Полная',
            ]
        ];

        return [
            'hasRealEstate' => $hasRealEstate,
            'objects' => $objects,
        ];
    }
}
