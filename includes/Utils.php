<?php
namespace Isekai\AIReview;

use ManualLogEntry;

class Utils {
    /**
     * Extract accumulated added text from MediaWiki's diff HTML.
     */
    public static function getDiffAddedLines($diffHtml) {
        if ($diffHtml === '') { return ''; }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML('<meta charset="UTF-8">' . $diffHtml, LIBXML_NONET)) {
                throw new \RuntimeException('Cannot parse moderation diff');
            }
            $xpath = new \DOMXPath($document);
            $lines = [];
            foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " diff-addedline ")]') as $node) {
                $lines[] = $node->textContent;
            }
            return trim(implode("\n", $lines));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function getReadableReason($reasons) {
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
    public static function addAIReviewLog($event, $robotUser, $modUser, $title, $modid, $reason = null, $tempRevisionId = null, $stage = 'publication') {
        $entry = new ManualLogEntry('aireview', $event);
        $entry->setPerformer($robotUser);
        $entry->setTarget($title);

        $param = [
            'modid' => $modid,
            'moduser' => $modUser,
            'temp_revision_id' => $tempRevisionId,
            'stage' => $stage,
        ];
        if($reason){
            $param['reason'] = $reason;
        }

        $entry->setParameters($param);
        $entry->insert();
    }
}
