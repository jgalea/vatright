<?php

namespace Vatright\Tests\Integration\Migration;

require_once __DIR__ . '/ImporterTest.php';

class ImporterPostsStorageTest extends ImporterTest {

	protected function storage_is_hpos(): bool {
		return false;
	}
}
