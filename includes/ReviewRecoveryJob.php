<?php
namespace Isekai\AIReview;

class ReviewRecoveryJob extends \Job {
	public function __construct( $title, array $params ) {
		parent::__construct( 'IsekaiAIReviewRecovery', $title, $params );
		$this->removeDuplicates = true;
	}

	public function getDeduplicationInfo() {
		return [ 'type' => $this->getType() ];
	}

	public function run() {
		( new ReviewScheduler() )->recover();
		return true;
	}
}
