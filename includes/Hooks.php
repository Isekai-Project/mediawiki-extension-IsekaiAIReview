<?php
namespace Isekai\AIReview;

use MediaWiki\MediaWikiServices;

class Hooks {
    public static function onBeforePageDisplay($out, $skin) {
        \DeferredUpdates::addCallableUpdate(static function () { (new ReviewScheduler())->dispatchRecovery(); });
    }

    public static function onModerationPending($fields, $mod_id) {
        if (\MediaWiki\Moderation\RevisionChain::isTextEdit($fields)) {
            (new ReviewScheduler())->schedule((int)$mod_id);
            return;
        }
        $title = \Title::makeTitle($fields['mod_namespace'], $fields['mod_title']);
        MediaWikiServices::getInstance()->getJobQueueGroup()->push(new AIReviewJob($title, ['mod_id' => $mod_id]));
    }
}
