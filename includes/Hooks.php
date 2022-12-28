<?php
namespace Isekai\AIReview;

use JobQueueGroup;
use MediaWiki\MediaWikiServices;
use Title;

class Hooks {
    public static function onModerationPending($fields, $mod_id){
        //加入审核队列
        $title = Title::newFromText($fields['mod_title']);
        $job = new AIReviewJob($title, ['mod_id' => $mod_id]);
        MediaWikiServices::getInstance()->getJobQueueGroup()->push($job);
    }
}