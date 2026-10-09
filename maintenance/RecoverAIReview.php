<?php
namespace Isekai\AIReview\Maintenance;

use Isekai\AIReview\ReviewScheduler;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/** Run once per minute when the configured queue cannot deliver delayed jobs. */
class RecoverAIReview extends \Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Recover queued or abandoned AI review chains without running AI requests.' );
		$this->requireExtension( 'IsekaiAIReview' );
	}

	public function execute() {
		( new ReviewScheduler() )->recover();
		$this->output( "AI review recovery completed.\n" );
	}
}

$maintClass = RecoverAIReview::class;
require_once RUN_MAINTENANCE_IF_MAIN;
