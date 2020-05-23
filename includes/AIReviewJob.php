<?php
namespace Isekai\AIReview;

use Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\AddLogEntryConsequence;
use Title;
use User;
use RequestContext;
use ModerationViewableEntry;

class AIReviewJob extends Job {
    public function __construct(Title $title, array $params){
        parent::__construct('IsekaiAIReview', $title, $params);
    }

    /**
     * 运行job，开始进行AI审核
     */
    public function run(){
        global $wgAIReviewRobotUID;

        $dbr = wfGetDB(DB_REPLICA);

        $robotUser = User::newFromId($wgAIReviewRobotUID);

        $modid = $this->params['mod_id'];
        $modUser = $dbr->selectField('moderation', 'mod_user', ['mod_id' => $modid], __METHOD__);

        $services = MediaWikiServices::getInstance();
        $entryFactory = $services->getService('Moderation.EntryFactory');
        $consequenceManager = $services->getService('Moderation.ConsequenceManager');

        /** @var ModerationViewableEntry $contentEntry  */
        $contentEntry = $entryFactory->findViewableEntry($modid);
        $title = $contentEntry->getTitle();

        $context = RequestContext::getMain();
        $context->setTitle($title);
        //获取diff内容
        $diffHtml = $contentEntry->getDiffHTML($context);
        //取出增加的文本内容
        $addedText = Utils::getDiffAddedLines($diffHtml);
        if(strlen($addedText) > 0){
            //开始进行AI审核
            $reviewer = new AliyunAIReview();
            $result = $reviewer->reviewText($addedText);
            if(!$result['pass']){ //审核不通过
                wfDebugLog(
                    'isekai-aireview',
                    'Reject revision on: ' . $title->getText() . ', reason: ' . Utils::getReadableReason($result['reason'])
                );
                Utils::addAIReviewLog('reject', $robotUser, $modUser, $title, $modid, $result['reason']);
                return true;
            }
        }

        //审核通过
        wfDebugLog(
            'isekai-aireview',
            'Approve revision on: ' . $title->getText()
        );
        Utils::addAIReviewLog('approve', $robotUser, $modUser, $title, $modid);
        $approveEntry = $entryFactory->findApprovableEntry($modid);
        $approveEntry->approve($robotUser);
        return true;
    }
}