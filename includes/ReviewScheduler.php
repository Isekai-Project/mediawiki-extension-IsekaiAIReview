<?php
namespace Isekai\AIReview;

use MediaWiki\MediaWikiServices;

/** Durable scheduling and fenced ownership; the Job queue only delivers wakeups. */
class ReviewScheduler {
	private function db() {
		return \ModerationCompatTools::getDB( DB_PRIMARY );
	}

	private function chains() {
		return MediaWikiServices::getInstance()->getService( 'Moderation.RevisionChain' );
	}

	public function schedule( int $id ): void {
		$db = $this->db();
		$generation = null;
		$db->doAtomicSection( __METHOD__, function () use ( $db, $id, &$generation ) {
			$row = $this->chains()->get( $id, true );
			if ( !$row || $row->mc_job_state !== 'idle' || !$this->hasWork( $id ) ) { return; }
			$generation = (int)$row->mc_generation + 1;
			$db->update( 'moderation_chain', [ 'mc_job_state' => 'queued', 'mc_generation' => $generation,
				'mc_job_updated' => time() ], [ 'mc_mod_id' => $id ], __METHOD__ );
		} );
		if ( $generation !== null ) {
			// The queued marker survives failed delivery. The recovery job replays it.
			\DeferredUpdates::addCallableUpdate( function () use ( $id, $generation ) {
				$this->dispatchRecovery();
				$this->dispatch( $id, $generation );
			}, \DeferredUpdates::POSTSEND, $db );
		}
	}

	private function dispatch( int $id, int $generation, bool $retryReady = false ): void {
		$row = $this->chains()->get( $id );
		if ( !$row || (int)$row->mc_generation !== $generation || $row->mc_job_state !== 'queued' ) { return; }
		$fields = json_decode( $row->mc_payload, true );
		$title = \Title::makeTitle( $fields['mod_namespace'], $fields['mod_title'] );
		$queue = MediaWikiServices::getInstance()->getJobQueueGroup();
		$error = $this->db()->selectField( 'moderation_revision', 'mr_id',
			[ 'mr_mod_id' => $id, 'mr_status' => 'pending', "(mr_ai_status = 'error' OR mr_publish_status = 'error')" ], __METHOD__ );
		$params = [ 'mod_id' => $id, 'generation' => $generation ];
		if ( $error && !$retryReady ) {
			if ( !$queue->get( 'IsekaiAIReview' )->delayedJobsEnabled() ) { return; }
			$params['jobReleaseTimestamp'] = time() + 60;
		}
		$queue->push( new AIReviewJob( $title, $params ) );
	}

	public function dispatchRecovery(): void {
		$queue = MediaWikiServices::getInstance()->getJobQueueGroup();
		$params = [];
		if ( $queue->get( 'IsekaiAIReviewRecovery' )->delayedJobsEnabled() ) {
			$params['jobReleaseTimestamp'] = time() + 60;
		} else {
			// DB/Memory queues cannot delay jobs. Page visits and the recovery maintenance
			// command provide periodic wakeups, without a self-enqueueing busy loop.
			$cache = \ObjectCache::getLocalClusterInstance();
			if ( !$cache->add( $cache->makeKey( 'isekai-ai-review-recovery' ), 1, 60 ) ) { return; }
		}
		$queue->push( new ReviewRecoveryJob( \Title::makeTitle( NS_SPECIAL, 'Moderation' ), $params ) );
	}

	private function hasWork( int $id ): bool {
		if ( $this->db()->selectField( 'moderation_revision', 'mr_id',
			[ 'mr_mod_id' => $id, 'mr_status' => 'pending', 'mr_ai_status' => [ 'unreviewed', 'reviewing', 'error' ] ], __METHOD__ ) ) { return true; }
		// A worker may exit after saving its AI result but before applying it.
		foreach ( $this->chains()->revisions( $id, true ) as $row ) {
			if ( $row->mr_ai_status === 'pass' ) { return $row->mr_publish_status !== 'conflict'; }
		}
		return false;
	}

	public function claim( int $id, ?int $generation ): ?string {
		$db = $this->db();
		$token = null;
		$db->doAtomicSection( __METHOD__, function () use ( $db, $id, $generation, &$token ) {
			$row = $this->chains()->get( $id, true );
			if ( !$row || ( $generation !== null && $generation !== (int)$row->mc_generation ) ||
				( $row->mc_job_state === 'running' && (int)$row->mc_lease_until > time() ) ) { return; }
			$token = bin2hex( random_bytes( 24 ) );
			$db->update( 'moderation_chain', [ 'mc_job_state' => 'running', 'mc_lease' => $token,
				'mc_lease_until' => time() + 300, 'mc_job_updated' => time(), 'mc_reviewing' => 0 ],
				[ 'mc_mod_id' => $id ], __METHOD__ );
		} );
		$this->commit();
		return $token;
	}

	public function next( int $id, string $token ) {
		$db = $this->db();
		$revision = null;
		$db->doAtomicSection( __METHOD__, function () use ( $db, $id, $token, &$revision ) {
			$chain = $this->chains()->get( $id, true );
			if ( !$this->owns( $chain, $token ) ) { return; }
			$revision = $db->selectRow( 'moderation_revision', '*', [ 'mr_mod_id' => $id,
				'mr_status' => 'pending', 'mr_ai_status' => [ 'unreviewed', 'reviewing', 'error' ] ],
				__METHOD__, [ 'ORDER BY' => 'mr_sequence', 'FOR UPDATE' ] );
			if ( !$revision ) { return; }
			$revision->mr_base_revid = (int)$chain->mc_public_revid;
			$db->update( 'moderation_revision', [ 'mr_ai_status' => 'reviewing',
				'mr_ai_base' => $chain->mc_public_revid ], [ 'mr_id' => $revision->mr_id ], __METHOD__ );
			$db->update( 'moderation_chain', [ 'mc_reviewing' => $revision->mr_id,
				'mc_lease_until' => time() + 300, 'mc_job_updated' => time() ], [ 'mc_mod_id' => $id ], __METHOD__ );
		} );
		$this->commit();
		return $revision;
	}

	private function owns( $chain, string $token ): bool {
		return $chain && $chain->mc_job_state === 'running' &&
			$chain->mc_lease === $token && (int)$chain->mc_lease_until > time();
	}

	public function saveResult( int $id, string $token, $revision, string $status, $reason ): bool {
		$db = $this->db();
		$saved = false;
		$db->doAtomicSection( __METHOD__, function () use ( $db, $id, $token, $revision, $status, $reason, &$saved ) {
			$chain = $this->chains()->get( $id, true );
			if ( !$this->owns( $chain, $token ) || (int)$chain->mc_reviewing !== (int)$revision->mr_id ) { return; }
			// A concurrent manual publication invalidates the baseline of this AI request.
			$valid = (int)$chain->mc_public_revid === (int)$revision->mr_base_revid;
			$db->update( 'moderation_revision', [ 'mr_ai_status' => $valid ? $status : 'unreviewed',
				'mr_ai_reason' => $valid ? json_encode( $reason, JSON_UNESCAPED_UNICODE ) : '' ],
				[ 'mr_id' => $revision->mr_id, 'mr_status' => 'pending', 'mr_hash' => $revision->mr_hash,
					'mr_ai_status' => 'reviewing' ], __METHOD__ );
			$saved = $valid && $db->affectedRows() > 0;
			$db->update( 'moderation_chain', [ 'mc_reviewing' => 0, 'mc_job_updated' => time() ],
				[ 'mc_mod_id' => $id ], __METHOD__ );
		} );
		$this->commit();
		return $saved;
	}

	/** Apply only a contiguous reviewed prefix ending at its last pass. */
	public function applyReviewed( int $id, string $token, \User $robot ): array {
		$chain = $this->chains()->get( $id );
		if ( !$this->owns( $chain, $token ) ) { return []; }
		$decisions = [];
		$lastPass = 0;
		foreach ( $this->chains()->revisions( $id, true ) as $row ) {
			if ( !in_array( $row->mr_ai_status, [ 'pass', 'fail' ], true ) ) { break; }
			$decisions[] = [ 'id' => (int)$row->mr_id, 'decision' => $row->mr_ai_status === 'pass' ? 'approve' : 'reject' ];
			if ( $row->mr_ai_status === 'pass' ) { $lastPass = count( $decisions ); }
		}
		if ( !$lastPass ) { return []; }
		$result = $this->chains()->apply( $id, (int)$chain->mc_version,
			array_slice( $decisions, 0, $lastPass ), $robot, true, $token );
		$this->commit();
		return $result;
	}

	/** Checking remaining work and relinquishing ownership is one transaction. */
	public function finish( int $id, string $token ): void {
		$db = $this->db();
		$generation = null;
		$db->doAtomicSection( __METHOD__, function () use ( $db, $id, $token, &$generation ) {
			$row = $this->chains()->get( $id, true );
			if ( !$this->owns( $row, $token ) ) { return; }
			$work = $this->hasWork( $id );
			$generation = $work ? (int)$row->mc_generation + 1 : null;
			$db->update( 'moderation_chain', [ 'mc_job_state' => $work ? 'queued' : 'idle',
				'mc_generation' => $generation ?? $row->mc_generation, 'mc_lease' => '',
				'mc_lease_until' => 0, 'mc_reviewing' => 0, 'mc_job_updated' => time() ], [ 'mc_mod_id' => $id ], __METHOD__ );
		} );
		$this->commit();
		if ( $generation !== null ) { $this->dispatch( $id, $generation ); }
	}

	public function recover(): void {
		$db = $this->db();
		$rows = iterator_to_array( $db->select( 'moderation_chain', '*',
			[ "mc_job_state <> 'idle' OR mc_mod_id IN (SELECT mr_mod_id FROM " . $db->tableName( 'moderation_revision' ) .
				" WHERE mr_status = 'pending' AND (mr_ai_status IN ('unreviewed','reviewing','error') OR " .
				"(mr_ai_status = 'pass' AND mr_publish_status <> 'conflict')))" ], __METHOD__, [ 'LIMIT' => 100, 'ORDER BY' => 'mc_job_updated' ] ), false );
		foreach ( $rows as $row ) {
			$generation = null;
			$db->doAtomicSection( __METHOD__, function () use ( $db, $row, &$generation ) {
				$current = $this->chains()->get( (int)$row->mc_mod_id, true );
				if ( !$current || ( $current->mc_job_state === 'idle' && !$this->hasWork( (int)$row->mc_mod_id ) ) ||
					( $current->mc_job_state === 'running' && (int)$current->mc_lease_until > time() ) ||
					( $current->mc_job_state === 'queued' && (int)$current->mc_job_updated > time() - 60 ) ) { return; }
				$generation = (int)$current->mc_generation + 1;
				$db->update( 'moderation_chain', [ 'mc_job_state' => 'queued', 'mc_generation' => $generation,
					'mc_lease' => '', 'mc_lease_until' => 0, 'mc_reviewing' => 0, 'mc_job_updated' => time() ],
					[ 'mc_mod_id' => $row->mc_mod_id ], __METHOD__ );
			} );
			$this->commit();
			if ( $generation !== null ) { $this->dispatch( (int)$row->mc_mod_id, $generation, true ); }
		}
		if ( $db->selectField( 'moderation_chain', 'mc_mod_id', [ 'mc_job_state' => [ 'queued', 'running' ] ], __METHOD__ ) ) { $this->dispatchRecovery(); }
	}

	public function commit(): void {
		MediaWikiServices::getInstance()->getDBLoadBalancer()->commitPrimaryChanges( __METHOD__ );
	}
}
