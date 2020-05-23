<?php
namespace Isekai\AIReview;

use JobQueueGroup;
use Title;

class Hooks {
    public static function onModerationPending($fields, $modid){
        //加入审核队列
        $job = new AIReviewJob(Title::newFromText($fields['mod_title']), ['mod_id' => $modid]);
        JobQueueGroup::singleton()->push($job);
    }
}