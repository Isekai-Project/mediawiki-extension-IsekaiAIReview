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
        $this->removeDuplicates = true;
    }

    /**
     * 运行job，开始进行AI审核
     */
    public function getDeduplicationInfo() {
        return ['type' => $this->getType(), 'mod_id' => (int)$this->params['mod_id'],
            'generation' => $this->params['generation'] ?? 0];
    }

    public function run() {
        $services = MediaWikiServices::getInstance();
        $id = (int)$this->params['mod_id'];
        $chains = $services->getService('Moderation.RevisionChain');
        if (!$chains->get($id)) {
            // Missing entries are harmless; legacy move/upload jobs retain their old workflow.
            $db = \ModerationCompatTools::getDB(DB_PRIMARY);
            if (!$db->selectField('moderation', 'mod_id', ['mod_id' => $id], __METHOD__)) { return true; }
            return $this->runLegacy();
        }
        $scheduler = $this->createScheduler();
        $token = $scheduler->claim($id, isset($this->params['generation']) ? (int)$this->params['generation'] : null);
        if (!$token) { return true; }
        $robot = $services->getUserFactory()->newFromId($services->getMainConfig()->get('AIReviewRobotUID'));
        $start = microtime(true);
        try {
            for ($count = 0; $count < 20 && microtime(true) - $start < 60; $count++) {
                $revision = $scheduler->next($id, $token);
                if (!$revision) { break; }
                $fields = json_decode($revision->mr_payload, true);
                $title = Title::makeTitle($fields['mod_namespace'], $fields['mod_title']);
                $context = new \DerivativeContext(RequestContext::getMain());
                $context->setTitle($title);
                try {
                    $text = Utils::getDiffAddedLines($chains->diff($revision, $context));
                    $scheduler->commit();
                    $reviewTitle = (int)$revision->mr_base_revid === 0 ? $title->getPrefixedText() : null;
                    if ($text === '' && $reviewTitle === null) {
                        $result = ['pass' => true, 'reason' => []];
                    } else {
                        $reviewer = $this->createReviewer();
                        $reviewer->setRequestTimeout(60 - (microtime(true) - $start));
                        $result = $reviewer->reviewText($text, false, $reviewTitle);
                    }
                    $status = !empty($result['error']) ? 'error' : ($result['pass'] ? 'pass' : 'fail');
                    $reason = $result['reason'];
                } catch (\Throwable $e) {
                    $status = 'error';
                    $reason = ['service-error'];
                    wfDebugLog('isekai-aireview', 'AI service error for pending revision ' . $revision->mr_id);
                }
                if ($scheduler->saveResult($id, $token, $revision, $status, $reason) && $status !== 'error') {
                    Utils::addAIReviewLog($status === 'pass' ? 'approve' : 'reject', $robot,
                        $fields['mod_user'], $title, $id, $reason, (int)$revision->mr_id, 'judgment');
                    $services->getHookContainer()->run('IsekaiAIReviewResult',
                        [$status === 'pass', $title, $id, $fields['mod_user'], $reason, (int)$revision->mr_id, 'judgment']);
                    $scheduler->commit();
                }
                if ($status === 'error') { break; }
            }
            $applied = $scheduler->applyReviewed($id, $token, $robot);
            $history = [];
            foreach ($chains->revisions($id) as $snapshot) { $history[$snapshot->mr_id] = $snapshot; }
            foreach ($applied['processed'] ?? [] as $item) {
                $snapshot = $history[$item['id']];
                $fields = json_decode($snapshot->mr_payload, true);
                $title = Title::makeTitle($fields['mod_namespace'], $fields['mod_title']);
                $reason = json_decode($snapshot->mr_ai_reason, true) ?? [];
                Utils::addAIReviewLog($item['decision'], $robot, $fields['mod_user'],
                    $title, $id, $reason, $item['id'], 'publication');
                $services->getHookContainer()->run('IsekaiAIReviewResult',
                    [$item['decision'] === 'approve', $title, $id, $fields['mod_user'], $reason, $item['id'], 'publication']);
            }
            $scheduler->commit();
        } finally {
            $scheduler->finish($id, $token);
        }
        // Errors get a delayed successor rather than a tight retry of the claimed Job.
        return true;
    }

    protected function createScheduler(): ReviewScheduler { return new ReviewScheduler(); }

    protected function createReviewer(): AliyunAIReview { return new AliyunAIReview(); }

    private function runLegacy() {
        $services = MediaWikiServices::getInstance();
        $db = \ModerationCompatTools::getDB(DB_PRIMARY);
        $row = $db->selectRow('moderation', \ModerationApprovableEntry::getFields(),
            ['mod_id' => (int)$this->params['mod_id']], __METHOD__);
        if (!$row || $row->rejected || $row->merged_revid) { return true; }
        if ($row->type === \ModerationNewChange::MOD_TYPE_MOVE) {
            return $this->runMove($row);
        }
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
            if (!empty($result['error'])) { return false; }
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

    protected function commitDatabase(): void {
        MediaWikiServices::getInstance()->getDBLoadBalancer()->commitPrimaryChanges(__METHOD__);
    }

    private function runMove($row): bool {
        $services = MediaWikiServices::getInstance();
        $factory = $services->getService('Moderation.EntryFactory');
        $title = $factory->makeViewableEntry($row)->getPage2Title();
        if (!$title) { return false; }
        $this->commitDatabase();
        try {
            $reviewer = $this->createReviewer();
            $reviewer->setRequestTimeout(60);
            $result = $reviewer->reviewText($title->getPrefixedText());
        } catch (\Throwable $e) {
            wfDebugLog('isekai-aireview', 'AI service error for pending move ' . $row->id);
            return false;
        }
        if (!empty($result['error'])) { return false; }
        $robot = $services->getUserFactory()->newFromId($services->getMainConfig()->get('AIReviewRobotUID'));
        $db = \ModerationCompatTools::getDB(DB_PRIMARY);
        $db->doAtomicSection(__METHOD__, function () use ($db, $factory, $row, $result, $robot, $title, $services) {
            $current = $db->selectRow('moderation', \ModerationApprovableEntry::getFields(),
                ['mod_id' => (int)$row->id], __METHOD__, ['FOR UPDATE']);
            // Human decisions or changed destinations invalidate the in-flight judgment.
            if (!$current || $current->rejected || $current->merged_revid || $current != $row) { return; }
            if ($result['pass']) { $factory->makeApprovableEntry($current)->approve($robot); }
            $reason = $result['reason'] ?? [];
            Utils::addAIReviewLog($result['pass'] ? 'approve' : 'reject', $robot,
                $current->user, $title, (int)$row->id, $reason);
            $services->getHookContainer()->run('IsekaiAIReviewResult',
                [(bool)$result['pass'], $title, (int)$row->id, $current->user, $reason]);
        });
        $this->commitDatabase();
        return true;
    }
}
