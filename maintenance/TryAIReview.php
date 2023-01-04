<?php

namespace Isekai\AIReview\Maintenance;

use Isekai\AIReview\AliyunAIReview;
use Maintenance;

/**
 * Make sure the index for the wiki is sane.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class TryAIReview extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Test AI Review');
		$this->addArg('text', 'Text to review', false);
		$this->addOption('file', 'File to review', false, true);
	}

	public function execute() {
		$text = '';

		if ($this->hasOption('file')) {
			$file = $this->getOption('file');

			if (!file_exists($file)) {
				$this->error("File not found." . PHP_EOL);
				return false;
			}

			$text = file_get_contents($file);
		}

		if ($this->hasArg(0)) {
			$text = $this->getArg(0);
		}

		if (empty($text)) {
			$this->error("Text to review is empty." . PHP_EOL);
			return false;
		}

		$aiReview = new AliyunAIReview();

		/** @var \AlibabaCloud\Client\Result\Result $response */
		$response = $aiReview->reviewText($text, true);

		$this->output("Response Status: " . $response->getReasonPhrase() . PHP_EOL);
		$this->output("Response Body: ");
		var_dump($response->toArray());
		$this->output(PHP_EOL);

		$parsedResponse = $aiReview->parseResponse($response->toArray());
		$this->output("Parsed response: ");
		var_dump($parsedResponse);

		return true;
	}
}

$maintClass = TryAIReview::class;
require_once RUN_MAINTENANCE_IF_MAIN;
