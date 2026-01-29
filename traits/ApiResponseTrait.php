<?php

namespace app\models\traits;

use Yii;

trait ApiResponseTrait
{
    /**
     * @param null $response
     * @param string $message
     * @param int $status
     * @return array
     */
    public function successResponse($response = null, $message = 'OK', $status = 200)
    {
        return $this->formatResponse($status, $message, $response);
    }

    /**
     * @param string $message
     * @param int $status
     * @param null $response
     * @return array
     */
    public function errorResponse($message = 'Error', $status = 400, $response = null)
    {
        return $this->formatResponse($status, $message, $response);
    }

    /**
     * @param $status
     * @param $message
     * @param $response
     * @return array
     */
    private function formatResponse($status, $message, $response)
    {
        Yii::$app->response->statusCode = $status;

        return [
            'status' => $status,
            'message' => $message,
            'response' => $response,
            'requested_at' => date('d.m.Y H:i'),
        ];
    }

    /**
     * @param $date
     * @param string $format
     * @return false|null|string
     */
    public function formatDate($date, $format = 'd.m.Y H:i') {
        if(!$date) {
            return null;
        }
        return date($format, strtotime($date));
    }


    /**
     * @return array|mixed|object
     * @throws \yii\base\InvalidConfigException
     */
    public function getParams() {
        $request = \Yii::$app->request;

        $params = $request->getBodyParams();
        if (!empty($params)) {
            return $params;
        }

        $raw = $request->getRawBody();
        if (!empty($raw)) {
            $json = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return $json;
            }
        }

        return $request->post() ?: $request->get();
    }

}
