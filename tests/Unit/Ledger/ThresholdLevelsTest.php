<?php

namespace Vatright\Tests\Unit\Ledger;

use PHPUnit\Framework\TestCase;
use Vatright\Ledger\ThresholdLevels;

class ThresholdLevelsTest extends TestCase {

	public function test_nothing_is_due_below_the_warning_share(): void {
		$this->assertNull( ThresholdLevels::due( 7999.99, 10000.0, 80, array() ) );
	}

	public function test_warning_is_due_at_the_configured_share(): void {
		$this->assertSame( ThresholdLevels::WARNING, ThresholdLevels::due( 8000.0, 10000.0, 80, array() ) );
		$this->assertNull( ThresholdLevels::due( 5999.99, 10000.0, 60, array() ) );
		$this->assertSame( ThresholdLevels::WARNING, ThresholdLevels::due( 6000.0, 10000.0, 60, array() ) );
	}

	public function test_crossing_means_strictly_above_the_threshold(): void {
		$this->assertSame( ThresholdLevels::WARNING, ThresholdLevels::due( 10000.0, 10000.0, 80, array() ) );
		$this->assertSame( ThresholdLevels::CROSSED, ThresholdLevels::due( 10000.01, 10000.0, 80, array() ) );
	}

	public function test_a_sent_level_is_not_due_again(): void {
		$this->assertNull( ThresholdLevels::due( 8500.0, 10000.0, 80, array( ThresholdLevels::WARNING ) ) );
		$this->assertNull( ThresholdLevels::due( 12000.0, 10000.0, 80, array( ThresholdLevels::WARNING, ThresholdLevels::CROSSED ) ) );
	}

	public function test_crossing_is_due_after_the_warning_went_out(): void {
		$this->assertSame( ThresholdLevels::CROSSED, ThresholdLevels::due( 12000.0, 10000.0, 80, array( ThresholdLevels::WARNING ) ) );
	}

	public function test_a_jump_past_the_threshold_asks_for_the_crossing_email_only(): void {
		$this->assertSame( ThresholdLevels::CROSSED, ThresholdLevels::due( 15000.0, 10000.0, 80, array() ) );
	}

	public function test_the_levels_covered_by_a_send(): void {
		$this->assertSame( array( ThresholdLevels::WARNING ), ThresholdLevels::covered( ThresholdLevels::WARNING ) );
		$this->assertSame( array( ThresholdLevels::WARNING, ThresholdLevels::CROSSED ), ThresholdLevels::covered( ThresholdLevels::CROSSED ) );
	}
}
