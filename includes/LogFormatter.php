<?php
namespace Isekai\AIReview;

use LogFormatter as GlobalLogFormatter;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Message\Message;
use MediaWiki\Linker\Linker;
use MediaWiki\Title\Title;

class LogFormatter extends GlobalLogFormatter {
    protected function getMessageKey() {
        $params = $this->entry->getParameters();
        return parent::getMessageKey() . (($params['stage'] ?? '') === 'judgment' ? '-judgment' : '');
    }
    /**
     * @param array $params
     * @return array
     * @throws \MWException
     */
    public function buildBaseParams(array $params): array {
        $services = MediaWikiServices::getInstance();
        $linkRenderer = $this->getLinkRenderer();
        $entryParams = $this->entry->getParameters();
        $modId = $entryParams['modid'];

        $user = $services->getUserFactory()->newFromId($entryParams['moduser']);
        $userLink = Linker::userLink($user->getId(), $user->getName());
        $params[3] = Message::rawParam($userLink);

        $target = SpecialPage::getTitleFor('Moderation');
        $tempId = $entryParams['temp_revision_id'] ?? 0;
        if ($tempId) { $target->setFragment('temp-revision-' . $tempId); }
        $link = $linkRenderer->makeKnownLink(
            $target,
            $this->msg('moderation-log-change')->params($modId)->text() . ($tempId ? ' (temp_revision_id=' . $tempId . ')' : ''),
            ['title' => $this->msg('tooltip-moderation-rejected-change')->plain()],
            ['modaction' => 'show', 'modid' => $modId]
        );
        $params[4] = Message::rawParam($link);
        return $params;
    }

    public function getMessageParameters() {
        $params = parent::getMessageParameters();
        $entryParams = $this->entry->getParameters();
        $type = $this->entry->getSubtype();
        
        switch($type){
            case 'approve':
                $params = $this->buildBaseParams($params);

                break;
            case 'reject':
                $params = $this->buildBaseParams($params);

                $params[5] = Utils::getReadableReason($entryParams['reason'] ?? []);
                break;
        }
        return $params;
    }

    public function getPreloadTitles() {
        $services = MediaWikiServices::getInstance();
		$type = $this->entry->getSubtype();
		$params = $this->entry->getParameters();

		$titles = [];

        if ( $params['moduser'] ) { # Not anonymous
            $user = $services->getUserFactory()->newFromId($params['moduser']);
            $titles[] = Title::makeTitle( NS_USER, $user->getName() );
        }

		return $titles;
	}
}
