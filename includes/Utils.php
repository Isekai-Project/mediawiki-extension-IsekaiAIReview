<?php
namespace Isekai\AIReview;

use ManualLogEntry;
use PHPHtmlParser\Dom;

class Utils {
    /**
     * @throws \PHPHtmlParser\Exceptions\CurlException
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\NotLoadedException
     * @throws \PHPHtmlParser\Exceptions\CircularException
     * @throws \PHPHtmlParser\Exceptions\StrictException
     */
    public static function getDiffAddedLines($diffHtml) {
        $dom = new Dom();
        $dom->load($diffHtml);
        $lines = [];

        if($addedLineDomList = $dom->find('.diff-addedline')){
            /** @var \PHPHtmlParser\Dom\HtmlNode $addedLineDom */
            foreach($addedLineDomList as $addedLineDom){
                $lines[] = strip_tags($addedLineDom->innerHtml);
            }
        }

        return trim(implode("\n", $lines));
    }

    public static function getReadableReason($reasons){
        $allowedReasons = ['spam', 'ad', 'politics', 'terrorism', 'abuse', 'porn', 'flood', 'contraband', 'meaningless', 'customized', 'normal'];

        if(is_string($reasons)) return $reasons;

        $readableReasons = [];
        foreach($reasons as $reason){
            if(in_array($reason, $allowedReasons)){
                $readableReasons[] = wfMessage('isekai-aireview-aliyun-reason-' . $reason)->escaped();
            } else {
                $readableReasons[] = wfMessage('isekai-aireview-aliyun-reason-unknow', $reason)->escaped();
            }
        }

        return implode(', ', $readableReasons);
    }

    /**
     * @throws \MWException
     */
    public static function addAIReviewLog($event, $robotUser, $modUser, $title, $modid, $reason = null){
        $entry = new ManualLogEntry('aireview', $event);
        $entry->setPerformer($robotUser);
        $entry->setTarget($title);

        $param = [
            'modid' => $modid,
            'moduser' => $modUser,
        ];
        if($reason){
            $param['reason'] = $reason;
        }

        $entry->setParameters($param);
        $entry->insert();
    }
}