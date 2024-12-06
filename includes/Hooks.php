<?php
namespace Isekai\AIReview;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class Hooks {
    public static function onModerationPending($fields, $mod_id){
        //加入审核队列
        $title = Title::newFromText($fields['mod_title']);
        $job = new AIReviewJob($title, ['mod_id' => $mod_id]);
        MediaWikiServices::getInstance()->getJobQueueGroup()->push($job);
    }
}