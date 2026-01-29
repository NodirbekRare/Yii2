<?php

namespace app\services;

use Yii;
use app\models\FamilyTask;
use app\models\FamilyMember;
use app\models\RealEstate;
use DOMDocument;
use yii\db\Transaction;
use yii\db\BatchQueryResult;
use yii\helpers\ArrayHelper;

class FamilyProcessingService
{
protected RealEstateService $realEstateService;

    public function __construct(RealEstateService $realEstateService)
    {
        $this->realEstateService = $realEstateService;
    }

    /**
     * Основной метод обработки задачи
     * @param FamilyTask $task
     * @throws \Throwable
     */
    public function process(FamilyTask $task): void
    {
        $logger = Yii::getLogger();
        $logger->log(sprintf(
            'Начало обработки задачи ID: %d, файл: %s',
            $task->id,
            basename($task->input_file)
        ), \yii\log\Logger::LEVEL_INFO, 'family');

        $startTime = microtime(true);

        if (!file_exists($task->input_file)) {
            $error = sprintf('Входной XML файл не найден: %s', $task->input_file);
            $logger->log($error, \yii\log\Logger::LEVEL_ERROR, 'family');
            throw new \RuntimeException($error);
        }

        // Проверка размера файла
        $fileSize = filesize($task->input_file);
        if ($fileSize > 10 * 1024 * 1024) {
            $error = sprintf('Файл слишком большой: %d байт (максимум 10MB)', $fileSize);
            $logger->log($error, \yii\log\Logger::LEVEL_ERROR, 'family');
            throw new \RuntimeException($error);
        }

        $xmlContent = file_get_contents($task->input_file);

        // Безопасный парсинг XML
        $previous = libxml_disable_entity_loader(true);
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);

        if (!$dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOENT)) {
            $xmlErrors = libxml_get_errors();
            libxml_clear_errors();
            libxml_disable_entity_loader($previous);

            $errorMessages = array_map(function($error) {
                return sprintf('Line %d: %s', $error->line, $error->message);
            }, $xmlErrors);

            $error = sprintf('Ошибка парсинга XML: %s', implode('; ', $errorMessages));
            $logger->log($error, \yii\log\Logger::LEVEL_ERROR, 'family');
            throw new \RuntimeException('Некорректный XML формат');
        }

        libxml_disable_entity_loader($previous);

        $memberNodes = $dom->getElementsByTagName('Member');
        if ($memberNodes->length === 0) {
            $error = 'В XML не найдены узлы <Member>';
            $logger->log($error, \yii\log\Logger::LEVEL_ERROR, 'family');
            throw new \RuntimeException($error);
        }

        $transaction = Yii::$app->db->beginTransaction(Transaction::SERIALIZABLE);

        try {
            $hasApplicant = false;
            $membersProcessed = 0;
            $realEstateBatch = [];

            // Сбор всех данных членов семьи для валидации
            $membersData = [];
            foreach ($memberNodes as $index => $node) {
                $memberData = $this->extractMemberData($node);
                $membersData[] = $memberData;

                if ($memberData['is_applicant']) {
                    if ($hasApplicant) {
                        throw new \RuntimeException('В XML обнаружено более одного заявителя');
                    }
                    $hasApplicant = true;
                }
            }

            if (!$hasApplicant) {
                throw new \RuntimeException('В XML не найден заявитель (должен быть хотя бы один член с признаком заявителя)');
            }

            // Обработка и сохранение членов семьи
            foreach ($membersData as $index => $data) {
                $member = $this->processMember($task->id, $data);
                $membersProcessed++;

                // Получаем данные о недвижимости
                $realEstateData = $this->getRealEstateData($member);

                if (!empty($realEstateData['objects'])) {
                    foreach ($realEstateData['objects'] as $obj) {
                        $realEstateBatch[] = [
                            'member_id' => $member->id,
                            'type' => $this->sanitizeString($obj['type'] ?? null),
                            'address' => $this->sanitizeString($obj['address'] ?? null),
                            'ownership' => $this->sanitizeString($obj['ownership'] ?? null),
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                    }
                }
            }

            // Batch insert для недвижимости
            if (!empty($realEstateBatch)) {
                $batchSize = 100;
                for ($i = 0; $i < count($realEstateBatch); $i += $batchSize) {
                    $batch = array_slice($realEstateBatch, $i, $batchSize);
                    Yii::$app->db->createCommand()
                        ->batchInsert(
                            RealEstate::tableName(),
                            ['member_id', 'type', 'address', 'ownership', 'created_at'],
                            $batch
                        )->execute();
                }

                $logger->log(sprintf(
                    'Добавлено %d записей о недвижимости',
                    count($realEstateBatch)
                ), \yii\log\Logger::LEVEL_INFO, 'family');
            }

            $transaction->commit();

            // Генерация результирующего XML
            $this->generateResultXml($task);

            $duration = round(microtime(true) - $startTime, 2);
            $logger->log(sprintf(
                'Задача %d успешно обработана. Обработано членов: %d, время: %s сек, память: %s MB',
                $task->id,
                $membersProcessed,
                $duration,
                round(memory_get_peak_usage(true) / 1024 / 1024, 2)
            ), \yii\log\Logger::LEVEL_INFO, 'family');

        } catch (\Throwable $e) {
            $transaction->rollBack();

            $logger->log(sprintf(
                'Ошибка обработки задачи %d: %s в файле %s:%d',
                $task->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ), \yii\log\Logger::LEVEL_ERROR, 'family');

            // Дополнительное логирование для отладки
            Yii::error([
                'task_id' => $task->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 'family-processing');

            throw new \RuntimeException(sprintf(
                'Ошибка обработки данных: %s',
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * Извлечение данных члена семьи из XML узла
     */
    protected function extractMemberData(\DOMElement $node): array
    {
        $data = [
            'last_name' => $this->getNodeValue($node, 'LastName'),
            'first_name' => $this->getNodeValue($node, 'FirstName'),
            'middle_name' => $this->getNodeValue($node, 'MiddleName'),
            'birth_date' => $this->getNodeValue($node, 'BirthDate'),
            'relation' => $this->getNodeValue($node, 'Relation'),
            'is_applicant' => strtolower($this->getNodeValue($node, 'Applicant') ?? 'false') === 'true',
        ];

        // Валидация обязательных полей
        $requiredFields = ['last_name', 'first_name', 'birth_date', 'relation'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException(sprintf(
                    'Отсутствует обязательное поле: %s',
                    $field
                ));
            }
        }

        // Валидация даты
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['birth_date'])) {
            throw new \RuntimeException(sprintf(
                'Некорректный формат даты рождения: %s. Ожидается YYYY-MM-DD',
                $data['birth_date']
            ));
        }

        // Проверка возраста для заявителя
        if ($data['is_applicant']) {
            $birthDate = new \DateTime($data['birth_date']);
            $today = new \DateTime();
            $age = $today->diff($birthDate)->y;

            if ($age < 18) {
                throw new \RuntimeException(sprintf(
                    'Заявитель %s %s %s несовершеннолетний (возраст: %d)',
                    $data['last_name'],
                    $data['first_name'],
                    $data['middle_name'],
                    $age
                ));
            }
        }

        // Санитизация строк
        foreach (['last_name', 'first_name', 'middle_name', 'relation'] as $field) {
            if ($data[$field]) {
                $data[$field] = $this->sanitizeString($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Обработка и сохранение члена семьи
     */
    protected function processMember(int $taskId, array $data): FamilyMember
    {
        // Поиск существующего члена семьи
        $member = FamilyMember::find()
            ->where([
                'task_id' => $taskId,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'middle_name' => $data['middle_name'],
                'birth_date' => $data['birth_date'],
            ])
            ->one();

        if (!$member) {
            $member = new FamilyMember();
            $member->task_id = $taskId;
        }

        $member->first_name = $data['first_name'];
        $member->last_name = $data['last_name'];
        $member->middle_name = $data['middle_name'];
        $member->birth_date = $data['birth_date'];
        $member->relation = $data['relation'];
        $member->is_applicant = $data['is_applicant'];

        if (!$member->save()) {
            $errors = implode(', ', $member->getErrorSummary(false));
            throw new \RuntimeException(sprintf(
                'Ошибка сохранения члена семьи %s %s: %s',
                $member->first_name,
                $member->last_name,
                $errors
            ));
        }

        Yii::getLogger()->log(sprintf(
            'Сохранен член семьи: %s (ID: %d)',
            $member->getFullName(),
            $member->id
        ), \yii\log\Logger::LEVEL_INFO, 'family');

        return $member;
    }

    /**
     * Получение данных о недвижимости с обработкой ошибок
     */
    protected function getRealEstateData(FamilyMember $member): array
    {
        try {
            $data = $this->realEstateService->getByPerson($member);

            if (!is_array($data)) {
                throw new \RuntimeException('Сервис недвижимости вернул некорректный ответ');
            }

            return [
                'hasRealEstate' => $data['hasRealEstate'] ?? false,
                'objects' => $data['objects'] ?? [],
            ];

        } catch (\Throwable $e) {
            Yii::getLogger()->log(sprintf(
                'Ошибка получения недвижимости для члена семьи %d: %s',
                $member->id,
                $e->getMessage()
            ), \yii\log\Logger::LEVEL_WARNING, 'family');

            return ['hasRealEstate' => false, 'objects' => []];
        }
    }

    /**
     * Генерация итогового XML
     */
    protected function generateResultXml(FamilyTask $task): void
    {
        try {
            // Оптимизированный запрос с пагинацией
            $query = $task->getMembers()
                ->with('realEstates')
                ->orderBy(['id' => SORT_ASC]);

            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->formatOutput = true;

            $root = $dom->createElement('FamilyRealEstateResult');
            $dom->appendChild($root);

            $batchSize = 50;
            $offset = 0;

            do {
                $members = $query->limit($batchSize)->offset($offset)->all();

                foreach ($members as $member) {
                    $memberNode = $dom->createElement('Member');

                    // ФИО
                    $fioNode = $dom->createElement('FIO');
                    $fioNode->appendChild($dom->createTextNode($member->getFullName()));
                    $memberNode->appendChild($fioNode);

                    // Дата рождения
                    $birthNode = $dom->createElement('BirthDate');
                    $birthNode->appendChild($dom->createTextNode($member->birth_date));
                    $memberNode->appendChild($birthNode);

                    // Родство
                    $relationNode = $dom->createElement('Relation');
                    $relationNode->appendChild($dom->createTextNode($member->relation));
                    $memberNode->appendChild($relationNode);

                    // Недвижимость
                    $realEstateNode = $this->createRealEstateNode($dom, $member);
                    $memberNode->appendChild($realEstateNode);

                    // Статус
                    $statusNode = $dom->createElement('Status');
                    $statusNode->appendChild($dom->createTextNode('OK'));
                    $memberNode->appendChild($statusNode);

                    $root->appendChild($memberNode);
                }

                $offset += $batchSize;
                unset($members); // Освобождаем память

            } while (!empty($members));

            // Сохранение файла
            $resultDir = Yii::getAlias('@app/runtime/results');
            if (!is_dir($resultDir)) {
                if (!mkdir($resultDir, 0755, true) && !is_dir($resultDir)) {
                    throw new \RuntimeException(sprintf('Не удалось создать директорию: %s', $resultDir));
                }
            }

            $filename = sprintf('task_%d_%s.xml', $task->id, date('Ymd_His'));
            $filepath = $resultDir . DIRECTORY_SEPARATOR . $filename;

            if (!$dom->save($filepath)) {
                throw new \RuntimeException('Не удалось сохранить XML файл');
            }

            $task->result_file = $filepath;
            $task->status = FamilyTask::STATUS_COMPLETED;

            if (!$task->save(false)) {
                throw new \RuntimeException('Не удалось обновить задачу');
            }

            Yii::getLogger()->log(sprintf(
                'Сгенерирован результат для задачи %d: %s',
                $task->id,
                $filename
            ), \yii\log\Logger::LEVEL_INFO, 'family');

        } catch (\Throwable $e) {
            Yii::getLogger()->log(sprintf(
                'Ошибка генерации XML для задачи %d: %s',
                $task->id,
                $e->getMessage()
            ), \yii\log\Logger::LEVEL_ERROR, 'family');

            throw $e;
        }
    }

    /**
     * Создание узла недвижимости
     */
    protected function createRealEstateNode(DOMDocument $dom, FamilyMember $member): \DOMElement
    {
        $realEstateNode = $dom->createElement('RealEstate');

        $hasRealEstate = !empty($member->realEstates);
        $hasRealEstateNode = $dom->createElement('HasRealEstate');
        $hasRealEstateNode->appendChild($dom->createTextNode($hasRealEstate ? 'true' : 'false'));
        $realEstateNode->appendChild($hasRealEstateNode);

        $objectsNode = $dom->createElement('Objects');
        foreach ($member->realEstates as $re) {
            $objNode = $dom->createElement('Object');

            $typeNode = $dom->createElement('Type');
            $typeNode->appendChild($dom->createTextNode($re->type ?? 'Не указан'));
            $objNode->appendChild($typeNode);

            $addressNode = $dom->createElement('Address');
            $addressNode->appendChild($dom->createTextNode($re->address ?? 'Не указан'));
            $objNode->appendChild($addressNode);

            $ownershipNode = $dom->createElement('Ownership');
            $ownershipNode->appendChild($dom->createTextNode($re->ownership ?? 'Не указан'));
            $objNode->appendChild($ownershipNode);

            $objectsNode->appendChild($objNode);
        }

        $realEstateNode->appendChild($objectsNode);
        return $realEstateNode;
    }

    /**
     * Безопасное получение значения узла
     */
    protected function getNodeValue(\DOMElement $node, string $tag): ?string
    {
        $child = $node->getElementsByTagName($tag)->item(0);
        if (!$child) {
            return null;
        }

        $value = trim($child->nodeValue);
        return $value === '' ? null : $value;
    }

    /**
     * Санитизация строки
     */
    protected function sanitizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Удаляем лишние пробелы и спецсимволы
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);

        // Экранирование HTML сущностей
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}