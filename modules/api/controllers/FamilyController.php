<?php

namespace app\modules\api\controllers;
use app\models\traits\ApiResponseTrait;
use Yii;
use yii\rest\Controller;
use yii\web\UploadedFile;
use yii\web\BadRequestHttpException;
use app\models\FamilyTask;
use app\models\FamilyMember;
use app\jobs\FamilyProcessingJob;

class FamilyController extends Controller
{

    use ApiResponseTrait;

    public function verbs()
    {
        return [
            'upload' => ['POST'],
            'result' => ['GET'],
            'member' => ['GET'],
            'member-real-estate' => ['GET'],
            'process' => ['POST'],
        ];
    }

    /**
     * POST /api/family/upload
     */
    public function actionUpload()
    {
        $file = UploadedFile::getInstanceByName('file');
        if (!$file) throw new BadRequestHttpException('XML file is required.');

        $task = new FamilyTask();
        $task->status = 'pending';
        $task->input_file = Yii::getAlias("@app/runtime/uploads/") . uniqid() . '.xml';
        if (!is_dir(dirname($task->input_file))) mkdir(dirname($task->input_file), 0777, true);
        $file->saveAs($task->input_file);
        $task->save(false);

        // Асинхронная обработка
        Yii::$app->queue->push(new FamilyProcessingJob($task->id));
        return $this->successResponse(['taskId' => $task->id]);
    }

    /**
     * GET /api/family/{taskId}/result
     */
    public function actionResult($taskId)
    {
        $task = FamilyTask::findOne($taskId);
        if(!empty($task) && $task->status == FamilyTask::STATUS_FAILED) {
            return $this->successResponse(['status' => $task->status, 'error' => $task->error_message]);
        }
        if (!$task || !$task->result_file || !file_exists($task->result_file)) {
            throw new BadRequestHttpException('Result not ready.');
        }
        return Yii::$app->response->sendFile($task->result_file);
    }

    /**
     * GET /api/family/members/{memberId}
     */
    public function actionMember($memberId)
    {
        $member = FamilyMember::findOne($memberId);
        if (!$member) throw new BadRequestHttpException("Member not found.");

        return [
            'id' => $member->id,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'middle_name' => $member->middle_name,
            'birth_date' => $member->birth_date,
            'relation' => $member->relation,
            'task_id' => $member->task_id,
        ];
    }

    /**
     * GET /api/family/members/{memberId}/real-estate
     */
    public function actionMemberRealEstate($memberId)
    {
        $member = FamilyMember::findOne($memberId);
        if (!$member) throw new BadRequestHttpException("Member not found.");

        $realEstate = [];
        foreach ($member->realEstates as $re) {
            $realEstate[] = [
                'type' => $re->type,
                'address' => $re->address,
                'ownership' => $re->ownership,
            ];
        }

        return $this->successResponse([
            'member_id' => $member->id,
            'has_real_estate' => !empty($realEstate),
            'objects' => $realEstate,
        ]);
    }

    /**
     * POST /api/family/process
     * Запускает обработку уже загруженного XML по taskId
     */
    public function actionProcess()
    {
        $taskId = Yii::$app->request->post('taskId');
        $task = FamilyTask::findOne($taskId);
        if (!$task) throw new BadRequestHttpException("Task not found.");

        if ($task->status === 'pending') {
            Yii::$app->queue->push(new FamilyProcessingJob(['taskId' => $task->id]));
        }

        return $this->successResponse(['taskId' => $task->id, 'status' => $task->status]);
    }
}
