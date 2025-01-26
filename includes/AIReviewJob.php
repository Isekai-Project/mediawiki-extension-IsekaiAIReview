<?php
namespace Isekai\AIReview;

use Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Context\RequestContext;
use ModerationViewableEntry;

class AIReviewJob extends Job {
    public function __construct(Title $title, array $params){
        parent::__construct('IsekaiAIReview', $title, $params);
    }

    /**
     * 运行job，开始进行AI审核
     */
    public function run(){
        $services = MediaWikiServices::getInstance();
        $config = $services->getMainConfig();
        $wgAIReviewRobotUID = $config->get('AIReviewRobotUID');

        $dbr = $services->getDBLoadBalancer()->getMaintenanceConnectionRef(DB_REPLICA);

        $robotUser = $services->getUserFactory()->newFromId($wgAIReviewRobotUID);

        $mod_id = $this->params['mod_id'];
        $modUser = $dbr->selectField('moderation', 'mod_user', ['mod_id' => $mod_id], __METHOD__);

        $entryFactory = $services->getService('Moderation.EntryFactory');
        $consequenceManager = $services->getService('Moderation.ConsequenceManager');

        /** @var ModerationViewableEntry $contentEntry  */
        $contentEntry = $entryFactory->findViewableEntry($mod_id);
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
                Utils::addAIReviewLog('reject', $robotUser, $modUser, $title, $mod_id, $result['reason']);
                $services->getHookContainer()->run("IsekaiAIReviewResult",
                    [ false, $title, $mod_id, $modUser, $result['reason'] ]);
                return true;
            }
        }

        //审核通过
        wfDebugLog(
            'isekai-aireview',
            'Approve revision on: ' . $title->getText()
        );
        Utils::addAIReviewLog('approve', $robotUser, $modUser, $title, $mod_id);
        $approveEntry = $entryFactory->findApprovableEntry($mod_id);
        $approveEntry->approve($robotUser);
        $services->getHookContainer()->run("IsekaiAIReviewResult",
            [ true, $title, $mod_id, $modUser, '' ]);
        return true;
    }
}