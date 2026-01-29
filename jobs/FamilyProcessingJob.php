<?php

namespace app\jobs;

use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use app\models\FamilyTask;
use app\services\FamilyProcessingService;
use app\services\RealEstateService;

class FamilyProcessingJob extends BaseObject implements JobInterface
{
public int $taskId;

    public function __construct(int $taskId, $config = [])
    {
        $this->taskId = $taskId;
        parent::__construct($config);
    }

    public function execute($queue)
    {
        $task = FamilyTask::findOne($this->taskId);
        if (!$task) {
            Yii::error("Task {$this->taskId} not found", 'family');
            return;
        }

        // Не обрабатываем уже выполненные задачи
        if ($task->status === FamilyTask::STATUS_DONE) {
            Yii::info("Task {$this->taskId} already processed", 'family');
            return;
        }

        try {
            // Внедрение зависимости RealEstateService через DI
            $realEstateService = Yii::createObject(RealEstateService::class);
            $service = new FamilyProcessingService($realEstateService);

            $service->process($task);

            // Статус успешной обработки
            $task->status = FamilyTask::STATUS_DONE;
            $task->error_message = null;
            $task->save(false);

            Yii::info("Task {$this->taskId} processed successfully", 'family');

        } catch (\Throwable $e) {
            $task->status = FamilyTask::STATUS_FAILED;
            $task->error_message = $e->getMessage();
            $task->save(false);

            Yii::error([
                'message' => "Task {$this->taskId} failed",
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 'family');
        }
    }
}
