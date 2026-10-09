<?php
namespace Isekai\AIReview;

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Green\Green;

class AliyunAIReview {
    private const MAX_LENGTH = 10000;
    private $requestTimeout = 120;

    public function setRequestTimeout(float $seconds): void {
        $this->requestTimeout = max(0.1, min(120, $seconds));
    }

    public function __construct() {
        global $wgAIReviewEndpoint, $wgAIReviewAccessKeyId, $wgAIReviewAccessKeySecret;
        AlibabaCloud::accessKeyClient($wgAIReviewAccessKeyId, $wgAIReviewAccessKeySecret)
            ->connectTimeout(3)
            ->timeout(120)
            ->regionId($wgAIReviewEndpoint)
            ->asDefaultClient();
    }

    public function reviewText($text, $rawResponse = false, ?string $title = null) {
        $reqData = $this->buildRequestData($text, $title);
        $response = $this->doRequest($reqData, $rawResponse);
        return $response;
    }

    public function buildRequestData($text, ?string $title = null) {
        global $wgAIReviewBizType;

        $reqData = [
            'scenes' => ['antispam'],
            'tasks' => $this->buildTasks($text, $title),
        ];

        if($wgAIReviewBizType) $reqData['bizType'] = $wgAIReviewBizType;
        return $reqData;
    }

    public function buildTasks($text, ?string $title = null) {
        $prefix = $title === null ? '' : '《' . $title . "》\n\n";
        $bodyLimit = self::MAX_LENGTH - mb_strlen($prefix, 'UTF-8');
        if ($bodyLimit < 1) {
            throw new \InvalidArgumentException('Page title exceeds AI task length limit');
        }
        $splitter = new SectionSplitter($text, $bodyLimit);
        $chunkList = $splitter->getChunkList();
        $taskList = [];
        foreach($chunkList as $chunk){
            $task = [
                'dataId' => uniqid(),
                'content' => $prefix . $chunk,
            ];
            $taskList[] = $task;
        };
        unset($chunkList);
        return $taskList;
    }

    /**
     * @throws \AlibabaCloud\Client\Exception\ServerException
     * @throws \AlibabaCloud\Client\Exception\ClientException
     */
    public function doRequest($requestData, $rawResponse = false) {
        $textScan = Green::v20180509()->textScan()->timeout($this->requestTimeout);
        $response = $textScan->body(json_encode($requestData))->request();

        if ($rawResponse) {
            return $response;
        } else {
            if ($response->getReasonPhrase() === 'OK') {
                return $this->parseResponse($response->toArray(), count($requestData['tasks']));
            } else {
                return ['pass' => false, 'error' => true, 'reason' => wfMessage('isekai-aireview-aliyun-server-error', $response->getStatusCode())->escaped()];
            }
        }
    }

    public function parseResponse($response, $expectedTasks = null) {
        if (!isset($response['code']) || $response['code'] !== 200)
            return ['pass' => false, 'error' => true, 'reason' => wfMessage('isekai-aireview-aliyun-server-error', ($response['code'] ?? 0))->escaped()];
        
        if (empty($response['data']) || !is_array($response['data']) ||
            ($expectedTasks !== null && count($response['data']) !== $expectedTasks)) {
            return ['pass' => false, 'error' => true, 'reason' => ['incomplete-response']];
        }
        $pass = true;
        $reasons = [];
        foreach($response['data'] as $task){
            if (empty($task['results']) || !is_array($task['results']) || (isset($task['code']) && $task['code'] !== 200)) {
                return ['pass' => false, 'error' => true, 'reason' => ['incomplete-response']];
            }
            if(is_array($task['results'])){
                foreach($task['results'] as $result){
                    if (!isset($result['suggestion']) || !in_array($result['suggestion'], ['pass', 'review', 'block'], true)) {
                        return ['pass' => false, 'error' => true, 'reason' => ['incomplete-response']];
                    }
                    if($result['suggestion'] !== 'pass'){
                        $pass = false;
                        foreach(($result['details'] ?? []) as $detail){
                            $reason = $detail['label'];
                            if(!in_array($reason, $reasons)){
                                $reasons[] = $reason;
                            }
                        }
                    }
                }
            }
        }
        return ['pass' => $pass, 'reason' => $reasons];
    }
}
