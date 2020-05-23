<?php
namespace Isekai\AIReview;

use LogFormatter as GlobalLogFormatter;
use SpecialPage;
use Message;
use Linker;
use Title;
use User;

class LogFormatter extends GlobalLogFormatter {
    public function getMessageParameters(){
        $params = parent::getMessageParameters();

        $type = $this->entry->getSubtype();
        $entryParams = $this->entry->getParameters();
        $linkRenderer = $this->getLinkRenderer();
        
        switch($type){
            case 'approve':
                $modId = $entryParams['modid'];

                $user = User::newFromId($entryParams['moduser']);
                $userLink = Linker::userLink( $user->getId(), $user->getName() );
                $params[3] = Message::rawParam( $userLink );

                $link = $linkRenderer->makeKnownLink(
                    SpecialPage::getTitleFor( 'Moderation' ),
                    $this->msg( 'moderation-log-change' )->params( $modId )->text(),
                    [ 'title' => $this->msg( 'tooltip-moderation-rejected-change' )->plain() ],
                    [ 'modaction' => 'show', 'modid' => $modId ]
                );
                $params[4] = Message::rawParam( $link );

                break;
            case 'reject':
                $modId = $entryParams['modid'];

                $user = User::newFromId($entryParams['moduser']);
                $userLink = Linker::userLink( $user->getId(), $user->getName() );
                $params[3] = Message::rawParam( $userLink );

                $link = $linkRenderer->makeKnownLink(
                    SpecialPage::getTitleFor( 'Moderation' ),
                    $this->msg( 'moderation-log-change' )->params( $modId )->text(),
                    [ 'title' => $this->msg( 'tooltip-moderation-rejected-change' )->plain() ],
                    [ 'modaction' => 'show', 'modid' => $modId ]
                );
                $params[4] = Message::rawParam( $link );

                $params[5] = Utils::getReadableReason($entryParams['reason']);
                break;
        }
        return $params;
    }

    public function getPreloadTitles() {
		$type = $this->entry->getSubtype();
		$params = $this->entry->getParameters();

		$titles = [];

        if ( $params['moduser'] ) { # Not anonymous
            $user = User::newFromId($params['moduser']);
            $titles[] = Title::makeTitle( NS_USER, $user->getName() );
        }

		return $titles;
	}
}