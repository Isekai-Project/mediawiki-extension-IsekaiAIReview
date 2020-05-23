<?php
namespace Isekai\AIReview;

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Green\Green;
use Exception;

class AliyunAIReview {
    private const MAX_LENGTH = 10000;

    public function __construct(){
        global $wgAIReviewEndpoint, $wgAIReviewAccessKeyId, $wgAIReviewAccessKeySecret;
        AlibabaCloud::accessKeyClient($wgAIReviewAccessKeyId, $wgAIReviewAccessKeySecret)
            ->regionId($wgAIReviewEndpoint)
            ->asDefaultClient();
    }

    public function reviewText($text){
        $reqData = $this->buildRequestData($text);
        $response = $this->doRequest($reqData);
        return $response;
    }

    public function buildRequestData($text){
        global $wgAIReviewBizType;

        $reqData = [
            'scenes' => ['antispam'],
            'tasks' => $this->buildTasks($text),
        ];

        if($wgAIReviewBizType) $reqData['bizType'] = $wgAIReviewBizType;
        return $reqData;
    }

    public function buildTasks($text){
        $splitter = new SectionSplitter($text, self::MAX_LENGTH);
        $chunkList = $splitter->getChunkList();
        $taskList = [];
        foreach($chunkList as $chunk){
            $task = [
                'dataId' => uniqid(),
                'content' => $chunk,
            ];
            $taskList[] = $task;
        };
        unset($chunkList);
        return $taskList;
    }

    public function doRequest($requestData){
        $textScan = Green::v20180509()->textScan();
        $response = $textScan->setMethod('POST')->setAcceptFormat('JSON')->setContent(json_encode($requestData))->request();

        if($response->getReasonPhrase() === 'OK'){
            return $this->parseResponse($response->toArray());
        } else {
            return ['pass' => false, 'reason' => wfMessage('isekai-aireview-aliyun-server-error', $response->getStatusCode())->escaped()];
        }
    }

    public function parseResponse($response){
        if($response['code'] !== 200)
            return ['pass' => false, 'reason' => wfMessage('isekai-aireview-aliyun-server-error', $response['code'])->escaped()];
        
        $pass = true;
        $reasons = [];
        foreach($response['data'] as $task){
            if(is_array($task['results'])){
                foreach($task['results'] as $result){
                    if($result['suggestion'] !== 'pass'){
                        $pass = false;
                        foreach($result['details'] as $detail){
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